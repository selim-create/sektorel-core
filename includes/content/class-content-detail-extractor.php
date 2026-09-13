<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Safe source-text enrichment before AI editorial processing.
 *
 * Official sources use source-specific policies. KOSGEB detail pages use an
 * audited text-boundary extractor because the production HTML collapses the
 * article and page chrome into one wrapper. TOBB detail pages are attempted
 * first but always fall back to the official RSS summary when production cannot
 * fetch or confidently isolate the article body. TCMB keeps detail-page fetch as
 * required behavior.
 */
class Sektorel_Content_Detail_Extractor {

    const TIMEOUT = 18;
    const MAX_BODY_SIZE = 3145728; // 3 MB.
    const MAX_TEXT_LENGTH = 24000;
    const MIN_EXISTING_TEXT = 80;
    const MIN_FETCHED_TEXT = 160;

    public static function enrich_candidate( $row ) {
        $row = is_array( $row ) ? $row : array();
        $candidate_id = absint( $row['id'] ?? 0 );
        $source_key = sanitize_key( $row['source_key'] ?? '' );
        if ( ! $candidate_id || ! $source_key ) {
            return new WP_Error( 'invalid_content_candidate', 'Geçersiz content candidate.' );
        }

        $existing = self::clean_text( $row['extracted_text'] ?? '', self::MAX_TEXT_LENGTH );
        $strategy = class_exists( 'Sektorel_Content_Source_Policy' )
            ? Sektorel_Content_Source_Policy::detail_strategy( $source_key, absint( $row['source_id'] ?? 0 ) )
            : ( 'tcmb_press' === $source_key ? 'detail_page_required' : 'feed_only' );

        if ( 'feed_only' === $strategy ) {
            if ( mb_strlen( $existing ) < self::MIN_EXISTING_TEXT ) {
                return new WP_Error( 'content_detail_too_short', 'Candidate için kullanılabilir kaynak metni yetersiz.' );
            }
            self::persist_detail_evidence( $row, $existing, 'source_payload', $row['source_url'] ?? '' );
            return self::fresh_row( $candidate_id, $row );
        }

        $url = trim( (string) ( ! empty( $row['canonical_url'] ) ? $row['canonical_url'] : ( $row['source_url'] ?? '' ) ) );
        if ( ! self::is_allowed_source_url( $source_key, $url ) ) {
            if ( 'detail_page_preferred' === $strategy && mb_strlen( $existing ) >= self::MIN_EXISTING_TEXT ) {
                self::persist_detail_evidence( $row, $existing, 'rss_summary_fallback', $url, 0, '', 'detail_url_not_allowed' );
                return self::fresh_row( $candidate_id, $row );
            }
            return new WP_Error( 'content_detail_url_not_allowed', 'Detay URL bu kaynak için güvenli allowlist ile eşleşmiyor.' );
        }

        $document = self::fetch_document(
            $source_key,
            $url,
            sanitize_text_field( $row['title'] ?? '' ),
            $existing
        );
        if ( ! is_wp_error( $document ) ) {
            $text = self::clean_text( $document['text'] ?? '', self::MAX_TEXT_LENGTH, true );
            $image_url = esc_url_raw( $document['image_url'] ?? '' );

            if (
                mb_strlen( $text ) >= self::MIN_FETCHED_TEXT &&
                self::is_plausible_detail_text( $text, sanitize_text_field( $row['title'] ?? '' ) )
            ) {
                self::persist_detail_evidence(
                    $row,
                    $text,
                    'detail_page',
                    $url,
                    absint( $document['http_status'] ?? 0 ),
                    $image_url
                );
                return self::fresh_row( $candidate_id, $row );
            }

            $document = new WP_Error( 'content_detail_extract_short', 'Detay sayfasından güvenilir ana haber metni çıkarılamadı.' );
        }

        if ( 'detail_page_preferred' === $strategy && mb_strlen( $existing ) >= self::MIN_EXISTING_TEXT ) {
            self::persist_detail_evidence(
                $row,
                $existing,
                'rss_summary_fallback',
                $url,
                0,
                '',
                is_wp_error( $document ) ? $document->get_error_code() : 'detail_unknown_error'
            );
            return self::fresh_row( $candidate_id, $row );
        }

        return is_wp_error( $document )
            ? $document
            : new WP_Error( 'content_detail_fetch_error', 'Detay sayfası zenginleştirilemedi.' );
    }

