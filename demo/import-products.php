<?php
/**
 * KİNETİK demo — ürün kataloğu içe aktarıcı. İdempotent: SKU ile eşleşir, mevcut ürünü yerinde günceller.
 *
 *   export WP_CLI_PHP_ARGS='-d error_reporting=24575'
 *   wp eval-file demo/setup-store.php        # önce: kategoriler + beden/cinsiyet özellikleri
 *   wp eval-file demo/import-products.php
 *   KINETIK_OFFSET=0 KINETIK_LIMIT=10 wp eval-file demo/import-products.php   # parça parça
 *
 * Kaynak: demo/catalog.json  ·  Görseller: demo/images/ (krediler: images/CREDITS.md)
 * - Beden (pa_beden) birden fazla ise değişken ürün; her varyasyon aynı fiyat, stok takibi açık, 10 adet.
 * - Tek beden ("Standart") ise basit ürün, stok 10.
 * - Cinsiyet (pa_cinsiyet) görünür, varyasyon dışı özellik.
 * - Görseller medya kütüphanesine bir kez yüklenir (_kinetik_source_file meta ile tekrar kullanılır).
 * - Stok miktarı yalnızca ürün/varyasyon İLK oluşturulurken 10'a ayarlanır; sonraki çalıştırmalar satışları ezmez.
 */
if (!defined('ABSPATH')) exit('WordPress runtime gerekli.');
if (!class_exists('WooCommerce')) { echo "WooCommerce aktif değil.\n"; return; }

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

const KINETIK_INITIAL_STOCK = 10;

$dir     = __DIR__;
$img_dir = $dir . '/images';
$catalog = json_decode((string) file_get_contents($dir . '/catalog.json'), true);
if (!is_array($catalog)) { echo "catalog.json okunamadı.\n"; return; }

$offset = max(0, (int) (getenv('KINETIK_OFFSET') ?: 0));
$limit  = (int) (getenv('KINETIK_LIMIT') ?: 0);
$total  = count($catalog);
$catalog = array_slice($catalog, $offset, $limit > 0 ? $limit : null);

function kinetik_log($msg) { if (class_exists('WP_CLI')) WP_CLI::log($msg); else echo $msg . "\n"; }
function kinetik_warn($msg) { if (class_exists('WP_CLI')) WP_CLI::warning($msg); else echo "UYARI: $msg\n"; }

/** Global özellik terimini getirir (yoksa oluşturur). */
function kinetik_term($taxonomy, $name) {
    $slug = sanitize_title($name);
    $t = get_term_by('slug', $slug, $taxonomy) ?: get_term_by('name', $name, $taxonomy);
    if ($t) return $t;
    $r = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
    return is_wp_error($r) ? null : get_term($r['term_id'], $taxonomy);
}

/** Kaynak dosyayı medya kütüphanesine bir kez yükler; aynı dosya için mevcut eki döndürür. */
function kinetik_attachment($img_dir, $file, $alt, $parent_id, &$stats) {
    $found = get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids',
        'meta_key' => '_kinetik_source_file', 'meta_value' => $file]);
    if ($found) {
        $id = (int) $found[0];
        update_post_meta($id, '_wp_attachment_image_alt', $alt);
        $stats['img_reused']++;
        return $id;
    }
    $src = $img_dir . '/' . $file;
    if (!is_readable($src)) { kinetik_warn("Görsel yok: $src"); return 0; }
    $tmp = wp_tempnam($file);
    copy($src, $tmp);
    $id = media_handle_sideload(['name' => $file, 'tmp_name' => $tmp], $parent_id, $alt);
    if (is_wp_error($id)) { @unlink($tmp); kinetik_warn("$file: " . $id->get_error_message()); return 0; }
    update_post_meta($id, '_kinetik_source_file', $file);
    update_post_meta($id, '_wp_attachment_image_alt', $alt);
    $stats['img_new']++;
    return (int) $id;
}

$tax_size   = 'pa_beden';
$tax_gender = 'pa_cinsiyet';
foreach ([$tax_size, $tax_gender] as $tax) {
    if (!taxonomy_exists($tax)) { echo "Özellik taksonomisi yok: $tax — önce demo/setup-store.php çalıştırın.\n"; return; }
}
$size_attr_id   = wc_attribute_taxonomy_id_by_name('beden');
$gender_attr_id = wc_attribute_taxonomy_id_by_name('cinsiyet');

$stats = ['created' => 0, 'updated' => 0, 'variations_new' => 0, 'variations_upd' => 0, 'img_new' => 0, 'img_reused' => 0, 'errors' => 0];

