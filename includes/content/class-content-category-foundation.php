<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provisions the editorial category foundation used by the Content Source Engine.
 *
 * Safety rules:
 * - Existing categories are never deleted or renamed.
 * - Existing non-empty descriptions / Rank Math values are never overwritten.
 * - Only newly-created categories receive the initial noindex,follow policy.
 * - The seed is versioned and idempotent.
 */
class Sektorel_Content_Category_Foundation {

    private const SEED_VERSION = 1;
    private const OPTION_KEY = 'sektorel_content_category_seed_version';
    private const NOTICE_KEY = 'sektorel_content_category_seed_notice';

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }

        add_action( 'admin_init', array( __CLASS__, 'maybe_seed' ) );
        add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
    }

    public static function maybe_seed() {
        if ( ! current_user_can( 'manage_categories' ) ) {
            return;
        }

        if ( (int) get_option( self::OPTION_KEY, 0 ) >= self::SEED_VERSION ) {
            return;
        }

        $created = 0;
        $updated = 0;
        $errors = array();

        foreach ( self::definitions() as $definition ) {
            $result = self::seed_category( $definition );

            if ( is_wp_error( $result ) ) {
                $errors[] = sprintf(
                    '%s: %s',
                    $definition['name'],
                    $result->get_error_message()
                );
                continue;
            }

            if ( 'created' === $result ) {
                $created++;
            } elseif ( 'updated' === $result ) {
                $updated++;
            }
        }

        if ( empty( $errors ) ) {
            update_option( self::OPTION_KEY, self::SEED_VERSION, false );
        }

        set_transient(
            self::NOTICE_KEY,
            array(
                'created' => $created,
                'updated' => $updated,
                'errors'  => $errors,
            ),
            MINUTE_IN_SECONDS
        );
    }

    private static function seed_category( $definition ) {
        $term = get_term_by( 'slug', $definition['slug'], 'category' );
        $created = false;

        if ( ! $term ) {
            $inserted = wp_insert_term(
                $definition['name'],
                'category',
                array(
                    'slug'        => $definition['slug'],
                    'description' => $definition['description'],
                )
            );

            if ( is_wp_error( $inserted ) ) {
                return $inserted;
            }

            $term = get_term( (int) $inserted['term_id'], 'category' );
            $created = true;
        }

        if ( ! $term || is_wp_error( $term ) ) {
            return new WP_Error( 'sektorel_content_category_missing', 'Kategori oluşturulduktan sonra okunamadı.' );
        }

        $changed = false;
        $term_id = (int) $term->term_id;

        if ( ! $created && '' === trim( (string) $term->description ) ) {
            $description_update = wp_update_term(
                $term_id,
                'category',
                array( 'description' => $definition['description'] )
            );

            if ( is_wp_error( $description_update ) ) {
                return $description_update;
            }

            $changed = true;
        }

        $seo_meta = array(
            'rank_math_title'                => $definition['seo_title'],
            'rank_math_description'          => $definition['seo_description'],
            'rank_math_focus_keyword'        => $definition['focus_keyword'],
            'rank_math_facebook_title'       => $definition['seo_title'],
            'rank_math_facebook_description' => $definition['seo_description'],
            'rank_math_twitter_use_facebook' => '1',
        );

        foreach ( $seo_meta as $meta_key => $meta_value ) {
            if ( '' !== trim( (string) get_term_meta( $term_id, $meta_key, true ) ) ) {
                continue;
            }

            update_term_meta( $term_id, $meta_key, $meta_value );
            $changed = true;
        }

        if ( $created && '' === trim( (string) get_term_meta( $term_id, 'rank_math_robots', true ) ) ) {
            update_term_meta( $term_id, 'rank_math_robots', array( 'noindex', 'follow' ) );
            $changed = true;
        }

        update_term_meta( $term_id, '_sektorel_content_category_managed', '1' );
        update_term_meta( $term_id, '_sektorel_content_category_seed_version', self::SEED_VERSION );

        if ( $created ) {
            return 'created';
        }

        return $changed ? 'updated' : 'unchanged';
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
        $class = empty( $errors ) ? 'notice notice-success is-dismissible' : 'notice notice-warning';

        printf(
            '<div class="%1$s"><p><strong>Sektörel Ajanda içerik kategorileri:</strong> %2$d oluşturuldu, %3$d mevcut kategori güvenli biçimde tamamlandı.%4$s</p></div>',
            esc_attr( $class ),
            isset( $notice['created'] ) ? (int) $notice['created'] : 0,
            isset( $notice['updated'] ) ? (int) $notice['updated'] : 0,
            empty( $errors ) ? '' : ' Hatalar: ' . esc_html( implode( ' | ', $errors ) )
        );
    }

    private static function definitions() {
        return array(
            array(
                'name'            => 'Ekonomi & Piyasalar',
                'slug'            => 'ekonomi-piyasalar',
                'description'     => 'Türkiye ve küresel ekonomide büyüme, enflasyon, faiz, döviz, piyasa göstergeleri ve iş dünyasını etkileyen makroekonomik gelişmeler.',
                'seo_title'       => 'Ekonomi ve Piyasa Haberleri | Sektörel Ajanda',
                'seo_description' => 'Ekonomi, enflasyon, faiz, döviz ve piyasa göstergeleriyle iş dünyasını etkileyen güncel gelişmeleri Sektörel Ajanda’da takip edin.',
                'focus_keyword'   => 'ekonomi haberleri',
            ),
            array(
                'name'            => 'Finans & Bankacılık',
                'slug'            => 'finans-bankacilik',
                'description'     => 'Bankacılık, finansman, ödeme sistemleri, fintech, kredi piyasası, TCMB ve BDDK düzenlemeleri ile finans sektöründeki gelişmeler.',
                'seo_title'       => 'Finans ve Bankacılık Haberleri | Sektörel Ajanda',
                'seo_description' => 'Bankacılık, finansman, fintech, ödeme sistemleri, kredi piyasası ve finans sektöründeki güncel düzenleme ve gelişmeleri takip edin.',
                'focus_keyword'   => 'finans bankacılık haberleri',
            ),
            array(
                'name'            => 'Şirketler & Yatırımlar',
                'slug'            => 'sirketler-yatirimlar',
                'description'     => 'Şirket yatırımları, satın almalar, ortaklıklar, kapasite artışları, yeni tesisler, finansman turları ve önemli kurumsal gelişmeler.',
                'seo_title'       => 'Şirket ve Yatırım Haberleri | Sektörel Ajanda',
                'seo_description' => 'Şirket yatırımları, yeni tesisler, satın almalar, ortaklıklar, finansman turları ve önemli kurumsal gelişmeleri takip edin.',
                'focus_keyword'   => 'şirket yatırım haberleri',
            ),
            array(
                'name'            => 'Sanayi & Üretim',
                'slug'            => 'sanayi-uretim',
                'description'     => 'İmalat sanayisi, üretim, kapasite, sanayi bölgeleri, üretim teknolojileri ve reel sektördeki güncel gelişmeler.',
                'seo_title'       => 'Sanayi ve Üretim Haberleri | Sektörel Ajanda',
                'seo_description' => 'İmalat sanayisi, üretim, kapasite, sanayi bölgeleri ve reel sektördeki güncel yatırım ve üretim gelişmelerini takip edin.',
                'focus_keyword'   => 'sanayi üretim haberleri',
            ),
            array(
                'name'            => 'Dış Ticaret & İhracat',
                'slug'            => 'dis-ticaret-ihracat',
                'description'     => 'İhracat, ithalat, dış ticaret verileri, pazarlar, gümrük, e-ihracat ve Türk şirketlerinin küresel ticaret gündemi.',
                'seo_title'       => 'Dış Ticaret ve İhracat Haberleri | Sektörel Ajanda',
                'seo_description' => 'İhracat, ithalat, dış ticaret verileri, yeni pazarlar, gümrük ve e-ihracat gündemindeki güncel gelişmeleri takip edin.',
                'focus_keyword'   => 'ihracat haberleri',
            ),
            array(
                'name'            => 'Teknoloji & Dijital Dönüşüm',
                'slug'            => 'teknoloji-dijital-donusum',
                'description'     => 'Yapay zekâ, yazılım, teknoloji yatırımları, Ar-Ge, dijital dönüşüm, fintech ve iş dünyasını değiştiren yeni teknolojiler.',
                'seo_title'       => 'Teknoloji ve Dijital Dönüşüm Haberleri | Sektörel Ajanda',
                'seo_description' => 'Yapay zekâ, Ar-Ge, yazılım, teknoloji yatırımları ve şirketlerin dijital dönüşüm gündemindeki güncel gelişmeleri takip edin.',
                'focus_keyword'   => 'dijital dönüşüm haberleri',
            ),
            array(
                'name'            => 'KOBİ & Girişimcilik',
                'slug'            => 'kobi-girisimcilik',
                'description'     => 'KOBİ destekleri, girişimcilik, startup ekosistemi, fonlama, büyüme programları ve işletmelere yönelik yeni fırsatlar.',
                'seo_title'       => 'KOBİ ve Girişimcilik Haberleri | Sektörel Ajanda',
                'seo_description' => 'KOBİ destekleri, girişimcilik, startup ekosistemi, fonlama programları ve işletmelere yönelik yeni fırsatları takip edin.',
                'focus_keyword'   => 'KOBİ girişimcilik haberleri',
            ),
            array(
                'name'            => 'Enerji & Sürdürülebilirlik',
                'slug'            => 'enerji-surdurulebilirlik',
                'description'     => 'Elektrik, doğal gaz, yenilenebilir enerji, enerji piyasaları, iklim, karbon, yeşil dönüşüm ve sürdürülebilirlik gündemi.',
                'seo_title'       => 'Enerji ve Sürdürülebilirlik Haberleri | Sektörel Ajanda',
                'seo_description' => 'Enerji piyasaları, yenilenebilir enerji, karbon, iklim ve şirketlerin yeşil dönüşüm gündemindeki gelişmeleri takip edin.',
                'focus_keyword'   => 'enerji sürdürülebilirlik haberleri',
            ),
            array(
                'name'            => 'Mevzuat & Teşvikler',
                'slug'            => 'mevzuat-tesvikler',
                'description'     => 'İş dünyasını etkileyen yeni mevzuat, yönetmelik, tebliğ, kurul kararları, kamu destekleri, hibeler ve yatırım teşvikleri.',
                'seo_title'       => 'Mevzuat ve Teşvik Haberleri | Sektörel Ajanda',
                'seo_description' => 'İş dünyasını etkileyen mevzuat, yönetmelik, tebliğ, kamu destekleri, hibeler ve yatırım teşviklerindeki değişiklikleri takip edin.',
                'focus_keyword'   => 'mevzuat teşvik haberleri',
            ),
            array(
                'name'            => 'İstihdam & İnsan Kaynakları',
                'slug'            => 'istihdam-insan-kaynaklari',
                'description'     => 'İstihdam verileri, çalışma hayatı, işveren düzenlemeleri, ücretler, yetenek yönetimi ve insan kaynakları gündemi.',
                'seo_title'       => 'İstihdam ve İnsan Kaynakları Haberleri | Sektörel Ajanda',
                'seo_description' => 'İstihdam, çalışma hayatı, işveren düzenlemeleri, ücretler ve insan kaynakları gündemindeki güncel gelişmeleri takip edin.',
                'focus_keyword'   => 'istihdam insan kaynakları haberleri',
            ),
        );
    }
}
