<?php
/**
 * Sepet özeti: ara toplam, kupon(lar), kargo (WooCommerce'in kendi kargo HTML'i), toplam,
 * kupon formu, ödemeye geç. Çekirdek fonksiyonlar ve kancalar korunur.
 *
 * @version 2.3.6
 */
defined('ABSPATH') || exit;
?>
<div class="cart_totals summary-card <?php echo WC()->customer->has_calculated_shipping() ? 'calculated_shipping' : ''; ?>">
  <?php do_action('woocommerce_before_cart_totals'); ?>
  <h2 class="summary-card__title">Sipariş özeti</h2>

  <table class="summary-table" role="presentation">
    <tr class="cart-subtotal"><th>Ara toplam</th><td data-title="Ara toplam"><?php wc_cart_totals_subtotal_html(); ?></td></tr>

    <?php foreach (WC()->cart->get_coupons() as $code => $coupon): ?>
      <tr class="cart-discount coupon-<?php echo esc_attr(sanitize_title($code)); ?>">
        <th>Kupon: <?php echo esc_html(wc_format_coupon_code($code)); ?></th>
        <td data-title="Kupon"><?php wc_cart_totals_coupon_html($coupon); ?></td>
      </tr>
    <?php endforeach; ?>

    <?php if (WC()->cart->needs_shipping() && WC()->cart->show_shipping()): ?>
      <?php do_action('woocommerce_cart_totals_before_shipping'); ?>
      <?php wc_cart_totals_shipping_html(); ?>
      <?php do_action('woocommerce_cart_totals_after_shipping'); ?>
    <?php elseif (WC()->cart->needs_shipping() && 'yes' === get_option('woocommerce_enable_shipping_calc')): ?>
      <tr class="shipping"><th>Kargo</th><td data-title="Kargo"><?php woocommerce_shipping_calculator(); ?></td></tr>
    <?php endif; ?>

    <?php foreach (WC()->cart->get_fees() as $fee): ?>
      <tr class="fee"><th><?php echo esc_html($fee->name); ?></th><td data-title="<?php echo esc_attr($fee->name); ?>"><?php wc_cart_totals_fee_html($fee); ?></td></tr>
    <?php endforeach; ?>

    <?php if (wc_tax_enabled() && !WC()->cart->display_prices_including_tax()):
        foreach (WC()->cart->get_tax_totals() as $code => $tax): ?>
          <tr class="tax-rate"><th><?php echo esc_html($tax->label); ?></th><td><?php echo wp_kses_post($tax->formatted_amount); ?></td></tr>
    <?php endforeach; endif; ?>

    <?php do_action('woocommerce_cart_totals_before_order_total'); ?>
    <tr class="order-total"><th>Toplam</th><td data-title="Toplam"><?php wc_cart_totals_order_total_html(); ?></td></tr>
    <?php do_action('woocommerce_cart_totals_after_order_total'); ?>
  </table>

  <?php if (wc_coupons_enabled()): ?>
    <details class="coupon-box">
      <summary>İndirim kodun var mı?</summary>
      <form class="coupon-box__form" action="<?php echo esc_url(wc_get_cart_url()); ?>" method="post">
        <label class="screen-reader-text" for="coupon_code">Kupon kodu</label>
        <input type="text" name="coupon_code" class="input-text" id="coupon_code" value="" placeholder="Kupon kodu" autocomplete="off">
        <button type="submit" class="btn btn--dark btn--sm" name="apply_coupon" value="Uygula">Uygula</button>
        <?php wp_nonce_field('woocommerce-cart', 'woocommerce-cart-nonce'); ?>
      </form>
    </details>
  <?php endif; ?>

  <div class="wc-proceed-to-checkout"><?php do_action('woocommerce_proceed_to_checkout'); ?></div>
  <p class="summary-card__pay"><?php echo kinetik_icon('bank', 18); ?> Ödeme Havale / EFT ile; banka bilgileri sipariş onayında.</p>
  <?php do_action('woocommerce_after_cart_totals'); ?>
</div>
