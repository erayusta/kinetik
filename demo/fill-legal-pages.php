<?php
/**
 * KİNETİK — yasal sayfaların içeriğini yazar. İdempotent: tekrar çalıştırılabilir.
 *   wp eval-file demo/fill-legal-pages.php
 *
 * Metinler Türkiye mevzuatına (6502, Mesafeli Sözleşmeler Yönetmeliği, 6698 KVKK) göre hazırlanmış ŞABLONLARDIR;
 * hukuki danışmanlık değildir. Yayına almadan önce kendi bilgilerinizle güncelleyip hukuk kontrolünden geçirin.
 * - Satıcı künyesinin TEK kaynağı `kinetik_seller_info` seçeneğidir (demo/setup-store.php oluşturur,
 *   WooCommerce → Sözleşmeler ekranı düzenler). Künye değişince bu betiği yeniden çalıştır.
 * - Boş künye alanları <span class="pending-field">[…]</span> olarak bırakılır — UYDURULMAZ.
 * - Kargo ücreti / ücretsiz kargo eşiği WooCommerce kargo ayarlarından okunur.
 * - Sayfalar slug ile bulunur ve yalnızca içerikleri güncellenir; yoksa oluşturulur.
 * - Mesafeli Satış Sözleşmesi ve Ön Bilgilendirme Formu, kinetik-sozlesmeler eklentisi
 *   tarafından ödeme adımında siparişe özel belgeye dönüştürülür (.content-intro atlanır).
 * Not (yalnızca yönetici için): metinler satışa açılmadan önce nihai şirket bilgileriyle
 * güncellenmeli ve profesyonel hukuk kontrolünden geçirilmelidir.
 */
if (!defined('ABSPATH')) exit('WordPress runtime gerekli (wp eval-file).');

function kinetik_demo_legal_page(string $slug, string $title, string $content): void {
    $page = get_page_by_path($slug, OBJECT, 'page');
    if ($page) {
        if ($page->post_content === $content && $page->post_status === 'publish') {
            echo "Değişiklik yok: {$slug} (ID {$page->ID})\n";
            return;
        }
        wp_update_post(['ID' => $page->ID, 'post_content' => $content, 'post_status' => 'publish']);
        echo "Güncellendi: {$slug} (ID {$page->ID}, " . mb_strlen(wp_strip_all_tags($content)) . " karakter)\n";
        return;
    }
    $id = wp_insert_post(['post_type' => 'page', 'post_name' => $slug, 'post_title' => $title, 'post_status' => 'publish', 'post_content' => $content]);
    echo "Oluşturuldu: {$slug} (ID {$id})\n";
}

function kinetik_demo_pending(string $label): string {
    return '<span class="pending-field">[' . esc_html($label) . ' eklenecek]</span>';
}

/* ───────────────────────── Künye ───────────────────────── */
$s = get_option('kinetik_seller_info', []);
$s = is_array($s) ? $s : [];
$f = function (string $key, string $label) use ($s): string {
    $v = trim((string) ($s[$key] ?? ''));
    return $v === '' ? kinetik_demo_pending($label) : esc_html($v);
};

$brand     = trim((string) ($s['marka'] ?? '')) ?: wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
$brand_h   = esc_html($brand);
$unvan     = $f('unvan', 'Şirket ünvanı');
$adres     = $f('adres', 'Adres');
$telefon   = trim((string) ($s['telefon'] ?? ''));
$tel       = $telefon === '' ? kinetik_demo_pending('Telefon') : '<a href="tel:+90' . esc_attr(ltrim(preg_replace('/\D+/', '', $telefon), '0')) . '">' . esc_html($telefon) . '</a>';
$eposta    = trim((string) ($s['eposta'] ?? ''));
$mail      = $eposta === '' ? kinetik_demo_pending('E-posta adresi') : '<a href="mailto:' . esc_attr($eposta) . '">' . esc_html($eposta) . '</a>';
$vd        = $f('vergi_dairesi', 'Vergi dairesi');
$vn        = $f('vergi_no', 'Vergi no');
$mersis    = $f('mersis', 'MERSİS no');
$web_raw   = trim((string) ($s['web'] ?? ''));
$site      = $web_raw !== '' ? esc_html(preg_replace('#^https?://(www\.)?#', '', rtrim($web_raw, '/'))) : kinetik_demo_pending('Web adresi');
$iletisim  = '<a href="' . esc_url(home_url('/iletisim/')) . '">İletişim</a>';
// Müşterinin bize yazılı ulaşabileceği kanallar (e-posta henüz yoksa telefon + iletişim sayfası)
$kanal     = "{$mail} adresi, {$tel} numaralı telefon ya da {$iletisim} sayfamız";

$seller_block = "<p><strong>{$brand_h}</strong> — {$unvan}<br>"
    . "Adres: {$adres}<br>"
    . "Vergi dairesi / no: {$vd} / {$vn}<br>"
    . "MERSİS no: {$mersis}<br>"
    . "Telefon: {$tel} · E-posta: {$mail}<br>"
    . "Web: {$site}</p>";

