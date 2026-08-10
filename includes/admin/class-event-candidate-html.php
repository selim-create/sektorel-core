<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ES-4B generic HTML event discovery.
 *
 * Conservative by design: a candidate is written only when a plausible event
 * title, an explicit start date and an event-context signal are all present.
 */
class Sektorel_Event_Candidate_HTML {

    const NONCE_ACTION = 'sektorel_event_candidate_html';
    const BATCH_SIZE   = 3;
    const TIMEOUT      = 12;
    const MAX_BODY     = 1048576;
    const MAX_NODES    = 180;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ), 41 );
        add_action( 'wp_ajax_sektorel_prepare_html_event_scan', array( __CLASS__, 'ajax_prepare_scan' ) );
        add_action( 'wp_ajax_sektorel_html_event_scan_batch', array( __CLASS__, 'ajax_scan_batch' ) );
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=event',
            'HTML Etkinliklerini Tara',
            'HTML Tara',
            'manage_options',
            'sektorel-html-events',
            array( __CLASS__, 'render_scan_page' )
        );
    }

    public static function render_scan_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }

        $nonce = wp_create_nonce( self::NONCE_ACTION );
        $ids   = self::html_source_ids();
        ?>
        <div class="wrap">
            <h1>HTML Etkinliklerini Tara</h1>
            <p>Erişilebilir HTML kaynaklarından etkinlik adaylarını generic kurallarla keşfeder. Bulunan kayıtlar yalnızca Aday Etkinlikler havuzuna yazılır; otomatik yayın yapılmaz.</p>
            <div class="card" style="max-width:900px;padding:22px;">
                <p><strong><?php echo esc_html( count( $ids ) ); ?></strong> HTML kaynağı taramaya hazır.</p>
                <p>Yanlış pozitifleri azaltmak için yalnızca etkinlik adı, başlangıç tarihi ve etkinlik bağlamı birlikte tespit edilebilen kayıtlar aday olarak oluşturulur.</p>
                <p><button type="button" class="button button-primary button-hero" id="sektorel-html-start">HTML Kaynaklarını Tara</button></p>
                <div id="sektorel-html-progress" style="display:none;margin-top:20px;">
                    <div style="height:22px;background:#e2e4e7;overflow:hidden;"><div id="sektorel-html-bar" style="width:0;height:100%;background:#2271b1;"></div></div>
                    <p><strong id="sektorel-html-count">0 / 0</strong></p>
                </div>
                <div id="sektorel-html-summary" style="display:none;margin-top:16px;padding:14px;background:#f6f7f7;border-left:4px solid #2271b1;"></div>
                <div id="sektorel-html-log" style="display:none;margin-top:16px;max-height:300px;overflow:auto;background:#1d2327;color:#f0f0f1;padding:12px;font:12px/1.6 monospace;"></div>
            </div>
        </div>
        <script>
        jQuery(function($){
            var token='',total=0,offset=0,running=false,totals={created:0,updated:0,skipped:0,error:0};
            function log(m,e){var l=$('#sektorel-html-log');l.show().append('<div style="color:'+(e?'#ff8080':'#f0f0f1')+'">'+$('<div>').text(m).html()+'</div>');l.scrollTop(l[0].scrollHeight);}
            function progress(){var p=total?Math.min(100,Math.round((offset/total)*100)):0;$('#sektorel-html-progress').show();$('#sektorel-html-bar').css('width',p+'%');$('#sektorel-html-count').text(offset+' / '+total);}
            function fail(m){running=false;$('#sektorel-html-start').prop('disabled',false).text('Tekrar Dene');log(m,true);}
            function finish(){running=false;$('#sektorel-html-start').prop('disabled',false).text('Yeniden Tara');$('#sektorel-html-bar').css('width','100%').css('background','#00a32a');$('#sektorel-html-summary').show().html('<strong>Tarama tamamlandı.</strong><br>Yeni aday: <strong>'+totals.created+'</strong> &nbsp; Güncellendi: <strong>'+totals.updated+'</strong> &nbsp; Atlandı: <strong>'+totals.skipped+'</strong> &nbsp; Hata: <strong>'+totals.error+'</strong><br><br><a class="button" href="edit.php?post_type=event_candidate">Aday Etkinlikleri Gör</a>');log('Tüm HTML kaynak kuyruğu işlendi.',false);}
            function next(){$.post(ajaxurl,{action:'sektorel_html_event_scan_batch',nonce:'<?php echo esc_js( $nonce ); ?>',token:token,offset:offset}).done(function(r){if(!r||!r.success){fail(r&&r.data&&r.data.message?r.data.message:'HTML batch başarısız.');return;}totals.created+=Number(r.data.created||0);totals.updated+=Number(r.data.updated||0);totals.skipped+=Number(r.data.skipped||0);totals.error+=Number(r.data.error||0);offset=Number(r.data.next_offset||total);progress();(r.data.messages||[]).forEach(function(m){log(m,false);});if(r.data.done){finish();}else{window.setTimeout(next,250);}}).fail(function(){fail('Sunucu isteği başarısız.');});}
            $('#sektorel-html-start').on('click',function(){if(running)return;running=true;token='';total=0;offset=0;totals={created:0,updated:0,skipped:0,error:0};$('#sektorel-html-summary').hide().empty();$('#sektorel-html-log').show().empty();$('#sektorel-html-bar').css('background','#2271b1');$(this).prop('disabled',true).text('Kuyruk Hazırlanıyor...');$.post(ajaxurl,{action:'sektorel_prepare_html_event_scan',nonce:'<?php echo esc_js( $nonce ); ?>'}).done(function(r){if(!r||!r.success){fail(r&&r.data&&r.data.message?r.data.message:'HTML kuyruğu hazırlanamadı.');return;}token=r.data.token;total=Number(r.data.total||0);progress();$('#sektorel-html-start').text('Taranıyor...');log(total+' HTML kaynağı kuyruğa alındı.',false);next();}).fail(function(){fail('Kuyruk isteği başarısız.');});});
        });
        </script>
        <?php
    }

    public static function ajax_prepare_scan() {
        self::require_ajax();
        $ids = self::html_source_ids();
        if ( ! $ids ) {
            wp_send_json_error( array( 'message' => 'Taramaya hazır HTML kaynağı bulunamadı.' ) );
        }
        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient( self::queue_key( get_current_user_id(), $token ), $ids, 2 * HOUR_IN_SECONDS );
        wp_send_json_success( array( 'token' => $token, 'total' => count( $ids ) ) );
    }

    public static function ajax_scan_batch() {
        self::require_ajax();
        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $ids    = get_transient( self::queue_key( get_current_user_id(), $token ) );
        if ( ! is_array( $ids ) ) {
            wp_send_json_error( array( 'message' => 'HTML tarama kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $created=$updated=$skipped=$error=0;
        $messages=array();
        foreach ( array_slice( $ids, $offset, self::BATCH_SIZE ) as $source_id ) {
            $source_id=absint($source_id);
            $result=self::scan_source($source_id);
            $title=get_the_title($source_id);
            if(is_wp_error($result)){$error++;$messages[]='Hata: '.$title.' — '.$result->get_error_message();continue;}
            $created+=$result['created'];$updated+=$result['updated'];$skipped+=$result['skipped'];
            $messages[]=$title.': '.$result['created'].' yeni, '.$result['updated'].' güncel, '.$result['skipped'].' atlandı.';
        }
        $total=count($ids);$next=min($total,$offset+self::BATCH_SIZE);$done=$next>=$total;
        if($done)delete_transient(self::queue_key(get_current_user_id(),$token));
        wp_send_json_success(array('created'=>$created,'updated'=>$updated,'skipped'=>$skipped,'error'=>$error,'messages'=>$messages,'next_offset'=>$next,'done'=>$done));
    }

    private static function scan_source( $source_id ) {
        $url=trim((string)get_post_meta($source_id,'source_url',true));
        if(!$url)return new WP_Error('missing_url','Kaynak URL eksik.');
        $response=wp_safe_remote_get($url,array('timeout'=>self::TIMEOUT,'redirection'=>3,'limit_response_size'=>self::MAX_BODY,'user-agent'=>'SektorelAjandaBot/1.0; +'.home_url('/'),'headers'=>array('Accept'=>'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5')));
        if(is_wp_error($response)){self::record_source_scan($source_id,0,$response->get_error_message());return $response;}
        $code=(int)wp_remote_retrieve_response_code($response);
        if($code<200||$code>=400){$message='HTTP '.$code;self::record_source_scan($source_id,0,$message);return new WP_Error('http_error',$message);}
        $content_type=strtolower((string)wp_remote_retrieve_header($response,'content-type'));
        $body=(string)wp_remote_retrieve_body($response);
        if(false===strpos($content_type,'html')&&false===stripos($body,'<html')){self::record_source_scan($source_id,0,'HTML içerik tespit edilmedi.');return new WP_Error('not_html','HTML içerik tespit edilmedi.');}
        $items=self::extract_html_events($body,$url);
        if(!$items){self::record_source_scan($source_id,0,'');return array('created'=>0,'updated'=>0,'skipped'=>1);}
        $created=$updated=$skipped=0;
        foreach($items as $item){$result=self::upsert_candidate($source_id,$url,$item);if('created'===$result)$created++;elseif('updated'===$result)$updated++;else$skipped++;}
        self::record_source_scan($source_id,count($items),'');
        return array('created'=>$created,'updated'=>$updated,'skipped'=>$skipped);
    }

    private static function extract_html_events( $html, $page_url ) {
        if(!class_exists('DOMDocument')||!class_exists('DOMXPath'))return array();
        $dom=new DOMDocument();$prev=libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);libxml_clear_errors();libxml_use_internal_errors($prev);
        $xpath=new DOMXPath($dom);$nodes=self::event_nodes($xpath);$items=array();$seen=array();
        foreach($nodes as $node){if(!$node instanceof DOMElement)continue;$item=self::extract_item($xpath,$node,$page_url);if(!$item||empty($item['title'])||empty($item['start_date']))continue;$key=sha1(self::normalize_title($item['title']).'|'.$item['start_date'].'|'.$item['event_url']);if(isset($seen[$key]))continue;$seen[$key]=true;$items[]=$item;}
        if(!$items){$body=$xpath->query('//body')->item(0);if($body instanceof DOMElement){$item=self::extract_item($xpath,$body,$page_url,true);if($item&&!empty($item['title'])&&!empty($item['start_date']))$items[]=$item;}}
        return array_slice($items,0,100);
    }

    private static function event_nodes( $xpath ) {
        $query="//article"
            ." | //*[@itemscope and contains(translate(@itemtype,'EVENT','event'),'event')]"
            ." | //*[@class and (contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'event') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'etkinlik') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'calendar-item') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'agenda-item') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'conference') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'webinar') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'expo'))]"
            ." | //*[@id and (contains(translate(@id,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'event') or contains(translate(@id,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'etkinlik'))]";
        $nodes=$xpath->query($query);if(!$nodes)return array();$result=array();
        foreach($nodes as $node){if(count($result)>=self::MAX_NODES)break;if($node instanceof DOMElement&&self::is_reasonable_container($node))$result[]=$node;}
        return $result;
    }

    private static function is_reasonable_container( $node ) {
        $text=self::clean_text($node->textContent);$len=function_exists('mb_strlen')?mb_strlen($text,'UTF-8'):strlen($text);return $len>=12&&$len<=12000;
    }

    private static function extract_item( $xpath, $node, $page_url, $page_fallback=false ) {
        $title=self::extract_title($xpath,$node,$page_fallback);if(!$title)return array();
        $dates=self::extract_dates($xpath,$node);if(empty($dates['start']))return array();
        $text=self::clean_text($node->textContent);if(!self::has_event_signal($node,$text,$title,$page_url,$page_fallback))return array();
        $event_url=self::extract_event_url($xpath,$node,$page_url,$page_fallback);
        $registration=self::extract_registration_url($xpath,$node,$event_url?:$page_url);
        $venue=self::extract_named_value($xpath,$node,array('venue','location','mekan','yer'));
        $address=self::extract_named_value($xpath,$node,array('address','adres'));
        $organizer=self::extract_named_value($xpath,$node,array('organizer','organiser','organization','organizat','düzenleyen','duzenleyen'));
        if(!$venue)$venue=self::extract_labeled_text($text,array('Mekan','Yer','Venue','Location'));
        if(!$address)$address=self::extract_labeled_text($text,array('Adres','Address'));
        if(!$organizer)$organizer=self::extract_labeled_text($text,array('Organizatör','Organizator','Düzenleyen','Organizer','Organiser'));
        return array('title'=>$title,'start_date'=>$dates['start'],'end_date'=>$dates['end'],'event_url'=>$event_url?:$page_url,'registration_link'=>$registration?:($event_url?:$page_url),'venue'=>self::limit_text($venue,240),'address'=>self::limit_text($address,500),'organizer'=>self::limit_text($organizer,240),'location_type'=>self::infer_location_type($text),'description'=>self::extract_description($xpath,$node));
    }

    private static function has_event_signal( $node, $text, $title, $page_url, $page_fallback ) {
        $attrs=$node instanceof DOMElement?$node->getAttribute('class').' '.$node->getAttribute('id').' '.$node->getAttribute('itemtype'):'';
        $haystack=strtolower(remove_accents($attrs.' '.$title.' '.$text.' '.$page_url));
        if(preg_match('/\b(event|etkinlik|fuar|fair|expo|conference|konferans|summit|zirve|congress|kongre|webinar|seminar|seminer|sempozyum|symposium|workshop|calistay|festival|forum|calendar|agenda)\b/i',$haystack))return true;
        if(preg_match('/\b(kayit|register|registration|bilet|ticket|basvur|katil|rsvp)\b/i',$haystack))return true;
        return $page_fallback&&preg_match('#/(event|events|etkinlik|etkinlikler|fuar|expo|conference|konferans|webinar|agenda|calendar)(?:/|$)#i',(string)wp_parse_url($page_url,PHP_URL_PATH));
    }

    private static function extract_title( $xpath, $node, $page_fallback ) {
        $queries=array(".//*[@itemprop='name'][self::h1 or self::h2 or self::h3 or self::h4]",'.//h1','.//h2','.//h3','.//h4',".//*[@class and contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'title')]");
        foreach($queries as $query){$nodes=$xpath->query($query,$node);if(!$nodes)continue;foreach($nodes as $candidate){$title=self::clean_text($candidate->textContent);if(self::is_valid_title($title,$page_fallback))return $title;}}
        return '';
    }

    private static function is_valid_title( $title, $page_fallback ) {
        $title=trim((string)$title);$len=function_exists('mb_strlen')?mb_strlen($title,'UTF-8'):strlen($title);if($len<4||$len>220)return false;
        $generic=array('etkinlikler','events','event','takvim','calendar','agenda','gündem','gundem');$lower=strtolower(remove_accents($title));if(in_array($lower,$generic,true))return false;
        if($page_fallback&&preg_match('/^(anasayfa|home|hakkimizda|about|haberler|news)$/i',$lower))return false;
        return true;
    }

    private static function extract_dates( $xpath, $node ) {
        $raw=array();$queries=array(".//*[@itemprop='startDate']/@content",".//*[@itemprop='startDate']/@datetime",".//*[@itemprop='endDate']/@content",".//*[@itemprop='endDate']/@datetime",'.//time/@datetime',".//*[@data-start]/@data-start",".//*[@data-date]/@data-date");
        foreach($queries as $query){$values=$xpath->query($query,$node);if(!$values)continue;foreach($values as $value_node){$value=trim((string)$value_node->nodeValue);if($value)$raw[]=$value;}}
        $parsed=array();foreach(array_unique($raw) as $value){$date=self::normalize_datetime($value);if($date)$parsed[]=$date;}
        if($parsed)return array('start'=>$parsed[0],'end'=>isset($parsed[1])?$parsed[1]:'');
        return self::parse_dates_from_text(self::clean_text($node->textContent));
    }

    private static function parse_dates_from_text( $text ) {
        $months=self::month_pattern();
        if(preg_match('/\b(\d{1,2})\s*[-–—]\s*(\d{1,2})\s+('.$months.')\s+(20\d{2})\b/iu',$text,$m)){$month=self::month_number($m[3]);if($month)return array('start'=>self::build_datetime((int)$m[4],$month,(int)$m[1],self::time_from_text($text)),'end'=>self::build_datetime((int)$m[4],$month,(int)$m[2],''));}
        if(preg_match('/\b(\d{1,2})\s+('.$months.')\s+(20\d{2})\s*[-–—]\s*(\d{1,2})\s+('.$months.')\s+(20\d{2})\b/iu',$text,$m)){$m1=self::month_number($m[2]);$m2=self::month_number($m[5]);if($m1&&$m2)return array('start'=>self::build_datetime((int)$m[3],$m1,(int)$m[1],self::time_from_text($text)),'end'=>self::build_datetime((int)$m[6],$m2,(int)$m[4],''));}
        if(preg_match('/\b(\d{1,2})\s+('.$months.')\s+(20\d{2})\b/iu',$text,$m)){$month=self::month_number($m[2]);if($month)return array('start'=>self::build_datetime((int)$m[3],$month,(int)$m[1],self::time_from_text($text)),'end'=>'');}
        if(preg_match('/\b(\d{1,2})[\.\/]([01]?\d)[\.\/](20\d{2})\s*[-–—]\s*(\d{1,2})[\.\/]([01]?\d)[\.\/](20\d{2})\b/u',$text,$m))return array('start'=>self::build_datetime((int)$m[3],(int)$m[2],(int)$m[1],self::time_from_text($text)),'end'=>self::build_datetime((int)$m[6],(int)$m[5],(int)$m[4],''));
        if(preg_match('/\b(\d{1,2})[\.\/]([01]?\d)[\.\/](20\d{2})\b/u',$text,$m))return array('start'=>self::build_datetime((int)$m[3],(int)$m[2],(int)$m[1],self::time_from_text($text)),'end'=>'');
        if(preg_match('/\b(20\d{2})-([01]\d)-([0-3]\d)\b/u',$text,$m))return array('start'=>self::build_datetime((int)$m[1],(int)$m[2],(int)$m[3],self::time_from_text($text)),'end'=>'');
        return array('start'=>'','end'=>'');
    }

    private static function normalize_datetime( $value ) {
        $value=trim(html_entity_decode((string)$value,ENT_QUOTES|ENT_HTML5,'UTF-8'));if(!$value)return '';
        try{$date=new DateTime($value);return $date->format('Y-m-d\TH:i');}catch(Exception $e){return '';}
    }

    private static function month_pattern(){return 'Ocak|Şubat|Subat|Mart|Nisan|Mayıs|Mayis|Haziran|Temmuz|Ağustos|Agustos|Eylül|Eylul|Ekim|Kasım|Kasim|Aralık|Aralik|January|February|March|April|May|June|July|August|September|October|November|December';}

    private static function month_number( $month ) {
        $key=strtolower(remove_accents(trim((string)$month)));$map=array('ocak'=>1,'january'=>1,'subat'=>2,'february'=>2,'mart'=>3,'march'=>3,'nisan'=>4,'april'=>4,'mayis'=>5,'may'=>5,'haziran'=>6,'june'=>6,'temmuz'=>7,'july'=>7,'agustos'=>8,'august'=>8,'eylul'=>9,'september'=>9,'ekim'=>10,'october'=>10,'kasim'=>11,'november'=>11,'aralik'=>12,'december'=>12);return isset($map[$key])?$map[$key]:0;
    }

    private static function build_datetime( $year, $month, $day, $time ) {
        if(!checkdate($month,$day,$year))return '';$time=preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/',$time)?$time:'00:00';return sprintf('%04d-%02d-%02dT%s',$year,$month,$day,$time);
    }

    private static function time_from_text( $text ) {
        if(preg_match('/\b(?:saat|time)?\s*([01]?\d|2[0-3])[:\.]([0-5]\d)\b/iu',$text,$m))return sprintf('%02d:%02d',(int)$m[1],(int)$m[2]);return '';
    }

    private static function extract_event_url( $xpath, $node, $page_url, $page_fallback ) {
        $queries=array('.//h1//a[@href] | .//h2//a[@href] | .//h3//a[@href] | .//h4//a[@href]',".//a[@itemprop='url'][@href]",".//a[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'detail')][@href]",'.//a[@href]');
        foreach($queries as $query){$links=$xpath->query($query,$node);if(!$links)continue;foreach($links as $link){$href=trim((string)$link->getAttribute('href'));if(!$href||self::is_registration_link($link)||0===strpos($href,'#'))continue;$url=self::absolute_url($href,$page_url);if($url)return $url;}}
        return $page_fallback?$page_url:$page_url;
    }

    private static function extract_registration_url( $xpath, $node, $base_url ) {
        $links=$xpath->query('.//a[@href]',$node);if(!$links)return '';
        foreach($links as $link){if(!self::is_registration_link($link))continue;$url=self::absolute_url($link->getAttribute('href'),$base_url);if($url)return $url;}return '';
    }

    private static function is_registration_link( $link ) {
        $haystack=self::clean_text($link->textContent.' '.$link->getAttribute('class').' '.$link->getAttribute('id').' '.$link->getAttribute('href'));$haystack=strtolower(remove_accents($haystack));return(bool)preg_match('/\b(kayit|register|registration|bilet|ticket|basvur|katil|rsvp|rezervasyon)\b/i',$haystack);
    }

    private static function extract_named_value( $xpath, $node, $needles ) {
        foreach($needles as $needle){$needle=strtolower(remove_accents($needle));$query=".//*[@itemprop='".$needle."'] | .//*[@class and contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'".$needle."')] | .//*[@id and contains(translate(@id,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'".$needle."')]";$matches=$xpath->query($query,$node);if(!$matches)continue;foreach($matches as $match){$value=self::clean_text($match->textContent);if($value&&strlen($value)<=600)return $value;}}
        return '';
    }

    private static function extract_labeled_text( $text, $labels ) {
        foreach($labels as $label){if(preg_match('/(?:^|\s)'.preg_quote($label,'/').'\s*[:\-]\s*([^|•]{2,240})/iu',$text,$m))return self::clean_text($m[1]);}return '';
    }

    private static function infer_location_type( $text ) {
        $lower=strtolower(remove_accents($text));return preg_match('/\b(webinar|online|cevrimici|zoom|microsoft teams|google meet|canli yayin)\b/i',$lower)?'online':'physical';
    }

    private static function extract_description( $xpath, $node ) {
        $paragraphs=$xpath->query('.//p',$node);if(!$paragraphs)return '';$parts=array();
        foreach($paragraphs as $paragraph){$text=self::clean_text($paragraph->textContent);if(strlen($text)<20)continue;$parts[]=$text;if(count($parts)>=3)break;}return self::limit_text(implode("\n\n",$parts),1800);
    }

    private static function upsert_candidate( $source_id, $source_url, $item ) {
        $title=self::clean_text(isset($item['title'])?$item['title']:'');$start=isset($item['start_date'])?trim((string)$item['start_date']):'';if(!$title||!$start)return 'skipped';
        $fingerprint=sha1(absint($source_id).'|'.self::normalize_title($title).'|'.$start);
        $existing=get_posts(array('post_type'=>'event_candidate','post_status'=>'any','posts_per_page'=>1,'fields'=>'ids','meta_key'=>'candidate_fingerprint','meta_value'=>$fingerprint,'no_found_rows'=>true));
        $post_id=!empty($existing[0])?absint($existing[0]):0;$postarr=array('post_type'=>'event_candidate','post_status'=>'publish','post_title'=>$title,'post_content'=>isset($item['description'])?wp_kses_post($item['description']):'');
        if($post_id){$postarr['ID']=$post_id;$result=wp_update_post($postarr,true);}else{$result=wp_insert_post($postarr,true);}if(is_wp_error($result))return 'skipped';$post_id=absint($result);
        $current_status=(string)get_post_meta($post_id,'candidate_status',true);
        $meta=array('candidate_fingerprint'=>$fingerprint,'source_id'=>absint($source_id),'source_url'=>esc_url_raw($source_url,array('http','https')),'event_url'=>esc_url_raw(isset($item['event_url'])?$item['event_url']:$source_url,array('http','https')),'start_date'=>$start,'end_date'=>isset($item['end_date'])?trim((string)$item['end_date']):'','location_type'=>isset($item['location_type'])?sanitize_key($item['location_type']):'physical','venue'=>isset($item['venue'])?sanitize_text_field($item['venue']):'','address'=>isset($item['address'])?sanitize_text_field($item['address']):'','organizer'=>isset($item['organizer'])?sanitize_text_field($item['organizer']):'','registration_link'=>esc_url_raw(isset($item['registration_link'])?$item['registration_link']:'',array('http','https')),'parser_type'=>'html');
        foreach($meta as $key=>$value)update_post_meta($post_id,$key,$value);if(!$current_status)update_post_meta($post_id,'candidate_status','new');
        return !empty($existing[0])?'updated':'created';
    }

    private static function record_source_scan( $source_id, $count, $error ) {
        update_post_meta($source_id,'last_candidate_scan_at',current_time('mysql'));update_post_meta($source_id,'last_candidate_count',absint($count));update_post_meta($source_id,'last_candidate_parser','html');update_post_meta($source_id,'last_candidate_error',sanitize_text_field($error));
    }

    private static function html_source_ids() {
        return array_values(array_map('absint',get_posts(array('post_type'=>'event_source','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','orderby'=>'ID','order'=>'ASC','no_found_rows'=>true,'meta_query'=>array('relation'=>'AND',array('key'=>'source_status','value'=>'active'),array('key'=>'check_state','value'=>'ok'),array('key'=>'detected_parser','value'=>'html'))))));
    }

    private static function absolute_url( $url, $base_url ) {
        $url=trim(html_entity_decode((string)$url,ENT_QUOTES|ENT_HTML5,'UTF-8'));$base_url=trim((string)$base_url);if(!$url)return '';
        if(preg_match('#^https?://#i',$url))return esc_url_raw($url,array('http','https'));if(preg_match('#^[a-z][a-z0-9+.-]*:#i',$url))return '';
        $base=wp_parse_url($base_url);if(!is_array($base)||empty($base['scheme'])||empty($base['host']))return '';$scheme=strtolower((string)$base['scheme']);if(!in_array($scheme,array('http','https'),true))return '';
        $origin=$scheme.'://'.$base['host'];if(!empty($base['port']))$origin.=':'.absint($base['port']);if(0===strpos($url,'//'))return esc_url_raw($scheme.':'.$url,array('http','https'));if(0===strpos($url,'/'))return esc_url_raw($origin.self::normalize_path($url),array('http','https'));
        $base_path=isset($base['path'])&&$base['path']?(string)$base['path']:'/';if(0===strpos($url,'?'))return esc_url_raw($origin.$base_path.$url,array('http','https'));if(0===strpos($url,'#')){$query=isset($base['query'])&&$base['query']?'?'.$base['query']:'';return esc_url_raw($origin.$base_path.$query.$url,array('http','https'));}
        $directory='/'===substr($base_path,-1)?$base_path:trailingslashit(dirname($base_path));return esc_url_raw($origin.self::normalize_path($directory.$url),array('http','https'));
    }

    private static function normalize_path( $path ) {
        $fragment='';$query='';$hash_pos=strpos($path,'#');if(false!==$hash_pos){$fragment=substr($path,$hash_pos);$path=substr($path,0,$hash_pos);}$query_pos=strpos($path,'?');if(false!==$query_pos){$query=substr($path,$query_pos);$path=substr($path,0,$query_pos);}$segments=array();foreach(explode('/',$path) as $segment){if(''===$segment||'.'===$segment)continue;if('..'===$segment){array_pop($segments);continue;}$segments[]=$segment;}return '/'.implode('/',$segments).$query.$fragment;
    }

    private static function normalize_title( $title ) {
        $title=strtolower(remove_accents(self::clean_text($title)));$title=preg_replace('/[^a-z0-9]+/i',' ',$title);return trim(preg_replace('/\s+/',' ',$title));
    }

    private static function clean_text( $value ) {
        $value=html_entity_decode((string)$value,ENT_QUOTES|ENT_HTML5,'UTF-8');$value=wp_strip_all_tags($value);$value=preg_replace('/\s+/u',' ',$value);return sanitize_text_field(trim($value));
    }

    private static function limit_text( $value, $max ) {
        $value=self::clean_text($value);return function_exists('mb_substr')?mb_substr($value,0,$max,'UTF-8'):substr($value,0,$max);
    }

    private static function require_ajax(){check_ajax_referer(self::NONCE_ACTION,'nonce');if(!current_user_can('manage_options'))wp_send_json_error(array('message'=>'Yetkisiz işlem.'),403);}
    private static function queue_key($user_id,$token){return 'sektorel_html_'.absint($user_id).'_'.sanitize_key($token);}
}
