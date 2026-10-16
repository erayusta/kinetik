/**
 * KİNETİK Marketing — bülten formu (AJAX). Formlar delegasyonla dinlenir; tema
 * formu sonradan (ör. açılır pencerede) ekleyebilir.
 *   .kinetik-newsletter > form.kinetik-newsletter__form + .kinetik-newsletter__result
 */
(function ($) {
  $(document).on('submit', '.kinetik-newsletter__form', function (e) {
    e.preventDefault();
    if (typeof KinetikMkt === 'undefined') return;
    var $f = $(this);
    var $box = $f.closest('.kinetik-newsletter');
    var $msg = $box.find('.kinetik-newsletter__result');
    var $input = $f.find('input[type="email"]');
    var email = $.trim($input.val() || '');
    var $btn = $f.find('button');

    if (!email || email.indexOf('@') < 1) {
      $msg.text('Geçerli bir e-posta adresi girin.').addClass('is-error').removeClass('is-success');
      $input.trigger('focus');
      return;
    }

    $btn.prop('disabled', true).addClass('is-loading');
    $.post(KinetikMkt.ajax, {
      action: 'kinetik_subscribe',
      nonce: KinetikMkt.nonce,
      email: email,
      source: window.location.href
    }).done(function (res) {
      if (res && res.success) {
        var $out = $('<span>').text(res.data.message);
        if (res.data.coupon) {
          $out = $out.add($('<span class="kinetik-newsletter__code">').text(res.data.coupon));
        }
        if (res.data.note) {
          $out = $out.add($('<small class="kinetik-newsletter__note">').text(' ' + res.data.note));
        }
        $msg.empty().append($out).addClass('is-success').removeClass('is-error');
        $f.slideUp(200);
        $box.addClass('is-done');
      } else {
        $msg.text((res && res.data && res.data.message) || 'Bir sorun oluştu, lütfen tekrar deneyin.').addClass('is-error');
      }
    }).fail(function () {
      $msg.text('Bağlantı hatası, lütfen tekrar deneyin.').addClass('is-error');
    }).always(function () {
      $btn.prop('disabled', false).removeClass('is-loading');
    });
  });
})(jQuery);
