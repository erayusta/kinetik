<?php
/**
 * WooCommerce entegrasyonu.
 *
 * Yapı: woocommerce.php mağaza/kategori arşivini ve tekil ürünü sarar;
 * sepet/ödeme/hesabım shortcode sayfalarıdır ve page.php ile render edilir.
 * Override'lar woocommerce/ altında. Eklentilerin (kinetik-*) standart kancaları korunur:
 * woocommerce_single_product_summary, woocommerce_checkout_* , woocommerce_before_cart,
 * woocommerce_review_order_before_submit, woocommerce_loop_add_to_cart_link, woocommerce_get_stock_html.
 */
if (!defined('ABSPATH')) exit;

/* ------------------------------------------------------------ Görsel boyutları
 * Giyim için 4:5 portre kırpım. Ürün görselleri değişince:
 *   wp media regenerate --yes
 */
add_filter('woocommerce_get_image_size_thumbnail', fn() => ['width' => 600, 'height' => 750, 'crop' => 1]);
add_filter('woocommerce_get_image_size_single', fn() => ['width' => 1000, 'height' => 1250, 'crop' => 1]);
add_filter('woocommerce_get_image_size_gallery_thumbnail', fn() => ['width' => 160, 'height' => 200, 'crop' => 1]);

/* Alt boyutlar WebP (orijinal JPEG/PNG kalır). */
add_filter('image_editor_output_format', function ($formats) {
    $formats['image/jpeg'] = 'image/webp';
    $formats['image/png']  = 'image/webp';
    return $formats;
});

/* ------------------------------------------------------------ Sarmalayıcılar */
remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);
remove_action('woocommerce_before_main_content', 'woocommerce_breadcrumb', 20);
remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);
remove_action('woocommerce_after_shop_loop', 'woocommerce_pagination', 10);

add_filter('woocommerce_breadcrumb_defaults', fn($d) => array_merge($d, [
    'delimiter'   => '<span class="crumbs__sep" aria-hidden="true">/</span>',
    'wrap_before' => '<nav class="crumbs" aria-label="Sayfa yolu">',
    'wrap_after'  => '</nav>',
    'home'        => 'Ana sayfa',
]));

add_filter('loop_shop_columns', fn() => 3);
add_filter('loop_shop_per_page', fn() => 12);

/* Sıralama adları — mağazanın diliyle */
add_filter('woocommerce_catalog_orderby', fn() => [
    'menu_order' => 'Önerilen',
    'date'       => 'En yeniler',
    'popularity' => 'En çok satanlar',
    'price'      => 'Fiyat: artan',
    'price-desc' => 'Fiyat: azalan',
]);
add_filter('woocommerce_default_catalog_orderby', fn() => 'menu_order');

/* WooCommerce'in varsayılan ön yüz CSS'i yüklenmez; tüm stiller assets/css/ altında. */
add_filter('woocommerce_enqueue_styles', '__return_empty_array');

