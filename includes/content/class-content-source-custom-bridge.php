<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-content-source-custom-adapters.php';
require_once __DIR__ . '/class-content-source-iso-adapter.php';
require_once __DIR__ . '/class-content-source-btk-adapter.php';
require_once __DIR__ . '/class-content-source-tbb-finance-adapter.php';

/**
 * Adapter-aware bridge for the existing Content Source Scanner admin actions.
 *
 * RSS sources continue to use Sektorel_Content_Source_Scanner::scan_source()
 * unchanged. Only explicitly supported custom adapters are handled here.
 */
class Sektorel_Content_Source_Custom_Bridge {

    public static function init() {
        if ( ! is_admin() || ! class_exists( 'Sektorel_Content_Source_Scanner' ) ) {
            return;
        }

        remove_action( 'admin_post_sektorel_content_scan_source', array( 'Sektorel_Content_Source_Scanner', 'handle_single_scan' ) );
        add_action( 'admin_post_sektorel_content_scan_source', array( __CLASS__, 'handle_single_scan' ) );

        remove_action( 'admin_post_sektorel_content_seed_defaults', array( 'Sektorel_Content_Source_Scanner', 'handle_seed_defaults' ) );
        add_action( 'admin_post_sektorel_content_seed_defaults', array( __CLASS__, 'handle_seed_defaults' ) );

        remove_action( 'wp_ajax_sektorel_content_scan_batch', array( 'Sektorel_Content_Source_Scanner', 'ajax_scan_batch' ) );
        add_action( 'wp_ajax_sektorel_content_scan_batch', array( __CLASS__, 'ajax_scan_batch' ) );
    }