/* ───────────────────────── Mağaza kuralları ───────────────────────── */
$free = 2000.0; $flat = 99.0;
if (class_exists('WC_Shipping_Zones')) {
    foreach (WC_Shipping_Zones::get_zones() as $z) {
        foreach ($z['shipping_methods'] as $m) {
            if ($m->enabled !== 'yes') continue;
            if ($m->id === 'free_shipping' && (float) $m->get_option('min_amount') > 0) $free = (float) $m->get_option('min_amount');
            if ($m->id === 'flat_rate' && is_numeric($m->get_option('cost'))) $flat = (float) $m->get_option('cost');
        }
    }
}
$tl        = function (float $n): string { return number_format($n, 0, ',', '.') . ' TL'; };
$kargo     = $tl($flat);
$esik      = $tl($free);
$updated   = '<p><em>Son güncelleme: ' . esc_html(wp_date('j F Y')) . '</em></p>';

/* ───────────────────────── 1. Mesafeli Satış Sözleşmesi ───────────────────────── */
$mesafeli = <<<HTML
<div class="content-intro">
<p class="eyebrow">Satış koşulları</p>
<p class="content-lead">Bu sözleşme, {$site} üzerinden verdiğin siparişlerde tarafların hak ve yükümlülüklerini düzenler. Sipariş vermeden önce okumanı rica ederiz.</p>
</div>
<div class="legal-body">
{$updated}
<p>İşbu Mesafeli Satış Sözleşmesi ("Sözleşme"), 6502 sayılı Tüketicinin Korunması Hakkında Kanun ve Mesafeli Sözleşmeler Yönetmeliği hükümlerine göre düzenlenmiştir. Ödeme adımında Ön Bilgilendirme Formu'nu ve bu Sözleşme'yi onaylamanla Sözleşme kurulur; sipariş özeti, ürün, beden, adet, bedel ve teslimat bilgileri Sözleşme'nin ayrılmaz parçası olarak e-posta adresine gönderilir.</p>

<h2>1. Taraflar</h2>
<h3>Satıcı</h3>
{$seller_block}
<h3>Alıcı</h3>
<p>Sipariş formunda beyan ettiği ad-soyad, teslimat/fatura adresi, e-posta ve telefon bilgileriyle alışveriş yapan tüketici. Alıcı, verdiği bilgilerin doğru ve güncel olduğunu kabul eder; hatalı bilgiden doğan gecikme ve zararlardan Satıcı sorumlu tutulamaz.</p>

<h2>2. Sözleşmenin konusu</h2>
<p>Alıcı'nın {$site} üzerinden elektronik ortamda sipariş verdiği, nitelikleri ve satış bedeli sipariş özetinde belirtilen spor giyim ve aksesuar ürünlerinin satışı ve teslimi ile tarafların bu satıştan doğan hak ve yükümlülükleridir.</p>

<h2>3. Ürün bilgileri ve bedel</h2>
<ul>
<li>Ürünün adı, temel nitelikleri (beden, kumaş ve kullanım bilgisi), adedi ve tüm vergiler dâhil satış bedeli ürün sayfasında ve sipariş özetinde gösterilir.</li>
<li>Ürün görselleri gerçek ürünü yansıtacak şekilde hazırlanır; ekran ve ışık ayarlarından kaynaklanan küçük renk tonu farkları olabilir. Beden ölçüleri <a href="/beden-rehberi/">Beden Rehberi</a>'nde yer alır.</li>
<li>Fiyatlar Türk Lirası'dır ve sipariş anında geçerli olan tutar esas alınır. Sipariş onayından sonra yapılan fiyat değişiklikleri verilmiş siparişi etkilemez.</li>
<li>Kupon veya kampanya indirimi uygulanmışsa, indirimli tutar sipariş özetinde ayrıca gösterilir.</li>
<li>Kargo bedeli ve ödeme yöntemi sipariş özetinde açıkça belirtilir; bunların dışında ek masraf talep edilmez.</li>
</ul>

<h2>4. Ödeme</h2>
<ul>
<li>Ödeme <strong>Havale / EFT</strong> ile yapılır. Satıcı'nın banka hesap bilgileri sipariş onay ekranında ve sipariş onay e-postasında paylaşılır; açıklama alanına sipariş numarasının yazılması gerekir.</li>
<li>Sipariş, ödeme Satıcı hesabına ulaşana kadar "ödeme bekleniyor" durumunda tutulur. Makul süre (3 iş günü) içinde ödemesi ulaşmayan siparişler iptal edilebilir; Alıcı'ya e-posta ile bilgi verilir.</li>
<li>Havale / EFT işlemlerinde bankaların uyguladığı işlem ücretleri Alıcı'ya aittir.</li>
</ul>

<h2>5. Teslimat</h2>
<ul>
<li>Teslimat yalnızca Türkiye sınırları içinde, Alıcı'nın bildirdiği adrese anlaşmalı kargo firmasıyla yapılır.</li>
<li>Kargo bedeli sipariş başına {$kargo}'dir; ürün ara toplamı {$esik} ve üzeri siparişlerde kargo ücretsizdir.</li>
<li>Siparişler ödemenin onaylanmasından itibaren en geç <strong>3 iş günü</strong> içinde kargoya teslim edilir ve kargo takip numarası e-posta ile paylaşılır. Kargo firmasının teslim süresi bölgeye göre değişir; yasal azami teslim süresi sipariş tarihinden itibaren 30 gündür.</li>
<li>Alıcı'nın adreste bulunmaması, adresin hatalı olması veya teslimatı reddetmesi hâlinde ürün Satıcı'ya döner; yeniden gönderim masrafı Alıcı'ya aittir.</li>
<li>Teslim sırasında paket hasarlıysa Alıcı, kargo görevlisine tutanak tutturmalı ve paketi teslim almamalıdır. Teslim alındıktan sonra fark edilen hasar, eksik ya da yanlış ürün en kısa sürede fotoğraflarıyla {$kanal} üzerinden bildirilmelidir.</li>
</ul>