/* Arşivde sonuç sayısı ve sıralama araç çubuğunda (woocommerce.php) — kancadaki kopyalar kaldırılır. */
remove_action('woocommerce_before_shop_loop', 'woocommerce_result_count', 20);
remove_action('woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30);

/**
 * Listelemede stoktaki ürünler önce (seçilen sıralama ikinci kriter olarak korunur).
 * Stokta olmayanlar gizlenmez: kinetik-growth "Haber ver" ile talep toplar.
 */
add_filter('posts_clauses', function ($clauses, $query) {
    if (is_admin() || !$query->is_main_query()) return $clauses;
    if (!(is_shop() || is_product_taxonomy() || ($query->is_search() && $query->get('post_type') === 'product'))) return $clauses;
    global $wpdb;
    $lookup = $wpdb->prefix . 'wc_product_meta_lookup';
    if (strpos($clauses['join'], $lookup) !== false) return $clauses;
    $clauses['join'] .= " LEFT JOIN {$lookup} kinetik_stock ON {$wpdb->posts}.ID = kinetik_stock.product_id ";
    $first = "(kinetik_stock.stock_status = 'instock') DESC";
    // "Önerilen" (varsayılan) sıralamada tüm mağaza sayfası: menu_order eşitse günlük sabit tohumlu karışık
    // sıra — aynı gün eklenen tek bir kategori ilk sayfayı doldurmasın; sayfalama tutarlı kalır.
    $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'menu_order';
    if ($orderby === 'menu_order' && is_shop() && !$query->is_search()) {
        $first .= ", {$wpdb->posts}.menu_order ASC, RAND(" . (int) wp_date('Ymd') . ")";
        $clauses['orderby'] = $first;
        return $clauses;
    }
    $clauses['orderby'] = $clauses['orderby'] ? $first . ', ' . $clauses['orderby'] : $first;
    return $clauses;
}, 20, 2);

/* Ücretsiz kargo hak edildiyse ücretli yöntemi gösterme (ücretli yöntem yanlışlıkla seçili kalmasın). */
add_filter('woocommerce_package_rates', function ($rates) {
    $free = array_filter($rates, fn($r) => $r->get_method_id() === 'free_shipping');
    return $free ?: $rates;
}, 20);

/* SKU vitrinde gösterilmez */
add_filter('wc_product_sku_enabled', fn($on) => is_admin() ? $on : false);

/* ------------------------------------------------------------ Sepet sayacı (AJAX fragment) */
add_filter('woocommerce_add_to_cart_fragments', function ($fragments) {
    $n = kinetik_cart_count();
    $fragments['span.cart-count'] = '<span class="cart-count" data-count="' . $n . '">' . $n . '</span>';
    return $fragments;
});

/* ------------------------------------------------------------ Ürün kartı yardımcıları */
function kinetik_product_badge($product) {
    if (!$product instanceof WC_Product) return '';
    if (!$product->is_in_stock()) return '<span class="tag tag--muted">Tükendi</span>';
    if ($product->is_on_sale()) {
        $reg = (float) ($product->is_type('variable') ? $product->get_variation_regular_price('max') : $product->get_regular_price());
        $now = (float) ($product->is_type('variable') ? $product->get_variation_sale_price('min') : $product->get_price());
        $pct = $reg > 0 ? (int) round(($reg - $now) / $reg * 100) : 0;
        return '<span class="tag tag--volt">' . ($pct > 0 ? '−%' . $pct : 'İndirim') . '</span>';
    }
    // "Yeni" rozeti bilinçli olarak yok: tüm katalog aynı gün eklendi, her kartta "Yeni" anlamsız olurdu.
    return '';
}

/** Kartta gösterilecek beden aralığı (stoktakiler): "S – XL" */
function kinetik_product_sizes($product) {
    if (!$product instanceof WC_Product_Variable) return [];
    $order = ['xs', 's', 'm', 'l', 'xl', 'xxl', 'standart'];
    $sizes = [];
    foreach ($product->get_children() as $vid) {
        $v = wc_get_product($vid);
        if (!$v || !$v->is_in_stock() || $v->get_status() !== 'publish') continue;
        $slug = $v->get_attribute('pa_beden');
        if ($slug !== '') $sizes[sanitize_title($slug)] = $slug;
    }
    $pos = function ($k) use ($order) { $i = array_search($k, $order, true); return $i === false ? 99 : $i; };
    uksort($sizes, fn($a, $b) => $pos($a) <=> $pos($b));
    return array_values($sizes);
}

/* Varyasyonlu ürünlerde liste butonu "Beden seç" */
add_filter('woocommerce_product_add_to_cart_text', function ($text, $product) {
    if ($product->is_type('variable') && $product->is_in_stock()) return 'Beden seç';
    if ($product->is_type('simple') && $product->is_in_stock()) return 'Sepete ekle';
    return $text;
}, 10, 2);

/* Anasayfa / ilgili ürünler: yalnızca stoktakiler */
function kinetik_in_stock_products($args = []) {
    return wc_get_products(array_merge([
        'status'       => 'publish',
        'stock_status' => 'instock',
        'visibility'   => 'catalog',
        'limit'        => 8,
        'orderby'      => 'date',
        'order'        => 'DESC',
    ], $args));
}

/**
 * Anasayfa "Yeni gelenler": stoktaki en yeni ürünler, kategoriler arasında sırayla dağıtılır
 * (aynı gün eklenen bir kategori ızgarayı tek başına doldurmasın).
 */
function kinetik_new_arrivals($limit = 8) {
    $pool = kinetik_in_stock_products(['limit' => 48]);
    $buckets = [];
    foreach ($pool as $p) {
        $cats = $p->get_category_ids();
        $buckets[$cats ? (int) $cats[0] : 0][] = $p;
    }
    $out = [];
    while (count($out) < $limit && $buckets) {
        foreach ($buckets as $k => &$list) {
            if (!$list) { unset($buckets[$k]); continue; }
            $out[] = array_shift($list);
            if (count($out) >= $limit) break;
        }
        unset($list);
    }
    return $out;
}

/** Kategori için görsel: terim görseli → kategorideki ilk stoktaki ürün → null */
function kinetik_category_image_id($term) {
    $tid = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
    if ($tid) return $tid;
    $p = kinetik_in_stock_products(['limit' => 1, 'category' => [$term->slug], 'orderby' => 'date']);
    return $p ? (int) $p[0]->get_image_id() : 0;
}

/* ------------------------------------------------------------ Arşiv filtreleri
 * WooCommerce ana sorguda filter_{özellik} ve min_price/max_price parametrelerini kendisi uygular
 * (WC_Query); burada yalnızca form ve fiyat sınırları üretilir.
 */
function kinetik_archive_base_url() {
    if (is_product_taxonomy()) {
        $link = get_term_link(get_queried_object());
        if (!is_wp_error($link)) return $link;
    }
    return wc_get_page_permalink('shop');
}

function kinetik_price_bounds() {
    global $wpdb;
    $row = $wpdb->get_row("SELECT MIN(min_price) mn, MAX(max_price) mx FROM {$wpdb->prefix}wc_product_meta_lookup l INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id WHERE p.post_type = 'product' AND p.post_status = 'publish'");
    return [floor((float) ($row->mn ?? 0) / 50) * 50, ceil((float) ($row->mx ?? 0) / 50) * 50];
}

function kinetik_chosen_filter($attr) {
    $raw = isset($_GET['filter_' . $attr]) ? sanitize_text_field(wp_unslash($_GET['filter_' . $attr])) : '';
    return array_filter(array_map('sanitize_title', explode(',', $raw)));
}

function kinetik_active_filter_count() {
    $n = count(kinetik_chosen_filter('beden')) + count(kinetik_chosen_filter('cinsiyet'));
    if (!empty($_GET['min_price']) || !empty($_GET['max_price'])) $n++;
    return $n;
}

/* ------------------------------------------------------------ PDP: beden butonları
 * Varyasyon <select>'i DOM'da kalır (WooCommerce varyasyon JS'i onu okur); yanına butonlar basılır,
 * main.js tıklamayı select'e aktarır ve WooCommerce'in "update_variation_values" olayında
 * uygun olmayan seçenekleri pasifler. Stokta olmayan beden çizili görünür; seçilebilir kalır ki
 * kinetik-growth'un "Haber ver" formu o beden için açılabilsin (sepete ekle WooCommerce tarafından kilitlenir).
 */
add_filter('woocommerce_dropdown_variation_attribute_options_html', function ($html, $args) {
    $product   = $args['product'] ?? null;
    $attribute = $args['attribute'] ?? '';
    $options   = $args['options'] ?? [];
    if (!$product instanceof WC_Product_Variable || !$options) return $html;

    $name = 'attribute_' . sanitize_title($attribute);
    $single_attr = count($product->get_variation_attributes()) === 1;

    // Tek özellikli ürünlerde bedenin stok durumu sunucuda bilinir
    $stock = [];
    if ($single_attr) {
        foreach ($product->get_children() as $vid) {
            $v = wc_get_product($vid);
            if (!$v) continue;
            $val = $v->get_attributes()[sanitize_title($attribute)] ?? '';
            if ($val === '') continue;
            $stock[$val] = ($stock[$val] ?? false) || $v->is_in_stock();
        }
    }

    $items = [];
    if (taxonomy_exists($attribute)) {
        foreach (wc_get_product_terms($product->get_id(), $attribute, ['fields' => 'all']) as $term) {
            if (in_array($term->slug, $options, true)) $items[$term->slug] = $term->name;
        }
    } else {
        foreach ($options as $o) $items[$o] = $o;
    }

    $label = wc_attribute_label($attribute, $product);
    $out = '<div class="size-buttons" data-attribute_name="' . esc_attr($name) . '" role="group" aria-label="' . esc_attr($label) . ' seç">';
    foreach ($items as $value => $text) {
        $oos = $single_attr && isset($stock[$value]) && !$stock[$value];
        $out .= sprintf(
            '<button type="button" class="size-btn%s" data-value="%s" aria-pressed="false"%s>%s</button>',
            $oos ? ' is-oos' : '',
            esc_attr($value),
            $oos ? ' title="Bu beden tükendi — seçersen stoğa girince haber verebiliriz" aria-label="' . esc_attr($text) . ' (tükendi)"' : '',
            esc_html($text)
        );
    }
    $out .= '</div>';
    $html = str_replace('<select ', '<select data-kinetik-native="1" ', $html);
    return '<div class="size-picker">' . $html . $out . '</div>';
}, 10, 2);

/* Varyasyon tablosunun "Temizle" bağlantısı sade metin */
add_filter('woocommerce_reset_variations_link', fn() => '<button type="button" class="reset_variations" aria-label="Seçimi temizle">Seçimi temizle</button>');

/* ------------------------------------------------------------ PDP: kancalar
 * content-single-product.php başlık, fiyat, kısa açıklama ve sepete ekle bölümünü kendisi basar;
 * woocommerce_single_product_summary yine tetiklenir (kinetik-growth @31 vb. eklenti çıktıları için),
 * yalnızca çekirdek kopyalar kaldırılır.
 */
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_title', 5);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 50);
remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10);
remove_action('woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15);
remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20);
remove_action('woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10);
remove_action('woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20);

/** İlgili ürünler — yalnızca stoktakiler; aynı kategoriden yetmezse yeni gelenlerle tamamlanır. */
function kinetik_related_products($product, $limit = 4) {
    $ids = wc_get_related_products($product->get_id(), 12);
    $out = [];
    foreach ($ids as $id) {
        $p = wc_get_product($id);
        if ($p && $p->is_in_stock() && $p->is_visible()) $out[$id] = $p;
        if (count($out) >= $limit) break;
    }
    if (count($out) < $limit) {
        foreach (kinetik_in_stock_products(['limit' => $limit + 2, 'exclude' => array_merge([$product->get_id()], array_keys($out))]) as $p) {
            $out[$p->get_id()] = $p;
            if (count($out) >= $limit) break;
        }
    }
    return array_values($out);
}

/** Bakım talimatı: ürün meta'sı `_kinetik_care` varsa o, yoksa kategoriye göre genel öneri. */
function kinetik_care_text($product) {
    $own = trim((string) $product->get_meta('_kinetik_care'));
    if ($own !== '') return wpautop(esc_html($own));
    $cats = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'slugs']);
    if (!is_wp_error($cats) && in_array('aksesuar', $cats, true)) {
        return '<p>Ürün etiketindeki bakım talimatına uy. Elde, soğuk suda yıkamak çoğu aksesuarın formunu en uzun süre korur.</p>';
    }
    return '<ul><li>Ters çevirerek, benzer renklerle 30°C\'de yıka.</li><li>Ağartıcı kullanma; yumuşatıcıyı az kullan.</li><li>Kurutma makinesi yerine askıda kurut.</li><li>Baskılı bölgeyi tersinden, düşük ısıda ütüle.</li></ul><p class="muted">Her ürünün etiketindeki talimat önceliklidir.</p>';
}

