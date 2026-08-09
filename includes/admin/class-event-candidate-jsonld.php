<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Candidate_JSONLD {

    const NONCE_ACTION = 'sektorel_event_candidate_jsonld';
    const BATCH_SIZE   = 3;
    const TIMEOUT      = 12;
    const MAX_BODY     = 1048576;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ), 40 );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
        add_filter( 'manage_event_candidate_posts_columns', array( __CLASS__, 'columns' ) );
        add_action( 'manage_event_candidate_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
        add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 20, 2 );
        add_action( 'admin_post_sektorel_import_event_candidate', array( __CLASS__, 'handle_import_candidate' ) );
        add_action( 'wp_ajax_sektorel_prepare_jsonld_scan', array( __CLASS__, 'ajax_prepare_scan' ) );
        add_action( 'wp_ajax_sektorel_jsonld_scan_batch', array( __CLASS__, 'ajax_scan_batch' ) );
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=event',
            'JSON-LD Etkinliklerini Tara',
            'JSON-LD Tara',
            'manage_options',
            'sektorel-jsonld-events',
            array( __CLASS__, 'render_scan_page' )
        );
    }

    public static function render_scan_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }
        $nonce = wp_create_nonce( self::NONCE_ACTION );
        $count = self::jsonld_source_ids();
        ?>
        <div class="wrap">
            <h1>JSON-LD Etkinliklerini Tara</h1>
            <p>JSON-LD Event sinyali bulunan erişilebilir kaynaklardan etkinlik adaylarını çıkarır. Hiçbir kayıt otomatik yayınlanmaz.</p>
            <div class="card" style="max-width:900px;padding:22px;">
                <p><strong><?php echo esc_html( count( $count ) ); ?></strong> JSON-LD kaynağı taramaya hazır.</p>
                <p><button type="button" class="button button-primary button-hero" id="sektorel-jsonld-start">JSON-LD Kaynaklarını Tara</button></p>
                <div id="sektorel-jsonld-progress" style="display:none;margin-top:20px;">
                    <div style="height:22px;background:#e2e4e7;overflow:hidden;"><div id="sektorel-jsonld-bar" style="width:0;height:100%;background:#2271b1;"></div></div>
                    <p><strong id="sektorel-jsonld-count">0 / 0</strong></p>
                </div>
                <div id="sektorel-jsonld-summary" style="display:none;margin-top:16px;padding:14px;background:#f6f7f7;border-left:4px solid #2271b1;"></div>
                <div id="sektorel-jsonld-log" style="display:none;margin-top:16px;max-height:280px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;font:12px/1.6 monospace;"></div>
            </div>
        </div>
        <script>
        jQuery(function($){
            var token='', total=0, offset=0, totals={created:0,updated:0,skipped:0,error:0}, running=false;
            function log(m,e){var l=$('#sektorel-jsonld-log');l.show().append('<div style="color:'+(e?'#ff8080':'#f0f0f1')+'">'+$('<div>').text(m).html()+'</div>');l.scrollTop(l[0].scrollHeight);}
            function progress(){var p=total?Math.round((offset/total)*100):0;$('#sektorel-jsonld-progress').show();$('#sektorel-jsonld-bar').css('width',p+'%');$('#sektorel-jsonld-count').text(offset+' / '+total);}
            function fail(m){running=false;$('#sektorel-jsonld-start').prop('disabled',false).text('Tekrar Dene');log(m,true);}
            function finish(){running=false;$('#sektorel-jsonld-start').prop('disabled',false).text('Yeniden Tara');$('#sektorel-jsonld-bar').css('width','100%').css('background','#00a32a');$('#sektorel-jsonld-summary').show().html('<strong>Tarama tamamlandı.</strong><br>Yeni aday: <strong>'+totals.created+'</strong> &nbsp; Güncellendi: <strong>'+totals.updated+'</strong> &nbsp; Atlandı: <strong>'+totals.skipped+'</strong> &nbsp; Hata: <strong>'+totals.error+'</strong><br><br><a class="button" href="edit.php?post_type=event_candidate">Aday Etkinlikleri Gör</a>');}
            function next(){
                $.post(ajaxurl,{action:'sektorel_jsonld_scan_batch',nonce:'<?php echo esc_js( $nonce ); ?>',token:token,offset:offset}).done(function(r){
                    if(!r||!r.success){fail(r&&r.data&&r.data.message?r.data.message:'Batch başarısız.');return;}
                    totals.created+=Number(r.data.created||0);totals.updated+=Number(r.data.updated||0);totals.skipped+=Number(r.data.skipped||0);totals.error+=Number(r.data.error||0);offset=Number(r.data.next_offset||total);progress();
                    (r.data.messages||[]).forEach(function(m){log(m,false);});
                    if(r.data.done){finish();}else{setTimeout(next,250);}
                }).fail(function(){fail('Sunucu isteği başarısız.');});
            }
            $('#sektorel-jsonld-start').on('click',function(){if(running)return;running=true;offset=0;totals={created:0,updated:0,skipped:0,error:0};$('#sektorel-jsonld-log').empty().show();$(this).prop('disabled',true).text('Kuyruk Hazırlanıyor...');$.post(ajaxurl,{action:'sektorel_prepare_jsonld_scan',nonce:'<?php echo esc_js( $nonce ); ?>'}).done(function(r){if(!r||!r.success){fail(r&&r.data&&r.data.message?r.data.message:'Kuyruk hazırlanamadı.');return;}token=r.data.token;total=Number(r.data.total||0);progress();$('#sektorel-jsonld-start').text('Taranıyor...');next();}).fail(function(){fail('Kuyruk isteği başarısız.');});});
        });
        </script>
        <?php
    }

    public static function ajax_prepare_scan() {
        self::require_ajax();
        $ids = self::jsonld_source_ids();
        if ( ! $ids ) {
            wp_send_json_error( array( 'message' => 'JSON-LD kaynağı bulunamadı.' ) );
        }
        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient( self::queue_key( get_current_user_id(), $token ), $ids, 2 * HOUR_IN_SECONDS );
        wp_send_json_success( array( 'token' => $token, 'total' => count( $ids ) ) );
    }

    public static function ajax_scan_batch() {
        self::require_ajax();
        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $ids = get_transient( self::queue_key( get_current_user_id(), $token ) );
        if ( ! is_array( $ids ) ) {
            wp_send_json_error( array( 'message' => 'Tarama kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $created=$updated=$skipped=$error=0; $messages=array();
        foreach ( array_slice( $ids, $offset, self::BATCH_SIZE ) as $source_id ) {
            $result = self::scan_source( absint( $source_id ) );
            if ( is_wp_error( $result ) ) { $error++; $messages[]='Hata: '.get_the_title($source_id).' — '.$result->get_error_message(); continue; }
            $created += $result['created']; $updated += $result['updated']; $skipped += $result['skipped'];
            $messages[] = get_the_title($source_id).': '.$result['created'].' yeni, '.$result['updated'].' güncel, '.$result['skipped'].' atlandı.';
        }
        $total=count($ids); $next=min($total,$offset+self::BATCH_SIZE); $done=$next>=$total;
        if($done) delete_transient(self::queue_key(get_current_user_id(),$token));
        wp_send_json_success(array('created'=>$created,'updated'=>$updated,'skipped'=>$skipped,'error'=>$error,'messages'=>$messages,'next_offset'=>$next,'done'=>$done));
    }

    private static function scan_source( $source_id ) {
        $url = (string) get_post_meta( $source_id, 'source_url', true );
        if ( ! $url ) return new WP_Error( 'missing_url', 'Kaynak URL eksik.' );
        $response = wp_safe_remote_get( $url, array( 'timeout'=>self::TIMEOUT, 'redirection'=>3, 'limit_response_size'=>self::MAX_BODY, 'user-agent'=>'SektorelAjandaBot/1.0; +'.home_url('/') ) );
        if ( is_wp_error( $response ) ) return $response;
        $code=(int)wp_remote_retrieve_response_code($response); if($code<200||$code>=400) return new WP_Error('http_error','HTTP '.$code);
        $body=(string)wp_remote_retrieve_body($response);
        $items=self::extract_jsonld_events($body);
        if(!$items) return array('created'=>0,'updated'=>0,'skipped'=>1);
        $created=$updated=$skipped=0;
        foreach($items as $item){$r=self::upsert_candidate($source_id,$url,$item); if('created'===$r)$created++; elseif('updated'===$r)$updated++; else $skipped++;}
        return array('created'=>$created,'updated'=>$updated,'skipped'=>$skipped);
    }

    private static function extract_jsonld_events( $html ) {
        if ( ! class_exists( 'DOMDocument' ) ) return array();
        $dom=new DOMDocument(); $prev=libxml_use_internal_errors(true); $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING); libxml_clear_errors(); libxml_use_internal_errors($prev);
        $events=array();
        foreach($dom->getElementsByTagName('script') as $script){
            if(strtolower(trim($script->getAttribute('type'))) !== 'application/ld+json') continue;
            $data=json_decode(trim($script->textContent),true); if(!is_array($data)) continue;
            self::walk_jsonld($data,$events);
        }
        return $events;
    }

    private static function walk_jsonld( $node, &$events ) {
        if(!is_array($node)) return;
        $type=isset($node['@type'])?$node['@type']:null;
        $types=is_array($type)?$type:array($type);
        foreach($types as $t){if(is_string($t)&&0===strcasecmp($t,'Event')){$events[]=$node;break;}}
        foreach($node as $value){if(is_array($value)) self::walk_jsonld($value,$events);}
    }

    private static function upsert_candidate( $source_id, $source_url, $item ) {
        $title=sanitize_text_field(isset($item['name'])?$item['name']:'');
        $start=self::normalize_datetime(isset($item['startDate'])?$item['startDate']:'');
        if(!$title||!$start) return 'skipped';
        $end=self::normalize_datetime(isset($item['endDate'])?$item['endDate']:'');
        $event_url=esc_url_raw(isset($item['url'])?$item['url']:$source_url);
        $fingerprint=sha1(strtolower(remove_accents($title)).'|'.$start.'|'.wp_parse_url($event_url,PHP_URL_HOST));
        $existing=get_posts(array('post_type'=>'event_candidate','post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','meta_key'=>'candidate_fingerprint','meta_value'=>$fingerprint,'no_found_rows'=>true));
        $location=self::location_data(isset($item['location'])?$item['location']:array());
        $organizer=self::organization_name(isset($item['organizer'])?$item['organizer']:array());
        $description=isset($item['description'])?wp_kses_post($item['description']):'';
        $registration=self::offer_url(isset($item['offers'])?$item['offers']:array(),$event_url);
        $post_id=!empty($existing[0])?(int)$existing[0]:0;
        $postarr=array('post_type'=>'event_candidate','post_status'=>'publish','post_title'=>$title,'post_content'=>$description);
        if($post_id){$postarr['ID']=$post_id;$result=wp_update_post($postarr,true);}else{$result=wp_insert_post($postarr,true);}
        if(is_wp_error($result)) return 'skipped'; $post_id=(int)$result;
        $meta=array('candidate_fingerprint'=>$fingerprint,'candidate_status'=>'new','source_id'=>$source_id,'source_url'=>$source_url,'event_url'=>$event_url,'start_date'=>$start,'end_date'=>$end,'location_type'=>$location['type'],'venue'=>$location['venue'],'address'=>$location['address'],'organizer'=>$organizer,'registration_link'=>$registration,'parser_type'=>'jsonld');
        foreach($meta as $k=>$v) update_post_meta($post_id,$k,$v);
        return !empty($existing[0])?'updated':'created';
    }

    public static function add_meta_boxes() {
        add_meta_box('sektorel_candidate_details','Aday Etkinlik Detayları',array(__CLASS__,'render_meta_box'),'event_candidate','normal','high');
    }

    public static function render_meta_box( $post ) {
        $keys=array('candidate_status','parser_type','source_url','event_url','start_date','end_date','location_type','venue','address','organizer','registration_link','imported_event_id');
        echo '<table class="widefat striped"><tbody>';
        foreach($keys as $key){$value=get_post_meta($post->ID,$key,true);echo '<tr><th style="width:180px">'.esc_html($key).'</th><td>'.($value?esc_html((string)$value):'—').'</td></tr>';}
        echo '</tbody></table>';
    }

    public static function columns( $columns ) {
        return array('cb'=>$columns['cb']??'','title'=>'Aday Etkinlik','candidate_status'=>'Durum','start_date'=>'Başlangıç','source'=>'Kaynak','parser'=>'Parser','date'=>'Bulunma');
    }

    public static function render_column( $column, $post_id ) {
        if('candidate_status'===$column||'start_date'===$column){echo esc_html((string)get_post_meta($post_id,$column,true)?:'—');}
        elseif('parser'===$column){echo esc_html(strtoupper((string)get_post_meta($post_id,'parser_type',true)));}
        elseif('source'===$column){$u=(string)get_post_meta($post_id,'source_url',true); echo $u?'<a target="_blank" rel="noopener noreferrer" href="'.esc_url($u).'">'.esc_html(wp_parse_url($u,PHP_URL_HOST)).'</a>':'—';}
    }

    public static function row_actions( $actions, $post ) {
        if(!$post||'event_candidate'!==$post->post_type||!current_user_can('manage_options')) return $actions;
        if('imported'===get_post_meta($post->ID,'candidate_status',true)) return $actions;
        $url=wp_nonce_url(admin_url('admin-post.php?action=sektorel_import_event_candidate&candidate_id='.$post->ID),self::NONCE_ACTION.'_import_'.$post->ID);
        $actions['import_event']='<a href="'.esc_url($url).'" style="font-weight:700;">Etkinliğe Ekle</a>';
        return $actions;
    }

    public static function handle_import_candidate() {
        if(!current_user_can('manage_options')) wp_die('Yetkisiz işlem.');
        $candidate_id=isset($_GET['candidate_id'])?absint($_GET['candidate_id']):0;
        check_admin_referer(self::NONCE_ACTION.'_import_'.$candidate_id);
        if(!$candidate_id||'event_candidate'!==get_post_type($candidate_id)) wp_die('Geçersiz aday.');
        $existing=(int)get_post_meta($candidate_id,'imported_event_id',true);
        if($existing&&'event'===get_post_type($existing)){wp_safe_redirect(get_edit_post_link($existing,'url'));exit;}
        $event_id=wp_insert_post(array('post_type'=>'event','post_status'=>'draft','post_title'=>get_the_title($candidate_id),'post_content'=>get_post_field('post_content',$candidate_id)),true);
        if(is_wp_error($event_id)) wp_die(esc_html($event_id->get_error_message()));
        $keys=array('start_date','end_date','location_type','venue','address','organizer','registration_link'); foreach($keys as $key) update_post_meta($event_id,$key,get_post_meta($candidate_id,$key,true));
        update_post_meta($event_id,'event_type','konferans');
        update_post_meta($event_id,'source_candidate_id',$candidate_id);
        update_post_meta($event_id,'source_url',get_post_meta($candidate_id,'event_url',true)?:get_post_meta($candidate_id,'source_url',true));
        update_post_meta($candidate_id,'candidate_status','imported'); update_post_meta($candidate_id,'imported_event_id',$event_id);
        wp_safe_redirect(get_edit_post_link($event_id,'url')); exit;
    }

    private static function jsonld_source_ids() {
        return array_values(array_map('absint',get_posts(array('post_type'=>'event_source','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true,'meta_query'=>array('relation'=>'AND',array('key'=>'check_state','value'=>'ok'),array('key'=>'detected_parser','value'=>'jsonld'))))));
    }

    private static function normalize_datetime( $value ) {
        if(!$value) return ''; try{$d=new DateTime((string)$value); return $d->format('Y-m-d\TH:i');}catch(Exception $e){return '';}
    }

    private static function location_data( $location ) {
        $result=array('type'=>'physical','venue'=>'','address'=>''); if(!is_array($location)) return $result;
        $type=isset($location['@type'])?$location['@type']:''; if(is_string($type)&&false!==stripos($type,'VirtualLocation')){$result['type']='online';$result['venue']=isset($location['url'])?sanitize_text_field($location['url']):'Online';return $result;}
        $result['venue']=isset($location['name'])?sanitize_text_field($location['name']):''; $address=isset($location['address'])?$location['address']:'';
        if(is_array($address)){ $parts=array(); foreach(array('streetAddress','addressLocality','addressRegion','postalCode','addressCountry') as $k){if(!empty($address[$k]))$parts[]=is_array($address[$k])?'':sanitize_text_field($address[$k]);} $result['address']=implode(', ',array_filter($parts)); } else $result['address']=sanitize_text_field((string)$address);
        return $result;
    }

    private static function organization_name( $org ) { if(is_array($org)&&!empty($org['name']))return sanitize_text_field($org['name']); if(is_string($org))return sanitize_text_field($org); return ''; }
    private static function offer_url( $offers, $fallback ) { if(is_array($offers)&&isset($offers['url']))return esc_url_raw($offers['url']); if(is_array($offers)&&isset($offers[0])&&is_array($offers[0])&&!empty($offers[0]['url']))return esc_url_raw($offers[0]['url']); return esc_url_raw($fallback); }
    private static function require_ajax(){check_ajax_referer(self::NONCE_ACTION,'nonce'); if(!current_user_can('manage_options'))wp_send_json_error(array('message'=>'Yetkisiz işlem.'),403);}
    private static function queue_key($user_id,$token){return 'sektorel_jsonld_'.$user_id.'_'.sanitize_key($token);}
}
