<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Suggestion-only AI helper. Never mutates Event business fields or matcher state. */
class Sektorel_Event_AI_Assistant {
    const NONCE_ACTION = 'sektorel_event_ai_assistant';
    const META_KEY = 'event_ai_suggestion';

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 110 );
        add_action( 'wp_ajax_sektorel_event_ai_suggest', array( __CLASS__, 'ajax_suggest' ) );
    }

    public static function add_meta_boxes() {
        add_meta_box( 'sektorel_event_ai_assistant', 'AI Yardımcısı', array( __CLASS__, 'render_meta_box' ), 'event', 'side', 'default' );
    }

    public static function render_meta_box( $post ) {
        $enabled = self::api_key() !== '';
        $saved = get_post_meta( $post->ID, self::META_KEY, true );
        echo '<p class="description">AI yalnız inceleme önerisi üretir; Event alanlarını, matcher veya duplicate kararını değiştirmez.</p>';
        if ( ! $enabled ) {
            echo '<p><strong>AI kapalı.</strong></p><p class="description">Etkinleştirmek için wp-config.php içinde <code>SEKTOREL_OPENAI_API_KEY</code> tanımlanabilir.</p>';
        } else {
            $nonce = wp_create_nonce( self::NONCE_ACTION );
            echo '<p><button type="button" class="button" id="sektorel-ai-suggest">AI önerisi üret</button></p><div id="sektorel-ai-status" class="description"></div>';
            ?>
            <script>jQuery(function($){$('#sektorel-ai-suggest').on('click',function(){var b=$(this),s=$('#sektorel-ai-status');b.prop('disabled',true);s.text('Öneri hazırlanıyor…');$.post(ajaxurl,{action:'sektorel_event_ai_suggest',nonce:'<?php echo esc_js( $nonce ); ?>',event_id:<?php echo absint( $post->ID ); ?>}).done(function(r){if(r&&r.success){location.reload();}else{s.text(r&&r.data&&r.data.message?r.data.message:'AI önerisi üretilemedi.');b.prop('disabled',false);}}).fail(function(){s.text('AI isteği başarısız.');b.prop('disabled',false);});});});</script>
            <?php
        }
        if ( is_array( $saved ) && ! empty( $saved['summary'] ) ) {
            echo '<hr><p><strong>Son öneri</strong></p><p>' . esc_html( $saved['summary'] ) . '</p>';
            if ( ! empty( $saved['suggestions'] ) && is_array( $saved['suggestions'] ) ) {
                echo '<ul style="list-style:disc;padding-left:18px;">';
                foreach ( $saved['suggestions'] as $item ) {
                    if ( is_array( $item ) && ! empty( $item['field'] ) && ! empty( $item['suggestion'] ) ) echo '<li><strong>'.esc_html($item['field']).':</strong> '.esc_html($item['suggestion']).'</li>';
                }
                echo '</ul>';
            }
        }
    }

    public static function ajax_suggest() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        if ( ! $event_id || 'event' !== get_post_type( $event_id ) ) wp_send_json_error( array( 'message' => 'Geçersiz etkinlik.' ) );
        if ( class_exists( 'Sektorel_Event_Data_Health' ) ) Sektorel_Event_Data_Health::assess_event( $event_id );
        $result = self::request_suggestion( $event_id );
        if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        update_post_meta( $event_id, self::META_KEY, $result );
        wp_send_json_success( array( 'message' => 'AI önerisi kaydedildi.' ) );
    }

    private static function request_suggestion( $event_id ) {
        $key = self::api_key();
        if ( ! $key ) return new WP_Error( 'ai_disabled', 'OpenAI API anahtarı tanımlı değil.' );
        $payload = array(
            'event' => array(
                'title' => get_the_title( $event_id ),
                'description' => self::excerpt( get_post_field( 'post_content', $event_id ), 1200 ),
                'start_date' => get_post_meta( $event_id, 'start_date', true ), 'end_date' => get_post_meta( $event_id, 'end_date', true ),
                'location_type' => get_post_meta( $event_id, 'location_type', true ), 'venue' => get_post_meta( $event_id, 'venue', true ),
                'address' => get_post_meta( $event_id, 'address', true ), 'organizer' => get_post_meta( $event_id, 'organizer', true ),
                'event_url' => get_post_meta( $event_id, 'event_url', true ), 'registration_link' => get_post_meta( $event_id, 'registration_link', true ),
            ),
            'missing_fields' => get_post_meta( $event_id, 'event_missing_fields', true ),
            'conflicts' => get_post_meta( $event_id, 'event_conflicts', true ),
            'evidence' => get_post_meta( $event_id, 'event_source_evidence', true ),
        );
        $prompt = "Sektörel Ajanda veri kalite yardımcısısın. Yalnız verilen Event ve kaynak kanıtlarını kullan; bilgi uydurma. Çakışmada kesin değer seçme, manuel doğrulama öner. Yalnız JSON döndür: {\"summary\":\"...\",\"suggestions\":[{\"field\":\"...\",\"suggestion\":\"...\",\"confidence\":\"high|medium|low\"}]}.\n\n" . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $response = wp_remote_post( 'https://api.openai.com/v1/responses', array(
            'timeout' => 25,
            'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
            'body' => wp_json_encode( array( 'model' => self::model(), 'input' => $prompt, 'max_output_tokens' => 900 ) ),
        ) );
        if ( is_wp_error( $response ) ) return $response;
        $code = absint( wp_remote_retrieve_response_code( $response ) ); $json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) return new WP_Error( 'openai_http_error', 'OpenAI isteği başarısız oldu (HTTP '.$code.').' );
        $text = ! empty( $json['output_text'] ) ? $json['output_text'] : '';
        if ( ! $text && ! empty( $json['output'] ) ) foreach ( $json['output'] as $out ) foreach ( isset($out['content'])?(array)$out['content']:array() as $c ) if ( ! empty($c['text']) ) $text .= $c['text'];
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i','',trim((string)$text)); $data = json_decode($text,true);
        if ( ! is_array($data) ) return new WP_Error( 'openai_invalid_json', 'AI geçerli öneri JSON’u döndürmedi.' );
        $items=array(); foreach(isset($data['suggestions'])&&is_array($data['suggestions'])?$data['suggestions']:array() as $item){if(!is_array($item))continue;$f=sanitize_key($item['field']??'');$s=sanitize_text_field($item['suggestion']??'');$c=sanitize_key($item['confidence']??'low');if($f&&$s)$items[]=array('field'=>$f,'suggestion'=>$s,'confidence'=>in_array($c,array('high','medium','low'),true)?$c:'low');}
        return array('summary'=>sanitize_text_field($data['summary']??'AI veri kalite önerisi.'),'suggestions'=>array_slice($items,0,12),'model'=>self::model(),'created_at'=>current_time('mysql'));
    }

    private static function excerpt($v,$limit){$v=trim(preg_replace('/\s+/u',' ',wp_strip_all_tags((string)$v)));return function_exists('mb_substr')?mb_substr($v,0,$limit,'UTF-8'):substr($v,0,$limit);}
    private static function api_key(){return defined('SEKTOREL_OPENAI_API_KEY')?trim((string)SEKTOREL_OPENAI_API_KEY):'';}
    private static function model(){return defined('SEKTOREL_OPENAI_MODEL')&&trim((string)SEKTOREL_OPENAI_MODEL)!==''?trim((string)SEKTOREL_OPENAI_MODEL):'gpt-5-mini';}
}
