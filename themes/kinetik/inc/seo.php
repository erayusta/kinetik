<?php
/**
 * SEO temeli: başlık, meta açıklama, canonical, robots (filtre/sıralama varyantları),
 * Open Graph / Twitter, JSON-LD (Organization + WebSite anasayfada).
 * Ürün JSON-LD'sini WooCommerce kendisi basar (WC_Structured_Data); burada marka/satıcı eklenir.
 * Breadcrumb JSON-LD'si de woocommerce_breadcrumb() çağrıldığında WooCommerce'ten gelir.
 */
if (!defined('ABSPATH')) exit;

add_filter('document_title_separator', fn() => '|');

add_filter('document_title_parts', function ($parts) {
    if (is_front_page()) {
        $parts['title'] = kinetik_brand();
        $parts['tagline'] = 'Spor giyim: tişört, eşofman, hoodie, şort ve tayt';
    } elseif (function_exists('is_product_category') && is_product_category()) {
        $parts['title'] = single_term_title('', false) . ' modelleri';
    } elseif (function_exists('is_shop') && is_shop() && !is_search()) {
        $parts['title'] = 'Tüm ürünler';
    }
    return $parts;
});

function kinetik_seo_trim($text, $len = 158) {
    $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes((string) $text))));
    if (mb_strlen($text) <= $len) return $text;
    $text = mb_substr($text, 0, $len);
    $cut = mb_strrpos($text, ' ');
    return ($cut ? mb_substr($text, 0, $cut) : $text) . '…';
}

function kinetik_meta_description() {
    $free = kinetik_tl(kinetik_free_shipping_threshold());
    if (is_front_page()) {
        return kinetik_brand() . ": tişört, eşofman, sweatshirt, hoodie, şort ve taytta sade, iyi oturan spor giyim. {$free} üzeri ücretsiz kargo, en geç " . kinetik_dispatch_days() . " iş gününde kargoda, " . kinetik_return_days() . " gün iade.";
    }
    if (function_exists('is_product') && is_product()) {
        $p = wc_get_product(get_queried_object_id());
        $d = $p ? ($p->get_short_description() ?: $p->get_description()) : '';
        return kinetik_seo_trim($d ?: get_the_title() . ' — ' . kinetik_brand() . '.');
    }
    if (function_exists('is_product_category') && is_product_category()) {
        $t = get_queried_object();
        return kinetik_seo_trim(($t->description ?: $t->name . ' modelleri') . " {$free} üzeri ücretsiz kargo.");
    }
    if (function_exists('is_shop') && is_shop()) {
        return kinetik_brand() . " tüm ürünler: tişört, eşofman, sweatshirt & hoodie, şort & tayt ve aksesuar. {$free} üzeri ücretsiz kargo.";
    }
    if (is_singular()) {
        $post = get_queried_object();
        if (is_page('iletisim')) return kinetik_brand() . ' iletişim bilgileri: ' . kinetik_seller('unvan') . ', ' . kinetik_seller('adres') . ', ' . kinetik_seller('telefon') . '.';
        if (is_page('beden-rehberi')) return kinetik_brand() . ' beden rehberi: üst giyim, alt giyim ve kadın tayt için genel vücut ölçüsü tabloları (cm) ve doğru ölçü alma ipuçları.';
        return kinetik_seo_trim($post->post_excerpt ?: $post->post_content ?: get_bloginfo('description'));
    }
    return kinetik_seo_trim(get_bloginfo('description'));
}

function kinetik_canonical_url() {
    if (is_singular()) return ''; // tekil sayfalarda canonical'ı WordPress basar (rel_canonical)
    if (function_exists('is_shop') && is_shop()) {
        $paged = max(1, (int) get_query_var('paged'));
        $base = wc_get_page_permalink('shop');
        return $paged > 1 ? trailingslashit($base) . 'page/' . $paged . '/' : $base;
    }
    if (is_tax() || is_category() || is_tag()) {
        $link = get_term_link(get_queried_object());
        if (is_wp_error($link)) return '';
        $paged = max(1, (int) get_query_var('paged'));
        return $paged > 1 ? trailingslashit($link) . 'page/' . $paged . '/' : $link;
    }
    return '';
}

/** Filtrelenmiş/sıralanmış arşivler ve arama: indekslenmesin, bağlantılar izlensin. */
add_filter('wp_robots', function ($robots) {
    $variant = false;
    foreach (array_keys($_GET) as $k) {
        if (in_array($k, ['orderby', 'min_price', 'max_price'], true) || strpos($k, 'filter_') === 0 || strpos($k, 'query_type_') === 0) $variant = true;
    }
    if ($variant || is_search() || (function_exists('is_cart') && (is_cart() || is_checkout() || is_account_page()))) {
        $robots['noindex'] = true;
        $robots['follow'] = true;
        unset($robots['max-image-preview']);
    }
    return $robots;
});

