<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic adapter for the GIB "Son eklenen mevzuat" first-party JSON API.
 *
 * The public Next.js portal loads its latest legislation stream from:
 * https://gib.gov.tr/api/gibportal/search/mevzuatLastAdded
 *
 * The adapter intentionally keeps a narrow normative gate. Case-specific special
 * rulings (OZELGE), article/search entities and unknown entity types are excluded.
 * Only explicit API dates and canonical GIB detail URLs are accepted.
 */
class Sektorel_Content_Source_GIB_Mevzuat_Adapter {

    const TIMEOUT       = 20;
    const MAX_BODY_SIZE = 1048576; // 1 MB.

    public static function fetch_items( $source_id, $limit ) {
        $source_id = absint( $source_id );
        $limit     = max( 1, min( 20, absint( $limit ) ) );
        $url       = trim( (string) get_post_meta( $source_id, 'feed_url', true ) );

        if ( ! $url ) {
            return new WP_Error( 'missing_listing_url', 'GİB mevzuat API URL alanı eksik.' );
        }
        if ( ! self::is_feed_url( $url ) ) {
            return new WP_Error( 'unsafe_listing_url', 'GİB mevzuat API URL resmi host/path allowlist ile eşleşmiyor.' );
        }

        $response = wp_safe_remote_get( $url, self::request_args() );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'custom_source_fetch_failed', $response->get_error_message() );
        }

        $status = absint( wp_remote_retrieve_response_code( $response ) );
        $body   = (string) wp_remote_retrieve_body( $response );

        if ( 200 !== $status ) {
            return new WP_Error( 'custom_source_http_error', 'GİB mevzuat API HTTP ' . $status . ' döndürdü.' );
        }
        if ( '' === trim( $body ) ) {
            return new WP_Error( 'custom_source_empty', 'GİB mevzuat API boş yanıt döndürdü.' );
        }

        $payload = json_decode( $body, true );
        if ( ! is_array( $payload ) ) {
            return new WP_Error( 'invalid_source_json', 'GİB mevzuat API geçerli JSON döndürmedi.' );
        }

        if ( 200 !== absint( $payload['status'] ?? 0 ) ) {
            return new WP_Error(
                'unexpected_source_status',
                'GİB mevzuat API beklenmeyen uygulama durumu döndürdü.'
            );
        }

        $container = $payload['resultContainer'] ?? null;
        $results   = is_array( $container ) && isset( $container['results'] ) && is_array( $container['results'] )
            ? $container['results']
            : array();

        if ( ! $results ) {
            return new WP_Error( 'custom_source_no_items', 'GİB mevzuat API son eklenen kayıt üretmedi.' );
        }

        $items = self::parse_results( $results, $url, $limit );
        if ( is_wp_error( $items ) ) {
            return $items;
        }

        return array(
            'items'       => $items,
            'http_status' => $status,
            'fetch_url'   => $url,
        );
    }

    private static function parse_results( $results, $feed_url, $limit ) {
        $current_year = (int) current_time( 'Y' );
        $items        = array();
        $seen         = array();

        foreach ( $results as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $entity_type = strtoupper( sanitize_key( (string) ( $row['entityType'] ?? '' ) ) );
            if ( ! self::is_normative_type( $entity_type ) ) {
                continue;
            }

            $title = self::clean_text( $row['text'] ?? '', 1500 );
            if ( mb_strlen( $title, 'UTF-8' ) < 8 || self::looks_mojibaked( $title ) ) {
                continue;
            }

            $url = self::canonical_url( $row['url'] ?? '' );
            if ( ! $url ) {
                continue;
            }

            $identity = self::identity_from_url( $entity_type, $url );
            if ( ! $identity || isset( $seen[ $identity ] ) ) {
                continue;
            }

            $date = self::explicit_date( $row );
            if ( ! $date['value'] || ! $date['field'] || (int) substr( $date['value'], 0, 4 ) !== $current_year ) {
                continue;
            }

            $seen[ $identity ] = true;

            $kanun_no    = self::clean_text( $row['kanunNo'] ?? '', 100 );
            $kanun_title = self::clean_text( $row['kanunTitle'] ?? '', 1000 );
            $rg_sayi     = self::clean_text( $row['resmiGazeteSayi'] ?? '', 100 );
            $summary     = self::summary(
                $entity_type,
                $title,
                $kanun_no,
                $kanun_title,
                $date['value'],
                $rg_sayi
            );

            if ( mb_strlen( $summary, 'UTF-8' ) < 80 || self::looks_mojibaked( $summary ) ) {
                continue;
            }

            $path_info = self::path_info( $url );

            $items[] = array(
                'source_item_key' => mb_substr( 'gib-mevzuat:' . $identity, 0, 191 ),
                'title'           => $title,
                'url'             => esc_url_raw( $url ),
                'published_at'    => $date['value'],
                'summary'         => $summary,
                'date_source'     => $date['field'],
                'source_strategy' => 'official_json_api',
                'raw_payload'     => array(
                    'api_endpoint'         => esc_url_raw( $feed_url ),
                    'api_scope'            => 'resultContainer.results',
                    'entity_type'          => $entity_type,
                    'entity_label'         => self::type_label( $entity_type ),
                    'entity_path'          => $path_info['entity_path'],
                    'detail_id'            => $path_info['detail_id'],
                    'kanun_id'             => $path_info['kanun_id'],
                    'kanun_no'             => $kanun_no,
                    'kanun_title'          => $kanun_title,
                    'resmi_gazete_tarih'   => self::clean_text( $row['resmiGazeteTarih'] ?? '', 100 ),
                    'resmi_gazete_sayi'    => $rg_sayi,
                    'explicit_date_field'  => $date['field'],
                    'current_year_gate'    => $current_year,
                    'normative_type_gate'  => true,
                    'excluded_case_ruling' => false,
                ),
            );

            if ( count( $items ) >= $limit ) {
                break;
            }
        }

        if ( ! $items ) {
            return new WP_Error(
                'custom_source_no_items',
                'GİB mevzuat API current-year tarih, normatif tür ve canonical URL kontrollerini geçen kayıt üretmedi.'
            );
        }

        return $items;
    }

    private static function is_normative_type( $entity_type ) {
        return in_array(
            $entity_type,
            array(
                'TEBLIG',
                'CBK',
                'SIRKULER',
                'YONETMELIK',
                'ICGENELGE',
                'GENELYAZI',
                'BKK',
                'KANUN',
            ),
            true
        );
    }

    private static function explicit_date( $row ) {
        foreach ( array( 'resmiGazeteTarih', 'sirkulerTarih', 'tarih' ) as $field ) {
            $raw = trim( (string) ( $row[ $field ] ?? '' ) );
            if ( ! $raw ) {
                continue;
            }

            $normalized = self::normalize_iso_date( $raw );
            if ( $normalized ) {
                return array(
                    'field' => sanitize_key( $field ),
                    'value' => $normalized,
                );
            }
        }

        return array( 'field' => '', 'value' => null );
    }

    private static function normalize_iso_date( $value ) {
        $value = trim( (string) $value );
        if ( ! preg_match( '~^(\d{4})-(\d{2})-(\d{2})(?:[T\s].*)?$~', $value, $match ) ) {
            return null;
        }

        $year  = (int) $match[1];
        $month = (int) $match[2];
        $day   = (int) $match[3];
        if ( ! checkdate( $month, $day, $year ) ) {
            return null;
        }

        return sprintf( '%04d-%02d-%02d 12:00:00', $year, $month, $day );
    }

    private static function canonical_url( $value ) {
        $url   = html_entity_decode( trim( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return '';
        }

        if ( 'https' !== strtolower( (string) $parts['scheme'] ) || 'gib.gov.tr' !== self::canonical_host( $parts['host'] ) ) {
            return '';
        }

        $path = (string) ( $parts['path'] ?? '' );
        if ( ! preg_match( '~^/mevzuat/kanun/\d+(?:/[a-z0-9_-]+/\d+)?/?$~i', $path ) ) {
            return '';
        }

        return 'https://gib.gov.tr' . $path;
    }

    private static function identity_from_url( $entity_type, $url ) {
        $info = self::path_info( $url );
        if ( ! $info['kanun_id'] ) {
            return '';
        }

        if ( 'KANUN' === $entity_type ) {
            return '' === $info['entity_path'] && ! $info['detail_id']
                ? 'kanun:' . $info['kanun_id']
                : '';
        }

        $expected = self::type_path( $entity_type );
        if ( ! $expected || $expected !== $info['entity_path'] || ! $info['detail_id'] ) {
            return '';
        }

        return $info['entity_path'] . ':' . $info['detail_id'];
    }

    private static function path_info( $url ) {
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( preg_match( '~^/mevzuat/kanun/(\d+)/([a-z0-9_-]+)/(\d+)/?$~i', $path, $match ) ) {
            return array(
                'kanun_id'    => absint( $match[1] ),
                'entity_path' => sanitize_key( $match[2] ),
                'detail_id'   => absint( $match[3] ),
            );
        }
        if ( preg_match( '~^/mevzuat/kanun/(\d+)/?$~i', $path, $match ) ) {
            return array(
                'kanun_id'    => absint( $match[1] ),
                'entity_path' => '',
                'detail_id'   => 0,
            );
        }

        return array( 'kanun_id' => 0, 'entity_path' => '', 'detail_id' => 0 );
    }

    private static function type_path( $entity_type ) {
        $map = array(
            'TEBLIG'      => 'teblig',
            'CBK'         => 'cbk',
            'SIRKULER'    => 'sirkuler',
            'YONETMELIK'  => 'yonetmelik',
            'ICGENELGE'   => 'icgenelge',
            'GENELYAZI'   => 'genelyazi',
            'BKK'         => 'bkk',
        );

        return $map[ $entity_type ] ?? '';
    }

    private static function type_label( $entity_type ) {
        $map = array(
            'TEBLIG'      => 'Tebliğ',
            'CBK'         => 'Cumhurbaşkanı Kararı',
            'SIRKULER'    => 'Sirküler',
            'YONETMELIK'  => 'Yönetmelik',
            'ICGENELGE'   => 'İç Genelge',
            'GENELYAZI'   => 'Genel Yazı',
            'BKK'         => 'Bakanlar Kurulu Kararı',
            'KANUN'       => 'Kanun',
        );

        return $map[ $entity_type ] ?? $entity_type;
    }

    private static function summary( $entity_type, $title, $kanun_no, $kanun_title, $published, $rg_sayi ) {
        $parts = array( 'Mevzuat türü: ' . self::type_label( $entity_type ) . '.' );

        if ( $kanun_no || $kanun_title ) {
            $kanun = trim( $kanun_no . ( $kanun_no && $kanun_title ? ' sayılı ' : '' ) . $kanun_title );
            if ( $kanun ) {
                $parts[] = 'İlgili mevzuat: ' . $kanun . '.';
            }
        }

        $parts[] = 'Düzenleme: ' . $title . '.';
        $parts[] = 'Tarih: ' . substr( $published, 0, 10 ) . '.';

        if ( $rg_sayi ) {
            $parts[] = 'Resmî Gazete sayısı: ' . $rg_sayi . '.';
        }

        $parts[] = 'Kaynak: Gelir İdaresi Başkanlığı.';

        return self::clean_text( implode( ' ', $parts ), 4000 );
    }

    private static function request_args() {
        return array(
            'timeout'             => self::TIMEOUT,
            'redirection'         => 2,
            'limit_response_size' => self::MAX_BODY_SIZE,
            'user-agent'          => 'Mozilla/5.0 (compatible; SektorelAjandaContentBot/1.2)',
            'headers'             => array(
                'Accept'          => 'application/json, text/plain, */*',
                'Accept-Language' => 'tr-TR,tr;q=0.9',
                'Origin'          => 'https://www.gib.gov.tr',
                'Referer'         => 'https://www.gib.gov.tr/',
            ),
        );
    }

    private static function is_feed_url( $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }

        return 'https' === strtolower( (string) $parts['scheme'] ) &&
            'gib.gov.tr' === self::canonical_host( $parts['host'] ) &&
            '/api/gibportal/search/mevzuatLastAdded' === rtrim( (string) ( $parts['path'] ?? '' ), '/' );
    }

    private static function canonical_host( $host ) {
        $host = strtolower( rtrim( (string) $host, '.' ) );
        return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
    }

    private static function clean_text( $value, $max_length ) {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = trim( preg_replace( '/\s+/u', ' ', $value ) );
        return mb_substr( $value, 0, max( 1, absint( $max_length ) ) );
    }

    private static function looks_mojibaked( $text ) {
        foreach ( array(
            'Ã¼', 'Ãœ', 'Ã¶', 'Ã–', 'Ã§', 'Ã‡',
            'Ä°', 'Ä±', 'ÄŸ', 'Äž', 'ÅŸ', 'Åž',
            'â€™', 'â€œ', 'â€', 'â€“', 'â€”', 'Â ',
        ) as $marker ) {
            if ( false !== strpos( (string) $text, $marker ) ) {
                return true;
            }
        }
        return false;
    }
}
