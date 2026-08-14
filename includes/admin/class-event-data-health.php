<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Data_Health {
    const NONCE_ACTION = 'sektorel_event_data_health';
    const QUEUE_TTL = 7200;
    const BATCH_SIZE = 25;
    const VERSION = '1460';
    private static $lock = false;

    public static function init() {
        add_action( 'wp_ajax_sektorel_event_data_health_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_event_data_health_batch', array( __CLASS__, 'ajax_batch' ) );
        add_action( 'save_post_event', array( __CLASS__, 'on_event_save' ), 120, 2 );
        add_action( 'added_post_meta', array( __CLASS__, 'maybe_refresh_from_evidence' ), 160, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'maybe_refresh_from_evidence' ), 160, 4 );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 100 );
        add_filter( 'manage_event_posts_columns', array( __CLASS__, 'columns' ), 90 );
        add_action( 'manage_event_posts_custom_column', array( __CLASS__, 'render_column' ), 90, 2 );
    }

    public static function ajax_prepare() {
        self::require_ajax();
        $ids = self::operational_event_ids();
        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient( self::queue_key( get_current_user_id(), $token ), array_values( $ids ), self::QUEUE_TTL );
        wp_send_json_success( array( 'token' => $token, 'total' => count( $ids ) ) );
    }

    public static function ajax_batch() {
        self::require_ajax();
        $token = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
        $key = self::queue_key( get_current_user_id(), $token );
        $ids = get_transient( $key );
        if ( ! $token || ! is_array( $ids ) ) {
            wp_send_json_error( array( 'message' => 'Veri sağlığı kuyruğu bulunamadı veya süresi doldu.' ) );
        }
        $batch = array_slice( array_values( array_map( 'absint', $ids ) ), $offset, self::BATCH_SIZE );
        $updated = $unchanged = $error = 0;
        foreach ( $batch as $event_id ) {
            $result = self::assess_event( $event_id );
            if ( is_wp_error( $result ) ) { $error++; }
            elseif ( ! empty( $result['changed'] ) ) { $updated++; }
            else { $unchanged++; }
        }
        $next = min( count( $ids ), $offset + count( $batch ) );
        $done = $next >= count( $ids );
        if ( $done ) { delete_transient( $key ); }
        wp_send_json_success( array(
            'next_offset' => $next, 'done' => $done, 'created' => 0,
            'updated' => $updated, 'unchanged' => $unchanged, 'skipped' => 0,
            'error' => $error, 'messages' => array(),
        ) );
    }

    public static function on_event_save( $post_id, $post ) {
        if ( self::$lock || ! $post || 'event' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        self::assess_event( absint( $post_id ) );
    }

    public static function maybe_refresh_from_evidence( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( self::$lock || 'event_source_evidence' !== $meta_key || 'event' !== get_post_type( $object_id ) ) return;
        self::assess_event( absint( $object_id ) );
    }

    public static function assess_event( $event_id ) {
        $event_id = absint( $event_id );
        if ( ! $event_id || 'event' !== get_post_type( $event_id ) || 'trash' === get_post_status( $event_id ) ) return new WP_Error( 'invalid_event', 'Geçersiz etkinlik.' );
        $health = self::calculate_health( $event_id );
        $old = array(
            'score' => absint( get_post_meta( $event_id, 'event_completeness_score', true ) ),
            'status' => (string) get_post_meta( $event_id, 'event_completeness_status', true ),
            'missing' => get_post_meta( $event_id, 'event_missing_fields', true ),
            'conflicts' => get_post_meta( $event_id, 'event_conflicts', true ),
        );
        $changed = $old['score'] !== $health['score'] || $old['status'] !== $health['status']
            || wp_json_encode( (array) $old['missing'] ) !== wp_json_encode( $health['missing_fields'] )
            || wp_json_encode( (array) $old['conflicts'] ) !== wp_json_encode( $health['conflicts'] );
        self::$lock = true;
        update_post_meta( $event_id, 'event_completeness_score', $health['score'] );
        update_post_meta( $event_id, 'event_completeness_status', $health['status'] );
        update_post_meta( $event_id, 'event_missing_fields', $health['missing_fields'] );
        update_post_meta( $event_id, 'event_conflicts', $health['conflicts'] );
        update_post_meta( $event_id, 'event_conflict_fields', array_keys( $health['conflicts'] ) );
        update_post_meta( $event_id, 'event_conflict_count', count( $health['conflicts'] ) );
        update_post_meta( $event_id, 'event_health_evidence_count', $health['evidence_count'] );
        update_post_meta( $event_id, 'event_health_version', self::VERSION );
        update_post_meta( $event_id, 'event_health_checked_at', current_time( 'mysql' ) );
        self::$lock = false;
        return array( 'changed' => $changed, 'health' => $health );
    }

    private static function calculate_health( $event_id ) {
        $title = self::clean_text( get_the_title( $event_id ) );
        $description = self::clean_text( get_post_field( 'post_content', $event_id ) );
        $start = self::date_part( get_post_meta( $event_id, 'start_date', true ) );
        $end = self::date_part( get_post_meta( $event_id, 'end_date', true ) );
        $location = sanitize_key( (string) get_post_meta( $event_id, 'location_type', true ) );
        $venue = self::clean_text( get_post_meta( $event_id, 'venue', true ) );
        $address = self::clean_text( get_post_meta( $event_id, 'address', true ) );
        $organizer = self::clean_text( get_post_meta( $event_id, 'organizer', true ) );
        $event_url = trim( (string) get_post_meta( $event_id, 'event_url', true ) );
        $source_url = trim( (string) get_post_meta( $event_id, 'source_url', true ) );
        $registration = trim( (string) get_post_meta( $event_id, 'registration_link', true ) );
        $checks = array(
            'title' => array(15, '' !== $title), 'start_date' => array(20, '' !== $start),
            'end_date' => array(10, '' !== $end), 'location_type' => array(5, '' !== $location),
            'organizer' => array(10, '' !== $organizer), 'official_link' => array(10, '' !== $event_url || '' !== $source_url),
            'registration_link' => array(5, '' !== $registration), 'description' => array(10, self::text_length( $description ) >= 40),
        );
        if ( 'online' === $location ) {
            $checks['online_location'] = array(15, '' !== $event_url || '' !== $registration);
        } elseif ( 'hybrid' === $location ) {
            $checks['venue'] = array(10, '' !== $venue); $checks['hybrid_link'] = array(5, '' !== $event_url || '' !== $registration);
        } else {
            $checks['venue'] = array(10, '' !== $venue); $checks['address'] = array(5, '' !== $address);
        }
        $earned = $possible = 0; $missing = array();
        foreach ( $checks as $field => $check ) {
            $weight = absint( $check[0] ); $possible += $weight;
            if ( ! empty( $check[1] ) ) $earned += $weight; else $missing[] = $field;
        }
        $score = $possible ? (int) round(100 * $earned / $possible) : 0;
        $status = $score >= 85 ? 'complete' : ($score >= 60 ? 'partial' : 'weak');
        $evidence = get_post_meta( $event_id, 'event_source_evidence', true );
        $evidence = is_array( $evidence ) ? $evidence : array();
        return array('score'=>$score,'status'=>$status,'missing_fields'=>array_values($missing),'conflicts'=>self::detect_conflicts($evidence),'evidence_count'=>count($evidence));
    }

    private static function detect_conflicts( $evidence ) {
        if ( ! is_array($evidence) || count($evidence) < 2 ) return array();
        $fields = array('start_date','end_date','location_type','venue','address','organizer'); $conflicts = array();
        foreach ( $fields as $field ) {
            $entries = array();
            foreach ( $evidence as $entry ) {
                $values = ! empty($entry['values']) && is_array($entry['values']) ? $entry['values'] : array();
                $raw = isset($values[$field]) ? self::clean_text($values[$field]) : '';
                if ( '' === $raw ) continue;
                $entries[] = array('source_id'=>!empty($entry['source_id'])?absint($entry['source_id']):0,'source_name'=>!empty($entry['source_name'])?self::clean_text($entry['source_name']):'Kaynak','value'=>$raw,'key'=>self::comparison_key($field,$raw));
            }
            if ( count($entries) < 2 ) continue;
            $groups = array();
            foreach ( $entries as $entry ) {
                $placed = false;
                foreach ( $groups as &$group ) {
                    if ( self::values_equivalent($field,$entry['key'],$group['key']) ) { $group['items'][]=$entry; $placed=true; break; }
                }
                unset($group);
                if(!$placed) $groups[] = array('key'=>$entry['key'],'items'=>array($entry));
            }
            if(count($groups)>1) $conflicts[$field] = array_values($entries);
        }
        return $conflicts;
    }

    private static function comparison_key($field,$value){ return in_array($field,array('start_date','end_date'),true)?self::date_part($value):self::normalize_text($value); }
    private static function values_equivalent($field,$left,$right){
        if($left===$right)return true; if(!$left||!$right)return false;
        if(in_array($field,array('start_date','end_date','location_type'),true))return false;
        $percent=0.0; similar_text($left,$right,$percent); return $percent >= ('address'===$field?68:75);
    }

    private static function operational_event_ids(){
        $ids=get_posts(array('post_type'=>'event','post_status'=>array('publish','draft','future','pending','private'),'posts_per_page'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','no_found_rows'=>true));
        $today=current_time('Y-m-d');$result=array();
        foreach($ids as $event_id){$event_id=absint($event_id);$start=self::date_part(get_post_meta($event_id,'start_date',true));$end=self::date_part(get_post_meta($event_id,'end_date',true));if(!$start||($end?:$start)>=$today)$result[]=$event_id;}
        return $result;
    }

    public static function add_meta_boxes(){ add_meta_box('sektorel_event_data_health','Veri Sağlığı',array(__CLASS__,'render_meta_box'),'event','side','high'); }
    public static function render_meta_box($post){
        $result=self::assess_event($post->ID); if(is_wp_error($result))return;
        $h=$result['health'];$labels=self::field_labels();
        echo '<p style="font-size:24px;font-weight:700;margin:0 0 4px;">'.esc_html((string)$h['score']).'/100</p>';
        echo '<p><strong>'.esc_html('complete'===$h['status']?'İyi':('partial'===$h['status']?'Eksikler var':'Zayıf')).'</strong> · '.esc_html((string)$h['evidence_count']).' kaynak kanıtı</p>';
        if($h['missing_fields']){echo '<p><strong>Eksik alanlar</strong></p><ul style="list-style:disc;padding-left:18px;">';foreach($h['missing_fields'] as $field)echo '<li>'.esc_html(isset($labels[$field])?$labels[$field]:$field).'</li>';echo '</ul>';}
        else echo '<p style="color:#116329;"><strong>Kritik eksik alan yok.</strong></p>';
        if($h['conflicts']){echo '<p style="color:#b32d2e;"><strong>Kaynak çakışması: '.esc_html((string)count($h['conflicts'])).' alan</strong></p><ul style="list-style:disc;padding-left:18px;">';foreach(array_keys($h['conflicts']) as $field)echo '<li>'.esc_html(isset($labels[$field])?$labels[$field]:$field).'</li>';echo '</ul><p class="description">Çakışan değerler otomatik overwrite edilmez.</p>';}
    }
    public static function columns($columns){$result=array();foreach($columns as $key=>$label){$result[$key]=$label;if('date'===$key)$result['event_health']='Veri Sağlığı';}if(!isset($result['event_health']))$result['event_health']='Veri Sağlığı';return $result;}
    public static function render_column($column,$post_id){if('event_health'!==$column)return;$score=absint(get_post_meta($post_id,'event_completeness_score',true));$conflicts=absint(get_post_meta($post_id,'event_conflict_count',true));echo '<strong>'.esc_html((string)$score).'/100</strong>';if($conflicts)echo '<br><span style="color:#b32d2e;">'.esc_html((string)$conflicts).' çakışma</span>';}
    private static function field_labels(){return array('title'=>'Başlık','start_date'=>'Başlangıç tarihi','end_date'=>'Bitiş tarihi','location_type'=>'Konum türü','venue'=>'Mekan','address'=>'Adres','organizer'=>'Organizatör','official_link'=>'Resmî/kaynak bağlantısı','registration_link'=>'Kayıt bağlantısı','description'=>'Açıklama','online_location'=>'Online katılım bağlantısı','hybrid_link'=>'Online katılım bağlantısı');}
    private static function text_length($v){return function_exists('mb_strlen')?mb_strlen((string)$v,'UTF-8'):strlen((string)$v);}
    private static function date_part($v){return preg_match('/^(\d{4}-\d{2}-\d{2})/',trim((string)$v),$m)?$m[1]:'';}
    private static function normalize_text($v){$v=strtolower(remove_accents(self::clean_text($v)));$v=preg_replace('/[^a-z0-9]+/i',' ',$v);return trim(preg_replace('/\s+/',' ',$v));}
    private static function clean_text($v){$v=html_entity_decode((string)$v,ENT_QUOTES|ENT_HTML5,'UTF-8');$v=wp_strip_all_tags($v);$v=str_replace("\xC2\xA0",' ',$v);return trim(preg_replace('/\s+/u',' ',$v));}
    private static function require_ajax(){check_ajax_referer(self::NONCE_ACTION,'nonce');if(!current_user_can('manage_options'))wp_send_json_error(array('message'=>'Yetkisiz işlem.'),403);}
    private static function queue_key($user_id,$token){return 'sektorel_event_health_'.absint($user_id).'_'.sanitize_key($token);}
}
