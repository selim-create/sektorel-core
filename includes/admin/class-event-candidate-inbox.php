<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Operational inbox for event candidates.
 *
 * Candidate posts remain in storage for provenance, dedupe and evidence, but
 * the daily admin surface should show only candidates that still need human
 * attention. Resolved/imported candidates stay accessible through archive/all
 * views without polluting the default inbox count.
 */
class Sektorel_Event_Candidate_Inbox {

    const NONCE_ACTION = 'sektorel_candidate_inbox_counts';

    public static function init() {
        add_action( 'pre_get_posts', array( __CLASS__, 'apply_default_inbox' ), 90 );
        add_filter( 'views_edit-event_candidate', array( __CLASS__, 'candidate_views' ), 50 );
        add_action( 'wp_ajax_sektorel_candidate_inbox_counts', array( __CLASS__, 'ajax_counts' ) );
        add_action( 'admin_footer', array( __CLASS__, 'render_source_center_patch' ), 130 );
    }

    public static function archive_statuses() {
        return array( 'imported', 'existing', 'ignored', 'rejected' );
    }

    public static function apply_default_inbox( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        if ( 'event_candidate' !== $query->get( 'post_type' ) ) {
            return;
        }

        // WordPress Trash and an explicit matcher-status filter must always win.
        if ( 'trash' === $query->get( 'post_status' ) || ! empty( $_GET['candidate_match_status'] ) ) {
            return;
        }

        $view = isset( $_GET['candidate_view'] ) ? sanitize_key( wp_unslash( $_GET['candidate_view'] ) ) : 'inbox';
        if ( 'all' === $view ) {
            return;
        }

        $meta_query = (array) $query->get( 'meta_query' );
        if ( 'archive' === $view ) {
            $meta_query[] = array(
                'key'     => 'candidate_status',
                'value'   => self::archive_statuses(),
                'compare' => 'IN',
            );
        } else {
            $meta_query[] = self::reviewable_meta_query();
        }

        $query->set( 'meta_query', $meta_query );
    }

    public static function candidate_views( $views ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return $views;
        }

        $counts  = self::counts();
        $current = isset( $_GET['candidate_view'] ) ? sanitize_key( wp_unslash( $_GET['candidate_view'] ) ) : 'inbox';
        if ( ! empty( $_GET['candidate_match_status'] ) ) {
            $current = '';
        }

        $base = admin_url( 'edit.php?post_type=event_candidate' );

        // Replace WordPress' misleading "All" candidate-post count with the
        // operational inbox. Keep the full history one click away.
        $views['all'] = sprintf(
            '<a href="%1$s"%2$s>İnceleme Bekleyen <span class="count">(%3$s)</span></a>',
            esc_url( add_query_arg( 'candidate_view', 'inbox', $base ) ),
            'inbox' === $current ? ' class="current" aria-current="page"' : '',
            esc_html( number_format_i18n( $counts['review'] ) )
        );

        $views['candidate_archive'] = sprintf(
            '<a href="%1$s"%2$s>İşlenmiş / Arşiv <span class="count">(%3$s)</span></a>',
            esc_url( add_query_arg( 'candidate_view', 'archive', $base ) ),
            'archive' === $current ? ' class="current" aria-current="page"' : '',
            esc_html( number_format_i18n( $counts['archive'] ) )
        );

        $views['candidate_history'] = sprintf(
            '<a href="%1$s"%2$s>Tüm Candidate Geçmişi <span class="count">(%3$s)</span></a>',
            esc_url( add_query_arg( 'candidate_view', 'all', $base ) ),
            'all' === $current ? ' class="current" aria-current="page"' : '',
            esc_html( number_format_i18n( $counts['total'] ) )
        );

        return $views;
    }

    public static function ajax_counts() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }

        wp_send_json_success( self::counts() );
    }

    public static function render_source_center_patch() {
        if ( ! current_user_can( 'manage_options' ) || ! self::is_source_center_page() ) {
            return;
        }

        $nonce = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <script>
        jQuery(function($){
            var candidateInboxNonce = '<?php echo esc_js( $nonce ); ?>';

            function applyCounts(data){
                if(!data){ return; }
                $('.ssc-stat').each(function(){
                    var $stat=$(this), $label=$stat.find('.ssc-stat-label');
                    if($.trim($label.text())!=='Aday Etkinlik' && $.trim($label.text())!=='İnceleme Bekleyen'){
                        return;
                    }
                    $label.text('İnceleme Bekleyen');
                    $stat.find('.ssc-stat-value').text(Number(data.review||0));
                    $stat.find('.ssc-candidate-history').remove();
                    $stat.append('<div class="description ssc-candidate-history" style="margin-top:4px;">Toplam candidate geçmişi: '+Number(data.total||0)+'</div>');
                });
            }

            function refreshCounts(){
                $.post(ajaxurl,{
                    action:'sektorel_candidate_inbox_counts',
                    nonce:candidateInboxNonce
                }).done(function(response){
                    if(response && response.success){ applyCounts(response.data||{}); }
                });
            }

            refreshCounts();

            var summary=document.getElementById('ssc-summary');
            if(summary && window.MutationObserver){
                var observer=new MutationObserver(function(){
                    if((summary.textContent||'').indexOf('Kaynak taraması tamamlandı.')!==-1){
                        refreshCounts();
                    }
                });
                observer.observe(summary,{childList:true,subtree:true,characterData:true});
            }
        });
        </script>
        <?php
    }

    public static function counts() {
        $total  = self::count_query( array() );
        $review = self::count_query( self::reviewable_meta_query() );

        return array(
            'review'  => $review,
            'archive' => max( 0, $total - $review ),
            'total'   => $total,
        );
    }

    private static function count_query( $meta_query ) {
        $args = array(
            'post_type'      => 'event_candidate',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
        );

        if ( $meta_query ) {
            $args['meta_query'] = isset( $meta_query['relation'] ) ? array( $meta_query ) : $meta_query;
        }

        $query = new WP_Query( $args );
        return absint( $query->found_posts );
    }

    private static function reviewable_meta_query() {
        return array(
            'relation' => 'OR',
            array(
                'key'     => 'candidate_status',
                'compare' => 'NOT EXISTS',
            ),
            array(
                'key'     => 'candidate_status',
                'value'   => self::archive_statuses(),
                'compare' => 'NOT IN',
            ),
        );
    }

    private static function is_source_center_page() {
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
        $page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        return 'event' === $post_type && 'sektorel-source-center' === $page;
    }
}
