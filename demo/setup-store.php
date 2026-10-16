<?php
/**
 * KİNETİK — demo mağaza kurulumu. İdempotent: tekrar çalıştırılabilir.
 *   wp eval-file demo/setup-store.php
 * Yasal sayfaların içeriği demo/fill-legal-pages.php'nin sahipliğindedir; burada yalnızca oluşturulur.
 *
 * Buradaki satıcı künyesi TAMAMEN KURGUSALDIR (demo). Gerçek mağazada WooCommerce → Sözleşmeler
 * ekranından kendi bilgilerinizi girin; bu betik dolu alanların üzerine yazmaz.
 */
if (!defined('ABSPATH')) exit;
if (!class_exists('WooCommerce')) { echo "WooCommerce aktif değil.\n"; return; }

// Mağaza iş kuralları (demo değerleri). Tema ve eklentiler bu değerleri WooCommerce kargo yöntemlerinden okur.
const KINETIK_FREE_SHIPPING = 2000;
const KINETIK_FLAT_SHIPPING = 99;
const KINETIK_BRAND         = 'KİNETİK';

update_option('blogname', KINETIK_BRAND);
update_option('WPLANG', 'tr_TR');
// Marka renkleri: WooCommerce e-postaları
update_option('woocommerce_email_base_color', '#0D0D0D');
update_option('woocommerce_email_background_color', '#F3F1EC');
update_option('woocommerce_email_body_background_color', '#FFFFFF');
update_option('woocommerce_email_text_color', '#1C1C1C');
update_option('woocommerce_email_footer_text', KINETIK_BRAND . ' · ' . wp_parse_url(home_url(), PHP_URL_HOST));
update_option('woocommerce_email_from_name', KINETIK_BRAND);
update_option('blogdescription', 'Hareket et. İz bırak.');
update_option('timezone_string', 'Europe/Istanbul');
update_option('date_format', 'j F Y');
update_option('permalink_structure', '/%postname%/');
update_option('woocommerce_permalinks', array_merge((array) get_option('woocommerce_permalinks', []), [
    'product_base' => '/urun', 'category_base' => 'urun-kategorisi', 'tag_base' => 'urun-etiketi', 'attribute_base' => '', 'use_verbose_page_rules' => false,
]));
update_option('woocommerce_currency', 'TRY');
update_option('woocommerce_currency_pos', 'right_space');
update_option('woocommerce_price_thousand_sep', '.');
update_option('woocommerce_price_decimal_sep', ',');
update_option('woocommerce_price_num_decimals', 0);
update_option('woocommerce_default_country', 'TR:TR34');
update_option('woocommerce_store_address', 'Örnek Mah. Demo Sok. No:1');
update_option('woocommerce_store_city', 'Kadıköy / İstanbul');
update_option('woocommerce_store_postcode', '34710');
update_option('woocommerce_calc_taxes', 'no');
update_option('woocommerce_enable_guest_checkout', 'yes');
update_option('woocommerce_enable_checkout_login_reminder', 'yes');
update_option('woocommerce_enable_signup_and_login_from_checkout', 'yes');
update_option('woocommerce_allowed_countries', 'specific');
update_option('woocommerce_specific_allowed_countries', ['TR']);
update_option('woocommerce_ship_to_countries', 'specific');
update_option('woocommerce_specific_ship_to_countries', ['TR']);
update_option('woocommerce_weight_unit', 'kg');
update_option('woocommerce_dimension_unit', 'cm');
update_option('woocommerce_manage_stock', 'yes');
update_option('woocommerce_hide_out_of_stock_items', 'no');
update_option('woocommerce_enable_reviews', 'yes');
update_option('woocommerce_review_rating_verification_required', 'yes');
update_option('woocommerce_coming_soon', 'no');
update_option('woocommerce_feature_order_attribution_enabled', 'no');

