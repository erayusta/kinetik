<?php
/** Beden rehberi — tablolar inc/size-guide.php'den (PDP modalı ile aynı kaynak). */
if (!defined('ABSPATH')) exit;
get_header(); ?>
<article class="page-wrap wrap page--prose page--sizes">
  <header class="page-head">
    <h1 class="page-head__title"><?php the_title(); ?></h1>
    <p class="page-head__lead">Genel ölçü rehberi. Tablolardaki değerler vücut ölçüsüdür (cm); ürünün kendi ölçüsü değildir. İki beden arasında kalırsan rahat kullanım için büyüğünü seç.</p>
  </header>
  <div class="entry">
    <?php while (have_posts()): the_post(); the_content(); endwhile; ?>
    <h2>Nasıl ölçülür?</h2>
    <?php echo kinetik_size_howto_html(); ?>
    <?php foreach (array_keys(kinetik_size_tables()) as $k) echo kinetik_size_table_html($k); ?>
    <p>Bir ürünün kalıbıyla ilgili sorun mu var? <a href="<?php echo esc_url(kinetik_page_url('iletisim')); ?>">Bize yaz</a>, ölçüleri birlikte netleştirelim.</p>
  </div>
</article>
<?php get_footer();
