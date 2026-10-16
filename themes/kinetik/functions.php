<?php
/**
 * KİNETİK — Türkiye için WooCommerce spor giyim teması. Tema giriş noktası.
 *
 *   inc/helpers.php      logo, ikon, varlık URL'si, satıcı künyesi, menü yardımcıları
 *   inc/woocommerce.php  WooCommerce entegrasyonu (görsel boyutları, kart, filtre, beden butonları, sepet parçaları)
 *   inc/seo.php          meta açıklama, canonical, OG/Twitter, JSON-LD
 *   inc/contact.php      [kinetik_contact_form] iletişim formu (yöneticiye e-posta + kayıt)
 *   inc/size-guide.php   beden tabloları (sayfa + PDP modalı tek kaynak)
 */
if (!defined('ABSPATH')) exit;

define('KINETIK_THEME_DIR', get_template_directory());
define('KINETIK_THEME_URI', get_template_directory_uri());

require_once KINETIK_THEME_DIR . '/inc/helpers.php';
require_once KINETIK_THEME_DIR . '/inc/size-guide.php';
require_once KINETIK_THEME_DIR . '/inc/contact.php';
require_once KINETIK_THEME_DIR . '/inc/seo.php';
if (class_exists('WooCommerce')) {
    require_once KINETIK_THEME_DIR . '/inc/woocommerce.php';
}

/* ---------------------------------------------------------------- Kurulum */
add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('woocommerce', [
        'thumbnail_image_width' => 600,
        'single_image_width'    => 1000,
    ]);
    add_theme_support('wc-product-gallery-lightbox');
    register_nav_menus([
        'primary'      => 'Ana Menü',
        'footer_help'  => 'Alt Menü — Yardım',
        'footer_legal' => 'Alt Menü — Kurumsal',
    ]);
    add_image_size('kinetik-editorial', 1200, 1500, true);
});

/* ---------------------------------------------------------------- Varlıklar */
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('kinetik-fonts', 'https://fonts.googleapis.com/css2?family=Barlow+Condensed:ital,wght@0,600;0,700;0,800;1,600;1,700;1,800;1,900&family=Inter:wght@400;500;600;700&display=swap', [], null);

    $css = ['base', 'layout', 'cards'];
    if (is_front_page()) $css[] = 'home';
    if (function_exists('is_woocommerce') && (is_shop() || is_product_taxonomy() || is_search())) $css[] = 'shop';
    if (function_exists('is_product') && is_product()) $css[] = 'product';
    if (function_exists('is_cart') && (is_cart() || is_checkout() || is_account_page())) $css[] = 'checkout';
    if (is_page() || is_404() || is_search() || is_singular('post') || is_home()) $css[] = 'pages';

    $deps = ['kinetik-fonts'];
    foreach (array_unique($css) as $name) {
        $rel = "assets/css/{$name}.css";
        wp_enqueue_style("kinetik-{$name}", KINETIK_THEME_URI . '/' . $rel, $deps, kinetik_ver($rel));
        $deps = ["kinetik-{$name}"];
    }

    wp_enqueue_script('kinetik-main', KINETIK_THEME_URI . '/assets/js/main.js', [], kinetik_ver('assets/js/main.js'), ['in_footer' => true, 'strategy' => 'defer']);
    wp_localize_script('kinetik-main', 'KINETIK', [
        'ajax'    => admin_url('admin-ajax.php'),
        'cartUrl' => function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/sepet/'),
    ]);
}, 20);

/* Blok kütüphanesi CSS'i yalnızca ihtiyaç olan sayfalarda (sepet/ödeme blokları ihtimaline karşı) */
add_action('wp_enqueue_scripts', function () {
    if (function_exists('is_cart') && (is_cart() || is_checkout() || is_account_page())) return;
    foreach (['wp-block-library', 'wp-block-library-theme', 'global-styles', 'classic-theme-styles'] as $h) wp_dequeue_style($h);
}, 100);

add_action('init', function () {
    remove_action('wp_head', 'wp_site_icon', 99);
    remove_action('wp_head', 'wp_generator');
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
});

/* Font sunucularına preconnect + favicon seti + anasayfada hero preload */
add_action('wp_head', function () {
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    echo '<meta name="theme-color" content="#0D0D0D">' . "\n";
    // Favicon seti temadan (PNG/ICO/SVG). WP site simgesi ayarlı kalır (yönetim paneli için) ama onun
    // etiketleri basılmaz: alt boyutları WebP'ye dönüştürülüyor, iOS apple-touch-icon için WebP'yi okumaz.
    echo '<link rel="icon" href="' . esc_url(kinetik_asset('assets/logo/favicon.ico')) . '" sizes="any">' . "\n";
    echo '<link rel="icon" type="image/svg+xml" href="' . esc_url(kinetik_asset('assets/logo/favicon.svg')) . '">' . "\n";
    echo '<link rel="apple-touch-icon" href="' . esc_url(kinetik_asset('assets/logo/apple-touch-icon.png')) . '">' . "\n";
    if (is_front_page()) {
        echo '<link rel="preload" as="image" type="image/webp" fetchpriority="high" media="(min-width: 768px)" href="' . esc_url(kinetik_asset('assets/img/hero.webp')) . '">' . "\n";
        echo '<link rel="preload" as="image" type="image/webp" fetchpriority="high" media="(max-width: 767px)" href="' . esc_url(kinetik_asset('assets/img/hero-mobile.webp')) . '">' . "\n";
    }
}, 1);

/* Gövde sınıfı: koyu zeminli sayfalar */
add_filter('body_class', function ($classes) {
    if (is_front_page()) $classes[] = 'is-dark-top';
    return $classes;
});
