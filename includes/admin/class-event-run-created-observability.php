<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Run-level observability for draft Events created by the Source Center.
 *
 * This class does not participate in ingestion or matching. It listens only to
 * creation markers that the existing Event-producing stages already write and
 * records them against the currently active background run. That keeps the
 * report exact without changing provider/dedupe/upsert contracts.
 */
class Sektorel_Event_Run_Created_Observability {

    const NONCE_ACTION   = 'sektorel_source_background_run';
    const ACTIVE_OPTION  = 'sektorel_source_active_run';
    const RUN_PREFIX     = 'sektorel_source_run_';
    const HISTORY_OPTION = 'sektorel_source_run_history';
    const ITEM_PREFIX    = 'sektorel_source_created_events_';
    const MAX_ITEMS      = 50;

    public static function init() {
        add_action( 'added_post_meta', array( __CLASS__, 'capture_created_event' ), 40, 4 );
        add_action( 'wp_ajax_sektorel_source_created_events_observability', array( __CLASS__, 'ajax_snapshot' ) );

        if ( is_admin() ) {
            add_action( 'admin_footer', array( __CLASS__, 'render_source_center_script' ), 135 );
        }
    }

    public static function capture_created_event( $meta_id, $object_id, $meta_key, $meta_value ) {
        $event_id = absint( $object_id );
        if ( ! $event_id || 'event' !== get_post_type( $event_id ) ) {
            return;
        }

        $run_id = sanitize_key( (string) get_option( self::ACTIVE_OPTION, '' ) );
        if ( ! $run_id ) {
            return;
        }

        $run = get_option( self::RUN_PREFIX . $run_id, array() );
        if ( ! is_array( $run ) || empty( $run['stages'] ) || empty( $run['started_at'] ) ) {
            return;
        }

        $run_started   = strtotime( (string) $run['started_at'] );
        $event_created = get_post_time( 'U', true, $event_id );
        if ( $run_started && $event_created && $event_created < ( $run_started - 5 ) ) {
            return;
        }

        $stage_index = isset( $run['current_stage'] ) ? absint( $run['current_stage'] ) : 0;
        if ( ! isset( $run['stages'][ $stage_index ] ) || ! is_array( $run['stages'][ $stage_index ] ) ) {
            return;
        }

        $stage       = $run['stages'][ $stage_index ];
        $stage_key   = isset( $stage['key'] ) ? sanitize_key( (string) $stage['key'] ) : '';
        $stage_label = isset( $stage['label'] ) ? sanitize_text_field( (string) $stage['label'] ) : $stage_key;

        $source = '';
        if ( 'public_opportunities' === $stage_key && 'opportunity_provider_name' === $meta_key ) {
            $source = sanitize_text_field( (string) $meta_value );
        } elseif ( in_array( $stage_key, array( 'official_calendar', 'official_calendar_phase2' ), true ) && 'official_calendar_managed' === $meta_key ) {
            $source = sanitize_text_field( (string) get_post_meta( $event_id, 'official_institution', true ) );
        } elseif ( in_array( $stage_key, array( 'canonical_drafts', 'safe_discovery_drafts' ), true ) && 'source_candidate_id' === $meta_key ) {
            $candidate_id = absint( $meta_value );
            $source_id    = $candidate_id ? absint( get_post_meta( $candidate_id, 'source_id', true ) ) : 0;
            if ( $source_id && 'event_source' === get_post_type( $source_id ) ) {
                $source = sanitize_text_field( (string) get_the_title( $source_id ) );
            }
            if ( ! $source ) {
                $parser = $candidate_id ? sanitize_key( (string) get_post_meta( $candidate_id, 'parser_type', true ) ) : '';
                $source = $parser ? strtoupper( $parser ) . ' kaynak' : 'Aday etkinlik';
            }
        } else {
            return;
        }

        self::store_item(
            $run_id,
            array(
                'event_id'    => $event_id,
                'title'       => sanitize_text_field( (string) get_the_title( $event_id ) ),
                'source'      => $source ?: '—',
                'stage_key'   => $stage_key,
                'stage_label' => $stage_label,
                'created_at'  => current_time( 'mysql' ),
            )
        );
    }

