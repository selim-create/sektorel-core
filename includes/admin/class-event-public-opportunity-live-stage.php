<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Live discovery for public support / application opportunities.
 *
 * KOSGEB and ISKUR are parsed with source-specific list -> detail adapters.
 * A detail is materialized only when an application deadline can be verified
 * from the official detail text and that deadline is not in the past.
 *
 * The verified 1.50.0 catalogue remains a safe fallback. Live rows override
 * only dynamic fields for the same source URL while preserving the catalogue's
 * stable occurrence key, so existing production records never duplicate.
 */
class Sektorel_Event_Public_Opportunity_Live_Stage {

    const NONCE_ACTION = 'sektorel_public_opportunities';
    const QUEUE_TTL    = 2 * HOUR_IN_SECONDS;
    const BATCH_SIZE   = 10;
    const VERSION      = '1510';

    const KOSGEB_INDEX = 'https://www.kosgeb.gov.tr/site/tr/genel/';
    const ISKUR_INDEX  = 'https://www.iskur.gov.tr/duyurular/';
    const ISKUR_ENGELSIZ_INDEX = 'https://engelsiz.iskur.gov.tr/haberlerduyurular/';

    const MAX_KOSGEB_DETAILS = 24;
    const MAX_ISKUR_DETAILS  = 30;

    public static function init() {
        add_action( 'wp_ajax_sektorel_public_opportunities_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_public_opportunities_batch', array( __CLASS__, 'ajax_batch' ) );
    }