<h2>6. Cayma hakkı</h2>
<p>Alıcı, ürünü teslim aldığı tarihten itibaren <strong>14 gün</strong> içinde hiçbir gerekçe göstermeksizin ve cezai şart ödemeksizin sözleşmeden cayabilir. Birden fazla ürünün ayrı ayrı teslim edildiği siparişlerde süre, son ürünün teslim alındığı gün başlar. Cayma bildirimi, süre dolmadan önce Hesabım → Siparişlerim sayfasından ya da {$kanal} üzerinden sipariş numarasıyla yapılır.</p>
<ul>
<li>Ürün; <strong>kullanılmamış, yıkanmamış, etiketleri sökülmemiş</strong> ve mümkünse orijinal ambalajıyla, faturası ve tüm parçalarıyla birlikte iade edilmelidir. Ürünü denemek için yalnızca bir mağazada deneyebileceğin ölçüde (kısa süreli, etiketleri yerinde) prova yapılabilir.</li>
<li>Cayma bildiriminin ulaşmasından itibaren 10 gün içinde ürün, Satıcı'nın bildirdiği anlaşmalı kargo firmasıyla gönderilir. Anlaşmalı kargo kullanıldığında iade kargo bedeli Satıcı'ya aittir; Alıcı başka bir kargo firması seçerse kargo bedeli Alıcı'ya aittir.</li>
<li>Satıcı, cayma bildiriminin kendisine ulaştığı tarihten itibaren en geç <strong>14 gün</strong> içinde ürün bedelini ve varsa teslimat masrafını iade eder. Havale / EFT ile yapılan ödemelerde iade, Alıcı'nın bildirdiği ve Alıcı adına kayıtlı IBAN'a yapılır. Satıcı, ürün kendisine ulaşana ya da Alıcı ürünü geri gönderdiğini belgeleyene kadar iadeyi bekletebilir.</li>
<li>Ürünün olağan kontrolü aşan şekilde kullanılması, yıkanması, etiketlerinin sökülmesi ya da hasar görmesi nedeniyle oluşan değer kaybından Alıcı sorumludur.</li>
</ul>

<h2>7. Cayma hakkının kullanılamayacağı ürünler</h2>
<p>Mesafeli Sözleşmeler Yönetmeliği'nin 15. maddesi uyarınca aşağıdaki ürünlerde cayma hakkı kullanılamaz:</p>
<ul>
<li>Tesliminden sonra hijyenik ambalajı, koruyucu bandı veya mührü açılmış <strong>çorap ve iç giyim</strong> ürünleri gibi sağlık ve hijyen açısından iadesi uygun olmayan ürünler,</li>
<li>Alıcı'nın istekleri veya kişisel ihtiyaçları doğrultusunda hazırlanan, kişiye özel (ör. isim/numara baskılı) ürünler.</li>
</ul>
<p>Hijyenik ambalajı açılmamış çorap ve iç giyim ürünlerinde 14 günlük cayma hakkı tam olarak geçerlidir.</p>

<h2>8. Ayıplı ürün</h2>
<p>Teslim edilen ürünün ayıplı olması hâlinde Alıcı, 6502 sayılı Kanun'un 11. maddesindeki seçimlik haklarını (sözleşmeden dönme, bedelden indirim, ücretsiz onarım veya ayıpsız misliyle değişim) kullanabilir. Ayıp bildirimi {$kanal} üzerinden sipariş numarası ve görsellerle yapılır. Ayıplı ürünün iade ve yeniden gönderim masrafları Satıcı'ya aittir.</p>

<h2>9. Satıcı'nın yükümlülükleri</h2>
<ul>
<li>Ürünü, sipariş özetinde belirtilen nitelikte ve bedende, sağlam ve eksiksiz teslim etmek,</li>
<li>Stok tükenmesi ya da başka bir nedenle siparişin ifası imkânsızlaşırsa Alıcı'yı bu durumu öğrendiği tarihten itibaren 3 gün içinde bilgilendirmek ve ödenen tutarı en geç 14 gün içinde iade etmek,</li>
<li>Alıcı'nın kişisel verilerini <a href="/kvkk-aydinlatma-metni/">KVKK Aydınlatma Metni</a>'ne uygun biçimde işlemek.</li>
</ul>

<h2>10. Uyuşmazlıkların çözümü</h2>
<p>Sözleşme'den doğan uyuşmazlıklarda, Ticaret Bakanlığı'nca her yıl belirlenen parasal sınırlar dâhilinde Alıcı'nın yerleşim yerindeki veya alışverişi yaptığı yerdeki Tüketici Hakem Heyetleri, bu sınırların üzerindeki uyuşmazlıklarda Tüketici Mahkemeleri yetkilidir. Alıcı şikâyetlerini önce {$kanal} üzerinden iletebilir.</p>

<h2>11. Yürürlük</h2>
<p>Alıcı, siparişi tamamlamadan önce Ön Bilgilendirme Formu'nu ve bu Sözleşme'yi okuduğunu, elektronik ortamda onayladığını kabul eder. Sözleşme, siparişin oluşturulduğu tarihte yürürlüğe girer ve bir örneği Alıcı'nın e-posta adresine gönderilir; ayrıca Hesabım → Siparişlerim sayfasında saklanır.</p>
</div>
HTML;

