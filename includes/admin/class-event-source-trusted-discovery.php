<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Phase 4 trusted discovery adapters.
 *
 * These are deterministic, source-specific discovery adapters for high-value
 * official event pages. They only create/update event_candidate posts; Event
 * creation stays in the existing safe discovery draft stage.
 */
class Sektorel_Event_Source_Trusted_Discovery {

    const NONCE_ACTION = 'sektorel_trusted_discovery';
    const QUEUE_TTL    = 2 * HOUR_IN_SECONDS;
    const BATCH_SIZE   = 5;
    const TIMEOUT      = 15;
    const MAX_BODY     = 2097152;

    public static function init() {
        add_action( 'wp_ajax_sektorel_trusted_discovery_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_trusted_discovery_batch', array( __CLASS__, 'ajax_batch' ) );
    }

    public static function ajax_prepare() {
        self::require_ajax();

        $year = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : (int) current_time( 'Y' );
        if ( $year < 2025 || $year > ( (int) current_time( 'Y' ) + 2 ) ) {
            wp_send_json_error( array( 'message' => 'Geçersiz trusted discovery yılı.' ) );
        }

        $jobs = array(
            array( 'profile' => 'webrazzi_events', 'year' => $year ),
            array( 'profile' => 'teknofest_events', 'year' => $year ),
        );

        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient( self::queue_key( get_current_user_id(), $token ), $jobs, self::QUEUE_TTL );

        wp_send_json_success( array(
            'token' => $token,
            'total' => count( $jobs ),
        ) );
    }

