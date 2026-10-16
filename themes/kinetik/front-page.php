<?php
/**
 * Anasayfa. Tüm içerik canlı veritabanından: kategoriler ve sayıları, stoktaki yeni ürünler,
 * gerçek kargo eşiği ve yalnızca var olan hoş geldin kuponu (kinetik_welcome_offer). Uydurma yorum/istatistik yok.
 */
if (!defined('ABSPATH')) exit;
get_header();

$free     = kinetik_tl(kinetik_free_shipping_threshold());
$shop_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/magaza/');
$tagline  = get_bloginfo('description') ?: 'Hareket et. İz bırak.';

// Kategoriler (setup-store.php sırası); görsel yoksa editoryal yedek
$cat_order = ['tisort' => 'look-1', 'esofman' => 'look-3', 'sweatshirt-hoodie' => 'hero-mobile', 'sort-tayt' => 'look-2', 'aksesuar' => 'band'];
$cats = [];
foreach ($cat_order as $slug => $fallback) {
    $t = get_term_by('slug', $slug, 'product_cat');
    if ($t) $cats[] = [$t, $fallback];
}
$new = function_exists('kinetik_in_stock_products') ? kinetik_new_arrivals(8) : [];
$offer    = kinetik_welcome_offer();
?>

<section class="hero" aria-labelledby="hero-title">
  <picture class="hero__media">
    <source media="(max-width: 767px)" srcset="<?php echo esc_url(kinetik_asset('assets/img/hero-mobile.webp')); ?>">
    <img src="<?php echo esc_url(kinetik_asset('assets/img/hero.webp')); ?>" alt="" width="2400" height="1602" fetchpriority="high">
  </picture>
  <div class="hero__content wrap">
    <h1 class="hero__title" id="hero-title"><?php echo esc_html($tagline); ?></h1>
    <p class="hero__lead">Antrenmandan sokağa aynı rahatlık: tişört, eşofman, hoodie, şort ve tayt.</p>
    <div class="hero__actions">
      <a class="btn btn--primary" href="<?php echo esc_url(add_query_arg('orderby', 'date', $shop_url)); ?>">Yeni gelenleri gör</a>
      <a class="btn btn--ghost-light" href="#kategoriler">Kategoriler</a>
    </div>
  </div>
</section>

<?php if ($cats): ?>
<section class="home-cats wrap" id="kategoriler" aria-labelledby="cats-title">
  <div class="section-head">
    <h2 class="section-title" id="cats-title">Kategoriler</h2>
    <a class="link-arrow" href="<?php echo esc_url($shop_url); ?>">Tüm ürünler <?php echo kinetik_icon('arrow', 18); ?></a>
  </div>
  <div class="cat-tiles">
    <?php foreach ($cats as $i => [$term, $fallback]):
        $img_id = function_exists('kinetik_category_image_id') ? kinetik_category_image_id($term) : 0; ?>
      <a class="cat-tile cat-tile--<?php echo (int) $i + 1; ?>" href="<?php echo esc_url(get_term_link($term)); ?>">
        <span class="cat-tile__img">
          <?php if ($img_id): echo wp_get_attachment_image($img_id, $i === 0 ? 'woocommerce_single' : 'woocommerce_thumbnail', false, ['loading' => 'lazy', 'alt' => '']);
                else: ?><img src="<?php echo esc_url(kinetik_asset("assets/img/{$fallback}.webp")); ?>" alt="" loading="lazy"><?php endif; ?>
        </span>
        <span class="cat-tile__body">
          <span class="cat-tile__name"><?php echo esc_html($term->name); ?></span>
          <span class="cat-tile__count"><?php echo $term->count ? (int) $term->count . ' ürün' : 'Yakında'; ?></span>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($new): ?>
