/* KİNETİK — tema davranışları (bağımlılıksız; WooCommerce olayları için jQuery varsa kullanılır). */
(function () {
  'use strict';
  var $ = window.jQuery;
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var body = document.body;

  /* ---------------------------------------------------------- Mobil çekmece */
  (function () {
    var toggle = document.querySelector('.menu-toggle');
    var drawer = document.getElementById('drawer');
    var back = document.querySelector('.drawer-backdrop');
    if (!toggle || !drawer) return;
    var timer;
    function set(open) {
      clearTimeout(timer);
      if (open) {
        drawer.removeAttribute('inert'); back.hidden = false;
        requestAnimationFrame(function () { body.classList.add('drawer-open'); });
        var f = drawer.querySelector('.drawer__close'); if (f) f.focus();
      } else {
        body.classList.remove('drawer-open');
        drawer.setAttribute('inert', '');
        timer = setTimeout(function () { back.hidden = true; }, 300);
        if (drawer.contains(document.activeElement)) toggle.focus();
      }
      drawer.setAttribute('aria-hidden', String(!open));
      toggle.setAttribute('aria-expanded', String(open));
    }
    toggle.addEventListener('click', function () { set(!body.classList.contains('drawer-open')); });
    drawer.querySelector('.drawer__close').addEventListener('click', function () { set(false); });
    back.addEventListener('click', function () { set(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && body.classList.contains('drawer-open')) set(false); });
  })();

  /* ---------------------------------------------------------- Arama paneli */
  (function () {
    var btn = document.querySelector('.search-toggle');
    var panel = document.getElementById('search-panel');
    if (!btn || !panel) return;
    function set(open) {
      panel.hidden = !open;
      btn.setAttribute('aria-expanded', String(open));
      if (open) setTimeout(function () { var i = panel.querySelector('input[type=search]'); if (i) i.focus(); }, 30);
    }
    btn.addEventListener('click', function () { set(panel.hidden); });
    panel.querySelector('.search-panel__close').addEventListener('click', function () { set(false); btn.focus(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) set(false); });
  })();

  /* ---------------------------------------------------------- Header: kaydırınca daralır */
  (function () {
    var h = document.getElementById('site-header');
    if (!h) return;
    var on = function () { h.classList.toggle('is-stuck', window.scrollY > 40); };
    on(); window.addEventListener('scroll', on, { passive: true });
  })();

  /* ---------------------------------------------------------- Toast */
  function toast(title, text, isError) {
    var old = document.querySelector('.toast'); if (old) old.remove();
    var t = document.createElement('div');
    t.className = 'toast' + (isError ? ' toast--error' : '');
    t.setAttribute('role', 'status');
    var b = document.createElement('div'); b.className = 'toast__body';
    var s = document.createElement('b'); s.textContent = title; b.appendChild(s);
    if (text) { var p = document.createElement('span'); p.textContent = text; b.appendChild(p); }
    t.appendChild(b);
    if (!isError) {
      var a = document.createElement('a'); a.href = (window.KINETIK && KINETIK.cartUrl) || '/sepet/'; a.className = 'toast__link'; a.textContent = 'Sepete git';
      t.appendChild(a);
    }
    body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('is-in'); });
    setTimeout(function () { t.classList.remove('is-in'); setTimeout(function () { t.remove(); }, 300); }, 4200);
  }
  if ($) {
    $(document.body).on('added_to_cart', function (e, frag, hash, $btn) {
      var name = '';
      if ($btn && $btn.length) name = $.trim($btn.closest('li.product').find('.pcard__title').text());
      toast('Sepete eklendi', name);
    });
  }

  /* ---------------------------------------------------------- Beden butonları (PDP) */
  (function () {
    var form = document.querySelector('form.variations_form');
    if (!form) return;
    var msg = document.querySelector('.pdp-size-msg');

    function groupFor(select) { return select.parentNode.querySelector('.size-buttons'); }
    function sync() {
      form.querySelectorAll('select[data-kinetik-native]').forEach(function (sel) {
        var g = groupFor(sel); if (!g) return;
        var avail = {};
        Array.prototype.forEach.call(sel.options, function (o) { if (o.value) avail[o.value] = !o.disabled; });
        g.querySelectorAll('.size-btn').forEach(function (b) {
          var v = b.getAttribute('data-value');
          var on = sel.value === v;
          b.classList.toggle('is-selected', on);
          b.setAttribute('aria-pressed', String(on));
          b.classList.toggle('is-unavailable', !(v in avail) || !avail[v]);
        });
      });
    }
    form.addEventListener('click', function (e) {
      var b = e.target.closest('.size-btn'); if (!b) return;
      var g = b.closest('.size-buttons');
      var sel = g.parentNode.querySelector('select[data-kinetik-native]');
      if (!sel || b.classList.contains('is-unavailable')) return;
      var v = b.getAttribute('data-value');
      sel.value = sel.value === v ? '' : v;
      if ($) $(sel).trigger('change'); else sel.dispatchEvent(new Event('change', { bubbles: true }));
      if (msg) msg.hidden = true;
      sync();
    });
    if ($) {
      $(form).on('woocommerce_update_variation_values reset_data', function () { setTimeout(sync, 0); });
      // Seçilen varyasyonun fiyatını başlıktaki fiyat bloğuna taşı
      var priceBlock = document.querySelector('.pdp-summary__price');
      var original = priceBlock ? priceBlock.innerHTML : '';
      $(form).on('found_variation', function (e, v) {
        if (priceBlock && v && v.price_html) { priceBlock.innerHTML = v.price_html; }
      }).on('reset_data', function () { if (priceBlock) priceBlock.innerHTML = original; });
    }
    // Beden seçilmeden butona basılırsa WooCommerce'in alert()'i yerine satır içi uyarı
    form.addEventListener('click', function (e) {
      var btn = e.target.closest('.single_add_to_cart_button');
      if (!btn || !btn.classList.contains('disabled')) return;
      e.preventDefault(); e.stopImmediatePropagation();
      if (btn.classList.contains('wc-variation-selection-needed') && msg) {
        msg.hidden = false;
        var g = form.querySelector('.size-buttons');
        if (g && !reduce) { g.classList.remove('shake'); void g.offsetWidth; g.classList.add('shake'); }
      }
    }, true);
    sync();
  })();

  /* ---------------------------------------------------------- PDP AJAX sepete ekle
     Form ürün adresine fetch ile POST edilir (WooCommerce'in standart form işleyicisi:
     varyasyon, stok ve eklenti doğrulamaları aynen çalışır). Dönen sayfadaki bildirimler okunur. */
  (function () {
    var form = document.querySelector('.pdp-buy form.cart');
    if (!form || !window.fetch || !window.FormData) return;
    form.addEventListener('submit', function (e) {
      var btn = form.querySelector('.single_add_to_cart_button');
      if (btn && btn.classList.contains('disabled')) return;
      e.preventDefault();
      var fd = new FormData(form);
      if (!fd.has('add-to-cart') && btn && btn.value) fd.set('add-to-cart', btn.value);
      if (btn) { btn.disabled = true; btn.classList.add('is-loading'); }
      fetch(form.getAttribute('action') || window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.text(); })
        .then(function (html) {
          var doc = new DOMParser().parseFromString(html, 'text/html');
          var err = doc.querySelector('.woocommerce-error li, .woocommerce-error, .wc-block-components-notice-banner.is-error');
          if (err) { toast('Sepete eklenemedi', err.textContent.replace(/\s+/g, ' ').trim(), true); return; }
          var title = document.querySelector('.pdp-summary__title');
          var sel = form.querySelector('.size-btn.is-selected');
          toast('Sepete eklendi', (title ? title.textContent.trim() : '') + (sel ? ' — ' + sel.textContent.trim() : ''));
          var cnt = doc.querySelector('.cart-count');
          if (cnt) document.querySelectorAll('.cart-count').forEach(function (c) { c.textContent = cnt.textContent; c.setAttribute('data-count', cnt.textContent.trim()); });
          if ($) $(document.body).trigger('wc_fragment_refresh');
        })
        .catch(function () { form.submit(); })
        .finally(function () { if (btn) { btn.disabled = false; btn.classList.remove('is-loading'); } });
    });
  })();

  /* ---------------------------------------------------------- PDP galeri: mobil nokta göstergesi */
  (function () {
    var track = document.querySelector('.pdp-gallery__track');
    var dots = document.querySelectorAll('.pdp-gallery__dots span');
    if (!track || !dots.length) return;
    track.addEventListener('scroll', function () {
      var i = Math.round(track.scrollLeft / track.clientWidth);
      dots.forEach(function (d, k) { d.classList.toggle('is-on', k === i); });
    }, { passive: true });
  })();

  /* ---------------------------------------------------------- Dialog (beden tablosu) */
  document.addEventListener('click', function (e) {
    var open = e.target.closest('[data-dialog]');
    if (open) {
      var d = document.getElementById(open.getAttribute('data-dialog'));
      if (d && d.showModal) { d.showModal(); body.classList.add('has-dialog'); }
      return;
    }
    if (e.target.closest('[data-dialog-close]') || (e.target.tagName === 'DIALOG' && e.target.open)) {
      var dlg = e.target.closest('dialog'); if (dlg) dlg.close();
    }
  });
  document.querySelectorAll('dialog').forEach(function (d) { d.addEventListener('close', function () { body.classList.remove('has-dialog'); }); });

  /* ---------------------------------------------------------- Filtre çekmecesi + temiz URL */
  (function () {
    var btn = document.querySelector('.filter-toggle');
    var panel = document.getElementById('shop-filters');
    if (!btn || !panel) return;
    var back = document.querySelector('.filters-backdrop');
    function set(open) {
      body.classList.toggle('filters-open', open);
      btn.setAttribute('aria-expanded', String(open));
      back.hidden = !open;
    }
    btn.addEventListener('click', function () { set(!body.classList.contains('filters-open')); });
    panel.querySelector('.filters__close').addEventListener('click', function () { set(false); });
    back.addEventListener('click', function () { set(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') set(false); });

    var form = panel.querySelector('.filters__form');
    if (form) form.addEventListener('submit', function (e) {
      e.preventDefault();
      var url = new URL(form.action, window.location.href);
      var groups = {};
      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name || el.disabled) return;
        if (el.type === 'checkbox') { if (el.checked) { var k = el.name.replace('[]', ''); (groups[k] = groups[k] || []).push(el.value); } return; }
        if (el.name.indexOf('query_type_') === 0) return;
        if (el.value !== '') url.searchParams.set(el.name, el.value);
      });
      Object.keys(groups).forEach(function (k) {
        url.searchParams.set(k, groups[k].join(','));
        url.searchParams.set('query_type_' + k.replace('filter_', ''), 'or');
      });
      window.location.href = url.toString();
    });
  })();

  /* ---------------------------------------------------------- Sıralama: otomatik gönder (WooCommerce JS yoksa) */
  document.querySelectorAll('.woocommerce-ordering select').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
  });

  /* ---------------------------------------------------------- Mağaza: "Daha fazla göster" (sayfalama yedekte kalır) */
  (function () {
    var list = document.querySelector('.shop-main ul.products');
    var pag = document.querySelector('.shop-pagination');
    if (!list || !pag) return;
    var next = pag.querySelector('a.next');
    if (!next) return;
    var more = document.createElement('button');
    more.type = 'button'; more.className = 'btn btn--outline load-more'; more.textContent = 'Daha fazla göster';
    pag.parentNode.insertBefore(more, pag);
    pag.classList.add('is-js');
    var url = next.href;
    more.addEventListener('click', function () {
      more.disabled = true; more.textContent = 'Yükleniyor…';
      fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        doc.querySelectorAll('.shop-main ul.products > li').forEach(function (li) { list.appendChild(document.importNode(li, true)); });
        var n = doc.querySelector('.shop-pagination a.next');
        url = n ? n.href : null;
        if (!url) { more.remove(); pag.remove(); } else { more.disabled = false; more.textContent = 'Daha fazla göster'; }
      }).catch(function () { window.location.href = url; });
    });
  })();

  /* ---------------------------------------------------------- Sepet: adet adımlayıcı + otomatik güncelle */
  (function () {
    var form = document.querySelector('form.woocommerce-cart-form');
    if (!form) return;
    var t;
    function queue() {
      clearTimeout(t);
      t = setTimeout(function () {
        var u = document.querySelector('form.woocommerce-cart-form button[name="update_cart"]');
        if (u) { u.disabled = false; u.click(); }
      }, 600);
    }
    document.addEventListener('click', function (e) {
      var b = e.target.closest('.qty-stepper__btn'); if (!b) return;
      var input = b.parentNode.querySelector('input.qty'); if (!input) return;
      e.preventDefault();
      var v = (parseInt(input.value, 10) || 0) + parseInt(b.getAttribute('data-step'), 10);
      var max = parseInt(input.getAttribute('max'), 10);
      if (v < 0) v = 0; if (max && v > max) v = max;
      input.value = v;
      queue();
    });
    document.addEventListener('change', function (e) { if (e.target.matches('.woocommerce-cart-form input.qty')) queue(); });
  })();

  /* ---------------------------------------------------------- İletişim formu */
  (function () {
    var f = document.getElementById('kinetik-contact-form');
    if (!f) return;
    var btn = f.querySelector('.contact-form__submit');
    var out = f.querySelector('.contact-form__status');
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!f.checkValidity()) { f.reportValidity(); return; }
      var fd = new FormData(f); fd.append('action', 'kinetik_contact');
      btn.disabled = true; var old = btn.textContent; btn.textContent = 'Gönderiliyor…';
      out.className = 'contact-form__status'; out.textContent = '';
      fetch((window.KINETIK && KINETIK.ajax) || '/wp-admin/admin-ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          var ok = j && j.success;
          out.className = 'contact-form__status ' + (ok ? 'is-ok' : 'is-err');
          out.textContent = (j && j.data && j.data.message) || (ok ? 'Mesajın alındı.' : 'Gönderilemedi. Tekrar dene.');
          if (ok) { f.reset(); btn.textContent = 'Gönderildi'; } else { btn.disabled = false; btn.textContent = old; }
        })
        .catch(function () { out.className = 'contact-form__status is-err'; out.textContent = 'Bağlantı kurulamadı. Telefonla da ulaşabilirsin.'; btn.disabled = false; btn.textContent = old; });
    });
  })();

  /* ---------------------------------------------------------- Çerez bildirimi */
  (function () {
    var bar = document.getElementById('cookie-bar');
    if (!bar) return;
    var seen = false;
    try { seen = localStorage.getItem('kinetik_cookie_ok') === '1'; } catch (e) {}
    if (seen) return;
    bar.hidden = false;
    bar.querySelector('[data-cookie-ok]').addEventListener('click', function () {
      try { localStorage.setItem('kinetik_cookie_ok', '1'); } catch (e) {}
      bar.hidden = true;
    });
  })();
})();
