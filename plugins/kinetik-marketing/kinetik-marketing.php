<?php
/**
 * Plugin Name: KİNETİK Marketing
 * Description: Hafif, dürüst pazarlama araçları: ücretsiz kargo ilerleme çubuğu (eşik WooCommerce ücretsiz kargo ayarından okunur) ve e-posta aboneliği (MERHABA5 hoş geldin kuponu). Eklenti bağımlılığı yok.
 * Version: 1.0.0
 * Author: Erusoft
 * Author URI: https://erusoft.com
 * Plugin URI: https://github.com/erayusta/kinetik
 * License: GPL-2.0-or-later
 * Text Domain: kinetik-marketing
 * Requires Plugins: woocommerce
 *
 * Tema entegrasyonu (bülten formu):
 *   [kinetik_newsletter] kısa kodu ya da kinetik_newsletter_form() hazır form basar. Tema kendi
 *   formunu kullanacaksa kapsayıcıya .kinetik-newsletter, forma .kinetik-newsletter__form, sonuç
 *   alanına .kinetik-newsletter__result sınıfı vermesi yeterli — marketing.js AJAX'ı bağlar.
 */

if (!defined('ABSPATH')) exit;

class Kinetik_Marketing {

    const VER = '1.0.0';
    /** Yalnızca WooCommerce'te min_amount'lı ücretsiz kargo yöntemi bulunamazsa kullanılır. */
    const FREE_SHIPPING_FALLBACK = 2000;   // TL
    const FLAT_SHIPPING_FALLBACK = 99;     // TL
    const WELCOME_COUPON     = 'MERHABA5';
    const WELCOME_PCT        = 5;
    const WELCOME_MIN        = 750;        // TL
    const SUBSCRIBER_CPT     = 'kinetik_subscriber';

    public static function init() {
        add_action('init', [__CLASS__, 'register_subscriber_cpt']);
        add_shortcode('kinetik_newsletter', [__CLASS__, 'newsletter_shortcode']);

        if (!class_exists('WooCommerce')) return;

        // Ücretsiz kargo ilerleme çubuğu (sepet + ödeme)
        add_action('woocommerce_before_cart', [__CLASS__, 'free_shipping_bar'], 5);
        add_action('woocommerce_checkout_before_customer_details', [__CLASS__, 'free_shipping_bar'], 5);
        add_shortcode('kinetik_free_shipping_bar', function () { ob_start(); self::free_shipping_bar(); return ob_get_clean(); });
        // Eşik aşıldığında ücretli kargo seçeneğini gizle: WooCommerce aksi hâlde ilk sıradaki
        // 99 TL'lik yöntemi varsayılan seçili bırakıyor ve müşteri ücretsiz kargoyu kaçırabiliyor.
        add_filter('woocommerce_package_rates', [__CLASS__, 'hide_paid_when_free'], 100);

        // E-posta aboneliği (AJAX)
        add_action('wp_ajax_kinetik_subscribe', [__CLASS__, 'ajax_subscribe']);
        add_action('wp_ajax_nopriv_kinetik_subscribe', [__CLASS__, 'ajax_subscribe']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);

        add_filter('manage_' . self::SUBSCRIBER_CPT . '_posts_columns', function ($c) { $c['title'] = 'E-posta'; return $c; });
    }

    public static function activate() {
        self::register_subscriber_cpt();
        if (class_exists('WooCommerce')) self::ensure_welcome_coupon();
        flush_rewrite_rules();
    }

    /* ------------------------------------------------- Kargo eşiği (tek kaynak) */

    /**
     * Ücretsiz kargo eşiği: WooCommerce kargo bölgelerindeki etkin "Ücretsiz Kargo"
     * yönteminin min_amount değeri (demo/setup-store.php 2000 yazar). Bulunamazsa sabit.
     */
    public static function free_shipping_threshold() {
        static $cached = null;
        if ($cached === null) {
            $cached = 0.0;
            if (class_exists('WC_Shipping_Zones')) {
                $mins = [];
                foreach (WC_Shipping_Zones::get_zones() as $z) {
                    foreach ($z['shipping_methods'] as $m) {
                        if ($m->id !== 'free_shipping' || $m->enabled !== 'yes') continue;
                        $req = (string) $m->get_option('requires');
                        $min = (float) $m->get_option('min_amount');
                        if ($min > 0 && in_array($req, ['min_amount', 'either', 'both'], true)) $mins[] = $min;
                    }
                }
                if ($mins) $cached = min($mins);
            }
            if ($cached <= 0) $cached = (float) self::FREE_SHIPPING_FALLBACK;
        }
        return (float) apply_filters('kinetik_free_shipping_threshold', $cached);
    }

