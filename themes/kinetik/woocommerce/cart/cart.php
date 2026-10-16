<?php
/**
 * Sepet — satır kartları (görsel, ad + beden, birim fiyat, adet adımlayıcı, kaldır, satır toplamı).
 * WooCommerce form sözleşmesi korunur: cart[key][qty], update_cart, woocommerce-cart-nonce.
 * Kupon formu sepet özetinin içinde (cart-totals.php).
 *
 * @version 10.8.0
 */
defined('ABSPATH') || exit;

do_action('woocommerce_before_cart'); ?>

<div class="cart-layout">
<form class="woocommerce-cart-form cart-items" action="<?php echo esc_url(wc_get_cart_url()); ?>" method="post">
  <?php do_action('woocommerce_before_cart_table'); ?>
  <ul class="cart-list">
    <?php do_action('woocommerce_before_cart_contents'); ?>
    <?php foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item):
        $_product   = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);
        $product_id = apply_filters('woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key);
        if (!$_product || !$_product->exists() || $cart_item['quantity'] <= 0 || !apply_filters('woocommerce_cart_item_visible', true, $cart_item, $cart_item_key)) continue;
        $permalink = apply_filters('woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink($cart_item) : '', $cart_item, $cart_item_key);
        $name = $_product->get_name();
        ?>
      <li class="cart-row <?php echo esc_attr(apply_filters('woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key)); ?>">
        <a class="cart-row__img" href="<?php echo esc_url($permalink); ?>" tabindex="-1" aria-hidden="true">
          <?php echo apply_filters('woocommerce_cart_item_thumbnail', $_product->get_image('woocommerce_gallery_thumbnail'), $cart_item, $cart_item_key); // phpcs:ignore ?>
        </a>
        <div class="cart-row__body">
          <div class="cart-row__name">
            <?php echo wp_kses_post(apply_filters('woocommerce_cart_item_name', $permalink ? sprintf('<a href="%s">%s</a>', esc_url($permalink), esc_html($name)) : esc_html($name), $cart_item, $cart_item_key)); ?>
          </div>
          <?php echo wc_get_formatted_cart_item_data($cart_item); // phpcs:ignore ?>
          <div class="cart-row__unit"><?php echo apply_filters('woocommerce_cart_item_price', WC()->cart->get_product_price($_product), $cart_item, $cart_item_key); // phpcs:ignore ?></div>
          <?php if ($_product->backorders_require_notification() && $_product->is_on_backorder($cart_item['quantity'])) echo '<p class="backorder_notification">Ön siparişe açık</p>'; ?>
          <div class="cart-row__controls">
            <div class="qty-stepper">
              <button type="button" class="qty-stepper__btn" data-step="-1" aria-label="Azalt"><?php echo kinetik_icon('minus', 16); ?></button>
              <?php
              if ($_product->is_sold_individually()) {
                  echo '<input type="hidden" name="cart[' . esc_attr($cart_item_key) . '][qty]" value="1" class="qty"><span class="qty-stepper__n">1</span>';
              } else {
                  echo woocommerce_quantity_input([
                      'input_name'   => "cart[{$cart_item_key}][qty]",
                      'input_value'  => $cart_item['quantity'],
                      'max_value'    => $_product->get_max_purchase_quantity(),
                      'min_value'    => '0',
                      'product_name' => $name,
                  ], $_product, false);
              }
              ?>
              <button type="button" class="qty-stepper__btn" data-step="1" aria-label="Artır"><?php echo kinetik_icon('plus', 16); ?></button>
            </div>
            <?php echo apply_filters('woocommerce_cart_item_remove_link', sprintf(
                '<a href="%s" class="remove cart-row__remove" aria-label="%s" data-product_id="%s" data-product_sku="%s" data-cart_item_key="%s">Kaldır</a>',
                esc_url(wc_get_cart_remove_url($cart_item_key)),
                esc_attr(sprintf('%s ürününü sepetten kaldır', wp_strip_all_tags($name))),
                esc_attr($product_id),
                esc_attr($_product->get_sku()),
                esc_attr($cart_item_key)
            ), $cart_item_key); // phpcs:ignore ?>
          </div>
        </div>
        <div class="cart-row__total"><?php echo apply_filters('woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal($_product, $cart_item['quantity']), $cart_item, $cart_item_key); // phpcs:ignore ?></div>
      </li>
    <?php endforeach; ?>
    <?php do_action('woocommerce_cart_contents'); ?>
  </ul>

  <div class="cart-actions">
    <button type="submit" class="btn btn--outline btn--sm cart-actions__update" name="update_cart" value="Sepeti güncelle">Sepeti güncelle</button>
    <?php do_action('woocommerce_cart_actions'); ?>
    <?php wp_nonce_field('woocommerce-cart', 'woocommerce-cart-nonce'); ?>
  </div>
  <?php do_action('woocommerce_after_cart_contents'); ?>
  <?php do_action('woocommerce_after_cart_table'); ?>
</form>

<?php do_action('woocommerce_before_cart_collaterals'); ?>
<div class="cart-collaterals">
  <?php do_action('woocommerce_cart_collaterals'); ?>
</div>
</div>

<?php do_action('woocommerce_after_cart'); ?>
