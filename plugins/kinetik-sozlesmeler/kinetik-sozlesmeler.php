<?php
/**
 * Plugin Name: KİNETİK Sözleşmeler
 * Description: Mesafeli Satış Sözleşmesi + Ön Bilgilendirme Formu'nu ödeme adımında siparişe özel olarak üretir, iki ayrı onay kutusuyla alır, onay kaydını (IP/zaman) siparişe yazar, kopyasını müşteri e-postasına ve Hesabım ekranına koyar. Satıcı künyesi tek kaynaktan (kinetik_seller_info) okunur.
 * Version: 1.0.0
 * Author: Erusoft
 * Author URI: https://erusoft.com
 * Plugin URI: https://github.com/erayusta/kinetik
 * License: GPL-2.0-or-later
 * Requires Plugins: woocommerce
 * Text Domain: kinetik-sozlesmeler
 *
 * Tasarım: yaygın Türk WooCommerce sözleşme akışı (checkout onayı → sipariş anında
 * anlık görüntü → e-posta + hesabım), ancak metnin TEK KAYNAĞI sitedeki yasal sayfalar
 * (demo/fill-legal-pages.php sahibi). Sayfa içeriği kopyalanmaz, çalışma anında okunur.
 */

defined('ABSPATH') || exit;

class Kinetik_Sozlesmeler {

    const VER            = '1.0.0';
    const OPT_SELLER     = 'kinetik_seller_info';           // tek kaynak: demo/setup-store.php
    const META_DOCS      = '_kinetik_sozlesme_belgeler';   // ['mesafeli'=>html, 'onbilgi'=>html]
    const META_ONAY      = '_kinetik_sozlesme_onay';       // ['ip','ua','ts','yontem']
    const META_TICARI    = '_kinetik_ticari_ileti';        // ['onay'=>bool,'ip','ts','kanal']

    /** Sözleşme tanımları: alan adı => [başlık, sayfa slug'ı, onay kutusu metni] */
    public static function types() {
        return [
            'onbilgi'  => [
                'ad'    => 'Ön Bilgilendirme Formu',
                'slug'  => 'on-bilgilendirme-formu',
                'onay'  => 'Ön Bilgilendirme Formu’nu okudum ve onaylıyorum.',
            ],
            'mesafeli' => [
                'ad'    => 'Mesafeli Satış Sözleşmesi',
                'slug'  => 'mesafeli-satis-sozlesmesi',
                'onay'  => 'Mesafeli Satış Sözleşmesi’ni okudum ve kabul ediyorum.',
            ],
        ];
    }

    /* ─────────────────────────── satıcı künyesi ─────────────────────────── */

    /**
     * Künyenin TEK kaynağı `kinetik_seller_info` seçeneğidir (demo/setup-store.php oluşturur,
     * yasal sayfalar da demo/fill-legal-pages.php ile buradan üretilir). Bu eklenti ikinci
     * bir kaynak oluşturmaz; ayar ekranı da doğrudan bu seçeneği düzenler.
     */
    public static function seller_labels() {
        return [
            'unvan'         => 'Satıcı ünvanı',
            'marka'         => 'Marka',
            'adres'         => 'Adres',
            'telefon'       => 'Telefon',
            'eposta'        => 'E-posta',
            'vergi_dairesi' => 'Vergi dairesi',
            'vergi_no'      => 'Vergi no',
            'mersis'        => 'MERSİS no',
            'iban'          => 'IBAN',
            'banka'         => 'Banka',
            'web'           => 'Web adresi',
        ];
    }

    public static function seller() {
        $saved = get_option(self::OPT_SELLER, []);
        $out = array_fill_keys(array_keys(self::seller_labels()), '');
        if (is_array($saved)) foreach ($saved as $k => $v) $out[$k] = (string) $v;
        return $out;
    }

    /** Boş alan: uydurma değer yerine görünür "bekleyen alan" işareti. */
    public static function pending($label) {
        return '<span class="pending-field">[' . esc_html($label) . ' eklenecek]</span>';
    }

    /** Doldurulmamış zorunlu künye alanları (mevzuat gereği sözleşmede bulunmalı). */
    public static function missing_seller_fields() {
        $s = self::seller();
        $need = ['vergi_dairesi' => 'Vergi dairesi', 'vergi_no' => 'Vergi no', 'mersis' => 'MERSİS no', 'telefon' => 'Telefon', 'eposta' => 'E-posta'];
        $out = [];
        foreach ($need as $k => $label) if (trim((string) $s[$k]) === '') $out[$k] = $label;
        return $out;
    }

