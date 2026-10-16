<?php
/**
 * WooCommerce sarmalayıcısı. Tema woocommerce.php içerdiğinde WooCommerce archive-product.php'yi
 * yok sayar — mağaza/kategori düzeni (filtre + ızgara) burada. Tekil ürün content-single-product.php
 * şablon parçasıyla çizilir. Sepet/ödeme/hesabım ise sayfa (page.php).
 */
if (!defined('ABSPATH')) exit;
get_header();

if (is_shop() || is_product_taxonomy()) :
    $term  = is_product_taxonomy() ? get_queried_object() : null;
    $title = $term ? $term->name : (is_search() ? 'Arama: “' . get_search_query() . '”' : 'Tüm ürünler');
    $desc  = $term && $term->description ? $term->description : '';
    $base  = kinetik_archive_base_url();
    $found = (int) $GLOBALS['wp_query']->found_posts;

    $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'parent' => 0, 'exclude' => [(int) get_option('default_product_cat')], 'orderby' => 'term_order']);
    $beden = taxonomy_exists('pa_beden') ? get_terms(['taxonomy' => 'pa_beden', 'hide_empty' => true, 'orderby' => 'menu_order']) : [];
    $cins  = taxonomy_exists('pa_cinsiyet') ? get_terms(['taxonomy' => 'pa_cinsiyet', 'hide_empty' => true, 'orderby' => 'menu_order']) : [];
    $chosen_b = kinetik_chosen_filter('beden');
    $chosen_c = kinetik_chosen_filter('cinsiyet');
    [$pmin, $pmax] = kinetik_price_bounds();
    $active = kinetik_active_filter_count();
    ?>
    <div class="shop wrap">
      <?php woocommerce_breadcrumb(); ?>
      <header class="shop-head">
        <h1 class="shop-head__title"><?php echo esc_html($title); ?></h1>
        <?php if ($desc): ?><p class="shop-head__desc"><?php echo esc_html(wp_strip_all_tags($desc)); ?></p><?php endif; ?>
      </header>

      <div class="shop-toolbar">
        <button type="button" class="btn btn--outline btn--sm filter-toggle" aria-expanded="false" aria-controls="shop-filters">
          <?php echo kinetik_icon('filter', 18); ?> Filtrele<?php echo $active ? ' <span class="filter-toggle__n">' . (int) $active . '</span>' : ''; ?>
        </button>
        <span class="shop-toolbar__count"><?php echo (int) $found; ?> ürün</span>
        <div class="shop-toolbar__sort"><?php woocommerce_catalog_ordering(); ?></div>
      </div>

      <div class="shop-layout">
        <div class="filters-backdrop" hidden></div>
        <aside class="filters" id="shop-filters" aria-label="Filtreler">
          <div class="filters__head">
            <span class="filters__title">Filtrele</span>
            <button type="button" class="icon-btn filters__close" aria-label="Filtreleri kapat"><?php echo kinetik_icon('close'); ?></button>
          </div>

          <?php if ($cats && !is_wp_error($cats)): ?>
          <div class="filters__group">
            <h2 class="filters__label">Kategori</h2>
            <ul class="filters__cats">
              <li><a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"<?php echo !$term ? ' aria-current="page"' : ''; ?>>Tümü</a></li>
              <?php foreach ($cats as $c): ?>
                <li><a href="<?php echo esc_url(get_term_link($c)); ?>"<?php echo ($term && $term->term_id === $c->term_id) ? ' aria-current="page"' : ''; ?>><?php echo esc_html($c->name); ?> <span><?php echo (int) $c->count; ?></span></a></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <?php endif; ?>

          <form class="filters__form" method="get" action="<?php echo esc_url($base); ?>">
            <?php if (!empty($_GET['orderby'])): ?><input type="hidden" name="orderby" value="<?php echo esc_attr(sanitize_text_field(wp_unslash($_GET['orderby']))); ?>"><?php endif; ?>
            <?php if (is_search()): ?><input type="hidden" name="s" value="<?php echo esc_attr(get_search_query()); ?>"><input type="hidden" name="post_type" value="product"><?php endif; ?>

            <?php if ($beden && !is_wp_error($beden)): ?>
            <fieldset class="filters__group">
              <legend class="filters__label">Beden</legend>
              <div class="filters__sizes">
                <?php foreach ($beden as $b): ?>
                  <label class="chip"><input type="checkbox" name="filter_beden[]" value="<?php echo esc_attr($b->slug); ?>"<?php checked(in_array($b->slug, $chosen_b, true)); ?>><span><?php echo esc_html($b->name); ?></span></label>
                <?php endforeach; ?>
              </div>
              <input type="hidden" name="query_type_beden" value="or">
            </fieldset>
            <?php endif; ?>

            <?php if ($cins && !is_wp_error($cins)): ?>
            <fieldset class="filters__group">
              <legend class="filters__label">Cinsiyet</legend>
              <?php foreach ($cins as $c): ?>
                <label class="check"><input type="checkbox" name="filter_cinsiyet[]" value="<?php echo esc_attr($c->slug); ?>"<?php checked(in_array($c->slug, $chosen_c, true)); ?>><span><?php echo esc_html($c->name); ?></span></label>
              <?php endforeach; ?>
              <input type="hidden" name="query_type_cinsiyet" value="or">
            </fieldset>
            <?php endif; ?>

            <?php if ($pmax > 0): ?>
            <fieldset class="filters__group">
              <legend class="filters__label">Fiyat (TL)</legend>
              <div class="filters__price">
                <label><span class="screen-reader-text">En az</span><input type="number" name="min_price" inputmode="numeric" min="0" step="50" placeholder="<?php echo (int) $pmin; ?>" value="<?php echo isset($_GET['min_price']) ? (int) $_GET['min_price'] : ''; ?>"></label>
                <span aria-hidden="true">–</span>
                <label><span class="screen-reader-text">En çok</span><input type="number" name="max_price" inputmode="numeric" min="0" step="50" placeholder="<?php echo (int) $pmax; ?>" value="<?php echo isset($_GET['max_price']) ? (int) $_GET['max_price'] : ''; ?>"></label>
              </div>
            </fieldset>
            <?php endif; ?>

            <div class="filters__actions">
              <button type="submit" class="btn btn--dark btn--block">Sonuçları göster</button>
              <?php if ($active): ?><a class="filters__clear" href="<?php echo esc_url($base); ?>">Filtreleri temizle</a><?php endif; ?>
            </div>
          </form>
        </aside>

        <div class="shop-main">
          <?php
          if (woocommerce_product_loop()) {
              do_action('woocommerce_before_shop_loop');
              echo '<ul class="products product-grid product-grid--3">';
              while (have_posts()) { the_post(); wc_get_template_part('content', 'product'); }
              echo '</ul>';
              echo '<div class="shop-pagination">';
              woocommerce_pagination();
              echo '</div>';
              do_action('woocommerce_after_shop_loop');
          } else {
              echo '<div class="empty-state"><p class="empty-state__title">Bu seçimle eşleşen ürün yok.</p><p>Filtreleri gevşetmeyi ya da tüm ürünlere bakmayı dene.</p><a class="btn btn--dark" href="' . esc_url(wc_get_page_permalink('shop')) . '">Tüm ürünler</a></div>';
          }
          ?>
        </div>
      </div>
    </div>
<?php else : ?>
    <div class="wrap wc-single">
      <?php woocommerce_breadcrumb(); ?>
      <?php woocommerce_content(); ?>
    </div>
<?php endif;

get_footer();