/* ------------------------------------------------------------ Sepet / ödeme */

/* Ödeme özetinde küçük ürün görseli */
add_filter('woocommerce_cart_item_name', function ($name, $cart_item) {
    if (!is_checkout() || is_cart()) return $name;
    $product = $cart_item['data'] ?? null;
    if (!$product instanceof WC_Product) return $name;
    return '<span class="review-item">' . $product->get_image('woocommerce_gallery_thumbnail', ['class' => 'review-item__img'])
        . '<span class="review-item__name">' . $name . '</span></span>';
}, 10, 2);

/* Ödeme butonunun üstünde gerçek koşullar */
add_action('woocommerce_review_order_before_submit', function () {
    echo '<ul class="checkout-trust">'
        . '<li>' . kinetik_icon('truck', 18) . ' Ödemesi onaylanan siparişler en geç ' . (int) kinetik_dispatch_days() . ' iş günü içinde kargoda</li>'
        . '<li>' . kinetik_icon('return', 18) . ' Teslimattan itibaren ' . (int) kinetik_return_days() . ' gün içinde iade</li>'
        . '<li>' . kinetik_icon('shield', 18) . ' Havale / EFT ile ödeme — kart bilgisi istenmez</li>'
        . '</ul>';
}, 4);

/* Ödeme sayfası etiketleri */
add_filter('gettext', function ($translation, $text, $domain) {
    if ($domain !== 'woocommerce') return $translation;
    static $map = [
        'Billing details'              => 'Fatura ve teslimat bilgileri',
        'Ship to a different address?' => 'Siparişim başka bir adrese gönderilsin',
        'Additional information'       => 'Sipariş notu',
        'Notes about your order, e.g. special notes for delivery.' => 'Kurye için not (isteğe bağlı)',
        'Your order'                   => 'Siparişin',
        'Proceed to checkout'          => 'Ödemeye geç',
        'Place order'                  => 'Siparişi onayla',
    ];
    return $map[$text] ?? $translation;
}, 10, 3);

