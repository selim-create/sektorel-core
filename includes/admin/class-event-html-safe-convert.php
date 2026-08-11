<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Guarded conversion path for advisory-safe HTML candidates.
 *
 * This class does not publish events. It exposes a dedicated bulk action that
 * re-validates every selected candidate server-side and delegates the actual
 * draft conversion to the existing Sektorel_Event_Candidate_Quality converter.
 */
class Sektorel_Event_HTML_Safe_Convert {

    const ACTION = 'sektorel_convert_safe_html_candidates';

    public static function init() {
        add_action( 'restrict_manage_posts', array( __CLASS__, 'triage_filter' ), 80 );
        add_action( 'pre_get_posts', array( __CLASS__, 'apply_triage_filter' ), 80 );

        add_filter( 'bulk_actions-edit-event_candidate', array( __CLASS__, 'bulk_actions' ), 80 );
        add_filter( 'handle_bulk_actions-edit-event_candidate', array( __CLASS__, 'handle_bulk_action' ), 80, 3 );
        add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ), 80 );
    }

    public static function triage_filter() {
        global $typenow;

        if ( 'event_candidate' !== $typenow ) {
            return;
        }

        $selected = isset( $_GET['candidate_triage'] )
            ? sanitize_key( wp_unslash( $_GET['candidate_triage'] ) )
            : '';
        ?>
        <select name="candidate_triage">
            <option value="">İnceleme Önerisi: Tümü</option>
            <option value="safe" <?php selected( $selected, 'safe' ); ?>>Güvenli Aday</option>
            <option value="review" <?php selected( $selected, 'review' ); ?>>Manuel İnceleme</option>
            <option value="noise" <?php selected( $selected, 'noise' ); ?>>Muhtemel Gürültü</option>
        </select>
        <?php
    }

    public static function apply_triage_filter( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $post_type = $query->get( 'post_type' );
        if ( 'event_candidate' !== $post_type ) {
            return;
        }

        $triage = isset( $_GET['candidate_triage'] )
            ? sanitize_key( wp_unslash( $_GET['candidate_triage'] ) )
            : '';

        if ( ! in_array( $triage, array( 'safe', 'review', 'noise' ), true ) ) {
            return;
        }

        $meta_query = (array) $query->get( 'meta_query' );
        $meta_query[] = array(
            'key'   => 'candidate_triage_level',
            'value' => $triage,
        );
        $query->set( 'meta_query', $meta_query );
    }

    public static function bulk_actions( $actions ) {
        $actions[ self::ACTION ] = 'Seçilen Güvenli Adayları Taslağa Dönüştür';
        return $actions;
    }

    public static function handle_bulk_action( $redirect_url, $action, $post_ids ) {
        if ( self::ACTION !== $action ) {
            return $redirect_url;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return add_query_arg( 'sektorel_safe_convert_error', 'permission', $redirect_url );
        }

        $converted = 0;
        $existing  = 0;
        $blocked   = 0;
        $failed    = 0;
        $reasons   = array();

        foreach ( array_map( 'absint', (array) $post_ids ) as $candidate_id ) {
            $eligibility = self::eligibility( $candidate_id );
            if ( is_wp_error( $eligibility ) ) {
                $blocked++;
                $code = sanitize_key( $eligibility->get_error_code() );
                if ( ! isset( $reasons[ $code ] ) ) {
                    $reasons[ $code ] = 0;
                }
                $reasons[ $code ]++;
                continue;
            }

            $result = self::convert_with_existing_engine( $candidate_id );
            if ( is_wp_error( $result ) ) {
                $failed++;
            } elseif ( 'existing' === $result ) {
                $existing++;
            } else {
                $converted++;
            }
        }

        $args = array(
            'sektorel_safe_converted' => $converted,
            'sektorel_safe_existing'  => $existing,
            'sektorel_safe_blocked'   => $blocked,
            'sektorel_safe_failed'    => $failed,
        );

        if ( $reasons ) {
            $args['sektorel_safe_reasons'] = rawurlencode( wp_json_encode( $reasons ) );
        }

        return add_query_arg( $args, remove_query_arg( array( 'sektorel_safe_reasons' ), $redirect_url ) );
    }

    public static function eligibility( $candidate_id ) {
        $candidate_id = absint( $candidate_id );
        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) ) {
            return new WP_Error( 'invalid_candidate', 'Geçersiz aday.' );
        }

        if ( 'html' !== (string) get_post_meta( $candidate_id, 'parser_type', true ) ) {
            return new WP_Error( 'not_html', 'Aday HTML parser kaydı değil.' );
        }

        if ( 'safe' !== (string) get_post_meta( $candidate_id, 'candidate_triage_level', true ) ) {
            return new WP_Error( 'not_safe_triage', 'Aday Güvenli Aday sınıfında değil.' );
        }

        $status = (string) get_post_meta( $candidate_id, 'candidate_status', true );
        if ( 'new' !== $status ) {
            return new WP_Error( 'not_new', 'Yalnız new durumundaki adaylar dönüştürülebilir.' );
        }

        $matched_event_id = absint( get_post_meta( $candidate_id, 'matched_event_id', true ) );
        if ( $matched_event_id ) {
            return new WP_Error( 'matched_event', 'Aday mevcut bir etkinlikle eşleşiyor.' );
        }

        $match_reason = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_match_reason', true ) );
        if ( 'no_match' !== $match_reason ) {
            return new WP_Error( 'match_not_clear', 'Matcher adayı açık şekilde yeni olarak doğrulamamış.' );
        }

        $imported_event_id = absint( get_post_meta( $candidate_id, 'imported_event_id', true ) );
        if ( $imported_event_id ) {
            return new WP_Error( 'already_imported', 'Aday daha önce dönüştürülmüş.' );
        }

        $start = self::date_part( get_post_meta( $candidate_id, 'start_date', true ) );
        if ( ! $start ) {
            return new WP_Error( 'missing_start', 'Başlangıç tarihi eksik.' );
        }

        $end = self::date_part( get_post_meta( $candidate_id, 'end_date', true ) );
        if ( $end && $end < $start ) {
            return new WP_Error( 'invalid_date_range', 'Bitiş tarihi başlangıç tarihinden önce.' );
        }

        return true;
    }

    private static function convert_with_existing_engine( $candidate_id ) {
        if ( ! class_exists( 'Sektorel_Event_Candidate_Quality' ) ) {
            return new WP_Error( 'converter_missing', 'Mevcut aday dönüşüm motoru bulunamadı.' );
        }

        try {
            $method = new ReflectionMethod( 'Sektorel_Event_Candidate_Quality', 'convert_candidate' );
            if ( method_exists( $method, 'setAccessible' ) ) {
                $method->setAccessible( true );
            }
            return $method->invoke( null, absint( $candidate_id ) );
        } catch ( Throwable $e ) {
            return new WP_Error( 'converter_exception', $e->getMessage() );
        }
    }

    public static function bulk_notice() {
        if ( ! isset( $_GET['sektorel_safe_converted'] ) ) {
            return;
        }

        $converted = absint( $_GET['sektorel_safe_converted'] );
        $existing  = isset( $_GET['sektorel_safe_existing'] ) ? absint( $_GET['sektorel_safe_existing'] ) : 0;
        $blocked   = isset( $_GET['sektorel_safe_blocked'] ) ? absint( $_GET['sektorel_safe_blocked'] ) : 0;
        $failed    = isset( $_GET['sektorel_safe_failed'] ) ? absint( $_GET['sektorel_safe_failed'] ) : 0;

        echo '<div class="notice notice-success is-dismissible"><p><strong>Güvenli aday dönüşümü:</strong> ';
        echo 'yeni taslak: <strong>' . esc_html( (string) $converted ) . '</strong>, ';
        echo 'zaten dönüştürülmüş: <strong>' . esc_html( (string) $existing ) . '</strong>, ';
        echo 'güvenlik nedeniyle engellendi: <strong>' . esc_html( (string) $blocked ) . '</strong>, ';
        echo 'hata: <strong>' . esc_html( (string) $failed ) . '</strong>.';
        echo '</p>';

        $reasons = self::redirect_reasons();
        if ( $reasons ) {
            $labels = array(
                'invalid_candidate'  => 'geçersiz aday',
                'not_html'           => 'HTML değil',
                'not_safe_triage'    => 'Güvenli Aday değil',
                'not_new'            => 'new değil',
                'matched_event'      => 'mevcut event eşleşmesi var',
                'match_not_clear'    => 'matcher no_match değil',
                'already_imported'   => 'zaten dönüştürülmüş',
                'missing_start'      => 'başlangıç tarihi eksik',
                'invalid_date_range' => 'geçersiz tarih aralığı',
            );
            $parts = array();
            foreach ( $reasons as $code => $count ) {
                $label = isset( $labels[ $code ] ) ? $labels[ $code ] : $code;
                $parts[] = $label . ': ' . absint( $count );
            }
            echo '<p><small>Engelleme nedenleri: ' . esc_html( implode( ', ', $parts ) ) . '</small></p>';
        }
        echo '</div>';
    }

    private static function redirect_reasons() {
        if ( empty( $_GET['sektorel_safe_reasons'] ) ) {
            return array();
        }
        $raw = rawurldecode( sanitize_text_field( wp_unslash( $_GET['sektorel_safe_reasons'] ) ) );
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : array();
    }

    private static function date_part( $value ) {
        return preg_match( '/^(\d{4}-\d{2}-\d{2})/', (string) $value, $m ) ? $m[1] : '';
    }
}
