<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic Tüyap İstanbul enrichment adapter.
 *
 * Tüyap is enrichment-only: it may create/update candidate evidence and enrich
 * an existing event occurrence, but it never creates a new event post.
 */
class Sektorel_Event_Source_Tuyap {

    const ADAPTER         = 'tuyap_istanbul_fair_calendar';
    const SOURCE_TITLE    = 'Tüyap İstanbul Fuar Takvimi';
    const CALENDAR_URL    = 'https://tuyap.com.tr/istanbul/fuar-takvimi';
    const NONCE_ACTION    = 'sektorel_tuyap_fair_calendar';
    const BATCH_SIZE      = 3;
    const TIMEOUT         = 15;
    const MAX_BODY        = 2097152;
    const MATCH_THRESHOLD = 75;
    const QUEUE_TTL       = 2 * HOUR_IN_SECONDS;

    public static function init() {
        add_action( 'wp_ajax_sektorel_tuyap_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_tuyap_import_batch', array( __CLASS__, 'ajax_import_batch' ) );

        add_filter( 'sektorel_source_center_stages', array( __CLASS__, 'register_stage' ), 25 );
        add_filter( 'sektorel_source_background_action_map', array( __CLASS__, 'register_background_actions' ), 25 );
        add_filter( 'sektorel_source_background_nonce_actions', array( __CLASS__, 'register_nonce_actions' ), 25 );
    }

    public static function register_stage( $stages ) {
        $stage = array(
            'key'             => 'tuyap',
            'label'           => 'Tüyap Mekan / Organizatör Zenginleştirme',
            'description'     => 'Tüyap İstanbul fuar takvimini mevcut etkinliklerle eşleştirir; eksik mekan, organizatör, bitiş tarihi, resmî site ve açıklamayı tamamlar.',
            'prepare_action'  => 'sektorel_tuyap_prepare',
            'batch_action'    => 'sektorel_tuyap_import_batch',
            'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
            'prepare_payload' => array(
                'year'          => (int) current_time( 'Y' ),
                'upcoming_only' => 1,
            ),
        );

        $result   = array();
        $inserted = false;

        foreach ( (array) $stages as $existing ) {
            $result[] = $existing;
            $key = isset( $existing['key'] ) ? sanitize_key( (string) $existing['key'] ) : '';
            if ( ! $inserted && 'ifm' === $key ) {
                $result[] = $stage;
                $inserted = true;
            }
        }

        if ( ! $inserted ) {
            $result = array();
            foreach ( (array) $stages as $existing ) {
                $result[] = $existing;
                $key = isset( $existing['key'] ) ? sanitize_key( (string) $existing['key'] ) : '';
                if ( ! $inserted && 'tobb' === $key ) {
                    $result[] = $stage;
                    $inserted = true;
                }
            }
        }

        if ( ! $inserted ) {
            $result[] = $stage;
        }

        return $result;
    }

    public static function register_background_actions( $map ) {
        $map['sektorel_tuyap_prepare']      = array( __CLASS__, 'ajax_prepare' );
        $map['sektorel_tuyap_import_batch'] = array( __CLASS__, 'ajax_import_batch' );
        return $map;
    }

    public static function register_nonce_actions( $map ) {
        $map['sektorel_tuyap_prepare']      = self::NONCE_ACTION;
        $map['sektorel_tuyap_import_batch'] = self::NONCE_ACTION;
        return $map;
    }