    /** Sabit kargo ücreti: etkin flat_rate yönteminin cost değeri, yoksa sabit. */
    public static function flat_shipping_cost() {
        static $cached = null;
        if ($cached === null) {
            $cached = 0.0;
            if (class_exists('WC_Shipping_Zones')) {
                foreach (WC_Shipping_Zones::get_zones() as $z) {
                    foreach ($z['shipping_methods'] as $m) {
                        if ($m->id === 'flat_rate' && $m->enabled === 'yes' && is_numeric($m->get_option('cost'))) {
                            $cached = (float) $m->get_option('cost');
                            break 2;
                        }
                    }
                }
            }
            if ($cached <= 0) $cached = (float) self::FLAT_SHIPPING_FALLBACK;
        }
        return (float) apply_filters('kinetik_flat_shipping_cost', $cached);
    }

    public static function hide_paid_when_free($rates) {
        $free = array_filter($rates, function ($r) { return $r->get_method_id() === 'free_shipping'; });
        return $free ?: $rates;
    }

    /* --------------------------------------------- Ücretsiz kargo çubuğu */
    public static function free_shipping_bar() {
        if (!function_exists('WC') || !WC()->cart || WC()->cart->is_empty()) return;
        // Ödeme sayfasında kargo zaten sipariş özetinde yazıyor; yalnız eyleme
        // dönüşebilir hâlde göster (eşiğe yaklaşıldı ya da kazanıldı).
        $odemede   = function_exists('is_checkout') && is_checkout() && !is_cart();
        $subtotal  = (float) WC()->cart->get_displayed_subtotal();
        $threshold = self::free_shipping_threshold();
        $remaining = max(0, $threshold - $subtotal);
        $pct       = $threshold > 0 ? min(100, ($subtotal / $threshold) * 100) : 100;
        // Hedef uzaktayken "şu kadar kaldı" demek motive etmiyor; sade kargo bilgisi göster.
        $show_progress = (float) apply_filters('kinetik_shipbar_min_pct', 40);

        if ($odemede && $remaining > 0 && $pct < $show_progress) return;
        echo '<div class="kinetik-shipbar">';
        if ($remaining <= 0) {
            echo '<p class="kinetik-shipbar__msg is-done">Kargo bizden — siparişin <strong>ücretsiz kargo</strong> ile gelecek.</p>';
            echo '<div class="kinetik-shipbar__track"><span style="width:100%"></span></div>';
        } elseif ($pct >= $show_progress) {
            echo '<p class="kinetik-shipbar__msg">Ücretsiz kargoya yalnızca <strong>' . wp_kses_post(wc_price($remaining)) . '</strong> kaldı.</p>';
            echo '<div class="kinetik-shipbar__track"><span style="width:' . esc_attr(round($pct)) . '%"></span></div>';
        } else {
            echo '<p class="kinetik-shipbar__msg is-flat">Kargo <strong>' . wp_kses_post(wc_price(self::flat_shipping_cost())) . '</strong> · '
               . wp_kses_post(wc_price($threshold)) . ' ve üzeri siparişlerde <strong>ücretsiz</strong>.</p>';
        }
        echo '</div>';
    }

    /* ------------------------------------------------------ Abonelik (AJAX) */
    public static function ajax_subscribe() {
        check_ajax_referer('kinetik_subscribe', 'nonce');
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (!is_email($email)) wp_send_json_error(['message' => 'Geçerli bir e-posta adresi girin.']);

        $existing = get_posts([
            'post_type'   => self::SUBSCRIBER_CPT,
            'title'       => $email,
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
        ]);
        if (!$existing) {
            $id = wp_insert_post([
                'post_type'   => self::SUBSCRIBER_CPT,
                'post_title'  => $email,
                'post_status' => 'publish',
            ]);
            if ($id && !is_wp_error($id)) {
                // Rıza kanıtı (İYS / KVKK): zaman + IP + kaynak sayfa
                update_post_meta($id, '_kinetik_consent_ts', time());
                update_post_meta($id, '_kinetik_consent_ip', self::client_ip());
                $ref = isset($_POST['source']) ? esc_url_raw(wp_unslash($_POST['source'])) : '';
                if ($ref) update_post_meta($id, '_kinetik_consent_source', $ref);
            }
        }
        $coupon = class_exists('WooCommerce') ? self::ensure_welcome_coupon() : '';
        wp_send_json_success([
            'message' => 'Teşekkürler, listedesin! İlk siparişine özel kodun:',
            'coupon'  => $coupon,
            'note'    => sprintf('%%%d indirim · %s ve üzeri siparişlerde geçerli.', self::WELCOME_PCT, html_entity_decode(wp_strip_all_tags(wc_price(self::WELCOME_MIN)), ENT_QUOTES, 'UTF-8')),
        ]);
    }

