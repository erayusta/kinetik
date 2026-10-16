<?php
/**
 * Plugin Name: KİNETİK Growth
 * Description: Büyüme otomasyonları — Havale/EFT ödeme hatırlatması, teslimat sonrası değerlendirme isteği, "stoğa gelince haber ver" (beden/varyasyon destekli) ve Google Shopping ürün feed'i (/?kinetik_feed=google). Hafif, dürüst, cron tabanlı.
 * Version: 1.0.0
 * Author: Erusoft
 * Author URI: https://erusoft.com
 * Plugin URI: https://github.com/erayusta/kinetik
 * License: GPL-2.0-or-later
 * Text Domain: kinetik-growth
 * Requires Plugins: woocommerce
 *
 * Stok bildirimi — hangi kancalar:
 *  1. `woocommerce_get_stock_html` filtresi (asıl yol). Basit üründe "Stokta yok" satırının
 *     altına kart ekler. Değişken üründe WooCommerce her varyasyonun `availability_html`
 *     alanını bu fonksiyonla ürettiği için, müşteri tükenmiş bir BEDENİ seçtiğinde kart o
 *     bedene özel olarak (varyasyon kimliğiyle) görünür → bildirim beden bazındadır.
 *     Tema kendi beden butonlarını kullansa da altta WooCommerce varyasyon formu ve
 *     .woocommerce-variation-availability alanı korunduğu sürece çalışır.
 *  2. `woocommerce_single_product_summary` (öncelik 31) yedeği: tüm bedenleri tükenmiş
 *     değişken ürün ya da stok satırı basılmayan şablonlar için ürün düzeyinde kart.
 *     Aynı sayfada iki kez basılmaz.
 *  3. Tema istediği yere `do_action('kinetik_stock_alert')` ile ürün düzeyindeki kartı basabilir.
 * Gönderim: `woocommerce_variation_set_stock_status` (beden stoğa girince o bedeni
 * bekleyenler) + `woocommerce_product_set_stock_status` (basit ürün ya da değişken ürünün
 * ebeveyni tükenmişten stoğa dönünce ürün düzeyinde bekleyenler). Herkese tek e-posta.
 */
if (!defined('ABSPATH')) exit;

final class Kinetik_Growth {

    const REMINDER_META  = '_kinetik_bacs_reminder_sent';
    const REVIEW_META    = '_kinetik_review_request_sent';
    const ALERT_CPT      = 'kinetik_stock_alert';
    const REMINDER_DELAY = 3 * HOUR_IN_SECONDS;   // havale hatırlatması: 3 saat
    const REVIEW_DELAY   = 7 * DAY_IN_SECONDS;    // değerlendirme isteği: tamamlandıktan 7 gün sonra
    /** Marka adı: satıcı künyesindeki "marka", yoksa site başlığı. Filtre: kinetik_brand_name. */
    private static function brand() {
        $s = get_option('kinetik_seller_info', []);
        $b = is_array($s) ? trim((string) ($s['marka'] ?? '')) : '';
        if ($b === '') $b = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        return (string) apply_filters('kinetik_brand_name', $b);
    }

    private static $alert_printed = false;

