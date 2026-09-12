<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Canonical editorial architecture for Sektörel Ajanda.
 *
 * Public category slugs stay stable in v2 to avoid breaking existing URLs.
 * Display names and editorial roles evolve while legacy source metadata remains
 * compatible. New content gets exactly one primary editorial desk; cross-cutting
 * dimensions live in normalized payload fields instead of extra categories.
 */
class Sektorel_Content_Editorial_Architecture {

    const SCHEMA_VERSION = 2;
    const MIGRATION_VERSION = 2;
    const OPTION_KEY = 'sektorel_content_editorial_architecture_version';
    const NOTICE_KEY = 'sektorel_content_editorial_architecture_notice';

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }

        add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ), 30 );
        add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
    }

    /**
     * Nine primary editorial desks.
     *
     * Slugs intentionally preserve the first-generation URLs. The architecture
     * key/name is the canonical editorial concept; a future URL migration can be
     * performed separately with explicit redirects instead of silently here.
     */
    public static function primary_categories() {
        return array(
            'sirketler-yatirimlar' => array(
                'key'             => 'companies',
                'name'            => 'Şirketler',
                'description'     => 'Şirket yatırımları, büyüme, yeni tesisler, kapasite artışları, satın almalar, ortaklıklar ve önemli kurumsal gelişmeler.',
                'seo_title'       => 'Şirket Haberleri ve Yatırımlar | Sektörel Ajanda',
                'seo_description' => 'Türkiye’de şirket yatırımları, yeni tesisler, büyüme, satın almalar, ortaklıklar ve önemli kurumsal gelişmeleri takip edin.',
                'focus_keyword'   => 'şirket haberleri',
            ),
            'sanayi-uretim' => array(
                'key'             => 'industry',
                'name'            => 'Sanayi & Üretim',
                'description'     => 'Fabrikalar, üretim, imalat, kapasite, makine, otomasyon, OSB gündemi, verimlilik ve reel sektördeki gelişmeler.',
                'seo_title'       => 'Sanayi ve Üretim Haberleri | Sektörel Ajanda',
                'seo_description' => 'Fabrika, imalat, üretim, kapasite, otomasyon, OSB ve reel sektördeki yatırım ve üretim gelişmelerini takip edin.',
                'focus_keyword'   => 'sanayi üretim haberleri',
            ),
            'kobi-girisimcilik' => array(
                'key'             => 'sme-entrepreneurship',
                'name'            => 'KOBİ & Girişim',
                'description'     => 'KOBİ büyümesi, girişimciler, yeni iş modelleri, franchise, ölçeklenme, startup ekosistemi ve işletme fırsatları.',
                'seo_title'       => 'KOBİ ve Girişim Haberleri | Sektörel Ajanda',
                'seo_description' => 'KOBİ büyümesi, girişimcilik, yeni iş modelleri, franchise, startup ekosistemi ve işletmelere yönelik fırsatları takip edin.',
                'focus_keyword'   => 'KOBİ haberleri',
            ),
            'teknoloji-dijital-donusum' => array(
                'key'             => 'technology',
                'name'            => 'Teknoloji & Dijital Dönüşüm',
                'description'     => 'Yapay zekâ, yazılım, ERP, bulut, siber güvenlik, e-ticaret, Ar-Ge, otomasyon ve şirketlerin dijital dönüşümü.',
                'seo_title'       => 'Teknoloji ve Dijital Dönüşüm Haberleri | Sektörel Ajanda',
                'seo_description' => 'Yapay zekâ, yazılım, e-ticaret, Ar-Ge, siber güvenlik ve şirketlerin dijital dönüşüm gündemindeki gelişmeleri takip edin.',
                'focus_keyword'   => 'dijital dönüşüm haberleri',
            ),
            'finans-bankacilik' => array(
                'key'             => 'finance',
                'name'            => 'Finansman',
                'description'     => 'KOBİ ve şirket finansmanı, krediler, faktoring, leasing, bankacılık, ödeme sistemleri, fintech ve risk yönetimi.',
                'seo_title'       => 'Şirket ve KOBİ Finansmanı Haberleri | Sektörel Ajanda',
                'seo_description' => 'Kredi, faktoring, leasing, bankacılık, ödeme sistemleri, fintech ve şirket finansmanındaki güncel gelişmeleri takip edin.',
                'focus_keyword'   => 'KOBİ finansmanı',
            ),
            'dis-ticaret-ihracat' => array(
                'key'             => 'exports-trade',
                'name'            => 'İhracat & Dış Ticaret',
                'description'     => 'İhracat, e-ihracat, ithalat, dış pazarlar, gümrük, lojistik, ticaret anlaşmaları ve şirketlerin küresel ticaret gündemi.',
                'seo_title'       => 'İhracat ve Dış Ticaret Haberleri | Sektörel Ajanda',
                'seo_description' => 'İhracat, e-ihracat, dış pazarlar, gümrük, ithalat, lojistik ve küresel ticaret gündemindeki gelişmeleri takip edin.',
                'focus_keyword'   => 'ihracat haberleri',
            ),
            'mevzuat-tesvikler' => array(
                'key'             => 'incentives-regulation',
                'name'            => 'Teşvik & Mevzuat',
                'description'     => 'KOSGEB ve kamu destekleri, hibeler, yatırım teşvikleri, vergi ve iş dünyasını etkileyen yeni mevzuat ve düzenlemeler.',
                'seo_title'       => 'Teşvik, Destek ve Mevzuat Haberleri | Sektörel Ajanda',
                'seo_description' => 'KOSGEB destekleri, hibeler, yatırım teşvikleri, yönetmelikler ve iş dünyasını etkileyen mevzuat değişikliklerini takip edin.',
                'focus_keyword'   => 'yatırım teşvikleri',
            ),
            'istihdam-insan-kaynaklari' => array(
                'key'             => 'people-management',
                'name'            => 'İnsan & Yönetim',
                'description'     => 'İnsan kaynakları, istihdam, liderlik, yönetim, iş kültürü, yetenek yönetimi ve üst düzey yönetici atamaları.',
                'seo_title'       => 'İnsan Kaynakları ve Yönetim Haberleri | Sektörel Ajanda',
                'seo_description' => 'İnsan kaynakları, istihdam, liderlik, iş kültürü, yetenek yönetimi ve üst düzey yönetici atamalarını takip edin.',
                'focus_keyword'   => 'insan kaynakları haberleri',
            ),
            'ekonomi-piyasalar' => array(
                'key'             => 'economy-markets',
                'name'            => 'Ekonomi & Piyasalar',
                'description'     => 'Şirketleri ve üreticileri doğrudan etkileyen faiz, enflasyon, kur, büyüme, rezervler ve temel makroekonomik gelişmeler.',
                'seo_title'       => 'Ekonomi ve Piyasa Haberleri | Sektörel Ajanda',
                'seo_description' => 'Faiz, enflasyon, kur, büyüme ve iş dünyasını doğrudan etkileyen temel ekonomi ve piyasa gelişmelerini takip edin.',
                'focus_keyword'   => 'ekonomi haberleri',
            ),
        );
    }

    public static function primary_slugs() {
        return array_keys( self::primary_categories() );
    }

    public static function is_primary_slug( $slug ) {
        return in_array( sanitize_title( $slug ), self::primary_slugs(), true );
    }

    /**
     * Legacy category kept for URL/content continuity but removed from the
     * primary-desk pool. It becomes a cross-cutting topic hub in v2.
     */
    public static function deprecated_categories() {
        return array(
            'enerji-surdurulebilirlik' => array(
                'role'   => 'topic_hub',
                'reason' => 'Enerji ve sürdürülebilirlik v2 ile çapraz konu boyutudur; yeni içerikte tek başına primary desk olarak kullanılmaz.',
            ),
        );
    }

    public static function source_category_slugs( $slugs ) {
        $result = array();
        foreach ( (array) $slugs as $slug ) {
            $slug = sanitize_title( $slug );
            if ( self::is_primary_slug( $slug ) && ! in_array( $slug, $result, true ) ) {
                $result[] = $slug;
            }
        }
        return $result;
    }

    /** Weighted deterministic mapper; AI is not used for pre-triage routing. */
    public static function primary_category_for_text( $text, $fallback_slugs = array() ) {
        $text = self::normalize_text( $text );
        $scores = array();

        foreach ( self::weighted_keyword_map() as $slug => $keywords ) {
            $score = 0;
            foreach ( $keywords as $keyword => $weight ) {
                $needle = self::normalize_text( $keyword );
                if ( '' !== $needle && false !== mb_strpos( $text, $needle ) ) {
                    $score += max( 1, (int) $weight );
                }
            }
            if ( $score > 0 ) {
                $scores[ $slug ] = $score;
            }
        }

        if ( $scores ) {
            $priority = array_flip( self::primary_slugs() );
            uksort( $scores, static function( $a, $b ) use ( $scores, $priority ) {
                if ( $scores[ $a ] === $scores[ $b ] ) {
                    return ( $priority[ $a ] ?? PHP_INT_MAX ) <=> ( $priority[ $b ] ?? PHP_INT_MAX );
                }
                return $scores[ $b ] <=> $scores[ $a ];
            } );
            return (string) array_key_first( $scores );
        }

        $fallbacks = self::source_category_slugs( $fallback_slugs );
        return $fallbacks ? (string) $fallbacks[0] : '';
    }

    public static function topic_tags_for_text( $text ) {
        $text = self::normalize_text( $text );
        $tags = array();

        foreach ( self::topic_keyword_map() as $tag => $keywords ) {
            foreach ( $keywords as $keyword ) {
                $needle = self::normalize_text( $keyword );
                if ( '' !== $needle && false !== mb_strpos( $text, $needle ) ) {
                    $tags[] = sanitize_title( $tag );
                    break;
                }
            }
        }

        return array_values( array_unique( $tags ) );
    }

    public static function content_format_for_text( $text ) {
        $text = self::normalize_text( $text );
        $formats = array(
            'interview' => array( 'roportaj', 'soylesi', 'soru cevap' ),
            'research'  => array( 'arastirma', 'rapor', 'endeks', 'analiz dosyasi' ),
            'guide'     => array( 'rehber', 'nasil yapilir', 'nasil basvurulur' ),
        );

        foreach ( $formats as $format => $needles ) {
            foreach ( $needles as $needle ) {
                if ( false !== mb_strpos( $text, self::normalize_text( $needle ) ) ) {
                    return $format;
                }
            }
        }
        return 'news';
    }

    /** Ensure every candidate can evolve without another database migration. */
    public static function normalize_dimensions( $payload, $primary_category, $topic_tags, $content_format ) {
        $payload = is_array( $payload ) ? $payload : array();
        $primary_category = sanitize_title( $primary_category );

        $payload['schema_version'] = self::SCHEMA_VERSION;
        $payload['primary_category'] = $primary_category;
        // Backward compatibility for the existing AI processor contract.
        $payload['suggested_category_slugs'] = $primary_category ? array( $primary_category ) : array();
        $payload['topic_tags'] = array_values( array_unique( array_filter( array_map( 'sanitize_title', (array) $topic_tags ) ) ) );
        $payload['sector_terms'] = isset( $payload['sector_terms'] ) && is_array( $payload['sector_terms'] ) ? array_values( $payload['sector_terms'] ) : array();
        $payload['location_terms'] = isset( $payload['location_terms'] ) && is_array( $payload['location_terms'] ) ? array_values( $payload['location_terms'] ) : array();
        $payload['company_entities'] = isset( $payload['company_entities'] ) && is_array( $payload['company_entities'] ) ? array_values( $payload['company_entities'] ) : array();
        $payload['person_entities'] = isset( $payload['person_entities'] ) && is_array( $payload['person_entities'] ) ? array_values( $payload['person_entities'] ) : array();
        $payload['content_format'] = sanitize_key( $content_format ?: 'news' );

        return $payload;
    }

    public static function maybe_migrate() {
        if ( ! current_user_can( 'manage_categories' ) ) {
            return;
        }
        if ( (int) get_option( self::OPTION_KEY, 0 ) >= self::MIGRATION_VERSION ) {
            return;
        }

        $renamed = 0;
        $created = 0;
        $deprecated = 0;
        $reset_candidates = 0;
        $errors = array();

        foreach ( self::primary_categories() as $slug => $definition ) {
            $term = get_term_by( 'slug', $slug, 'category' );
            $term_created = false;

            if ( ! $term ) {
                $inserted = wp_insert_term( $definition['name'], 'category', array(
                    'slug'        => $slug,
                    'description' => $definition['description'],
                ) );
                if ( is_wp_error( $inserted ) ) {
                    $errors[] = $definition['name'] . ': ' . $inserted->get_error_message();
                    continue;
                }
                $term = get_term( (int) $inserted['term_id'], 'category' );
                $term_created = true;
                $created++;
            }

            if ( ! $term || is_wp_error( $term ) ) {
                $errors[] = $definition['name'] . ': kategori okunamadı.';
                continue;
            }

            $term_id = (int) $term->term_id;
            $managed = '1' === (string) get_term_meta( $term_id, '_sektorel_content_category_managed', true );
            if ( $managed && $term->name !== $definition['name'] ) {
                $updated = wp_update_term( $term_id, 'category', array( 'name' => $definition['name'] ) );
                if ( is_wp_error( $updated ) ) {
                    $errors[] = $definition['name'] . ': ' . $updated->get_error_message();
                    continue;
                }
                $renamed++;
            }

            if ( '' === trim( (string) $term->description ) ) {
                wp_update_term( $term_id, 'category', array( 'description' => $definition['description'] ) );
            }

            if ( $managed || $term_created ) {
                update_term_meta( $term_id, 'rank_math_title', $definition['seo_title'] );
                update_term_meta( $term_id, 'rank_math_description', $definition['seo_description'] );
                update_term_meta( $term_id, 'rank_math_focus_keyword', $definition['focus_keyword'] );
                update_term_meta( $term_id, 'rank_math_facebook_title', $definition['seo_title'] );
                update_term_meta( $term_id, 'rank_math_facebook_description', $definition['seo_description'] );
                update_term_meta( $term_id, 'rank_math_twitter_use_facebook', '1' );
            }

            update_term_meta( $term_id, '_sektorel_content_category_managed', '1' );
            update_term_meta( $term_id, '_sektorel_editorial_desk', '1' );
            update_term_meta( $term_id, '_sektorel_editorial_key', $definition['key'] );
            update_term_meta( $term_id, '_sektorel_editorial_architecture_version', self::MIGRATION_VERSION );
            delete_term_meta( $term_id, '_sektorel_content_category_deprecated' );
            delete_term_meta( $term_id, '_sektorel_content_category_role' );
        }

        foreach ( self::deprecated_categories() as $slug => $definition ) {
            $term = get_term_by( 'slug', $slug, 'category' );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }
            update_term_meta( (int) $term->term_id, '_sektorel_content_category_deprecated', '1' );
            update_term_meta( (int) $term->term_id, '_sektorel_content_category_role', sanitize_key( $definition['role'] ) );
            update_term_meta( (int) $term->term_id, '_sektorel_content_category_deprecation_reason', $definition['reason'] );
            update_term_meta( (int) $term->term_id, '_sektorel_editorial_architecture_version', self::MIGRATION_VERSION );
            delete_term_meta( (int) $term->term_id, '_sektorel_editorial_desk' );
            $deprecated++;
        }

        // Prevent old triage-v3 ready candidates from bypassing the v2 mapper.
        // Processed and duplicate records are intentionally untouched.
        if ( class_exists( 'Sektorel_Content_Candidates' ) ) {
            global $wpdb;
            $table = Sektorel_Content_Candidates::table_name();
            $updated = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table}
                     SET status = %s, updated_at = %s
                     WHERE status IN ( %s, %s )
                       AND draft_post_id = 0
                       AND ai_status IN ( 'pending', 'error' )",
                    Sektorel_Content_Candidates::STATUS_NEW,
                    current_time( 'mysql', true ),
                    Sektorel_Content_Candidates::STATUS_READY,
                    Sektorel_Content_Candidates::STATUS_REVIEW
                )
            );
            if ( false === $updated ) {
                $errors[] = 'Candidate v2 yeniden değerlendirme kuyruğu hazırlanamadı: ' . ( $wpdb->last_error ?: 'veritabanı hatası' );
            } else {
                $reset_candidates = (int) $updated;
            }
        }

        if ( ! $errors ) {
            update_option( self::OPTION_KEY, self::MIGRATION_VERSION, false );
        }

        set_transient( self::NOTICE_KEY, array(
            'created'          => $created,
            'renamed'          => $renamed,
            'deprecated'       => $deprecated,
            'reset_candidates' => $reset_candidates,
            'errors'           => $errors,
        ), MINUTE_IN_SECONDS );
    }

    public static function render_notice() {
        if ( ! current_user_can( 'manage_categories' ) ) {
            return;
        }
        $notice = get_transient( self::NOTICE_KEY );
        if ( ! is_array( $notice ) ) {
            return;
        }
        delete_transient( self::NOTICE_KEY );

        $errors = isset( $notice['errors'] ) && is_array( $notice['errors'] ) ? $notice['errors'] : array();
        $class = $errors ? 'notice notice-warning' : 'notice notice-success is-dismissible';
        printf(
            '<div class="%1$s"><p><strong>Sektörel Ajanda Editorial Architecture v2:</strong> %2$d kategori oluşturuldu, %3$d desk adı güncellendi, %4$d legacy kategori topic-hub olarak işaretlendi, %5$d candidate v2 triage için kuyruğa alındı.%6$s</p></div>',
            esc_attr( $class ),
            (int) ( $notice['created'] ?? 0 ),
            (int) ( $notice['renamed'] ?? 0 ),
            (int) ( $notice['deprecated'] ?? 0 ),
            (int) ( $notice['reset_candidates'] ?? 0 ),
            $errors ? ' Hatalar: ' . esc_html( implode( ' | ', $errors ) ) : ''
        );
    }

    private static function weighted_keyword_map() {
        return array(
            'sirketler-yatirimlar' => array(
                'satın alma' => 5, 'birleşme' => 5, 'yeni tesis' => 5, 'şirket yatırımı' => 5,
                'kapasite artışı' => 4, 'ortaklık' => 4, 'yatırım kararı' => 4, 'sermaye artırımı' => 3,
                'şirket' => 1,
            ),
            'sanayi-uretim' => array(
                'fabrika' => 5, 'imalat' => 4, 'osb' => 4, 'organize sanayi' => 4, 'üretim' => 3,
                'sanayi' => 3, 'kapasite' => 2, 'otomasyon' => 2, 'robot' => 2, 'karbonsuzlaşma' => 2,
                'enerji verimliliği' => 2,
            ),
            'kobi-girisimcilik' => array(
                'kobi' => 6, 'küçük ve orta' => 6, 'girişimcilik' => 4, 'girişimci' => 4,
                'startup' => 4, 'franchise' => 3, 'ölçeklenme' => 3,
            ),
            'teknoloji-dijital-donusum' => array(
                'yapay zeka' => 6, 'yapay zekâ' => 6, 'dijital dönüşüm' => 5, 'siber güvenlik' => 5,
                'e-ticaret' => 4, 'yazılım' => 4, 'bulut' => 3, 'erp' => 3, 'ar-ge' => 3, 'arge' => 3,
                'teknoloji' => 2,
            ),
            'finans-bankacilik' => array(
                'faktoring' => 6, 'leasing' => 6, 'finansman' => 5, 'kredi' => 4, 'ödeme sistemi' => 4,
                'fintech' => 4, 'bankacılık' => 3, 'mevduat' => 3, 'banka' => 1,
            ),
            'dis-ticaret-ihracat' => array(
                'ihracat' => 6, 'e-ihracat' => 6, 'dış ticaret' => 6, 'gümrük' => 5, 'ihracatçı' => 5,
                'eurochambres' => 5, 'dış pazar' => 4, 'ithalat' => 3, 'ticaret odaları' => 3,
                'anti damping' => 3, 'damping' => 3,
            ),
            'mevzuat-tesvikler' => array(
                'kosgeb' => 6, 'teşvik' => 6, 'hibe' => 5, 'destek programı' => 5, 'resmi gazete' => 5,
                'yönetmelik' => 4, 'tebliğ' => 4, 'mevzuat' => 4, 'regülasyon' => 3, 'düzenleme' => 2,
            ),
            'istihdam-insan-kaynaklari' => array(
                'insan kaynakları' => 6, 'istihdam' => 5, 'işe alım' => 5, 'yetenek yönetimi' => 5,
                'ceo atandı' => 5, 'genel müdür atandı' => 5, 'yönetici atama' => 5, 'işgücü' => 4,
                'çalışan' => 2, 'ücret' => 2, 'liderlik' => 2,
            ),
            'ekonomi-piyasalar' => array(
                'para politikası' => 6, 'politika faizi' => 6, 'enflasyon' => 6, 'gsyh' => 5,
                'cari açık' => 5, 'rezerv' => 4, 'büyüme' => 4, 'döviz' => 3, 'faiz' => 3,
                'kur' => 2, 'piyasa' => 1, 'tcmb' => 1,
            ),
        );
    }

    private static function topic_keyword_map() {
        return array(
            'yapay-zeka' => array( 'yapay zeka', 'yapay zekâ', 'artificial intelligence' ),
            'e-ticaret' => array( 'e-ticaret', 'pazaryeri', 'online satış' ),
            'enerji-surdurulebilirlik' => array( 'enerji', 'sürdürülebilir', 'karbon', 'emisyon', 'yenilenebilir', 'ges', 'eudr', 'skdm', 'karbonsuzlaşma', 'mavi su' ),
            'osb' => array( 'osb', 'organize sanayi bölgesi', 'organize sanayi bölgeleri' ),
            'otomasyon-robotik' => array( 'otomasyon', 'robotik', 'robot yatırımı', 'endüstri 4.0' ),
            'fintech-odeme' => array( 'fintech', 'ödeme sistemi', 'ödeme teknolojileri' ),
            'e-ihracat' => array( 'e-ihracat', 'mikro ihracat' ),
        );
    }

    private static function normalize_text( $text ) {
        $text = remove_accents( wp_strip_all_tags( (string) $text ) );
        $text = mb_strtolower( $text, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', $text ) );
    }
}
