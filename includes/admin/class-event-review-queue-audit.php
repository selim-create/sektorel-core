<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Phase 3 review-queue audit.
 * Read-only diagnostics for reviewable event candidates.
 */
class Sektorel_Event_Review_Queue_Audit {

    public static function init() {
        add_action( 'admin_footer', array( __CLASS__, 'render_source_center_card' ), 135 );
    }

    public static function report() {
        $ids = self::reviewable_candidate_ids();
        $buckets = array(
            'html_noise'           => array( 'label' => 'HTML · Muhtemel Gürültü', 'count' => 0 ),
            'html_safe_new'        => array( 'label' => 'HTML · Güvenli Yeni Aday', 'count' => 0 ),
            'changed_matched'      => array( 'label' => 'Eşleşmiş · Değişiklik Var', 'count' => 0 ),
            'manual_match'         => array( 'label' => 'Belirsiz Eşleşme · Manuel İnceleme', 'count' => 0 ),
            'enrichment_unmatched' => array( 'label' => 'Zenginleştirme · Eşleşme Gerekli', 'count' => 0 ),
            'enrichment_matched'   => array( 'label' => 'Zenginleştirme · Eşleşmiş Ama Açık', 'count' => 0 ),
            'discovery_new'        => array( 'label' => 'Discovery/Canonical · Yeni', 'count' => 0 ),
            'other'                => array( 'label' => 'Diğer', 'count' => 0 ),
        );

        $status_counts = $parser_counts = $role_counts = $reason_counts = array();
        if ( $ids ) {
            update_meta_cache( 'post', $ids );
        }

        foreach ( $ids as $candidate_id ) {
            $status   = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_status', true ) );
            $parser   = sanitize_key( (string) get_post_meta( $candidate_id, 'parser_type', true ) );
            $reason   = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_match_reason', true ) );
            $triage   = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_triage_level', true ) );
            $event_id = absint( get_post_meta( $candidate_id, 'matched_event_id', true ) );
            $role     = class_exists( 'Sektorel_Event_Source_Role' ) ? Sektorel_Event_Source_Role::role_for_candidate( $candidate_id ) : 'discovery';

            self::increment( $status_counts, $status ?: 'unset' );
            self::increment( $parser_counts, $parser ?: 'unset' );
            self::increment( $role_counts, $role ?: 'unset' );
            self::increment( $reason_counts, $reason ?: 'unset' );

            $enrichment = in_array( $role, array( 'venue_enrichment', 'organizer_enrichment', 'official_enrichment' ), true );
            if ( 'html' === $parser && 'noise' === $triage && in_array( $status, array( 'new', 'incomplete', '' ), true ) ) {
                $bucket = 'html_noise';
            } elseif ( 'html' === $parser && 'safe' === $triage && 'new' === $status ) {
                $bucket = 'html_safe_new';
            } elseif ( 'changed' === $status && $event_id ) {
                $bucket = 'changed_matched';
            } elseif ( $enrichment && ! $event_id ) {
                $bucket = 'enrichment_unmatched';
            } elseif ( $enrichment && $event_id ) {
                $bucket = 'enrichment_matched';
            } elseif ( 'incomplete' === $status || 'manual_review' === $reason ) {
                $bucket = 'manual_match';
            } elseif ( in_array( $role, array( 'discovery', 'canonical_registry' ), true ) && 'new' === $status ) {
                $bucket = 'discovery_new';
            } else {
                $bucket = 'other';
            }
            $buckets[ $bucket ]['count']++;
        }

        return array(
            'total'    => count( $ids ),
            'buckets'  => $buckets,
            'statuses' => $status_counts,
            'parsers'  => $parser_counts,
            'roles'    => $role_counts,
            'reasons'  => $reason_counts,
        );
    }

    public static function render_source_center_card() {
        if ( ! current_user_can( 'manage_options' ) || ! self::is_source_center_page() ) {
            return;
        }

        $report = self::report();
        echo '<div class="wrap"><div class="card ssc-review-audit" style="max-width:1000px;padding:20px;margin-top:18px;">';
        echo '<h2 style="margin-top:0;">Faz 3 · Review Queue Dağılımı</h2>';
        echo '<p class="description">İnceleme kuyruğundaki <strong>' . esc_html( number_format_i18n( $report['total'] ) ) . '</strong> kayıt salt-okunur olarak sınıflandırıldı. Bu kart hiçbir candidate veya Event durumunu değiştirmez.</p>';
        echo '<div style="margin-top:14px;">';
        foreach ( $report['buckets'] as $bucket ) {
            if ( empty( $bucket['count'] ) ) {
                continue;
            }
            echo '<div style="display:flex;justify-content:space-between;gap:20px;padding:7px 0;border-top:1px solid #e2e4e7;">';
            echo '<span>' . esc_html( $bucket['label'] ) . '</span><strong>' . esc_html( number_format_i18n( $bucket['count'] ) ) . '</strong>';
            echo '</div>';
        }
        echo '</div>';
        echo '<p class="description" style="margin-bottom:0;margin-top:14px;">Bir sonraki otomasyon, en yüksek hacimli ve deterministik olarak güvenli bucket üzerinden seçilecek.</p>';
        echo '</div></div>';
    }

    private static function reviewable_candidate_ids() {
        $ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ) );
        $archive = class_exists( 'Sektorel_Event_Candidate_Inbox' ) ? Sektorel_Event_Candidate_Inbox::archive_statuses() : array( 'imported', 'existing', 'ignored', 'rejected' );
        $reviewable = array();
        foreach ( $ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            $status = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_status', true ) );
            if ( ! in_array( $status, $archive, true ) ) {
                $reviewable[] = $candidate_id;
            }
        }
        return $reviewable;
    }

    private static function increment( &$counts, $key ) {
        if ( ! isset( $counts[ $key ] ) ) {
            $counts[ $key ] = 0;
        }
        $counts[ $key ]++;
    }

    private static function is_source_center_page() {
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
        $page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        return 'event' === $post_type && 'sektorel-source-center' === $page;
    }
}