    public static function ajax_prepare() {
        self::require_ajax();

        $year          = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : (int) current_time( 'Y' );
        $upcoming_only = ! empty( $_POST['upcoming_only'] );
        if ( $year < 2020 || $year > ( (int) current_time( 'Y' ) + 3 ) ) {
            wp_send_json_error( array( 'message' => 'Geçersiz Tüyap takvim yılı.' ) );
        }

        $source_id = self::ensure_source();
        if ( is_wp_error( $source_id ) ) {
            wp_send_json_error( array( 'message' => $source_id->get_error_message() ) );
        }

        $response = self::safe_get( self::CALENDAR_URL );
        if ( is_wp_error( $response ) ) {
            self::record_source_error( $source_id, $response->get_error_message() );
            wp_send_json_error( array( 'message' => 'Tüyap takvimi alınamadı: ' . $response->get_error_message() ) );
        }

        $urls = self::extract_detail_urls( $response['body'], $year );
        if ( ! $urls ) {
            self::record_source_error( $source_id, 'Tüyap İstanbul takviminde ilgili yıl için fuar detay bağlantısı bulunamadı.' );
            wp_send_json_error( array( 'message' => 'Tüyap İstanbul takviminde ilgili yıl için fuar detay bağlantısı bulunamadı.' ) );
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
        update_post_meta( $source_id, 'last_result', count( $urls ) . ' Tüyap İstanbul fuar detay bağlantısı bulundu.' );
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
            wp_send_json_error( array( 'message' => 'Tüyap kuyruk anahtarı eksik.' ) );
        }

        $key   = self::queue_key( get_current_user_id(), $token );
        $queue = get_transient( $key );
        if ( ! is_array( $queue ) || empty( $queue['urls'] ) || ! is_array( $queue['urls'] ) ) {
            wp_send_json_error( array( 'message' => 'Tüyap kuyruğu bulunamadı veya süresi doldu.' ) );
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
                if ( ! empty( $result['conflicts'] ) ) {
                    $message .= ', overwrite edilmeyen çakışmalar: ' . implode( ', ', $result['conflicts'] );
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
            update_post_meta( $source_id, 'last_result', 'Tüyap İstanbul adapter taraması tamamlandı.' );
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
        $existing = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => 'candidate_fingerprint',
            'meta_value'     => $fingerprint,
            'no_found_rows'  => true,
        ) );

        $candidate_id = ! empty( $existing[0] ) ? absint( $existing[0] ) : 0;
        $record_hash = sha1( wp_json_encode( array(
            $record['title'],
            $record['start_date'],
            $record['end_date'],
            $record['venue'],
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

        $meta = array(
            'candidate_fingerprint' => $fingerprint,
            'source_id'             => absint( $source_id ),
            'source_url'            => self::CALENDAR_URL,
            'event_url'             => $record['event_url'] ?: $record['detail_url'],
            'start_date'            => $record['start_date'],
            'end_date'              => $record['end_date'],
            'location_type'         => 'physical',
            'venue'                 => $record['venue'],
            'organizer'             => $record['organizer'],
            'registration_link'     => '',
            'parser_type'           => 'adapter',
            'source_adapter'        => self::ADAPTER,
            'source_record_hash'    => $record_hash,
            'tuyap_detail_url'      => $record['detail_url'],
            'tuyap_venue'           => $record['venue'],
            'source_last_seen_at'   => current_time( 'mysql' ),
        );
        foreach ( $meta as $key => $value ) {
            update_post_meta( $candidate_id, $key, $value );
        }

        $match = self::find_event_match( $record );
        if ( ! $match ) {
            if ( 'imported' !== (string) get_post_meta( $candidate_id, 'candidate_status', true ) ) {
                update_post_meta( $candidate_id, 'candidate_status', 'incomplete' );
                update_post_meta( $candidate_id, 'candidate_match_reason', 'tuyap_enrichment_unmatched' );
                delete_post_meta( $candidate_id, 'matched_event_id' );
            }
            return array(
                'candidate_result' => $candidate_result,
                'matched_event_id' => 0,
                'enriched'         => false,
                'enriched_fields'  => array(),
                'conflicts'        => array(),
            );
        }

        $event_id        = absint( $match['event_id'] );
        $conflicts       = self::detect_conflicts( $event_id, $record );
        $enriched_fields = self::enrich_event( $event_id, $record );

        update_post_meta( $candidate_id, 'candidate_status', 'imported' );
        update_post_meta( $candidate_id, 'matched_event_id', $event_id );
        update_post_meta( $candidate_id, 'candidate_match_score', absint( $match['score'] ) );
        update_post_meta( $candidate_id, 'candidate_match_reason', $conflicts ? 'tuyap_enrichment_match_with_conflict' : 'tuyap_enrichment_match' );
        update_post_meta( $candidate_id, 'candidate_cross_source_match_signals', $match['signals'] );
        update_post_meta( $candidate_id, 'tuyap_enriched_fields', $enriched_fields );
        update_post_meta( $candidate_id, 'tuyap_conflicts', $conflicts );
        update_post_meta( $candidate_id, 'imported_event_id', $event_id );

        return array(
            'candidate_result' => $candidate_result,
            'matched_event_id' => $event_id,
            'enriched'         => ! empty( $enriched_fields ),
            'enriched_fields'  => $enriched_fields,
            'conflicts'        => $conflicts,
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
        $best = null;

        foreach ( $ids as $event_id ) {
            $event_id = absint( $event_id );
            if ( self::date_part( get_post_meta( $event_id, 'start_date', true ) ) !== $date ) {
                continue;
            }

            $title_similarity = self::title_similarity( $record['title'], get_the_title( $event_id ) );
            if ( $title_similarity < 60 ) {
                continue;
            }

            $score   = 35;
            $signals = array( 'start_date_exact' );
            if ( 100 === $title_similarity ) {
                $score += 55;
                $signals[] = 'title_exact';
            } elseif ( $title_similarity >= 90 ) {
                $score += 50;
                $signals[] = 'title_90';
            } elseif ( $title_similarity >= 80 ) {
                $score += 40;
                $signals[] = 'title_80';
            } elseif ( $title_similarity >= 70 ) {
                $score += 30;
                $signals[] = 'title_70';
            } else {
                $score += 20;
                $signals[] = 'title_60';
            }

            $event_end = self::date_part( get_post_meta( $event_id, 'end_date', true ) );
            if ( $record['end_date'] && $event_end && self::date_part( $record['end_date'] ) === $event_end ) {
                $score += 10;
                $signals[] = 'end_date_exact';
            }

            $event_venue  = self::normalize_text( get_post_meta( $event_id, 'venue', true ) );
            $source_venue = self::normalize_text( $record['venue'] );
            if ( $event_venue && $source_venue ) {
                $venue_similarity = self::similarity( $event_venue, $source_venue );
                if ( 100 === $venue_similarity ) {
                    $score += 15;
                    $signals[] = 'venue_exact';
                } elseif ( $venue_similarity >= 70 ) {
                    $score += 8;
                    $signals[] = 'venue_similar';
                } elseif ( $venue_similarity < 35 ) {
                    $score -= 20;
                    $signals[] = 'venue_conflict_penalty';
                }
            }

            $event_organizer  = self::normalize_text( get_post_meta( $event_id, 'organizer', true ) );
            $source_organizer = self::normalize_text( $record['organizer'] );
            if ( $event_organizer && $source_organizer && $event_organizer === $source_organizer ) {
                $score += 10;
                $signals[] = 'organizer_exact';
            }

            $score = max( 0, min( 100, $score ) );
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

        if ( $record['event_url'] && ! trim( (string) get_post_meta( $event_id, 'event_url', true ) ) ) {
            update_post_meta( $event_id, 'event_url', esc_url_raw( $record['event_url'] ) );
            $changed[] = 'event_url';
        }

        $current_venue = trim( (string) get_post_meta( $event_id, 'venue', true ) );
        if ( $record['venue'] && ! $current_venue ) {
            update_post_meta( $event_id, 'venue', $record['venue'] );
            $changed[] = 'venue';
        } elseif ( $record['venue'] && self::is_plain_tuyap_venue( $current_venue ) && self::normalize_text( $current_venue ) !== self::normalize_text( $record['venue'] ) ) {
            update_post_meta( $event_id, 'venue', $record['venue'] );
            $changed[] = 'venue';
        }

        update_post_meta( $event_id, 'tuyap_detail_url', esc_url_raw( $record['detail_url'] ) );
        if ( $record['venue'] ) {
            update_post_meta( $event_id, 'tuyap_venue', $record['venue'] );
        }

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

    private static function detect_conflicts( $event_id, $record ) {
        $conflicts = array();

        $event_end  = self::date_part( get_post_meta( $event_id, 'end_date', true ) );
        $source_end = self::date_part( $record['end_date'] );
        if ( $event_end && $source_end && $event_end !== $source_end ) {
            $conflicts[] = 'end_date';
        }

        foreach ( array( 'venue', 'organizer' ) as $key ) {
            $current  = self::normalize_text( get_post_meta( $event_id, $key, true ) );
            $incoming = self::normalize_text( $record[ $key ] );
            if ( $current && $incoming && self::similarity( $current, $incoming ) < 45 ) {
                $conflicts[] = $key;
            }
        }

        $current_url  = self::url_host( get_post_meta( $event_id, 'event_url', true ) );
        $incoming_url = self::url_host( $record['event_url'] );
        if ( $current_url && $incoming_url && $current_url !== $incoming_url ) {
            $conflicts[] = 'event_url';
        }

        return array_values( array_unique( $conflicts ) );
    }

    private static function parse_detail( $html, $detail_url ) {
        if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
            return new WP_Error( 'tuyap_dom_missing', 'DOMDocument/DOMXPath kullanılamıyor.' );
        }

        $dom  = new DOMDocument();
        $prev = libxml_use_internal_errors( true );
        $ok   = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        if ( ! $ok ) {
            return new WP_Error( 'tuyap_html_invalid', 'Tüyap detay HTML parse edilemedi.' );
        }

        $xpath = new DOMXPath( $dom );
        $title = self::first_text( $xpath, array( '//h1[1]', '//main//h1[1]', '//article//h1[1]' ) );
        if ( ! $title ) {
            $title_node = $dom->getElementsByTagName( 'title' )->item( 0 );
            $title = $title_node ? self::clean_text( $title_node->textContent ) : '';
            $title = preg_replace( '/\s*\|\s*TÜYAP.*$/u', '', $title );
        }

        $text  = self::clean_text( $dom->textContent );
        $dates = self::calendar_dates( $text );
        if ( empty( $dates['start'] ) ) {
            $dates = self::turkish_date_range( $text );
        }

        $text_nodes  = self::text_nodes( $xpath );
        $venue       = self::extract_venue( $text_nodes );
        $organizer   = self::extract_organizer( $text_nodes, $venue );
        $event_url   = self::detail_web_link( $xpath );
        $description = self::description_text( $xpath );

        if ( ! $title || empty( $dates['start'] ) || ! $venue ) {
            return new WP_Error( 'tuyap_required_fields', 'Tüyap detayında başlık, başlangıç tarihi veya mekan eksik.' );
        }

        return array(
            'title'       => sanitize_text_field( $title ),
            'start_date'  => $dates['start'] . 'T00:00',
            'end_date'    => ! empty( $dates['end'] ) ? $dates['end'] . 'T00:00' : '',
            'venue'       => sanitize_text_field( $venue ),
            'organizer'   => sanitize_text_field( $organizer ),
            'event_url'   => esc_url_raw( $event_url ),
            'detail_url'  => esc_url_raw( $detail_url ),
            'description' => wp_kses_post( $description ),
        );
    }

    private static function extract_detail_urls( $html, $year ) {
        if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
            return array();
        }

        $dom  = new DOMDocument();
        $prev = libxml_use_internal_errors( true );
        $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        $xpath = new DOMXPath( $dom );
        $urls  = array();

        foreach ( $xpath->query( '//a[@href]' ) as $anchor ) {
            $href = trim( $anchor->getAttribute( 'href' ) );
            if ( ! $href || false === strpos( $href, '/fuarlar/' ) ) {
                continue;
            }

            $url = self::absolute_tuyap_url( $href );
            if ( ! $url || ! self::is_tuyap_host( (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
                continue;
            }

            $card_year = self::nearest_card_year( $anchor );
            if ( $card_year && absint( $card_year ) !== absint( $year ) ) {
                continue;
            }

            $urls[ $url ] = $url;
        }

        return array_values( $urls );
    }

    private static function nearest_card_year( $node ) {
        $current = $node ? $node->parentNode : null;
        for ( $depth = 0; $current && $depth < 6; $depth++, $current = $current->parentNode ) {
            $text = self::clean_text( $current->textContent );
            if ( preg_match( '/\b\d{2}\.\d{2}\.(20\d{2})\b/u', $text, $m ) ) {
                return absint( $m[1] );
            }
            if ( preg_match( '/\b(20\d{2})\b/u', $text, $m ) && strlen( $text ) < 500 ) {
                return absint( $m[1] );
            }
        }
        return 0;
    }

    private static function calendar_dates( $text ) {
        if ( preg_match( '/TAKVİME\s+EKLE\s+(20\d{2}-\d{2}-\d{2})\s+\d{1,2}:\d{2}\s*(?:AM|PM)?\s+(20\d{2}-\d{2}-\d{2})\s+\d{1,2}:\d{2}/iu', $text, $m ) ) {
            return array( 'start' => $m[1], 'end' => $m[2] );
        }
        return array( 'start' => '', 'end' => '' );
    }

    private static function turkish_date_range( $text ) {
        $months        = self::months();
        $month_pattern = implode( '|', array_map( 'preg_quote', array_keys( $months ) ) );

        if ( preg_match( '/\b(\d{1,2})\s+(' . $month_pattern . ')\s*[-–]\s*(\d{1,2})\s+(' . $month_pattern . ')\s+(20\d{2})\b/iu', $text, $m ) ) {
            return array(
                'start' => sprintf( '%04d-%02d-%02d', absint( $m[5] ), $months[ self::lower( $m[2] ) ], absint( $m[1] ) ),
                'end'   => sprintf( '%04d-%02d-%02d', absint( $m[5] ), $months[ self::lower( $m[4] ) ], absint( $m[3] ) ),
            );
        }

        if ( preg_match( '/\b(\d{1,2})\s*[-–]\s*(\d{1,2})\s+(' . $month_pattern . ')\s+(20\d{2})\b/iu', $text, $m ) ) {
            $month = $months[ self::lower( $m[3] ) ];
            return array(
                'start' => sprintf( '%04d-%02d-%02d', absint( $m[4] ), $month, absint( $m[1] ) ),
                'end'   => sprintf( '%04d-%02d-%02d', absint( $m[4] ), $month, absint( $m[2] ) ),
            );
        }

        return array( 'start' => '', 'end' => '' );
    }

    private static function months() {
        return array(
            'ocak' => 1,
            'şubat' => 2,
            'mart' => 3,
            'nisan' => 4,
            'mayıs' => 5,
            'haziran' => 6,
            'temmuz' => 7,
            'ağustos' => 8,
            'eylül' => 9,
            'ekim' => 10,
            'kasım' => 11,
            'aralık' => 12,
        );
    }

    private static function text_nodes( $xpath ) {
        $out   = array();
        $nodes = $xpath->query( '//body//text()[normalize-space()]' );
        if ( ! $nodes ) {
            return $out;
        }
        foreach ( $nodes as $node ) {
            $value = self::clean_text( $node->nodeValue );
            if ( $value ) {
                $out[] = $value;
            }
        }
        return $out;
    }

    private static function extract_venue( $nodes ) {
        foreach ( (array) $nodes as $value ) {
            $norm = self::normalize_text( $value );
            if ( strlen( $value ) > 180 || false === strpos( $norm, 'fuar' ) ) {
                continue;
            }
            if ( false !== strpos( $norm, 'merkezi' ) || false !== strpos( $norm, 'alani' ) || false !== strpos( $norm, 'center' ) ) {
                return $value;
            }
        }
        return '';
    }

    private static function extract_organizer( $nodes, $venue ) {
        $venue_index = -1;
        foreach ( (array) $nodes as $index => $value ) {
            if ( self::normalize_text( $value ) === self::normalize_text( $venue ) ) {
                $venue_index = $index;
                break;
            }
        }

        $start = $venue_index >= 0 ? $venue_index + 1 : 0;
        $limit = min( count( $nodes ), $start + 18 );
        for ( $i = $start; $i < $limit; $i++ ) {
            $value = trim( (string) $nodes[ $i ] );
            $norm  = self::normalize_text( $value );
            if ( ! $value || strlen( $value ) > 180 || self::normalize_text( $venue ) === $norm ) {
                continue;
            }
            if ( preg_match( '/\b(?:A\.?Ş\.?|fuarcılık|organizasyon|organizatör|reed|rx|alz)\b/iu', $value ) && false === strpos( $norm, 'fuari' ) ) {
                return $value;
            }
        }

        return '';
    }

    private static function detail_web_link( $xpath ) {
        foreach ( $xpath->query( '//a[@href]' ) as $anchor ) {
            $label = self::normalize_text( $anchor->textContent );
            if ( false === strpos( $label, 'fuar web sitesi' ) ) {
                continue;
            }
            $href = trim( $anchor->getAttribute( 'href' ) );
            if ( preg_match( '~^https?://~i', $href ) || 0 === strpos( $href, '//' ) ) {
                return 0 === strpos( $href, '//' ) ? 'https:' . $href : $href;
            }
        }
        return '';
    }

    private static function description_text( $xpath ) {
        $parts = array();
        foreach ( array( '//main//p', '//article//p', '//body//p' ) as $query ) {
            $nodes = $xpath->query( $query );
            if ( ! $nodes ) {
                continue;
            }
            foreach ( $nodes as $node ) {
                $text = self::clean_text( $node->textContent );
                $norm = self::normalize_text( $text );
                if ( strlen( $text ) < 80 ) {
                    continue;
                }
                if ( false !== strpos( $norm, 'detayli arama ile fuar adi' ) || false !== strpos( $norm, 'e posta adresinizi kaydedin' ) ) {
                    continue;
                }
                $parts[] = $text;
                if ( count( $parts ) >= 4 ) {
                    break 2;
                }
            }
        }
        return implode( "\n\n", array_values( array_unique( $parts ) ) );
    }

    private static function title_similarity( $a, $b ) {
        $a = self::normalize_title( $a );
        $b = self::normalize_title( $b );
        if ( ! $a || ! $b ) {
            return 0;
        }
        if ( $a === $b ) {
            return 100;
        }

        $score = self::similarity( $a, $b );
        $short = strlen( $a ) <= strlen( $b ) ? $a : $b;
        $long  = strlen( $a ) <= strlen( $b ) ? $b : $a;
        if ( strlen( $short ) >= 7 && false !== strpos( ' ' . $long . ' ', ' ' . $short . ' ' ) && ! self::generic_title( $short ) ) {
            $score = max( $score, 92 );
        }

        return $score;
    }

    private static function normalize_title( $value ) {
        $value = self::normalize_text( $value );
        $value = preg_replace( '/\b(?:fuari|fuar|uluslararasi|international|istanbul|202[0-9])\b/u', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }

    private static function generic_title( $value ) {
        return in_array( trim( $value ), array( 'fuar', 'expo', 'istanbul', 'uluslararasi', 'international' ), true );
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
            'source_url'      => self::CALENDAR_URL,
            'source_type'     => 'Fuar',
            'parser_type'     => 'adapter',
            'source_status'   => 'active',
            'source_role'     => 'organizer_enrichment',
            'source_adapter'  => self::ADAPTER,
            'detected_parser' => 'adapter',
        );
        foreach ( $meta as $key => $value ) {
            update_post_meta( $source_id, $key, $value );
        }

        return absint( $source_id );
    }

    private static function safe_get( $url ) {
        $url  = esc_url_raw( $url );
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( ! $url || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) || ! self::is_tuyap_host( $host ) ) {
            return new WP_Error( 'tuyap_unsafe_url', 'Tüyap adapter yalnız https://tuyap.com.tr hedeflerini okuyabilir.' );
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
            return new WP_Error( 'tuyap_http_error', 'HTTP ' . $code );
        }

        return array(
            'code' => $code,
            'body' => (string) wp_remote_retrieve_body( $response ),
        );
    }

    private static function is_tuyap_host( $host ) {
        $host = strtolower( trim( (string) $host ) );
        return in_array( $host, array( 'tuyap.com.tr', 'www.tuyap.com.tr' ), true );
    }

    private static function absolute_tuyap_url( $href ) {
        if ( preg_match( '~^https?://~i', $href ) ) {
            return esc_url_raw( $href );
        }
        if ( 0 === strpos( $href, '//' ) ) {
            return esc_url_raw( 'https:' . $href );
        }
        return esc_url_raw( 'https://tuyap.com.tr/' . ltrim( $href, '/' ) );
    }

    private static function first_text( $xpath, $queries ) {
        foreach ( $queries as $query ) {
            $nodes = $xpath->query( $query );
            if ( $nodes && $nodes->length ) {
                $value = self::clean_text( $nodes->item( 0 )->textContent );
                if ( $value ) {
                    return $value;
                }
            }
        }
        return '';
    }

    private static function is_plain_tuyap_venue( $value ) {
        $norm = self::normalize_text( $value );
        return in_array( $norm, array(
            'tuyap',
            'tuyap fuar merkezi',
            'tuyap fuar ve kongre merkezi',
            'tuyap istanbul fuar merkezi',
        ), true );
    }

    private static function similarity( $a, $b ) {
        if ( ! $a || ! $b ) {
            return 0;
        }
        if ( $a === $b ) {
            return 100;
        }
        similar_text( $a, $b, $percent );
        return (int) round( $percent );
    }

    private static function normalize_text( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = remove_accents( wp_strip_all_tags( $value ) );
        $value = self::lower( $value );
        $value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }

    private static function lower( $value ) {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
    }

    private static function clean_text( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        return trim( preg_replace( '/\s+/u', ' ', $value ) );
    }

    private static function date_part( $value ) {
        $value = trim( (string) $value );
        return preg_match( '/^(20\d{2}-\d{2}-\d{2})/', $value, $m ) ? $m[1] : '';
    }

    private static function url_host( $value ) {
        $host = strtolower( (string) wp_parse_url( trim( (string) $value ), PHP_URL_HOST ) );
        return preg_replace( '/^www\./', '', $host );
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_tuyap_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }

    private static function record_source_error( $source_id, $message ) {
        update_post_meta( $source_id, 'check_state', 'error' );
        update_post_meta( $source_id, 'last_checked_at', current_time( 'mysql' ) );
        update_post_meta( $source_id, 'last_error', sanitize_text_field( $message ) );
        update_post_meta( $source_id, 'last_result', '' );
        update_post_meta( $source_id, 'detected_parser', 'adapter' );
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }
}