// Satıcı künyesi — tek kaynak (WooCommerce → Sözleşmeler ekranından düzenlenir). KURGUSAL demo değerleri;
// mevcut dolu alanlar korunur. Boş alanlar sayfalarda "bekleyen alan" olarak işaretlenir.
$seller = get_option('kinetik_seller_info', []);
update_option('kinetik_seller_info', array_merge([
    'unvan'        => 'Kinetik Demo Spor Giyim Ltd. Şti.',
    'marka'        => KINETIK_BRAND,
    'adres'        => 'Örnek Mah. Demo Sok. No:1, Kadıköy / İstanbul',
    'telefon'      => '0850 000 00 00',
    'eposta'       => 'demo@example.com',
    'vergi_dairesi'=> 'Demo',
    'vergi_no'     => 'Demo',
    'mersis'       => 'Demo',
    'iban'         => 'Demo',
    'banka'        => 'Demo',
    'web'          => home_url('/'),
], is_array($seller) ? array_filter($seller, 'strlen') : []));

// Kategoriler
$categories = [
    'Tişört'               => ['tisort', 'Günlük antrenmandan sokağa: nefes alan, iyi duran tişörtler.'],
    'Eşofman'              => ['esofman', 'Takım ya da tek parça; rahat kalıplı, şık eşofmanlar.'],
    'Sweatshirt & Hoodie'  => ['sweatshirt-hoodie', 'Serin havalar için kalın dokulu sweatshirt ve kapüşonlular.'],
    'Şort & Tayt'          => ['sort-tayt', 'Koşu, salon ve yaz için hafif şort ve taytlar.'],
    'Aksesuar'             => ['aksesuar', 'Şapka, çanta ve çorap: kombini tamamlayan parçalar.'],
];
foreach ($categories as $name => [$slug, $desc]) {
    $t = term_exists($slug, 'product_cat');
    if (!$t) wp_insert_term($name, 'product_cat', ['slug' => $slug, 'description' => $desc]);
    else wp_update_term((int) $t['term_id'], 'product_cat', ['name' => $name, 'description' => $desc]);
}
// Varsayılan "Kategorilenmemiş" kategorisini gizlemek için adını değiştir
$uncat = get_term_by('slug', 'uncategorized', 'product_cat') ?: get_term_by('slug', 'kategorilenmemis', 'product_cat');
if ($uncat) wp_update_term($uncat->term_id, 'product_cat', ['name' => 'Diğer', 'slug' => 'diger']);

// Global özellikler: beden + cinsiyet
function kinetik_demo_attribute($slug, $name, array $terms) {
    $id = wc_attribute_taxonomy_id_by_name($slug);
    if (!$id) {
        $id = wc_create_attribute(['name' => $name, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false]);
        delete_transient('wc_attribute_taxonomies');
        WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
    }
    $tax = 'pa_' . $slug;
    if (!taxonomy_exists($tax)) register_taxonomy($tax, 'product', ['hierarchical' => false, 'labels' => ['name' => $name]]);
    foreach ($terms as $i => $term) {
        $t = term_exists(sanitize_title($term), $tax);
        if (!$t) $t = wp_insert_term($term, $tax, ['slug' => sanitize_title($term)]);
        if (!is_wp_error($t)) update_term_meta((int) $t['term_id'], 'order', $i);
    }
}
kinetik_demo_attribute('beden', 'Beden', ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'Standart']);
kinetik_demo_attribute('cinsiyet', 'Cinsiyet', ['Erkek', 'Kadın', 'Unisex']);

// Sayfalar (yalnızca yoksa oluşturur; içerik sahipliği ilgili fill-* betiklerinde)
function kinetik_demo_page($title, $slug, $content = '') {
    $existing = get_page_by_path($slug);
    if ($existing) return $existing->ID;
    return wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => $content]);
}