    public static function handle_single_scan() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Yetkisiz işlem.', 'sektorel-core' ) );
        }

        $source_id = isset( $_GET['source_id'] ) ? absint( $_GET['source_id'] ) : 0;
        check_admin_referer( Sektorel_Content_Source_Scanner::NONCE_ACTION . '_' . $source_id );
        $result = self::scan_source( $source_id );

        $args = array(
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

        $legacy = Sektorel_Content_Source_Scanner::seed_default_sources();
        $custom = self::seed_sources();

        if ( is_wp_error( $legacy ) || is_wp_error( $custom ) ) {
            $args = array(
                'page'                  => 'sektorel-content-source-center',
                'sektorel_content_seed' => 'error',
            );
        } else {
            $args = array(
                'page'                  => 'sektorel-content-source-center',
                'sektorel_content_seed' => 'ok',
                'seed_created'          => (int) $legacy['created'] + (int) $custom['created'],
                'seed_updated'          => (int) $legacy['updated'] + (int) $custom['updated'],
            );
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
        exit;
    }

    public static function ajax_scan_batch() {
        self::require_admin_ajax();

        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
        $key    = self::queue_key( get_current_user_id(), $token );
        $ids    = $token ? get_transient( $key ) : false;

        if ( ! is_array( $ids ) ) {
            wp_send_json_error( array( 'message' => 'Tarama kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $totals   = array( 'created' => 0, 'existing' => 0, 'duplicates' => 0, 'errors' => 0 );
        $messages = array();
        $batch    = array_slice( $ids, $offset, Sektorel_Content_Source_Scanner::BATCH_SIZE );

        foreach ( $batch as $source_id ) {
            $source_id = absint( $source_id );
            $title     = get_the_title( $source_id ) ?: 'Kaynak #' . $source_id;
            $result    = self::scan_source( $source_id );

            if ( is_wp_error( $result ) ) {
                $totals['errors']++;
                $messages[] = 'Hata: ' . $title . ' — ' . $result->get_error_message();
                continue;
            }

            foreach ( array( 'created', 'existing', 'duplicates' ) as $key_name ) {
                $totals[ $key_name ] += (int) $result[ $key_name ];
            }
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
        }

        wp_send_json_success( array_merge( $totals, array(
            'messages'    => $messages,
            'next_offset' => $next_offset,
            'done'        => $done,
        ) ) );
    }

    public static function scan_source( $source_id ) {
        $source_id   = absint( $source_id );
        $source_type = sanitize_key( (string) get_post_meta( $source_id, 'source_type', true ) );
        $adapter     = sanitize_key( (string) get_post_meta( $source_id, 'adapter', true ) );

        $generic_supported = class_exists( 'Sektorel_Content_Source_Custom_Adapters' ) &&
            Sektorel_Content_Source_Custom_Adapters::supported( $adapter );
        $iso_supported = 'iso_news_html' === $adapter && class_exists( 'Sektorel_Content_Source_ISO_Adapter' );
        $btk_supported = 'btk_news_html' === $adapter && class_exists( 'Sektorel_Content_Source_BTK_Adapter' );
        $tbb_supported = 'tbb_finance_news_html' === $adapter && class_exists( 'Sektorel_Content_Source_TBB_Finance_Adapter' );

        if ( 'custom' !== $source_type || ( ! $generic_supported && ! $iso_supported && ! $btk_supported && ! $tbb_supported ) ) {
            return Sektorel_Content_Source_Scanner::scan_source( $source_id );
        }

        return self::scan_custom_source( $source_id, $adapter );
    }

    public static function seed_sources() {
        $created = 0;
        $updated = 0;

        foreach ( self::source_definitions() as $definition ) {
            $ids = get_posts( array(
                'post_type'      => 'content_source',
                'post_status'    => array( 'publish', 'draft', 'private' ),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array( 'key' => 'source_key', 'value' => $definition['source_key'], 'compare' => '=' ),
                ),
            ) );

            $source_id = $ids ? absint( $ids[0] ) : 0;
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
            if ( ! $is_new && $changed ) {
                $updated++;
            }
        }

        return array( 'created' => $created, 'updated' => $updated );
    }

    private static function scan_custom_source( $source_id, $adapter ) {
        if ( ! $source_id || 'content_source' !== get_post_type( $source_id ) ) {
            return new WP_Error( 'invalid_content_source', 'Geçersiz içerik kaynağı.' );
        }

        $source_key = sanitize_key( (string) get_post_meta( $source_id, 'source_key', true ) );
        $max_items  = max( 1, min( 50, absint( get_post_meta( $source_id, 'max_items_per_scan', true ) ?: 20 ) ) );
        update_post_meta( $source_id, 'last_scan', current_time( 'mysql' ) );

        if ( ! $source_key ) {
            return self::source_error( $source_id, 'Kaynak key alanı eksik.', 'missing_source_key' );
        }

        if ( 'iso_news_html' === $adapter ) {
            $fetched = Sektorel_Content_Source_ISO_Adapter::fetch_items( $source_id, $max_items );
        } elseif ( 'btk_news_html' === $adapter ) {
            $fetched = Sektorel_Content_Source_BTK_Adapter::fetch_items( $source_id, $max_items );
        } elseif ( 'tbb_finance_news_html' === $adapter ) {
            $fetched = Sektorel_Content_Source_TBB_Finance_Adapter::fetch_items( $source_id, $max_items );
        } else {
            $fetched = Sektorel_Content_Source_Custom_Adapters::fetch_items( $adapter, $source_id, $max_items );
        }

        if ( is_wp_error( $fetched ) ) {
            return self::source_error( $source_id, $fetched->get_error_message(), $fetched->get_error_code() );
        }

        $items       = isset( $fetched['items'] ) && is_array( $fetched['items'] ) ? $fetched['items'] : array();
        $http_status = absint( $fetched['http_status'] ?? 0 );
        $fetch_url   = esc_url_raw( $fetched['fetch_url'] ?? get_post_meta( $source_id, 'feed_url', true ) );
        update_post_meta( $source_id, 'last_http_status', $http_status );

        if ( ! $items ) {
            return self::source_error( $source_id, 'Custom adapter okunabilir kayıt üretmedi.', 'custom_source_no_items' );
        }

        $created = $existing = $duplicates = $item_errors = 0;
        $categories = self::category_slugs( get_post_meta( $source_id, 'category_slugs', true ) );
        $role       = sanitize_key( (string) get_post_meta( $source_id, 'role', true ) ) ?: 'official';
        $trust      = sanitize_key( (string) get_post_meta( $source_id, 'trust_level', true ) ) ?: 'high';
        $language   = sanitize_key( (string) get_post_meta( $source_id, 'language', true ) ) ?: 'tr';
        $fetched_at = gmdate( 'c' );

        foreach ( $items as $item ) {
            $item_key = mb_substr( (string) ( $item['source_item_key'] ?? '' ), 0, 191 );
            if ( ! $item_key ) {
                $item_errors++;
                continue;
            }

            $already_exists = self::candidate_exists( $source_key, $item_key );
            $result = Sektorel_Content_Candidates::upsert( $source_id, $source_key, $item_key, array(
                'source_url'         => esc_url_raw( $item['url'] ?? '' ),
                'canonical_url'      => esc_url_raw( $item['url'] ?? '' ),
                'title'              => sanitize_text_field( $item['title'] ?? '' ),
                'published_at'       => $item['published_at'] ?? null,
                'extracted_text'     => (string) ( $item['summary'] ?? '' ),
                'normalized_payload' => array(
                    'title'          => sanitize_text_field( $item['title'] ?? '' ),
                    'url'            => esc_url_raw( $item['url'] ?? '' ),
                    'published_at'   => $item['published_at'] ?? null,
                    'summary'        => (string) ( $item['summary'] ?? '' ),
                    'category_slugs' => $categories,
                    'language'       => $language,
                ),
                'raw_payload' => is_array( $item['raw_payload'] ?? null ) ? $item['raw_payload'] : array(),
                'evidence'    => array(
                    'feed_url'        => $fetch_url,
                    'adapter'         => sanitize_key( $adapter ),
                    'role'            => $role,
                    'trust_level'     => $trust,
                    'fetched_at'      => $fetched_at,
                    'date_source'     => sanitize_key( $item['date_source'] ?? '' ),
                    'source_strategy' => 'custom_html',
                ),
            ) );

            if ( is_wp_error( $result ) ) {
                $item_errors++;
                continue;
            }

            if ( $already_exists ) {
                $existing++;
            } else {
                $created++;
                if ( ! empty( $result['duplicate_candidate_id'] ) ) {
                    $duplicates++;
                }
            }
        }

        $summary = array(
            'parsed'      => count( $items ),
            'created'     => $created,
            'existing'    => $existing,
            'duplicates'  => $duplicates,
            'item_errors' => $item_errors,
            'http_status' => $http_status,
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

    private static function source_definitions() {
        return array(
            array(
                'source_key' => 'kosgeb_news',
                'title'      => 'KOSGEB — Haberler',
                'meta'       => array(
                    'source_key'         => 'kosgeb_news',
                    'base_url'           => 'https://www.kosgeb.gov.tr/',
                    'feed_url'           => 'https://www.kosgeb.gov.tr/site/tr/genel/liste/4/haber?Page=1',
                    'source_type'        => 'custom',
                    'adapter'            => 'kosgeb_news_html',
                    'role'               => 'official',
                    'trust_level'        => 'high',
                    'language'           => 'tr',
                    'category_slugs'     => 'kobi-girisimcilik',
                    'scan_interval'      => 'manual',
                    'enabled'            => '1',
                    'ai_enabled'         => '0',
                    'max_items_per_scan' => '20',
                    'max_ai_items_daily' => '0',
                ),
            ),
            array(
                'source_key' => 'sanayi_news',
                'title'      => 'Sanayi ve Teknoloji Bakanlığı — Haberler',
                'meta'       => array(
                    'source_key'         => 'sanayi_news',
                    'base_url'           => 'https://www.sanayi.gov.tr/',
                    'feed_url'           => 'https://www.sanayi.gov.tr/medya/haberler',
                    'source_type'        => 'custom',
                    'adapter'            => 'sanayi_news_html',
                    'role'               => 'official',
                    'trust_level'        => 'high',
                    'language'           => 'tr',
                    'category_slugs'     => 'sanayi-uretim',
                    'scan_interval'      => 'manual',
                    'enabled'            => '1',
                    'ai_enabled'         => '0',
                    'max_items_per_scan' => '20',
                    'max_ai_items_daily' => '0',
                ),
            ),
            array(
                'source_key' => 'iso_news',
                'title'      => 'İstanbul Sanayi Odası — Haberler',
                'meta'       => array(
                    'source_key'         => 'iso_news',
                    'base_url'           => 'https://www.iso.org.tr/',
                    'feed_url'           => 'https://www.iso.org.tr/haberler',
                    'source_type'        => 'custom',
                    'adapter'            => 'iso_news_html',
                    'role'               => 'official',
                    'trust_level'        => 'high',
                    'language'           => 'tr',
                    'category_slugs'     => 'sanayi-uretim',
                    'scan_interval'      => 'manual',
                    'enabled'            => '1',
                    'ai_enabled'         => '0',
                    'max_items_per_scan' => '20',
                    'max_ai_items_daily' => '0',
                    'primary_desk'       => 'sanayi-uretim',
                    'topic_scope'        => 'imalat-sanayi,uretim,pmi',
                    'sector_scope'       => 'multi-sector',
                    'geography'          => 'tr-national',
                    'source_tier'        => 'b',
                    'detail_strategy'    => 'detail_page_required',
                    'image_policy'       => 'source_preferred',
                ),
            ),
            array(
                'source_key' => 'btk_news',
                'title'      => 'BTK — Haberler',
                'meta'       => array(
                    'source_key'         => 'btk_news',
                    'base_url'           => 'https://www.btk.gov.tr/',
                    'feed_url'           => 'https://www.btk.gov.tr/haberler',
                    'source_type'        => 'custom',
                    'adapter'            => 'btk_news_html',
                    'role'               => 'official',
                    'trust_level'        => 'high',
                    'language'           => 'tr',
                    'category_slugs'     => 'teknoloji-dijital-donusum',
                    'scan_interval'      => 'manual',
                    'enabled'            => '1',
                    'ai_enabled'         => '0',
                    'max_items_per_scan' => '20',
                    'max_ai_items_daily' => '0',
                    'primary_desk'       => 'teknoloji-dijital-donusum',
                    'topic_scope'        => 'dijital-donusum,yapay-zeka,5g,siber-guvenlik,dijital-yonetisim',
                    'sector_scope'       => 'bilgi-iletisim-teknolojileri',
                    'geography'          => 'tr-national',
                    'source_tier'        => 'a',
                    'detail_strategy'    => 'detail_page_required',
                    'image_policy'       => 'source_preferred',
                ),
            ),
            array(
                'source_key' => 'tbb_finance_news',
                'title'      => 'Türkiye Bankalar Birliği — Finansman Haberleri',
                'meta'       => array(
                    'source_key'         => 'tbb_finance_news',
                    'base_url'           => 'https://www.tbb.org.tr/',
                    'feed_url'           => 'https://www.tbb.org.tr/haberler',
                    'source_type'        => 'custom',
                    'adapter'            => 'tbb_finance_news_html',
                    'role'               => 'trusted_industry',
                    'trust_level'        => 'high',
                    'language'           => 'tr',
                    'category_slugs'     => 'finans-bankacilik',
                    'scan_interval'      => 'manual',
                    'enabled'            => '1',
                    'ai_enabled'         => '0',
                    'max_items_per_scan' => '20',
                    'max_ai_items_daily' => '0',
                    'primary_desk'       => 'finans-bankacilik',
                    'topic_scope'        => 'finansman,kredi,iklim-finansmani,surdurulebilir-finans',
                    'sector_scope'       => 'bankacilik-finans',
                    'geography'          => 'tr-national',
                    'source_tier'        => 'b',
                    'detail_strategy'    => 'detail_page_preferred',
                    'image_policy'       => 'source_preferred',
                ),
            ),
        );
    }

    private static function candidate_exists( $source_key, $source_item_key ) {
        global $wpdb;
        if ( ! $source_item_key ) {
            return false;
        }

        return (bool) $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM ' . Sektorel_Content_Candidates::table_name() . ' WHERE source_key = %s AND source_item_key = %s LIMIT 1',
            $source_key,
            $source_item_key
        ) );
    }

    private static function category_slugs( $value ) {
        return array_values( array_filter( array_map( 'sanitize_title', array_map( 'trim', explode( ',', (string) $value ) ) ) ) );
    }

    private static function source_error( $source_id, $message, $code ) {
        update_post_meta( $source_id, 'last_error', sanitize_text_field( $message ) );
        update_post_meta( $source_id, 'last_error_code', sanitize_key( $code ) );
        return new WP_Error( sanitize_key( $code ), $message );
    }

    private static function require_admin_ajax() {
        check_ajax_referer( Sektorel_Content_Source_Scanner::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_content_scan_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }
}

if ( is_admin() ) {
    add_action( 'admin_init', array( 'Sektorel_Content_Source_Custom_Bridge', 'init' ), 100 );
}
