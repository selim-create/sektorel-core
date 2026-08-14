<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Converts deterministically safe discovery candidates to draft Events after
 * matching has completed.
 *
 * HTML candidates keep the existing safe-triage contract. JSON-LD and trusted
 * source-specific adapter candidates are eligible only when matcher explicitly
 * classified the candidate as a new no-match occurrence.
 *
 * This stage never publishes Events and excludes canonical/enrichment roles.
 */
class Sektorel_Event_Safe_Discovery_Draft_Stage {

    const NONCE_ACTION = 'sektorel_safe_discovery_drafts';
    const QUEUE_TTL    = 2 * HOUR_IN_SECONDS;
    const BATCH_SIZE   = 10;

    public static function init() {
        add_action( 'wp_ajax_sektorel_safe_discovery_drafts_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_safe_discovery_drafts_batch', array( __CLASS__, 'ajax_batch' ) );
    }

    public static function ajax_prepare() {
        self::require_ajax();

        if ( ! class_exists( 'Sektorel_Event_HTML_Safe_Convert' ) || ! class_exists( 'Sektorel_Event_Candidate_Quality' ) || ! class_exists( 'Sektorel_Event_Source_Role' ) ) {
            wp_send_json_error( array( 'message' => 'Güvenli discovery draft bağımlılıkları kullanılamıyor.' ) );
        }

        $ids   = self::eligible_candidate_ids();
        $token = strtolower( wp_generate_password( 24, false, false ) );

        set_transient(
            self::queue_key( get_current_user_id(), $token ),
            array( 'ids' => array_values( $ids ) ),
            self::QUEUE_TTL
        );

        wp_send_json_success( array(
            'token' => $token,
            'total' => count( $ids ),
        ) );
    }

    public static function ajax_batch() {
        self::require_ajax();

        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
        if ( ! $token ) {
            wp_send_json_error( array( 'message' => 'Güvenli discovery draft kuyruk anahtarı eksik.' ) );
        }

        $key   = self::queue_key( get_current_user_id(), $token );
        $queue = get_transient( $key );
        if ( ! is_array( $queue ) || ! isset( $queue['ids'] ) || ! is_array( $queue['ids'] ) ) {
            wp_send_json_error( array( 'message' => 'Güvenli discovery draft kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $ids       = array_values( array_map( 'absint', $queue['ids'] ) );
        $batch     = array_slice( $ids, $offset, self::BATCH_SIZE );
        $created   = 0;
        $unchanged = 0;
        $skipped   = 0;
        $error     = 0;
        $messages  = array();

        foreach ( $batch as $candidate_id ) {
            $eligibility = self::eligibility( $candidate_id );
            if ( is_wp_error( $eligibility ) ) {
                $skipped++;
                $messages[] = 'Atlandı: ' . get_the_title( $candidate_id ) . ' — ' . $eligibility->get_error_message();
                continue;
            }

            $result = self::convert_with_existing_engine( $candidate_id );
            if ( is_wp_error( $result ) ) {
                $error++;
                $messages[] = 'Hata: ' . get_the_title( $candidate_id ) . ' — ' . $result->get_error_message();
                continue;
            }

            if ( 'existing' === $result ) {
                $unchanged++;
                $messages[] = 'Mevcutla birleşti: ' . get_the_title( $candidate_id );
                continue;
            }

            $created_event_id = absint( $result );
            if ( $created_event_id && 'event' === get_post_type( $created_event_id ) ) {
                $final_event_id = absint( get_post_meta( $candidate_id, 'imported_event_id', true ) );
                if ( ! $final_event_id || 'event' !== get_post_type( $final_event_id ) ) {
                    $final_event_id = $created_event_id;
                }

                if ( $final_event_id !== $created_event_id ) {
                    $unchanged++;
                    $messages[] = 'Mevcutla birleşti: ' . get_the_title( $candidate_id ) . ' → Event #' . $final_event_id;
                } else {
                    $created++;
                    $messages[] = 'Yeni güvenli taslak: ' . get_the_title( $candidate_id ) . ' → Event #' . $final_event_id;
                }
            } else {
                $error++;
                $messages[] = 'Hata: ' . get_the_title( $candidate_id ) . ' — geçerli Event ID dönmedi.';
            }
        }

        $next_offset = min( count( $ids ), $offset + count( $batch ) );
        $done        = $next_offset >= count( $ids );

        if ( $done ) {
            delete_transient( $key );
        } else {
            set_transient( $key, $queue, self::QUEUE_TTL );
        }

        wp_send_json_success( array(
            'next_offset' => $next_offset,
            'done'        => $done,
            'created'     => $created,
            'unchanged'   => $unchanged,
            'skipped'     => $skipped,
            'error'       => $error,
            'updated'     => 0,
            'messages'    => $messages,
        ) );
    }

    private static function eligible_candidate_ids() {
        $ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ) );

        if ( ! $ids ) {
            return array();
        }

        update_meta_cache( 'post', $ids );
        $eligible = array();
        foreach ( $ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            if ( true === self::eligibility( $candidate_id ) ) {
                $eligible[] = $candidate_id;
            }
        }

        return $eligible;
    }

    private static function eligibility( $candidate_id ) {
        $candidate_id = absint( $candidate_id );
        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) || 'trash' === get_post_status( $candidate_id ) ) {
            return new WP_Error( 'invalid_candidate', 'Geçersiz aday.' );
        }

