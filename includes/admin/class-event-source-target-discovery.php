<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Source_Target_Discovery {
    const NONCE_ACTION = 'sektorel_event_source_target_discovery';
    const BATCH_SIZE = 3;
    const TIMEOUT = 12;
    const MAX_BODY = 1048576;
    const MIN_SCORE = 180;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ), 42 );
        add_action( 'wp_ajax_sektorel_prepare_source_target_discovery', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_source_target_discovery_batch', array( __CLASS__, 'ajax_batch' ) );
        add_filter( 'manage_event_source_posts_columns', array( __CLASS__, 'columns' ), 60 );
        add_action( 'manage_event_source_posts_custom_column', array( __CLASS__, 'render_column' ), 60, 2 );
    }

    public static function add_admin_menu() {
        add_submenu_page( 'edit.php?post_type=event', 'Etkinlik Kaynak Hedeflerini Keşfet', 'Kaynak Hedeflerini Keşfet', 'manage_options', 'sektorel-source-target-discovery', array( __CLASS__, 'render_page' ) );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Yetkisiz işlem.' );
        $nonce = wp_create_nonce( self::NONCE_ACTION );
        $ids = self::eligible_source_ids();
        ?>
        <div class="wrap"><h1>Etkinlik Kaynak Hedeflerini Keşfet</h1>
        <p>Ana sayfa / generic URL kullanan erişilebilir HTML kaynaklarında aynı domain üzerindeki yüksek güvenli etkinlik liste sayfalarını keşfeder.</p>
        <div class="card" style="max-width:920px;padding:22px;">
        <p><strong><?php echo esc_html( count( $ids ) ); ?></strong> kaynak keşfe uygun.</p>
        <p>Eski URL <code>source_original_url</code> alanında saklanır. Yalnız yüksek güvenli hedefler otomatik uygulanır.</p>
        <p><button type="button" class="button button-primary button-hero" id="sektorel-discovery-start">Hedefleri Keşfet</button></p>
        <div id="sektorel-discovery-progress" style="display:none;margin-top:20px;"><div style="height:22px;background:#e2e4e7;overflow:hidden;"><div id="sektorel-discovery-bar" style="width:0;height:100%;background:#2271b1;"></div></div><p><strong id="sektorel-discovery-count">0 / 0</strong></p></div>
        <div id="sektorel-discovery-summary" style="display:none;margin-top:16px;padding:14px;background:#f6f7f7;border-left:4px solid #2271b1;"></div>
        <div id="sektorel-discovery-log" style="display:none;margin-top:16px;max-height:360px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;font:12px/1.6 monospace;"></div>
        </div></div>
        <script>jQuery(function($){var token='',total=0,offset=0,running=false,totals={changed:0,unchanged:0,error:0};function log(m,e){var b=$('#sektorel-discovery-log');b.show().append('<div style="color:'+(e?'#ff8080':'#f0f0f1')+'">'+$('<div>').text(m).html()+'</div>');b.scrollTop(b[0].scrollHeight);}function progress(){var p=total?Math.min(100,Math.round((offset/total)*100)):0;$('#sektorel-discovery-progress').show();$('#sektorel-discovery-bar').css('width',p+'%');$('#sektorel-discovery-count').text(offset+' / '+total);}function fail(m){running=false;$('#sektorel-discovery-start').prop('disabled',false).text('Tekrar Dene');log(m,true);}function finish(){running=false;$('#sektorel-discovery-start').prop('disabled',false).text('Yeniden Keşfet');$('#sektorel-discovery-bar').css('width','100%').css('background','#00a32a');$('#sektorel-discovery-summary').show().html('<strong>Keşif tamamlandı.</strong><br>Hedef değişti: <strong>'+totals.changed+'</strong> &nbsp; Değişmedi: <strong>'+totals.unchanged+'</strong> &nbsp; Hata: <strong>'+totals.error+'</strong><br><br><strong>Sonraki adım:</strong> Toplu Kaynak Kontrolü → HTML Tara.');}function next(){$.post(ajaxurl,{action:'sektorel_source_target_discovery_batch',nonce:'<?php echo esc_js( $nonce ); ?>',token:token,offset:offset}).done(function(r){if(!r||!r.success){fail(r&&r.data&&r.data.message?r.data.message:'Keşif batch başarısız.');return;}totals.changed+=Number(r.data.changed||0);totals.unchanged+=Number(r.data.unchanged||0);totals.error+=Number(r.data.error||0);offset=Number(r.data.next_offset||total);progress();(r.data.messages||[]).forEach(function(m){log(m,false);});if(r.data.done){finish();}else{window.setTimeout(next,250);}}).fail(function(){fail('Sunucu isteği başarısız.');});}$('#sektorel-discovery-start').on('click',function(){if(running)return;running=true;token='';total=0;offset=0;totals={changed:0,unchanged:0,error:0};$('#sektorel-discovery-summary').hide().empty();$('#sektorel-discovery-log').show().empty();$('#sektorel-discovery-bar').css('background','#2271b1');$(this).prop('disabled',true).text('Kuyruk Hazırlanıyor...');$.post(ajaxurl,{action:'sektorel_prepare_source_target_discovery',nonce:'<?php echo esc_js( $nonce ); ?>'}).done(function(r){if(!r||!r.success){fail(r&&r.data&&r.data.message?r.data.message:'Kuyruk hazırlanamadı.');return;}token=r.data.token;total=Number(r.data.total||0);progress();$('#sektorel-discovery-start').text('Keşfediliyor...');log(total+' kaynak kuyruğa alındı.',false);next();}).fail(function(){fail('Kuyruk isteği başarısız.');});});});</script>
        <?php
    }

    public static function ajax_prepare() {
        self::require_ajax();
        $ids = self::eligible_source_ids();
        if ( ! $ids ) wp_send_json_error( array( 'message' => 'Keşfe uygun kaynak bulunamadı.' ) );
        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient( self::queue_key( get_current_user_id(), $token ), $ids, 2 * HOUR_IN_SECONDS );
        wp_send_json_success( array( 'token' => $token, 'total' => count( $ids ) ) );
    }

    public static function ajax_batch() {
        self::require_ajax();
        $token = isset($_POST['token']) ? sanitize_key(wp_unslash($_POST['token'])) : '';
        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $ids = get_transient( self::queue_key( get_current_user_id(), $token ) );
        if ( ! is_array( $ids ) ) wp_send_json_error( array( 'message' => 'Keşif kuyruğu bulunamadı veya süresi doldu.' ) );
        $changed=$unchanged=$error=0; $messages=array();
        foreach ( array_slice( $ids, $offset, self::BATCH_SIZE ) as $source_id ) {
            $source_id=absint($source_id); $result=self::discover_source($source_id); $title=get_the_title($source_id);
            if(is_wp_error($result)){$error++;$messages[]='Hata: '.$title.' — '.$result->get_error_message();continue;}
            if(!empty($result['changed'])){$changed++;$messages[]=$title.' → '.$result['url'].' (skor '.$result['score'].')';}
            else{$unchanged++;$messages[]=$title.': hedef değiştirilmedi'.(!empty($result['best_url'])?'; en iyi aday '.$result['best_url'].' / '.$result['score']:'').'.';}
        }
        $total=count($ids);$next=min($total,$offset+self::BATCH_SIZE);$done=$next>=$total;if($done)delete_transient(self::queue_key(get_current_user_id(),$token));
        wp_send_json_success(array('changed'=>$changed,'unchanged'=>$unchanged,'error'=>$error,'messages'=>$messages,'next_offset'=>$next,'done'=>$done));
    }

    private static function discover_source( $source_id ) {
        $url=trim((string)get_post_meta($source_id,'source_url',true)); if(!$url)return new WP_Error('missing_url','Kaynak URL eksik.');
        $response=wp_safe_remote_get($url,array('timeout'=>self::TIMEOUT,'redirection'=>3,'limit_response_size'=>self::MAX_BODY,'user-agent'=>'SektorelAjandaBot/1.0; +'.home_url('/'),'headers'=>array('Accept'=>'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5')));
        if(is_wp_error($response))return $response; $code=(int)wp_remote_retrieve_response_code($response); if($code<200||$code>=400)return new WP_Error('http_error','HTTP '.$code); $body=(string)wp_remote_retrieve_body($response); if(!$body)return new WP_Error('empty_body','Boş HTML yanıtı.');
        $best=self::best_target($body,$url); update_post_meta($source_id,'target_discovery_at',current_time('mysql')); update_post_meta($source_id,'target_discovery_best_url',isset($best['url'])?$best['url']:''); update_post_meta($source_id,'target_discovery_score',isset($best['score'])?absint($best['score']):0); update_post_meta($source_id,'target_discovery_anchor',isset($best['anchor'])?sanitize_text_field($best['anchor']):'');
        if(!$best||empty($best['url'])||$best['score']<self::MIN_SCORE||self::same_url($best['url'],$url))return array('changed'=>false,'best_url'=>isset($best['url'])?$best['url']:'','score'=>isset($best['score'])?$best['score']:0);
        if(!get_post_meta($source_id,'source_original_url',true))update_post_meta($source_id,'source_original_url',$url); update_post_meta($source_id,'source_url',esc_url_raw($best['url'],array('http','https'))); update_post_meta($source_id,'target_discovery_status','applied'); update_post_meta($source_id,'target_discovery_previous_url',$url);
        delete_post_meta($source_id,'check_state'); delete_post_meta($source_id,'detected_parser'); delete_post_meta($source_id,'last_http_status'); delete_post_meta($source_id,'last_candidate_scan_at'); delete_post_meta($source_id,'last_candidate_count');
        return array('changed'=>true,'url'=>$best['url'],'score'=>$best['score']);
    }

    private static function best_target( $html, $base_url ) {
        if(!class_exists('DOMDocument')||!class_exists('DOMXPath'))return array(); $dom=new DOMDocument();$previous=libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);libxml_clear_errors();libxml_use_internal_errors($previous);$xpath=new DOMXPath($dom);$links=$xpath->query('//a[@href]');if(!$links)return array();$best=array();
        foreach($links as $link){if(!$link instanceof DOMElement)continue;$href=trim((string)$link->getAttribute('href'));if(!$href||0===strpos($href,'#'))continue;$absolute=self::absolute_url($href,$base_url);if(!$absolute||!self::same_host($absolute,$base_url))continue;$anchor=self::clean_text($link->textContent);$score=self::score_target($anchor,$absolute,$base_url);if($score<=0)continue;if(!$best||$score>$best['score'])$best=array('url'=>$absolute,'score'=>$score,'anchor'=>$anchor);} return $best;
    }

    private static function score_target( $anchor, $url, $base_url ) {
        $text=self::normalize_key($anchor);$path=self::normalize_key((string)wp_parse_url($url,PHP_URL_PATH));if(!$text&&!$path)return 0;$score=30;
        $exact=array('gelecek etkinlikler'=>190,'yaklasan etkinlikler'=>190,'tum etkinlikler'=>180,'etkinlikler'=>165,'etkinlik takvimi'=>180,'takvim'=>120,'upcoming events'=>190,'all events'=>180,'events'=>165,'event calendar'=>180,'calendar'=>120,'fuarlar'=>155,'trade fairs'=>155,'seminars'=>145,'webinars'=>145);
        if(isset($exact[$text]))$score+=$exact[$text];elseif(preg_match('/\b(gelecek|yaklasan|upcoming|tum|all)\b.*\b(etkinlik|event|fuar|fair|webinar|seminar)\b/i',$text))$score+=165;elseif(preg_match('/\b(etkinlik|events?|fuarlar|fairs?|calendar|takvim|agenda|webinars?|seminars?)\b/i',$text))$score+=110;
        if(preg_match('/\b(etkinlik|event|calendar|takvim|agenda|fuar|fair|webinar|seminar)\b/i',$path))$score+=55;if(preg_match('/\b(gelecek|upcoming)\b/i',$path))$score+=30;if(preg_match('/\b(gecmis|past|archive|arsiv)\b/i',$text.' '.$path))$score-=180;if(preg_match('/\b(20(?:1\d|2[0-5]))\b/',$text.' '.$path))$score-=90;if(preg_match('/\b(haber|news|blog|duyuru|announcement|press|media|video)\b/i',$text.' '.$path))$score-=120;if(self::same_url($url,$base_url))$score-=160;return max(0,$score);
    }

    private static function eligible_source_ids() {
        $ids=get_posts(array('post_type'=>'event_source','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','no_found_rows'=>true,'meta_query'=>array('relation'=>'AND',array('key'=>'source_status','value'=>'active'),array('key'=>'check_state','value'=>'ok'),array('key'=>'detected_parser','value'=>'html'))));$result=array();foreach($ids as $source_id){$url=trim((string)get_post_meta($source_id,'source_url',true));if($url&&self::is_generic_source_url($url))$result[]=absint($source_id);}return $result;
    }

    private static function is_generic_source_url( $url ) {$path=(string)wp_parse_url($url,PHP_URL_PATH);$path='/'.trim($path,'/');if('/'===$path||''===$path)return true;return(bool)preg_match('#^/(?:tr|en|de|fr)?/?(?:default|index|home)?(?:\.(?:html?|php))?/?$#i',$path);}
    public static function columns( $columns ) {$result=array();foreach($columns as $key=>$label){$result[$key]=$label;if('source_url'===$key)$result['target_discovery']='Keşfedilen Hedef';}return $result;}
    public static function render_column( $column, $post_id ) {if('target_discovery'!==$column)return;$url=(string)get_post_meta($post_id,'target_discovery_best_url',true);$score=absint(get_post_meta($post_id,'target_discovery_score',true));$status=(string)get_post_meta($post_id,'target_discovery_status',true);if(!$url){echo '—';return;}echo '<a href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer">'.esc_html(wp_parse_url($url,PHP_URL_HOST).wp_parse_url($url,PHP_URL_PATH)).'</a><br><span style="font-size:11px;color:#646970;">skor '.esc_html((string)$score).($status?' · '.esc_html($status):'').'</span>';}
    private static function same_host( $left, $right ) {$a=strtolower((string)wp_parse_url($left,PHP_URL_HOST));$b=strtolower((string)wp_parse_url($right,PHP_URL_HOST));$a=preg_replace('/^www\./','',$a);$b=preg_replace('/^www\./','',$b);return $a&&$b&&$a===$b;}
    private static function same_url( $left, $right ) {$normalize=static function($url){$parts=wp_parse_url(trim((string)$url));if(!is_array($parts)||empty($parts['host']))return '';$scheme=!empty($parts['scheme'])?strtolower($parts['scheme']):'https';$host=strtolower(preg_replace('/^www\./','',rtrim($parts['host'],'.')));$path=isset($parts['path'])?'/'.trim($parts['path'],'/'):'/';return $scheme.'://'.$host.$path;};return $normalize($left)===$normalize($right);}
    private static function absolute_url( $url, $base_url ) {$url=trim(html_entity_decode((string)$url,ENT_QUOTES|ENT_HTML5,'UTF-8'));if(!$url)return '';if(preg_match('#^https?://#i',$url))return esc_url_raw($url,array('http','https'));if(preg_match('#^[a-z][a-z0-9+.-]*:#i',$url))return '';$base=wp_parse_url($base_url);if(!is_array($base)||empty($base['scheme'])||empty($base['host']))return '';$origin=strtolower($base['scheme']).'://'.$base['host'];if(!empty($base['port']))$origin.=':'.absint($base['port']);if(0===strpos($url,'//'))return esc_url_raw(strtolower($base['scheme']).':'.$url,array('http','https'));if(0===strpos($url,'/'))return esc_url_raw($origin.$url,array('http','https'));$base_path=isset($base['path'])&&$base['path']?(string)$base['path']:'/';$directory='/'===substr($base_path,-1)?$base_path:trailingslashit(dirname($base_path));return esc_url_raw($origin.$directory.$url,array('http','https'));}
    private static function clean_text( $value ) {$value=html_entity_decode((string)$value,ENT_QUOTES|ENT_HTML5,'UTF-8');$value=wp_strip_all_tags($value);$value=preg_replace('/\s+/u',' ',$value);return trim((string)$value);}
    private static function normalize_key( $value ) {$value=strtolower(remove_accents(self::clean_text($value)));$value=preg_replace('/[^a-z0-9]+/i',' ',$value);return trim(preg_replace('/\s+/',' ',$value));}
    private static function require_ajax(){check_ajax_referer(self::NONCE_ACTION,'nonce');if(!current_user_can('manage_options'))wp_send_json_error(array('message'=>'Yetkisiz işlem.'),403);}
    private static function queue_key($user_id,$token){return 'sektorel_target_discovery_'.absint($user_id).'_'.sanitize_key($token);}
}