    public static function ajax_batch() {
        self::require_ajax();

        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
        $key    = self::queue_key( get_current_user_id(), $token );
        $jobs   = get_transient( $key );

        if ( ! $token || ! is_array( $jobs ) ) {
            wp_send_json_error( array( 'message' => 'Trusted discovery kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $batch = array_slice( array_values( $jobs ), $offset, self::BATCH_SIZE );
        $created = $updated = $unchanged = $skipped = $error = 0;
        $messages = array();

        foreach ( $batch as $job ) {
            $profile = isset( $job['profile'] ) ? sanitize_key( $job['profile'] ) : '';
            $year    = isset( $job['year'] ) ? absint( $job['year'] ) : (int) current_time( 'Y' );

            if ( 'webrazzi_events' === $profile ) {
                $result = self::scan_webrazzi( $year );
            } elseif ( 'teknofest_events' === $profile ) {
                $result = self::scan_teknofest( $year );
            } else {
                $result = new WP_Error( 'unknown_profile', 'Bilinmeyen trusted discovery profili.' );
            }

            if ( is_wp_error( $result ) ) {
                $error++;
                $messages[] = 'Hata: ' . $profile . ' — ' . $result->get_error_message();
                continue;
            }

            foreach ( array( 'created', 'updated', 'unchanged', 'skipped', 'error' ) as $stat ) {
                if ( isset( $result[ $stat ] ) ) {
                    ${$stat} += absint( $result[ $stat ] );
                }
            }
            if ( ! empty( $result['messages'] ) ) {
                $messages = array_merge( $messages, array_slice( (array) $result['messages'], 0, 20 ) );
            }
        }

        $next = min( count( $jobs ), $offset + count( $batch ) );
        $done = $next >= count( $jobs );
        if ( $done ) {
            delete_transient( $key );
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

    private static function scan_webrazzi( $year ) {
        $calendar_url = 'https://webrazzi.com/etkinlik/';
        $source_id    = self::ensure_source( 'Webrazzi Events', $calendar_url, 'webrazzi_events' );
        if ( is_wp_error( $source_id ) ) {
            return $source_id;
        }

        $response = self::safe_get( $calendar_url );
        if ( is_wp_error( $response ) ) {
            self::record_source_error( $source_id, $response->get_error_message() );
            return $response;
        }

        $entries = self::webrazzi_calendar_entries( $response['body'], $year );
        if ( ! $entries ) {
            self::record_source_error( $source_id, 'Webrazzi yaklaşan etkinlik kartları doğrulanamadı.' );
            return new WP_Error( 'webrazzi_calendar_entries_missing', 'Webrazzi yaklaşan etkinlik kartları doğrulanamadı.' );
        }

        $stats = self::empty_stats();
        foreach ( $entries as $entry ) {
            $existing_id = self::webrazzi_existing_candidate( $source_id, $entry );
            if ( $existing_id ) {
                update_post_meta( $existing_id, 'source_last_seen_at', current_time( 'mysql' ) );
                $stats['unchanged']++;
                continue;
            }

            $record = null;
            $detail = self::safe_get( $entry['url'] );
            if ( ! is_wp_error( $detail ) ) {
                $parsed = self::parse_webrazzi_detail( $detail['body'], $entry['url'], $year );
                if ( ! is_wp_error( $parsed ) && $entry['date'] === substr( (string) $parsed['start_date'], 0, 10 ) ) {
                    $record = $parsed;
                }
            }

            if ( ! $record ) {
                $record = self::webrazzi_calendar_record( $entry, $calendar_url, $year );
                if ( is_wp_error( $record ) ) {
                    $stats['error']++;
                    $stats['messages'][] = $record->get_error_message();
                    continue;
                }
                $stats['messages'][] = 'Webrazzi detail yerine resmî takvim kanıtı kullanıldı: ' . $entry['title'];
            }

            self::apply_upsert_result( $stats, self::upsert_candidate( $source_id, 'webrazzi_events', $record ) );
        }

        self::record_source_success( $source_id, count( $entries ) . ' yaklaşan Webrazzi occurrence işlendi.' );
        return $stats;
    }

    private static function scan_teknofest( $year ) {
        $url       = 'https://www.teknofest.org/tr/content/teknofest-events/';
        $source_id = self::ensure_source( 'TEKNOFEST Etkinlikleri', $url, 'teknofest_events' );
        if ( is_wp_error( $source_id ) ) {
            return $source_id;
        }

        $response = self::safe_get( $url );
        if ( is_wp_error( $response ) ) {
            self::record_source_error( $source_id, $response->get_error_message() );
            return $response;
        }

        $records = self::parse_teknofest_year( $response['body'], $url, $year );
        if ( is_wp_error( $records ) ) {
            self::record_source_error( $source_id, $records->get_error_message() );
            return $records;
        }

        $stats = self::empty_stats();
        foreach ( $records as $record ) {
            self::apply_upsert_result( $stats, self::upsert_candidate( $source_id, 'teknofest_events', $record ) );
        }

        self::record_source_success( $source_id, count( $records ) . ' TEKNOFEST occurrence işlendi.' );
        return $stats;
    }

    private static function webrazzi_calendar_entries( $html, $year ) {
        $dom = self::dom( $html );
        if ( ! $dom ) {
            return array();
        }

        $xpath   = new DOMXPath( $dom );
        $entries = array();
        $today   = current_time( 'Y-m-d' );

        foreach ( $xpath->query( '//a[@href]' ) as $node ) {
            $title = self::clean_text( $node->textContent );
            if ( ! $title || false === stripos( $title, (string) $year ) ) {
                continue;
            }

            $href = self::absolute_url( $node->getAttribute( 'href' ), 'https://webrazzi.com' );
            if ( ! preg_match( '#^https://webrazzi\.com/etkinlik/' . preg_quote( (string) $year, '#' ) . '/[^/?#]+/?$#i', $href ) ) {
                continue;
            }

            $date = self::webrazzi_date_from_scope( $node, $title, $year );
            if ( ! $date || $date < $today ) {
                continue;
            }

            $key = untrailingslashit( $href );
            $entries[ $key ] = array(
                'title' => $title,
                'date'  => $date,
                'url'   => $href,
            );
        }

        return array_values( $entries );
    }

    private static function webrazzi_date_from_scope( $node, $title, $year ) {
        $scope = $node;
        for ( $depth = 0; $depth < 6; $depth++ ) {
            $scope = $scope ? $scope->parentNode : null;
            if ( ! $scope ) {
                break;
            }

            $text = self::clean_text( $scope->textContent );
            if ( ! $text || false === stripos( $text, $title ) || strlen( $text ) > 1200 ) {
                continue;
            }

            $date = self::first_turkish_date( $text, $year );
            if ( $date ) {
                return $date;
            }
        }
        return '';
    }

    private static function webrazzi_existing_candidate( $source_id, $entry ) {
        $fingerprint = sha1(
            absint( $source_id ) . '|' . self::normalize_text( $entry['title'] ) . '|' . $entry['date']
        );
        $ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => 'candidate_fingerprint',
            'meta_value'     => $fingerprint,
            'no_found_rows'  => true,
        ) );
        if ( empty( $ids[0] ) ) {
            return 0;
        }

        $candidate_id = absint( $ids[0] );
        return 'webrazzi_events' === sanitize_key( (string) get_post_meta( $candidate_id, 'source_adapter', true ) )
            ? $candidate_id
            : 0;
    }

    private static function webrazzi_calendar_record( $entry, $calendar_url, $year ) {
        $title = isset( $entry['title'] ) ? self::clean_text( $entry['title'] ) : '';
        $date  = isset( $entry['date'] ) ? trim( (string) $entry['date'] ) : '';
        $url   = isset( $entry['url'] ) ? esc_url_raw( $entry['url'] ) : '';

        if ( ! $title || false === stripos( $title, (string) $year ) || ! preg_match( '/^20\d{2}-\d{2}-\d{2}$/', $date ) || ! $url ) {
            return new WP_Error( 'webrazzi_calendar_record_invalid', 'Webrazzi takvim occurrence kimliği doğrulanamadı.' );
        }

        return array(
            'title'             => $title,
            'start_date'        => $date . ' 00:00:00',
            'end_date'          => $date . ' 00:00:00',
            'location_type'     => 'physical',
            'venue'             => '',
            'address'           => 'İstanbul',
            'organizer'         => '',
            'registration_link' => '',
            'event_url'         => $url,
            'source_url'        => $calendar_url,
            'description'       => '',
        );
    }

    private static function parse_webrazzi_detail( $html, $url, $year ) {
        $dom = self::dom( $html );
        if ( ! $dom ) {
            return new WP_Error( 'webrazzi_dom', 'Webrazzi HTML parse edilemedi.' );
        }
        $xpath = new DOMXPath( $dom );

        $title = self::first_text( $xpath, array( '//h1[1]', "//meta[@property='og:title']/@content" ) );
        $body  = self::clean_text( $dom->textContent );
        if ( ! $title || false === stripos( $title, (string) $year ) ) {
            return new WP_Error( 'webrazzi_title', 'Webrazzi occurrence başlığı doğrulanamadı.' );
        }

        $date = self::first_turkish_date( $body, $year );
        if ( ! $date ) {
            return new WP_Error( 'webrazzi_date', 'Webrazzi occurrence tarihi doğrulanamadı.' );
        }

        $venue = '';
        foreach ( $xpath->query( '//h2|//h3|//h4|//h5|//h6' ) as $heading ) {
            $text = self::clean_text( $heading->textContent );
            if ( ! $text || preg_match( '/\b\d{1,2}\s+(Ocak|Şubat|Mart|Nisan|Mayıs|Haziran|Temmuz|Ağustos|Eylül|Ekim|Kasım|Aralık)\s+' . preg_quote( (string) $year, '/' ) . '\b/iu', $text ) ) {
                continue;
            }
            if ( false !== stripos( $text, 'Hotel' ) || false !== stripos( $text, 'Center' ) || false !== stripos( $text, 'Merkezi' ) || false !== stripos( $text, 'İstanbul' ) ) {
                $venue = $text;
                break;
            }
        }

        $organizer = false !== stripos( $body, 'Crenvo İK tarafından düzenlenen' ) ? 'Crenvo İK' : 'Webrazzi';

        return array(
            'title'             => $title,
            'start_date'        => $date . ' 00:00:00',
            'end_date'          => $date . ' 00:00:00',
            'location_type'     => 'physical',
            'venue'             => $venue,
            'address'           => '',
            'organizer'         => $organizer,
            'registration_link' => self::first_link_by_text( $xpath, array( 'Bilet Al', 'Bilet' ), $url ),
            'event_url'         => $url,
            'source_url'        => $url,
            'description'       => '',
        );
    }

    private static function parse_teknofest_year( $html, $url, $year ) {
        if ( 2026 !== absint( $year ) ) {
            return new WP_Error( 'teknofest_year_not_supported', 'TEKNOFEST adapterı şu an doğrulanmış 2026 occurrence yapısını destekliyor.' );
        }

        $text = self::clean_text( wp_strip_all_tags( $html ) );
        if ( false === stripos( $text, 'TEKNOFEST' ) || false === strpos( $text, '2026' ) ) {
            return new WP_Error( 'teknofest_identity', 'TEKNOFEST 2026 kaynak kimliği doğrulanamadı.' );
        }

        $records = array();
        if ( preg_match( '/20\s*[-–]\s*23\s+Ağustos[^.]{0,220}Gölcük\s+Tersanesi\s+Komutanlığı/iu', $text ) ) {
            $records[] = array(
                'title' => 'TEKNOFEST 2026 Mavi Vatan',
                'start_date' => '2026-08-20 00:00:00',
                'end_date' => '2026-08-23 00:00:00',
                'location_type' => 'physical',
                'venue' => 'Gölcük Tersanesi Komutanlığı',
                'address' => 'Gölcük, Kocaeli',
                'organizer' => 'Türkiye Teknoloji Takımı Vakfı (T3 Vakfı) / T.C. Sanayi ve Teknoloji Bakanlığı',
                'registration_link' => '',
                'event_url' => $url,
                'source_url' => $url,
                'description' => '',
            );
        }

        if ( preg_match( '/30\s+Eylül\s*[-–]\s*4\s+Ekim[^.]{0,220}(?:Şanlıurfa\s+)?GAP\s+Havalimanı/iu', $text ) ) {
            $records[] = array(
                'title' => 'TEKNOFEST 2026 Şanlıurfa',
                'start_date' => '2026-09-30 00:00:00',
                'end_date' => '2026-10-04 00:00:00',
                'location_type' => 'physical',
                'venue' => 'Şanlıurfa GAP Havalimanı',
                'address' => 'Şanlıurfa',
                'organizer' => 'Türkiye Teknoloji Takımı Vakfı (T3 Vakfı) / T.C. Sanayi ve Teknoloji Bakanlığı',
                'registration_link' => '',
                'event_url' => $url,
                'source_url' => $url,
                'description' => '',
            );
        }

        if ( ! $records ) {
            return new WP_Error( 'teknofest_occurrences_missing', 'Doğrulanmış TEKNOFEST 2026 occurrence blokları bulunamadı.' );
        }

        return $records;
    }

    private static function upsert_candidate( $source_id, $adapter, $record ) {
        $title = self::clean_text( $record['title'] );
        $start = trim( (string) $record['start_date'] );
        if ( ! $title || ! preg_match( '/^20\d{2}-\d{2}-\d{2}/', $start ) ) {
            return new WP_Error( 'trusted_record_invalid', 'Trusted discovery kaydı title/start_date içermiyor.' );
        }

        $fingerprint = sha1( absint( $source_id ) . '|' . self::normalize_text( $title ) . '|' . substr( $start, 0, 10 ) );
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

        $hash = sha1( wp_json_encode( $record ) );
        $old_hash = $candidate_id ? (string) get_post_meta( $candidate_id, 'source_record_hash', true ) : '';
        if ( $candidate_id && $hash === $old_hash ) {
            update_post_meta( $candidate_id, 'source_last_seen_at', current_time( 'mysql' ) );
            return 'unchanged';
        }

        $postarr = array(
            'post_type'    => 'event_candidate',
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => isset( $record['description'] ) ? (string) $record['description'] : '',
        );
        if ( $candidate_id ) {
            $postarr['ID'] = $candidate_id;
            $saved = wp_update_post( $postarr, true );
            $result = 'updated';
        } else {
            $saved = wp_insert_post( $postarr, true );
            $result = 'created';
        }
        if ( is_wp_error( $saved ) ) {
            return $saved;
        }
        $candidate_id = absint( $saved );

        $meta = array(
            'candidate_fingerprint' => $fingerprint,
            'source_id'             => absint( $source_id ),
            'source_url'            => esc_url_raw( $record['source_url'] ),
            'event_url'             => esc_url_raw( $record['event_url'] ),
            'start_date'            => $start,
            'end_date'              => trim( (string) $record['end_date'] ),
            'location_type'         => sanitize_key( $record['location_type'] ),
            'venue'                 => self::clean_text( $record['venue'] ),
            'address'               => self::clean_text( $record['address'] ),
            'organizer'             => self::clean_text( $record['organizer'] ),
            'registration_link'     => esc_url_raw( $record['registration_link'] ),
            'parser_type'           => 'adapter',
            'source_adapter'        => $adapter,
            'source_record_hash'    => $hash,
            'source_last_seen_at'   => current_time( 'mysql' ),
        );
        foreach ( $meta as $key => $value ) {
            update_post_meta( $candidate_id, $key, $value );
        }

        if ( ! absint( get_post_meta( $candidate_id, 'imported_event_id', true ) ) ) {
            update_post_meta( $candidate_id, 'candidate_status', 'new' );
            update_post_meta( $candidate_id, 'candidate_match_reason', 'pending_match' );
            delete_post_meta( $candidate_id, 'matched_event_id' );
            delete_post_meta( $candidate_id, 'candidate_match_signature' );
        }

        return $result;
    }

    private static function ensure_source( $title, $url, $adapter ) {
        $ids = get_posts( array(
            'post_type'      => 'event_source',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => 'source_adapter',
            'meta_value'     => $adapter,
            'no_found_rows'  => true,
        ) );

        $source_id = ! empty( $ids[0] ) ? absint( $ids[0] ) : 0;
        if ( ! $source_id ) {
            $source_id = wp_insert_post( array(
                'post_type'   => 'event_source',
                'post_status' => 'publish',
                'post_title'  => $title,
            ), true );
            if ( is_wp_error( $source_id ) ) {
                return $source_id;
            }
            $source_id = absint( $source_id );
        }

        update_post_meta( $source_id, 'source_url', esc_url_raw( $url ) );
        update_post_meta( $source_id, 'source_type', 'Etkinlik Takvimi' );
        update_post_meta( $source_id, 'parser_type', 'adapter' );
        update_post_meta( $source_id, 'detected_parser', 'adapter' );
        update_post_meta( $source_id, 'source_status', 'active' );
        update_post_meta( $source_id, 'source_role', 'discovery' );
        update_post_meta( $source_id, 'source_adapter', $adapter );
        return $source_id;
    }

    private static function safe_get( $url ) {
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
            return new WP_Error( 'trusted_http_error', 'Kaynak HTTP ' . $code . ' döndürdü.' );
        }
        return array( 'code' => $code, 'body' => (string) wp_remote_retrieve_body( $response ) );
    }

    private static function dom( $html ) {
        if ( ! class_exists( 'DOMDocument' ) || '' === trim( (string) $html ) ) {
            return null;
        }
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors( true );
        $ok = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        return $ok ? $dom : null;
    }

    private static function first_text( $xpath, $queries ) {
        foreach ( $queries as $query ) {
            $nodes = $xpath->query( $query );
            if ( ! $nodes ) continue;
            foreach ( $nodes as $node ) {
                $text = self::clean_text( $node->nodeValue );
                if ( $text ) return $text;
            }
        }
        return '';
    }

    private static function first_link_by_text( $xpath, $needles, $base_url ) {
        foreach ( $xpath->query( '//a[@href]' ) as $node ) {
            $text = self::clean_text( $node->textContent );
            foreach ( $needles as $needle ) {
                if ( false !== stripos( $text, $needle ) ) {
                    return self::absolute_url( $node->getAttribute( 'href' ), $base_url );
                }
            }
        }
        return '';
    }

    private static function first_turkish_date( $text, $year ) {
        $months = array(
            'ocak'=>1,'şubat'=>2,'mart'=>3,'nisan'=>4,'mayıs'=>5,'haziran'=>6,
            'temmuz'=>7,'ağustos'=>8,'eylül'=>9,'ekim'=>10,'kasım'=>11,'aralık'=>12,
        );
        if ( ! preg_match( '/\b(\d{1,2})\s+(Ocak|Şubat|Mart|Nisan|Mayıs|Haziran|Temmuz|Ağustos|Eylül|Ekim|Kasım|Aralık)\s+' . preg_quote( (string) $year, '/' ) . '\b/iu', $text, $m ) ) {
            return '';
        }
        $month_key = mb_strtolower( $m[2], 'UTF-8' );
        if ( ! isset( $months[ $month_key ] ) ) return '';
        return sprintf( '%04d-%02d-%02d', absint( $year ), $months[ $month_key ], absint( $m[1] ) );
    }

    private static function absolute_url( $href, $base ) {
        $href = trim( (string) $href );
        if ( ! $href ) return '';
        if ( preg_match( '#^https?://#i', $href ) ) return esc_url_raw( $href );
        $parts = wp_parse_url( $base );
        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
        return esc_url_raw( $parts['scheme'] . '://' . $parts['host'] . '/' . ltrim( $href, '/' ) );
    }

    private static function apply_upsert_result( &$stats, $result ) {
        if ( is_wp_error( $result ) ) {
            $stats['error']++;
            $stats['messages'][] = $result->get_error_message();
            return;
        }
        if ( isset( $stats[ $result ] ) ) {
            $stats[ $result ]++;
        } else {
            $stats['skipped']++;
        }
    }

    private static function empty_stats() {
        return array( 'created'=>0, 'updated'=>0, 'unchanged'=>0, 'skipped'=>0, 'error'=>0, 'messages'=>array() );
    }

    private static function record_source_success( $source_id, $message ) {
        update_post_meta( $source_id, 'check_state', 'ok' );
        update_post_meta( $source_id, 'last_checked_at', current_time( 'mysql' ) );
        update_post_meta( $source_id, 'last_result', sanitize_text_field( $message ) );
        update_post_meta( $source_id, 'last_error', '' );
    }

    private static function record_source_error( $source_id, $message ) {
        update_post_meta( $source_id, 'check_state', 'error' );
        update_post_meta( $source_id, 'last_checked_at', current_time( 'mysql' ) );
        update_post_meta( $source_id, 'last_error', sanitize_text_field( $message ) );
    }

    private static function normalize_text( $text ) {
        $text = strtolower( remove_accents( self::clean_text( $text ) ) );
        $text = preg_replace( '/[^a-z0-9]+/i', ' ', $text );
        return trim( preg_replace( '/\s+/', ' ', $text ) );
    }

    private static function clean_text( $text ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', $text ) );
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_trusted_discovery_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }
}
