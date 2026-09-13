<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-content-editorial-architecture.php';
require_once __DIR__ . '/class-content-source-policy.php';

/**
 * Deterministic pre-AI triage for content candidates.
 * Never calls AI and never creates posts.
 */
class Sektorel_Content_Candidate_Triage {

    const NONCE_ACTION = 'sektorel_content_candidate_triage';
    const BATCH_SIZE = 25;
    const DEFAULT_FRESHNESS_DAYS = 45;
    const TRIAGE_VERSION = 5;
    const RUN_TTL = 30 * MINUTE_IN_SECONDS;

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }

        Sektorel_Content_Editorial_Architecture::init();
        add_action( 'wp_ajax_sektorel_content_triage_batch', array( __CLASS__, 'ajax_triage_batch' ) );
    }

    public static function nonce() {
        return wp_create_nonce( self::NONCE_ACTION );
    }

    public static function ajax_triage_batch() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }

        global $wpdb;
        $table   = Sektorel_Content_Candidates::table_name();
        $run_key = self::run_key( get_current_user_id() );
        $active  = get_transient( $run_key );

        if ( ! $active ) {
            $new_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                Sektorel_Content_Candidates::STATUS_NEW
            ) );

            if ( 0 === $new_count ) {
                $review_count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                    Sektorel_Content_Candidates::STATUS_REVIEW
                ) );

                if ( $review_count > 0 ) {
                    $wpdb->update(
                        $table,
                        array(
                            'status'     => Sektorel_Content_Candidates::STATUS_NEW,
                            'updated_at' => current_time( 'mysql', true ),
                        ),
                        array( 'status' => Sektorel_Content_Candidates::STATUS_REVIEW ),
                        array( '%s', '%s' ),
                        array( '%s' )
                    );
                }
            }

            set_transient( $run_key, array( 'started_at' => time() ), self::RUN_TTL );
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d",
                Sektorel_Content_Candidates::STATUS_NEW,
                self::BATCH_SIZE
            ),
            ARRAY_A
        );

        if ( ! $rows ) {
            delete_transient( $run_key );
            wp_send_json_success( array(
                'processed' => 0,
                'ready'     => 0,
                'review'    => 0,
                'done'      => true,
                'messages'  => array( 'Değerlendirilecek candidate kalmadı.' ),
            ) );
        }

        $ready = 0;
        $review = 0;
        $messages = array();

        foreach ( $rows as $row ) {
            if (
                empty( $row['published_at'] )
                && in_array( sanitize_key( $row['source_key'] ?? '' ), array( 'tobb_news', 'tobb_announcements' ), true )
                && class_exists( 'Sektorel_Content_Source_TOBB_Detail_Date' )
            ) {
                $row = Sektorel_Content_Source_TOBB_Detail_Date::enrich_candidate_for_triage( $row );
            }

            $result = self::evaluate( $row );
            $saved  = self::persist( (int) $row['id'], $row, $result );

            if ( is_wp_error( $saved ) ) {
                $review++;
                $messages[] = 'Hata #' . (int) $row['id'] . ': ' . $saved->get_error_message();
                continue;
            }

            if ( Sektorel_Content_Candidates::STATUS_READY === $result['status'] ) {
                $ready++;
            } else {
                $review++;
            }

            $messages[] = sprintf(
                '#%d %s → %s (%s)',
                (int) $row['id'],
                self::short_title( $row['title'] ?? '' ),
                $result['status'],
                implode( ', ', $result['reasons'] )
            );
        }

        $remaining = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %s",
            Sektorel_Content_Candidates::STATUS_NEW
        ) );

        if ( 0 === $remaining ) {
            delete_transient( $run_key );
        } else {
            set_transient( $run_key, array( 'started_at' => time() ), self::RUN_TTL );
        }

        wp_send_json_success( array(
            'processed' => count( $rows ),
            'ready'     => $ready,
            'review'    => $review,
            'remaining' => $remaining,
            'done'      => 0 === $remaining,
            'messages'  => $messages,
        ) );
    }

    public static function evaluate( $candidate ) {
        $candidate = is_array( $candidate ) ? $candidate : array();
        $title        = trim( (string) ( $candidate['title'] ?? '' ) );
        $url          = trim( (string) ( $candidate['canonical_url'] ?? $candidate['source_url'] ?? '' ) );
        $summary      = trim( (string) ( $candidate['extracted_text'] ?? '' ) );
        $published_at = trim( (string) ( $candidate['published_at'] ?? '' ) );
        $source_id    = absint( $candidate['source_id'] ?? 0 );
        $source_key   = sanitize_key( $candidate['source_key'] ?? '' );
        $routing_text = trim( $title . ' ' . $summary );

        $freshness_days       = self::source_freshness_days( $source_id );
        $age_days             = self::age_days( $published_at );
        $source_categories    = self::source_categories( $source_id, $candidate );
        $source_primary       = self::source_primary_desk( $source_key, $source_id );
        $suggested_categories = self::suggest_categories( $routing_text, $source_categories, $source_primary );
        $primary_category     = $suggested_categories ? (string) $suggested_categories[0] : '';
        $primary_source       = $source_primary && $primary_category === $source_primary ? 'source_coverage' : 'deterministic_text';
        $topic_tags           = Sektorel_Content_Editorial_Architecture::topic_tags_for_text( $routing_text );
        $content_format       = Sektorel_Content_Editorial_Architecture::content_format_for_text( $routing_text );

        $blocking = array();
        $positive = array();

        if ( '' === $title || mb_strlen( $title ) < 8 ) {
            $blocking[] = 'başlık yetersiz';
        } else {
            $positive[] = 'başlık uygun';
        }

        if ( ! self::valid_public_http_url( $url ) ) {
            $blocking[] = 'URL eksik/geçersiz';
        } else {
            $positive[] = 'URL uygun';
        }

        if ( null === $age_days ) {
            $blocking[] = 'yayın tarihi yok';
            $diagnostic = self::publication_date_diagnostic( $candidate );
            if ( $diagnostic ) {
                $blocking[] = $diagnostic;
            }
        } elseif ( $age_days < -2 ) {
            $blocking[] = 'gelecek tarihli';
        } elseif ( $age_days > $freshness_days ) {
            $blocking[] = 'eski içerik (' . $age_days . ' gün)';
        } else {
            $positive[] = 'güncel (' . max( 0, $age_days ) . ' gün)';
        }

        if ( '' === $primary_category ) {
            $blocking[] = 'primary desk eşleşmesi yok';
        } else {
            $positive[] = 'primary desk: ' . $primary_category;
            if ( 'source_coverage' === $primary_source ) {
                $positive[] = 'primary desk kaynağı: source coverage';
            }
        }

        if ( $topic_tags ) {
            $positive[] = 'topic: ' . implode( '+', $topic_tags );
        }

        if ( '' === $summary ) {
            $positive[] = 'özet yok; detay fetch gerekli';
        } elseif ( mb_strlen( $summary ) < 40 ) {
            $positive[] = 'kısa özet; detay fetch önerilir';
        } else {
            $positive[] = 'özet mevcut';
        }

        $status = empty( $blocking )
            ? Sektorel_Content_Candidates::STATUS_READY
            : Sektorel_Content_Candidates::STATUS_REVIEW;

        return array(
            'status'                  => $status,
            'reasons'                 => empty( $blocking ) ? $positive : array_merge( $blocking, $positive ),
            'blocking_reasons'        => $blocking,
            'positive_signals'        => $positive,
            'age_days'                => $age_days,
            'freshness_days'          => $freshness_days,
            'primary_category'        => $primary_category,
            'primary_category_source' => $primary_source,
            // Keep the legacy field bounded to exactly one item while older
            // processor code is still deployed alongside schema v2 candidates.
            'suggested_categories'    => $suggested_categories,
            'topic_tags'              => $topic_tags,
            'content_format'          => $content_format,
            'triaged_at'              => gmdate( 'c' ),
            'version'                 => self::TRIAGE_VERSION,
            'schema_version'          => Sektorel_Content_Editorial_Architecture::SCHEMA_VERSION,
        );
    }

    private static function persist( $candidate_id, $candidate, $triage ) {
        global $wpdb;
        $table = Sektorel_Content_Candidates::table_name();

        $evidence = array();
        if ( ! empty( $candidate['evidence_json'] ) ) {
            $decoded = json_decode( (string) $candidate['evidence_json'], true );
            if ( is_array( $decoded ) ) {
                $evidence = $decoded;
            }
        }
        $evidence['triage'] = $triage;

        $normalized = array();
        if ( ! empty( $candidate['normalized_payload'] ) ) {
            $decoded = json_decode( (string) $candidate['normalized_payload'], true );
            if ( is_array( $decoded ) ) {
                $normalized = $decoded;
            }
        }

        $normalized = Sektorel_Content_Editorial_Architecture::normalize_dimensions(
            $normalized,
            $triage['primary_category'] ?? '',
            $triage['topic_tags'] ?? array(),
            $triage['content_format'] ?? 'news'
        );
        $normalized['triage_status'] = $triage['status'];

        $updated = $wpdb->update(
            $table,
            array(
                'status'             => $triage['status'],
                'evidence_json'      => wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'normalized_payload' => wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'updated_at'         => current_time( 'mysql', true ),
            ),
            array( 'id' => absint( $candidate_id ) ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        return false === $updated
            ? new WP_Error( 'content_triage_update_failed', $wpdb->last_error ?: 'Candidate triage sonucu kaydedilemedi.' )
            : true;
    }

    private static function publication_date_diagnostic( $candidate ) {
        $source_key = sanitize_key( $candidate['source_key'] ?? '' );
        if ( ! in_array( $source_key, array( 'tobb_news', 'tobb_announcements' ), true ) ) {
            return '';
        }

        $evidence = array();
        if ( ! empty( $candidate['evidence_json'] ) ) {
            $decoded = json_decode( (string) $candidate['evidence_json'], true );
            if ( is_array( $decoded ) ) {
                $evidence = $decoded;
            }
        }

        if ( ! empty( $evidence['publication_date_resolution']['error_code'] ) ) {
            $code = sanitize_key( $evidence['publication_date_resolution']['error_code'] );
            $http = absint( $evidence['publication_date_resolution']['http_status'] ?? 0 );
            return 'TOBB tarih çözümleme: ' . $code . ( $http ? ' (HTTP ' . $http . ')' : '' );
        }

        if ( ! empty( $evidence['tobb_candidate_identity']['error_code'] ) ) {
            return 'TOBB kimlik çözümleme: ' . sanitize_key( $evidence['tobb_candidate_identity']['error_code'] );
        }

        return 'TOBB tarih çözümleme: tanı yok';
    }

    private static function source_freshness_days( $source_id ) {
        $days = $source_id ? absint( get_post_meta( $source_id, 'freshness_days', true ) ) : 0;
        return max( 1, min( 365, $days ?: self::DEFAULT_FRESHNESS_DAYS ) );
    }

    private static function source_categories( $source_id, $candidate ) {
        $raw = $source_id ? (string) get_post_meta( $source_id, 'category_slugs', true ) : '';
        $slugs = array();

        if ( '' === trim( $raw ) && ! empty( $candidate['normalized_payload'] ) ) {
            $payload = json_decode( (string) $candidate['normalized_payload'], true );
            if ( is_array( $payload ) && ! empty( $payload['category_slugs'] ) && is_array( $payload['category_slugs'] ) ) {
                $slugs = $payload['category_slugs'];
            }
        } else {
            $slugs = array_map( 'trim', explode( ',', $raw ) );
        }

        return Sektorel_Content_Editorial_Architecture::source_category_slugs( $slugs );
    }

    private static function source_primary_desk( $source_key, $source_id ) {
        if ( ! class_exists( 'Sektorel_Content_Source_Policy' ) ) {
            return '';
        }

        $slug = Sektorel_Content_Source_Policy::primary_desk( sanitize_key( $source_key ), absint( $source_id ) );
        if ( ! $slug || ! Sektorel_Content_Editorial_Architecture::is_primary_slug( $slug ) ) {
            return '';
        }

        return get_term_by( 'slug', $slug, 'category' ) ? $slug : '';
    }

    /** v2 intentionally returns at most one primary editorial category. */
    private static function suggest_categories( $text, $fallback, $source_primary = '' ) {
        $source_primary = sanitize_title( $source_primary );
        if ( $source_primary ) {
            return array( $source_primary );
        }

        $slug = Sektorel_Content_Editorial_Architecture::primary_category_for_text( $text, $fallback );
        if ( ! $slug || ! get_term_by( 'slug', $slug, 'category' ) ) {
            return array();
        }
        return array( $slug );
    }

    private static function age_days( $published_at ) {
        if ( '' === $published_at ) {
            return null;
        }
        $timestamp = strtotime( $published_at . ' UTC' );
        if ( false === $timestamp ) {
            return null;
        }
        return (int) floor( ( time() - $timestamp ) / DAY_IN_SECONDS );
    }

    private static function valid_public_http_url( $url ) {
        $parts = wp_parse_url( $url );
        return is_array( $parts )
            && ! empty( $parts['host'] )
            && ! empty( $parts['scheme'] )
            && in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true );
    }

    private static function short_title( $title ) {
        $title = trim( (string) $title );
        return mb_strlen( $title ) > 70 ? mb_substr( $title, 0, 67 ) . '...' : $title;
    }

    private static function run_key( $user_id ) {
        return 'sektorel_content_triage_run_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }
}