<section class="home-new" aria-labelledby="new-title">
  <div class="wrap">
    <div class="section-head">
      <h2 class="section-title" id="new-title">Yeni gelenler</h2>
      <a class="link-arrow" href="<?php echo esc_url(add_query_arg('orderby', 'date', $shop_url)); ?>">Hepsini gör <?php echo kinetik_icon('arrow', 18); ?></a>
    </div>
    <ul class="products product-grid product-grid--4">
      <?php foreach ($new as $p) {
          $GLOBALS['product'] = $p;
          $GLOBALS['post'] = get_post($p->get_id());
          setup_postdata($GLOBALS['post']);
          wc_get_template_part('content', 'product');
      }
      wp_reset_postdata(); ?>
    </ul>
  </div>
</section>
<?php endif; ?>

<section class="band" aria-labelledby="band-title">
  <div class="band__img"><img src="<?php echo esc_url(kinetik_asset('assets/img/band.webp')); ?>" alt="" loading="lazy"></div>
  <div class="band__body">
    <?php if ($offer && function_exists('kinetik_newsletter_form')): ?>
      <h2 class="band__title" id="band-title">İlk siparişine %<?php echo esc_html(wc_format_localized_decimal($offer['pct'])); ?> indirim</h2>
      <p class="band__text">Bültene kaydol, <strong><?php echo esc_html($offer['code']); ?></strong> kodu ekranda görünsün. <?php echo $offer['min'] > 0 ? esc_html(kinetik_tl($offer['min'])) . ' ve üzeri ilk' : 'İlk'; ?> siparişinde ödeme adımında kullan.</p>
      <?php echo kinetik_newsletter_form(['title' => '', 'text' => '', 'button' => 'Kodu al']); ?>
    <?php else: ?>
      <h2 class="band__title" id="band-title"><?php echo esc_html($free); ?> üzeri kargo bizden</h2>
      <p class="band__text">Sepetin <?php echo esc_html($free); ?> ve üzerine çıktığında kargo ücretsiz.</p>
      <a class="btn btn--dark" href="<?php echo esc_url($shop_url); ?>">Alışverişe başla</a>
    <?php endif; ?>
  </div>
</section>

<section class="look wrap" aria-labelledby="look-title">
  <div class="section-head">
    <h2 class="section-title" id="look-title">Sokakta, salonda, pistte</h2>
  </div>
  <div class="look__strip">
    <?php
    $looks = [
        ['look-1', 'tisort', 'Sade tişörtler', 'Her güne, her kombine.'],
        ['look-2', 'sort-tayt', 'Esneyen taytlar', 'Isınmadan soğumaya kadar.'],
        ['look-3', 'sweatshirt-hoodie', 'Kalın hoodie\'ler', 'Serin havalara hazır.'],
    ];
    foreach ($looks as [$img, $slug, $title, $text]):
        $t = get_term_by('slug', $slug, 'product_cat');
        $url = $t ? get_term_link($t) : $shop_url; ?>
      <a class="look__item" href="<?php echo esc_url($url); ?>">
        <img src="<?php echo esc_url(kinetik_asset("assets/img/{$img}.webp")); ?>" alt="" loading="lazy">
        <span class="look__cap"><b><?php echo esc_html($title); ?></b><span><?php echo esc_html($text); ?></span></span>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<section class="trust" aria-label="Alışveriş koşulları">
  <ul class="trust__list wrap">
    <li><?php echo kinetik_icon('truck', 28); ?><b>En geç <?php echo (int) kinetik_dispatch_days(); ?> iş günü</b><span>Ödemesi onaylanan sipariş kargoda</span></li>
    <li><?php echo kinetik_icon('return', 28); ?><b><?php echo (int) kinetik_return_days(); ?> gün iade</b><span>Kullanılmamış, etiketli ürünlerde</span></li>
    <li><?php echo kinetik_icon('shield', 28); ?><b>Güvenli alışveriş</b><span>Havale / EFT, kart bilgisi istenmez</span></li>
    <li><?php echo kinetik_icon('bolt', 28); ?><b><?php echo esc_html($free); ?> üzeri</b><span>Kargo ücretsiz</span></li>
  </ul>
</section>

<?php get_footer();
