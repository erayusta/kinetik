<?php
/** Tema yardımcıları — logo, ikonlar, varlıklar, satıcı künyesi, menüler. */
if (!defined('ABSPATH')) exit;

/** Dosya değiştikçe değişen sürüm (tarayıcı önbelleği eski CSS/görseli tutmasın). */
function kinetik_ver($rel) {
    $path = KINETIK_THEME_DIR . '/' . ltrim($rel, '/');
    return file_exists($path) ? (string) filemtime($path) : null;
}

function kinetik_asset($rel) {
    $ver = kinetik_ver($rel);
    $url = KINETIK_THEME_URI . '/' . ltrim($rel, '/');
    return $ver ? add_query_arg('v', $ver, $url) : $url;
}

/**
 * Satır içi logo (font kontürlerinden yollar; tools/make-logo.py üretir).
 * Harfler .logo-letters, volt detay .logo-accent — renkler CSS'ten (currentColor + --logo-accent).
 * $type: 'wordmark' | 'mark'
 */
function kinetik_logo($type = 'wordmark') {
    static $cache = [];
    if (!isset($cache[$type])) {
        $file = KINETIK_THEME_DIR . '/assets/logo/' . ($type === 'mark' ? 'kinetik-mark-inline.svg' : 'kinetik-inline.svg');
        $svg = file_exists($file) ? (string) file_get_contents($file) : '<span>' . esc_html(kinetik_brand()) . '</span>';
        $svg = preg_replace('#<title>.*?</title>#s', '', $svg); // dekoratif: erişilebilir ad bağlantının aria-label'ında
        $cache[$type] = str_replace('<svg ', '<svg class="logo logo--' . esc_attr($type) . '" aria-hidden="true" focusable="false" ', $svg);
    }
    return $cache[$type];
}

/** Basit çizgi ikon seti (24×24, stroke = currentColor). */
function kinetik_icon($name, $size = 22) {
    $paths = [
        'search'  => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'user'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
        'bag'     => '<path d="M5 8h14l-1 13H6L5 8Z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
        'menu'    => '<path d="M3 7h18M3 12h18M3 17h12"/>',
        'close'   => '<path d="M6 6l12 12M18 6 6 18"/>',
        'truck'   => '<path d="M2 6h11v10H2zM13 10h4l3 3v3h-7"/><circle cx="6" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'return'  => '<path d="M4 9h11a5 5 0 0 1 0 10H8"/><path d="m8 5-4 4 4 4"/>',
        'shield'  => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6l-8-3Z"/><path d="m9 12 2 2 4-4"/>',
        'bolt'    => '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/>',
        'filter'  => '<path d="M4 6h16M7 12h10M10 18h4"/>',
        'arrow'   => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'ruler'   => '<path d="M3 17 17 3l4 4L7 21l-4-4Z"/><path d="m7 13 2 2M10 10l2 2M13 7l2 2"/>',
        'check'   => '<path d="m5 12 5 5 9-10"/>',
        'phone'   => '<path d="M5 3h4l2 5-3 2a12 12 0 0 0 6 6l2-3 5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 3 5a2 2 0 0 1 2-2"/>',
        'pin'     => '<path d="M12 21s7-6 7-12a7 7 0 0 0-14 0c0 6 7 12 7 12Z"/><circle cx="12" cy="9" r="2.5"/>',
        'mail'    => '<rect x="3" y="5" width="18" height="14" rx="1"/><path d="m3 7 9 6 9-6"/>',
        'bank'    => '<path d="M3 10h18L12 4 3 10ZM5 10v8M9 10v8M15 10v8M19 10v8M3 20h18"/>',
        'minus'   => '<path d="M5 12h14"/>',
        'plus'    => '<path d="M5 12h14M12 5v14"/>',
    ];
    if (!isset($paths[$name])) return '';
    return '<svg class="icon icon--' . esc_attr($name) . '" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
}

/** Mağaza adı — Ayarlar → Genel → Site başlığı. */
function kinetik_brand() {
    return wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
}

/** Satıcı künyesi — tek kaynak `kinetik_seller_info` (WooCommerce → Sözleşmeler ekranı; demo/setup-store.php). */
function kinetik_seller($key = null) {
    $info = get_option('kinetik_seller_info', []);
    $info = is_array($info) ? $info : [];
    return $key === null ? $info : trim((string) ($info[$key] ?? ''));
}

