<?php if (!defined('ABSPATH')) exit; ?>
</main>

<footer class="site-footer">
  <div class="wrap site-footer__grid">
    <div class="site-footer__brand">
      <a class="site-footer__logo" href="<?php echo esc_url(home_url('/')); ?>" aria-label="<?php echo esc_attr(kinetik_brand()); ?> ana sayfa"><?php echo kinetik_logo('wordmark'); ?></a>
      <p class="site-footer__tagline"><?php echo esc_html(get_bloginfo('description')); ?></p>
      <?php if (function_exists('kinetik_newsletter_form')):
          $kinetik_offer = kinetik_welcome_offer();
          $kinetik_news  = 'Yeni ürünler ve kampanyalar e-postana gelsin.';
          if ($kinetik_offer) {
              $kinetik_news .= ' Kayıt olunca ilk siparişine özel %' . wc_format_localized_decimal($kinetik_offer['pct']) . ' indirim kodu ekranda görünür'
                  . ($kinetik_offer['min'] > 0 ? ' (' . kinetik_tl($kinetik_offer['min']) . ' ve üzeri).' : '.');
          } ?>
        <div class="site-footer__news">
          <?php echo kinetik_newsletter_form([
              'title'  => 'Bültene katıl',
              'text'   => $kinetik_news,
              'button' => 'Kaydol',
          ]); ?>
        </div>
      <?php endif; ?>
    </div>

    <nav class="site-footer__col" aria-label="Yardım">
      <h2 class="site-footer__title">Yardım</h2>
      <?php kinetik_menu('footer_help', 'site-footer__menu'); ?>
    </nav>

    <nav class="site-footer__col" aria-label="Kurumsal">
      <h2 class="site-footer__title">Kurumsal</h2>
      <?php kinetik_menu('footer_legal', 'site-footer__menu'); ?>
    </nav>

    <div class="site-footer__col site-footer__seller">
      <h2 class="site-footer__title">Satıcı</h2>
      <address>
        <strong><?php echo kinetik_seller_field('unvan', 'Ticari unvan'); ?></strong>
        <span><?php echo kinetik_seller_field('adres', 'Adres'); ?></span>
        <?php if ($tel = kinetik_seller('telefon')): ?>
          <a href="<?php echo esc_attr(kinetik_tel_href($tel)); ?>"><?php echo esc_html($tel); ?></a>
        <?php endif; ?>
        <?php if ($mail = kinetik_seller('eposta')): ?>
          <a href="mailto:<?php echo esc_attr($mail); ?>"><?php echo esc_html($mail); ?></a>
        <?php endif; ?>
      </address>
      <div class="site-footer__pay">
        <span class="site-footer__pay-title">Ödeme</span>
        <span class="pay-chip"><?php echo kinetik_icon('bank', 18); ?> Havale / EFT</span>
      </div>
    </div>
  </div>

  <div class="wrap site-footer__bottom">
    <span>&copy; <?php echo esc_html(wp_date('Y')); ?> <?php echo esc_html(kinetik_seller('unvan') ?: kinetik_brand()); ?></span>
    <a href="<?php echo esc_url(kinetik_page_url('mesafeli-satis-sozlesmesi')); ?>">Mesafeli Satış Sözleşmesi</a>
    <?php if ($kinetik_credit = kinetik_footer_credit()): ?>
      <span class="site-footer__credit"><?php echo wp_kses($kinetik_credit, ['a' => ['href' => [], 'target' => [], 'rel' => [], 'class' => []]]); ?></span>
    <?php endif; ?>
  </div>
</footer>

<div class="cookie-bar" id="cookie-bar" hidden role="region" aria-label="Çerez bildirimi">
  <p>Sepetin ve oturumun için zorunlu çerezler kullanıyoruz. Ayrıntılar <a href="<?php echo esc_url(kinetik_page_url('cerez-politikasi')); ?>">Çerez Politikası</a>'nda.</p>
  <button type="button" class="btn btn--light btn--sm" data-cookie-ok>Anladım</button>
</div>

<?php wp_footer(); ?>
</body>
</html>