    /**
     * Used by the media service for older drafts whose candidate was created
     * before source-image evidence existed.
     */
    public static function discover_source_image( $source_key, $url, $source_title = '' ) {
        $source_key = sanitize_key( $source_key );
        $url = trim( (string) $url );
        if ( ! $source_key || ! self::is_allowed_source_url( $source_key, $url ) ) {
            return '';
        }

        $document = self::fetch_document( $source_key, $url, sanitize_text_field( $source_title ) );
        return is_wp_error( $document ) ? '' : esc_url_raw( $document['image_url'] ?? '' );
    }

    private static function fetch_document( $source_key, $url, $source_title = '', $source_summary = '' ) {
        $parts = wp_parse_url( $url );
        $referer = is_array( $parts ) && ! empty( $parts['scheme'] ) && ! empty( $parts['host'] )
            ? $parts['scheme'] . '://' . $parts['host'] . '/'
            : home_url( '/' );

        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => self::TIMEOUT,
                'redirection'         => 4,
                'limit_response_size' => self::MAX_BODY_SIZE,
                'user-agent'          => 'Mozilla/5.0 (compatible; SektorelAjandaContentBot/1.1; +' . home_url( '/' ) . ')',
                'headers'             => array(
                    'Accept'          => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.2',
                    'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.5',
                    'Referer'         => $referer,
                    'Cache-Control'   => 'no-cache',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'content_detail_fetch_error', $response->get_error_message() );
        }

        $status = absint( wp_remote_retrieve_response_code( $response ) );
        $html = (string) wp_remote_retrieve_body( $response );
        if ( $status < 200 || $status >= 400 ) {
            return new WP_Error( 'content_detail_http_error', 'Detay sayfası HTTP ' . $status . ' döndürdü.' );
        }
        if ( '' === trim( $html ) ) {
            return new WP_Error( 'content_detail_empty', 'Detay sayfası boş yanıt döndürdü.' );
        }

        $parsed = self::extract_document_data( $html, $url, $source_title );

        if ( 'kosgeb_news' === $source_key && '' !== trim( (string) $source_summary ) ) {
            $kosgeb_text = self::extract_kosgeb_detail_text( $html, $source_title, $source_summary );
            if ( is_wp_error( $kosgeb_text ) ) {
                return $kosgeb_text;
            }
            $parsed['text'] = $kosgeb_text;
        }

        if ( empty( $parsed['text'] ) ) {
            return new WP_Error( 'content_detail_extract_short', 'Detay sayfasından ana metin çıkarılamadı.' );
        }