/* ───────────────────────── 2. Ön Bilgilendirme Formu ───────────────────────── */
$onbilgi = <<<HTML
<div class="content-intro">
<p class="eyebrow">Sipariş öncesi bilgilendirme</p>
<p class="content-lead">Siparişini tamamlamadan önce bilmen gereken satıcı, ürün, ödeme, teslimat ve cayma hakkı bilgilerini burada topladık.</p>
</div>
<div class="legal-body">
{$updated}
<p>Bu form, Mesafeli Sözleşmeler Yönetmeliği'nin 5. maddesi uyarınca, sipariş verilmeden önce tüketicinin bilgilendirilmesi amacıyla hazırlanmıştır. Formdaki bilgiler sipariş özetinde tekrar gösterilir ve sipariş onay e-postasıyla kalıcı veri saklayıcısı olarak sana iletilir.</p>

<h2>1. Satıcı bilgileri</h2>
{$seller_block}

<h2>2. Ürünün temel nitelikleri ve bedeli</h2>
<p>Sipariş ettiğin ürünün adı, seçtiğin beden, adedi, temel nitelikleri ve tüm vergiler dâhil satış bedeli ürün sayfasında ve sepet özetinde gösterilir. Fiyatlar Türk Lirası'dır ve sipariş anında geçerli tutardır. Kupon indirimi varsa indirimli tutar özette ayrıca görünür. Doğru bedeni seçmek için <a href="/beden-rehberi/">Beden Rehberi</a>'ne göz atabilirsin.</p>

<h2>3. Ödeme</h2>
<ul>
<li><strong>Havale / EFT:</strong> Siparişini tamamladıktan sonra banka hesap bilgilerimiz ekranda ve e-postanda görünür. Açıklama alanına sipariş numaranı yazarak ödersin.</li>
<li>Ödemen hesabımıza ulaştığında siparişin hazırlanmaya başlar. Makul süre (3 iş günü) içinde ödemesi ulaşmayan siparişler iptal edilebilir.</li>
</ul>

<h2>4. Teslimat</h2>
<ul>
<li>Teslimat Türkiye içine, anlaşmalı kargo firmasıyla yapılır. Kargo bedeli {$kargo}'dir; {$esik} ve üzeri ürün ara toplamında kargo ücretsizdir.</li>
<li>Siparişler ödemenin onayından sonra en geç <strong>3 iş günü</strong> içinde kargoya teslim edilir; kargo takip numarası e-posta ile paylaşılır. Yasal azami teslim süresi 30 gündür.</li>
<li>Teslim sırasında hasarlı paketler teslim alınmamalı, kargo görevlisine tutanak tutturulmalıdır.</li>
</ul>

<h2>5. Cayma hakkı</h2>
<p>Ürünü teslim aldığın tarihten itibaren <strong>14 gün</strong> içinde gerekçe göstermeden cayma hakkın vardır. Cayma bildirimini Hesabım → Siparişlerim sayfasından ya da {$kanal} üzerinden yapabilirsin. Ürün kullanılmamış, yıkanmamış ve etiketleri sökülmemiş olmalıdır. Bildirdiğimiz anlaşmalı kargoyla iade ücretsizdir. Ödediğin tutar, cayma bildirimin bize ulaştıktan sonra en geç 14 gün içinde, adına kayıtlı IBAN'a iade edilir.</p>

<h2>6. Cayma hakkının istisnaları</h2>
<p>Hijyen nedeniyle, hijyenik ambalajı veya koruyucu bandı açılmış çorap ve iç giyim ürünlerinde ve kişiye özel hazırlanan (ör. baskılı) ürünlerde cayma hakkı kullanılamaz. Ambalajı açılmamış ürünlerde hak tam olarak geçerlidir.</p>

<h2>7. Şikâyet ve başvuru</h2>
<p>Her türlü talep ve şikâyetini {$kanal} üzerinden iletebilirsin. Ayrıca yasal parasal sınırlar dâhilinde Tüketici Hakem Heyetlerine, sınırın üzerinde Tüketici Mahkemelerine başvurabilirsin.</p>

<h2>8. Onay</h2>
<p>Ödeme adımında "Ön Bilgilendirme Formu'nu okudum ve onaylıyorum" kutusunu işaretleyerek bu formdaki bilgileri sipariş öncesinde okuduğunu ve anladığını beyan etmiş olursun.</p>
</div>
HTML;

/* ───────────────────────── 3. İptal ve İade Koşulları ───────────────────────── */
$iade = <<<HTML
<div class="content-intro">
<p class="eyebrow">İptal, iade ve değişim</p>
<p class="content-lead">Beden olmadıysa ya da fikrin değiştiyse süreç net: 14 gün içinde talep et, anlaşmalı kargoyla ücretsiz gönder, iaden 14 gün içinde hesabında.</p>
</div>
<div class="legal-body">
{$updated}

<h2>Sipariş iptali</h2>
<p>Henüz kargoya verilmemiş siparişini Hesabım → Siparişlerim sayfasından ya da {$kanal} üzerinden iptal edebilirsin. Ödemen ulaşmışsa tutar, adına kayıtlı IBAN'a en geç 14 gün içinde iade edilir. Kargoya verilmiş siparişlerde aşağıdaki iade süreci uygulanır.</p>

