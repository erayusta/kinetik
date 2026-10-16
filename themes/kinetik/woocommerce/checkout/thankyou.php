<?php
/**
 * Sipariş onayı. Havale/EFT bilgileri WooCommerce'ten gelir:
 * woocommerce_thankyou_bacs → WC_Gateway_BACS::thankyou_page() (talimat + banka hesapları).
 *
 * @version 8.1.0
 * @var WC_Order|false $order
 */
defined('ABSPATH') || exit;
?>
<div class="woocommerce-order thanks">
<?php if ($order):
    do_action('woocommerce_before_thankyou', $order->get_id());
    if ($order->has_status('failed')): ?>
      <div class="thanks__head">
        <h1 class="thanks__title">Ödeme tamamlanamadı</h1>
        <p>Bankan ya da ödeme sağlayıcısı işlemi reddetti. Tekrar deneyebilirsin.</p>
        <p><a href="<?php echo esc_url($order->get_checkout_payment_url()); ?>" class="btn btn--primary">Tekrar öde</a></p>
      </div>
    <?php else: ?>
      <div class="thanks__head">
        <span class="thanks__check" aria-hidden="true"><?php echo kinetik_icon('check', 30); ?></span>
        <h1 class="thanks__title">Siparişin alındı<?php echo $order->get_billing_first_name() ? ', ' . esc_html($order->get_billing_first_name()) : ''; ?></h1>
        <p class="thanks__lead">
          <?php if ($order->get_payment_method() === 'bacs'): ?>
            Siparişini hazırlamaya ödemen hesabımıza ulaştığında başlıyoruz. Havale / EFT açıklamasına sipariş numaranı (<strong>#<?php echo esc_html($order->get_order_number()); ?></strong>) yazmayı unutma.
          <?php else: ?>
            Teşekkürler! Siparişini hazırlamaya başlıyoruz.
          <?php endif; ?>
          <?php if ($order->get_billing_email()): ?> Onay e-postası <strong><?php echo esc_html($order->get_billing_email()); ?></strong> adresine gönderildi.<?php endif; ?>
        </p>
      </div>
      <ul class="thanks__meta woocommerce-order-overview">
        <li class="woocommerce-order-overview__order"><span>Sipariş no</span><b>#<?php echo esc_html($order->get_order_number()); ?></b></li>
        <li class="woocommerce-order-overview__date"><span>Tarih</span><b><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></b></li>
        <li class="woocommerce-order-overview__total"><span>Toplam</span><b><?php echo wp_kses_post($order->get_formatted_order_total()); ?></b></li>
        <?php if ($order->get_payment_method_title()): ?><li class="woocommerce-order-overview__payment-method"><span>Ödeme</span><b><?php echo esc_html($order->get_payment_method_title()); ?></b></li><?php endif; ?>
      </ul>
    <?php endif; ?>
    <div class="thanks__details">
      <?php do_action('woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id()); ?>
      <?php do_action('woocommerce_thankyou', $order->get_id()); ?>
    </div>
<?php else: ?>
    <div class="thanks__head"><h1 class="thanks__title">Siparişin alındı</h1><p>Teşekkürler.</p></div>
<?php endif; ?>
</div>
