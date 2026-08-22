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
            'html_noise'           => array( 'label' => 'HTML · Muhtemel Gürültü', 'count' => 0, 'items' => array() ),
            'html_safe_new'        => array( 'label' => 'HTML · Güvenli Yeni Aday', 'count' => 0, 'items' => array() ),
            'changed_matched'      => array( 'label' => 'Eşleşmiş · Değişiklik Var', 'count' => 0, 'items' => array() ),
            'manual_match'         => array( 'label' => 'Belirsiz Eşleşme · Manuel İnceleme', 'count' => 0, 'items' => array() ),
            'enrichment_unmatched' => array( 'label' => 'Zenginleştirme · Eşleşme Gerekli', 'count' => 0, 'items' => array() ),
            'enrichment_matched'   => array( 'label' => 'Zenginleştirme · Eşleşmiş Ama Açık', 'count' => 0, 'items' => array() ),
            'discovery_new'        => array( 'label' => 'Discovery/Canonical · Yeni', 'count' => 0, 'items' => array() ),
            'other'                => array( 'label' => 'Diğer', 'count' => 0, 'items' => array() ),
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
            $buckets[ $bucket ]['items'][] = self::candidate_item( $candidate_id, $status, $parser, $reason, $role, $event_id );
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
        echo '<p class="description ssc-review-audit-description">İnceleme kuyruğundaki <strong class="ssc-review-audit-total">' . esc_html( number_format_i18n( $report['total'] ) ) . '</strong> kayıt mevcut durumlarına göre sınıflandırıldı. Bu rapor hiçbir candidate veya Event durumunu değiştirmez. Her grubun altında inceleme için gerekli candidate, kaynak, tarih ve bağlantı bilgileri gösterilir.</p>';
        echo '<div class="ssc-review-audit-rows" style="margin-top:14px;">' . self::bucket_rows_html( $report['buckets'] ) . '</div>';
        echo '</div>';
        ?>
        <script>
        jQuery(function($){
            var $card=$('#ssc-review-audit-card');
            var $advanced=$('.sektorel-source-center .ssc-advanced').first();
            var $main=$('.sektorel-source-center .ssc-main').first();
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

            var summary=document.getElementById('ssc-summary');
            if(summary && window.MutationObserver){
                var observer=new MutationObserver(function(){
                    if((summary.textContent||'').indexOf('Kaynak taraması tamamlandı.')!==-1){
                        refreshAudit();
                    }
                });
                observer.observe(summary,{childList:true,subtree:true,characterData:true});
            }
        });
        </script>
        <?php
    }

    private static function candidate_item( $candidate_id, $status, $parser, $reason, $role, $event_id ) {
        $source_id  = absint( get_post_meta( $candidate_id, 'source_id', true ) );
        $start_date = trim( (string) get_post_meta( $candidate_id, 'start_date', true ) );
        $end_date   = trim( (string) get_post_meta( $candidate_id, 'end_date', true ) );
        $source_url = esc_url_raw( (string) get_post_meta( $candidate_id, 'source_url', true ) );
        $event_url  = esc_url_raw( (string) get_post_meta( $candidate_id, 'event_url', true ) );

        return array(
            'id'            => absint( $candidate_id ),
            'title'         => get_the_title( $candidate_id ),
            'status'        => $status ?: 'unset',
            'parser'        => $parser ?: 'unset',
            'reason'        => $reason ?: 'unset',
            'role'          => $role ?: 'discovery',
            'event_id'      => absint( $event_id ),
            'source_id'     => $source_id,
            'source_title'  => $source_id ? get_the_title( $source_id ) : '',
            'start_date'    => $start_date,
            'end_date'      => $end_date,
            'source_url'    => $source_url,
            'event_url'     => $event_url,
        );
    }

    private static function bucket_rows_html( $buckets ) {
        $html = '';
        foreach ( (array) $buckets as $bucket ) {
            if ( empty( $bucket['count'] ) ) {
                continue;
            }

            $html .= '<section style="margin-top:14px;border-top:1px solid #dcdcde;padding-top:10px;">';
            $html .= '<div style="display:flex;justify-content:space-between;gap:20px;align-items:center;">';
            $html .= '<strong>' . esc_html( $bucket['label'] ) . '</strong><strong>' . esc_html( number_format_i18n( $bucket['count'] ) ) . '</strong>';
            $html .= '</div>';

            if ( ! empty( $bucket['items'] ) ) {
                $html .= '<div style="margin-top:8px;display:grid;gap:8px;">';
                foreach ( $bucket['items'] as $item ) {
                    $html .= self::candidate_item_html( $item );
                }
                $html .= '</div>';
            }

            $html .= '</section>';
        }
        return $html;
    }

    private static function candidate_item_html( $item ) {
        $candidate_id = absint( $item['id'] );
        $title = $item['title'] ? $item['title'] : '(Başlıksız candidate)';
        $candidate_edit = admin_url( 'post.php?post=' . $candidate_id . '&action=edit' );
        $date = self::date_label( $item['start_date'], $item['end_date'] );

        $meta = array(
            'Rol: ' . $item['role'],
            'Parser: ' . $item['parser'],
            'Durum: ' . $item['status'],
        );
        if ( 'unset' !== $item['reason'] ) {
            $meta[] = 'Eşleşme: ' . $item['reason'];
        }
        if ( $date ) {
            $meta[] = 'Tarih: ' . $date;
        }

        $html  = '<article style="border:1px solid #dcdcde;border-radius:5px;padding:10px 12px;background:#fff;">';
        $html .= '<div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;">';
        $html .= '<div><a href="' . esc_url( $candidate_edit ) . '"><strong>' . esc_html( $title ) . '</strong></a>';
        $html .= '<div class="description" style="margin-top:3px;">Candidate #' . esc_html( $candidate_id ) . ' · ' . esc_html( implode( ' · ', $meta ) ) . '</div>';
        $html .= '</div>';

        if ( ! empty( $item['event_id'] ) ) {
            $event_edit = admin_url( 'post.php?post=' . absint( $item['event_id'] ) . '&action=edit' );
            $html .= '<a class="button button-small" href="' . esc_url( $event_edit ) . '">Event #' . esc_html( absint( $item['event_id'] ) ) . '</a>';
        }
        $html .= '</div>';

        if ( ! empty( $item['source_id'] ) || ! empty( $item['source_title'] ) ) {
            $html .= '<div style="margin-top:6px;font-size:12px;">Kaynak: ';
            if ( ! empty( $item['source_id'] ) ) {
                $source_edit = admin_url( 'post.php?post=' . absint( $item['source_id'] ) . '&action=edit' );
                $source_label = $item['source_title'] ? $item['source_title'] : 'Kaynak #' . absint( $item['source_id'] );
                $html .= '<a href="' . esc_url( $source_edit ) . '">' . esc_html( $source_label ) . '</a>';
            } else {
                $html .= esc_html( $item['source_title'] );
            }
            $html .= '</div>';
        }

        $links = array();
        if ( ! empty( $item['event_url'] ) ) {
            $links[] = '<a href="' . esc_url( $item['event_url'] ) . '" target="_blank" rel="noopener noreferrer">Etkinlik URL</a>';
        }
        if ( ! empty( $item['source_url'] ) && $item['source_url'] !== $item['event_url'] ) {
            $links[] = '<a href="' . esc_url( $item['source_url'] ) . '" target="_blank" rel="noopener noreferrer">Kaynak URL</a>';
        }
        if ( $links ) {
            $html .= '<div style="margin-top:6px;font-size:12px;">' . implode( ' · ', $links ) . '</div>';
        }

        $html .= '</article>';
        return $html;
    }

    private static function date_label( $start_date, $end_date ) {
        $start = trim( (string) $start_date );
        $end   = trim( (string) $end_date );
        if ( ! $start && ! $end ) {
            return '';
        }
        if ( $start && $end && $start !== $end ) {
            return $start . ' → ' . $end;
        }
        return $start ?: $end;
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
        $archive = class_exists( 'Sektorel_Event_Candidate_Inbox' ) ? Sektorel_Event_Candidate_Inbox::archive_statuses() : array( 'imported', 'existing', 'ignored', 'rejected', 'expired' );
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
