<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Official Calendar Phase 2.
 *
 * Adds narrowly scoped, deterministic regulatory deadlines from official
 * Turkish authorities. These rows bypass Candidate review because the
 * occurrence itself is a canonical statutory deadline. Everything is draft,
 * idempotent and provenance-backed.
 */
class Sektorel_Event_Official_Calendar_Phase2_Stage {

    const NONCE_ACTION = 'sektorel_official_calendar_phase2';
    const QUEUE_TTL    = 2 * HOUR_IN_SECONDS;
    const BATCH_SIZE   = 10;
    const VERSION      = '1490';

    const CSB_TABS_SOURCE = 'https://osmaniye.csb.gov.tr/2025-yili-atik-beyanlarinin-yapilmasi-duyuru-472721';
    const EPDK_PROGRESS_SOURCE = 'https://www.epdk.gov.tr/Detay/Icerik/5-16255/kurumumuza-31-ocak-2026-tarihine-kadar-sunulmasi-';
    const EPDK_LICENSE_SOURCE = 'https://www.epdk.gov.tr/Detay/Icerik/3-0-86-3/lisans-islemlerilisans-islemleri';
    const SPK_SOURCE = 'https://spk.gov.tr/sirketler/duzenlemeler-ve-surecler/borsa-disi-halka-acik-sirketlerin-yukumlulukleri';

    public static function init() {
        add_action( 'wp_ajax_sektorel_official_calendar_phase2_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_official_calendar_phase2_batch', array( __CLASS__, 'ajax_batch' ) );
    }

    public static function ajax_prepare() {
        self::require_ajax();
        $year = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : (int) current_time( 'Y' );
        if ( $year < 2026 || $year > ( (int) current_time( 'Y' ) + 1 ) ) {
            wp_send_json_error( array( 'message' => 'Resmî Takvim Faz 2 yılı geçersiz.' ) );
        }

        $rows  = self::occurrences( $year );
        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient(
            self::queue_key( get_current_user_id(), $token ),
            array( 'year' => $year, 'rows' => array_values( $rows ) ),
            self::QUEUE_TTL
        );

        wp_send_json_success( array( 'token' => $token, 'total' => count( $rows ) ) );
    }

