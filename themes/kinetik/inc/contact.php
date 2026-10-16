<?php
/**
 * İletişim formu — [kinetik_contact_form].
 * Mesaj önce `kinetik_message` (yönetimde "İletişim mesajları") olarak kaydedilir, sonra site
 * yöneticisine (admin_email) e-postalanır; Yanıtla adresi gönderenin e-postasıdır.
 * Kayıt önce: e-posta altyapısı bir gün düşerse mesaj kaybolmasın.
 */
if (!defined('ABSPATH')) exit;

class Kinetik_Contact {
    const CPT = 'kinetik_message';

    public static function init() {
        add_action('init', [__CLASS__, 'register']);
        add_shortcode('kinetik_contact_form', [__CLASS__, 'form']);
        add_action('wp_ajax_kinetik_contact', [__CLASS__, 'submit']);
        add_action('wp_ajax_nopriv_kinetik_contact', [__CLASS__, 'submit']);
    }

    public static function register() {
        register_post_type(self::CPT, [
            'labels'       => ['name' => 'İletişim mesajları', 'singular_name' => 'Mesaj'],
            'public'       => false,
            'show_ui'      => true,
            'menu_icon'    => 'dashicons-email-alt',
            'supports'     => ['title', 'editor'],
            'capabilities' => ['create_posts' => 'do_not_allow'],
            'map_meta_cap' => true,
        ]);
    }

    public static function subjects() {
        return [
            'siparis' => 'Siparişim hakkında',
            'beden'   => 'Ürün ve beden seçimi',
            'iade'    => 'İade / değişim',
            'kargo'   => 'Kargo ve teslimat',
            'diger'   => 'Diğer',
        ];
    }

    public static function form() {
        ob_start(); ?>
        <form class="contact-form" id="kinetik-contact-form" novalidate>
          <div class="field-row">
            <label class="field"><span>Ad soyad</span><input type="text" name="name" required autocomplete="name" maxlength="80"></label>
            <label class="field"><span>E-posta</span><input type="email" name="email" required autocomplete="email" maxlength="120"></label>
          </div>
          <div class="field-row">
            <label class="field"><span>Telefon <i>(isteğe bağlı)</i></span><input type="tel" name="phone" autocomplete="tel" maxlength="30"></label>
            <label class="field"><span>Konu</span>
              <select name="subject" required>
                <?php foreach (self::subjects() as $k => $v) printf('<option value="%s">%s</option>', esc_attr($k), esc_html($v)); ?>
              </select>
            </label>
          </div>
          <label class="field"><span>Sipariş numarası <i>(varsa)</i></span><input type="text" name="order" maxlength="20" inputmode="numeric"></label>
          <label class="field"><span>Mesajın</span><textarea name="message" rows="5" required maxlength="3000"></textarea></label>
          <label class="field field--check"><input type="checkbox" name="kvkk" value="1" required>
            <span>Mesajıma yanıt verilebilmesi için bilgilerimin <a href="<?php echo esc_url(kinetik_page_url('kvkk-aydinlatma-metni')); ?>" target="_blank" rel="noopener">KVKK Aydınlatma Metni</a> kapsamında işlenmesini kabul ediyorum.</span></label>
          <p class="hp" aria-hidden="true"><label>Web sitesi<input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
          <?php wp_nonce_field('kinetik_contact', 'kinetik_contact_nonce'); ?>
          <button type="submit" class="btn btn--primary contact-form__submit">Mesajı gönder</button>
          <p class="contact-form__status" role="status" aria-live="polite"></p>
        </form>
        <?php
        return ob_get_clean();
    }

    public static function submit() {
        if (!check_ajax_referer('kinetik_contact', 'kinetik_contact_nonce', false)) {
            wp_send_json_error(['message' => 'Oturum süresi doldu. Sayfayı yenileyip tekrar gönder.'], 400);
        }
        if (!empty($_POST['website'])) wp_send_json_success(['message' => 'Mesajın alındı.']); // bot tuzağı

        $name    = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $email   = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $phone   = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $order   = sanitize_text_field(wp_unslash($_POST['order'] ?? ''));
        $subjects = self::subjects();
        $skey    = sanitize_key($_POST['subject'] ?? 'diger');
        $subject = $subjects[$skey] ?? $subjects['diger'];
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));

        if ($name === '' || !is_email($email) || mb_strlen($message) < 5) {
            wp_send_json_error(['message' => 'Ad, geçerli bir e-posta ve mesaj alanlarını doldur.'], 422);
        }
        if (empty($_POST['kvkk'])) {
            wp_send_json_error(['message' => 'Devam etmek için KVKK onay kutusunu işaretle.'], 422);
        }

        // Basit hız sınırı: aynı IP'den 10 dakikada en fazla 5 mesaj
        $ip  = preg_replace('/[^0-9a-f:.]/i', '', $_SERVER['REMOTE_ADDR'] ?? '');
        $key = 'kinetik_contact_' . md5($ip);
        $n   = (int) get_transient($key);
        if ($n >= 5) wp_send_json_error(['message' => 'Çok sayıda mesaj gönderildi. Birkaç dakika sonra tekrar dene.'], 429);
        set_transient($key, $n + 1, 10 * MINUTE_IN_SECONDS);

        $body = "Ad soyad: {$name}\nE-posta: {$email}\nTelefon: " . ($phone ?: '-') . "\nKonu: {$subject}\nSipariş no: " . ($order ?: '-') . "\n\n{$message}";
        wp_insert_post([
            'post_type'    => self::CPT,
            'post_status'  => 'private',
            'post_title'   => $subject . ' — ' . $name,
            'post_content' => $body,
        ]);

        $sent = wp_mail(
            get_option('admin_email'),
            '[' . kinetik_brand() . '] ' . $subject . ' — ' . $name,
            $body,
            ['Reply-To: ' . $name . ' <' . $email . '>']
        );

        wp_send_json_success(['message' => $sent
            ? 'Mesajın bize ulaştı. En kısa sürede e-posta ile dönüş yapacağız.'
            : 'Mesajın kaydedildi. En kısa sürede dönüş yapacağız.']);
    }
}
Kinetik_Contact::init();