<h2>Adım adım iade</h2>
<ol>
<li><strong>Talebini ilet.</strong> Ürünü teslim aldığın tarihten itibaren 14 gün içinde Hesabım → Siparişlerim'den iade talebi oluştur ya da {$kanal} üzerinden sipariş numaranla bize ulaş. Havale / EFT iadesi için adına kayıtlı IBAN'ını da paylaş.</li>
<li><strong>Paketle.</strong> Ürünü etiketleri üzerinde, faturası ve varsa orijinal ambalajıyla birlikte paketle.</li>
<li><strong>Kargoya ver.</strong> Sana bildirdiğimiz anlaşmalı kargo firması ve iade koduyla gönder; bu durumda iade kargosu ücretsizdir. Farklı bir kargo firması seçersen gönderim bedeli sana ait olur.</li>
<li><strong>Kontrol.</strong> Ürün depomuza ulaştığında iade koşullarına uygunluğu kontrol edilir ve sonuç e-posta ile bildirilir.</li>
<li><strong>Ücret iadesi.</strong> Ödediğin tutar, cayma bildirimin bize ulaştıktan sonra en geç <strong>14 gün</strong> içinde IBAN'ına iade edilir. Siparişin tamamını iade ediyorsan ödediğin kargo bedeli de iade edilir.</li>
</ol>

<h2>İade koşulları</h2>
<ul>
<li>Ürün <strong>kullanılmamış ve yıkanmamış</strong> olmalıdır; parfüm, deodorant, makyaj ya da ter izi taşımamalıdır.</li>
<li>Ürünün <strong>etiketleri sökülmemiş</strong> olmalı; varsa orijinal ambalajı ve aksesuarlarıyla eksiksiz gönderilmelidir.</li>
<li>Denemek serbest: ürünü bir mağaza kabininde deneyebileceğin gibi kısa süreli, etiketleri yerinde ve temiz bir ortamda prova edebilirsin.</li>
<li>Hediye olarak alınan ürünler de aynı koşullarla iade edilebilir; iade tutarı siparişi veren kişiye ödenir.</li>
</ul>

<h2>İade edilemeyen ürünler</h2>
<ul>
<li>Hijyen nedeniyle <strong>hijyenik ambalajı veya koruyucu bandı açılmış çorap ve iç giyim</strong> ürünleri,</li>
<li>İsteğin üzerine kişiye özel hazırlanan (ör. isim/numara baskılı) ürünler.</li>
</ul>
<p>Bu ürünlerde Mesafeli Sözleşmeler Yönetmeliği'nin 15. maddesi uyarınca cayma hakkı kullanılamaz. Ambalajı açılmamış çorap ve iç giyim ürünlerinde iade hakkın tam olarak geçerlidir.</p>

<h2>Beden değişimi</h2>
<p>Bedenin olmadıysa iade talebinde değişim istediğini ve yeni bedeni belirtmen yeterli. Ürün bize ulaşıp kontrol edildikten sonra, yeni beden stokta ise gönderimini ücretsiz yaparız. Stokta yoksa tutar iade edilir. Daha hızlı ulaşmasını istersen yeni bedeni ayrıca sipariş edip ilk ürünü iade edebilirsin.</p>

<h2>Hasarlı, kusurlu veya yanlış ürün</h2>
<ul>
<li>Paket dışarıdan hasarlıysa kargo görevlisine tutanak tutturup teslim almaman en hızlı çözümdür.</li>
<li>Teslim aldıktan sonra fark ettiğin hasar, üretim kusuru, eksik ya da yanlış ürünü fotoğraflarıyla {$kanal} üzerinden bildir.</li>
<li>Bu durumlarda iade ve yeniden gönderim kargosu tamamen Satıcı'ya aittir; dilersen değişim, dilersen tam iade yapılır. 6502 sayılı Kanun'daki seçimlik hakların saklıdır.</li>
</ul>

<h2>Satıcı</h2>
{$seller_block}

<h2>Yasal dayanak</h2>
<p>Bu sayfa 6502 sayılı Tüketicinin Korunması Hakkında Kanun ve Mesafeli Sözleşmeler Yönetmeliği'ne dayanır; ayrıntılı hükümler için <a href="/mesafeli-satis-sozlesmesi/">Mesafeli Satış Sözleşmesi</a>'ne bakabilirsin.</p>
</div>
HTML;

/* ───────────────────────── 4. KVKK Aydınlatma Metni ───────────────────────── */
$kvkk = <<<HTML
<div class="content-intro">
<p class="eyebrow">6698 sayılı KVKK</p>
<p class="content-lead">Müşterilerimizin ve site ziyaretçilerimizin kişisel verilerini hangi amaçla, hangi hukuki sebeple işlediğimizi ve haklarını bu metinde açıklıyoruz.</p>
</div>
<div class="legal-body">
{$updated}
<p>Bu aydınlatma metni, 6698 sayılı Kişisel Verilerin Korunması Kanunu'nun ("KVKK") 10. maddesi ve Aydınlatma Yükümlülüğünün Yerine Getirilmesinde Uyulacak Usul ve Esaslar Hakkında Tebliğ uyarınca, veri sorumlusu sıfatıyla {$unvan} ("{$brand_h}") tarafından hazırlanmıştır.</p>

<h2>1. Veri sorumlusu</h2>
{$seller_block}

