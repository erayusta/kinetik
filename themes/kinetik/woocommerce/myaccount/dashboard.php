<?php
/**
 * Hesabım — pano: kısa karşılama + kısayol kartları.
 *
 * @version 4.4.0
 */
defined('ABSPATH') || exit;
$user = wp_get_current_user();
$name = $user->first_name ?: $user->display_name;
$last = wc_get_orders(['customer_id' => get_current_user_id(), 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC']);
?>
<div class="acc-dash">
  <p class="acc-dash__hello">Merhaba <?php echo esc_html($name); ?>.</p>
  <?php if ($last): $o = $last[0]; ?>
    <a class="acc-last" href="<?php echo esc_url($o->get_view_order_url()); ?>">
      <span class="acc-last__label">Son siparişin</span>
      <b>#<?php echo esc_html($o->get_order_number()); ?></b>
      <span><?php echo esc_html(wc_get_order_status_name($o->get_status())); ?> · <?php echo wp_kses_post($o->get_formatted_order_total()); ?></span>
    </a>
  <?php endif; ?>
  <div class="acc-cards">
    <a class="acc-card" href="<?php echo esc_url(wc_get_endpoint_url('orders')); ?>"><?php echo kinetik_icon('bag', 24); ?><b>Siparişlerim</b><span>Durum ve sipariş detayları</span></a>
    <a class="acc-card" href="<?php echo esc_url(wc_get_endpoint_url('edit-address')); ?>"><?php echo kinetik_icon('pin', 24); ?><b>Adreslerim</b><span>Fatura ve teslimat adresi</span></a>
    <a class="acc-card" href="<?php echo esc_url(wc_get_endpoint_url('edit-account')); ?>"><?php echo kinetik_icon('user', 24); ?><b>Hesap bilgilerim</b><span>Ad, e-posta, şifre</span></a>
    <a class="acc-card" href="<?php echo esc_url(kinetik_page_url('kargo-ve-iade')); ?>"><?php echo kinetik_icon('return', 24); ?><b>İade ve değişim</b><span>14 gün içinde iade koşulları</span></a>
  </div>
</div>
<?php
do_action('woocommerce_account_dashboard');
