<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adds operational detail to Source Center without changing parser, matcher or
 * conversion behavior. The generic background runner keeps its stable counters;
 * this layer decorates only the two pipeline stages whose domain-specific
 * states need clearer labels.
 */
class Sektorel_Event_Pipeline_Reporting_Detail {

    const NONCE_ACTION = 'sektorel_pipeline_reporting_detail';

    public static function init() {
        add_action( 'wp_ajax_sektorel_pipeline_review_distribution', array( __CLASS__, 'ajax_review_distribution' ) );
        add_action( 'admin_footer', array( __CLASS__, 'render_source_center_script' ), 145 );
    }

    public static function ajax_review_distribution() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }

        wp_send_json_success( self::review_distribution() );
    }

    private static function review_distribution() {
        $counts = array(
            'new'        => 0,
            'incomplete' => 0,
            'changed'    => 0,
            'other'      => 0,
            'total'      => 0,
        );

        if ( ! class_exists( 'Sektorel_Event_Source_Role' ) ) {
            return $counts;
        }

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
            return $counts;
        }

        update_meta_cache( 'post', $ids );
        foreach ( $ids as $candidate_id ) {
            $candidate_id = absint( $candidate_id );
            if ( ! $candidate_id || 'trash' === get_post_status( $candidate_id ) ) {
                continue;
            }

            $status = sanitize_key( (string) get_post_meta( $candidate_id, 'candidate_status', true ) );
            if ( in_array( $status, array( 'imported', 'existing', 'ignored', 'rejected' ), true ) ) {
                continue;
            }

            $role = Sektorel_Event_Source_Role::role_for_candidate( $candidate_id );
            if ( ! in_array( $role, array( 'discovery', 'canonical_registry' ), true ) ) {
                continue;
            }

            $counts['total']++;
            if ( isset( $counts[ $status ] ) && in_array( $status, array( 'new', 'incomplete', 'changed' ), true ) ) {
                $counts[ $status ]++;
            } else {
                $counts['other']++;
            }
        }

        return $counts;
    }

    public static function render_source_center_script() {
        if ( ! current_user_can( 'manage_options' ) || ! self::is_source_center_page() ) {
            return;
        }

        $nonce = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <script>
        jQuery(function($){
            var reportingNonce = '<?php echo esc_js( $nonce ); ?>';
            var refreshTimer = null;

            function patchSafeDraftLabel(){
                var $row = $('.ssc-stage[data-stage="safe_discovery_drafts"]');
                if(!$row.length){ return; }
                var $result = $row.find('.ssc-result');
                var text = $result.text() || '';
                if(text.indexOf('Değişmedi:') !== -1){
                    $result.text(text.replace(/Değişmedi:/g, 'Mevcutla Birleşti:'));
                }
            }

            function refreshMatcherDistribution(){
                var $row = $('.ssc-stage[data-stage="candidate_matcher"]');
                if(!$row.length){ return; }

                $.post(ajaxurl,{
                    action:'sektorel_pipeline_review_distribution',
                    nonce:reportingNonce
                }).done(function(response){
                    if(!response || !response.success || !response.data){ return; }
                    var d=response.data;
                    var original=$row.find('.ssc-result').text() || '';
                    var match=original.match(/Kayıt:\s*\d+/);
                    var parts=[];
                    if(match){ parts.push(match[0]); }
                    parts.push('Review sonrası: '+Number(d.total||0));
                    if(Number(d.new||0)){ parts.push('Yeni Aday: '+Number(d.new||0)); }
                    if(Number(d.incomplete||0)){ parts.push('Eksik: '+Number(d.incomplete||0)); }
                    if(Number(d.changed||0)){ parts.push('Değişti: '+Number(d.changed||0)); }
                    if(Number(d.other||0)){ parts.push('Diğer: '+Number(d.other||0)); }
                    $row.find('.ssc-result').text(parts.join(' · '));
                });
            }

            function refresh(){
                patchSafeDraftLabel();
                refreshMatcherDistribution();
            }

            refresh();
            var root=document.querySelector('.ssc-main');
            if(root && window.MutationObserver){
                var observer=new MutationObserver(function(){
                    window.clearTimeout(refreshTimer);
                    refreshTimer=window.setTimeout(refresh,250);
                });
                observer.observe(root,{childList:true,subtree:true,characterData:true});
            }
        });
        </script>
        <?php
    }

    private static function is_source_center_page() {
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
        $page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        return 'event' === $post_type && 'sektorel-source-center' === $page;
    }
}