<h2>2. İşlediğimiz kişisel veriler</h2>
<table class="legal-table">
<tr><th>Kategori</th><th>Veriler</th><th>Kaynak</th></tr>
<tr><td>Kimlik</td><td>Ad, soyad; fatura için gerekliyse T.C. kimlik no / vergi no</td><td>Sipariş ve hesap formları</td></tr>
<tr><td>İletişim</td><td>E-posta, telefon, teslimat ve fatura adresi</td><td>Sipariş, hesap ve iletişim formları</td></tr>
<tr><td>Müşteri işlem</td><td>Sipariş içeriği ve geçmişi, seçilen bedenler, sepet, kupon kullanımı, iade ve değişim kayıtları, ürün değerlendirmeleri, talepler</td><td>Site kullanımı, e-posta ve telefon yazışmaları</td></tr>
<tr><td>Finans</td><td>Ödeme yöntemi ve tutarı, Havale / EFT gönderen bilgisi, iadelerde IBAN</td><td>Ödeme ve iade süreçleri</td></tr>
<tr><td>İşlem güvenliği</td><td>IP adresi, tarayıcı ve cihaz bilgisi, oturum kayıtları, sözleşme onay zamanı</td><td>Sunucu ve site kayıtları</td></tr>
<tr><td>Pazarlama</td><td>Bültene ya da stok bildirimine kaydolduysan e-posta adresin, izin tarihi ve izin kaynağı</td><td>Bülten / stok bildirimi formları (açık rıza)</td></tr>
</table>

<h2>3. İşleme amaçları</h2>
<ul>
<li>Siparişin alınması, ödemenin teyidi, ürünün hazırlanıp teslim edilmesi ve fatura düzenlenmesi,</li>
<li>Hesap oluşturma, sipariş takibi, iptal, iade, değişim ve satış sonrası destek süreçlerinin yürütülmesi,</li>
<li>Mesafeli satış sözleşmesi ve ön bilgilendirme onaylarının kayıt altına alınması,</li>
<li>Vergi, tüketici ve e-ticaret mevzuatından doğan yükümlülüklerin yerine getirilmesi,</li>
<li>Site güvenliğinin sağlanması, kötüye kullanımın önlenmesi ve hataların giderilmesi,</li>
<li>Talep etmen hâlinde: ödeme hatırlatması, değerlendirme isteği ve "stoğa gelince haber ver" bildirimlerinin gönderilmesi,</li>
<li>Açık rızan varsa: kampanya ve yeni koleksiyon duyurularının e-posta ile gönderilmesi.</li>
</ul>

<h2>4. Hukuki sebepler</h2>
<ul>
<li>KVKK m.5/2-c: Sözleşmenin kurulması ve ifası (sipariş, teslimat, iade),</li>
<li>KVKK m.5/2-ç: Hukuki yükümlülüğün yerine getirilmesi (fatura ve kayıtların saklanması),</li>
<li>KVKK m.5/2-e: Bir hakkın tesisi, kullanılması veya korunması (uyuşmazlık ve şikâyet süreçleri),</li>
<li>KVKK m.5/2-f: Meşru menfaat (site güvenliği, hizmet kalitesinin ölçülmesi),</li>
<li>KVKK m.5/1: Açık rıza (e-posta bülteni ve ticari elektronik ileti).</li>
</ul>

<h2>5. Kişisel verilerin aktarılması</h2>
<p>Verilerin, yalnızca yukarıdaki amaçlarla sınırlı olarak ve gerektiği ölçüde aşağıdaki alıcı gruplarına aktarılabilir:</p>
<ul>
<li>Kargo ve lojistik firmaları (ad, adres, telefon — teslimat ve iade için),</li>
<li>Bankalar (ödeme ve iade işlemleri için),</li>
<li>Mali müşavir ve muhasebe / e-fatura hizmet sağlayıcıları (fatura ve yasal kayıtlar için),</li>
<li>Barındırma, e-posta ve güvenlik altyapısı sağlayıcıları,</li>
<li>Yetkili kamu kurum ve kuruluşları, mahkemeler ve hakem heyetleri (yasal talep hâlinde).</li>
</ul>
<p>Hizmet sağlayıcılarımızın sunucularının yurt dışında bulunması hâlinde aktarım yalnızca KVKK m.9'daki şartlara uyularak yapılır.</p>

<h2>6. Toplama yöntemi</h2>
<p>Kişisel verilerin; site üzerindeki sipariş, hesap, iletişim, bülten ve stok bildirimi formları, ödeme adımı, e-posta ve telefon yazışmaları, çerezler ve sunucu kayıtları aracılığıyla elektronik ortamda toplanır.</p>

<h2>7. Saklama süresi</h2>
<p>Verilerin, işleme amacının gerektirdiği süre boyunca ve her hâlükârda ilgili mevzuatın (vergi, ticaret ve tüketici mevzuatı) öngördüğü yasal saklama süreleri boyunca saklanır; sürenin sonunda silinir, yok edilir veya anonim hâle getirilir. Bülten kaydın, aboneliği sonlandırdığında listeden çıkarılır.</p>

<h2>8. KVKK m.11 kapsamındaki hakların</h2>
<ul>
<li>Kişisel verilerinin işlenip işlenmediğini öğrenme ve işlenmişse bilgi talep etme,</li>
<li>İşlenme amacını ve amaca uygun kullanılıp kullanılmadığını öğrenme,</li>
<li>Yurt içinde veya yurt dışında aktarıldığı üçüncü kişileri bilme,</li>
<li>Eksik veya yanlış işlenmişse düzeltilmesini isteme,</li>
<li>KVKK m.7'deki şartlar çerçevesinde silinmesini veya yok edilmesini isteme,</li>
<li>Düzeltme, silme ve yok etme işlemlerinin aktarıldığı üçüncü kişilere bildirilmesini isteme,</li>
<li>Münhasıran otomatik sistemlerle analiz edilmesi sonucu aleyhine bir sonucun ortaya çıkmasına itiraz etme,</li>
<li>Kanuna aykırı işleme nedeniyle zarara uğraması hâlinde zararın giderilmesini talep etme.</li>
</ul>

