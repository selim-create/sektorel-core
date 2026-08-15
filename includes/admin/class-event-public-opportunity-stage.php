<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Public support / application opportunity engine.
 *
 * Public opportunities are not statutory obligations and therefore do not use
 * the Official Calendar semantics. Verified calls are materialized directly as
 * draft Events with their own managed identity and provenance. Past calls stay
 * in the provider catalogue for traceability but are never materialized as
 * current Events.
 */
class Sektorel_Event_Public_Opportunity_Stage {

    const NONCE_ACTION = 'sektorel_public_opportunities';
    const QUEUE_TTL    = 2 * HOUR_IN_SECONDS;
    const BATCH_SIZE   = 10;
    const VERSION      = '1500';

    const KOSGEB_AI_SOURCE = 'https://www.kosgeb.gov.tr/site/tr/genel/detay/9427/kosgebden-teknoloji-girisimlerine-yapay-zek-kredi-programi';
    const KOSGEB_APPLICATION = 'https://edevlet.kosgeb.gov.tr/';
    const ISKUR_2026_4_SOURCE = 'https://www.iskur.gov.tr/duyurular/2026-4-donemi-engelli-ve-eski-hukumlu-proje-basvurulari-basladi/';

    public static function init() {
        add_action( 'wp_ajax_sektorel_public_opportunities_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_public_opportunities_batch', array( __CLASS__, 'ajax_batch' ) );
    }

    public static function provider_registry() {
        return array(
            'kosgeb' => array(
                'key'        => 'kosgeb',
                'name'       => 'KOSGEB',
                'source_url' => 'https://www.kosgeb.gov.tr/',
            ),
            'iskur' => array(
                'key'        => 'iskur',
                'name'       => 'Türkiye İş Kurumu (İŞKUR)',
                'source_url' => 'https://www.iskur.gov.tr/',
            ),
        );
    }

    /**
     * Verified provider catalogue.
     *
     * The catalogue may contain expired rows so provider support remains
     * explicit and auditable. occurrences() filters them by the requested year
     * and today's date before the Source Center queue is created.
     */
    public static function catalogue() {
        return array(
            self::row(
                'kosgeb_ai_credit_2026',
                'KOSGEB Yapay Zekâ Kredi Programı — Son Başvuru',
                '2026-07-09',
                '2026-12-31',
                'kosgeb',
                'KOSGEB',
                'credit_support',
                array( 'technology_startup', 'technogirisim_badge_holder', 'kosgeb_registered_sme' ),
                'Teknoloji girişimlerinin yapay zekâ yatırımlarına erişimini kolaylaştırmak amacıyla KOSGEB tarafından yürütülen kredi programının başvuru dönemidir. Başvurular KOSGEB KOBİ Bilgi Sistemi üzerinden alınır.',
                self::KOSGEB_AI_SOURCE,
                self::KOSGEB_APPLICATION,
                '500.000 – 5.000.000 TL',
                'verified_kosgeb_2026_call'
            ),
            self::row(
                'iskur_disabled_exconvict_2026_4',
                'İŞKUR 2026/4 Engelli ve Eski Hükümlü Proje Başvuruları — Son Başvuru',
                '2026-07-01',
                '2026-07-10',
                'iskur',
                'Türkiye İş Kurumu (İŞKUR)',
                'grant_call',
                array( 'disabled_entrepreneur', 'ex_convict_entrepreneur', 'protected_workplace_project' ),
                'İŞKUR tarafından engelli ve eski hükümlülerin kendi işini kurmasına ve ilgili proje türlerine yönelik 2026/4 dönem çağrısıdır. Bu satır geçmiş çağrı olarak katalogda tutulur; süresi dolduğu için güncel Event üretilmez.',
                self::ISKUR_2026_4_SOURCE,
                self::ISKUR_2026_4_SOURCE,
                '550.000 TL’ye kadar',
                'verified_iskur_2026_4_call'
            ),
        );
    }

    public static function active_count_by_provider( $provider_key ) {
        $count = 0;
        foreach ( self::occurrences( (int) current_time( 'Y' ) ) as $row ) {
            if ( $provider_key === $row['provider'] ) {
                $count++;
            }
        }
        return $count;
    }

    public static function ajax_prepare() {
        self::require_ajax();

        $year = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : (int) current_time( 'Y' );
        if ( $year < 2026 || $year > ( (int) current_time( 'Y' ) + 1 ) ) {
            wp_send_json_error( array( 'message' => 'Kamu fırsatları yılı geçersiz.' ) );
        }

        $rows  = self::occurrences( $year );
        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient(
            self::queue_key( get_current_user_id(), $token ),
            array( 'year' => $year, 'rows' => array_values( $rows ) ),
            self::QUEUE_TTL
        );

        wp_send_json_success( array(
            'token' => $token,
            'total' => count( $rows ),
        ) );
    }

