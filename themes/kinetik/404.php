<?php
if (!defined('ABSPATH')) exit;
get_header();
$shop = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');
?>
<section class="notfound wrap">
  <p class="notfound__code" aria-hidden="true">404</p>
  <h1 class="notfound__title">Bu sayfa yarışı bırakmış.</h1>
  <p>Aradığın sayfa taşınmış ya da hiç olmamış olabilir. Ürün arayarak ya da kategorilerden devam edebilirsin.</p>
  <form class="notfound__search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
    <label class="screen-reader-text" for="nf-q">Ürün ara</label>
    <input id="nf-q" type="search" name="s" placeholder="Ürün ara">
    <input type="hidden" name="post_type" value="product">
    <button type="submit" class="btn btn--dark">Ara</button>
  </form>
  <p><a class="btn btn--primary" href="<?php echo esc_url($shop); ?>">Tüm ürünler</a></p>
</section>
<?php get_footer();