    private static function seller_rows() {
        $s = self::seller();
        $v = function ($k, $label) use ($s) { $x = trim((string) $s[$k]); return $x === '' ? self::pending($label) : esc_html($x); };
        return [
            'Satıcı ünvanı' => $v('unvan', 'Ünvan'),
            'Marka'         => $v('marka', 'Marka'),
            'Adres'         => $v('adres', 'Adres'),
            'Vergi dairesi / no' => $v('vergi_dairesi', 'Vergi dairesi') . ' / ' . $v('vergi_no', 'Vergi no'),
            'MERSİS no'     => $v('mersis', 'MERSİS no'),
            'Telefon'       => $v('telefon', 'Telefon'),
            'E-posta'       => $v('eposta', 'E-posta'),
            'Web'           => $v('web', 'Web adresi'),
        ];
    }

    /* ─────────────────────────── belge üretimi ─────────────────────────── */

    /** Ödeme adımındaki form verisinden (AJAX post_data) alıcı bilgisini çıkarır. */
    private static function buyer_from_post() {
        $d = [];
        if (isset($_POST['post_data'])) {
            parse_str(wp_unslash($_POST['post_data']), $d); // phpcs:ignore
        } elseif (!empty($_POST)) {
            $d = wp_unslash($_POST); // phpcs:ignore
        }
        $g = function ($k) use ($d) { return isset($d[$k]) ? sanitize_text_field((string) $d[$k]) : ''; };
        $ship = !empty($d['ship_to_different_address']);
        $line = function ($p) use ($g) {
            // kinetik-tr-adres alanları (ilçe değeri "id|Ad", "__diger__" = elle girildi).
            $tr = function ($k) use ($g, $p) { $v = preg_replace('/^\d+\|/', '', $g($p . '_' . $k)); return $v === '__diger__' ? '' : $v; };
            $state = $g($p . '_state');
            $states = function_exists('WC') && WC()->countries ? WC()->countries->get_states($g($p . '_country') ?: 'TR') : [];
            if (is_array($states) && isset($states[$state])) $state = html_entity_decode($states[$state], ENT_QUOTES, 'UTF-8');
            $city = $g($p . '_city');
            $parts = array_filter([
                trim($g($p . '_first_name') . ' ' . $g($p . '_last_name')),
                trim($tr('street') . ' ' . $g($p . '_address_1')), $g($p . '_address_2'),
                trim($tr('neighborhood') ?: $g($p . '_neighbourhood')),
                trim(($tr('district') ?: $city) . ' / ' . $state, ' /'),
                $g($p . '_postcode'),
            ]);
            return implode(', ', $parts);
        };
        return [
            'ad'       => trim($g('billing_first_name') . ' ' . $g('billing_last_name')),
            'eposta'   => $g('billing_email'),
            'telefon'  => $g('billing_phone'),
            'fatura'   => $line('billing'),
            'teslimat' => $ship ? $line('shipping') : $line('billing'),
        ];
    }

    private static function buyer_from_order(WC_Order $order) {
        $fmt = function ($a) { return trim(preg_replace('/\s*<br\s*\/?>\s*/i', ', ', $a), ', '); };
        return [
            'ad'       => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'eposta'   => $order->get_billing_email(),
            'telefon'  => $order->get_billing_phone(),
            'fatura'   => $fmt($order->get_formatted_billing_address()),
            'teslimat' => $fmt($order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address()),
        ];
    }