        if ( 'discovery' !== Sektorel_Event_Source_Role::role_for_candidate( $candidate_id ) ) {
            return new WP_Error( 'not_discovery_role', 'Kaynak rolü discovery değil.' );
        }

        $parser = sanitize_key( (string) get_post_meta( $candidate_id, 'parser_type', true ) );
        if ( 'html' === $parser ) {
            $safe = Sektorel_Event_HTML_Safe_Convert::eligibility( $candidate_id );
            if ( is_wp_error( $safe ) ) {
                return $safe;
            }
        } elseif ( 'jsonld' === $parser ) {
            $safe = self::jsonld_eligibility( $candidate_id );
            if ( is_wp_error( $safe ) ) {
                return $safe;
            }
        } elseif ( 'adapter' === $parser ) {
            $safe = self::trusted_adapter_eligibility( $candidate_id );
            if ( is_wp_error( $safe ) ) {
                return $safe;
            }
        } else {
            return new WP_Error( 'unsupported_parser', 'Bu parser tipi güvenli discovery draft akışında değil.' );
        }

        $start = self::date_part( get_post_meta( $candidate_id, 'start_date', true ) );
        $end   = self::date_part( get_post_meta( $candidate_id, 'end_date', true ) );
        $last  = $end ?: $start;
        if ( $last && $last < current_time( 'Y-m-d' ) ) {
            return new WP_Error( 'past_occurrence', 'Geçmiş occurrence otomatik taslağa dönüştürülmez.' );
        }

        return true;
    }

    private static function jsonld_eligibility( $candidate_id ) {
        return self::new_unmatched_eligibility( $candidate_id, 'JSON-LD' );
    }

    private static function trusted_adapter_eligibility( $candidate_id ) {
        $adapter = sanitize_key( (string) get_post_meta( $candidate_id, 'source_adapter', true ) );
        if ( ! in_array( $adapter, array( 'webrazzi_events', 'teknofest_events' ), true ) ) {
            return new WP_Error( 'untrusted_adapter', 'Adapter güvenli discovery allowlist içinde değil.' );
        }

        $safe = self::new_unmatched_eligibility( $candidate_id, 'Trusted adapter' );
        if ( is_wp_error( $safe ) ) {
            return $safe;
        }

        $start = self::date_part( get_post_meta( $candidate_id, 'start_date', true ) );
        $year  = $start ? substr( $start, 0, 4 ) : '';
        $title = (string) get_the_title( $candidate_id );
        if ( ! $year || false === strpos( $title, $year ) ) {
            return new WP_Error( 'adapter_occurrence_identity', 'Trusted adapter başlığı occurrence yılını doğrulamıyor.' );
        }

        return true;
    }

    private static function new_unmatched_eligibility( $candidate_id, $label ) {
        $status = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_status', true ) );
        if ( 'new' !== $status ) {
            return new WP_Error( 'not_new', 'Yalnız new durumundaki ' . $label . ' adayları dönüştürülebilir.' );
        }

        if ( absint( get_post_meta( $candidate_id, 'matched_event_id', true ) ) ) {
            return new WP_Error( 'matched_event', $label . ' adayı mevcut bir etkinlikle eşleşiyor.' );
        }

        if ( absint( get_post_meta( $candidate_id, 'imported_event_id', true ) ) ) {
            return new WP_Error( 'already_imported', $label . ' adayı daha önce dönüştürülmüş.' );
        }

        $match_reason = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_match_reason', true ) );
        if ( 'no_match' !== $match_reason ) {
            return new WP_Error( 'match_not_clear', 'Matcher ' . $label . ' adayını açık şekilde yeni olarak doğrulamamış.' );
        }

        $start = self::date_part( get_post_meta( $candidate_id, 'start_date', true ) );
        if ( ! $start ) {
            return new WP_Error( 'missing_start', $label . ' adayının başlangıç tarihi eksik.' );
        }

        $end = self::date_part( get_post_meta( $candidate_id, 'end_date', true ) );
        if ( $end && $end < $start ) {
            return new WP_Error( 'invalid_date_range', $label . ' adayının bitiş tarihi başlangıç tarihinden önce.' );
        }

        return true;
    }

    private static function convert_with_existing_engine( $candidate_id ) {
        $gateway = Closure::bind(
            static function ( $id ) {
                return Sektorel_Event_Candidate_Quality::convert_candidate( $id );
            },
            null,
            'Sektorel_Event_Candidate_Quality'
        );

        if ( ! $gateway ) {
            return new WP_Error( 'safe_discovery_converter_unavailable', 'Mevcut candidate dönüşüm motoruna erişilemedi.' );
        }

        try {
            return $gateway( absint( $candidate_id ) );
        } catch ( Throwable $e ) {
            return new WP_Error( 'safe_discovery_converter_runtime', $e->getMessage() );
        }
    }

    private static function date_part( $value ) {
        return preg_match( '/^(\d{4}-\d{2}-\d{2})/', (string) $value, $m ) ? $m[1] : '';
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_safe_discovery_drafts_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }
}
