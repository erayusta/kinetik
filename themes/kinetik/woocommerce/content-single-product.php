<?php
/**
 * Ürün sayfası.
 *   Galeri: masaüstünde 2 sütun görsel ızgarası, mobilde kaydırmalı şerit (4:5).
 *   Özet: kategori, başlık, fiyat, kısa açıklama, beden butonları + beden tablosu modalı,
 *   adet, AJAX sepete ekle (main.js), güven satırı, açılır bölümler (açıklama / bakım / kargo-iade).
 *   woocommerce_single_product_summary tetiklenir (çekirdek kopyalar inc/woocommerce.php'de kaldırıldı)
 *   ki eklenti çıktıları (kinetik-growth "Haber ver" vb.) görünsün.
 *
 * @version 3.6.0
 */
defined('ABSPATH') || exit;

global $product;
if (!$product instanceof WC_Product) $product = wc_get_product(get_the_ID());
if (!$product) return;

do_action('woocommerce_before_single_product');
if (post_password_required()) { echo get_the_password_form(); return; }

$ids = array_values(array_unique(array_filter(array_merge([(int) $product->get_image_id()], $product->get_gallery_image_ids()))));
$cats = wp_get_post_terms($product->get_id(), 'product_cat');
$cat = (!is_wp_error($cats) && $cats) ? $cats[0] : null;
$size_keys = kinetik_size_keys_for_product($product);
$has_size_attr = $product->is_type('variable') && array_key_exists('pa_beden', $product->get_variation_attributes());
$free = kinetik_tl(kinetik_free_shipping_threshold());
$desc = $product->get_description();
?>
<div id="product-<?php the_ID(); ?>" <?php wc_product_class('pdp', $product); ?>>
  <div class="pdp__top">
    <div class="pdp-gallery" data-count="<?php echo count($ids); ?>">
      <div class="pdp-gallery__track" tabindex="0" aria-label="Ürün görselleri">
        <?php if ($ids): foreach ($ids as $i => $id): ?>
          <figure class="pdp-gallery__item">
            <?php echo wp_get_attachment_image($id, 'woocommerce_single', false, [
                'alt'           => $product->get_name() . ($i ? ' — görsel ' . ($i + 1) : ''),
                'loading'       => $i ? 'lazy' : 'eager',
                'fetchpriority' => $i ? 'auto' : 'high',
                'sizes'         => '(max-width: 767px) 100vw, 30vw',
            ]); ?>
          </figure>
        <?php endforeach; else: ?>
          <figure class="pdp-gallery__item"><?php echo wc_placeholder_img('woocommerce_single'); ?></figure>
        <?php endif; ?>
      </div>
      <?php if (count($ids) > 1): ?>
        <div class="pdp-gallery__dots" aria-hidden="true"><?php foreach ($ids as $i => $id) echo '<span' . ($i ? '' : ' class="is-on"') . '></span>'; ?></div>
      <?php endif; ?>
      <?php echo kinetik_product_badge($product); ?>
    </div>

    <div class="pdp-summary">
      <div class="pdp-summary__in">
        <?php if ($cat): ?><a class="pdp-summary__cat" href="<?php echo esc_url(get_term_link($cat)); ?>"><?php echo esc_html($cat->name); ?></a><?php endif; ?>
        <h1 class="pdp-summary__title product_title"><?php the_title(); ?></h1>
        <div class="pdp-summary__price price-block"><?php echo $product->get_price_html(); ?></div>
        <?php if ($short = $product->get_short_description()): ?>
          <div class="pdp-summary__short"><?php echo wp_kses_post(wpautop($short)); ?></div>
        <?php endif; ?>

        <?php if ($has_size_attr): ?>
          <div class="pdp-size-head">
            <span class="pdp-size-head__label">Beden seç</span>
            <?php if ($size_keys): ?>
              <button type="button" class="pdp-size-head__guide" data-dialog="size-dialog"><?php echo kinetik_icon('ruler', 18); ?> Beden tablosu</button>
            <?php endif; ?>
          </div>
          <p class="pdp-size-msg" role="alert" hidden>Önce bedenini seç.</p>
        <?php endif; ?>

        <div class="pdp-buy">
          <?php woocommerce_template_single_add_to_cart(); ?>
        </div>

        <?php do_action('woocommerce_single_product_summary'); ?>

        <ul class="pdp-trust">
          <li><?php echo kinetik_icon('truck', 20); ?><span><b>En geç <?php echo (int) kinetik_dispatch_days(); ?> iş günü içinde kargoda</b> <?php echo esc_html($free); ?> üzeri kargo ücretsiz<?php echo kinetik_flat_shipping_cost() > 0 ? ', altı ' . esc_html(kinetik_tl(kinetik_flat_shipping_cost())) : ''; ?>.</span></li>
          <li><?php echo kinetik_icon('return', 20); ?><span><b><?php echo (int) kinetik_return_days(); ?> gün içinde iade</b> Kullanılmamış ve etiketi sökülmemiş ürünlerde.</span></li>
          <li><?php echo kinetik_icon('shield', 20); ?><span><b>Havale / EFT ile ödeme</b> Banka bilgileri sipariş onayında; kart bilgisi istenmez.</span></li>
        </ul>

        <div class="pdp-acc">
          <?php if ($desc): ?>
            <details open>
              <summary>Ürün açıklaması</summary>
              <div class="pdp-acc__body"><?php echo wp_kses_post(wpautop(do_shortcode($desc))); ?></div>
            </details>
          <?php endif; ?>
          <?php
          $attrs = array_filter($product->get_attributes(), fn($a) => $a->get_visible());
          if ($attrs): ?>
            <details>
              <summary>Özellikler</summary>
              <div class="pdp-acc__body">
                <dl class="pdp-attrs">
                  <?php foreach ($attrs as $a):
                      $vals = $a->is_taxonomy() ? wc_get_product_terms($product->get_id(), $a->get_name(), ['fields' => 'names']) : $a->get_options(); ?>
                    <dt><?php echo esc_html(wc_attribute_label($a->get_name())); ?></dt><dd><?php echo esc_html(implode(', ', $vals)); ?></dd>
                  <?php endforeach; ?>
                </dl>
              </div>
            </details>
          <?php endif; ?>
          <?php // Açıklama kendi bakım bölümünü içeriyorsa genel öneriyi tekrar etme ?>
          <?php if (!preg_match('/<h[2-4][^>]*>\s*Bakım/iu', $desc) || $product->get_meta('_kinetik_care')): ?>
          <details>
            <summary>Bakım</summary>
            <div class="pdp-acc__body"><?php echo wp_kses_post(kinetik_care_text($product)); ?></div>
          </details>
          <?php endif; ?>
          <details>
            <summary>Kargo ve iade</summary>
            <div class="pdp-acc__body">
              <p>Ödemesi onaylanan siparişler en geç <?php echo (int) kinetik_dispatch_days(); ?> iş günü içinde kargoya teslim edilir.<?php if (kinetik_flat_shipping_cost() > 0) echo ' Kargo ücreti ' . esc_html(kinetik_tl(kinetik_flat_shipping_cost())) . ';'; ?> <?php echo esc_html($free); ?> ve üzeri siparişlerde ücretsiz.</p>
              <p>Teslimattan itibaren <?php echo (int) kinetik_return_days(); ?> gün içinde cayma hakkını kullanabilirsin. Ayrıntılar: <a href="<?php echo esc_url(kinetik_page_url('kargo-ve-iade')); ?>">Kargo ve İade</a>.</p>
            </div>
          </details>
        </div>
      </div>
    </div>
  </div>

  <?php do_action('woocommerce_after_single_product_summary'); ?>

  <?php $related = kinetik_related_products($product, 4);
  if ($related): ?>
    <section class="pdp-related" aria-labelledby="related-title">
      <div class="section-head"><h2 class="section-title" id="related-title">Bunlar da hoşuna gidebilir</h2></div>
      <ul class="products product-grid product-grid--4">
        <?php
        $main_product = $product;
        foreach ($related as $rp) {
            $GLOBALS['product'] = $rp;
            $GLOBALS['post'] = get_post($rp->get_id());
            setup_postdata($GLOBALS['post']);
            wc_get_template_part('content', 'product');
        }
        wp_reset_postdata();
        $GLOBALS['product'] = $main_product;
        ?>
      </ul>
    </section>
  <?php endif; ?>
</div>

<?php if ($size_keys): ?>
<dialog class="dialog" id="size-dialog" aria-labelledby="size-dialog-title">
  <div class="dialog__head">
    <h2 class="dialog__title" id="size-dialog-title">Beden tablosu</h2>
    <button type="button" class="icon-btn dialog__close" data-dialog-close aria-label="Kapat"><?php echo kinetik_icon('close'); ?></button>
  </div>
  <div class="dialog__body">
    <p class="dialog__note">Genel ölçü rehberi: değerler vücut ölçüsüdür (cm). İki beden arasında kalırsan rahat kullanım için büyüğünü seç.</p>
    <?php foreach ($size_keys as $k) echo kinetik_size_table_html($k); ?>
    <?php echo kinetik_size_howto_html(); ?>
    <p><a href="<?php echo esc_url(kinetik_page_url('beden-rehberi')); ?>">Tüm beden rehberi</a></p>
  </div>
</dialog>
<?php endif; ?>

<?php do_action('woocommerce_after_single_product'); ?>