<h2>9. Başvuru</h2>
<p>Haklarını kullanmak için başvurunu; ad-soyad, T.C. kimlik no (yabancılar için uyruk ve pasaport no), tebligat adresi, iletişim bilgilerin ve talebinle birlikte yazılı olarak {$adres} adresine iletebilir ya da sistemimizde kayıtlı e-posta adresinden {$mail} adresine "KVKK Başvurusu" konusuyla gönderebilirsin. Başvurular en geç 30 gün içinde ücretsiz olarak sonuçlandırılır; işlemin ayrıca bir maliyet gerektirmesi hâlinde Kişisel Verileri Koruma Kurulu'nca belirlenen tarife uygulanabilir.</p>
</div>
HTML;

/* ───────────────────────── 5. Gizlilik Politikası ───────────────────────── */
$gizlilik = <<<HTML
<div class="content-intro">
<p class="eyebrow">Verilerine saygı</p>
<p class="content-lead">Kişisel verilerini yalnızca alışverişini tamamlamak ve sana hizmet vermek için gereken ölçüde, şeffaf ve güvenli biçimde işleriz. Bu sayfa bir özet; ayrıntılar <a href="/kvkk-aydinlatma-metni/">KVKK Aydınlatma Metni</a>'nde.</p>
</div>
<div class="legal-body">
{$updated}

<h2>1. Kimiz?</h2>
<p>{$site} adresindeki mağaza {$unvan} tarafından {$brand_h} markasıyla işletilir. Kişisel verilerinle ilgili her sorunda {$kanal} üzerinden bize ulaşabilirsin.</p>

<h2>2. Hangi verileri, neden topluyoruz?</h2>
<ul>
<li><strong>Hesap ve sipariş:</strong> Ad-soyad, adres, e-posta ve telefonunu siparişini hazırlayıp teslim etmek, faturanı düzenlemek ve sipariş takibini sağlamak için kullanırız.</li>
<li><strong>Ödeme:</strong> Ödemeler Havale / EFT ile alınır; sitemizde kart bilgisi istenmez ve saklanmaz. İadelerde IBAN'ını yalnızca iade için kullanırız.</li>
<li><strong>İletişim:</strong> Bize yazdığında ya da aradığında mesajını ve iletişim bilgilerini talebini çözmek için saklarız.</li>
<li><strong>Bülten:</strong> Kampanya ve yeni koleksiyon duyuruları için e-posta adresini yalnızca sen kaydolursan kullanırız (MERHABA5 hoş geldin kodu bu kayıtla verilir). Her e-postadaki bağlantıyla abonelikten çıkabilirsin.</li>
<li><strong>Stok bildirimi:</strong> Tükenen bir ürün ya da beden için "Haber ver" dediğinde e-postanı yalnızca o ürün stoğa girdiğinde tek bir bildirim göndermek için kullanırız.</li>
<li><strong>Teknik:</strong> IP adresi, tarayıcı ve oturum bilgileri site güvenliği ve hata giderme için kaydedilir.</li>
</ul>

<h2>3. Çerezler</h2>
<p>Sepetin, oturumun ve ödeme adımın için zorunlu çerezleri kullanırız. Ayrıntılar <a href="/cerez-politikasi/">Çerez Politikası</a>'nda.</p>

<h2>4. Verilerini kimlerle paylaşıyoruz?</h2>
<p>Yalnızca hizmeti sunmak için gereken iş ortaklarıyla: kargo firması, bankalar, mali müşavir, barındırma ve e-posta altyapısı sağlayıcıları. Verilerini hiçbir koşulda satmayız, reklam amacıyla üçüncü kişilere vermeyiz.</p>

<h2>5. Güvenlik</h2>
<p>Site SSL ile şifrelenir ve güncel yazılım altyapısıyla korunur. Hesabın için güçlü ve benzersiz bir şifre seçmeni öneririz.</p>

<h2>6. Ne kadar süre saklıyoruz?</h2>
<p>Sipariş ve fatura kayıtlarını mevzuatın öngördüğü yasal süreler boyunca, hesap bilgilerini hesabını silene kadar, bülten kaydını abonelikten çıkana kadar saklarız. Süresi dolan veriler silinir veya anonimleştirilir.</p>

<h2>7. Hakların</h2>
<p>Verilerine erişme, düzeltme, silme ve işlemeye itiraz etme hakların var. Başvuru yöntemleri <a href="/kvkk-aydinlatma-metni/">KVKK Aydınlatma Metni</a>'nde yer alır; başvurunu en geç 30 gün içinde yanıtlarız.</p>

<h2>8. Çocuklar</h2>
<p>Mağazamızda alışveriş için 18 yaşını doldurmuş olmak gerekir; bilerek çocuklardan veri toplamayız. Bir çocuğa ait veri paylaşıldığını fark edersek sileriz.</p>

<h2>9. Değişiklikler</h2>
<p>Bu politikayı güncellediğimizde yeni sürümü bu sayfada yayımlarız; önemli değişikliklerde hesap sahiplerini e-posta ile bilgilendiririz.</p>
</div>
HTML;

/* ───────────────────────── 6. Çerez Politikası ───────────────────────── */
$cerez = <<<HTML
<div class="content-intro">
<p class="eyebrow">Çerezler hakkında</p>
<p class="content-lead">Sitenin çalışması için gereken çerezleri kullanırız. Hangi çerezin ne işe yaradığını ve tercihini nasıl yöneteceğini burada bulacaksın.</p>
</div>
<div class="legal-body">
{$updated}