foreach ($catalog as $i => $row) {
    $sku = $row['sku'] ?? '';
    $n = $offset + $i + 1;
    if (!$sku || empty($row['name'])) { $stats['errors']++; kinetik_warn("Satır $n atlandı (sku/name yok)."); continue; }

    $sizes     = array_values($row['sizes'] ?? ['Standart']);
    $is_simple = count($sizes) <= 1;
    $price     = (string) (int) $row['price'];
    $sale      = !empty($row['sale_price']) ? (string) (int) $row['sale_price'] : '';

    $existing_id = wc_get_product_id_by_sku($sku);
    $product = $existing_id ? wc_get_product($existing_id) : null;
    // Tür değiştiyse (basit <-> değişken) ürünü doğru sınıfla yeniden yükle
    if ($product && $is_simple && !$product->is_type('simple')) {
        wp_set_object_terms($existing_id, 'simple', 'product_type');
        foreach ($product->get_children() as $child) wp_delete_post($child, true);
        $product = new WC_Product_Simple($existing_id);
    } elseif ($product && !$is_simple && !$product->is_type('variable')) {
        wp_set_object_terms($existing_id, 'variable', 'product_type');
        $product = new WC_Product_Variable($existing_id);
    }
    $is_new = !$product;
    if ($is_new) $product = $is_simple ? new WC_Product_Simple() : new WC_Product_Variable();

    $product->set_name($row['name']);
    if ($is_new) $product->set_slug(sanitize_title($row['name']));
    $product->set_sku($sku);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_description($row['description'] ?? '');
    $product->set_short_description($row['short_description'] ?? '');
    $product->set_featured(!empty($row['featured']));
    $product->set_reviews_allowed(true);

    $cat = get_term_by('slug', $row['category'], 'product_cat');
    if ($cat) $product->set_category_ids([(int) $cat->term_id]);
    else kinetik_warn("$sku: kategori yok ({$row['category']})");

    // Özellikler
    $attrs = [];
    $gender_term = kinetik_term($tax_gender, $row['gender'] ?? 'Unisex');
    $a = new WC_Product_Attribute();
    $a->set_id($gender_attr_id);
    $a->set_name($tax_gender);
    $a->set_options($gender_term ? [(int) $gender_term->term_id] : []);
    $a->set_position(1);
    $a->set_visible(true);
    $a->set_variation(false);
    $attrs[] = $a;

    $size_terms = [];
    foreach ($sizes as $s) { $t = kinetik_term($tax_size, $s); if ($t) $size_terms[$s] = $t; }
    $a = new WC_Product_Attribute();
    $a->set_id($size_attr_id);
    $a->set_name($tax_size);
    $a->set_options(array_map(fn($t) => (int) $t->term_id, array_values($size_terms)));
    $a->set_position(0);
    $a->set_visible(true);
    $a->set_variation(!$is_simple);
    $attrs[] = $a;
    $product->set_attributes($attrs);

    if ($is_simple) {
        $product->set_regular_price($price);
        $product->set_sale_price($sale);
        $product->set_manage_stock(true);
        if ($is_new || $product->get_stock_quantity() === null) $product->set_stock_quantity(KINETIK_INITIAL_STOCK);
        $product->set_stock_status('instock');
    } else {
        $product->set_manage_stock(false);
        $product->set_default_attributes([]);
    }

    $product_id = $product->save();
    if (!$product_id) { $stats['errors']++; kinetik_warn("$sku kaydedilemedi."); continue; }

    // Varyasyonlar
    if (!$is_simple) {
        $existing = [];
        foreach ($product->get_children() as $vid) {
            $v = wc_get_product($vid);
            if ($v) $existing[$v->get_attribute($tax_size) ? sanitize_title($v->get_attribute($tax_size)) : ''] = $v;
        }
        $wanted = [];
        foreach ($size_terms as $label => $term) {
            $key = $term->slug;
            $wanted[$key] = true;
            $v = $existing[$key] ?? null;
            $v_new = !$v;
            if ($v_new) { $v = new WC_Product_Variation(); $v->set_parent_id($product_id); }
            $v->set_attributes([$tax_size => $term->slug]);
            $v->set_sku($sku . '-' . strtoupper($term->slug));
            $v->set_regular_price($price);
            $v->set_sale_price($sale);
            $v->set_manage_stock(true);
            if ($v_new || $v->get_stock_quantity() === null) $v->set_stock_quantity(KINETIK_INITIAL_STOCK);
            $v->set_stock_status('instock');
            $v->set_status('publish');
            $v->set_menu_order(array_search($label, $sizes, true));
            $v->save();
            $v_new ? $stats['variations_new']++ : $stats['variations_upd']++;
        }
        // Katalogdan çıkarılan bedenleri sil
        foreach ($existing as $key => $v) if (!isset($wanted[$key])) $v->delete(true);
        WC_Product_Variable::sync($product_id);
        wc_delete_product_transients($product_id);
    }

    // Görseller
    $ids = [];
    foreach (($row['images'] ?? []) as $k => $file) {
        $alt = $row['name'] . ($k > 0 ? ' – görsel ' . ($k + 1) : '');
        $aid = kinetik_attachment($img_dir, $file, $alt, $product_id, $stats);
        if ($aid) $ids[] = $aid;
    }
    if ($ids) {
        $product = wc_get_product($product_id);
        $product->set_image_id($ids[0]);
        $product->set_gallery_image_ids(array_slice($ids, 1));
        $product->save();
        // Ekleri ürüne bağla (medya kütüphanesinde "Ekli olduğu" bilgisi için)
        foreach ($ids as $aid) if ((int) get_post_field('post_parent', $aid) !== $product_id) wp_update_post(['ID' => $aid, 'post_parent' => $product_id]);
    } else {
        kinetik_warn("$sku: görsel yok.");
    }

    update_post_meta($product_id, '_kinetik_colour', $row['colour'] ?? '');
    $is_new ? $stats['created']++ : $stats['updated']++;
    kinetik_log(sprintf('[%d/%d] %s %s — %s TL%s (%s, %d görsel)', $n, $total, $is_new ? 'YENİ ' : 'GÜNCEL', $sku, $price,
        $sale ? " → $sale TL" : '', $is_simple ? 'basit' : count($size_terms) . ' beden', count($ids)));
}

wc_delete_product_transients();
kinetik_log(sprintf('Tamamlandı. Yeni: %d, güncellenen: %d, yeni varyasyon: %d, güncellenen varyasyon: %d, yeni görsel: %d, tekrar kullanılan görsel: %d, hata: %d',
    $stats['created'], $stats['updated'], $stats['variations_new'], $stats['variations_upd'], $stats['img_new'], $stats['img_reused'], $stats['errors']));
