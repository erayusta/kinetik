<?php
/**
 * Genel arama (post_type verilmemiş). Ürün araması (post_type=product) woocommerce.php arşivine düşer.
 * Burada ürün sonuçları kart, sayfa sonuçları liste olarak gösterilir.
 */
if (!defined('ABSPATH')) exit;
get_header();
$products = []; $pages = [];
if (have_posts()) {
    while (have_posts()) { the_post(); if (get_post_type() === 'product') $products[] = get_the_ID(); else $pages[] = get_post(); }
}
?>
<div class="page-wrap wrap search-results">
  <header class="page-head"><h1 class="page-head__title">Arama: “<?php echo esc_html(get_search_query()); ?>”</h1></header>
  <?php if ($products): ?>
    <ul class="products product-grid product-grid--4">
      <?php foreach ($products as $pid) { $GLOBALS['post'] = get_post($pid); setup_postdata($GLOBALS['post']); $GLOBALS['product'] = wc_get_product($pid); wc_get_template_part('content', 'product'); } wp_reset_postdata(); ?>
    </ul>
  <?php endif; ?>
  <?php if ($pages): ?>
    <ul class="search-pages">
      <?php foreach ($pages as $p): ?><li><a href="<?php echo esc_url(get_permalink($p)); ?>"><?php echo esc_html(get_the_title($p)); ?></a></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if (!$products && !$pages): ?>
    <div class="empty-state"><p class="empty-state__title">Sonuç bulunamadı.</p><p>Daha kısa bir kelimeyle ya da kategori adıyla aramayı dene.</p>
      <form class="notfound__search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>"><label class="screen-reader-text" for="sr-q">Ürün ara</label><input id="sr-q" type="search" name="s" value="<?php echo esc_attr(get_search_query()); ?>"><input type="hidden" name="post_type" value="product"><button type="submit" class="btn btn--dark">Ara</button></form>
    </div>
  <?php endif; ?>
</div>
<?php get_footer();