function kinetik_og_image() {
    if (function_exists('is_product') && is_product()) {
        $img = get_the_post_thumbnail_url(get_queried_object_id(), 'large');
        if ($img) return $img;
    }
    if (function_exists('is_product_category') && is_product_category()) {
        $thumb = get_term_meta(get_queried_object_id(), 'thumbnail_id', true);
        if ($thumb && ($img = wp_get_attachment_image_url($thumb, 'large'))) return $img;
    }
    return kinetik_asset('assets/logo/og-logo.png');
}

add_action('wp_head', function () {
    if (is_404()) return;
    $desc  = kinetik_meta_description();
    $title = wp_get_document_title();
    $canon = kinetik_canonical_url();
    $url   = is_singular() ? get_permalink() : ($canon ?: home_url(add_query_arg(null, null)));
    $type  = (function_exists('is_product') && is_product()) ? 'product' : 'website';

    echo "\n<!-- KİNETİK SEO -->\n";
    if ($desc) echo '<meta name="description" content="' . esc_attr($desc) . '">' . "\n";
    if ($canon) echo '<link rel="canonical" href="' . esc_url($canon) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr(kinetik_brand()) . '">' . "\n";
    echo '<meta property="og:locale" content="tr_TR">' . "\n";
    echo '<meta property="og:type" content="' . esc_attr($type) . '">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
    if ($desc) echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
    echo '<meta property="og:image" content="' . esc_url(kinetik_og_image()) . '">' . "\n";
    if ($type === 'product' && ($p = wc_get_product(get_queried_object_id()))) {
        echo '<meta property="product:price:amount" content="' . esc_attr(wc_format_decimal($p->get_price(), 2)) . '">' . "\n";
        echo '<meta property="product:price:currency" content="' . esc_attr(get_woocommerce_currency()) . '">' . "\n";
        echo '<meta property="product:availability" content="' . ($p->is_in_stock() ? 'in stock' : 'out of stock') . '">' . "\n";
    }
    echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
}, 5);

/* JSON-LD: anasayfada kurum + site. Ürün/breadcrumb WooCommerce'ten gelir. */
add_action('wp_head', function () {
    if (!is_front_page()) return;
    $s = kinetik_seller();
    $graph = [
        [
            '@type'       => ['Organization', 'OnlineStore'],
            '@id'         => home_url('/#organization'),
            'name'        => kinetik_brand(),
            'legalName'   => $s['unvan'] ?? '',
            'url'         => home_url('/'),
            'logo'        => KINETIK_THEME_URI . '/assets/logo/icon-512.png',
            'telephone'   => !empty($s['telefon']) ? '+90' . ltrim(preg_replace('/\D+/', '', $s['telefon']), '0') : null,
            'email'       => !empty($s['eposta']) ? $s['eposta'] : null,
            'address'     => [
                '@type'           => 'PostalAddress',
                'streetAddress'   => get_option('woocommerce_store_address'),
                'addressLocality' => get_option('woocommerce_store_city'),
                'addressRegion'   => function_exists('WC') && WC()->countries ? (WC()->countries->get_states('TR')[explode(':', (string) get_option('woocommerce_default_country') . ':')[1]] ?? '') : '',
                'postalCode'      => get_option('woocommerce_store_postcode'),
                'addressCountry'  => 'TR',
            ],
            'areaServed'  => 'TR',
            'hasMerchantReturnPolicy' => [
                '@type' => 'MerchantReturnPolicy',
                'applicableCountry' => 'TR',
                'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                'merchantReturnDays' => kinetik_return_days(),
                'returnMethod' => 'https://schema.org/ReturnByMail',
                'url' => kinetik_page_url('kargo-ve-iade'),
            ],
        ],
        [
            '@type'     => 'WebSite',
            '@id'       => home_url('/#website'),
            'url'       => home_url('/'),
            'name'      => kinetik_brand(),
            'inLanguage'=> 'tr-TR',
            'publisher' => ['@id' => home_url('/#organization')],
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => home_url('/?s={search_term_string}&post_type=product')],
                'query-input' => 'required name=search_term_string',
            ],
        ],
    ];
    $graph[0] = array_filter($graph[0], fn($v) => $v !== null && $v !== '');
    echo '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
}, 6);

/* Ürün JSON-LD'sine marka ekle */
add_filter('woocommerce_structured_data_product', function ($markup) {
    $markup['brand'] = ['@type' => 'Brand', 'name' => kinetik_brand()];
    return $markup;
});
