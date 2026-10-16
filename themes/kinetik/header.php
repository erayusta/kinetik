<?php if (!defined('ABSPATH')) exit;
$kinetik_free  = kinetik_tl(kinetik_free_shipping_threshold());
$kinetik_cart  = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/sepet/');
$kinetik_acct  = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/hesabim/');
$kinetik_count = kinetik_cart_count();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="skip-link" href="#icerik">İçeriğe geç</a>

<div class="announce" role="note">
  <ul class="announce__list">
    <li><?php echo kinetik_icon('truck', 16); ?> <?php echo esc_html($kinetik_free); ?> üzeri ücretsiz kargo</li>
    <li><?php echo kinetik_icon('bolt', 16); ?> En geç <?php echo (int) kinetik_dispatch_days(); ?> iş günü içinde kargoda</li>
    <li><?php echo kinetik_icon('return', 16); ?> <?php echo (int) kinetik_return_days(); ?> gün içinde iade</li>
  </ul>
</div>

<header class="site-header" id="site-header">
  <div class="site-header__in wrap">
    <button type="button" class="icon-btn menu-toggle" aria-expanded="false" aria-controls="drawer" aria-label="Menüyü aç"><?php echo kinetik_icon('menu'); ?></button>

    <a class="brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="<?php echo esc_attr(kinetik_brand()); ?> ana sayfa">
      <?php echo kinetik_logo('wordmark'); ?>
    </a>

    <nav class="main-nav" aria-label="Ana menü">
      <?php kinetik_menu('primary', 'main-nav__list'); ?>
    </nav>

    <div class="tools">
      <button type="button" class="icon-btn search-toggle" aria-expanded="false" aria-controls="search-panel" aria-label="Ara"><?php echo kinetik_icon('search'); ?></button>
      <a class="icon-btn tools__account" href="<?php echo esc_url($kinetik_acct); ?>" aria-label="Hesabım"><?php echo kinetik_icon('user'); ?></a>
      <a class="icon-btn cart-link" href="<?php echo esc_url($kinetik_cart); ?>" aria-label="Sepet">
        <?php echo kinetik_icon('bag'); ?><span class="cart-count" data-count="<?php echo (int) $kinetik_count; ?>"><?php echo (int) $kinetik_count; ?></span>
      </a>
    </div>
  </div>

  <div class="search-panel" id="search-panel" hidden>
    <form class="search-panel__form wrap" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
      <label class="screen-reader-text" for="search-q">Ürün ara</label>
      <?php echo kinetik_icon('search', 24); ?>
      <input id="search-q" type="search" name="s" placeholder="Ne arıyorsun? Hoodie, tayt, eşofman…" autocomplete="off" value="<?php echo esc_attr(get_search_query()); ?>">
      <input type="hidden" name="post_type" value="product">
      <button type="submit" class="btn btn--primary btn--sm">Ara</button>
      <button type="button" class="icon-btn search-panel__close" aria-label="Aramayı kapat"><?php echo kinetik_icon('close'); ?></button>
    </form>
  </div>
</header>

<div class="drawer-backdrop" hidden></div>
<aside class="drawer" id="drawer" aria-label="Mobil menü" aria-hidden="true" inert>
  <div class="drawer__head">
    <a class="drawer__brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="<?php echo esc_attr(kinetik_brand()); ?> ana sayfa"><?php echo kinetik_logo('wordmark'); ?></a>
    <button type="button" class="icon-btn drawer__close" aria-label="Menüyü kapat"><?php echo kinetik_icon('close'); ?></button>
  </div>
  <form class="drawer__search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>">
    <label class="screen-reader-text" for="drawer-q">Ürün ara</label>
    <input id="drawer-q" type="search" name="s" placeholder="Ürün ara" autocomplete="off">
    <input type="hidden" name="post_type" value="product">
    <button type="submit" aria-label="Ara"><?php echo kinetik_icon('search', 20); ?></button>
  </form>
  <nav aria-label="Kategoriler"><?php kinetik_menu('primary', 'drawer__nav'); ?></nav>
  <div class="drawer__links">
    <a href="<?php echo esc_url($kinetik_acct); ?>"><?php echo kinetik_icon('user', 20); ?> Hesabım</a>
    <a href="<?php echo esc_url($kinetik_cart); ?>"><?php echo kinetik_icon('bag', 20); ?> Sepet <span class="cart-count" data-count="<?php echo (int) $kinetik_count; ?>"><?php echo (int) $kinetik_count; ?></span></a>
    <a href="<?php echo esc_url(kinetik_page_url('beden-rehberi')); ?>"><?php echo kinetik_icon('ruler', 20); ?> Beden rehberi</a>
    <a href="<?php echo esc_url(kinetik_page_url('iletisim')); ?>"><?php echo kinetik_icon('phone', 20); ?> İletişim</a>
  </div>
  <p class="drawer__note"><?php echo esc_html($kinetik_free); ?> üzeri ücretsiz kargo. Ödemesi onaylanan siparişler en geç <?php echo (int) kinetik_dispatch_days(); ?> iş gününde kargoda.</p>
</aside>

<main id="icerik" class="site-main">