<p>Bu politika, {$site} adresindeki mağazayı işleten {$unvan} ("{$brand_h}") tarafından, sitede kullanılan çerezler hakkında seni bilgilendirmek için hazırlanmıştır.</p>

<h2>1. Çerez nedir?</h2>
<p>Çerez, ziyaret ettiğin sitenin tarayıcına kaydettiği küçük bir metin dosyasıdır. Oturumunu sürdürmek, sepetini hatırlamak ve tercihlerini saklamak için kullanılır. Benzer amaçla tarayıcının yerel depolaması (localStorage) da kullanılabilir; bu politika onları da kapsar.</p>

<h2>2. Çerez türleri</h2>
<ul>
<li><strong>Süresine göre:</strong> oturum çerezleri tarayıcı kapanınca silinir; kalıcı çerezler belirtilen süre boyunca kalır.</li>
<li><strong>Kaynağına göre:</strong> birinci taraf çerezleri {$site} tarafından, üçüncü taraf çerezleri iş ortaklarımız tarafından yerleştirilir.</li>
<li><strong>Amacına göre:</strong> zorunlu, işlevsel, analitik ve pazarlama çerezleri.</li>
</ul>

<h2>3. Kullandığımız çerezler</h2>
<table class="legal-table">
<tr><th>Çerez</th><th>Süre</th><th>Amaç</th><th>Kategori</th></tr>
<tr><td>woocommerce_cart_hash, woocommerce_items_in_cart</td><td>Oturum</td><td>Sepetindeki ürünleri hatırlar</td><td>Zorunlu</td></tr>
<tr><td>wp_woocommerce_session_*</td><td>2 gün</td><td>Sepet ve ödeme oturumunu sürdürür</td><td>Zorunlu</td></tr>
<tr><td>wordpress_logged_in_*, wordpress_sec_*</td><td>Oturum / 14 gün</td><td>Hesabına giriş yaptığında oturumunu güvenli tutar</td><td>Zorunlu</td></tr>
<tr><td>wordpress_test_cookie</td><td>Oturum</td><td>Tarayıcının çerezleri kabul ettiğini doğrular</td><td>Zorunlu</td></tr>
</table>
<p>Şu anda sitemizde analitik veya pazarlama (reklam) çerezi kullanılmamaktadır. İleride bu tür çerezler eklenirse, yalnızca açık iznin alındıktan sonra çalıştırılır ve bu tablo güncellenir.</p>

<h2>4. Çerezleri nasıl yönetirsin?</h2>
<p>Tarayıcı ayarlarından çerezleri silebilir veya engelleyebilirsin: Chrome (Ayarlar → Gizlilik ve güvenlik → Çerezler), Safari (Ayarlar → Gizlilik), Firefox (Ayarlar → Gizlilik ve Güvenlik), Edge (Ayarlar → Çerezler ve site izinleri). Zorunlu çerezleri engellersen sepet, hesap ve ödeme adımları çalışmayabilir.</p>

<h2>5. Hukuki dayanak</h2>
<p>Zorunlu çerezler, sözleşmenin kurulması ve ifası ile meşru menfaat hukuki sebeplerine dayanır ve izin gerektirmez (KVKK m.5/2-c ve f). Analitik ve pazarlama çerezleri kullanılması hâlinde açık rızana dayanır (KVKK m.5/1); rızanı istediğin zaman geri alabilirsin.</p>

<h2>6. İletişim</h2>
<p>Çerezlerle ilgili soruların için {$kanal} üzerinden bize ulaşabilirsin. Kişisel verilerinle ilgili hakların için <a href="/kvkk-aydinlatma-metni/">KVKK Aydınlatma Metni</a>'ne bakabilirsin.</p>
</div>
HTML;

/* ───────────────────────── Uygula ───────────────────────── */
kinetik_demo_legal_page('kvkk-aydinlatma-metni', 'KVKK Aydınlatma Metni', $kvkk);
kinetik_demo_legal_page('gizlilik-politikasi', 'Gizlilik Politikası', $gizlilik);
kinetik_demo_legal_page('cerez-politikasi', 'Çerez Politikası', $cerez);
kinetik_demo_legal_page('mesafeli-satis-sozlesmesi', 'Mesafeli Satış Sözleşmesi', $mesafeli);
kinetik_demo_legal_page('on-bilgilendirme-formu', 'Ön Bilgilendirme Formu', $onbilgi);
kinetik_demo_legal_page('iptal-ve-iade-kosullari', 'İptal ve İade Koşulları', $iade);

$priv = get_page_by_path('gizlilik-politikasi', OBJECT, 'page');
if ($priv) update_option('wp_page_for_privacy_policy', $priv->ID);
$terms = get_page_by_path('mesafeli-satis-sozlesmesi', OBJECT, 'page');
if ($terms) update_option('woocommerce_terms_page_id', $terms->ID);

$eksik = [];
foreach (['eposta' => 'E-posta', 'vergi_dairesi' => 'Vergi dairesi', 'vergi_no' => 'Vergi no', 'mersis' => 'MERSİS'] as $k => $l) {
    if (trim((string) ($s[$k] ?? '')) === '') $eksik[] = $l;
}
echo "Tamamlandı. Kargo {$kargo}, ücretsiz kargo eşiği {$esik}.\n";
if ($eksik) echo 'Bekleyen künye alanları (sayfalarda pending-field): ' . implode(', ', $eksik) . "\n";