$home_id = kinetik_demo_page('Ana Sayfa', 'ana-sayfa');
$free = number_format(KINETIK_FREE_SHIPPING, 0, ',', '.');
$pages = [
    ['Hakkımızda', 'hakkimizda', '<p><strong>' . KINETIK_BRAND . '</strong>, hareketi hayatın merkezine koyanlar için tasarlanmış bir spor giyim markasıdır. Antrenmanda, sokakta ya da hafta sonu kaçamağında aynı rahatlığı ve aynı duruşu taşıyan parçalar üretiyoruz.</p><h2>Neye önem veriyoruz?</h2><p>Sade çizgiler, iyi oturan kalıplar ve her gün giymek isteyeceğiniz kumaşlar. Koleksiyonumuzu az ama doğru parçalarla kuruyor, her siparişi kendi depomuzda özenle hazırlıyoruz.</p><h2>Sorunuz mu var?</h2><p>İletişim sayfamızdan bize her zaman ulaşabilirsiniz.</p>'],
    ['İletişim', 'iletisim', ''],
    ['Sıkça Sorulan Sorular', 'sikca-sorulan-sorular', "<h2>Nasıl ödeme yapabilirim?</h2><p>Şu an Havale / EFT ile ödeme alıyoruz. Banka bilgileri sipariş onay ekranında ve e-postanızda yer alır. Açıklama alanına sipariş numaranızı yazmanız yeterlidir.</p><h2>Kargo ücreti ne kadar?</h2><p>Türkiye geneli kargo bedeli " . KINETIK_FLAT_SHIPPING . " TL'dir. {$free} TL ve üzeri siparişlerde kargo ücretsizdir.</p><h2>Siparişim ne zaman kargoya verilir?</h2><p>Ödemesi onaylanan siparişler en geç 3 iş günü içinde kargoya teslim edilir; çoğu sipariş aynı ya da ertesi iş günü yola çıkar.</p><h2>Bedenimi nasıl seçerim?</h2><p>Her ürün sayfasında bulunan <a href=\"/beden-rehberi/\">Beden Rehberi</a>'ni kullanabilirsiniz. İki beden arasında kaldıysanız rahat kullanım için bir büyüğünü öneririz.</p><h2>İade ve değişim yapabilir miyim?</h2><p>Teslimattan itibaren 14 gün içinde, kullanılmamış ve etiketi sökülmemiş ürünleri iade edebilir ya da değiştirebilirsiniz. Detaylar için <a href=\"/kargo-ve-iade/\">Kargo ve İade</a> sayfamıza göz atın.</p>"],
    ['Kargo ve İade', 'kargo-ve-iade', "<h2>Kargo</h2><p>Siparişler Türkiye'nin her yerine anlaşmalı kargo firmasıyla gönderilir. Kargo bedeli " . KINETIK_FLAT_SHIPPING . " TL'dir; {$free} TL ve üzeri siparişlerde ücretsizdir. Ödemesi onaylanan siparişler en geç 3 iş günü içinde kargoya teslim edilir ve takip numarası e-posta ile paylaşılır.</p><h2>İade ve değişim</h2><p>Teslim aldığınız tarihten itibaren 14 gün içinde cayma hakkınızı kullanabilirsiniz. Ürünün kullanılmamış, yıkanmamış ve etiketinin sökülmemiş olması gerekir. Hijyen nedeniyle ambalajı açılmış çoraplar iade kapsamı dışındadır.</p><p>İade talebinizi Hesabım sayfasındaki siparişler bölümünden ya da İletişim sayfamızdaki kanallardan iletebilirsiniz. Ürün depomuza ulaşıp kontrol edildikten sonra ücret iadesi en geç 14 gün içinde ödemenin yapıldığı hesaba gönderilir.</p><h2>Hasarlı veya hatalı ürün</h2><p>Kargo paketinde hasar varsa kargo görevlisine tutanak tutturun. Hatalı ya da yanlış gönderilen ürünlerde kargo bedeli bize aittir.</p>"],
    ['Beden Rehberi', 'beden-rehberi', ''],
    ['KVKK Aydınlatma Metni', 'kvkk-aydinlatma-metni'],
    ['Gizlilik Politikası', 'gizlilik-politikasi'],
    ['Çerez Politikası', 'cerez-politikasi'],
    ['Mesafeli Satış Sözleşmesi', 'mesafeli-satis-sozlesmesi'],
    ['Ön Bilgilendirme Formu', 'on-bilgilendirme-formu'],
    ['İptal ve İade Koşulları', 'iptal-ve-iade-kosullari'],
];
foreach ($pages as $p) kinetik_demo_page($p[0], $p[1], $p[2] ?? '');

