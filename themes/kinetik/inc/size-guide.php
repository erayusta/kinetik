<?php
/**
 * Beden rehberi — tek kaynak. /beden-rehberi/ sayfası (page-beden-rehberi.php) ve PDP'deki
 * "Beden tablosu" modalı aynı tabloları basar.
 *
 * Değerler GENEL vücut ölçüsü aralıklarıdır (cm), ürün ölçüsü değil; sayfada da öyle etiketlenir.
 * Ürüne özel ölçü eklenecekse ürün açıklamasına yazılmalı.
 */
if (!defined('ABSPATH')) exit;

function kinetik_size_tables() {
    return [
        'ust' => [
            'title' => 'Üst giyim',
            'note'  => 'Tişört, sweatshirt, hoodie ve eşofman üstü için.',
            'cols'  => ['Beden', 'Göğüs çevresi', 'Bel çevresi'],
            'rows'  => [
                ['XS', '86–90', '70–74'],
                ['S', '90–96', '74–80'],
                ['M', '96–102', '80–86'],
                ['L', '102–108', '86–92'],
                ['XL', '108–114', '92–98'],
                ['XXL', '114–120', '98–104'],
            ],
        ],
        'alt' => [
            'title' => 'Alt giyim',
            'note'  => 'Eşofman altı ve şort için.',
            'cols'  => ['Beden', 'Bel çevresi', 'Basen (kalça)', 'İç bacak boyu'],
            'rows'  => [
                ['XS', '68–72', '86–90', '76'],
                ['S', '72–78', '90–96', '78'],
                ['M', '78–84', '96–102', '80'],
                ['L', '84–90', '102–108', '81'],
                ['XL', '90–96', '108–114', '82'],
                ['XXL', '96–102', '114–120', '83'],
            ],
        ],
        'tayt' => [
            'title' => 'Kadın tayt',
            'note'  => 'Yüksek bel tayt ve biker şort için; kumaş esnek olduğundan iki beden arasında kalırsan küçüğünü seçebilirsin.',
            'cols'  => ['Beden', 'Bel çevresi', 'Basen (kalça)'],
            'rows'  => [
                ['XS', '60–64', '84–88'],
                ['S', '64–68', '88–92'],
                ['M', '68–74', '92–98'],
                ['L', '74–80', '98–104'],
                ['XL', '80–86', '104–110'],
                ['XXL', '86–92', '110–116'],
            ],
        ],
    ];
}

/** Hangi tablolar bu ürüne uyar? Aksesuarda tablo yok. */
function kinetik_size_keys_for_product($product) {
    if (!$product instanceof WC_Product) return [];
    $cats = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'slugs']);
    $cats = is_wp_error($cats) ? [] : $cats;
    if (in_array('aksesuar', $cats, true)) return [];
    $women = has_term('kadin', 'pa_cinsiyet', $product->get_id());
    if (in_array('sort-tayt', $cats, true)) {
        $is_tayt = stripos($product->get_name(), 'tayt') !== false || stripos($product->get_name(), 'biker') !== false;
        return ($women && $is_tayt) ? ['tayt', 'alt'] : ['alt'];
    }
    if (in_array('esofman', $cats, true)) return ['ust', 'alt'];
    return ['ust'];
}

function kinetik_size_table_html($key) {
    $tables = kinetik_size_tables();
    if (!isset($tables[$key])) return '';
    $t = $tables[$key];
    $h = '<div class="size-table">';
    $h .= '<h3 class="size-table__title">' . esc_html($t['title']) . '</h3>';
    $h .= '<p class="size-table__note">' . esc_html($t['note']) . '</p>';
    $h .= '<div class="size-table__scroll"><table><thead><tr>';
    foreach ($t['cols'] as $i => $c) $h .= '<th scope="col">' . esc_html($c) . ($i ? ' <small>cm</small>' : '') . '</th>';
    $h .= '</tr></thead><tbody>';
    foreach ($t['rows'] as $r) {
        $h .= '<tr>';
        foreach ($r as $i => $cell) $h .= $i ? '<td>' . esc_html($cell) . '</td>' : '<th scope="row">' . esc_html($cell) . '</th>';
        $h .= '</tr>';
    }
    $h .= '</tbody></table></div></div>';
    return $h;
}

function kinetik_size_howto_html() {
    return '<ul class="size-howto">'
        . '<li><b>Göğüs:</b> Mezurayı kollarının altından, göğsün en geniş yerinden yere paralel geçir.</li>'
        . '<li><b>Bel:</b> Belinin en ince noktasından, nefesini tutmadan ölç.</li>'
        . '<li><b>Basen:</b> Kalçanın en geniş yerinden, ayakların bitişikken ölç.</li>'
        . '<li><b>İç bacak:</b> Kasıktan ayak bileğine kadar bacağın iç çizgisi boyunca ölç.</li>'
        . '</ul>';
}
