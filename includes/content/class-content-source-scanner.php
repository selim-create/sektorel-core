<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manual RSS/XML ingestion for the Content Source Engine.
 *
 * Phase scope:
 * - admin-triggered scans only
 * - deterministic candidate upsert
 * - no AI processing
 * - no draft creation
 * - no automatic publishing
 */
class Sektorel_Content_Source_Scanner {

    const NONCE_ACTION      = 'sektorel_content_source_scan';
    const QUEUE_TTL         = 2 * HOUR_IN_SECONDS;
    const BATCH_SIZE        = 1;
    const TIMEOUT           = 15;
    const MAX_BODY_SIZE     = 2097152; // 2 MB.
    const DEFAULT_MAX_ITEMS = 20;

    public static function init() {
        if ( ! is_admin() ) {
            return;
        }

        add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 20, 2 );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
        add_action( 'admin_post_sektorel_content_scan_source', array( __CLASS__, 'handle_single_scan' ) );
        add_action( 'admin_post_sektorel_content_seed_defaults', array( __CLASS__, 'handle_seed_defaults' ) );
        add_action( 'wp_ajax_sektorel_content_prepare_scans', array( __CLASS__, 'ajax_prepare_scans' ) );
        add_action( 'wp_ajax_sektorel_content_scan_batch', array( __CLASS__, 'ajax_scan_batch' ) );
    }

    public static function row_actions( $actions, $post ) {
        if ( ! $post || 'content_source' !== $post->post_type || ! current_user_can( 'manage_options' ) ) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url( 'admin-post.php?action=sektorel_content_scan_source&source_id=' . absint( $post->ID ) ),
            self::NONCE_ACTION . '_' . absint( $post->ID )
        );
        $actions['sektorel_content_scan'] = '<a href="' . esc_url( $url ) . '">Şimdi Tara</a>';
        return $actions;
    }

    public static function admin_notices() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $scan = isset( $_GET['sektorel_content_scan'] ) ? sanitize_key( wp_unslash( $_GET['sektorel_content_scan'] ) ) : '';
        if ( $scan ) {
            $created   = isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0;
            $existing  = isset( $_GET['existing'] ) ? absint( $_GET['existing'] ) : 0;
            $duplicate = isset( $_GET['duplicates'] ) ? absint( $_GET['duplicates'] ) : 0;
            $message   = 'ok' === $scan
                ? sprintf( 'İçerik kaynağı tarandı. Yeni: %d, mevcut: %d, duplicate: %d.', $created, $existing, $duplicate )
                : 'İçerik kaynağı taranamadı. Kaynak ayarlarındaki Son Hata alanını kontrol edin.';
            $class = 'ok' === $scan ? 'notice notice-success is-dismissible' : 'notice notice-error is-dismissible';
            echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
        }

        $seed = isset( $_GET['sektorel_content_seed'] ) ? sanitize_key( wp_unslash( $_GET['sektorel_content_seed'] ) ) : '';
        if ( 'ok' === $seed ) {
            $created = isset( $_GET['seed_created'] ) ? absint( $_GET['seed_created'] ) : 0;
            $updated = isset( $_GET['seed_updated'] ) ? absint( $_GET['seed_updated'] ) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( 'Resmî başlangıç kaynakları hazırlandı. Yeni: %d, güncellenen: %d.', $created, $updated ) ) . '</p></div>';
        }
    }

    public static function handle_single_scan() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Yetkisiz işlem.', 'sektorel-core' ) );
        }

        $source_id = isset( $_GET['source_id'] ) ? absint( $_GET['source_id'] ) : 0;
        check_admin_referer( self::NONCE_ACTION . '_' . $source_id );

        $result = self::scan_source( $source_id );
        $args   = array(
            'post_type'             => 'content_source',
            'sektorel_content_scan' => is_wp_error( $result ) ? 'error' : 'ok',
        );

        if ( ! is_wp_error( $result ) ) {
            $args['created']    = (int) $result['created'];
            $args['existing']   = (int) $result['existing'];
            $args['duplicates'] = (int) $result['duplicates'];
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
        exit;
    }

    public static function handle_seed_defaults() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Yetkisiz işlem.', 'sektorel-core' ) );
        }

        check_admin_referer( 'sektorel_content_seed_defaults' );
        $result = self::seed_default_sources();

        $args = array(
            'page'                  => 'sektorel-content-source-center',
            'sektorel_content_seed' => is_wp_error( $result ) ? 'error' : 'ok',
        );

        if ( ! is_wp_error( $result ) ) {
            $args['seed_created'] = (int) $result['created'];
            $args['seed_updated'] = (int) $result['updated'];
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
        exit;
    }

    public static function ajax_prepare_scans() {
        self::require_admin_ajax();

        $ids = get_posts( array(
            'post_type'      => 'content_source',
            'post_status'    => array( 'publish', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array(
                    'key'     => 'enabled',
                    'value'   => '1',
                    'compare' => '=',
                ),
            ),
        ) );

        $ids = array_values( array_map( 'absint', $ids ) );
        if ( ! $ids ) {
            wp_send_json_error( array( 'message' => 'Taranacak aktif içerik kaynağı bulunamadı.' ) );
        }

        $token = strtolower( wp_generate_password( 24, false, false ) );
        set_transient( self::queue_key( get_current_user_id(), $token ), $ids, self::QUEUE_TTL );

        wp_send_json_success( array(
            'token' => $token,
            'total' => count( $ids ),
        ) );
    }

    public static function ajax_scan_batch() {
        self::require_admin_ajax();

        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
        if ( ! $token ) {
            wp_send_json_error( array( 'message' => 'Tarama kuyruğu anahtarı eksik.' ) );
        }

        $key = self::queue_key( get_current_user_id(), $token );
        $ids = get_transient( $key );
        if ( ! is_array( $ids ) ) {
            wp_send_json_error( array( 'message' => 'Tarama kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $batch      = array_slice( $ids, $offset, self::BATCH_SIZE );
        $created    = 0;
        $existing   = 0;
        $duplicates = 0;
        $errors     = 0;
        $messages   = array();

        foreach ( $batch as $source_id ) {
            $source_id = absint( $source_id );
            $result    = self::scan_source( $source_id );
            $title     = get_the_title( $source_id ) ?: ( 'Kaynak #' . $source_id );

            if ( is_wp_error( $result ) ) {
                $errors++;
                $messages[] = 'Hata: ' . $title . ' — ' . $result->get_error_message();
                continue;
            }

            $created    += (int) $result['created'];
            $existing   += (int) $result['existing'];
            $duplicates += (int) $result['duplicates'];
            $messages[] = sprintf(
                'OK: %s — %d öğe / %d yeni / %d mevcut / %d duplicate',
                $title,
                (int) $result['parsed'],
                (int) $result['created'],
                (int) $result['existing'],
                (int) $result['duplicates']
            );
        }

        $total       = count( $ids );
        $next_offset = min( $total, $offset + count( $batch ) );
        $done        = $next_offset >= $total;

        if ( $done ) {
            delete_transient( $key );
        } else {
            set_transient( $key, $ids, self::QUEUE_TTL );
        }

        wp_send_json_success( array(
            'created'     => $created,
            'existing'    => $existing,
            'duplicates'  => $duplicates,
            'errors'      => $errors,
            'messages'    => $messages,
            'next_offset' => $next_offset,
            'done'        => $done,
        ) );
    }

    public static function scan_source( $source_id ) {
        $source_id = absint( $source_id );
        if ( ! $source_id || 'content_source' !== get_post_type( $source_id ) ) {
            return new WP_Error( 'invalid_content_source', 'Geçersiz içerik kaynağı.' );
        }

        $source_key  = sanitize_key( (string) get_post_meta( $source_id, 'source_key', true ) );
        $source_type = sanitize_key( (string) get_post_meta( $source_id, 'source_type', true ) );
        $feed_url    = trim( (string) get_post_meta( $source_id, 'feed_url', true ) );
        $base_url    = trim( (string) get_post_meta( $source_id, 'base_url', true ) );
        $adapter     = sanitize_key( (string) get_post_meta( $source_id, 'adapter', true ) );
        $role        = sanitize_key( (string) get_post_meta( $source_id, 'role', true ) );
        $trust       = sanitize_key( (string) get_post_meta( $source_id, 'trust_level', true ) );
        $categories  = self::category_slugs( get_post_meta( $source_id, 'category_slugs', true ) );
        $max_items   = max( 1, min( 200, absint( get_post_meta( $source_id, 'max_items_per_scan', true ) ?: self::DEFAULT_MAX_ITEMS ) ) );

        update_post_meta( $source_id, 'last_scan', current_time( 'mysql' ) );

        if ( ! $source_key ) {
            return self::source_error( $source_id, 'Kaynak key alanı eksik.', 'missing_source_key' );
        }
        if ( 'rss' !== $source_type ) {
            return self::source_error( $source_id, 'Bu fazda yalnız RSS / XML kaynak tipi taranabilir.', 'unsupported_source_type' );
        }
        if ( ! $feed_url ) {
            return self::source_error( $source_id, 'Feed / Endpoint URL eksik.', 'missing_feed_url' );
        }
        if ( ! self::is_safe_public_url( $feed_url ) ) {
            return self::source_error( $source_id, 'Güvenli olmayan veya private ağ feed hedefi.', 'unsafe_feed_url' );
        }

        $response = wp_safe_remote_get( $feed_url, array(
            'timeout'             => self::TIMEOUT,
            'redirection'         => 3,
            'limit_response_size' => self::MAX_BODY_SIZE,
            'user-agent'          => 'SektorelAjandaContentBot/1.0; +' . home_url( '/' ),
            'headers'             => array(
                'Accept' => 'application/rss+xml,application/atom+xml,application/xml,text/xml;q=0.9,*/*;q=0.3',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return self::source_error( $source_id, $response->get_error_message(), 'fetch_failed' );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = (string) wp_remote_retrieve_body( $response );
        update_post_meta( $source_id, 'last_http_status', $status );

        if ( $status < 200 || $status >= 400 ) {
            return self::source_error( $source_id, 'Feed HTTP ' . $status . ' döndürdü.', 'http_error' );
        }
        if ( '' === trim( $body ) ) {
            return self::source_error( $source_id, 'Feed boş yanıt döndürdü.', 'empty_feed' );
        }

        $items = self::parse_feed( $body, $feed_url, $base_url, $max_items );
        if ( is_wp_error( $items ) ) {
            return self::source_error( $source_id, $items->get_error_message(), $items->get_error_code() );
        }

        $created     = 0;
        $existing    = 0;
        $duplicates  = 0;
        $item_errors = 0;
        $fetched_at  = gmdate( 'c' );

        foreach ( $items as $item ) {
            $result = Sektorel_Content_Candidates::upsert(
                $source_id,
                $source_key,
                $item['source_item_key'],
                array(
                    'source_url'         => $item['url'],
                    'canonical_url'      => $item['url'],
                    'title'              => $item['title'],
                    'published_at'       => $item['published_at'],
                    'extracted_text'     => $item['summary'],
                    'normalized_payload' => array(
                        'title'          => $item['title'],
                        'url'            => $item['url'],
                        'published_at'   => $item['published_at'],
                        'summary'        => $item['summary'],
                        'category_slugs' => $categories,
                        'language'       => (string) get_post_meta( $source_id, 'language', true ),
                    ),
                    'raw_payload'        => $item['raw_payload'],
                    'evidence'           => array(
                        'feed_url'    => $feed_url,
                        'adapter'     => $adapter ?: 'rss_generic',
                        'role'        => $role ?: 'official',
                        'trust_level' => $trust ?: 'medium',
                        'fetched_at'  => $fetched_at,
                    ),
                )
            );

            if ( is_wp_error( $result ) ) {
                $item_errors++;
                continue;
            }

            if ( ! empty( $result['created'] ) ) {
                $created++;
                if ( ! empty( $result['duplicate_candidate_id'] ) ) {
                    $duplicates++;
                }
            } else {
                $existing++;
            }
        }

        $summary = array(
            'parsed'      => count( $items ),
            'created'     => $created,
            'existing'    => $existing,
            'duplicates'  => $duplicates,
            'item_errors' => $item_errors,
            'http_status' => $status,
        );

        update_post_meta( $source_id, 'last_success', current_time( 'mysql' ) );
        update_post_meta( $source_id, 'last_error', '' );
        update_post_meta( $source_id, 'last_error_code', '' );
        update_post_meta( $source_id, 'last_scan_result', wp_json_encode( $summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
        update_post_meta( $source_id, 'last_scan_item_count', count( $items ) );
        update_post_meta( $source_id, 'last_scan_created', $created );
        update_post_meta( $source_id, 'last_scan_existing', $existing );
        update_post_meta( $source_id, 'last_scan_duplicates', $duplicates );
        update_post_meta( $source_id, 'last_scan_item_errors', $item_errors );

        return $summary;
    }

    public static function seed_default_sources() {
        if ( ! post_type_exists( 'content_source' ) ) {
            return new WP_Error( 'content_source_type_unavailable', 'İçerik kaynağı post type henüz kayıtlı değil.' );
        }

        $definitions = self::default_sources();
        $created     = 0;
        $updated     = 0;

        foreach ( $definitions as $definition ) {
            $existing = get_posts( array(
                'post_type'      => 'content_source',
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array(
                        'key'     => 'source_key',
                        'value'   => $definition['source_key'],
                        'compare' => '=',
                    ),
                ),
            ) );

            $source_id = $existing ? absint( $existing[0] ) : 0;
            $is_new    = ! $source_id;
            if ( $is_new ) {
                $source_id = wp_insert_post( array(
                    'post_type'   => 'content_source',
                    'post_status' => 'publish',
                    'post_title'  => $definition['title'],
                ), true );
                if ( is_wp_error( $source_id ) ) {
                    continue;
                }
                $created++;
            }

            $changed = false;
            foreach ( $definition['meta'] as $key => $value ) {
                if ( '' !== (string) get_post_meta( $source_id, $key, true ) ) {
                    continue;
                }
                update_post_meta( $source_id, $key, $value );
                $changed = true;
            }

            if ( '' === (string) get_post_meta( $source_id, '_sektorel_content_seed', true ) ) {
                update_post_meta( $source_id, '_sektorel_content_seed', 'official_v1' );
                $changed = true;
            }

            if ( ! $is_new && $changed ) {
                $updated++;
            }
        }

        return array( 'created' => $created, 'updated' => $updated );
    }

    public static function nonce() {
        return wp_create_nonce( self::NONCE_ACTION );
    }

    public static function seed_url() {
        return wp_nonce_url(
            admin_url( 'admin-post.php?action=sektorel_content_seed_defaults' ),
            'sektorel_content_seed_defaults'
        );
    }

    private static function default_sources() {
        return array(
            array(
                'source_key' => 'tcmb_press',
                'title'      => 'TCMB — Basın Duyuruları',
                'meta'       => array(
                    'source_key'         => 'tcmb_press',
                    'base_url'           => 'https://www.tcmb.gov.tr/',
                    'feed_url'           => 'https://www.tcmb.gov.tr/wps/wcm/connect/TR/TCMB%2BTR/Bottom%2BMenu/Diger/RSS/Basin%2BDuyurulari',
                    'source_type'        => 'rss',
                    'adapter'            => 'tcmb_rss',
                    'role'               => 'official',
                    'trust_level'        => 'high',
                    'language'           => 'tr',
                    'category_slugs'     => 'ekonomi-piyasalar,finans-bankacilik',
                    'scan_interval'      => 'manual',
                    'enabled'            => '1',
                    'ai_enabled'         => '0',
                    'max_items_per_scan' => '25',
                    'max_ai_items_daily' => '10',
                ),
            ),
            array(
                'source_key' => 'tobb_news',
                'title'      => 'TOBB — Haberler',
                'meta'       => array(
                    'source_key'         => 'tobb_news',
                    'base_url'           => 'https://www.tobb.org.tr/',
                    'feed_url'           => 'https://www.tobb.org.tr/Sayfalar/RssFeeder.php?List=Haberler',
                    'source_type'        => 'rss',
                    'adapter'            => 'rss_generic',
                    'role'               => 'official',
                    'trust_level'        => 'high',
                    'language'           => 'tr',
                    'category_slugs'     => 'sirketler-yatirimlar,kobi-girisimcilik,sanayi-uretim',
                    'scan_interval'      => 'manual',
                    'enabled'            => '1',
                    'ai_enabled'         => '0',
                    'max_items_per_scan' => '25',
                    'max_ai_items_daily' => '10',
                ),
            ),
            array(
                'source_key' => 'tobb_announcements',
                'title'      => 'TOBB — Duyurular',
                'meta'       => array(
                    'source_key'         => 'tobb_announcements',
                    'base_url'           => 'https://www.tobb.org.tr/',
                    'feed_url'           => 'https://www.tobb.org.tr/Sayfalar/RssFeeder.php?List=DuyurularListesi',
                    'source_type'        => 'rss',
                    'adapter'            => 'rss_generic',
                    'role'               => 'official',
                    'trust_level'        => 'high',
                    'language'           => 'tr',
                    'category_slugs'     => 'mevzuat-tesvikler,kobi-girisimcilik,dis-ticaret-ihracat',
                    'scan_interval'      => 'manual',
                    'enabled'            => '1',
                    'ai_enabled'         => '0',
                    'max_items_per_scan' => '25',
                    'max_ai_items_daily' => '10',
                ),
            ),
        );
    }

    private static function parse_feed( $body, $feed_url, $base_url, $limit ) {
        if ( ! function_exists( 'simplexml_load_string' ) ) {
            return new WP_Error( 'simplexml_unavailable', 'Sunucuda SimpleXML eklentisi bulunamadı.' );
        }

        $previous = libxml_use_internal_errors( true );
        $xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
        $errors   = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( false === $xml ) {
            $detail = $errors ? trim( (string) $errors[0]->message ) : 'XML ayrıştırılamadı.';
            return new WP_Error( 'invalid_rss_xml', 'RSS/XML ayrıştırılamadı: ' . $detail );
        }

        $items = array();
        if ( isset( $xml->channel->item ) ) {
            foreach ( $xml->channel->item as $node ) {
                $items[] = self::parse_rss_item( $node, $feed_url, $base_url );
                if ( count( $items ) >= $limit ) {
                    break;
                }
            }
        } elseif ( isset( $xml->entry ) ) {
            foreach ( $xml->entry as $node ) {
                $items[] = self::parse_atom_entry( $node, $feed_url, $base_url );
                if ( count( $items ) >= $limit ) {
                    break;
                }
            }
        }

        $items = array_values( array_filter( $items, static function( $item ) {
            return is_array( $item ) && ( ! empty( $item['title'] ) || ! empty( $item['url'] ) );
        } ) );

        if ( ! $items ) {
            return new WP_Error( 'rss_has_no_items', 'Feed içinde okunabilir RSS/Atom öğesi bulunamadı.' );
        }

        return $items;
    }

    private static function parse_rss_item( SimpleXMLElement $node, $feed_url, $base_url ) {
        $title       = self::clean_text( (string) $node->title, 1000 );
        $link        = self::resolve_url( trim( (string) $node->link ), $base_url ?: $feed_url );
        $guid        = trim( (string) $node->guid );
        $published   = trim( (string) $node->pubDate );
        $description = (string) $node->description;

        $namespaces = $node->getNameSpaces( true );
        if ( isset( $namespaces['content'] ) ) {
            $content = $node->children( $namespaces['content'] );
            if ( isset( $content->encoded ) && trim( (string) $content->encoded ) ) {
                $description = (string) $content->encoded;
            }
        }
        if ( ! $published && isset( $namespaces['dc'] ) ) {
            $dc = $node->children( $namespaces['dc'] );
            if ( isset( $dc->date ) ) {
                $published = trim( (string) $dc->date );
            }
        }

        $published_at = self::normalize_feed_date( $published );
        $summary      = self::clean_text( $description, 20000 );

        return array(
            'source_item_key' => self::item_key( $guid, $link, $title, $published_at ),
            'title'           => $title,
            'url'             => $link,
            'published_at'    => $published_at,
            'summary'         => $summary,
            'raw_payload'     => array(
                'guid'        => self::clean_text( $guid, 2000 ),
                'title'       => $title,
                'link'        => $link,
                'published'   => $published,
                'description' => self::clean_text( $description, 20000 ),
            ),
        );
    }

    private static function parse_atom_entry( SimpleXMLElement $node, $feed_url, $base_url ) {
        $title = self::clean_text( (string) $node->title, 1000 );
        $guid  = trim( (string) $node->id );
        $link  = '';

        foreach ( $node->link as $link_node ) {
            $href = trim( (string) $link_node['href'] );
            $rel  = trim( (string) $link_node['rel'] );
            if ( $href && ( ! $rel || 'alternate' === $rel ) ) {
                $link = $href;
                break;
            }
        }
        $link = self::resolve_url( $link, $base_url ?: $feed_url );

        $published = trim( (string) $node->published );
        if ( ! $published ) {
            $published = trim( (string) $node->updated );
        }
        $summary = (string) $node->content;
        if ( ! trim( $summary ) ) {
            $summary = (string) $node->summary;
        }

        $published_at = self::normalize_feed_date( $published );
        $summary      = self::clean_text( $summary, 20000 );

        return array(
            'source_item_key' => self::item_key( $guid, $link, $title, $published_at ),
            'title'           => $title,
            'url'             => $link,
            'published_at'    => $published_at,
            'summary'         => $summary,
            'raw_payload'     => array(
                'id'        => self::clean_text( $guid, 2000 ),
                'title'     => $title,
                'link'      => $link,
                'published' => $published,
                'summary'   => $summary,
            ),
        );
    }

    private static function item_key( $guid, $url, $title, $published_at ) {
        $guid = trim( (string) $guid );
        if ( $guid ) {
            return mb_substr( $guid, 0, 191 );
        }
        if ( $url ) {
            return mb_substr( $url, 0, 191 );
        }
        return hash( 'sha256', $title . '|' . (string) $published_at );
    }

    private static function normalize_feed_date( $value ) {
        $value = trim( (string) $value );
        if ( ! $value ) {
            return null;
        }

        $timestamp = strtotime( $value );
        return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
    }

    private static function resolve_url( $url, $base_url ) {
        $url = trim( html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( ! $url ) {
            return '';
        }

        $parts = wp_parse_url( $url );
        if ( is_array( $parts ) && ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
            return in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ? esc_url_raw( $url ) : '';
        }

        $base = wp_parse_url( $base_url );
        if ( ! is_array( $base ) || empty( $base['host'] ) ) {
            return '';
        }

        $scheme = ! empty( $base['scheme'] ) ? strtolower( $base['scheme'] ) : 'https';
        $origin = $scheme . '://' . $base['host'];
        if ( 0 === strpos( $url, '//' ) ) {
            return esc_url_raw( $scheme . ':' . $url );
        }
        if ( '/' === substr( $url, 0, 1 ) ) {
            return esc_url_raw( $origin . $url );
        }

        $base_path = isset( $base['path'] ) ? trailingslashit( dirname( $base['path'] ) ) : '/';
        return esc_url_raw( $origin . $base_path . ltrim( $url, '/' ) );
    }

    private static function clean_text( $value, $max_length = 20000 ) {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = trim( preg_replace( '/\s+/u', ' ', $value ) );
        return mb_substr( $value, 0, max( 1, absint( $max_length ) ) );
    }

    private static function category_slugs( $value ) {
        return array_values( array_filter( array_map( 'sanitize_title', array_map( 'trim', explode( ',', (string) $value ) ) ) ) );
    }

    private static function source_error( $source_id, $message, $code ) {
        update_post_meta( $source_id, 'last_error', sanitize_text_field( $message ) );
        update_post_meta( $source_id, 'last_error_code', sanitize_key( $code ) );
        return new WP_Error( sanitize_key( $code ), $message );
    }

    private static function is_safe_public_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }
        if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
            return false;
        }

        $host = strtolower( rtrim( $parts['host'], '.' ) );
        if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) || preg_match( '/\.local$/i', $host ) ) {
            return false;
        }
        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return self::is_public_ip( $host );
        }

        $ips = gethostbynamel( $host );
        if ( ! is_array( $ips ) || ! $ips ) {
            return false;
        }
        foreach ( $ips as $ip ) {
            if ( ! self::is_public_ip( $ip ) ) {
                return false;
            }
        }
        return true;
    }

    private static function is_public_ip( $ip ) {
        return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
    }

    private static function require_admin_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_content_scan_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }
}