    public static function ajax_prepare() {
        self::require_ajax();

        $year = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : (int) current_time( 'Y' );
        if ( $year < 2026 || $year > ( (int) current_time( 'Y' ) + 1 ) ) {
            wp_send_json_error( array( 'message' => 'Kamu fırsatları yılı geçersiz.' ) );
        }

        $discovery = self::discover( $year );
        $rows      = self::merge_with_verified_catalogue( $year, $discovery['rows'] );
        $token     = strtolower( wp_generate_password( 24, false, false ) );

        set_transient(
            self::queue_key( get_current_user_id(), $token ),
            array(
                'year'            => $year,
                'rows'            => array_values( $rows ),
                'provider_errors' => array_values( $discovery['errors'] ),
                'provider_stats'  => $discovery['stats'],
            ),
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

        if ( 0 === $offset ) {
            foreach ( (array) ( isset( $queue['provider_errors'] ) ? $queue['provider_errors'] : array() ) as $provider_error ) {
                $error++;
                $messages[] = 'Canlı kaynak uyarısı: ' . sanitize_text_field( $provider_error );
            }

            $stats = isset( $queue['provider_stats'] ) && is_array( $queue['provider_stats'] ) ? $queue['provider_stats'] : array();
            foreach ( $stats as $provider => $stat ) {
                $messages[] = strtoupper( sanitize_key( $provider ) ) . ' canlı keşif: '
                    . absint( isset( $stat['links'] ) ? $stat['links'] : 0 ) . ' detay bağlantısı, '
                    . absint( isset( $stat['verified'] ) ? $stat['verified'] : 0 ) . ' doğrulanmış açık/yaklaşan fırsat.';
            }
        }

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

    private static function discover( $year ) {
        $rows   = array();
        $errors = array();
        $stats  = array();

        $kosgeb = self::discover_kosgeb( $year );
        $rows   = array_merge( $rows, $kosgeb['rows'] );
        $errors = array_merge( $errors, $kosgeb['errors'] );
        $stats['kosgeb'] = $kosgeb['stats'];

        $iskur = self::discover_iskur( $year );
        $rows   = array_merge( $rows, $iskur['rows'] );
        $errors = array_merge( $errors, $iskur['errors'] );
        $stats['iskur'] = $iskur['stats'];

        $deduped = array();
        foreach ( $rows as $row ) {
            if ( empty( $row['source_url'] ) ) {
                continue;
            }
            $deduped[ self::url_key( $row['source_url'] ) ] = $row;
        }

        return array(
            'rows'   => array_values( $deduped ),
            'errors' => array_values( array_unique( $errors ) ),
            'stats'  => $stats,
        );
    }

    private static function discover_kosgeb( $year ) {
        $result = array(
            'rows'   => array(),
            'errors' => array(),
            'stats'  => array( 'links' => 0, 'verified' => 0 ),
        );

        $index = self::fetch_html( self::KOSGEB_INDEX, array( 'www.kosgeb.gov.tr', 'kosgeb.gov.tr' ) );
        if ( is_wp_error( $index ) ) {
            $result['errors'][] = 'KOSGEB ana sayfası alınamadı: ' . $index->get_error_message();
            return $result;
        }

        $dom = self::load_dom( $index );
        if ( is_wp_error( $dom ) ) {
            $result['errors'][] = 'KOSGEB ana sayfası ayrıştırılamadı.';
            return $result;
        }

        $links = self::collect_detail_links(
            $dom,
            self::KOSGEB_INDEX,
            '/site/tr/genel/detay/',
            array( 'www.kosgeb.gov.tr', 'kosgeb.gov.tr' ),
            array( 'basvuru', 'destek', 'kredi', 'finansman', 'cagri', 'program' )
        );
        $links = array_slice( $links, 0, self::MAX_KOSGEB_DETAILS, true );
        $result['stats']['links'] = count( $links );

        $detail_errors = 0;
        foreach ( $links as $url => $label ) {
            $detail = self::fetch_html( $url, array( 'www.kosgeb.gov.tr', 'kosgeb.gov.tr' ) );
            if ( is_wp_error( $detail ) ) {
                $detail_errors++;
                continue;
            }

            $row = self::parse_kosgeb_detail( $url, $detail, $label, $year );
            if ( $row ) {
                $result['rows'][] = $row;
            }
        }

        if ( $detail_errors ) {
            $result['errors'][] = 'KOSGEB detay sayfalarının ' . $detail_errors . ' tanesi alınamadı.';
        }
        $result['stats']['verified'] = count( $result['rows'] );
        return $result;
    }

    private static function discover_iskur( $year ) {
        $result = array(
            'rows'   => array(),
            'errors' => array(),
            'stats'  => array( 'links' => 0, 'verified' => 0 ),
        );

        $indexes = array(
            self::ISKUR_INDEX,
            self::ISKUR_ENGELSIZ_INDEX,
            self::ISKUR_ENGELSIZ_INDEX . '?page=2',
            self::ISKUR_ENGELSIZ_INDEX . '?page=3',
        );

        $links = array();
        foreach ( $indexes as $index_url ) {
            $index = self::fetch_html(
                $index_url,
                array( 'www.iskur.gov.tr', 'iskur.gov.tr', 'engelsiz.iskur.gov.tr' )
            );
            if ( is_wp_error( $index ) ) {
                // Main İŞKUR can occasionally reject automated requests. Engelsiz
                // İŞKUR is an official second discovery surface, so keep scanning.
                $result['errors'][] = 'İŞKUR liste sayfası alınamadı (' . self::display_host( $index_url ) . '): ' . $index->get_error_message();
                continue;
            }

            $dom = self::load_dom( $index );
            if ( is_wp_error( $dom ) ) {
                $result['errors'][] = 'İŞKUR liste sayfası ayrıştırılamadı (' . self::display_host( $index_url ) . ').';
                continue;
            }

            $path_hint = false !== strpos( $index_url, 'engelsiz.iskur.gov.tr' ) ? '/haberlerduyurular/' : '/duyurular/';
            $found = self::collect_detail_links(
                $dom,
                $index_url,
                $path_hint,
                array( 'www.iskur.gov.tr', 'iskur.gov.tr', 'engelsiz.iskur.gov.tr' ),
                array( 'basvuru', 'hibe', 'proje', 'destek', 'cagri' )
            );
            $links = $links + $found;
        }

        $links = array_slice( $links, 0, self::MAX_ISKUR_DETAILS, true );
        $result['stats']['links'] = count( $links );

        $detail_errors = 0;
        foreach ( $links as $url => $label ) {
            $detail = self::fetch_html(
                $url,
                array( 'www.iskur.gov.tr', 'iskur.gov.tr', 'engelsiz.iskur.gov.tr' )
            );
            if ( is_wp_error( $detail ) ) {
                $detail_errors++;
                continue;
            }

            $row = self::parse_iskur_detail( $url, $detail, $label, $year );
            if ( $row ) {
                $result['rows'][] = $row;
            }
        }

        if ( $detail_errors ) {
            $result['errors'][] = 'İŞKUR detay sayfalarının ' . $detail_errors . ' tanesi alınamadı.';
        }
        $result['stats']['verified'] = count( $result['rows'] );
        return $result;
    }

    private static function parse_kosgeb_detail( $url, $html, $fallback_title, $year ) {
        $dom = self::load_dom( $html );
        if ( is_wp_error( $dom ) ) {
            return null;
        }

        $title = self::extract_heading( $dom, $fallback_title );
        $text  = self::document_text( $dom );
        $key   = self::normalized_text( $title . ' ' . $text );

        if ( false === strpos( $key, 'basvuru' ) ) {
            return null;
        }
        if ( ! self::contains_any( $key, array( 'destek', 'kredi', 'hibe', 'finansman', 'cagri', 'program' ) ) ) {
            return null;
        }
        if ( self::contains_any( $key, array( 'personel alim', 'sozlesmeli personel', 'kariyer kapisi', 'stajyer alim', 'is ilan' ) ) ) {
            return null;
        }

        $published = self::extract_page_date( $dom, $text );
        $dates     = self::extract_application_dates( $text, $published );
        if ( ! $dates || (int) substr( $dates['deadline'], 0, 4 ) !== (int) $year || $dates['deadline'] < current_time( 'Y-m-d' ) ) {
            return null;
        }

        $application_url = self::extract_application_url(
            $dom,
            $url,
            array( 'edevlet.kosgeb.gov.tr', 'giris.turkiye.gov.tr', 'www.turkiye.gov.tr', 'turkiye.gov.tr' )
        );
        if ( ! $application_url ) {
            $application_url = 'https://edevlet.kosgeb.gov.tr/';
        }

        $detail_id = self::numeric_detail_id( $url );
        $title     = self::opportunity_title( $title );
        $kind      = self::infer_kind( $key );
        $audience  = self::infer_kosgeb_audience( $key );
        $amount    = self::extract_amount( $text );

        return self::row(
            $detail_id ? 'kosgeb_detail_' . $detail_id : 'kosgeb_' . substr( md5( self::url_key( $url ) ), 0, 12 ),
            $title,
            $dates['start'],
            $dates['deadline'],
            'kosgeb',
            'KOSGEB',
            $kind,
            $audience,
            self::build_description( 'KOSGEB', $title, $dates['start'], $dates['deadline'], $kind ),
            $url,
            $application_url,
            $amount,
            'live_kosgeb_official_detail'
        );
    }

    private static function parse_iskur_detail( $url, $html, $fallback_title, $year ) {
        $dom = self::load_dom( $html );
        if ( is_wp_error( $dom ) ) {
            return null;
        }

        $title = self::extract_heading( $dom, $fallback_title );
        $text  = self::document_text( $dom );
        $key   = self::normalized_text( $title . ' ' . $text );

        if ( false === strpos( $key, 'basvuru' ) ) {
            return null;
        }
        if ( ! self::contains_any( $key, array( 'hibe', 'proje', 'destek', 'cagri', 'kendi isini kur' ) ) ) {
            return null;
        }
        if ( self::contains_any( self::normalized_text( $title ), array( 'degerlendirildi', 'toplantisi', 'toplantisi gerceklestirildi', 'sonuclari' ) ) ) {
            return null;
        }

        $published = self::extract_page_date( $dom, $text );
        $dates     = self::extract_application_dates( $text, $published );
        if ( ! $dates || (int) substr( $dates['deadline'], 0, 4 ) !== (int) $year || $dates['deadline'] < current_time( 'Y-m-d' ) ) {
            return null;
        }

        $application_url = self::extract_application_url(
            $dom,
            $url,
            array( 'www.turkiye.gov.tr', 'turkiye.gov.tr', 'esube.iskur.gov.tr', 'www.iskur.gov.tr', 'iskur.gov.tr' )
        );
        if ( ! $application_url ) {
            $application_url = $url;
        }

        $title    = self::opportunity_title( $title );
        $audience = self::infer_iskur_audience( $key );
        $amount   = self::extract_amount( $text );

        return self::row(
            'iskur_' . substr( md5( self::url_key( $url ) ), 0, 12 ),
            $title,
            $dates['start'],
            $dates['deadline'],
            'iskur',
            'Türkiye İş Kurumu (İŞKUR)',
            'grant_call',
            $audience,
            self::build_description( 'İŞKUR', $title, $dates['start'], $dates['deadline'], 'grant_call' ),
            $url,
            $application_url,
            $amount,
            'live_iskur_official_detail'
        );
    }

    private static function merge_with_verified_catalogue( $year, $live_rows ) {
        $today = current_time( 'Y-m-d' );
        $by_source = array();

        if ( class_exists( 'Sektorel_Event_Public_Opportunity_Stage' ) ) {
            foreach ( (array) Sektorel_Event_Public_Opportunity_Stage::catalogue() as $seed ) {
                if ( empty( $seed['application_deadline'] ) || empty( $seed['source_url'] ) ) {
                    continue;
                }
                if ( (int) substr( $seed['application_deadline'], 0, 4 ) !== (int) $year || $seed['application_deadline'] < $today ) {
                    continue;
                }
                $seed['status'] = $seed['application_start'] > $today ? 'upcoming' : 'open';
                $by_source[ self::url_key( $seed['source_url'] ) ] = $seed;
            }
        }

        foreach ( (array) $live_rows as $live ) {
            if ( empty( $live['application_deadline'] ) || empty( $live['source_url'] ) ) {
                continue;
            }
            if ( (int) substr( $live['application_deadline'], 0, 4 ) !== (int) $year || $live['application_deadline'] < $today ) {
                continue;
            }

            $source_key = self::url_key( $live['source_url'] );
            if ( isset( $by_source[ $source_key ] ) ) {
                $seed = $by_source[ $source_key ];
                // Preserve stable identity and curated copy from the verified
                // pack, while live official dates are authoritative and may
                // reflect extensions announced after deployment.
                $live['occurrence_key'] = $seed['occurrence_key'];
                $live['title']           = ! empty( $seed['title'] ) ? $seed['title'] : $live['title'];
                $live['description']     = ! empty( $seed['description'] ) ? $seed['description'] : $live['description'];
                $live['application_url'] = ! empty( $seed['application_url'] ) ? $seed['application_url'] : $live['application_url'];
                $live['amount']          = ! empty( $seed['amount'] ) ? $seed['amount'] : $live['amount'];
                $live['audience']        = ! empty( $seed['audience'] ) ? $seed['audience'] : $live['audience'];
                $live['kind']            = ! empty( $seed['kind'] ) ? $seed['kind'] : $live['kind'];
            }
            $live['status'] = $live['application_start'] > $today ? 'upcoming' : 'open';
            $by_source[ $source_key ] = $live;
        }

        $rows = array_values( $by_source );
        usort( $rows, static function ( $a, $b ) {
            if ( $a['application_deadline'] === $b['application_deadline'] ) {
                return strcmp( $a['occurrence_key'], $b['occurrence_key'] );
            }
            return strcmp( $a['application_deadline'], $b['application_deadline'] );
        } );
        return $rows;
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

        $lookup = self::find_event( $row['occurrence_key'], $row['source_url'] );
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
            return new WP_Error( 'public_opportunity_not_managed', 'Aynı fırsatla eşleşen Event kamu fırsatı motoru tarafından yönetilmiyor.' );
        }

        $existing_key = sanitize_key( (string) get_post_meta( $event_id, 'opportunity_occurrence_key', true ) );
        if ( $existing_key ) {
            $row['occurrence_key'] = $existing_key;
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

    private static function find_event( $occurrence_key, $source_url ) {
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
        if ( $ids ) {
            return absint( $ids[0] );
        }

        $source_ids = get_posts( array(
            'post_type'      => 'event',
            'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
            'posts_per_page' => 2,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array(
                    'key'   => 'opportunity_source_url',
                    'value' => esc_url_raw( $source_url, array( 'http', 'https' ) ),
                ),
            ),
        ) );
        if ( count( $source_ids ) > 1 ) {
            return new WP_Error( 'public_opportunity_duplicate_source', 'Aynı resmî kaynak URL ile birden fazla kamu fırsatı Event’i bulundu; duplicate oluşturulmadı.' );
        }
        return $source_ids ? absint( $source_ids[0] ) : 0;
    }

    private static function store_evidence( $event_id, $row ) {
        $evidence = get_post_meta( $event_id, 'event_source_evidence', true );
        $evidence = is_array( $evidence ) ? $evidence : array();
        $kept     = array();

        foreach ( $evidence as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $same_occurrence = isset( $entry['opportunity_occurrence_key'] ) && $entry['opportunity_occurrence_key'] === $row['occurrence_key'];
            $same_source     = ! empty( $entry['source_url'] ) && self::url_key( $entry['source_url'] ) === self::url_key( $row['source_url'] );
            if ( $same_occurrence || $same_source ) {
                continue;
            }
            $kept[] = $entry;
        }

        $parser_type = 0 === strpos( (string) $row['date_basis'], 'live_' ) ? 'public_opportunity_live' : 'public_opportunity';
        $kept[] = array(
            'source_id'                  => 0,
            'source_name'                => $row['provider_name'],
            'source_url'                 => $row['source_url'],
            'parser_type'                => $parser_type,
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

    private static function collect_detail_links( $dom, $base_url, $path_hint, $allowed_hosts, $keywords ) {
        $xpath = new DOMXPath( $dom );
        $links = array();
        foreach ( $xpath->query( '//a[@href]' ) as $anchor ) {
            $href = trim( (string) $anchor->getAttribute( 'href' ) );
            if ( '' === $href ) {
                continue;
            }
            $url = self::absolute_url( $href, $base_url );
            if ( ! $url || false === strpos( $url, $path_hint ) || ! self::host_allowed( $url, $allowed_hosts ) ) {
                continue;
            }
            $text = self::clean_text( $anchor->textContent );
            $key  = self::normalized_text( $text );
            if ( $key && ! self::contains_any( $key, $keywords ) && ! isset( $links[ $url ] ) ) {
                continue;
            }
            if ( ! isset( $links[ $url ] ) ) {
                $links[ $url ] = $text;
            } elseif ( $text && false === strpos( $links[ $url ], $text ) ) {
                $links[ $url ] .= ' ' . $text;
            }
        }

        // Some cards link the headline and “devamı” separately. If a URL was
        // first encountered through a generic label, keep it only when the
        // aggregated text now carries a provider-specific opportunity signal.
        foreach ( $links as $url => $label ) {
            if ( ! self::contains_any( self::normalized_text( $label ), $keywords ) ) {
                unset( $links[ $url ] );
            }
        }
        return $links;
    }

    private static function fetch_html( $url, $allowed_hosts ) {
        if ( ! self::host_allowed( $url, $allowed_hosts ) ) {
            return new WP_Error( 'public_opportunity_host_blocked', 'Kaynak host allowlist dışında.' );
        }

        $response = wp_safe_remote_get( $url, array(
            'timeout'     => 15,
            'redirection' => 3,
            'user-agent'  => 'SektorelAjanda/1.51 (+https://sektorelajanda.com)',
            'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
        ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = absint( wp_remote_retrieve_response_code( $response ) );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'public_opportunity_http_error', 'HTTP ' . $code );
        }

        $body = (string) wp_remote_retrieve_body( $response );
        if ( strlen( $body ) < 200 ) {
            return new WP_Error( 'public_opportunity_empty_body', 'Kaynak sayfa gövdesi boş veya yetersiz.' );
        }
        return $body;
    }

    private static function load_dom( $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return new WP_Error( 'public_opportunity_dom_missing', 'PHP DOM uzantısı bulunamadı.' );
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . (string) $html, LIBXML_NOWARNING | LIBXML_NOERROR );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        return $loaded ? $dom : new WP_Error( 'public_opportunity_dom_invalid', 'HTML DOM oluşturulamadı.' );
    }

    private static function document_text( $dom ) {
        $xpath = new DOMXPath( $dom );
        $nodes = $xpath->query( '//article | //main | //*[(contains(@class,"detail") or contains(@class,"content") or contains(@class,"haber"))]' );
        $best  = '';
        if ( $nodes ) {
            foreach ( $nodes as $node ) {
                $text = self::clean_text( $node->textContent );
                if ( strlen( $text ) > strlen( $best ) && strlen( $text ) < 60000 ) {
                    $best = $text;
                }
            }
        }
        if ( strlen( $best ) >= 120 ) {
            return $best;
        }
        $body = $xpath->query( '//body' )->item( 0 );
        return $body ? self::clean_text( $body->textContent ) : '';
    }

    private static function extract_heading( $dom, $fallback ) {
        $xpath = new DOMXPath( $dom );
        $best  = '';
        foreach ( $xpath->query( '//h1 | //h2 | //h3' ) as $node ) {
            $text = self::clean_text( $node->textContent );
            $key  = self::normalized_text( $text );
            if ( in_array( $key, array( 'haberler', 'duyurular', 'destekler', 'haberler duyurular', 'hibe projeleri' ), true ) ) {
                continue;
            }
            if ( strlen( $text ) > strlen( $best ) && strlen( $text ) <= 240 ) {
                $best = $text;
            }
        }
        if ( $best ) {
            return $best;
        }

        $title = $xpath->query( '//title' )->item( 0 );
        $text  = $title ? self::clean_text( $title->textContent ) : self::clean_text( $fallback );
        $text  = preg_replace( '/\s+-\s+(KOSGEB|Engelsiz İŞKUR|İŞKUR).*$/ui', '', $text );
        return self::clean_text( $text );
    }

    private static function extract_page_date( $dom, $text ) {
        $xpath = new DOMXPath( $dom );
        foreach ( $xpath->query( '//time | //*[(contains(@class,"date") or contains(@class,"tarih"))]' ) as $node ) {
            $date = self::first_turkish_date( self::clean_text( $node->textContent ) );
            if ( $date ) {
                return $date;
            }
        }
        if ( preg_match( '/Tarih\s*:\s*(\d{1,2})\s+(\p{L}+)\s+(\d{4})/iu', $text, $m ) ) {
            return self::turkish_date( $m[1], $m[2], $m[3] );
        }
        return self::first_turkish_date( substr( $text, 0, 2500 ) );
    }

    private static function extract_application_dates( $text, $published ) {
        $text = self::clean_text( $text );
        $start = '';
        $deadline = '';

        // 9 Temmuz – 31 Aralık 2026 / 20 Nisan - 8 Mayıs 2026.
        if ( preg_match_all( '/(\d{1,2})\s+(\p{L}+)(?:\s+(\d{4}))?\s*(?:-|–|—)\s*(\d{1,2})\s+(\p{L}+)\s+(\d{4})/iu', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches as $match ) {
                $offset  = $match[0][1];
                $context = substr( $text, max( 0, $offset - 180 ), strlen( $match[0][0] ) + 360 );
                if ( false === strpos( self::normalized_text( $context ), 'basvuru' ) ) {
                    continue;
                }
                $end_year = $match[6][0];
                $start_year = ! empty( $match[3][0] ) ? $match[3][0] : $end_year;
                $start    = self::turkish_date( $match[1][0], $match[2][0], $start_year );
                $deadline = self::turkish_date( $match[4][0], $match[5][0], $end_year );
                if ( $start && $deadline ) {
                    break;
                }
            }
        }

        // 3–31 Ocak 2026 shorthand.
        if ( ! $deadline && preg_match_all( '/(\d{1,2})\s*(?:-|–|—)\s*(\d{1,2})\s+(\p{L}+)\s+(\d{4})/iu', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches as $match ) {
                $offset  = $match[0][1];
                $context = substr( $text, max( 0, $offset - 180 ), strlen( $match[0][0] ) + 360 );
                if ( false === strpos( self::normalized_text( $context ), 'basvuru' ) ) {
                    continue;
                }
                $start    = self::turkish_date( $match[1][0], $match[3][0], $match[4][0] );
                $deadline = self::turkish_date( $match[2][0], $match[3][0], $match[4][0] );
                if ( $start && $deadline ) {
                    break;
                }
            }
        }

        if ( preg_match( '/son\s+başvuru(?:\s+tarihi)?\s*:?\s*(\d{1,2})\s+(\p{L}+)\s+(\d{4})/iu', $text, $m ) ) {
            $explicit = self::turkish_date( $m[1], $m[2], $m[3] );
            if ( $explicit ) {
                $deadline = $explicit;
            }
        }

        if ( ! $deadline && preg_match( '/başvuru(?:lar[ıi]?|ları|lar)?[^.]{0,220}?(\d{1,2})\s+(\p{L}+)\s+(\d{4})[^.]{0,100}?(?:kadar|sona\s+erecek|sona\s+eriyor|sona\s+erer)/iu', $text, $m ) ) {
            $deadline = self::turkish_date( $m[1], $m[2], $m[3] );
        }

        if ( ! $deadline && preg_match( '/(\d{1,2})\s+(\p{L}+)\s+(\d{4})\s+tarih(?:i|ine)[^.]{0,140}?(?:kadar|sona\s+erecek|sona\s+erer)/iu', $text, $m ) ) {
            $deadline = self::turkish_date( $m[1], $m[2], $m[3] );
        }

        if ( ! $start && preg_match( '/başvuru(?:lar[ıi]?|ları|lar)?[^.]{0,150}?(\d{1,2})\s+(\p{L}+)\s+(\d{4})[^.]{0,100}?(?:başl|itibarıyla)/iu', $text, $m ) ) {
            $start = self::turkish_date( $m[1], $m[2], $m[3] );
        }

        if ( ! $start ) {
            $start = $published;
        }
        if ( ! $start || ! $deadline || $deadline < $start ) {
            return null;
        }

        return array( 'start' => $start, 'deadline' => $deadline );
    }

    private static function extract_application_url( $dom, $source_url, $allowed_hosts ) {
        $xpath = new DOMXPath( $dom );
        foreach ( $xpath->query( '//a[@href]' ) as $anchor ) {
            $text = self::normalized_text( $anchor->textContent );
            $href = self::absolute_url( trim( (string) $anchor->getAttribute( 'href' ) ), $source_url );
            if ( ! $href || ! self::host_allowed( $href, $allowed_hosts ) ) {
                continue;
            }
            if ( false !== strpos( $text, 'basvur' ) || false !== strpos( $text, 'e devlet' ) || false !== strpos( $text, 'e hizmet' ) ) {
                return esc_url_raw( $href, array( 'http', 'https' ) );
            }
        }
        return '';
    }

    private static function infer_kind( $normalized_text ) {
        if ( false !== strpos( $normalized_text, 'kredi' ) || false !== strpos( $normalized_text, 'finansman' ) ) {
            return 'credit_support';
        }
        if ( false !== strpos( $normalized_text, 'hibe' ) ) {
            return 'grant_call';
        }
        return 'support_call';
    }

    private static function infer_kosgeb_audience( $text ) {
        $audience = array();
        if ( false !== strpos( $text, 'teknogirisim' ) || false !== strpos( $text, 'teknoloji girisim' ) ) {
            $audience[] = 'technology_startup';
        }
        if ( false !== strpos( $text, 'teknogirisim rozeti' ) ) {
            $audience[] = 'technogirisim_badge_holder';
        }
        if ( false !== strpos( $text, 'kobi' ) || false !== strpos( $text, 'kosgeb veri tabani' ) ) {
            $audience[] = 'kosgeb_registered_sme';
        }
        if ( false !== strpos( $text, 'girisimci' ) ) {
            $audience[] = 'entrepreneur';
        }
        if ( false !== strpos( $text, 'kadin girisimci' ) ) {
            $audience[] = 'women_entrepreneur';
        }
        if ( false !== strpos( $text, 'genc girisimci' ) ) {
            $audience[] = 'young_entrepreneur';
        }
        return $audience ? array_values( array_unique( $audience ) ) : array( 'kosgeb_eligible_business' );
    }

    private static function infer_iskur_audience( $text ) {
        $audience = array();
        if ( false !== strpos( $text, 'engelli' ) ) {
            $audience[] = 'disabled_entrepreneur';
        }
        if ( false !== strpos( $text, 'eski hukumlu' ) ) {
            $audience[] = 'ex_convict_entrepreneur';
        }
        if ( false !== strpos( $text, 'korumali isyeri' ) ) {
            $audience[] = 'protected_workplace_project';
        }
        if ( false !== strpos( $text, 'destekli istihdam' ) ) {
            $audience[] = 'supported_employment_project';
        }
        if ( false !== strpos( $text, 'sivil toplum' ) || false !== strpos( $text, 'universite' ) || false !== strpos( $text, 'belediye' ) ) {
            $audience[] = 'eligible_project_organization';
        }
        return $audience ? array_values( array_unique( $audience ) ) : array( 'iskur_eligible_applicant' );
    }

    private static function extract_amount( $text ) {
        if ( preg_match_all( '/(\d+(?:[\.,]\d+)?)\s*(bin|milyon|milyar)\s*TL/iu', $text, $matches, PREG_SET_ORDER ) ) {
            $amounts = array();
            foreach ( $matches as $match ) {
                $value = trim( $match[1] . ' ' . $match[2] . ' TL' );
                if ( ! in_array( $value, $amounts, true ) ) {
                    $amounts[] = $value;
                }
                if ( count( $amounts ) >= 2 ) {
                    break;
                }
            }
            if ( 2 === count( $amounts ) ) {
                return $amounts[0] . ' – ' . $amounts[1];
            }
            if ( $amounts ) {
                return $amounts[0] . '’ye kadar';
            }
        }
        return '';
    }

    private static function build_description( $provider, $title, $start, $deadline, $kind ) {
        $kind_labels = array(
            'credit_support' => 'finansman/kredi desteği',
            'grant_call'     => 'hibe/proje çağrısı',
            'support_call'   => 'destek/başvuru çağrısı',
        );
        $kind_label = isset( $kind_labels[ $kind ] ) ? $kind_labels[ $kind ] : 'başvuru fırsatı';
        return sanitize_textarea_field(
            $provider . ' tarafından yayımlanan ' . $kind_label . ' için doğrulanmış başvuru dönemi '
            . self::display_date( $start ) . ' – ' . self::display_date( $deadline )
            . ' arasındadır. Uygunluk koşulları, kapsam ve güncel başvuru adımları resmî kaynak sayfasından doğrulanmalıdır.'
        );
    }

    private static function opportunity_title( $title ) {
        $title = self::clean_text( $title );
        if ( false === strpos( self::normalized_text( $title ), 'son basvuru' ) ) {
            $title .= ' — Son Başvuru';
        }
        return sanitize_text_field( $title );
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

    private static function absolute_url( $href, $base_url ) {
        $href = html_entity_decode( trim( (string) $href ), ENT_QUOTES, 'UTF-8' );
        if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'javascript:' ) || 0 === strpos( $href, 'mailto:' ) ) {
            return '';
        }
        if ( preg_match( '#^https?://#i', $href ) ) {
            return esc_url_raw( $href, array( 'http', 'https' ) );
        }
        $base = wp_parse_url( $base_url );
        if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
            return '';
        }
        if ( 0 === strpos( $href, '//' ) ) {
            return esc_url_raw( $base['scheme'] . ':' . $href, array( 'http', 'https' ) );
        }
        if ( 0 === strpos( $href, '/' ) ) {
            return esc_url_raw( $base['scheme'] . '://' . $base['host'] . $href, array( 'http', 'https' ) );
        }
        $path = isset( $base['path'] ) ? $base['path'] : '/';
        $dir  = trailingslashit( dirname( $path ) );
        return esc_url_raw( $base['scheme'] . '://' . $base['host'] . $dir . $href, array( 'http', 'https' ) );
    }

    private static function host_allowed( $url, $allowed_hosts ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( ! $host ) {
            return false;
        }
        foreach ( (array) $allowed_hosts as $allowed ) {
            if ( $host === strtolower( $allowed ) ) {
                return true;
            }
        }
        return false;
    }

    private static function display_host( $url ) {
        return (string) wp_parse_url( $url, PHP_URL_HOST );
    }

    private static function numeric_detail_id( $url ) {
        return preg_match( '#/detay/(\d+)(?:/|$)#', $url, $m ) ? absint( $m[1] ) : 0;
    }

    private static function url_key( $url ) {
        $url = esc_url_raw( $url, array( 'http', 'https' ) );
        return untrailingslashit( strtolower( $url ) );
    }

    private static function normalized_text( $text ) {
        $text = strtolower( remove_accents( self::clean_text( $text ) ) );
        $text = preg_replace( '/[^a-z0-9]+/i', ' ', $text );
        return trim( preg_replace( '/\s+/', ' ', $text ) );
    }

    private static function contains_any( $text, $needles ) {
        foreach ( (array) $needles as $needle ) {
            if ( false !== strpos( $text, self::normalized_text( $needle ) ) ) {
                return true;
            }
        }
        return false;
    }

    private static function clean_text( $value ) {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES, 'UTF-8' );
        $value = str_replace( array( "\xC2\xA0", "\r", "\n", "\t" ), ' ', $value );
        return trim( preg_replace( '/\s+/u', ' ', $value ) );
    }

