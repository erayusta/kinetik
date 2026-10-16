<?php
/**
 * İletişim — satıcı künyesi `kinetik_seller_info` seçeneğinden (tek kaynak). Boş alanlar
 * "eklenecek" olarak işaretlenir (pending-field), gizlenmez. Form yöneticiye e-posta atar (inc/contact.php).
 */
if (!defined('ABSPATH')) exit;
get_header();
$tel = kinetik_seller('telefon');
?>
<article class="page-wrap wrap page--contact">
  <header class="page-head">
    <h1 class="page-head__title"><?php the_title(); ?></h1>
    <p class="page-head__lead">Sipariş, beden ya da iade — sorunu yaz, e-posta ile dönelim. Hafta içi telefonla da ulaşabilirsin.</p>
  </header>
  <div class="contact">
    <section class="contact__info" aria-labelledby="seller-title">
      <h2 class="contact__title" id="seller-title">Satıcı bilgileri</h2>
      <dl class="seller-list">
        <div><dt>Ticari unvan</dt><dd><?php echo kinetik_seller_field('unvan', 'Ticari unvan'); ?></dd></div>
        <div><dt>Marka</dt><dd><?php echo kinetik_seller_field('marka', 'Marka'); ?></dd></div>
        <div><dt>Adres</dt><dd><?php echo kinetik_seller_field('adres', 'Adres'); ?></dd></div>
        <div><dt>Telefon</dt><dd><?php echo $tel ? '<a href="' . esc_attr(kinetik_tel_href($tel)) . '">' . esc_html($tel) . '</a>' : kinetik_seller_field('telefon', 'Telefon'); ?></dd></div>
        <div><dt>E-posta</dt><dd><?php echo ($m = kinetik_seller('eposta')) ? '<a href="mailto:' . esc_attr($m) . '">' . esc_html($m) . '</a>' : kinetik_seller_field('eposta', 'E-posta'); ?></dd></div>
        <div><dt>Vergi dairesi</dt><dd><?php echo kinetik_seller_field('vergi_dairesi', 'Vergi dairesi'); ?></dd></div>
        <div><dt>Vergi no</dt><dd><?php echo kinetik_seller_field('vergi_no', 'Vergi numarası'); ?></dd></div>
        <div><dt>MERSİS no</dt><dd><?php echo kinetik_seller_field('mersis', 'MERSİS numarası'); ?></dd></div>
      </dl>
      <?php while (have_posts()): the_post(); if (trim(get_the_content())): ?><div class="entry"><?php the_content(); ?></div><?php endif; endwhile; ?>
    </section>
    <section class="contact__form" aria-labelledby="form-title">
      <h2 class="contact__title" id="form-title">Bize yaz</h2>
      <?php echo do_shortcode('[kinetik_contact_form]'); ?>
    </section>
  </div>
</article>
<?php get_footer();
