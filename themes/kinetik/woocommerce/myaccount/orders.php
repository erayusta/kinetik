<?php
/**
 * Hesabım — siparişler: kart listesi (tablo mobilde okunmuyordu).
 * Kancalar ve sayfalama korunur.
 *
 * @version 9.5.0
 */
defined('ABSPATH') || exit;
do_action('woocommerce_before_account_orders', $has_orders);

if ($has_orders): ?>
  <ul class="order-cards">
    <?php foreach ($customer_orders->orders as $customer_order):
        $order = wc_get_order($customer_order);
        if (!$order) continue;
        $count = $order->get_item_count(); ?>
      <li class="order-card">
        <div class="order-card__top">
          <b>#<?php echo esc_html($order->get_order_number()); ?></b>
          <span class="status status--<?php echo esc_attr($order->get_status()); ?>"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></span>
        </div>
        <div class="order-card__meta">
          <span><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></span>
          <span><?php echo (int) $count; ?> ürün</span>
          <span><?php echo wp_kses_post($order->get_formatted_order_total()); ?></span>
        </div>
        <div class="order-card__actions">
          <?php foreach (wc_get_account_orders_actions($order) as $key => $action): ?>
            <a href="<?php echo esc_url($action['url']); ?>" class="btn btn--sm <?php echo $key === 'view' ? 'btn--dark' : 'btn--outline'; ?> <?php echo esc_attr($key); ?>"><?php echo esc_html($action['name']); ?></a>
          <?php endforeach; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php do_action('woocommerce_before_account_orders_pagination'); ?>
  <?php if (1 < $customer_orders->max_num_pages): ?>
    <div class="woocommerce-pagination acc-pagination">
      <?php if (1 !== $current_page): ?><a class="btn btn--outline btn--sm" href="<?php echo esc_url(wc_get_endpoint_url('orders', $current_page - 1)); ?>">Önceki</a><?php endif; ?>
      <?php if (intval($customer_orders->max_num_pages) !== $current_page): ?><a class="btn btn--outline btn--sm" href="<?php echo esc_url(wc_get_endpoint_url('orders', $current_page + 1)); ?>">Sonraki</a><?php endif; ?>
    </div>
  <?php endif; ?>
<?php else: ?>
  <div class="empty-state"><p class="empty-state__title">Henüz siparişin yok.</p><a class="btn btn--dark" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>">Alışverişe başla</a></div>
<?php endif;

do_action('woocommerce_after_account_orders', $has_orders);