    private static function first_turkish_date( $text ) {
        return preg_match( '/(\d{1,2})\s+(\p{L}+)\s+(\d{4})/u', $text, $m ) ? self::turkish_date( $m[1], $m[2], $m[3] ) : '';
    }

    private static function turkish_date( $day, $month, $year ) {
        $months = array(
            'ocak' => 1, 'subat' => 2, 'mart' => 3, 'nisan' => 4,
            'mayis' => 5, 'haziran' => 6, 'temmuz' => 7, 'agustos' => 8,
            'eylul' => 9, 'ekim' => 10, 'kasim' => 11, 'aralik' => 12,
        );
        $month_key = self::normalized_text( $month );
        $month_key = str_replace( ' ', '', $month_key );
        if ( ! isset( $months[ $month_key ] ) ) {
            return '';
        }
        $day  = absint( $day );
        $year = absint( $year );
        if ( ! checkdate( $months[ $month_key ], $day, $year ) ) {
            return '';
        }
        return sprintf( '%04d-%02d-%02d', $year, $months[ $month_key ], $day );
    }

    private static function display_date( $date ) {
        if ( ! self::valid_date( $date ) ) {
            return $date;
        }
        return substr( $date, 8, 2 ) . '.' . substr( $date, 5, 2 ) . '.' . substr( $date, 0, 4 );
    }

    private static function valid_date( $date ) {
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $m ) ) {
            return false;
        }
        return checkdate( absint( $m[2] ), absint( $m[3] ), absint( $m[1] ) );
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_public_opportunity_live_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }
}