    public static function ajax_batch() {
        self::require_ajax();
        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
        if ( ! $token ) {
            wp_send_json_error( array( 'message' => 'Resmî Takvim Faz 2 kuyruk anahtarı eksik.' ) );
        }

        $key   = self::queue_key( get_current_user_id(), $token );
        $queue = get_transient( $key );
        if ( ! is_array( $queue ) || ! isset( $queue['rows'] ) || ! is_array( $queue['rows'] ) ) {
            wp_send_json_error( array( 'message' => 'Resmî Takvim Faz 2 kuyruğu bulunamadı veya süresi doldu.' ) );
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
            $label  = isset( $row['title'] ) ? $row['title'] : 'Resmî yükümlülük';
            if ( is_wp_error( $result ) ) {
                $error++;
                $messages[] = 'Hata: ' . $label . ' — ' . $result->get_error_message();
                continue;
            }
            if ( 'created' === $result['status'] ) {
                $created++;
                $messages[] = 'Yeni resmî taslak: ' . $label . ' → Event #' . absint( $result['event_id'] );
            } elseif ( 'updated' === $result['status'] ) {
                $updated++;
                $messages[] = 'Resmî kayıt güncellendi: ' . $label . ' → Event #' . absint( $result['event_id'] );
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

    private static function occurrences( $year ) {
        $rows  = array_merge( self::environment_occurrences( $year ), self::epdk_occurrences( $year ), self::spk_occurrences( $year ) );
        $today = current_time( 'Y-m-d' );
        $max   = ( (int) $year + 1 ) . '-12-31';
        $seen  = array();
        $final = array();

        foreach ( $rows as $row ) {
            if ( empty( $row['due_date'] ) || empty( $row['occurrence_key'] ) || $row['due_date'] < $today || $row['due_date'] > $max ) {
                continue;
            }
            $key = sanitize_key( $row['occurrence_key'] );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $final[] = $row;
        }

        usort( $final, static function ( $a, $b ) {
            return $a['due_date'] === $b['due_date'] ? strcmp( $a['occurrence_key'], $b['occurrence_key'] ) : strcmp( $a['due_date'], $b['due_date'] );
        } );
        return $final;
    }

    private static function environment_occurrences( $year ) {
        $next = (int) $year + 1;
        return array(
            self::row(
                'csb_tabs_' . $year,
                $year . ' Yılı Atık Beyanı (TABS) Son Günü',
                $next . '-03-31',
                'T.C. Çevre, Şehircilik ve İklim Değişikliği Bakanlığı',
                'resmi_yukumluluk',
                array( 'waste_producer' ),
                'Tehlikeli ve tehlikesiz atık üreticilerinin bir önceki yıla ait atık beyanını Atık Beyan Sistemi (TABS) üzerinden Mart ayı sonuna kadar tamamlamasına ilişkin yıllık yükümlülüktür.',
                self::CSB_TABS_SOURCE,
                'csb_tabs_annual_waste_declaration',
                (string) $year,
                'csb_tabs_statutory_rule'
            ),
        );
    }

    private static function epdk_occurrences( $year ) {
        $rows = array();

        // January receives second-half progress data from the previous year;
        // July receives first-half progress data. The official rule is “within
        // January/July”; the final calendar day is materialized as the deadline.
        $rows[] = self::row(
            'epdk_progress_second_half_' . $year,
            $year . ' II. Yarıyıl EPDK Üretim Lisansı İlerleme Raporu Son Günü',
            ( (int) $year + 1 ) . '-01-31',
            'Enerji Piyasası Düzenleme Kurumu',
            'resmi_yukumluluk',
            array( 'epdk_generation_license_under_construction' ),
            'Üretim lisansı sahibi tüzel kişilerin tesis toplam kurulu gücünün tamamının kabulü yapılana kadar yılın ikinci yarısındaki gerçekleşmelere ilişkin ilerleme raporunu izleyen Ocak ayı içinde EBİS üzerinden sunma yükümlülüğüdür.',
            self::EPDK_PROGRESS_SOURCE,
            'epdk_generation_progress_report',
            $year . '-H2',
            'epdk_statutory_month_rule'
        );

        // 2026 exact remaining installment. Future years should be added only
        // after that year's official holiday/calendar context is verified.
        if ( 2026 === (int) $year ) {
            $rows[] = self::row(
                'epdk_license_fee_2026_3',
                '2026 EPDK Üretim Lisansı Yıllık Bedeli 3. Taksit Son Günü',
                '2026-10-07',
                'Enerji Piyasası Düzenleme Kurumu',
                'resmi_yukumluluk',
                array( 'epdk_generation_license_fee_payer' ),
                'Üretim lisansı yıllık lisans bedelinin Ekim ayının ilk beş iş günü içinde ödenmesi gereken üçüncü taksitine ilişkin son gündür. Mevzuattaki muafiyetler ayrıca dikkate alınmalıdır.',
                self::EPDK_LICENSE_SOURCE,
                'epdk_generation_annual_license_fee_installment',
                '2026-3',
                'verified_epdk_2026_business_days'
            );
        }

        return $rows;
    }

    private static function spk_occurrences( $year ) {
        $next = (int) $year + 1;
        return array(
            self::row(
                'spk_non_exchange_annual_report_' . $year,
                $year . ' Yıllık Finansal Rapor SPK Bildirim Son Günü',
                $next . '-03-31',
                'Sermaye Piyasası Kurulu',
                'resmi_yukumluluk',
                array( 'spk_public_company_non_exchange' ),
                'Payları borsada işlem görmeyen halka açık ortaklıkların yıllık finansal raporlarını ve varsa bağımsız denetim raporlarını genel kuruldan en az üç hafta önce ve her durumda hesap dönemi bitimini izleyen üçüncü ay sonuna kadar Kurula bildirmesine ilişkin yükümlülüktür.',
                self::SPK_SOURCE,
                'spk_non_exchange_annual_financial_report',
                (string) $year,
                'spk_statutory_rule'
            ),
        );
    }

    private static function row( $occurrence_key, $title, $due_date, $institution, $category, $applicability, $description, $source_url, $rule_key, $period, $date_basis ) {
        return array(
            'occurrence_key' => sanitize_key( $occurrence_key ),
            'title'          => sanitize_text_field( $title ),
            'due_date'       => sanitize_text_field( $due_date ),
            'institution'    => sanitize_text_field( $institution ),
            'category'       => sanitize_key( $category ),
            'applicability'  => array_values( array_unique( array_map( 'sanitize_key', (array) $applicability ) ) ),
            'description'    => sanitize_textarea_field( $description ),
            'source_url'     => esc_url_raw( $source_url, array( 'http', 'https' ) ),
            'rule_key'       => sanitize_key( $rule_key ),
            'period'         => sanitize_text_field( $period ),
            'date_basis'     => sanitize_key( $date_basis ),
        );
    }

    private static function upsert_occurrence( $row ) {
        foreach ( array( 'occurrence_key', 'title', 'due_date', 'institution', 'category', 'source_url', 'rule_key' ) as $required ) {
            if ( empty( $row[ $required ] ) ) {
                return new WP_Error( 'official_phase2_row_incomplete', 'Faz 2 resmî takvim satırında zorunlu alan eksik: ' . $required );
            }
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $row['due_date'] ) ) {
            return new WP_Error( 'official_phase2_due_date_invalid', 'Faz 2 resmî takvim tarihi geçersiz.' );
        }

        $lookup = self::find_event_id( $row['occurrence_key'] );
        if ( is_wp_error( $lookup ) ) {
            return $lookup;
        }
        $event_id = absint( $lookup );
        $created = false;

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
            $created = true;
        } elseif ( '1' !== (string) get_post_meta( $event_id, 'official_calendar_managed', true ) ) {
            return new WP_Error( 'official_phase2_not_managed', 'Aynı occurrence anahtarındaki Event otomatik yönetilen resmî kayıt değil.' );
        }

        $changed = false;
        $post = get_post( $event_id );
        if ( $post && ( $post->post_title !== $row['title'] || $post->post_content !== $row['description'] ) ) {
            $updated = wp_update_post( array( 'ID' => $event_id, 'post_title' => $row['title'], 'post_content' => $row['description'] ), true );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
            $changed = true;
        }

        $date_value = $row['due_date'] . 'T23:59';
        $meta = array(
            'is_official'                       => '1',
            'event_type'                        => 'resmi',
            'start_date'                        => $date_value,
            'end_date'                          => $date_value,
            'organizer'                         => $row['institution'],
            'event_url'                         => $row['source_url'],
            'source_url'                        => $row['source_url'],
            'official_category'                 => $row['category'],
            'official_institution'              => $row['institution'],
            'official_source_url'               => $row['source_url'],
            'official_applicability'             => $row['applicability'],
            'official_rule_key'                 => $row['rule_key'],
            'official_occurrence_key'           => $row['occurrence_key'],
            'official_period'                   => $row['period'],
            'official_date_basis'               => $row['date_basis'],
            'official_calendar_managed'         => '1',
            'official_calendar_engine_version'  => self::VERSION,
            'official_is_all_day'               => '1',
            'official_is_deadline'              => '1',
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

        return array( 'event_id' => $event_id, 'status' => $created ? 'created' : ( $changed ? 'updated' : 'unchanged' ) );
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
            'meta_query'     => array( array( 'key' => 'official_occurrence_key', 'value' => sanitize_key( $occurrence_key ) ) ),
        ) );
        if ( count( $ids ) > 1 ) {
            return new WP_Error( 'official_phase2_duplicate_key', 'Aynı Faz 2 occurrence anahtarıyla birden fazla Event bulundu; duplicate oluşturulmadı.' );
        }
        return $ids ? absint( $ids[0] ) : 0;
    }

    private static function store_evidence( $event_id, $row ) {
        $evidence = get_post_meta( $event_id, 'event_source_evidence', true );
        $evidence = is_array( $evidence ) ? $evidence : array();
        $kept = array();
        foreach ( $evidence as $entry ) {
            if ( ! is_array( $entry ) || ( isset( $entry['official_occurrence_key'] ) && $entry['official_occurrence_key'] === $row['occurrence_key'] ) ) {
                continue;
            }
            $kept[] = $entry;
        }
        $kept[] = array(
            'source_id'               => 0,
            'source_name'             => $row['institution'],
            'source_url'              => $row['source_url'],
            'parser_type'             => 'official',
            'last_seen_at'            => current_time( 'mysql' ),
            'official_occurrence_key' => $row['occurrence_key'],
            'values'                  => array(
                'start_date' => $row['due_date'] . 'T23:59',
                'end_date'   => $row['due_date'] . 'T23:59',
                'organizer'  => $row['institution'],
                'event_url'  => $row['source_url'],
            ),
        );
        update_post_meta( $event_id, 'event_source_evidence', array_values( $kept ) );
        update_post_meta( $event_id, 'event_source_evidence_count', count( $kept ) );
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_official_calendar_p2_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }
}