    public static function ajax_batch() {
        self::require_ajax();

        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
        if ( ! $token ) {
            wp_send_json_error( array( 'message' => 'Kamu fırsatları kuyruk anahtarı eksik.' ) );
        }

        $key   = self::queue_key( get_current_user_id(), $token );
        $queue = get_transient( $key );
        if ( ! is_array( $queue ) || ! isset( $queue['rows'] ) || ! is_array( $queue['rows'] ) ) {
            wp_send_json_error( array( 'message' => 'Kamu fırsatları kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $rows      = array_values( $queue['rows'] );
        $batch     = array_slice( $rows, $offset, self::BATCH_SIZE );
        $created   = 0;
        $updated   = 0;
        $unchanged = 0;
        $skipped   = 0;
        $error     = 0;
        $messages  = array();

        foreach ( $batch as $row ) {
            $result = self::upsert_occurrence( $row );
            $label  = isset( $row['title'] ) ? $row['title'] : 'Kamu desteği / son başvuru';

            if ( is_wp_error( $result ) ) {
                if ( 'public_opportunity_not_managed' === $result->get_error_code() ) {
                    $skipped++;
                    $messages[] = 'Atlandı: ' . $label . ' — ' . $result->get_error_message();
                } else {
                    $error++;
                    $messages[] = 'Hata: ' . $label . ' — ' . $result->get_error_message();
                }
                continue;
            }

            if ( 'created' === $result['status'] ) {
                $created++;
                $messages[] = 'Yeni kamu fırsatı taslağı: ' . $label . ' → Event #' . absint( $result['event_id'] );
            } elseif ( 'updated' === $result['status'] ) {
                $updated++;
                $messages[] = 'Kamu fırsatı güncellendi: ' . $label . ' → Event #' . absint( $result['event_id'] );
            } else {
                $unchanged++;
            }
        }

        $next = min( count( $rows ), $offset + count( $batch ) );
        $done = $next >= count( $rows );
        if ( $done ) {
            delete_transient( $key );
        } else {
            set_transient( $key, $queue, self::QUEUE_TTL );
        }

        wp_send_json_success( array(
            'next_offset' => $next,
            'done'        => $done,
            'created'     => $created,
            'updated'     => $updated,
            'unchanged'   => $unchanged,
            'skipped'     => $skipped,
            'error'       => $error,
            'messages'    => $messages,
        ) );
    }

    public static function occurrences( $year ) {
        $today = current_time( 'Y-m-d' );
        $seen  = array();
        $rows  = array();

        foreach ( self::catalogue() as $row ) {
            if ( empty( $row['application_deadline'] ) || empty( $row['occurrence_key'] ) ) {
                continue;
            }
            if ( (int) substr( $row['application_deadline'], 0, 4 ) !== (int) $year ) {
                continue;
            }
            if ( $row['application_deadline'] < $today ) {
                continue;
            }
            $key = sanitize_key( $row['occurrence_key'] );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $row['status'] = $row['application_start'] > $today ? 'upcoming' : 'open';
            $rows[] = $row;
        }

        usort( $rows, static function ( $a, $b ) {
            if ( $a['application_deadline'] === $b['application_deadline'] ) {
                return strcmp( $a['occurrence_key'], $b['occurrence_key'] );
            }
            return strcmp( $a['application_deadline'], $b['application_deadline'] );
        } );

        return $rows;
    }

    private static function row( $occurrence_key, $title, $application_start, $application_deadline, $provider, $provider_name, $kind, $audience, $description, $source_url, $application_url, $amount, $date_basis ) {
        return array(
            'occurrence_key'       => sanitize_key( $occurrence_key ),
            'title'                => sanitize_text_field( $title ),
            'application_start'    => sanitize_text_field( $application_start ),
            'application_deadline' => sanitize_text_field( $application_deadline ),
            'provider'             => sanitize_key( $provider ),
            'provider_name'        => sanitize_text_field( $provider_name ),
            'kind'                 => sanitize_key( $kind ),
            'audience'             => array_values( array_unique( array_map( 'sanitize_key', (array) $audience ) ) ),
            'description'          => sanitize_textarea_field( $description ),
            'source_url'           => esc_url_raw( $source_url, array( 'http', 'https' ) ),
            'application_url'      => esc_url_raw( $application_url, array( 'http', 'https' ) ),
            'amount'               => sanitize_text_field( $amount ),
            'date_basis'           => sanitize_key( $date_basis ),
        );
    }

    private static function upsert_occurrence( $row ) {
        foreach ( array( 'occurrence_key', 'title', 'application_start', 'application_deadline', 'provider', 'provider_name', 'source_url', 'application_url' ) as $required ) {
            if ( empty( $row[ $required ] ) ) {
                return new WP_Error( 'public_opportunity_row_incomplete', 'Kamu fırsatı satırında zorunlu alan eksik: ' . $required );
            }
        }
        if ( ! self::valid_date( $row['application_start'] ) || ! self::valid_date( $row['application_deadline'] ) ) {
            return new WP_Error( 'public_opportunity_date_invalid', 'Kamu fırsatı başvuru tarihi geçersiz.' );
        }
        if ( $row['application_deadline'] < $row['application_start'] ) {
            return new WP_Error( 'public_opportunity_range_invalid', 'Kamu fırsatı son başvuru tarihi başlangıç tarihinden önce olamaz.' );
        }

        $lookup = self::find_event_id( $row['occurrence_key'] );
        if ( is_wp_error( $lookup ) ) {
            return $lookup;
        }

        $event_id = absint( $lookup );
        $created  = false;
        if ( ! $event_id ) {
            $event_id = wp_insert_post( array(
                'post_type'    => 'event',
                'post_status'  => 'draft',
                'post_title'   => $row['title'],
                'post_content' => $row['description'],
            ), true );
            if ( is_wp_error( $event_id ) ) {
                return $event_id;
            }
            $event_id = absint( $event_id );
            $created  = true;
        } elseif ( '1' !== (string) get_post_meta( $event_id, 'opportunity_managed', true ) ) {
            return new WP_Error( 'public_opportunity_not_managed', 'Aynı occurrence anahtarındaki Event kamu fırsatı motoru tarafından yönetilmiyor.' );
        }

        $changed = false;
        $post    = get_post( $event_id );
        if ( $post && ( $post->post_title !== $row['title'] || $post->post_content !== $row['description'] ) ) {
            $updated = wp_update_post( array(
                'ID'           => $event_id,
                'post_title'   => $row['title'],
                'post_content' => $row['description'],
            ), true );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
            $changed = true;
        }

        $meta = array(
            'is_public_opportunity'             => '1',
            'event_type'                        => 'diger',
            'start_date'                        => $row['application_start'] . 'T00:00',
            'end_date'                          => $row['application_deadline'] . 'T23:59',
            'location_type'                     => 'online',
            'organizer'                         => $row['provider_name'],
            'event_url'                         => $row['source_url'],
            'registration_link'                 => $row['application_url'],
            'source_url'                        => $row['source_url'],
            'opportunity_provider'              => $row['provider'],
            'opportunity_provider_name'         => $row['provider_name'],
            'opportunity_kind'                  => $row['kind'],
            'opportunity_status'                => $row['status'],
            'opportunity_audience'              => $row['audience'],
            'opportunity_application_start'     => $row['application_start'],
            'opportunity_application_deadline'  => $row['application_deadline'],
            'opportunity_source_url'            => $row['source_url'],
            'opportunity_application_url'       => $row['application_url'],
            'opportunity_amount'                => $row['amount'],
            'opportunity_occurrence_key'        => $row['occurrence_key'],
            'opportunity_date_basis'            => $row['date_basis'],
            'opportunity_managed'               => '1',
            'opportunity_engine_version'        => self::VERSION,
            'opportunity_is_deadline'           => '1',
        );

        foreach ( $meta as $key => $value ) {
            $old = get_post_meta( $event_id, $key, true );
            if ( wp_json_encode( $old ) !== wp_json_encode( $value ) ) {
                update_post_meta( $event_id, $key, $value );
                $changed = true;
            }
        }

        self::store_evidence( $event_id, $row );
        if ( class_exists( 'Sektorel_Event_Data_Health' ) ) {
            Sektorel_Event_Data_Health::assess_event( $event_id );
        }

        return array(
            'event_id' => $event_id,
            'status'   => $created ? 'created' : ( $changed ? 'updated' : 'unchanged' ),
        );
    }

    private static function find_event_id( $occurrence_key ) {
        $ids = get_posts( array(
            'post_type'      => 'event',
            'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
            'posts_per_page' => 2,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array(
                    'key'   => 'opportunity_occurrence_key',
                    'value' => sanitize_key( $occurrence_key ),
                ),
            ),
        ) );

        if ( count( $ids ) > 1 ) {
            return new WP_Error( 'public_opportunity_duplicate_key', 'Aynı kamu fırsatı occurrence anahtarıyla birden fazla Event bulundu; duplicate oluşturulmadı.' );
        }

        return $ids ? absint( $ids[0] ) : 0;
    }

    private static function store_evidence( $event_id, $row ) {
        $evidence = get_post_meta( $event_id, 'event_source_evidence', true );
        $evidence = is_array( $evidence ) ? $evidence : array();
        $kept     = array();

        foreach ( $evidence as $entry ) {
            if ( ! is_array( $entry ) || ( isset( $entry['opportunity_occurrence_key'] ) && $entry['opportunity_occurrence_key'] === $row['occurrence_key'] ) ) {
                continue;
            }
            $kept[] = $entry;
        }

        $kept[] = array(
            'source_id'                  => 0,
            'source_name'                => $row['provider_name'],
            'source_url'                 => $row['source_url'],
            'parser_type'                => 'public_opportunity',
            'last_seen_at'               => current_time( 'mysql' ),
            'opportunity_occurrence_key' => $row['occurrence_key'],
            'values'                     => array(
                'start_date'        => $row['application_start'] . 'T00:00',
                'end_date'          => $row['application_deadline'] . 'T23:59',
                'organizer'         => $row['provider_name'],
                'event_url'         => $row['source_url'],
                'registration_link' => $row['application_url'],
            ),
        );

        update_post_meta( $event_id, 'event_source_evidence', array_values( $kept ) );
        update_post_meta( $event_id, 'event_source_evidence_count', count( $kept ) );
    }

    private static function valid_date( $date ) {
        return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date );
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_public_opportunity_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }
}
