<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * İstanbul Fuar Merkezi deterministic venue-enrichment adapter.
 *
 * IFM is not a discovery/canonical source. It may create/update candidates and
 * enrich an already existing event occurrence, but it never creates a new
 * event post. Matching is fail-closed: exact start date plus strong supporting
 * title/end-date/organizer signals are required.
 */
class Sektorel_Event_Source_IFM {

    const ADAPTER       = 'ifm_fair_calendar';
    const SOURCE_TITLE  = 'İFM Fuar Takvimi';
    const CALENDAR_URL  = 'https://ifm.com.tr/tr/ifm-fuar-takvimi';
    const NONCE_ACTION  = 'sektorel_ifm_fair_calendar';
    const BATCH_SIZE    = 3;
    const TIMEOUT       = 15;
    const MAX_BODY      = 2097152; // 2 MB.
    const MATCH_THRESHOLD = 75;
    const QUEUE_TTL     = 2 * HOUR_IN_SECONDS;

    public static function init() {
        add_action( 'wp_ajax_sektorel_ifm_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_ifm_import_batch', array( __CLASS__, 'ajax_import_batch' ) );
    }

    public static function ajax_prepare() {
        self::require_ajax();

        $year          = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : (int) current_time( 'Y' );
        $upcoming_only = ! empty( $_POST['upcoming_only'] );
        if ( $year < 2020 || $year > ( (int) current_time( 'Y' ) + 3 ) ) {
            wp_send_json_error( array( 'message' => 'Geçersiz İFM takvim yılı.' ) );
        }

        $source_id = self::ensure_source();
        if ( is_wp_error( $source_id ) ) {
            wp_send_json_error( array( 'message' => $source_id->get_error_message() ) );
        }

        $response = self::safe_get( self::CALENDAR_URL );
        if ( is_wp_error( $response ) ) {
            self::record_source_error( $source_id, $response->get_error_message() );
            wp_send_json_error( array( 'message' => 'İFM takvimi alınamadı: ' . $response->get_error_message() ) );
        }

        $urls = self::extract_detail_urls( $response['body'] );
        if ( ! $urls ) {
            self::record_source_error( $source_id, 'İFM takviminde fuar detay bağlantısı bulunamadı.' );
            wp_send_json_error( array( 'message' => 'İFM takviminde fuar detay bağlantısı bulunamadı.' ) );
        }

        $queue = array(
            'source_id'     => absint( $source_id ),
            'year'          => $year,
            'upcoming_only' => $upcoming_only,
            'urls'          => array_values( $urls ),
        );
        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient( self::queue_key( get_current_user_id(), $token ), $queue, self::QUEUE_TTL );

        update_post_meta( $source_id, 'check_state', 'ok' );
        update_post_meta( $source_id, 'last_checked_at', current_time( 'mysql' ) );
        update_post_meta( $source_id, 'last_http_status', absint( $response['code'] ) );
        update_post_meta( $source_id, 'last_result', count( $urls ) . ' İFM fuar detay bağlantısı bulundu.' );
        update_post_meta( $source_id, 'last_error', '' );
        update_post_meta( $source_id, 'detected_parser', 'adapter' );

        wp_send_json_success( array(
            'token' => $token,
            'total' => count( $urls ),
        ) );
    }