        return array(
            'text'        => $parsed['text'],
            'image_url'   => $parsed['image_url'],
            'http_status' => $status,
        );
    }

    private static function is_allowed_source_url( $source_key, $url ) {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }
        if ( ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
            return false;
        }

        $host = strtolower( rtrim( (string) $parts['host'], '.' ) );
        $hosts = class_exists( 'Sektorel_Content_Source_Policy' )
            ? Sektorel_Content_Source_Policy::allowed_detail_hosts( $source_key )
            : array();

        if ( ! $hosts && 'tcmb_press' === $source_key ) {
            $hosts = array( 'tcmb.gov.tr', 'www.tcmb.gov.tr' );
        }

        return in_array( $host, $hosts, true );
    }

    /**
     * KOSGEB's production detail HTML currently collapses the article body and
     * site chrome into one top-level wrapper without usable child selectors.
     * The audited listing summary remains present inside the detail body, so use
     * it as an anchor and select the nearest matching title boundary.
     */
    private static function extract_kosgeb_detail_text( $html, $source_title, $source_summary ) {
        $text = self::clean_text( $html, self::MAX_TEXT_LENGTH );
        $title = self::clean_text( $source_title, 1000 );
        $summary = self::clean_text( $source_summary, 5000 );

        if ( mb_strlen( $title, 'UTF-8' ) < 8 || mb_strlen( $summary, 'UTF-8' ) < 40 ) {
            return new WP_Error( 'content_detail_kosgeb_anchor_missing', 'KOSGEB detay metni için başlık veya liste özeti yetersiz.' );
        }

        $summary_needle = mb_substr( $summary, 0, min( 70, mb_strlen( $summary, 'UTF-8' ) ), 'UTF-8' );
        $summary_pos = mb_stripos( $text, $summary_needle, 0, 'UTF-8' );
        if ( false === $summary_pos ) {
            return new WP_Error( 'content_detail_kosgeb_summary_missing', 'KOSGEB liste özeti detay sayfasında doğrulanamadı.' );
        }

        $title_positions = self::substring_positions( $text, $title );
        $start = false;

        // Prefer the first matching title shortly after the listing-summary anchor.
        foreach ( $title_positions as $position ) {
            if ( $position > $summary_pos && ( $position - $summary_pos ) <= 700 ) {
                $start = $position;
                break;
            }
        }

        // Otherwise use the nearest matching title immediately before the summary.
        if ( false === $start ) {
            $previous = array();
            foreach ( $title_positions as $position ) {
                if ( $position <= $summary_pos && ( $summary_pos - $position ) <= 700 ) {
                    $previous[] = $position;
                }
            }
            if ( $previous ) {
                $start = max( $previous );
            }
        }

        if ( false === $start ) {
            return new WP_Error( 'content_detail_kosgeb_title_boundary_missing', 'KOSGEB haber gövdesi başlangıç sınırı doğrulanamadı.' );
        }

        $end = self::nearest_marker_position(
            $text,
            array( 'Güncelleme Tarihi:', 'Guncelleme Tarihi:' ),
            $start
        );

        if ( false === $end ) {
            $end = self::nearest_marker_position(
                $text,
                array(
                    'T.C. Küçük ve Orta Ölçekli İşletmeleri Geliştirme ve Destekleme İdaresi Başkanlığı',
                    'T.C. KÜÇÜK VE ORTA ÖLÇEKLİ İŞLETMELERİ GELİŞTİRME VE DESTEKLEME İDARESİ BAŞKANLIĞI',
                ),
                $start
            );
        }

        if ( false === $end || $end <= $start ) {
            return new WP_Error( 'content_detail_kosgeb_end_boundary_missing', 'KOSGEB haber gövdesi bitiş sınırı doğrulanamadı.' );
        }

        $slice = self::clean_text(
            mb_substr( $text, $start, $end - $start, 'UTF-8' ),
            self::MAX_TEXT_LENGTH
        );

        if ( mb_strlen( $slice, 'UTF-8' ) < self::MIN_FETCHED_TEXT ) {
            return new WP_Error( 'content_detail_kosgeb_too_short', 'KOSGEB haber gövdesi güvenilir minimum uzunluğun altında.' );
        }

        $summary_probe = mb_substr(
            $summary_needle,
            0,
            min( 40, mb_strlen( $summary_needle, 'UTF-8' ) ),
            'UTF-8'
        );
        if ( '' === $summary_probe || false === mb_stripos( $slice, $summary_probe, 0, 'UTF-8' ) ) {
            return new WP_Error( 'content_detail_kosgeb_summary_outside_body', 'KOSGEB liste özeti seçilen haber gövdesi içinde doğrulanamadı.' );
        }

        foreach (
            array(
                'Erişilebilirlik Menüsü',
                'Ekran Okuyucu',
                'Seçili Alan Okuyucu',
                'Erişilebilirlik Ayarlarını Temizle',
                'Site içi arama',
                'e-hizmetler MENU',
                'Kurumsal Başkan Başkan Yardımcıları',
            ) as $chrome_marker
        ) {
            if ( false !== mb_stripos( $slice, $chrome_marker, 0, 'UTF-8' ) ) {
                return new WP_Error( 'content_detail_kosgeb_page_chrome', 'KOSGEB detay metni sayfa arayüzü gürültüsü içeriyor.' );
            }
        }

        if ( ! self::is_plausible_detail_text( $slice, $title ) ) {
            return new WP_Error( 'content_detail_kosgeb_implausible', 'KOSGEB detay metni kaynak başlıkla yeterli örtüşme göstermiyor.' );
        }

        return $slice;
    }

    private static function substring_positions( $haystack, $needle ) {
        $positions = array();
        $needle = (string) $needle;
        if ( '' === $needle ) {
            return $positions;
        }

        $offset = 0;
        $needle_length = mb_strlen( $needle, 'UTF-8' );
        while ( false !== ( $position = mb_stripos( $haystack, $needle, $offset, 'UTF-8' ) ) ) {
            $positions[] = $position;
            $offset = $position + max( 1, $needle_length );
        }

        return $positions;
    }

    private static function nearest_marker_position( $text, $markers, $start ) {
        $positions = array();

        foreach ( (array) $markers as $marker ) {
            $position = mb_stripos( $text, (string) $marker, $start + 1, 'UTF-8' );
            if ( false !== $position ) {
                $positions[] = $position;
            }
        }

        return $positions ? min( $positions ) : false;
    }

    private static function extract_document_data( $html, $page_url, $source_title = '' ) {
        if ( ! class_exists( 'DOMDocument' ) ) {
            return array(
                'text'      => self::clean_text( wp_strip_all_tags( $html ), self::MAX_TEXT_LENGTH ),
                'image_url' => '',
            );
        }

        $previous = libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) {
            return array( 'text' => '', 'image_url' => '' );
        }

        $xpath = new DOMXPath( $dom );
        foreach ( array( '//script', '//style', '//noscript', '//svg', '//form', '//nav', '//header', '//footer', '//aside' ) as $query ) {
            $nodes = $xpath->query( $query );
            if ( ! $nodes ) {
                continue;
            }
            $remove = array();
            foreach ( $nodes as $node ) {
                $remove[] = $node;
            }
            foreach ( $remove as $node ) {
                if ( $node->parentNode ) {
                    $node->parentNode->removeChild( $node );
                }
            }
        }

        $title_container = self::find_title_container( $xpath, $source_title );
        $text = '';
        if ( $title_container ) {
            $text = self::node_text( $title_container );
        }

        if ( mb_strlen( $text ) < self::MIN_FETCHED_TEXT ) {
            $queries = array(
                '//article',
                '//main',
                '//*[@role="main"]',
                '//*[contains(@class,"article-content")]',
                '//*[contains(@class,"content-detail")]',
                '//*[contains(@class,"main-content")]',
                '//*[contains(@class,"detail")]',
                '//*[contains(@class,"detay")]',
                '//*[contains(@class,"haber")]',
                '//*[contains(@id,"detail")]',
                '//*[contains(@id,"detay")]',
                '//*[contains(@id,"haber")]',
                '//*[@id="content"]',
            );

            $best = '';
            foreach ( $queries as $query ) {
                $nodes = $xpath->query( $query );
                if ( ! $nodes ) {
                    continue;
                }
                foreach ( $nodes as $node ) {
                    $candidate = self::node_text( $node );
                    if (
                        mb_strlen( $candidate ) > mb_strlen( $best ) &&
                        mb_strlen( $candidate ) <= self::MAX_TEXT_LENGTH &&
                        self::is_plausible_detail_text( $candidate, $source_title )
                    ) {
                        $best = $candidate;
                    }
                }
            }
            $text = $best;
        }

        $image_url = self::extract_image_url( $dom, $xpath, $page_url, $title_container );

        return array(
            'text'      => self::clean_text( $text, self::MAX_TEXT_LENGTH, true ),
            'image_url' => $image_url,
        );
    }

    private static function find_title_container( DOMXPath $xpath, $source_title ) {
        $source_tokens = self::meaningful_tokens( $source_title );
        if ( count( $source_tokens ) < 2 ) {
            return null;
        }

        $headings = $xpath->query( '//h1|//h2|//h3|//h4' );
        if ( ! $headings ) {
            return null;
        }

        foreach ( $headings as $heading ) {
            $heading_tokens = self::meaningful_tokens( $heading->textContent );
            if ( self::token_overlap_ratio( $source_tokens, $heading_tokens ) < 0.6 ) {
                continue;
            }

            $node = $heading;
            $best = null;
            for ( $level = 0; $level < 6 && $node; $level++, $node = $node->parentNode ) {
                if ( ! ( $node instanceof DOMElement ) ) {
                    continue;
                }
                $text = self::node_text( $node );
                $length = mb_strlen( $text );
                if ( $length < self::MIN_FETCHED_TEXT || $length > self::MAX_TEXT_LENGTH ) {
                    continue;
                }
                if ( ! self::is_plausible_detail_text( $text, $source_title ) ) {
                    continue;
                }
                $best = $node;
                if ( $length >= 350 ) {
                    break;
                }
            }
            if ( $best ) {
                return $best;
            }
        }

        return null;
    }

    private static function extract_image_url( DOMDocument $dom, DOMXPath $xpath, $page_url, $title_container = null ) {
        $candidates = array();

        foreach ( $dom->getElementsByTagName( 'meta' ) as $meta ) {
            $property = strtolower( trim( (string) ( $meta->getAttribute( 'property' ) ?: $meta->getAttribute( 'name' ) ) ) );
            if ( ! in_array( $property, array( 'og:image', 'og:image:url', 'twitter:image', 'twitter:image:src' ), true ) ) {
                continue;
            }
            $url = self::normalize_url( $meta->getAttribute( 'content' ), $page_url );
            if ( self::is_usable_image_url( $url, $page_url ) ) {
                $candidates[] = array( 'url' => $url, 'score' => 20 );
            }
        }

        $image_nodes = array();
        if ( $title_container instanceof DOMElement ) {
            $local_xpath = new DOMXPath( $title_container->ownerDocument );
            $nodes = $local_xpath->query( './/img', $title_container );
            if ( $nodes ) {
                foreach ( $nodes as $node ) {
                    $image_nodes[] = array( $node, 8 );
                }
            }
        }

        $all_images = $xpath->query( '//img' );
        if ( $all_images ) {
            foreach ( $all_images as $node ) {
                $image_nodes[] = array( $node, 0 );
            }
        }

        foreach ( $image_nodes as $entry ) {
            list( $img, $base_score ) = $entry;
            if ( ! ( $img instanceof DOMElement ) ) {
                continue;
            }
            $raw = $img->getAttribute( 'src' ) ?: $img->getAttribute( 'data-src' ) ?: $img->getAttribute( 'data-original' );
            $url = self::normalize_url( $raw, $page_url );
            if ( ! self::is_usable_image_url( $url, $page_url ) ) {
                continue;
            }

            $score = $base_score;
            $width = absint( $img->getAttribute( 'width' ) );
            $height = absint( $img->getAttribute( 'height' ) );
            if ( $width >= 600 ) {
                $score += 5;
            }
            if ( $height >= 300 ) {
                $score += 3;
            }
            if ( mb_strlen( trim( $img->getAttribute( 'alt' ) ) ) >= 8 ) {
                $score += 2;
            }
            $candidates[] = array( 'url' => $url, 'score' => $score );
        }

        if ( ! $candidates ) {
            return '';
        }

        usort( $candidates, static function( $a, $b ) {
            return (int) $b['score'] <=> (int) $a['score'];
        } );

        return esc_url_raw( $candidates[0]['url'] ?? '' );
    }

    private static function normalize_url( $url, $page_url ) {
        $url = html_entity_decode( trim( (string) $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
            return '';
        }
        if ( preg_match( '#^https?://#i', $url ) ) {
            return esc_url_raw( $url );
        }

        $parts = wp_parse_url( $page_url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return '';
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if ( 0 === strpos( $url, '//' ) ) {
            return esc_url_raw( $parts['scheme'] . ':' . $url );
        }
        if ( 0 === strpos( $url, '/' ) ) {
            return esc_url_raw( $origin . $url );
        }

        $path = isset( $parts['path'] ) ? dirname( $parts['path'] ) : '/';
        $path = '/' === $path ? '' : rtrim( $path, '/' );
        return esc_url_raw( $origin . $path . '/' . ltrim( $url, '/' ) );
    }

    private static function is_usable_image_url( $url, $page_url ) {
        if ( ! $url || ! wp_http_validate_url( $url ) ) {
            return false;
        }

        $lower = strtolower( $url );
        foreach ( array( 'logo', 'icon', 'sprite', 'spacer', 'blank', 'loading', 'avatar', 'facebook', 'twitter', 'youtube', 'instagram', '.svg', '.gif' ) as $blocked ) {
            if ( false !== strpos( $lower, $blocked ) ) {
                return false;
            }
        }

        $image_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $page_host = strtolower( (string) wp_parse_url( $page_url, PHP_URL_HOST ) );
        if ( ! $image_host || ! $page_host ) {
            return false;
        }

        $page_root = preg_replace( '/^www\./', '', $page_host );
        $image_root = preg_replace( '/^www\./', '', $image_host );
        return $image_root === $page_root || str_ends_with( $image_root, '.' . $page_root );
    }

    private static function node_text( DOMNode $node ) {
        $lines = array();
        if ( $node instanceof DOMElement ) {
            $xpath = new DOMXPath( $node->ownerDocument );
            $parts = $xpath->query( './/h1|.//h2|.//h3|.//p|.//li', $node );
            if ( $parts && $parts->length ) {
                foreach ( $parts as $part ) {
                    $line = self::clean_text( $part->textContent, 4000 );
                    if ( '' !== $line ) {
                        $lines[] = $line;
                    }
                }
            }
        }
        if ( ! $lines ) {
            return self::clean_text( $node->textContent, self::MAX_TEXT_LENGTH, true );
        }
        return implode( "\n\n", array_values( array_unique( $lines ) ) );
    }

    private static function is_plausible_detail_text( $text, $source_title ) {
        $text = self::clean_text( $text, self::MAX_TEXT_LENGTH, true );
        if ( mb_strlen( $text ) < self::MIN_FETCHED_TEXT ) {
            return false;
        }

        $title_tokens = self::meaningful_tokens( $source_title );
        if ( count( $title_tokens ) < 2 ) {
            return true;
        }

        return self::token_overlap_ratio( $title_tokens, self::meaningful_tokens( $text ) ) >= 0.45;
    }

    private static function meaningful_tokens( $value ) {
        $value = mb_strtolower( self::clean_text( $value, 12000 ), 'UTF-8' );
        $value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value );
        $tokens = preg_split( '/\s+/u', trim( (string) $value ) );
        $stop = array( 've', 'ile', 'bir', 'bu', 'için', 'icin', 'de', 'da', 'ile', 'türkiye', 'turkiye', 'toplantısı', 'toplantisi', 'gerçekleştirildi', 'gerceklestirildi' );
        $tokens = array_filter( (array) $tokens, static function( $token ) use ( $stop ) {
            return mb_strlen( $token, 'UTF-8' ) >= 3 && ! in_array( $token, $stop, true );
        } );
        return array_values( array_unique( $tokens ) );
    }

    private static function token_overlap_ratio( $needle_tokens, $haystack_tokens ) {
        $needle_tokens = array_values( array_unique( (array) $needle_tokens ) );
        if ( ! $needle_tokens ) {
            return 0.0;
        }
        $matches = array_intersect( $needle_tokens, array_values( array_unique( (array) $haystack_tokens ) ) );
        return count( $matches ) / count( $needle_tokens );
    }

    private static function persist_detail_evidence( $row, $text, $method, $source_url, $http_status = 0, $image_url = '', $fallback_reason = '' ) {
        global $wpdb;
        $candidate_id = absint( $row['id'] ?? 0 );
        if ( ! $candidate_id ) {
            return false;
        }

        $evidence = self::decode_json_array( $row['evidence_json'] ?? '' );
        $detail = array(
            'status'       => 'resolved',
            'method'       => sanitize_key( $method ),
            'source_url'   => esc_url_raw( $source_url ),
            'http_status'  => absint( $http_status ),
            'text_length'  => mb_strlen( $text ),
            'extracted_at' => gmdate( 'c' ),
            'version'      => 2,
        );
        if ( $image_url ) {
            $detail['image_url'] = esc_url_raw( $image_url );
        }
        if ( $fallback_reason ) {
            $detail['fallback_reason'] = sanitize_key( $fallback_reason );
        }
        $evidence['detail_extraction'] = $detail;

        $normalized = self::decode_json_array( $row['normalized_payload'] ?? '' );
        $normalized['detail_text_length'] = mb_strlen( $text );
        $normalized['detail_source'] = sanitize_key( $method );
        if ( $image_url ) {
            $normalized['source_image_url'] = esc_url_raw( $image_url );
        }

        return false !== $wpdb->update(
            Sektorel_Content_Candidates::table_name(),
            array(
                'extracted_text'     => $text,
                'evidence_json'      => wp_json_encode( $evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'normalized_payload' => wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'updated_at'         => current_time( 'mysql', true ),
            ),
            array( 'id' => $candidate_id ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
    }

    private static function clean_text( $value, $max_length, $preserve_breaks = false ) {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        if ( $preserve_breaks ) {
            $value = preg_replace( "/[ \t]+/u", ' ', $value );
            $value = preg_replace( "/\s*\n\s*/u", "\n", $value );
            $value = preg_replace( "/\n{3,}/u", "\n\n", $value );
        } else {
            $value = preg_replace( '/\s+/u', ' ', $value );
        }
        $value = trim( (string) $value );
        return mb_substr( $value, 0, max( 1, absint( $max_length ) ) );
    }

    private static function fresh_row( $candidate_id, $fallback ) {
        global $wpdb;
        $fresh = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . Sektorel_Content_Candidates::table_name() . ' WHERE id = %d LIMIT 1',
                absint( $candidate_id )
            ),
            ARRAY_A
        );
        return is_array( $fresh ) ? $fresh : $fallback;
    }

    private static function decode_json_array( $value ) {
        if ( ! $value ) {
            return array();
        }
        $decoded = json_decode( (string) $value, true );
        return is_array( $decoded ) ? $decoded : array();
    }
}
