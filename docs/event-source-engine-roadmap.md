# Etkinlik Kaynak Motoru Yol Haritası

Sektörel Ajanda'nın etkinlik envanterini düzenli, doğrulanabilir ve sürdürülebilir şekilde güncel tutmak için geliştirilecek kaynak tarama ve aday etkinlik yönetim sistemi.

## Hedef

Admin kullanıcısı yüzlerce etkinlik kaynağını tek tek manuel gezmek yerine kaynakları sistemden kontrol eder. Sistem yeni/değişmiş etkinlikleri aday havuzuna alır; admin tek tıkla etkinliği oluşturur, günceller veya yok sayar.

## Faz ES-1 — Kaynak Kataloğu ve Yönetim Paneli

- `event_source` private CPT / yönetim modeli.
- Kaynak adı, URL/domain, tür, aktif/pasif, parser tipi, son kontrol, son sonuç, hata bilgisi.
- WordPress admin altında `Ajanda > Etkinlik Kaynakları` ekranı.
- Kaynak ekle/düzenle/pasifleştir.
- Excel kaynak listesinin toplu içe aktarımı için güvenli batch endpoint.
- Kaynak bazlı `Şimdi Kontrol Et` aksiyonu.

**Bitiş kriteri:** Kaynak listesi WordPress'ten yönetilebiliyor ve listedeki kaynaklar batch olarak sisteme aktarılabiliyor.

## Faz ES-2 — Tarama Kuyruğu

- `Tüm Kaynakları Kontrol Et` aksiyonu.
- Aynı HTTP isteğinde yüzlerce kaynağı taramak yerine batch/cron kuyruğu.
- Kaynak başına durum: `bekliyor`, `çalışıyor`, `tamamlandı`, `hata`.
- Son kontrol zamanı, bulunan kayıt sayısı ve hata özeti.
- Sunucu yükü ve timeout koruması.

**Bitiş kriteri:** Admin tüm aktif kaynakları tek aksiyonla kuyruğa alabiliyor; süreç ilerleme ve hata durumuyla izlenebiliyor.

## Faz ES-3 — Generic Etkinlik Keşfi

Öncelik sırası:

1. JSON-LD `schema.org/Event`
2. RSS / XML
3. ICS / iCalendar
4. HTML event/listing sayfaları
5. Kaynağa özel adapter

Normalize edilen aday alanları:

- title
- start_date / end_date
- event_type
- location_type
- venue
- address
- organizer
- registration_link
- source_url
- description
- sector/location adayları

**Bitiş kriteri:** Desteklenen kaynaklarda bulunan etkinlikler normalize edilerek aday havuzuna yazılabiliyor.

## Faz ES-4 — Aday Etkinlik Havuzu

- `event_candidate` private CPT / veri modeli.
- Durumlar: `new`, `existing`, `changed`, `incomplete`, `ignored`, `imported`, `error`.
- Admin liste ekranında kaynak, etkinlik adı, tarih, güven seviyesi, durum ve aksiyonlar.
- `Kaynağı Aç`, `Tek Tıkla Ekle`, `Güncelle`, `Yok Say`.
- Toplu seçili kayıtları ekleme.

**Bitiş kriteri:** Sistem bulduğu veriyi doğrudan yayına basmıyor; admin onaylı aday akışı çalışıyor.

## Faz ES-5 — Duplicate ve Değişiklik Tespiti

Birincil eşleştirme sinyalleri:

- normalize edilmiş başlık
- başlangıç tarihi
- source URL/domain
- kaynak tarafından sağlanan stabil id (varsa)

Durumlar:

- Yeni
- Zaten mevcut
- Değişmiş (tarih, mekan, kayıt linki vb.)
- Belirsiz / manuel kontrol

**Bitiş kriteri:** Aynı etkinlik tekrar oluşturulmuyor; kaynak değişiklikleri admin onayına düşüyor.

## Faz ES-6 — Excel Kaynak Listesinin Tam Entegrasyonu

- Kullanıcı tarafından sağlanan kaynak listesi sisteme aktarılır.
- URL'si eksik kaynaklar `kaynak_eksik` olarak işaretlenir.
- Aynı domaine bağlı birden fazla etkinlik serisi ayrı kaynak tanımı olarak korunur.
- İlk tarama sonrası kaynaklar `generic`, `adapter_gerekli`, `manuel` sınıflarına ayrılır.

**Bitiş kriteri:** Listedeki tüm kaynakların sistemde kayıt/durum karşılığı vardır.

## Faz ES-7 — Kaynağa Özel Adapterlar

Generic parser'ın yetersiz kaldığı yüksek değerli kaynaklara adapter yazılır.

İlk öncelik:

- büyük fuar organizatörleri
- resmî kurumlar
- düzenli webinar/etkinlik takvimleri
- bir sayfada birden fazla etkinlik yayınlayan kaynaklar

**Bitiş kriteri:** İlk yayın için yüksek değerli kaynakların büyük çoğunluğu otomatik kontrol edilebilir.

## Faz ES-8 — Otomatik Periyodik Kontrol

- Günlük / haftalık tarama periyodu.
- Yeni ve değişmiş etkinlikler için admin özeti.
- Kaynak hata oranı / uzun süredir güncellenmeyen kaynak uyarısı.
- Manuel `Tümünü Kontrol Et` aksiyonu korunur.

**Bitiş kriteri:** Etkinlik envanterinin güncelliği düzenli manuel site gezmeye bağlı değildir.

## İlk Beta İçin Zorunlu Kapsam

İlk kapalı beta öncesinde ES-1 — ES-6 tamamlanmalı. ES-7'de en değerli kaynaklar desteklenmeli. ES-8 beta sırasında devreye alınabilir.

## Güvenlik / Operasyon İlkeleri

- Kaynak verisi otomatik olarak publish edilmez.
- Tüm admin mutasyonlarında nonce + `manage_options` yetkisi.
- Dış HTTP çağrılarında timeout, redirect ve response-size sınırı.
- SSRF koruması: localhost/private ağ adresleri engellenir.
- Kuyruk batch işlenir; tek request'te yüzlerce kaynak çağrılmaz.
- Kaynak URL ve ham cevapların loglanmasında kişisel/sensitif veri tutulmaz.
- Import/güncelleme işlemleri tekrar çalıştırıldığında idempotent davranır.
