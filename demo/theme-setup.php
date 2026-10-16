<?php
/**
 * KİNETİK teması — kurulum sonrası ayarlar. İdempotent.
 *   wp eval-file demo/theme-setup.php
 * - Menü konumları (tema modları temaya özeldir; tema değişince yeniden atanmalı)
 * - Site simgesi (assets/logo/icon-512.png → ortam kütüphanesi)
 * İletişim ve Beden Rehberi içerikleri şablondan gelir (page-iletisim.php, page-beden-rehberi.php).
 */
if (!defined('ABSPATH')) exit;
if (get_option('stylesheet') !== 'kinetik') { echo "Önce temayı etkinleştir: wp theme activate kinetik\n"; return; }

$locations = ['primary' => 'Ana Menü', 'footer_help' => 'Alt Menü - Yardım', 'footer_legal' => 'Alt Menü - Kurumsal'];
$mods = get_theme_mod('nav_menu_locations', []);
foreach ($locations as $loc => $name) {
    $menu = wp_get_nav_menu_object($name);
    if ($menu) { $mods[$loc] = (int) $menu->term_id; echo "Menü: {$loc} → {$name}\n"; }
    else echo "UYARI: '{$name}' menüsü yok (demo/setup-store.php çalıştırıldı mı?)\n";
}
set_theme_mod('nav_menu_locations', $mods);

$src = get_template_directory() . '/assets/logo/icon-512.png';
$hash = md5_file($src);
$existing = get_posts(['post_type' => 'attachment', 'meta_key' => '_kinetik_site_icon_hash', 'meta_value' => $hash, 'fields' => 'ids', 'numberposts' => 1]);
if ($existing) {
    $att = (int) $existing[0];
} else {
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    $tmp = wp_tempnam('kinetik-icon.png');
    copy($src, $tmp);
    $att = media_handle_sideload(['name' => 'kinetik-site-icon.png', 'tmp_name' => $tmp], 0, wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . ' site simgesi');
    if (is_wp_error($att)) { echo 'Simge yüklenemedi: ' . $att->get_error_message() . "\n"; return; }
    update_post_meta($att, '_kinetik_site_icon_hash', $hash);
}
update_option('site_icon', $att);
echo "Site simgesi: #{$att}\n";
