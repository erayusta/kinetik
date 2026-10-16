<?php
/**
 * Ödeme — yapısal 2 sütun (masaüstü) / alt alta (mobil).
 * #customer_details ve #order_review kimlikleri ile tüm kancalar korunur: kinetik-tr-adres
 * (İl/İlçe/Mahalle) ve WooCommerce ödeme JS'i bunlara bağlı.
 *
 * @version 9.4.0
 */
if (!defined('ABSPATH')) exit;

do_action('woocommerce_before_checkout_form', $checkout);

if (!$checkout->is_registration_enabled() && $checkout->is_registration_required() && !is_user_logged_in()) {
    echo esc_html(apply_filters('woocommerce_checkout_must_be_logged_in_message', __('You must be logged in to checkout.', 'woocommerce')));
    return;
}
?>
<form name="checkout" method="post" class="checkout woocommerce-checkout" action="<?php echo esc_url(wc_get_checkout_url()); ?>" enctype="multipart/form-data" aria-label="Ödeme">
  <div class="checkout-cols">
    <div class="checkout-main">
      <?php if ($checkout->get_checkout_fields()): ?>
        <?php do_action('woocommerce_checkout_before_customer_details'); ?>
        <div id="customer_details">
          <?php do_action('woocommerce_checkout_billing'); ?>
          <?php do_action('woocommerce_checkout_shipping'); ?>
        </div>
        <?php do_action('woocommerce_checkout_after_customer_details'); ?>
      <?php endif; ?>
    </div>
    <div class="checkout-aside">
      <div class="summary-card">
        <?php do_action('woocommerce_checkout_before_order_review_heading'); ?>
        <h2 class="summary-card__title" id="order_review_heading">Siparişin</h2>
        <?php do_action('woocommerce_checkout_before_order_review'); ?>
        <div id="order_review" class="woocommerce-checkout-review-order">
          <?php do_action('woocommerce_checkout_order_review'); ?>
        </div>
        <?php do_action('woocommerce_checkout_after_order_review'); ?>
      </div>
    </div>
  </div>
</form>
<?php do_action('woocommerce_after_checkout_form', $checkout); ?>
