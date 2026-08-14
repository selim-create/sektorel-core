<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Read-only diagnostics for reviewable event candidates.
 */
class Sektorel_Event_Review_Queue_Audit {

    const NONCE_ACTION = 'sektorel_review_queue_audit_report';

    public static function init() {
        add_action( 'admin_footer', array( __CLASS__, 'render_source_center_card' ), 135 );
        add_action( 'wp_ajax_sektorel_review_queue_audit_report', array( __CLASS__, 'ajax_report' ) );
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

    public static function ajax_report() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }

        $report = self::report();
        wp_send_json_success( array(
            'total' => absint( $report['total'] ),
            'rows'  => self::bucket_rows_html( $report['buckets'] ),
        ) );
    }

    public static function render_source_center_card() {
        if ( ! current_user_can( 'manage_options' ) || ! self::is_source_center_page() ) {
            return;
        }

        $report = self::report();
        $nonce  = wp_create_nonce( self::NONCE_ACTION );
        echo '<div id="ssc-review-audit-card" class="card ssc-review-audit" style="max-width:1000px;padding:20px;margin-top:18px;">';
        echo '<h2 style="margin-top:0;">İnceleme Kuyruğu Dağılımı</h2>';
        echo '<p class="description ssc-review-audit-description">İnceleme kuyruğundaki <strong class="ssc-review-audit-total">' . esc_html( number_format_i18n( $report['total'] ) ) . '</strong> kayıt mevcut durumlarına göre sınıflandırıldı. Bu rapor hiçbir candidate veya Event durumunu değiştirmez.</p>';
        echo '<div class="ssc-review-audit-rows" style="margin-top:14px;">' . self::bucket_rows_html( $report['buckets'] ) . '</div>';
        echo '</div>';
        ?>
        <script>
        jQuery(function($){
            var $card=$('#ssc-review-audit-card');
            var $advanced=$('.sektorel-source-center .ssc-advanced').first();
            var $main=$('.sektorel-source-center .ssc-main').first();
            var refreshed=false;
            if(!$card.length){ return; }
            if($advanced.length){
                $card.insertBefore($advanced);
            }else if($main.length){
                $card.insertAfter($main);
            }

            function refreshAudit(){
                $.post(ajaxurl,{action:'sektorel_review_queue_audit_report',nonce:'<?php echo esc_js( $nonce ); ?>'}).done(function(r){
                    if(!r||!r.success){ return; }
                    $card.find('.ssc-review-audit-total').text(Number(r.data.total||0).toLocaleString());
                    $card.find('.ssc-review-audit-rows').html(r.data.rows||'');
                });
            }

            $('#ssc-start').on('click',function(){ refreshed=false; });
            window.setInterval(function(){
                if(refreshed){ return; }
                var $summary=$('#ssc-summary');
                if($summary.length && $summary.is(':visible') && $summary.text().indexOf('Kaynak taraması tamamlandı')!==-1){
                    refreshed=true;
                    refreshAudit();
                }
            },500);
        });
        </script>
        <?php
    }

    private static function bucket_rows_html( $buckets ) {
        $html = '';
        foreach ( (array) $buckets as $bucket ) {
            if ( empty( $bucket['count'] ) ) {
                continue;
            }
            $html .= '<div style="display:flex;justify-content:space-between;gap:20px;padding:7px 0;border-top:1px solid #e2e4e7;">';
            $html .= '<span>' . esc_html( $bucket['label'] ) . '</span><strong>' . esc_html( number_format_i18n( $bucket['count'] ) ) . '</strong>';
            $html .= '</div>';
        }
        return $html;
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
