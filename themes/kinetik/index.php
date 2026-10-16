<?php
/** Yedek şablon (blog kullanılmıyor). */
if (!defined('ABSPATH')) exit;
get_header(); ?>
<div class="page-wrap wrap page--prose">
  <?php if (have_posts()): while (have_posts()): the_post(); ?>
    <article <?php post_class('entry-card'); ?>>
      <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
      <?php the_excerpt(); ?>
    </article>
  <?php endwhile; the_posts_pagination(); else: ?>
    <p>İçerik bulunamadı.</p>
  <?php endif; ?>
</div>
<?php get_footer();
