<?php
/**
 * Ürün kartı (liste, anasayfa, ilgili ürünler).
 * İkinci görsel (galerinin ilki) masaüstünde üzerine gelince görünür.
 * Sepete ekle bağlantısı woocommerce_template_loop_add_to_cart() ile basılır — kinetik-growth
 * stokta olmayan ürünlerde onu "Haber ver"e çevirir (woocommerce_loop_add_to_cart_link).
 *
 * @version 9.4.0
 */
defined('ABSPATH') || exit;
global $product;
if (empty($product) || !$product->is_visible()) return;

$link    = $product->get_permalink();
$gallery = $product->get_gallery_image_ids();
$alt_id  = $gallery ? (int) $gallery[0] : 0;
$cats    = wp_get_post_terms($product->get_id(), 'product_cat');
$cat     = (!is_wp_error($cats) && $cats) ? $cats[0]->name : '';
$sizes   = kinetik_product_sizes($product);
?>
<li <?php wc_product_class('pcard', $product); ?>>
  <a class="pcard__media<?php echo $alt_id ? ' has-alt' : ''; ?>" href="<?php echo esc_url($link); ?>" tabindex="-1" aria-hidden="true">
    <?php echo $product->get_image('woocommerce_thumbnail', ['class' => 'pcard__img', 'loading' => 'lazy', 'alt' => '', 'sizes' => '(max-width: 767px) 50vw, (max-width: 1199px) 33vw, 25vw']); ?>
    <?php if ($alt_id) echo wp_get_attachment_image($alt_id, 'woocommerce_thumbnail', false, ['class' => 'pcard__img pcard__img--alt', 'loading' => 'lazy', 'alt' => '', 'sizes' => '(max-width: 767px) 50vw, (max-width: 1199px) 33vw, 25vw']); ?>
    <?php echo kinetik_product_badge($product); ?>
  </a>
  <div class="pcard__body">
    <?php if ($cat): ?><span class="pcard__cat"><?php echo esc_html($cat); ?></span><?php endif; ?>
    <h3 class="pcard__title woocommerce-loop-product__title"><a href="<?php echo esc_url($link); ?>"><?php echo esc_html($product->get_name()); ?></a></h3>
    <div class="pcard__price"><?php echo $product->get_price_html(); ?></div>
    <?php if (count($sizes) > 1): ?>
      <div class="pcard__sizes" aria-label="Stoktaki bedenler"><?php foreach ($sizes as $s) echo '<span>' . esc_html($s) . '</span>'; ?></div>
    <?php endif; ?>
  </div>
  <div class="pcard__actions"><?php woocommerce_template_loop_add_to_cart(); ?></div>
</li>