update_option('show_on_front', 'page');
update_option('page_on_front', $home_id);
$privacy = get_page_by_path('gizlilik-politikasi');
if ($privacy) update_option('wp_page_for_privacy_policy', $privacy->ID);
$terms = get_page_by_path('mesafeli-satis-sozlesmesi');
if ($terms) update_option('woocommerce_terms_page_id', $terms->ID);

// WordPress'in varsayılan içeriği
foreach (['hello-world', 'merhaba-dunya'] as $slug) {
    $p = get_page_by_path($slug, OBJECT, 'post');
    if ($p) wp_delete_post($p->ID, true);
}
foreach (['sample-page', 'ornek-sayfa'] as $slug) {
    $p = get_page_by_path($slug);
    if ($p) wp_delete_post($p->ID, true);
}
$draft_privacy = get_posts(['post_type' => 'page', 'post_status' => 'draft', 'name' => 'privacy-policy']);
foreach ($draft_privacy as $p) wp_delete_post($p->ID, true);

// Menüler
function kinetik_demo_menu($name, $location, array $items) {
    $menu = wp_get_nav_menu_object($name);
    $menu_id = $menu ? $menu->term_id : wp_create_nav_menu($name);
    foreach (wp_get_nav_menu_items($menu_id) ?: [] as $old) wp_delete_post($old->ID, true);
    foreach ($items as [$title, $url]) {
        wp_update_nav_menu_item($menu_id, 0, ['menu-item-title' => $title, 'menu-item-url' => $url, 'menu-item-status' => 'publish']);
    }
    $loc = get_theme_mod('nav_menu_locations', []);
    $loc[$location] = $menu_id;
    set_theme_mod('nav_menu_locations', $loc);
}
$cat = fn($slug) => home_url('/urun-kategorisi/' . $slug . '/');
kinetik_demo_menu('Ana Menü', 'primary', [
    ['Yeni Gelenler', wc_get_page_permalink('shop')],
    ['Tişört', $cat('tisort')],
    ['Eşofman', $cat('esofman')],
    ['Sweatshirt & Hoodie', $cat('sweatshirt-hoodie')],
    ['Şort & Tayt', $cat('sort-tayt')],
    ['Aksesuar', $cat('aksesuar')],
]);
kinetik_demo_menu('Alt Menü - Yardım', 'footer_help', [
    ['Sıkça Sorulan Sorular', home_url('/sikca-sorulan-sorular/')],
    ['Kargo ve İade', home_url('/kargo-ve-iade/')],
    ['Beden Rehberi', home_url('/beden-rehberi/')],
    ['İletişim', home_url('/iletisim/')],
    ['Hesabım', wc_get_page_permalink('myaccount')],
]);
kinetik_demo_menu('Alt Menü - Kurumsal', 'footer_legal', [
    ['Hakkımızda', home_url('/hakkimizda/')],
    ['KVKK Aydınlatma Metni', home_url('/kvkk-aydinlatma-metni/')],
    ['Gizlilik Politikası', home_url('/gizlilik-politikasi/')],
    ['Çerez Politikası', home_url('/cerez-politikasi/')],
    ['Mesafeli Satış Sözleşmesi', home_url('/mesafeli-satis-sozlesmesi/')],
    ['Ön Bilgilendirme Formu', home_url('/on-bilgilendirme-formu/')],
    ['İptal ve İade Koşulları', home_url('/iptal-ve-iade-kosullari/')],
]);

