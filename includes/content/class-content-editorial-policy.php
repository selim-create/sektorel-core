<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Injects the Sektörel Ajanda house style into the bounded Content AI request.
 *
 * Kept outside the processor so editorial policy can evolve independently from
 * queue/idempotency logic. The filter is scoped to the Content Engine AJAX call
 * and the OpenAI Responses endpoint only.
 */
class Sektorel_Content_Editorial_Policy {

    private static $booted = false;

    public static function init() {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;
        add_filter( 'http_request_args', array( __CLASS__, 'apply_house_style' ), 20, 2 );
    }

    public static function apply_house_style( $args, $url ) {
        if ( 'https://api.openai.com/v1/responses' !== (string) $url ) {
            return $args;
        }

        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        $allowed_actions = array(
            'sektorel_content_ai_draft_batch',
            'sektorel_content_ai_reprocess_tobb_drafts',
        );
        if ( ! in_array( $action, $allowed_actions, true ) ) {
            return $args;
        }

        if ( empty( $args['body'] ) || ! is_string( $args['body'] ) ) {
            return $args;
        }

        $body = json_decode( $args['body'], true );
        if ( ! is_array( $body ) || empty( $body['input'] ) || ! is_string( $body['input'] ) ) {
            return $args;
        }

        $policy = <<<'POLICY'

SEKTÖREL AJANDA EDİTORYAL STANDARDI — BU KURALLAR ÖNCEKİ TALİMATLARI TAMAMLAR:
- Çıktı bir kurum bülteni özeti değil, Sektörel Ajanda'da yayımlanabilecek gerçek bir iş dünyası/sektör haberi gibi okunmalı.
- İlk paragraf haberi doğrudan vermeli: ne oldu, kim yaptı/açıkladı, temel karar veya gelişme nedir. Törensel ve bürokratik girişlerden kaçın.
- Bilgileri kaynak metindeki önem sırasına göre yeniden kurgula; kaynak paragraf sırasını mekanik biçimde takip etme.
- "gerçekleştirildi", "katılım sağlandı", "değerlendirmelerde bulunuldu" gibi basın bülteni kalıplarını gerektiğinde sade haber Türkçesine çevir.
- Kaynak yeterince ayrıntılıysa 3-7 paragraf ve anlamlı olduğunda 1-3 h2 kullan. Kaynak kısaysa sırf uzunluk için tekrar, dolgu veya genel bilgi üretme.
- Haberin sektör, şirketler veya iş dünyası açısından önemini yalnız kaynakta bunu destekleyen somut bilgi varsa belirt. Kaynak dışı analiz, tahmin, piyasa etkisi veya yorum ekleme.
- İsimleri, unvanları, rakamları, tarihleri, kurumları ve teknik düzenleme adlarını kaynakla birebir doğrulanabilir tut.
- Alıntı işaretiyle yeni cümle üretme. Kaynakta doğrudan alıntı yoksa dolaylı anlatım kullan.
- Başlık doğal, kısa, haber değeri taşıyan ve clickbait olmayan bir Türkçe başlık olmalı. Kurumun başlığını aynen kopyalamak zorunda değilsin.
- excerpt tek başına okunduğunda haberi özetleyen 1-2 cümle olsun; başlığın tekrarı olmasın.
- tags yalnız gerçekten ayırt edici kurum, sektör, düzenleme veya konu terimlerinden oluşsun; "toplantı", "gündem", "haber" gibi düşük değerli etiketleri kullanma.
- focus_keyword doğal bir arama niyetini temsil eden kısa ifade olsun; salt kurum adı seçme, konu + kurum/sektör birleşimi gerekiyorsa kullan.
- image_query Pexels fallback'i için İNGİLİZCE, 2-5 kelimelik, somut ve görsel olarak temsil edilebilir bir arama sorgusu olsun. Kişi adı, kurum adı, şehir, yıl, etkinlik adı ve soyut "business/news" kelimelerinden kaçın. Örn. paper manufacturing factory, central bank currency, industrial recycling plant.
- Kaynak metni çok kısa ise bunu saklama: kısa ama eksiksiz bir haber üret; uydurarak uzatma.
POLICY;

        $body['input'] .= $policy;
        $args['body'] = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        return $args;
    }
}