/** Boş künye alanı: gizlenmez, açıkça "eklenecek" olarak işaretlenir. */
function kinetik_seller_field($key, $label) {
    $v = kinetik_seller($key);
    return $v !== '' ? esc_html($v) : '<span class="pending-field">[' . esc_html($label) . ' eklenecek]</span>';
}

function kinetik_tel_href($phone) {
    $d = preg_replace('/\D+/', '', (string) $phone);
    if (strpos($d, '0') === 0) $d = '90' . substr($d, 1);
    return 'tel:+' . $d;
}

/** Ücretsiz kargo eşiği — WooCommerce ayarındaki free_shipping min_amount'tan okunur. */
function kinetik_free_shipping_threshold() {
    static $min = null;
    if ($min !== null) return $min;
    $min = 2000.0;
    if (class_exists('WC_Shipping_Zones')) {
        foreach (WC_Shipping_Zones::get_zones() as $zone) {
            foreach ($zone['shipping_methods'] as $m) {
                if ($m->id === 'free_shipping' && $m->is_enabled() && in_array($m->requires, ['min_amount', 'either', 'both'], true) && (float) $m->min_amount > 0) {
                    return $min = (float) $m->min_amount;
                }
            }
        }
    }
    return $min;
}

/** Sabit kargo ücreti — WooCommerce'teki etkin flat_rate yönteminin ücreti (yoksa 0). */
function kinetik_flat_shipping_cost() {
    static $cost = null;
    if ($cost !== null) return $cost;
    $cost = 0.0;
    if (class_exists('WC_Shipping_Zones')) {
        foreach (WC_Shipping_Zones::get_zones() as $zone) {
            foreach ($zone['shipping_methods'] as $m) {
                if ($m->id === 'flat_rate' && $m->is_enabled() && is_numeric($m->get_option('cost'))) {
                    return $cost = (float) $m->get_option('cost');
                }
            }
        }
    }
    return $cost;
}

/** Kargoya teslim süresi (iş günü) ve cayma/iade süresi (gün). Filtrelerle değiştirilebilir. */
function kinetik_dispatch_days() {
    return max(1, (int) apply_filters('kinetik_dispatch_days', 3));
}
function kinetik_return_days() {
    return max(14, (int) apply_filters('kinetik_return_days', 14)); // yasal alt sınır 14 gün
}

/**
 * Hoş geldin teklifi — kinetik-marketing'in kuponu WooCommerce'te varsa [code, pct, min], yoksa null.
 * Metinler kuponun gerçek değerlerinden üretilir.
 */
function kinetik_welcome_offer() {
    static $offer = false;
    if ($offer !== false) return $offer;
    $offer = null;
    if (!function_exists('wc_get_coupon_id_by_code')) return $offer;
    $code = class_exists('Kinetik_Marketing') ? Kinetik_Marketing::WELCOME_COUPON : '';
    $code = (string) apply_filters('kinetik_welcome_coupon', $code);
    if ($code === '' || !($id = wc_get_coupon_id_by_code($code))) return $offer;
    $c = new WC_Coupon($id);
    if ($c->get_discount_type() !== 'percent') return $offer;
    return $offer = ['code' => strtoupper($c->get_code()), 'pct' => (float) $c->get_amount(), 'min' => (float) $c->get_minimum_amount()];
}

/**
 * Alt bilgi geliştirici kredisi. Kaldırmak için: add_filter('kinetik_footer_credit', '__return_empty_string');
 */
function kinetik_footer_credit() {
    $html = 'Tasarım &amp; geliştirme: <a href="https://erusoft.com" target="_blank" rel="noopener">Erusoft</a>';
    return (string) apply_filters('kinetik_footer_credit', $html);
}

function kinetik_tl($amount) {
    return number_format((float) $amount, 0, ',', '.') . ' TL';
}

function kinetik_cart_count() {
    return (function_exists('WC') && WC()->cart) ? (int) WC()->cart->get_cart_contents_count() : 0;
}

/** Menü konumu; boşsa hiçbir şey basmaz (sahte bağlantı üretmez). */
function kinetik_menu($location, $class = 'menu') {
    if (!has_nav_menu($location)) return;
    wp_nav_menu([
        'theme_location' => $location,
        'container'      => false,
        'depth'          => 1,
        'fallback_cb'    => false,
        'items_wrap'     => '<ul class="' . esc_attr($class) . '">%3$s</ul>',
    ]);
}

/** Bir sayfanın bağlantısı (slug ile); yoksa ana sayfa. */
function kinetik_page_url($slug) {
    $p = get_page_by_path($slug);
    return $p ? get_permalink($p) : home_url('/' . $slug . '/');
}