    /** Sipariş/sepet özet tablosu — sözleşmenin siparişe özgü kısmı. */
    private static function summary_html($order = null) {
        $buyer = $order instanceof WC_Order ? self::buyer_from_order($order) : self::buyer_from_post();
        $rows  = '';
        $items = [];
        $ara = $kargo = $indirim = $toplam = 0.0;
        $odeme = ''; $kargo_var = false;

        if ($order instanceof WC_Order) {
            foreach ($order->get_items() as $it) {
                $items[] = [$it->get_name(), $it->get_quantity(), (float) $it->get_total() + (float) $it->get_total_tax()];
            }
            $ara       = (float) $order->get_subtotal();
            $kargo     = (float) $order->get_shipping_total();
            $kargo_var = (bool) $order->get_shipping_methods();
            $indirim = (float) $order->get_total_discount();
            $toplam  = (float) $order->get_total();
            $odeme   = $order->get_payment_method_title();
            $no      = $order->get_order_number();
            $tarih   = $order->get_date_created() ? wp_date('d.m.Y H:i', $order->get_date_created()->getTimestamp()) : '';
        } else {
            if (WC()->cart) {
                foreach (WC()->cart->get_cart() as $ci) {
                    $items[] = [$ci['data']->get_name(), (int) $ci['quantity'], (float) $ci['line_total'] + (float) $ci['line_tax']];
                }
                $ara       = (float) WC()->cart->get_subtotal();
                $kargo     = (float) WC()->cart->get_shipping_total();
                $kargo_var = (bool) WC()->cart->needs_shipping();
                $indirim = (float) WC()->cart->get_discount_total();
                $toplam  = (float) WC()->cart->get_total('edit');
            }
            $no    = '<em>sipariş onayında oluşur</em>';
            $tarih = wp_date('d.m.Y H:i');
        }

        foreach ($items as $i) {
            $rows .= '<tr><td>' . esc_html($i[0]) . '</td><td style="text-align:center">' . (int) $i[1] . '</td><td style="text-align:right">' . wp_kses_post(wc_price($i[2])) . '</td></tr>';
        }
        if (!$rows) $rows = '<tr><td colspan="3"><em>Sepetinde ürün yok.</em></td></tr>';

        $sat = function ($label, $val) { return '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $val . '</td></tr>'; };

        $h  = '<div class="kinetik-sozlesme-ozet">';
        $h .= '<h3>Satıcı</h3><table class="legal-table kinetik-sozlesme-table"><tbody>';
        foreach (self::seller_rows() as $k => $v) $h .= $sat($k, $v);
        $h .= '</tbody></table>';

        $h .= '<h3>Alıcı ve teslimat</h3><table class="legal-table kinetik-sozlesme-table"><tbody>';
        // Ödeme adımında henüz doldurulmamış alanlar boş görünmesin.
        $alan = function ($v) { $v = trim((string) $v); return $v === '' ? '<em>sipariş formundan alınır</em>' : esc_html($v); };
        $h .= $sat('Ad soyad', $alan($buyer['ad']));
        $h .= $sat('E-posta', $alan($buyer['eposta']));
        $h .= $sat('Telefon', $alan($buyer['telefon']));
        $h .= $sat('Fatura adresi', $alan($buyer['fatura']));
        $h .= $sat('Teslimat adresi', $alan($buyer['teslimat']));
        $h .= '</tbody></table>';

        $h .= '<h3>Sipariş</h3><table class="legal-table kinetik-sozlesme-table"><tbody>';
        $h .= $sat('Sipariş no', is_string($no) && strpos($no, '<em>') === 0 ? $no : esc_html($no));
        $h .= $sat('Tarih', esc_html($tarih));
        if ($odeme !== '') $h .= $sat('Ödeme yöntemi', esc_html($odeme));
        $h .= '</tbody></table>';

        $h .= '<table class="legal-table kinetik-sozlesme-table kinetik-sozlesme-urunler"><thead><tr><th>Ürün</th><th style="text-align:center">Adet</th><th style="text-align:right">Tutar</th></tr></thead><tbody>' . $rows . '</tbody>';
        $h .= '<tfoot>';
        $h .= '<tr><th colspan="2" style="text-align:right">Ara toplam</th><td style="text-align:right">' . wp_kses_post(wc_price($ara)) . '</td></tr>';
        if ($indirim > 0) $h .= '<tr><th colspan="2" style="text-align:right">İndirim</th><td style="text-align:right">−' . wp_kses_post(wc_price($indirim)) . '</td></tr>';
        // Kargo satırı: 0 TL yalnızca gerçekten kargo yöntemi seçilmişse "Ücretsiz" demektir;
        // yöntem seçilmeden 0 gelirse (sepet adımı) "ödeme adımında hesaplanır" yazılır.
        $kargo_metin = $kargo > 0 ? wp_kses_post(wc_price($kargo)) : ($kargo_var ? 'Ücretsiz' : '<em>ödeme adımında hesaplanır</em>');
        $h .= '<tr><th colspan="2" style="text-align:right">Kargo</th><td style="text-align:right">' . $kargo_metin . '</td></tr>';
        $h .= '<tr><th colspan="2" style="text-align:right">Genel toplam (KDV dâhil)</th><td style="text-align:right"><strong>' . wp_kses_post(wc_price($toplam)) . '</strong></td></tr>';
        $h .= '</tfoot></table></div>';
        return $h;
    }

    /** Yasal sayfanın metni — tek kaynak, çalışma anında okunur. */
    private static function page_html($slug) {
        $page = get_page_by_path($slug, OBJECT, 'page');
        if (!$page || $page->post_status !== 'publish') return '';
        $c = $page->post_content;
        // Sayfanın kendi giriş bloğu sözleşme belgesinde gereksiz (pazarlama dili).
        $c = preg_replace('#<div class="content-intro">.*?</div>#is', '', $c);
        return do_shortcode(wpautop($c));
    }

    /** Tam belge: siparişe özgü özet + yasal metin. */
    public static function build($type, $order = null) {
        $t = self::types()[$type] ?? null;
        if (!$t) return '';
        $body = self::page_html($t['slug']);
        if ($body === '') return '';
        return '<div class="kinetik-sozlesme-belge" data-tip="' . esc_attr($type) . '">'
             . '<h2 class="kinetik-sozlesme-baslik">' . esc_html($t['ad']) . '</h2>'
             . self::summary_html($order)
             . '<div class="legal-body">' . $body . '</div></div>';
    }

    /* ─────────────────────────── ödeme adımı ─────────────────────────── */

    public static function init() {
        // WooCommerce'in tek "şartlar" kutusunu kapat; iki ayrı onayı biz basıyoruz.
        add_filter('woocommerce_checkout_show_terms', '__return_false');

        add_action('woocommerce_review_order_before_submit', [__CLASS__, 'checkout_block'], 5);
        add_action('woocommerce_checkout_process', [__CLASS__, 'validate']);
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'save_snapshot'], 20, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [__CLASS__, 'save_snapshot'], 20, 1);

