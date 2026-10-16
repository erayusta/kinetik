<?php
/**
 * Plugin Name: KİNETİK TR Adres
 * Description: Türkiye il / ilçe / mahalle / cadde-sokak kademeli checkout adres sistemi (NVİ verisi). Ülke seçici kaldırılır, Türkiye sabittir. Klasik WooCommerce checkout ile çalışır.
 * Version: 1.0.0
 * Author: Erusoft
 * Author URI: https://erusoft.com
 * Plugin URI: https://github.com/erayusta/kinetik
 * License: GPL-2.0-or-later
 * Text Domain: kinetik-tr-adres
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) exit;

class Kinetik_TR_Adres {

    public static function init() {
        if (!class_exists('WooCommerce')) return;

        add_filter('woocommerce_checkout_fields', [__CLASS__, 'checkout_fields'], 20);
        add_filter('woocommerce_billing_fields', [__CLASS__, 'billing_fields'], 20);
        add_filter('woocommerce_shipping_fields', [__CLASS__, 'shipping_fields'], 20);
        add_filter('woocommerce_default_address_fields', [__CLASS__, 'address_field_labels'], 20);
        add_filter('woocommerce_get_country_locale', [__CLASS__, 'country_locale'], 20);
        add_filter('woocommerce_localisation_address_formats', [__CLASS__, 'address_format'], 20);

        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 20);

        add_action('woocommerce_checkout_process', [__CLASS__, 'validate_phone']);
        add_filter('woocommerce_process_checkout_field_billing_phone', [__CLASS__, 'normalize_phone']);
        add_filter('woocommerce_process_checkout_field_shipping_phone', [__CLASS__, 'normalize_phone']);
        add_action('woocommerce_checkout_update_order_meta', [__CLASS__, 'save_order_meta']);
        add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'admin_order_fields']);
        add_filter('woocommerce_order_formatted_billing_address', [__CLASS__, 'merge_into_address'], 10, 2);
        add_filter('woocommerce_order_formatted_shipping_address', [__CLASS__, 'merge_into_shipping_address'], 10, 2);
    }

    /* ---------------------------------------------------- Checkout (billing) */
    public static function checkout_fields($fields) {
        // İletişim önce (telefon + e-posta), sonra adres kademeleri
        // Telefon ZORUNLU: kargo firması teslimat öncesi arıyor, telefonsuz sipariş
        // adrese teslim edilemiyor. WooCommerce TR yereli bunu isteğe bağlı bırakıyordu
        // ("Telefon (isteğe bağlı)" görünüyordu) — 14 Eyl 2026.
        $fields['billing']['billing_phone']['priority']    = 25;
        $fields['billing']['billing_phone']['label']       = 'Telefon';
        $fields['billing']['billing_phone']['required']    = true;
        $fields['billing']['billing_phone']['placeholder'] = '05XX XXX XX XX';
        // Mobilde sayısal klavye açılsın (type=tel tek başına her cihazda yetmiyor)
        $fields['billing']['billing_phone']['custom_attributes'] = ['inputmode' => 'tel', 'maxlength' => 20];
        $fields['billing']['billing_phone']['class']       = ['form-row-first'];
        $fields['billing']['billing_email']['priority'] = 26;
        $fields['billing']['billing_email']['label']    = 'E-posta';
        $fields['billing']['billing_email']['class']    = ['form-row-last'];

        $fields['billing']['billing_state']['label']    = 'İl';
        $fields['billing']['billing_state']['priority'] = 45;

        $fields['billing']['billing_district'] = [
            'type' => 'select', 'label' => 'İlçe', 'required' => true, 'priority' => 46,
            'class' => ['form-row-wide', 'kinetik-district-row'], 'options' => ['' => 'Önce il seçin'],
        ];
        // Mahalle ve cadde/sokak ZORUNLU. Veri eksiksiz (örneklenen 9.691 mahallenin
        // hepsinde sokak kaydı var) ve her listede "Listede yok / elle gireceğim"
        // seçeneği bulunuyor — kimse kilitlenmez.
        $fields['billing']['billing_neighborhood'] = [
            'type' => 'select', 'label' => 'Mahalle / Köy', 'required' => true, 'priority' => 47,
            'class' => ['form-row-first', 'kinetik-neighborhood-row'], 'options' => ['' => 'Önce ilçe seçin'],
        ];
        $fields['billing']['billing_street'] = [
            'type' => 'select', 'label' => 'Cadde / Sokak', 'required' => true, 'priority' => 47,
            'class' => ['form-row-last', 'kinetik-street-row'], 'options' => ['' => 'Önce mahalle seçin'],
        ];
        $fields['billing']['billing_address_1']['label']       = 'Bina No / Kat / Daire';
        $fields['billing']['billing_address_1']['placeholder'] = 'Örn: No:69 Kat:2 Daire:5';
        $fields['billing']['billing_address_1']['priority']    = 48;
        // Şehir: il seçiminden JS ile doluyor, kullanıcıya SORULMAZ. Eskiden
        // "Şehir (otomatik) (isteğe bağlı)" diye boş bir kutu olarak duruyordu.
        $fields['billing']['billing_city'] = self::gizli_alan($fields['billing']['billing_city'] ?? [], 49);

        // Posta kodu: Yurtiçi Kargo istemiyor, kimse doldurmuyor — formdan çıktı.
        unset(
            $fields['billing']['billing_address_2'],
            $fields['billing']['billing_company'],
            $fields['billing']['billing_postcode']
        );

        // Ülke seçici kaldırılsın: Türkiye sabit, gizli alan
        if (isset($fields['billing']['billing_country'])) {
            $fields['billing']['billing_country']['type']    = 'hidden';
            $fields['billing']['billing_country']['default'] = 'TR';
            $fields['billing']['billing_country']['class']   = ['kinetik-country-hidden'];
        }
        return $fields;
    }

    /**
     * TR cep telefonu doğrulama. Alan `type=tel` olduğu için tarayıcı HİÇBİR şey
     * denetlemiyordu: "abc" yazan müşteri sipariş verebiliyor, kargo teslim
     * edemiyordu (14 Eyl 2026). Sabit hat da kabul — 0212'li numara da geçerli.
     */
    public static function tr_telefon($ham) {
        $d = preg_replace('/\D+/', '', (string) $ham);
        if ($d === '') return null;
        if (str_starts_with($d, '90') && strlen($d) === 12) $d = substr($d, 2);   // +90…
        elseif (str_starts_with($d, '0') && strlen($d) === 11) $d = substr($d, 1); // 0…
        if (strlen($d) !== 10 || $d[0] === '0') return null;                       // 10 hane, 0 ile başlamaz
        return '0' . $d;                                                           // 05551112233 / 02121112233
    }

    public static function normalize_phone($deger) {
        $t = self::tr_telefon($deger);
        return $t ? $t : $deger;   // geçersizse dokunma, validate_phone hatayı bildirsin
    }

    public static function validate_phone() {
        $alanlar = ['billing_phone' => 'Telefon'];
        if (!empty($_POST['ship_to_different_address']) && isset($_POST['shipping_phone'])) {
            $alanlar['shipping_phone'] = 'Teslimat telefonu';
        }
        foreach ($alanlar as $alan => $etiket) {
            $ham = isset($_POST[$alan]) ? sanitize_text_field(wp_unslash($_POST[$alan])) : '';
            if ($ham === '') continue;   // zorunluluk kontrolünü WooCommerce yapıyor
            if (self::tr_telefon($ham) === null) {
                wc_add_notice(sprintf(
                    '<strong>%s</strong> geçerli görünmüyor. Türkiye numarası olarak yaz: 0555 111 22 33',
                    esc_html($etiket)
                ), 'error');
            }
        }
    }

    /** Bir checkout alanını gizli girdiye çevirir (değeri JS/sunucu doldurur, kullanıcıya sorulmaz). */
    private static function gizli_alan(array $alan, $oncelik) {
        return array_merge($alan, [
            'type' => 'hidden', 'label' => '', 'placeholder' => '', 'required' => false,
            'priority' => $oncelik, 'class' => ['kinetik-gizli-satir'], 'label_class' => ['screen-reader-text'],
        ]);
    }

    public static function address_field_labels($fields) {
        $fields['state']['label'] = 'İl';
        return $fields;
    }

    /* ---------------------------------------------------- Checkout (shipping) */
    public static function shipping_fields($fields) {
        // Teslimat adresi açıldığında fatura adresiyle aynı kurallar geçerli:
        // yarım adres kargoya verilemez.
        $fields['shipping_district'] = [
            'type' => 'select', 'label' => 'İlçe', 'required' => true, 'priority' => 46,
            'class' => ['form-row-wide', 'kinetik-district-row'], 'options' => ['' => 'Önce il seçin'],
        ];
        $fields['shipping_neighborhood'] = [
            'type' => 'select', 'label' => 'Mahalle / Köy', 'required' => true, 'priority' => 47,
            'class' => ['form-row-first', 'kinetik-neighborhood-row'], 'options' => ['' => 'Önce ilçe seçin'],
        ];
        $fields['shipping_street'] = [
            'type' => 'select', 'label' => 'Cadde / Sokak', 'required' => true, 'priority' => 47,
            'class' => ['form-row-last', 'kinetik-street-row'], 'options' => ['' => 'Önce mahalle seçin'],
        ];
        if (isset($fields['shipping_address_1'])) {
            $fields['shipping_address_1']['label']    = 'Bina No / Kat / Daire';
            $fields['shipping_address_1']['priority'] = 48;
        }
        if (isset($fields['shipping_city'])) $fields['shipping_city'] = self::gizli_alan($fields['shipping_city'], 49);
        // shipping_phone: fatura telefonu zaten zorunlu, ikinci telefon soru kalabalığı.
        unset(
            $fields['shipping_address_2'], $fields['shipping_company'],
            $fields['shipping_postcode'], $fields['shipping_phone']
        );
        return $fields;
    }

    /* -------------------------------------------- Account → Adresler formu */
    public static function billing_fields($fields) {
        if (!function_exists('is_account_page') || !is_account_page()) return $fields;
        $fields['billing_district'] = [
            'type' => 'select', 'label' => 'İlçe', 'required' => true, 'priority' => 46,
            'class' => ['form-row-wide', 'kinetik-district-row'], 'options' => ['' => 'Önce il seçin'],
        ];
        $fields['billing_neighborhood'] = [
            'type' => 'select', 'label' => 'Mahalle / Köy', 'required' => true, 'priority' => 47,
            'class' => ['form-row-first', 'kinetik-neighborhood-row'], 'options' => ['' => 'Önce ilçe seçin'],
        ];
        $fields['billing_street'] = [
            'type' => 'select', 'label' => 'Cadde / Sokak', 'required' => true, 'priority' => 47,
            'class' => ['form-row-last', 'kinetik-street-row'], 'options' => ['' => 'Önce mahalle seçin'],
        ];
        $fields['billing_address_1']['label']    = 'Bina No / Kat / Daire';
        $fields['billing_address_1']['priority'] = 48;
        if (isset($fields['billing_city'])) $fields['billing_city'] = self::gizli_alan($fields['billing_city'], 49);
        if (isset($fields['billing_phone'])) $fields['billing_phone']['required'] = true;
        unset($fields['billing_address_2'], $fields['billing_company'], $fields['billing_postcode']);
        return $fields;
    }

    /** TR yerel ayarı: il üstte, şehir otomatik/gizli */
    public static function country_locale($locale) {
        $locale['TR']['state']     = ['label' => 'İl', 'required' => true, 'priority' => 45];
        $locale['TR']['address_1'] = ['label' => 'Bina No / Kat / Daire', 'placeholder' => 'Örn: No:69 Kat:2 Daire:5', 'priority' => 48];
        $locale['TR']['city']      = ['label' => '', 'required' => false, 'priority' => 49, 'hidden' => true];
        $locale['TR']['postcode']  = ['required' => false, 'hidden' => true, 'priority' => 50];
        return $locale;
    }

    /**
     * TR adres kalıbı. Varsayılan "{postcode} {city} {state}" idi; şehir alanını il
     * adıyla dolduruyoruz (kargo etiketinde il yazsın diye) ve posta kodunu artık
     * sormuyoruz → adres "Adıyaman Adıyaman" diye çift basıyordu (14 Eyl 2026).
     */
    public static function address_format($formats) {
        $formats['TR'] = "{name}\n{address_1}\n{address_2}\n{city}\n{country}";
        return $formats;
    }

    /* ------------------------------------------------------------ Assets */
    public static function assets() {
        if (!function_exists('is_checkout')) return;
        $is_address_edit = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('edit-address');
        if (!is_checkout() && !$is_address_edit) return;

        $deps = ['jquery'];
        foreach (['selectWoo', 'select2'] as $handle) {
            if (wp_script_is($handle, 'registered')) { wp_enqueue_script($handle); $deps[] = $handle; break; }
        }
        if (wp_style_is('select2', 'registered')) wp_enqueue_style('select2');

        wp_enqueue_script('kinetik-tr-adres', plugins_url('adres/tr-adres.js', __FILE__), $deps, (string) filemtime(__DIR__ . '/adres/tr-adres.js'), true);

        $saved = ['billing' => new stdClass(), 'shipping' => new stdClass()];
        if (is_user_logged_in()) {
            $uid = get_current_user_id();
            foreach (['billing', 'shipping'] as $p) {
                $saved[$p] = [
                    'district'     => (string) get_user_meta($uid, $p . '_district', true),
                    'neighborhood' => (string) get_user_meta($uid, $p . '_neighborhood', true),
                    'street'       => (string) get_user_meta($uid, $p . '_street', true),
                ];
            }
        }
        wp_localize_script('kinetik-tr-adres', 'KinetikAdres', [
            'base'  => plugins_url('adres/', __FILE__),
            'v'     => (string) filemtime(__DIR__ . '/adres/il-ilce.json'),
            'saved' => $saved,
        ]);
    }

    /* --------------------------------------------------- Save & display */
    public static function save_order_meta($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        $keys = [
            'billing_district', 'billing_neighborhood', 'billing_street',
            'shipping_district', 'shipping_neighborhood', 'shipping_street',
        ];
        // "Farklı adrese gönder" işaretli DEĞİLSE teslimat adresi faturanın birebir aynısıdır.
        // WooCommerce yalnızca çekirdek alanları kopyalıyor; ilçe/mahalle/sokak (bizim alanlarımız)
        // POST'ta shipping_* olarak gelmediği için teslimat adresi "No:13 İstanbul" diye eksik kalıyordu.
        $ship_diff = !empty($_POST['ship_to_different_address']);
        $val = function ($key) {
            $raw = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
            if ($raw === '__diger__') $raw = '';
            if (substr($key, -9) === '_district') $raw = preg_replace('/^\d+\|/', '', $raw); // "id|Ad" → "Ad"
            return $raw;
        };
        foreach ($keys as $key) {
            $src = (!$ship_diff && strpos($key, 'shipping_') === 0) ? 'billing_' . substr($key, 9) : $key;
            $order->update_meta_data('_' . $key, $val($src));
        }
        $states = WC()->countries->get_states('TR');
        if (!empty($_POST['billing_state'])) {
            $il = $states[sanitize_text_field(wp_unslash($_POST['billing_state']))] ?? '';
            if ($il) $order->set_billing_city($il);
        }
        if ($ship_diff) {
            if (!empty($_POST['shipping_state'])) {
                $il = $states[sanitize_text_field(wp_unslash($_POST['shipping_state']))] ?? '';
                if ($il) $order->set_shipping_city($il);
            }
        } elseif ($order->has_shipping_address() || $order->get_billing_address_1()) {
            // Çekirdek alanları da faturadan eşitle (WooCommerce bazı alanları boş bırakabiliyor).
            $order->set_shipping_first_name($order->get_billing_first_name());
            $order->set_shipping_last_name($order->get_billing_last_name());
            $order->set_shipping_company($order->get_billing_company());
            $order->set_shipping_address_1($order->get_billing_address_1());
            $order->set_shipping_address_2($order->get_billing_address_2());
            $order->set_shipping_city($order->get_billing_city());
            $order->set_shipping_state($order->get_billing_state());
            $order->set_shipping_postcode($order->get_billing_postcode());
            $order->set_shipping_country($order->get_billing_country() ?: 'TR');
            if (method_exists($order, 'set_shipping_phone')) $order->set_shipping_phone($order->get_billing_phone());
        }
        $order->save();
    }

    public static function admin_order_fields($order) {
        $parts = array_filter([
            $order->get_meta('_billing_district'),
            $order->get_meta('_billing_neighborhood'),
            $order->get_meta('_billing_street'),
        ]);
        if ($parts) {
            printf('<p><strong>İlçe / Mahalle / Sokak:</strong> %s</p>', esc_html(implode(' / ', $parts)));
        }
    }

    public static function merge_into_address($address, $order) {
        $district = preg_replace('/^\d+\|/', '', (string) $order->get_meta('_billing_district'));
        $hood     = $order->get_meta('_billing_neighborhood');
        $street   = $order->get_meta('_billing_street');
        if ($street)   $address['address_1'] = trim($street . ' ' . $address['address_1']);
        if ($district) $address['address_2'] = trim(($hood ? $hood . ', ' : '') . $district);
        return $address;
    }

    public static function merge_into_shipping_address($address, $order) {
        $district = preg_replace('/^\d+\|/', '', (string) $order->get_meta('_shipping_district'));
        $hood     = $order->get_meta('_shipping_neighborhood');
        $street   = $order->get_meta('_shipping_street');
        if ($street)   $address['address_1'] = trim($street . ' ' . $address['address_1']);
        if ($district) $address['address_2'] = trim(($hood ? $hood . ', ' : '') . $district);
        return $address;
    }
}

add_action('init', ['Kinetik_TR_Adres', 'init']);