    public static function ajax_snapshot() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }

        $run_id = isset( $_POST['run_id'] ) ? sanitize_key( wp_unslash( $_POST['run_id'] ) ) : '';
        if ( ! $run_id ) {
            $run_id = self::latest_run_id();
        }

        $items = $run_id ? get_option( self::ITEM_PREFIX . $run_id, array() ) : array();
        wp_send_json_success(
            array(
                'run_id' => $run_id,
                'items'  => self::public_items( $items ),
            )
        );
    }

    public static function render_source_center_script() {
        if ( ! self::is_source_center_page() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $nonce = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <style>
            #ssc-created-events-summary{max-width:1000px;margin-top:10px;padding:12px 14px;background:#fff;border:1px solid #dcdcde;font-size:12px;line-height:1.55}
            #ssc-created-events-summary .ssc-created-event{display:grid;grid-template-columns:minmax(220px,1fr) minmax(120px,.55fr) minmax(150px,.65fr);gap:8px 14px;padding:7px 0;border-top:1px solid #f0f0f1;align-items:start}
            #ssc-created-events-summary .ssc-created-event:first-of-type{margin-top:7px}
            #ssc-created-events-summary .ssc-created-source,#ssc-created-events-summary .ssc-created-stage{color:#646970}
            @media(max-width:782px){#ssc-created-events-summary .ssc-created-event{grid-template-columns:1fr;gap:2px}}
        </style>
        <script>
        jQuery(function($){
            var nonce='<?php echo esc_js( $nonce ); ?>';
            var last='';
            function esc(text){return $('<div>').text(String(text||'')).html();}
            function render(data){
                var items=(data&&data.items)||[];
                var signature=JSON.stringify(items);
                if(signature===last)return;
                last=signature;
                var $box=$('#ssc-created-events-summary');
                if(!items.length){$box.remove();return;}
                if(!$box.length){$box=$('<div id="ssc-created-events-summary"></div>');$('#ssc-summary').after($box);}
                var html='<strong>Bu taramada oluşturulanlar ('+items.length+')</strong>';
                items.forEach(function(item){
                    var href='post.php?post='+Number(item.event_id||0)+'&action=edit';
                    html+='<div class="ssc-created-event">'
                        +'<a href="'+href+'"><strong>'+esc(item.title||('Event #'+item.event_id))+'</strong></a>'
                        +'<span class="ssc-created-source">'+esc(item.source||'—')+'</span>'
                        +'<span class="ssc-created-stage">'+esc(item.stage_label||item.stage_key||'—')+'</span>'
                        +'</div>';
                });
                $box.html(html);
            }
            function fetchItems(){
                $.post(ajaxurl,{action:'sektorel_source_created_events_observability',nonce:nonce}).done(function(r){
                    if(r&&r.success&&r.data)render(r.data);
                });
            }
            fetchItems();
            window.setInterval(fetchItems,5000);
        });
        </script>
        <?php
    }

    private static function store_item( $run_id, $item ) {
        $run_id = sanitize_key( (string) $run_id );
        if ( ! $run_id || empty( $item['event_id'] ) ) {
            return;
        }

        $key   = self::ITEM_PREFIX . $run_id;
        $items = get_option( $key, array() );
        $items = is_array( $items ) ? $items : array();

        $event_key = (string) absint( $item['event_id'] );
        $items[ $event_key ] = $item;
        if ( count( $items ) > self::MAX_ITEMS ) {
            $items = array_slice( $items, -self::MAX_ITEMS, null, true );
        }
        update_option( $key, $items, false );
        self::cleanup_old_snapshots( $run_id );
    }

    private static function public_items( $items ) {
        $clean = array();
        foreach ( (array) $items as $item ) {
            if ( ! is_array( $item ) || empty( $item['event_id'] ) ) {
                continue;
            }
            $event_id = absint( $item['event_id'] );
            if ( ! $event_id || 'event' !== get_post_type( $event_id ) || 'trash' === get_post_status( $event_id ) ) {
                continue;
            }
            $clean[] = array(
                'event_id'    => $event_id,
                'title'       => sanitize_text_field( isset( $item['title'] ) ? $item['title'] : get_the_title( $event_id ) ),
                'source'      => sanitize_text_field( isset( $item['source'] ) ? $item['source'] : '—' ),
                'stage_key'   => sanitize_key( isset( $item['stage_key'] ) ? $item['stage_key'] : '' ),
                'stage_label' => sanitize_text_field( isset( $item['stage_label'] ) ? $item['stage_label'] : '' ),
                'created_at'  => sanitize_text_field( isset( $item['created_at'] ) ? $item['created_at'] : '' ),
            );
        }
        return array_values( $clean );
    }

    private static function latest_run_id() {
        $active = sanitize_key( (string) get_option( self::ACTIVE_OPTION, '' ) );
        if ( $active ) {
            return $active;
        }
        $history = get_option( self::HISTORY_OPTION, array() );
        return is_array( $history ) && ! empty( $history[0] ) ? sanitize_key( (string) $history[0] ) : '';
    }

    private static function cleanup_old_snapshots( $current_run_id ) {
        $history = get_option( self::HISTORY_OPTION, array() );
        $keep    = is_array( $history ) ? array_values( array_filter( array_map( 'sanitize_key', $history ) ) ) : array();
        $keep[]  = sanitize_key( $current_run_id );
        $keep    = array_values( array_unique( $keep ) );

        global $wpdb;
        $like = $wpdb->esc_like( self::ITEM_PREFIX ) . '%';
        $keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
        foreach ( (array) $keys as $option_name ) {
            $run_id = sanitize_key( substr( $option_name, strlen( self::ITEM_PREFIX ) ) );
            if ( $run_id && ! in_array( $run_id, $keep, true ) ) {
                delete_option( $option_name );
            }
        }
    }

    private static function is_source_center_page() {
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
        $page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        return 'event' === $post_type && 'sektorel-source-center' === $page;
    }
}