    public static function init() {
        if (!class_exists('WooCommerce')) return;

        add_action('init', function () {
            if (!wp_next_scheduled('kinetik_growth_hourly')) wp_schedule_event(time() + 300, 'hourly', 'kinetik_growth_hourly');
            if (!wp_next_scheduled('kinetik_growth_daily'))  wp_schedule_event(time() + 600, 'daily', 'kinetik_growth_daily');
        });
        add_action('kinetik_growth_hourly', [__CLASS__, 'send_bacs_reminders']);
        add_action('kinetik_growth_daily',  [__CLASS__, 'send_review_requests']);

        // Stoğa gelince haber ver
        add_action('init', [__CLASS__, 'register_alert_cpt']);
        add_filter('woocommerce_get_stock_html', [__CLASS__, 'append_stock_alert'], 20, 2);
        add_action('woocommerce_single_product_summary', [__CLASS__, 'summary_fallback'], 31);
        add_action('kinetik_stock_alert', [__CLASS__, 'summary_fallback']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_stock_alert']);
        add_action('wp_ajax_kinetik_stock_alert', [__CLASS__, 'ajax_stock_alert']);
        add_action('wp_ajax_nopriv_kinetik_stock_alert', [__CLASS__, 'ajax_stock_alert']);
        add_action('woocommerce_product_set_stock_status', [__CLASS__, 'on_stock_status'], 10, 3);
        add_action('woocommerce_variation_set_stock_status', [__CLASS__, 'on_stock_status'], 10, 3);
        add_filter('woocommerce_loop_add_to_cart_link', [__CLASS__, 'loop_notify_button'], 20, 2);
        add_filter('manage_' . self::ALERT_CPT . '_posts_columns', [__CLASS__, 'alert_columns']);
        add_action('manage_' . self::ALERT_CPT . '_posts_custom_column', [__CLASS__, 'alert_column'], 10, 2);

        // Google Shopping feed
        add_action('template_redirect', [__CLASS__, 'maybe_render_feed']);
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('kinetik_growth_hourly');
        wp_clear_scheduled_hook('kinetik_growth_daily');
    }

    /* ---------------- Havale/EFT hatırlatma (on-hold bacs, 3+ saat, 1 kez) ---------------- */
    public static function send_bacs_reminders() {
        $orders = wc_get_orders([
            'status'         => 'on-hold',
            'payment_method' => 'bacs',
            'date_created'   => '<' . (time() - self::REMINDER_DELAY),
            'limit'          => 20,
        ]);
        $sent = 0;
        foreach ($orders as $order) {
            if ($order->get_meta(self::REMINDER_META)) continue;
            $to = $order->get_billing_email();
            if (!$to) continue;

            $name  = $order->get_billing_first_name();
            $no    = $order->get_order_number();
            $total = $order->get_formatted_order_total();
            $url   = $order->get_checkout_order_received_url();

            $html  = '<p>Merhaba' . ($name ? ' ' . esc_html($name) : '') . ',</p>';
            $html .= '<p><strong>#' . esc_html($no) . '</strong> numaralı siparişini ayırdık; ödemen henüz bize ulaşmadı. '
                   . 'Havale/EFT bilgileri sipariş onay e-postanda ve aşağıdaki bağlantıda yer alıyor.</p>';
            $html .= '<p style="font-size:18px;margin:16px 0"><strong>Tutar: ' . wp_kses_post($total) . '</strong></p>';
            $html .= '<p>Açıklama kısmına <strong>sipariş numaranı (#' . esc_html($no) . ')</strong> yazmayı unutma — eşleşme hızlanır, siparişin daha erken kargoya çıkar.</p>';
            $html .= '<p style="margin-top:22px">' . self::button($url, 'Sipariş detayını gör') . '</p>';
            $html .= '<p style="color:#777;font-size:12px;margin-top:22px">Ödemeni yaptıysan bu e-postayı dikkate alma — onayı kısa süre içinde ileteceğiz.</p>';

            self::send_branded($to, 'Siparişin seni bekliyor — #' . $no, 'Siparişin seni bekliyor', $html);
            $order->update_meta_data(self::REMINDER_META, time());
            $order->save();
            $sent++;
        }
        return $sent;
    }

    /* ---------------- Değerlendirme isteği (completed, 7+ gün, 1 kez) ---------------- */
    public static function send_review_requests() {
        $orders = wc_get_orders([
            'status'         => 'completed',
            'date_completed' => '<' . (time() - self::REVIEW_DELAY),
            'limit'          => 20,
        ]);
        $sent = 0;
        foreach ($orders as $order) {
            if ($order->get_meta(self::REVIEW_META)) continue;
            $to = $order->get_billing_email();
            if (!$to) continue;

            $name = $order->get_billing_first_name();
            $items_html = '';
            $seen = [];
            foreach ($order->get_items() as $item) {
                // Yorumlar ana ürüne yazılır; aynı ürünün iki bedeni tek satır olsun.
                $pid = (int) $item->get_product_id();
                if (!$pid || isset($seen[$pid])) continue;
                $p = wc_get_product($pid);
                if (!$p) continue;
                $seen[$pid] = true;
                $items_html .= '<li style="margin:6px 0"><a href="' . esc_url($p->get_permalink() . '#reviews') . '" style="color:#0D0D0D;font-weight:bold">'
                    . esc_html($p->get_name()) . '</a></li>';
            }
            if (!$items_html) continue;

            $html  = '<p>Merhaba' . ($name ? ' ' . esc_html($name) : '') . ',</p>';
            $html .= '<p>Siparişini teslim aldın; umarız kalıbı da kumaşı da beklediğin gibi çıkmıştır. Birkaç cümlelik değerlendirmen '
                   . '— özellikle beden ve kalıp hakkında — hem bize yol gösterir hem de doğru bedeni arayan diğer müşterilere yardımcı olur.</p>';
            $html .= '<p><strong>Değerlendirebileceğin ürünler:</strong></p><ul>' . $items_html . '</ul>';

            self::send_branded($to, 'Siparişin nasıldı? Fikrin bizim için değerli', 'Siparişin nasıldı?', $html);
            $order->update_meta_data(self::REVIEW_META, time());
            $order->save();
            $sent++;
        }
        return $sent;
    }

    private static function button($url, $label) {
        return '<a href="' . esc_url($url) . '" style="background:#0D0D0D;color:#C8FF2E;padding:14px 28px;text-decoration:none;font-weight:bold;letter-spacing:.06em;text-transform:uppercase;font-size:13px;display:inline-block">' . esc_html($label) . '</a>';
    }

    /** WooCommerce e-posta şablonuyla sarılı gönderim. */
    private static function send_branded($to, $subject, $heading, $html) {
        $mailer  = WC()->mailer();
        $wrapped = $mailer->wrap_message($heading, $html);
        $mailer->send($to, $subject, $wrapped);
    }

    /* ---------------- Google Shopping XML feed: /?kinetik_feed=google ---------------- */

    private static function feed_category($product_id) {
        $slugs = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'slugs']);
        $slugs = is_array($slugs) ? $slugs : [];
        if (in_array('aksesuar', $slugs, true)) return 'Apparel &amp; Accessories &gt; Clothing Accessories';
        if (in_array('tisort', $slugs, true))   return 'Apparel &amp; Accessories &gt; Clothing &gt; Shirts &amp; Tops';
        return 'Apparel &amp; Accessories &gt; Clothing &gt; Activewear';
    }

    private static function feed_gender($parent) {
        $val = $parent->get_attribute('pa_cinsiyet');
        $val = mb_strtolower((string) $val);
        if ($val === '') return '';
        if (strpos($val, ',') !== false || strpos($val, 'unisex') !== false) return 'unisex';
        if (strpos($val, 'kad') !== false) return 'female';
        if (strpos($val, 'erkek') !== false) return 'male';
        return 'unisex';
    }

    private static function feed_shipping_price($price) {
        $threshold = class_exists('Kinetik_Marketing') ? Kinetik_Marketing::free_shipping_threshold() : 2000;
        $flat      = class_exists('Kinetik_Marketing') ? Kinetik_Marketing::flat_shipping_cost() : 99;
        return (float) $price >= $threshold ? 0.0 : $flat;
    }

    private static function feed_item(WC_Product $p, WC_Product $parent, $group_id = 0) {
        $price = $p->get_regular_price() !== '' ? $p->get_regular_price() : $p->get_price();
        if (!(float) $price) return;
        $img_id = $p->get_image_id() ?: $parent->get_image_id();
        $img = $img_id ? wp_get_attachment_url($img_id) : '';
        if (!$img) return;   // görselsiz ürünü Google zaten reddediyor

        $desc = wp_strip_all_tags($parent->get_short_description() ?: $parent->get_description());
        $desc = mb_substr(trim(preg_replace('/\s+/', ' ', $desc)), 0, 4990);
        $title = $p->get_name();

        echo '<item>';
        echo '<g:id>' . esc_html($p->get_sku() ?: ('KINETIK-' . $p->get_id())) . '</g:id>';
        if ($group_id) echo '<g:item_group_id>' . esc_html($parent->get_sku() ?: ('KINETIK-' . $group_id)) . '</g:item_group_id>';
        echo '<g:title><![CDATA[' . $title . ']]></g:title>';
        echo '<g:description><![CDATA[' . ($desc ?: $title) . ']]></g:description>';
        echo '<g:link>' . esc_url($p->get_permalink()) . '</g:link>';
        echo '<g:image_link>' . esc_url($img) . '</g:image_link>';
        foreach (array_slice($parent->get_gallery_image_ids(), 0, 10) as $gid) {
            $gu = wp_get_attachment_url($gid);
            if ($gu) echo '<g:additional_image_link>' . esc_url($gu) . '</g:additional_image_link>';
        }
        echo '<g:availability>' . ($p->is_in_stock() ? 'in_stock' : 'out_of_stock') . '</g:availability>';
        echo '<g:price>' . wc_format_decimal($price, 2) . ' TRY</g:price>';
        if ($p->is_on_sale() && $p->get_sale_price() !== '') echo '<g:sale_price>' . wc_format_decimal($p->get_sale_price(), 2) . ' TRY</g:sale_price>';
        echo '<g:brand>' . esc_html(self::brand()) . '</g:brand><g:condition>new</g:condition>';
        // Kendi markamız: üretici GTIN'i yok → identifier_exists=no + kendi SKU'muz mpn.
        echo '<g:mpn>' . esc_html($p->get_sku() ?: ('KINETIK-' . $p->get_id())) . '</g:mpn>';
        echo '<g:identifier_exists>no</g:identifier_exists>';
        echo '<g:google_product_category>' . self::feed_category($parent->get_id()) . '</g:google_product_category>';
        echo '<g:product_type><![CDATA[' . implode(' > ', (array) wp_get_post_terms($parent->get_id(), 'product_cat', ['fields' => 'names'])) . ']]></g:product_type>';
        echo '<g:age_group>adult</g:age_group>';
        $gender = self::feed_gender($parent);
        if ($gender) echo '<g:gender>' . $gender . '</g:gender>';
        $size = $p->is_type('variation') ? $p->get_attribute('pa_beden') : '';
        if ($size === '' && !$p->is_type('variation')) {
            $sizes = array_map('trim', explode(',', (string) $p->get_attribute('pa_beden')));
            if (count($sizes) === 1 && $sizes[0] !== '') $size = $sizes[0];
        }
        if ($size !== '') echo '<g:size>' . esc_html($size) . '</g:size>';
        echo '<g:shipping><g:country>TR</g:country><g:service>Standart</g:service><g:price>' . wc_format_decimal(self::feed_shipping_price($p->get_price()), 2) . ' TRY</g:price></g:shipping>';
        echo '</item>';
    }

    public static function maybe_render_feed() {
        if (!isset($_GET['kinetik_feed']) || $_GET['kinetik_feed'] !== 'google') return; // phpcs:ignore

        $products = wc_get_products(['status' => 'publish', 'limit' => -1, 'visibility' => 'visible']);
        nocache_headers();
        header('Content-Type: application/xml; charset=UTF-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>';
        echo '<title>' . esc_html(self::brand()) . '</title><link>' . esc_url(home_url('/')) . '</link><description>' . esc_html(self::brand()) . ' ürün kataloğu</description>';

        foreach ($products as $p) {
            if ($p->is_type('variable')) {
                // Google giyimde her beden ayrı ürün + ortak item_group_id ister.
                foreach ($p->get_children() as $vid) {
                    $v = wc_get_product($vid);
                    if ($v && $v->get_status() === 'publish') self::feed_item($v, $p, $p->get_id());
                }
            } elseif ($p->is_type('simple')) {
                self::feed_item($p, $p);
            }
        }
        echo '</channel></rss>';
        exit;
    }

    /* ================================================================
     * STOĞA GELİNCE HABER VER
     * ================================================================ */

    public static function register_alert_cpt() {
        register_post_type(self::ALERT_CPT, [
            'labels' => ['name' => 'Stok Bildirimleri', 'singular_name' => 'Stok Bildirimi', 'menu_name' => 'Stok Bildirimleri'],
            'public' => false, 'show_ui' => true, 'show_in_menu' => true,
            'menu_icon' => 'dashicons-bell', 'supports' => ['title'],
            'capabilities' => ['create_posts' => 'do_not_allow'], 'map_meta_cap' => true,
        ]);
    }

    /** "Stokta yok" satırının altına kart (basit ürün ya da seçilen tükenmiş beden). */
    public static function append_stock_alert($html, $product) {
        if (!$product instanceof WC_Product || $product->is_in_stock()) return $html;
        if ($product->is_type('variation')) {
            // Varyasyon verisi PDP'de (ya da >30 varyasyonda AJAX ile) üretilir.
            $parent_ok = (function_exists('is_product') && is_product() && (int) $product->get_parent_id() === (int) get_queried_object_id()) || wp_doing_ajax();
            if (!$parent_ok) return $html;
            return $html . self::stock_alert_html($product, false);
        }
        if (!function_exists('is_product') || !is_product() || (int) $product->get_id() !== (int) get_queried_object_id()) return $html;
        // Blok temalarda özet kancası stok satırından ÖNCE çalışabiliyor; kart bir kez basılır.
        if (self::$alert_printed) return $html;
        self::$alert_printed = true;
        return $html . self::stock_alert_html($product, true);
    }

    /** Ürün düzeyinde yedek kart (tümü tükenmiş değişken ürün / stok satırı basmayan şablon). */
    public static function summary_fallback() {
        global $product;
        if (self::$alert_printed || !$product instanceof WC_Product || $product->is_in_stock()) return;
        self::$alert_printed = true;
        echo self::stock_alert_html($product, true); // phpcs:ignore — içerik escape edildi
    }

    private static function stock_alert_html(WC_Product $product, $primary) {
        $count = self::alert_count($product->get_id());
        $size  = $product->is_type('variation') ? $product->get_attribute('pa_beden') : '';
        $title = $size !== '' ? sprintf('%s beden stoğa gelince haber verelim', $size) : 'Stoğa gelince haber verelim';
        $uid   = 'kinetik-sa-' . $product->get_id();
        ob_start(); ?>
<div class="kinetik-stock-alert"<?php echo $primary ? ' id="kinetik-stock-alert"' : ''; ?> data-product="<?php echo esc_attr($product->get_id()); ?>">
  <div class="kinetik-stock-alert__head">
    <span class="kinetik-stock-alert__eyebrow"><?php echo $size !== '' ? esc_html($size) . ' beden şu an tükendi' : 'Şu an stokta yok'; ?></span>
    <p class="kinetik-stock-alert__title"><?php echo esc_html($title); ?></p>
    <p class="kinetik-stock-alert__text">E-postanı bırak; <?php echo $size !== '' ? 'bu beden' : 'ürün'; ?> yeniden stoğa girdiği an sana tek bir e-posta gönderelim.</p>
  </div>
  <form class="kinetik-stock-alert__form" novalidate>
    <label class="screen-reader-text" for="<?php echo esc_attr($uid); ?>">E-posta adresin</label>
    <input id="<?php echo esc_attr($uid); ?>" type="email" name="email" placeholder="E-posta adresin" autocomplete="email" required>
    <button type="submit" class="button">Haber ver</button>
  </form>
  <p class="kinetik-stock-alert__note"><?php echo $count > 1 ? esc_html($count) . ' kişi bekliyor · ' : ''; ?>Spam yok, yalnızca tek e-posta.</p>
  <div class="kinetik-stock-alert__done" hidden>
    <b>Tamam, listedesin.</b>
    <span><?php echo $size !== '' ? 'Bu beden' : 'Ürün'; ?> stoğa girince <em></em> adresine haber vereceğiz.</span>
  </div>
</div>
<?php
        return trim(ob_get_clean());
    }

    public static function enqueue_stock_alert() {
        if (!function_exists('is_product') || !is_product()) return;
        $ajax  = esc_url_raw(admin_url('admin-ajax.php'));
        $nonce = wp_create_nonce('kinetik_stock_alert');
        $js = <<<JS
(function(){
  document.addEventListener('submit',function(e){
    var form=e.target; if(!form.classList||!form.classList.contains('kinetik-stock-alert__form')) return;
    e.preventDefault();
    var box=form.closest('.kinetik-stock-alert'), input=form.querySelector('input[type=email]'), btn=form.querySelector('button');
    var v=(input.value||'').trim();
    if(!v||v.indexOf('@')<1){input.classList.add('is-error');input.focus();return;}
    input.classList.remove('is-error'); btn.disabled=true; btn.textContent='Kaydediliyor…';
    var fd=new FormData(); fd.append('action','kinetik_stock_alert'); fd.append('nonce','{$nonce}'); fd.append('email',v); fd.append('product',box.getAttribute('data-product'));
    fetch('{$ajax}',{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();}).then(function(j){
      if(j&&j.success){box.querySelector('.kinetik-stock-alert__done em').textContent=v;form.hidden=true;box.querySelector('.kinetik-stock-alert__note').hidden=true;box.querySelector('.kinetik-stock-alert__done').hidden=false;box.classList.add('is-done');}
      else{btn.disabled=false;btn.textContent='Haber ver';input.classList.add('is-error');}
    }).catch(function(){btn.disabled=false;btn.textContent='Haber ver';});
  });
})();
JS;
        wp_register_script('kinetik-stock-alert', false, [], '1.0.0', true);
        wp_enqueue_script('kinetik-stock-alert');
        wp_add_inline_script('kinetik-stock-alert', $js);
        $css = '.kinetik-stock-alert{margin:16px 0;padding:16px;border:1px solid #E2DFD8;background:#F3F1EC}'
             . '.kinetik-stock-alert__eyebrow{display:block;font-size:11px;letter-spacing:.12em;text-transform:uppercase;opacity:.7}'
             . '.kinetik-stock-alert__title{margin:4px 0;font-weight:700;font-size:16px}'
             . '.kinetik-stock-alert__text,.kinetik-stock-alert__note{font-size:13px;margin:0 0 10px}'
             . '.kinetik-stock-alert__form{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 8px}'
             . '.kinetik-stock-alert__form input{flex:1 1 180px;min-width:0}'
             . '.kinetik-stock-alert__form input.is-error{outline:2px solid #B3261E}'
             . '.kinetik-stock-alert__done[hidden],.kinetik-stock-alert__form[hidden],.kinetik-stock-alert__note[hidden]{display:none!important}';
        wp_register_style('kinetik-stock-alert', false, [], '1.0.0');
        wp_enqueue_style('kinetik-stock-alert');
        wp_add_inline_style('kinetik-stock-alert', $css);
    }

    public static function ajax_stock_alert() {
        check_ajax_referer('kinetik_stock_alert', 'nonce');
        $email   = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $pid     = isset($_POST['product']) ? absint($_POST['product']) : 0;
        $product = $pid ? wc_get_product($pid) : null;
        if (!is_email($email) || !$product) wp_send_json_error(['message' => 'Geçersiz e-posta.']);
        if ($product->is_in_stock()) wp_send_json_error(['message' => 'Ürün zaten stokta.']);
        if (!self::find_alert($email, $pid)) {
            $id = wp_insert_post([
                'post_type'   => self::ALERT_CPT,
                'post_status' => 'publish',
                'post_title'  => $email . ' — ' . $product->get_name(),
            ]);
            if ($id && !is_wp_error($id)) {
                update_post_meta($id, '_kinetik_alert_email', $email);
                update_post_meta($id, '_kinetik_alert_product', $pid);
                update_post_meta($id, '_kinetik_alert_parent', $product->get_parent_id() ?: $pid);
                update_post_meta($id, '_kinetik_alert_status', 'pending');
            }
        }
        wp_send_json_success();
    }

    private static function find_alert($email, $pid) {
        $found = get_posts([
            'post_type' => self::ALERT_CPT, 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'any',
            'meta_query' => [
                ['key' => '_kinetik_alert_email', 'value' => $email],
                ['key' => '_kinetik_alert_product', 'value' => $pid],
                ['key' => '_kinetik_alert_status', 'value' => 'pending'],
            ],
        ]);
        return $found ? (int) $found[0] : 0;
    }

    private static function alert_count($pid) {
        $q = new WP_Query(['post_type' => self::ALERT_CPT, 'fields' => 'ids', 'posts_per_page' => 1, 'post_status' => 'any',
            'meta_query' => [['key' => '_kinetik_alert_product', 'value' => $pid], ['key' => '_kinetik_alert_status', 'value' => 'pending']]]);
        return (int) $q->found_posts;
    }

    /** Ürün/varyasyon stok durumu instock'a dönünce bekleyenlere tek seferlik e-posta. */
    public static function on_stock_status($product_id, $status, $product = null) {
        if ($status !== 'instock') return;
        self::notify_back_in_stock($product_id);
    }

    public static function notify_back_in_stock($product_id) {
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_in_stock()) return 0;
        $alerts = get_posts([
            'post_type' => self::ALERT_CPT, 'numberposts' => -1, 'post_status' => 'any',
            'meta_query' => [['key' => '_kinetik_alert_product', 'value' => $product_id], ['key' => '_kinetik_alert_status', 'value' => 'pending']],
        ]);
        if (!$alerts) return 0;
        $parent = $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : $product;
        $img_id = $product->get_image_id() ?: ($parent ? $parent->get_image_id() : 0);
        $img    = $img_id ? wp_get_attachment_image_url($img_id, 'woocommerce_thumbnail') : '';
        $url    = add_query_arg(['utm_source' => 'kinetik', 'utm_medium' => 'email', 'utm_campaign' => 'back-in-stock'], $product->get_permalink());
        $size   = $product->is_type('variation') ? $product->get_attribute('pa_beden') : '';

        $sent = 0;
        foreach ($alerts as $alert) {
            $email = get_post_meta($alert->ID, '_kinetik_alert_email', true);
            if (!is_email($email)) continue;
            $html  = '<p style="font-size:15px;line-height:1.6">' . ($size !== '' ? 'Beklediğin <strong>' . esc_html($size) . '</strong> beden yeniden stokta.' : 'Beklediğin ürün yeniden stokta.')
                   . ' Bu e-posta yalnızca sen istediğin için ve bir kez gönderildi.</p>';
            if ($img) $html .= '<p style="margin:18px 0"><a href="' . esc_url($url) . '"><img src="' . esc_url($img) . '" alt="" width="180" style="display:block"></a></p>';
            $html .= '<p style="font-size:18px;margin:0 0 6px"><strong>' . esc_html($product->get_name()) . '</strong></p>';
            $html .= '<p style="font-size:16px;margin:0 0 20px">' . wp_kses_post($product->get_price_html()) . '</p>';
            $html .= '<p>' . self::button($url, 'Ürünü gör') . '</p>';
            $html .= '<p style="font-size:13px;color:#555;margin-top:22px">Stoklar sınırlı; beden tükenmeden siparişini verebilirsin.</p>';
            self::send_branded($email, 'Stoğa geldi: ' . $product->get_name(), $size !== '' ? 'Bedenin stokta' : 'Beklediğin ürün stokta', $html);
            update_post_meta($alert->ID, '_kinetik_alert_status', 'sent');
            update_post_meta($alert->ID, '_kinetik_alert_sent_at', time());
            $sent++;
        }
        return $sent;
    }

    /** Tükenmiş ürün kartında "Devamını oku" yerine "Haber ver". */
    public static function loop_notify_button($link, $product) {
        if (!$product instanceof WC_Product || $product->is_in_stock()) return $link;
        return sprintf('<a href="%s" class="button kinetik-loop-notify" aria-label="%s">Haber ver</a>',
            esc_url($product->get_permalink() . '#kinetik-stock-alert'),
            esc_attr('Stoğa gelince haber ver: ' . $product->get_name()));
    }

    public static function alert_columns($cols) {
        return ['cb' => $cols['cb'] ?? '', 'title' => 'E-posta', 'kinetik_product' => 'Ürün / beden', 'kinetik_status' => 'Durum', 'date' => 'Tarih'];
    }

    public static function alert_column($col, $post_id) {
        if ($col === 'kinetik_product') {
            $p = wc_get_product((int) get_post_meta($post_id, '_kinetik_alert_product', true));
            $edit = $p ? get_edit_post_link($p->get_parent_id() ?: $p->get_id()) : '';
            echo $p ? '<a href="' . esc_url($edit) . '">' . esc_html($p->get_name()) . '</a>' : '—';
        }
        if ($col === 'kinetik_status') {
            $st = get_post_meta($post_id, '_kinetik_alert_status', true);
            echo $st === 'sent' ? 'Gönderildi · ' . esc_html(date_i18n('d.m.Y H:i', (int) get_post_meta($post_id, '_kinetik_alert_sent_at', true))) : 'Bekliyor';
        }
    }
}
add_action('plugins_loaded', ['Kinetik_Growth', 'init']);
register_deactivation_hook(__FILE__, ['Kinetik_Growth', 'deactivate']);