/* Hesabım: indirilebilir ürün yok */
add_filter('woocommerce_account_menu_items', function ($items) {
    unset($items['downloads']);
    return $items;
});

/* Boş sepet: yönlendiren mesaj */
remove_action('woocommerce_cart_is_empty', 'wc_empty_cart_message', 10);
add_action('woocommerce_cart_is_empty', function () {
    echo '<div class="empty-state"><p class="empty-state__title">Sepetin boş.</p><p>Yeni gelenlere göz at, bedenini seç, gerisini biz hallederiz.</p></div>';
}, 10);
add_filter('woocommerce_return_to_shop_text', fn() => 'Alışverişe başla');

/**
 * Filtre formu GET parametrelerini WooCommerce'in beklediği biçime getirir:
 *   filter_beden[]=s&filter_beden[]=m  →  filter_beden=s,m
 * ve boş min_price/max_price'ı kaldırır (boş max_price WooCommerce'te "0 TL'ye kadar" demek olur).
 * main.js formu zaten temiz URL ile gönderir; bu katman JS'siz gönderim içindir.
 * WC_Query pre_get_posts'ta okuduğu için init'te çalışmalı.
 */
add_action('init', function () {
    if (is_admin()) return;
    foreach ($_GET as $k => $v) {
        if (strpos($k, 'filter_') === 0 && is_array($v)) {
            $_GET[$k] = implode(',', array_map('sanitize_title', wp_unslash($v)));
            if ($_GET[$k] === '') unset($_GET[$k]);
        }
    }
    foreach (array_keys($_GET) as $k) {
        if (strpos($k, 'query_type_') === 0 && empty($_GET['filter_' . substr($k, 11)])) unset($_GET[$k]);
    }
    foreach (['min_price', 'max_price'] as $k) {
        if (isset($_GET[$k]) && trim((string) $_GET[$k]) === '') unset($_GET[$k]);
    }
}, 1);

/* Sepette "gönderim bedeli hesapla" formu ve tahmini metni gereksiz: tek bölge (Türkiye), sabit ücret. */
add_filter('pre_option_woocommerce_enable_shipping_calc', fn() => 'no');
add_filter('woocommerce_shipping_estimate_html', '__return_empty_string');
