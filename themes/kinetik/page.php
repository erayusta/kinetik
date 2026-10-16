<?php
/**
 * Sayfa şablonu. Sepet / ödeme / hesabım shortcode sayfalarıdır ve burada render edilir
 * (woocommerce.php'den değil); başlıkları buradan gelir. Düz içerik sayfaları okunur genişlikte (prose).
 */
if (!defined('ABSPATH')) exit;
get_header();

$is_cart     = function_exists('is_cart') && is_cart();
$is_received = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received');
$is_checkout = function_exists('is_checkout') && is_checkout() && !$is_received;
$is_account  = function_exists('is_account_page') && is_account_page();
$is_shop_page = $is_cart || $is_checkout || $is_received || $is_account;

while (have_posts()): the_post(); ?>
  <article <?php post_class('page-wrap wrap' . ($is_shop_page ? ' page--app' : ' page--prose')); ?>>
    <?php if ($is_cart):
        $n = kinetik_cart_count(); ?>
      <header class="page-head page-head--app">
        <h1 class="page-head__title">Sepetin</h1>
        <?php if ($n): ?><span class="page-head__meta"><?php echo (int) $n; ?> ürün</span><?php endif; ?>
      </header>
    <?php elseif ($is_checkout): ?>
      <header class="page-head page-head--app">
        <h1 class="page-head__title">Ödeme</h1>
        <ol class="steps" aria-label="Sipariş adımları">
          <li class="is-done"><a href="<?php echo esc_url(wc_get_cart_url()); ?>">Sepet</a></li>
          <li class="is-current" aria-current="step">Bilgiler ve ödeme</li>
          <li>Onay</li>
        </ol>
      </header>
    <?php elseif ($is_received): ?>
      <?php /* teşekkür şablonu kendi başlığını basar */ ?>
    <?php elseif ($is_account): ?>
      <header class="page-head page-head--app"><h1 class="page-head__title"><?php echo is_user_logged_in() ? 'Hesabım' : 'Giriş yap'; ?></h1></header>
    <?php else: ?>
      <header class="page-head">
        <h1 class="page-head__title"><?php the_title(); ?></h1>
      </header>
    <?php endif; ?>
    <div class="entry">
      <?php the_content(); ?>
    </div>
  </article>
<?php endwhile;
get_footer();
