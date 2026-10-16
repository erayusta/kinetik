/**
 * Türkiye il → ilçe → mahalle → cadde/sokak kademeli adres seçimi.
 * Kaynak: NVİ (adres.nvi.gov.tr). Fatura (billing_) ve teslimat (shipping_)
 * adreslerinin ikisinde de çalışır; veri önbellekleri ortaktır.
 */
(function ($) {
  var ilIlce = null;        // {plaka: {name, districts: [[id, ad],...]}}
  var mahalleCache = {};    // ilceId -> [[mahalleId, ad],...]
  var sokakCache = {};      // ilceId -> {mahalleId: [sokak,...]}
  var OTHER = '__diger__';

  function fillSelect($sel, items, placeholder, valueFn, textFn) {
    $sel.empty().append($('<option>', { value: '', text: placeholder }));
    (items || []).forEach(function (it) {
      $sel.append($('<option>', { value: valueFn(it), text: textFn(it) }));
    });
    $sel.append($('<option>', { value: OTHER, text: 'Listede yok / elle gireceğim' }));
    $sel.trigger('change.select2');
  }

  function getJSON(path) {
    return $.getJSON(KinetikAdres.base + path + '?v=' + KinetikAdres.v);
  }

  /** Bir adres bölümü (billing|shipping) için kademeli seçim kur */
  function setup(prefix) {
    var $state    = $('#' + prefix + '_state');
    var $district = $('#' + prefix + '_district');
    var $hood     = $('#' + prefix + '_neighborhood');
    var $street   = $('#' + prefix + '_street');
    var $city     = $('#' + prefix + '_city');
    if (!$district.length) return;

    var mahalleIdByName = {};
    var pending = (KinetikAdres.saved && KinetikAdres.saved[prefix]) ? Object.assign({}, KinetikAdres.saved[prefix]) : {};

    function plate() {
      var m = /^TR(\d{2})$/.exec($state.val() || '');
      return m ? m[1] : null;
    }
    function districtId() {
      var v = $district.val();
      return v && v !== OTHER ? v.split('|')[0] : null;
    }
    function resetBelow(level) {
      if (level <= 1) fillSelect($district, [], 'Önce il seçin', String, String);
      if (level <= 2) fillSelect($hood, [], 'Önce ilçe seçin', String, String);
      if (level <= 3) fillSelect($street, [], 'Önce mahalle seçin', String, String);
    }

    function onIl() {
      var p = plate();
      if (!p || !ilIlce || !ilIlce[p]) { resetBelow(1); return; }
      $city.val(ilIlce[p].name);
      fillSelect($district, ilIlce[p].districts, 'İlçe seçin',
        function (d) { return d[0] + '|' + d[1]; }, function (d) { return d[1]; });
      resetBelow(2);
      if (pending.district) {
        var want = pending.district; pending.district = null;
        var hit = $district.find('option').filter(function () {
          return this.value === want || $(this).text() === want.replace(/^\d+\|/, '');
        }).first();
        if (hit.length) { $district.val(hit.val()); $district.trigger('change'); }
      }
    }

    function onIlce() {
      var id = districtId();
      if (!id) {
        resetBelow(2);
        if ($district.val() === OTHER) fillSelect($hood, [], 'Mahalleyi adres satırına yazın', String, String);
        return;
      }
      var apply = function (rows) {
        mahalleIdByName = {};
        (rows || []).forEach(function (r) { mahalleIdByName[r[1]] = r[0]; });
        fillSelect($hood, rows, 'Mahalle / köy seçin',
          function (r) { return r[1]; }, function (r) { return r[1]; });
        resetBelow(3);
        if (pending.neighborhood) {
          var wantN = pending.neighborhood; pending.neighborhood = null;
          if ($hood.find('option[value="' + wantN.replace(/"/g, '\\"') + '"]').length) {
            $hood.val(wantN); $hood.trigger('change');
          }
        }
      };
      if (mahalleCache[id]) { apply(mahalleCache[id]); }
      else {
        getJSON('mahalle/' + id + '.json')
          .done(function (d) { mahalleCache[id] = d; apply(d); })
          .fail(function () { apply(null); });
      }
      if (!sokakCache[id]) {
        getJSON('sokak/' + id + '.json').done(function (d) { sokakCache[id] = d; });
      }
    }

    function onMahalle() {
      var id = districtId();
      var mahalleAd = $hood.val();
      if (!id || !mahalleAd || mahalleAd === OTHER) {
        fillSelect($street, [], mahalleAd === OTHER ? 'Cadde/sokağı adres satırına yazın' : 'Önce mahalle seçin', String, String);
        return;
      }
      var mid = mahalleIdByName[mahalleAd];
      var apply = function (data) {
        var list = (data && data[String(mid)]) || [];
        fillSelect($street, list, list.length ? 'Cadde / sokak seçin' : 'Bu mahallede kayıt yok — elle girin', String, String);
        if (pending.street) {
          var wantS = pending.street; pending.street = null;
          if ($street.find('option[value="' + wantS.replace(/"/g, '\\"') + '"]').length) {
            $street.val(wantS).trigger('change.select2');
          }
        }
      };
      if (sokakCache[id]) { apply(sokakCache[id]); }
      else {
        getJSON('sokak/' + id + '.json')
          .done(function (d) { sokakCache[id] = d; apply(d); })
          .fail(function () { apply(null); });
      }
    }

    $(document.body)
      .on('change', '#' + prefix + '_state', onIl)
      .on('change', '#' + prefix + '_district', onIlce)
      .on('change', '#' + prefix + '_neighborhood', onMahalle);

    return { init: onIl, hasState: function () { return !!$state.val(); } };
  }

  /** Seçicileri selectWoo (Select2) ile aranabilir hale getir */
  function enhance() {
    var plugin = $.fn.selectWoo ? 'selectWoo' : ($.fn.select2 ? 'select2' : null);
    if (!plugin) return;
    var lang = {
      noResults: function () { return 'Sonuç bulunamadı'; },
      searching: function () { return 'Aranıyor…'; },
    };
    ['billing', 'shipping'].forEach(function (prefix) {
      ['state', 'district', 'neighborhood', 'street'].forEach(function (f) {
        var $el = $('#' + prefix + '_' + f);
        if (!$el.length || $el.hasClass('select2-hidden-accessible')) return;
        // WC boş seçenekli select'e data-placeholder basar; Select2 onu init'te dondurur ve
        // liste dolduktan sonra da "Önce il seçin" gösterir. fillSelect'in yazdığı metin görünsün.
        if (f !== 'state') $el.removeAttr('data-placeholder data-allow_clear');
        $el[plugin]({ width: '100%', language: lang, minimumResultsForSearch: 8 });
      });
    });
  }

  $(function () {
    if (!$('#billing_district').length && !$('#shipping_district').length) return;
    var sections = [];
    // il-ilce.json inene kadar (mobil bağlantıda ~3sn) İl dolu olduğu hâlde İlçe
    // "Önce il seçin" yazıyordu — kullanıcı formun bozuk olduğunu sanıyordu.
    // O aralıkta dürüst bir yükleniyor metni gösteriyoruz (14 Eyl 2026).
    ['billing', 'shipping'].forEach(function (prefix) {
      if ($('#' + prefix + '_state').val()) {
        $('#' + prefix + '_district').find('option[value=""]').text('İlçeler yükleniyor…');
      }
    });
    getJSON('il-ilce.json').done(function (d) {
      ilIlce = d;
      ['billing', 'shipping'].forEach(function (prefix) {
        var sec = setup(prefix);
        if (sec) sections.push(sec);
      });
      sections.forEach(function (sec) { if (sec.hasState()) sec.init(); });
    });
    enhance();
    $(document.body).on('updated_checkout', enhance);
  });
})(jQuery);