    public static function ajax_import_batch() {
        self::require_ajax();

        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
        if ( ! $token ) {
            wp_send_json_error( array( 'message' => 'İFM kuyruk anahtarı eksik.' ) );
        }

        $key   = self::queue_key( get_current_user_id(), $token );
        $queue = get_transient( $key );
        if ( ! is_array( $queue ) || empty( $queue['urls'] ) || ! is_array( $queue['urls'] ) ) {
            wp_send_json_error( array( 'message' => 'İFM kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $urls      = array_values( $queue['urls'] );
        $source_id = absint( $queue['source_id'] );
        $batch     = array_slice( $urls, $offset, self::BATCH_SIZE );
        $created = $updated = $unchanged = $skipped = $error = $changed = 0;
        $messages = array();

        foreach ( $batch as $detail_url ) {
            $response = self::safe_get( $detail_url );
            if ( is_wp_error( $response ) ) {
                $error++;
                $messages[] = 'Hata: ' . $detail_url . ' — ' . $response->get_error_message();
                continue;
            }

            $record = self::parse_detail( $response['body'], $detail_url );
            if ( is_wp_error( $record ) ) {
                $skipped++;
                $messages[] = 'Atlandı: ' . $detail_url . ' — ' . $record->get_error_message();
                continue;
            }

            if ( absint( substr( $record['start_date'], 0, 4 ) ) !== absint( $queue['year'] ) ) {
                $skipped++;
                continue;
            }

            if ( ! empty( $queue['upcoming_only'] ) && self::date_part( $record['end_date'] ?: $record['start_date'] ) < current_time( 'Y-m-d' ) ) {
                $skipped++;
                continue;
            }

            $result = self::upsert_and_enrich( $source_id, $record );
            if ( is_wp_error( $result ) ) {
                $error++;
                $messages[] = 'Hata: ' . $record['title'] . ' — ' . $result->get_error_message();
                continue;
            }

            if ( 'created' === $result['candidate_result'] ) {
                $created++;
            } elseif ( 'updated' === $result['candidate_result'] ) {
                $updated++;
            } else {
                $unchanged++;
            }

            if ( ! empty( $result['enriched'] ) ) {
                $changed++;
            }

            $message = $record['title'] . ': ' . $result['candidate_result'];
            if ( ! empty( $result['matched_event_id'] ) ) {
                $message .= ', Event #' . absint( $result['matched_event_id'] ) . ' eşleşti';
                if ( ! empty( $result['enriched_fields'] ) ) {
                    $message .= ', zenginleşen alanlar: ' . implode( ', ', $result['enriched_fields'] );
                }
            } else {
                $message .= ', güçlü mevcut event eşleşmesi yok; yeni event oluşturulmadı';
            }
            $messages[] = $message . '.';
        }

        $total       = count( $urls );
        $next_offset = min( $total, $offset + count( $batch ) );
        $done        = $next_offset >= $total;

        if ( $done ) {
            delete_transient( $key );
            update_post_meta( $source_id, 'last_checked_at', current_time( 'mysql' ) );
            update_post_meta( $source_id, 'last_result', 'İFM adapter taraması tamamlandı.' );
            update_post_meta( $source_id, 'last_error', '' );
        } else {
            set_transient( $key, $queue, self::QUEUE_TTL );
        }

        wp_send_json_success( array(
            'created'     => $created,
            'updated'     => $updated,
            'unchanged'   => $unchanged,
            'skipped'     => $skipped,
            'error'       => $error,
            'changed'     => $changed,
            'messages'    => $messages,
            'next_offset' => $next_offset,
            'done'        => $done,
        ) );
    }

    private static function upsert_and_enrich( $source_id, $record ) {
        $fingerprint = sha1( absint( $source_id ) . '|' . self::normalize_text( $record['title'] ) . '|' . self::date_part( $record['start_date'] ) );
        $existing    = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => 'candidate_fingerprint',
            'meta_value'     => $fingerprint,
            'no_found_rows'  => true,
        ) );

        if ( empty( $existing ) && ! empty( $record['detail_url'] ) ) {
            $existing = get_posts( array(
                'post_type'      => 'event_candidate',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => 'ifm_detail_url',
                'meta_value'     => esc_url_raw( $record['detail_url'] ),
                'no_found_rows'  => true,
            ) );
        }

        $candidate_id = ! empty( $existing[0] ) ? absint( $existing[0] ) : 0;
        $record_hash  = sha1( wp_json_encode( array(
            $record['title'],
            $record['start_date'],
            $record['end_date'],
            $record['halls'],
            $record['organizer'],
            $record['event_url'],
            $record['description'],
        ) ) );
        $old_hash = $candidate_id ? (string) get_post_meta( $candidate_id, 'source_record_hash', true ) : '';

        if ( $candidate_id && $old_hash === $record_hash ) {
            $candidate_result = 'unchanged';
        } else {
            $postarr = array(
                'post_type'    => 'event_candidate',
                'post_status'  => 'publish',
                'post_title'   => $record['title'],
                'post_content' => $record['description'],
            );
            if ( $candidate_id ) {
                $postarr['ID'] = $candidate_id;
                $saved = wp_update_post( $postarr, true );
                $candidate_result = 'updated';
            } else {
                $saved = wp_insert_post( $postarr, true );
                $candidate_result = 'created';
            }
            if ( is_wp_error( $saved ) ) {
                return $saved;
            }
            $candidate_id = absint( $saved );
        }

        $venue = self::venue_value( $record['halls'] );
        $meta = array(
            'candidate_fingerprint' => $fingerprint,
            'source_id'             => absint( $source_id ),
            'source_url'            => self::CALENDAR_URL,
            'event_url'             => $record['event_url'] ?: $record['detail_url'],
            'start_date'            => $record['start_date'],
            'end_date'              => $record['end_date'],
            'location_type'         => 'physical',
            'venue'                 => $venue,
            'organizer'             => $record['organizer'],
            'registration_link'     => '',
            'parser_type'           => 'adapter',
            'source_adapter'        => self::ADAPTER,
            'source_record_hash'    => $record_hash,
            'ifm_detail_url'        => $record['detail_url'],
            'ifm_halls'             => $record['halls'],
            'ifm_subject'           => $record['subject'],
            'source_last_seen_at'   => current_time( 'mysql' ),
        );
        foreach ( $meta as $key => $value ) {
            update_post_meta( $candidate_id, $key, $value );
        }

        $match = self::find_event_match( $record );
        if ( ! $match ) {
            if ( 'imported' !== (string) get_post_meta( $candidate_id, 'candidate_status', true ) ) {
                update_post_meta( $candidate_id, 'candidate_status', 'incomplete' );
                update_post_meta( $candidate_id, 'candidate_match_reason', 'ifm_enrichment_unmatched' );
                delete_post_meta( $candidate_id, 'matched_event_id' );
            }
            return array(
                'candidate_result' => $candidate_result,
                'matched_event_id' => 0,
                'enriched'         => false,
                'enriched_fields'  => array(),
            );
        }

        $event_id        = absint( $match['event_id'] );
        $enriched_fields = self::enrich_event( $event_id, $record );

        update_post_meta( $candidate_id, 'candidate_status', 'imported' );
        update_post_meta( $candidate_id, 'matched_event_id', $event_id );
        update_post_meta( $candidate_id, 'candidate_match_score', absint( $match['score'] ) );
        update_post_meta( $candidate_id, 'candidate_match_reason', 'ifm_enrichment_match' );
        update_post_meta( $candidate_id, 'candidate_cross_source_match_signals', $match['signals'] );
        update_post_meta( $candidate_id, 'ifm_enriched_fields', $enriched_fields );

        // Evidence hook listens to imported_event_id and appends IFM provenance
        // to the already-existing event without creating another event post.
        update_post_meta( $candidate_id, 'imported_event_id', $event_id );

        return array(
            'candidate_result' => $candidate_result,
            'matched_event_id' => $event_id,
            'enriched'         => ! empty( $enriched_fields ),
            'enriched_fields'  => $enriched_fields,
        );
    }

    private static function find_event_match( $record ) {
        $date = self::date_part( $record['start_date'] );
        if ( ! $date ) {
            return null;
        }

        $ids = get_posts( array(
            'post_type'      => 'event',
            'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => 'start_date',
                    'value'   => $date,
                    'compare' => 'LIKE',
                ),
            ),
            'no_found_rows' => true,
        ) );
        if ( ! $ids ) {
            return null;
        }