    private static function client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /* ------------------------------------------------------- Hoş geldin kuponu */
    /** Kupon demo/setup-store.php ile oluşturulur; yoksa aynı kurallarla yaratılır. */
    public static function ensure_welcome_coupon() {
        $code = self::WELCOME_COUPON;
        if (wc_get_coupon_id_by_code($code)) return $code;
        $coupon = new WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type('percent');
        $coupon->set_amount(self::WELCOME_PCT);
        $coupon->set_minimum_amount(self::WELCOME_MIN);
        $coupon->set_usage_limit_per_user(1);
        $coupon->set_description('E-posta kaydına hoş geldin indirimi');
        $coupon->save();
        return $code;
    }

    /* ------------------------------------------------------- Bülten formu */
    public static function newsletter_form($args = []) {
        $a = wp_parse_args($args, [
            'title' => 'Bültene katıl, %5 kazan',
            'text'  => 'Yeni koleksiyonlar, antrenman kombinleri ve kampanyalar ilk sana gelsin. İlk siparişine özel indirim kodu kayıt olur olmaz ekranda.',
            'button'=> 'Katıl',
        ]);
        static $n = 0; $n++;
        $id = 'kinetik-news-' . $n;
        $h  = '<div class="kinetik-newsletter">';
        if ($a['title'] !== '') $h .= '<p class="kinetik-newsletter__title">' . esc_html($a['title']) . '</p>';
        if ($a['text'] !== '')  $h .= '<p class="kinetik-newsletter__text">' . esc_html($a['text']) . '</p>';
        $h .= '<form class="kinetik-newsletter__form" novalidate>'
            . '<label class="screen-reader-text" for="' . esc_attr($id) . '">E-posta adresin</label>'
            . '<input id="' . esc_attr($id) . '" type="email" name="email" placeholder="E-posta adresin" autocomplete="email" required>'
            . '<button type="submit">' . esc_html($a['button']) . '</button>'
            . '</form>'
            . '<p class="kinetik-newsletter__result" role="status" aria-live="polite"></p>'
            . '<p class="kinetik-newsletter__legal">Kayıt olarak kampanya e-postalarını almayı kabul edersin; her e-postadaki bağlantıdan tek tıkla çıkabilirsin. Ayrıntılar: <a href="' . esc_url(home_url('/kvkk-aydinlatma-metni/')) . '">KVKK Aydınlatma Metni</a>.</p>'
            . '</div>';
        return $h;
    }

    public static function newsletter_shortcode($atts) {
        $atts = shortcode_atts(['title' => null, 'text' => null, 'button' => null], $atts, 'kinetik_newsletter');
        return self::newsletter_form(array_filter($atts, function ($v) { return $v !== null; }));
    }

    /* ------------------------------------------------------------- Varlıklar */
    public static function assets() {
        wp_enqueue_script('kinetik-marketing', plugins_url('marketing.js', __FILE__), ['jquery'], (string) filemtime(__DIR__ . '/marketing.js'), true);
        wp_localize_script('kinetik-marketing', 'KinetikMkt', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kinetik_subscribe'),
        ]);
        $css = '.kinetik-shipbar{margin:0 0 16px}.kinetik-shipbar__msg{margin:0 0 6px;font-size:14px}'
             . '.kinetik-shipbar__track{height:6px;background:#E2DFD8;border-radius:999px;overflow:hidden}'
             . '.kinetik-shipbar__track span{display:block;height:100%;background:#0D0D0D;border-radius:999px;transition:width .3s}'
             . '.kinetik-newsletter__code{display:inline-block;margin-left:4px;padding:2px 8px;border:1px dashed currentColor;font-weight:700;letter-spacing:.06em}'
             . '.kinetik-newsletter__note{display:block;margin-top:6px}'
             . '.kinetik-newsletter__result.is-error{color:#B3261E}';
        wp_register_style('kinetik-marketing', false, [], self::VER);
        wp_enqueue_style('kinetik-marketing');
        wp_add_inline_style('kinetik-marketing', $css);
    }

    /* -------------------------------------------------------- Abone CPT */
    public static function register_subscriber_cpt() {
        register_post_type(self::SUBSCRIBER_CPT, [
            'labels' => [
                'name'          => 'Aboneler',
                'singular_name' => 'Abone',
                'menu_name'     => 'E-posta Aboneleri',
            ],
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => true,
            'menu_icon'       => 'dashicons-email',
            'supports'        => ['title'],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ]);
    }
}

/** Tema için yardımcı: hazır bülten formu HTML'i. */
function kinetik_newsletter_form($args = []) {
    return Kinetik_Marketing::newsletter_form($args);
}

add_action('plugins_loaded', ['Kinetik_Marketing', 'init']);
register_activation_hook(__FILE__, ['Kinetik_Marketing', 'activate']);