        add_action('woocommerce_email_after_order_table', [__CLASS__, 'email_docs'], 30, 4);
        add_action('woocommerce_order_details_after_order_table', [__CLASS__, 'account_docs'], 20);
        // Teşekkür sayfası şablonu order_details kancasını çalıştırmıyor (tema kendi
        // düzenini basıyor) — Havale akışında müşteri en çok bu sayfada kalıyor.
        add_action('woocommerce_thankyou', function ($order_id) { self::account_docs(wc_get_order($order_id)); }, 30);

        add_action('add_meta_boxes', [__CLASS__, 'meta_box']);
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_notices', [__CLASS__, 'admin_notice']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
    }

    /**
     * Onay bloğu + modal için asgari, temadan bağımsız stil ve davranış. Ödeme adımında
     * #order_review AJAX ile yenilendiği için dinleyiciler document üzerinde delegasyonla durur.
     * Tema bu sınıfları kendi stiliyle ezebilir.
     */
    public static function assets() {
        if (!function_exists('is_checkout')) return;
        $on_checkout = is_checkout() && !is_wc_endpoint_url('order-received');
        if (!$on_checkout && !is_account_page() && !is_wc_endpoint_url('order-received')) return;

        $css = '.kinetik-sozlesme{margin:0 0 16px;padding:16px;border:1px solid #E2DFD8;border-radius:8px;background:#F3F1EC}'
             . '.kinetik-sozlesme__kvkk{margin:0 0 12px;font-size:12px;line-height:1.6;color:#555}'
             . '.kinetik-sozlesme__kvkk a{color:inherit;text-decoration:underline}'
             . '.kinetik-sozlesme__row{margin:0 0 8px!important;padding:0!important}'
             . '.kinetik-sozlesme__label{display:flex!important;align-items:flex-start;gap:10px;font-size:13px;line-height:1.55;text-transform:none!important;letter-spacing:normal!important;font-weight:400!important}'
             . '.kinetik-sozlesme__cb{width:18px;height:18px;flex:0 0 18px;margin:1px 0 0!important;accent-color:#0D0D0D;cursor:pointer}'
             . '.kinetik-sozlesme__text{flex:1}'
             . '.kinetik-sozlesme__row--ticari small{display:block;margin-top:3px;font-size:11.5px;opacity:.75}'
             . '.kinetik-sozlesme__ac{border:0;background:none;padding:0;font:inherit;color:inherit;font-weight:600;text-decoration:underline;text-underline-offset:3px;cursor:pointer}'
             . '.kinetik-sozlesme__modal{position:fixed;inset:0;z-index:9999;background:rgba(13,13,13,.55);display:flex;align-items:center;justify-content:center;padding:16px}'
             . '.kinetik-sozlesme__modal[hidden],.kinetik-sozlesme__belge[hidden]{display:none!important}'
             . '.kinetik-sozlesme__modal-card{position:relative;display:flex;flex-direction:column;width:min(760px,100%);max-height:min(86vh,860px);background:#fff;border-radius:8px;overflow:hidden}'
             . '.kinetik-sozlesme__kapat{position:absolute;top:10px;right:10px;z-index:2;width:36px;height:36px;border:0;border-radius:999px;background:#F3F1EC;color:#0D0D0D;cursor:pointer}'
             . '.kinetik-sozlesme__modal-icerik{overflow-y:auto;-webkit-overflow-scrolling:touch;padding:28px 24px 20px;font-size:13px;line-height:1.7}'
             . '.kinetik-sozlesme__modal-alt{flex-shrink:0;padding:14px 24px;border-top:1px solid #E2DFD8;background:#F3F1EC}'
             . '.kinetik-sozlesme__onayla{width:100%;padding:14px;border:0;background:#0D0D0D;color:#fff;font-weight:700;cursor:pointer}'
             . 'body.kinetik-modal-acik{overflow:hidden}'
             . '.kinetik-sozlesme-table{width:100%;border-collapse:collapse;margin:0 0 12px;font-size:12.5px}'
             . '.kinetik-sozlesme-table th,.kinetik-sozlesme-table td{border:1px solid #E2DFD8;padding:7px 10px;text-align:left;vertical-align:top}'
             . '.kinetik-sozlesme-table tbody th{width:38%;font-weight:600;background:#F3F1EC}'
             . '.kinetik-sozlesme-hesabim{margin:32px 0 0}'
             . '.kinetik-sozlesme-details{border:1px solid #E2DFD8;border-radius:8px;margin:0 0 10px;overflow:hidden}'
             . '.kinetik-sozlesme-details>summary{cursor:pointer;padding:14px 18px;font-weight:600}'
             . '.kinetik-sozlesme-icerik{padding:0 18px 18px}'
             . '.kinetik-sozlesme-icerik .kinetik-sozlesme-baslik{display:none}'
             . '.pending-field{background:#FFF4CC;padding:0 3px;border-radius:2px}'
             . '@media (max-width:640px){.kinetik-sozlesme__modal{padding:0}.kinetik-sozlesme__modal-card{max-height:100vh;height:100%;border-radius:0}.kinetik-sozlesme__modal-icerik{padding:24px 16px 16px}.kinetik-sozlesme-table{table-layout:fixed;word-break:break-word}.kinetik-sozlesme-urunler{display:block;overflow-x:auto}}';
        wp_register_style('kinetik-sozlesmeler', false, [], self::VER);
        wp_enqueue_style('kinetik-sozlesmeler');
        wp_add_inline_style('kinetik-sozlesmeler', $css);

        if (!$on_checkout) return;
        $js = <<<'JS'
(function(){
  var modal=function(){return document.getElementById('kinetik-sozlesme-modal');};
  var acik=null;
  var kapat=function(){var m=modal();if(!m)return;m.hidden=true;document.body.classList.remove('kinetik-modal-acik');acik=null;};
  document.addEventListener('click',function(e){
    var ac=e.target.closest('[data-kinetik-sozlesme]');
    if(ac){
      e.preventDefault();
      var tip=ac.getAttribute('data-kinetik-sozlesme');
      var kaynak=document.getElementById('kinetik-sozlesme-belge-'+tip);
      var m=modal(); if(!m||!kaynak) return;
      var ic=m.querySelector('.kinetik-sozlesme__modal-icerik');
      ic.innerHTML=kaynak.innerHTML; ic.scrollTop=0;
      m.hidden=false; document.body.classList.add('kinetik-modal-acik'); acik=tip;
      return;
    }
    if(e.target.closest('.kinetik-sozlesme__kapat')||(e.target.classList&&e.target.classList.contains('kinetik-sozlesme__modal'))){kapat();return;}
    if(e.target.closest('.kinetik-sozlesme__onayla')){
      var cb=acik&&document.getElementById('kinetik_sozlesme_'+acik);
      if(cb){cb.checked=true;cb.dispatchEvent(new Event('change',{bubbles:true}));}
      kapat();
    }
  });
  document.addEventListener('keydown',function(e){if(e.key==='Escape')kapat();});
})();
JS;
        wp_register_script('kinetik-sozlesmeler', false, [], self::VER, true);
        wp_enqueue_script('kinetik-sozlesmeler');
        wp_add_inline_script('kinetik-sozlesmeler', $js);
    }

    /** Ödeme butonunun hemen üstündeki onay bloğu. */
    public static function checkout_block() {
        $types = self::types();
        $priv  = get_privacy_policy_url() ?: home_url('/gizlilik-politikasi/');
        $kvkk  = home_url('/kvkk-aydinlatma-metni/');

        // AJAX yenilemesinde işaretli kutular kaybolmasın.
        $posted = [];
        if (isset($_POST['post_data'])) parse_str(wp_unslash($_POST['post_data']), $posted); // phpcs:ignore

        echo '<div class="kinetik-sozlesme" id="kinetik-sozlesme">';
        echo '<p class="kinetik-sozlesme__kvkk">Kişisel verilerin siparişin işlenmesi için <a href="' . esc_url($kvkk) . '" target="_blank" rel="noopener">KVKK Aydınlatma Metni</a> ve <a href="' . esc_url($priv) . '" target="_blank" rel="noopener">Gizlilik Politikası</a> kapsamında işlenir.</p>';

        foreach ($types as $key => $t) {
            $name = 'kinetik_sozlesme_' . $key;
            $on   = !empty($posted[$name]);
            echo '<p class="form-row kinetik-sozlesme__row validate-required">';
            echo '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox kinetik-sozlesme__label">';
            echo '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox kinetik-sozlesme__cb" name="' . esc_attr($name) . '" id="' . esc_attr($name) . '" value="1"' . checked($on, true, false) . '> ';
            echo '<span class="kinetik-sozlesme__text">';
            $metin = str_replace(
                $t['ad'],
                '<button type="button" class="kinetik-sozlesme__ac" data-kinetik-sozlesme="' . esc_attr($key) . '">' . esc_html($t['ad']) . '</button>',
                esc_html($t['onay'])
            );
            echo wp_kses($metin, ['button' => ['type' => [], 'class' => [], 'data-kinetik-sozlesme' => []]]);
            echo ' <abbr class="required" title="zorunlu">*</abbr></span></label></p>';
        }

        /**
         * Ticari elektronik ileti izni. 6563 sayılı kanun gereği:
         * ayrı ve açık rıza · ÖN İŞARETLİ OLAMAZ · siparişin şartı yapılamaz.
         * Bu yüzden validate() içinde kontrol edilmez (14 Eyl 2026).
         */
        $ticari_on = !empty($posted['kinetik_ticari_ileti']);
        echo '<p class="form-row kinetik-sozlesme__row kinetik-sozlesme__row--ticari">';
        echo '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox kinetik-sozlesme__label">';
        echo '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox kinetik-sozlesme__cb" name="kinetik_ticari_ileti" id="kinetik_ticari_ileti" value="1"' . checked($ticari_on, true, false) . '> ';
        echo '<span class="kinetik-sozlesme__text">Kampanya ve yeni koleksiyon duyurularını e-posta ile almak istiyorum. <small>İsteğe bağlı — her e-postanın altındaki bağlantıdan tek tıkla çıkabilirsin.</small></span>';
        echo '</label></p>';

        // Belgeler — modalda gösterilir, sepet/adres değişiminde bu blokla birlikte tazelenir.
        foreach ($types as $key => $t) {
            echo '<div class="kinetik-sozlesme__belge" id="kinetik-sozlesme-belge-' . esc_attr($key) . '" hidden>' . wp_kses_post(self::build($key)) . '</div>';
        }

        echo '<div class="kinetik-sozlesme__modal" id="kinetik-sozlesme-modal" hidden role="dialog" aria-modal="true" aria-label="Sözleşme">';
        echo '<div class="kinetik-sozlesme__modal-card"><button type="button" class="kinetik-sozlesme__kapat" aria-label="Kapat">✕</button><div class="kinetik-sozlesme__modal-icerik"></div>';
        echo '<div class="kinetik-sozlesme__modal-alt"><button type="button" class="btn btn--primary kinetik-sozlesme__onayla">Okudum, onaylıyorum</button></div></div></div>';
        echo '</div>';
    }

    public static function validate() {
        foreach (self::types() as $key => $t) {
            if (empty($_POST['kinetik_sozlesme_' . $key])) { // phpcs:ignore
                wc_add_notice(sprintf('Siparişi tamamlamak için <strong>%s</strong> onayı gereklidir.', esc_html($t['ad'])), 'error');
            }
        }
    }

    /** Sipariş oluştuğunda belgelerin o anki hâlini ve onay kaydını sabitler. */
    public static function save_snapshot($order_id) {
        $order = $order_id instanceof WC_Order ? $order_id : wc_get_order($order_id);
        if (!$order || $order->get_meta(self::META_DOCS)) return;

        $docs = [];
        foreach (array_keys(self::types()) as $key) {
            $html = self::build($key, $order);
            if ($html !== '') $docs[$key] = $html;
        }
        if (!$docs) return;

        $order->update_meta_data(self::META_DOCS, $docs);
        // Ticari ileti izni: İYS'ye bildirim için kanıt (zaman + IP) ile saklanır.
        $ticari = !empty($_POST['kinetik_ticari_ileti']); // phpcs:ignore
        $order->update_meta_data(self::META_TICARI, [
            'onay'  => $ticari,
            'ip'    => self::client_ip(),
            'ts'    => time(),
            'kanal' => 'eposta',
        ]);
        if ($ticari && ($eposta = $order->get_billing_email())) {
            update_option('kinetik_ticari_son', $eposta, false);
            if ($uid = $order->get_customer_id()) update_user_meta($uid, '_kinetik_ticari_ileti', 1);
        }

        $order->update_meta_data(self::META_ONAY, [
            'ip'     => self::client_ip(),
            'ua'     => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 255) : '',
            'ts'     => time(),
            'yontem' => 'ödeme adımında elektronik onay',
        ]);
        $order->save();
    }

    private static function client_ip() {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', sanitize_text_field(wp_unslash($_SERVER[$k])))[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '';
    }

    /* ─────────────────────────── teslim: e-posta + hesabım ─────────────────────────── */

    /** Kalıcı veri saklayıcısı: sözleşmelerin kopyası müşteri e-postasında. */
    public static function email_docs($order, $sent_to_admin, $plain_text, $email = null) {
        if ($sent_to_admin || $plain_text || !$order instanceof WC_Order) return;
        $ids = ['customer_on_hold_order', 'customer_processing_order', 'customer_invoice'];
        if ($email && !in_array($email->id, $ids, true)) return;
        $docs = $order->get_meta(self::META_DOCS);
        if (!is_array($docs) || !$docs) return;

        echo '<h2 style="color:#0D0D0D;font-family:Arial,Helvetica,sans-serif;text-transform:uppercase;letter-spacing:.04em;margin:32px 0 8px">Sözleşmeleriniz</h2>';
        echo '<p style="font-size:13px;color:#555;margin:0 0 16px">Siparişinizle birlikte onayladığınız belgelerin kopyası aşağıdadır. Bu e-postayı saklamanızı öneririz.</p>';
        foreach (self::types() as $key => $t) {
            if (empty($docs[$key])) continue;
            echo '<div style="font-size:12px;line-height:1.6;color:#1C1C1C;border:1px solid #E2DFD8;border-radius:8px;padding:16px;margin:0 0 16px">' . wp_kses_post($docs[$key]) . '</div>';
        }
    }

    /** Hesabım → sipariş detayında açılır belgeler. */
    public static function account_docs($order) {
        if (!$order instanceof WC_Order) return;
        // WooCommerce zaten `woocommerce_thankyou`'ya order_details_table'ı bağlıyor;
        // yedek kancamızla birlikte bölüm iki kez basılıyordu (ölçümle yakalandı).
        static $basildi = [];
        if (isset($basildi[$order->get_id()])) return;
        $docs = $order->get_meta(self::META_DOCS);
        if (!is_array($docs) || !$docs) return;
        $basildi[$order->get_id()] = true;
        $onay = $order->get_meta(self::META_ONAY);

        echo '<section class="kinetik-sozlesme-hesabim"><h2>Sözleşmeler</h2>';
        if (is_array($onay) && !empty($onay['ts'])) {
            echo '<p class="kinetik-sozlesme-onaykaydi">' . esc_html(wp_date('d.m.Y H:i', (int) $onay['ts'])) . ' tarihinde elektronik ortamda onaylandı.</p>';
        }
        foreach (self::types() as $key => $t) {
            if (empty($docs[$key])) continue;
            echo '<details class="kinetik-sozlesme-details"><summary>' . esc_html($t['ad']) . '</summary><div class="kinetik-sozlesme-icerik">' . wp_kses_post($docs[$key]) . '</div></details>';
        }
        echo '</section>';
    }

    /* ─────────────────────────── yönetim ─────────────────────────── */

    public static function meta_box() {
        $screens = ['shop_order', 'woocommerce_page_wc-orders'];
        foreach ($screens as $s) {
            add_meta_box('kinetik_sozlesme_box', 'Sözleşme onayı', [__CLASS__, 'render_meta_box'], $s, 'side', 'default');
        }
    }

    public static function render_meta_box($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order) return;
        $onay = $order->get_meta(self::META_ONAY);
        $docs = $order->get_meta(self::META_DOCS);
        if (!is_array($onay) || empty($onay['ts'])) { echo '<p>Bu sipariş için sözleşme onay kaydı yok.</p>'; return; }
        echo '<p><strong>Onay:</strong> ' . esc_html(wp_date('d.m.Y H:i', (int) $onay['ts'])) . '<br>';
        echo '<strong>IP:</strong> ' . esc_html($onay['ip'] ?: '—') . '<br>';
        echo '<strong>Belgeler:</strong> ' . esc_html(implode(', ', array_map(function ($k) { return self::types()[$k]['ad'] ?? $k; }, array_keys((array) $docs)))) . '</p>';
        echo '<p style="font-size:11px;color:#666;word-break:break-all">' . esc_html($onay['ua']) . '</p>';
    }

    public static function menu() {
        add_submenu_page('woocommerce', 'Sözleşmeler — KİNETİK', 'Sözleşmeler', 'manage_woocommerce', 'kinetik-sozlesmeler', [__CLASS__, 'settings_page']);
    }

    public static function register_settings() {
        register_setting('kinetik_sozlesmeler', self::OPT_SELLER, ['type' => 'array', 'sanitize_callback' => [__CLASS__, 'sanitize_seller']]);
        // options.php varsayılan olarak manage_options ister; mağaza yöneticisi de düzenleyebilsin.
        add_filter('option_page_capability_kinetik_sozlesmeler', function () { return 'manage_woocommerce'; });
    }

    /** Bilinen alanları temizler; seçenekte başka anahtar varsa korur. */
    public static function sanitize_seller($in) {
        $out = self::seller();
        if (!is_array($in)) return $out;
        foreach (array_keys(self::seller_labels()) as $k) {
            if (!array_key_exists($k, $in)) continue;
            $v = (string) $in[$k];
            if ($k === 'eposta')   $v = sanitize_email($v);
            elseif ($k === 'web')  $v = esc_url_raw(trim($v));
            elseif ($k === 'iban') $v = strtoupper(preg_replace('/[^A-Za-z0-9 ]/', '', $v));
            else                   $v = sanitize_text_field($v);
            $out[$k] = trim($v);
        }
        return $out;
    }

    public static function settings_page() {
        $s = self::seller();
        echo '<div class="wrap"><h1>Sözleşmeler — Satıcı künyesi</h1>';
        echo '<p>Bu ekran mağazanın tek satıcı künyesini (<code>kinetik_seller_info</code>) düzenler. Künye; ödeme adımındaki Mesafeli Satış Sözleşmesi ve Ön Bilgilendirme Formu’nun <strong>satıcı</strong> bölümünde kullanılır. Sözleşme metinlerinin kendisi <code>/mesafeli-satis-sozlesmesi/</code> ve <code>/on-bilgilendirme-formu/</code> sayfalarından okunur; künye değişince yasal sayfaları güncellemek için <code>wp eval-file demo/fill-legal-pages.php</code> çalıştırın.</p>';
        echo '<p>Boş bırakılan alanlar sitede uydurma değer yerine görünür bir “eklenecek” işaretiyle gösterilir.</p>';
        $eksik = self::missing_seller_fields();
        if ($eksik) echo '<div class="notice notice-warning inline"><p><strong>Eksik zorunlu alanlar:</strong> ' . esc_html(implode(', ', $eksik)) . ' — mevzuat bu bilgilerin sözleşmede yer almasını istiyor. Yasal metinler satışa açılmadan önce nihai bilgilerle güncellenmeli ve profesyonel hukuk kontrolünden geçirilmelidir.</p></div>';
        echo '<form method="post" action="options.php">';
        settings_fields('kinetik_sozlesmeler');
        echo '<table class="form-table">';
        foreach (self::seller_labels() as $k => $label) {
            echo '<tr><th scope="row"><label for="kinetik_seller_' . esc_attr($k) . '">' . esc_html($label) . '</label></th><td><input type="text" class="regular-text" id="kinetik_seller_' . esc_attr($k) . '" name="' . esc_attr(self::OPT_SELLER) . '[' . esc_attr($k) . ']" value="' . esc_attr($s[$k]) . '"></td></tr>';
        }
        echo '</table>';
        submit_button();
        echo '</form></div>';
    }

    public static function admin_notice() {
        if (!current_user_can('manage_woocommerce')) return;
        $screen = get_current_screen();
        if (!$screen || strpos((string) $screen->id, 'kinetik-sozlesmeler') !== false) return;
        $eksik = self::missing_seller_fields();
        if (!$eksik) return;
        echo '<div class="notice notice-warning"><p><strong>KİNETİK Sözleşmeler:</strong> satıcı künyesinde eksik zorunlu alanlar var (' . esc_html(implode(', ', $eksik)) . '). <a href="' . esc_url(admin_url('admin.php?page=kinetik-sozlesmeler')) . '">Şimdi doldur</a>.</p></div>';
    }
}

add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) Kinetik_Sozlesmeler::init();
});