        update_meta_cache( 'post', $ids );
        $record_title = self::normalize_text( $record['title'] );
        $best = null;

        foreach ( $ids as $event_id ) {
            $event_id = absint( $event_id );
            if ( self::date_part( get_post_meta( $event_id, 'start_date', true ) ) !== $date ) {
                continue;
            }

            $title_similarity = self::similarity( $record_title, self::normalize_text( get_the_title( $event_id ) ) );
            if ( $title_similarity < 60 ) {
                continue;
            }

            $score   = 35;
            $signals = array( 'start_date_exact' );
            if ( 100 === $title_similarity ) {
                $score += 55; $signals[] = 'title_exact';
            } elseif ( $title_similarity >= 90 ) {
                $score += 50; $signals[] = 'title_90';
            } elseif ( $title_similarity >= 80 ) {
                $score += 40; $signals[] = 'title_80';
            } elseif ( $title_similarity >= 70 ) {
                $score += 30; $signals[] = 'title_70';
            } else {
                $score += 20; $signals[] = 'title_60';
            }

            $event_end = self::date_part( get_post_meta( $event_id, 'end_date', true ) );
            if ( $record['end_date'] && $event_end && self::date_part( $record['end_date'] ) === $event_end ) {
                $score += 10; $signals[] = 'end_date_exact';
            }

            $event_organizer = self::normalize_text( get_post_meta( $event_id, 'organizer', true ) );
            $ifm_organizer   = self::normalize_text( $record['organizer'] );
            if ( $event_organizer && $ifm_organizer && $event_organizer === $ifm_organizer ) {
                $score += 10; $signals[] = 'organizer_exact';
            }

            $score = min( 100, $score );
            if ( $score < self::MATCH_THRESHOLD ) {
                continue;
            }

            if ( null === $best || $score > $best['score'] || ( $score === $best['score'] && $event_id < $best['event_id'] ) ) {
                $best = array(
                    'event_id' => $event_id,
                    'score'    => $score,
                    'signals'  => array_values( array_unique( $signals ) ),
                );
            }
        }