// Ödeme: yalnızca Havale / EFT
$bacs = get_option('woocommerce_bacs_settings', []);
$bacs['enabled'] = 'yes';
$bacs['title'] = 'Havale / EFT';
$bacs['description'] = 'Siparişinizi tamamladıktan sonra banka hesap bilgilerimiz görüntülenecektir. Açıklama alanına sipariş numaranızı yazmayı unutmayın.';
$bacs['instructions'] = 'Ödemeniz hesabımıza ulaştığında siparişiniz hazırlanmaya başlar. Açıklama alanına sipariş numaranızı yazmayı unutmayın.';
update_option('woocommerce_bacs_settings', $bacs);
// Banka hesabı tek kaynaktan: kinetik_seller_info (iban/banka). IBAN girilmeden hesap yayınlanmaz.
$si = get_option('kinetik_seller_info', []);
if (!empty($si['iban']) && strcasecmp($si['iban'], 'Demo') !== 0) {
    update_option('woocommerce_bacs_accounts', [[
        'account_name'   => $si['unvan'],
        'account_number' => '',
        'bank_name'      => $si['banka'] ?? '',
        'sort_code'      => '',
        'iban'           => str_replace(' ', '', $si['iban']),
        'bic'            => '',
    ]]);
}
foreach (['cheque', 'cod', 'paypal'] as $gw) {
    $s = get_option("woocommerce_{$gw}_settings", []);
    if (is_array($s)) { $s['enabled'] = 'no'; update_option("woocommerce_{$gw}_settings", $s); }
}

// Kargo bölgesi
foreach (WC_Shipping_Zones::get_zones() as $z) {
    if ($z['zone_name'] === 'Türkiye') (new WC_Shipping_Zone($z['zone_id']))->delete();
}
$zone = new WC_Shipping_Zone();
$zone->set_zone_name('Türkiye');
$zone->set_zone_order(1);
$zone->add_location('TR', 'country');
$zone->save();
$flat_id = $zone->add_shipping_method('flat_rate');
$free_id = $zone->add_shipping_method('free_shipping');
update_option("woocommerce_flat_rate_{$flat_id}_settings", ['title' => 'Kargo', 'tax_status' => 'none', 'cost' => (string) KINETIK_FLAT_SHIPPING]);
update_option("woocommerce_free_shipping_{$free_id}_settings", ['title' => 'Ücretsiz Kargo', 'requires' => 'min_amount', 'min_amount' => (string) KINETIK_FREE_SHIPPING, 'ignore_discounts' => 'no']);

// Kupon
if (!wc_get_coupon_id_by_code('MERHABA5')) {
    $c = new WC_Coupon();
    $c->set_code('MERHABA5');
    $c->set_discount_type('percent');
    $c->set_amount(5);
    $c->set_minimum_amount(750);
    $c->set_usage_limit_per_user(1);
    $c->set_description('E-posta kaydına hoş geldin indirimi');
    $c->save();
}

// WooCommerce sayfalarına Türkçe slug; varsayılan iade/gizlilik taslaklarını kaldır
foreach (['shop' => ['magaza', 'Mağaza'], 'cart' => ['sepet', 'Sepet'], 'checkout' => ['odeme', 'Ödeme'], 'myaccount' => ['hesabim', 'Hesabım']] as $key => [$slug, $title]) {
    $pid = wc_get_page_id($key);
    if ($pid > 0) wp_update_post(['ID' => $pid, 'post_name' => $slug, 'post_title' => $title]);
}
foreach (['refund_returns', 'gizlilik-ilkesi', 'privacy-policy'] as $slug) {
    $p = get_page_by_path($slug);
    if ($p && (int) get_option('wp_page_for_privacy_policy') !== $p->ID) wp_delete_post($p->ID, true);
}

// Sepet ve ödeme KLASİK kısa kod olmalı (kinetik-tr-adres ve kinetik-sozlesmeler klasik checkout kancalarını kullanır)
foreach (['cart' => '[woocommerce_cart]', 'checkout' => '[woocommerce_checkout]'] as $key => $sc) {
    $pid = wc_get_page_id($key);
    if ($pid > 0 && trim((string) get_post_field('post_content', $pid)) !== $sc) wp_update_post(['ID' => $pid, 'post_content' => $sc]);
}

flush_rewrite_rules();
echo KINETIK_BRAND . " demo kurulumu tamam.\n";