        return $best;
    }

    private static function enrich_event( $event_id, $record ) {
        if ( ! $event_id || 'event' !== get_post_type( $event_id ) ) {
            return array();
        }

        $changed = array();
        if ( $record['end_date'] && ! trim( (string) get_post_meta( $event_id, 'end_date', true ) ) ) {
            update_post_meta( $event_id, 'end_date', $record['end_date'] );
            $changed[] = 'end_date';
        }

        if ( $record['organizer'] && ! trim( (string) get_post_meta( $event_id, 'organizer', true ) ) ) {
            update_post_meta( $event_id, 'organizer', $record['organizer'] );
            $changed[] = 'organizer';
        }

        $event_url = trim( (string) get_post_meta( $event_id, 'event_url', true ) );
        if ( ! $event_url && $record['event_url'] ) {
            update_post_meta( $event_id, 'event_url', esc_url_raw( $record['event_url'] ) );
            $changed[] = 'event_url';
        }

        $current_venue = trim( (string) get_post_meta( $event_id, 'venue', true ) );
        $ifm_venue     = self::venue_value( $record['halls'] );
        if ( ! $current_venue && $ifm_venue ) {
            update_post_meta( $event_id, 'venue', $ifm_venue );
            $changed[] = 'venue';
        } elseif ( $record['halls'] && self::is_plain_ifm_venue( $current_venue ) ) {
            $detailed = self::venue_value( $record['halls'] );
            if ( self::normalize_text( $detailed ) !== self::normalize_text( $current_venue ) ) {
                update_post_meta( $event_id, 'venue', $detailed );
                $changed[] = 'venue';
            }
        }

        if ( $record['halls'] ) {
            update_post_meta( $event_id, 'ifm_halls', $record['halls'] );
        }
        update_post_meta( $event_id, 'ifm_detail_url', esc_url_raw( $record['detail_url'] ) );

        $post = get_post( $event_id );
        if ( $post && '' === trim( (string) $post->post_content ) && $record['description'] ) {
            wp_update_post( array(
                'ID'           => $event_id,
                'post_content' => wp_kses_post( $record['description'] ),
            ) );
            $changed[] = 'description';
        }

        return array_values( array_unique( $changed ) );
    }

    private static function parse_detail( $html, $detail_url ) {
        if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
            return new WP_Error( 'ifm_dom_missing', 'DOMDocument/DOMXPath kullanılamıyor.' );
        }

        $dom  = new DOMDocument();
        $prev = libxml_use_internal_errors( true );
        $ok   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        if ( ! $ok ) {
            return new WP_Error( 'ifm_html_invalid', 'İFM detay HTML parse edilemedi.' );
        }

        $xpath = new DOMXPath( $dom );
        $title = self::first_text( $xpath, array( '//h4[1]', '//h1', '//main//h2[1]', '//h2[1]' ) );
        if ( ! $title ) {
            $title = trim( (string) $dom->getElementsByTagName( 'title' )->item(0)->textContent );
            $title = preg_replace( '/\s*\|\s*İFM.*$/u', '', $title );
        }

        $text = self::clean_text( $dom->textContent );
        $start = self::labeled_value( $text, 'Başlangıç Tarihi' );
        $end   = self::labeled_value( $text, 'Bitiş Tarihi' );
        $halls = self::labeled_value( $text, 'Salon' );
        $subject = self::labeled_value( $text, 'Fuarın Konusu' );
        $organizer = self::labeled_value( $text, 'Organizatör' );
        $web = self::labeled_value( $text, 'Web' );

        $start = self::normalize_date( $start );
        $end   = self::normalize_date( $end );
        $halls = self::normalize_halls( $halls );
        $web   = self::extract_url( $web );

        if ( ! $web ) {
            $web = self::detail_web_link( $xpath, $detail_url );
        }

        if ( ! $title || ! $start || ! $organizer ) {
            return new WP_Error( 'ifm_required_fields', 'İFM detayında başlık, başlangıç tarihi veya organizatör eksik.' );
        }

        $description = self::description_text( $xpath );

        return array(
            'title'       => sanitize_text_field( $title ),
            'start_date'  => $start . 'T00:00',
            'end_date'    => $end ? $end . 'T00:00' : '',
            'halls'       => $halls,
            'subject'     => sanitize_text_field( $subject ),
            'organizer'   => sanitize_text_field( $organizer ),
            'event_url'   => esc_url_raw( $web ),
            'detail_url'  => esc_url_raw( $detail_url ),
            'description' => wp_kses_post( $description ),
        );
    }

    private static function extract_detail_urls( $html ) {
        if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
            return array();
        }
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        $xpath = new DOMXPath( $dom );
        $urls = array();
        foreach ( $xpath->query( '//a[@href]' ) as $anchor ) {
            $href = trim( $anchor->getAttribute( 'href' ) );
            if ( ! $href || false === strpos( $href, '/fuarlar/' ) ) {
                continue;
            }
            $url = self::absolute_ifm_url( $href );
            if ( $url && 'ifm.com.tr' === strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
                $urls[ $url ] = $url;
            }
        }
        return array_values( $urls );
    }

    private static function ensure_source() {
        $ids = get_posts( array(
            'post_type'      => 'event_source',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => 'source_adapter',
            'meta_value'     => self::ADAPTER,
            'no_found_rows'  => true,
        ) );

        if ( ! empty( $ids[0] ) ) {
            $source_id = absint( $ids[0] );
        } else {
            $source_id = wp_insert_post( array(
                'post_type'   => 'event_source',
                'post_status' => 'publish',
                'post_title'  => self::SOURCE_TITLE,
            ), true );
            if ( is_wp_error( $source_id ) ) {
                return $source_id;
            }
        }

        $meta = array(
            'source_url'     => self::CALENDAR_URL,
            'source_type'    => 'Fuar',
            'parser_type'    => 'adapter',
            'source_status'  => 'active',
            'source_role'    => 'venue_enrichment',
            'source_adapter' => self::ADAPTER,
            'detected_parser'=> 'adapter',
        );
        foreach ( $meta as $key => $value ) {
            update_post_meta( $source_id, $key, $value );
        }

        return absint( $source_id );
    }

    private static function safe_get( $url ) {
        $url = esc_url_raw( $url );
        if ( ! $url || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) || 'ifm.com.tr' !== strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
            return new WP_Error( 'ifm_unsafe_url', 'İFM adapter yalnız https://ifm.com.tr hedeflerini okuyabilir.' );
        }
        $response = wp_safe_remote_get( $url, array(
            'timeout'             => self::TIMEOUT,
            'redirection'         => 3,
            'limit_response_size' => self::MAX_BODY,
            'user-agent'          => 'SektorelAjandaBot/1.0; +' . home_url( '/' ),
            'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5' ),
        ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = absint( wp_remote_retrieve_response_code( $response ) );
        if ( $code < 200 || $code >= 400 ) {
            return new WP_Error( 'ifm_http_error', 'HTTP ' . $code );
        }
        return array(
            'code' => $code,
            'body' => (string) wp_remote_retrieve_body( $response ),
        );
    }

    private static function labeled_value( $text, $label ) {
        $labels = array( 'Başlangıç Tarihi', 'Bitiş Tarihi', 'Salon', 'Fuarın Konusu', 'Organizatör', 'Web', 'Fuar Takvimi' );
        $escaped = array_map( function( $value ) { return preg_quote( $value, '/' ); }, $labels );
        $next = implode( '|', $escaped );
        if ( preg_match( '/' . preg_quote( $label, '/' ) . '\s*:\s*(.*?)(?=\s*(?:' . $next . ')\s*:|\s+Fuar Takvimi\b|$)/isu', $text, $match ) ) {
            return trim( self::clean_text( $match[1] ) );
        }
        return '';
    }

    private static function normalize_date( $value ) {
        $value = trim( (string) $value );
        if ( preg_match( '/\b(\d{2})\.(\d{2})\.(\d{4})\b/', $value, $m ) ) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        if ( preg_match( '/\b(\d{4})-(\d{2})-(\d{2})\b/', $value, $m ) ) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        return '';
    }

    private static function normalize_halls( $value ) {
        preg_match_all( '/\d{1,2}/', (string) $value, $matches );
        $halls = array_values( array_unique( array_map( 'absint', isset( $matches[0] ) ? $matches[0] : array() ) ) );
        $halls = array_values( array_filter( $halls ) );
        sort( $halls, SORT_NUMERIC );
        return $halls ? implode( ', ', $halls ) : '';
    }

    private static function extract_url( $value ) {
        if ( preg_match( '~https?://[^\s<>]+~i', (string) $value, $m ) ) {
            return rtrim( $m[0], '.,;)' );
        }
        return '';
    }

    private static function detail_web_link( $xpath, $detail_url ) {
        foreach ( $xpath->query( '//a[@href]' ) as $anchor ) {
            $href = trim( $anchor->getAttribute( 'href' ) );
            if ( ! preg_match( '~^https?://~i', $href ) ) continue;
            $host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
            if ( $host && 'ifm.com.tr' !== $host && false === strpos( $host, '.ifm.com.tr' ) ) {
                return $href;
            }
        }
        return '';
    }

    private static function first_text( $xpath, $queries ) {
        foreach ( $queries as $query ) {
            $nodes = $xpath->query( $query );
            if ( $nodes && $nodes->length ) {
                $value = self::clean_text( $nodes->item(0)->textContent );
                if ( $value ) return $value;
            }
        }
        return '';
    }

    private static function description_text( $xpath ) {
        $queries = array( '//main//p', '//article//p' );
        $parts = array();
        foreach ( $queries as $query ) {
            $nodes = $xpath->query( $query );
            if ( ! $nodes ) continue;
            foreach ( $nodes as $node ) {
                $text = self::clean_text( $node->textContent );
                if ( strlen( $text ) >= 60 ) $parts[] = $text;
                if ( count( $parts ) >= 4 ) break 2;
            }
        }
        return implode( "\n\n", array_values( array_unique( $parts ) ) );
    }

    private static function venue_value( $halls ) {
        return $halls ? 'İstanbul Fuar Merkezi · Salon ' . $halls : 'İstanbul Fuar Merkezi';
    }

    private static function is_plain_ifm_venue( $value ) {
        $norm = self::normalize_text( $value );
        return in_array( $norm, array( 'istanbul fuar merkezi', 'ifm', 'istanbul expo center' ), true );
    }

    private static function absolute_ifm_url( $href ) {
        if ( preg_match( '~^https?://~i', $href ) ) return esc_url_raw( $href );
        if ( 0 === strpos( $href, '//' ) ) return esc_url_raw( 'https:' . $href );
        return esc_url_raw( 'https://ifm.com.tr/' . ltrim( $href, '/' ) );
    }

    private static function similarity( $a, $b ) {
        if ( ! $a || ! $b ) return 0;
        if ( $a === $b ) return 100;
        similar_text( $a, $b, $percent );
        return (int) round( $percent );
    }

    private static function normalize_text( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = remove_accents( wp_strip_all_tags( $value ) );
        $value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
        $value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }

    private static function clean_text( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        return trim( preg_replace( '/\s+/u', ' ', $value ) );
    }

    private static function date_part( $value ) {
        return preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $value, $m ) ? $m[0] : '';
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_ifm_q_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }

    private static function record_source_error( $source_id, $message ) {
        update_post_meta( $source_id, 'check_state', 'error' );
        update_post_meta( $source_id, 'last_checked_at', current_time( 'mysql' ) );
        update_post_meta( $source_id, 'last_result', '' );
        update_post_meta( $source_id, 'last_error', sanitize_text_field( $message ) );
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }
}
