<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Featured-image service for AI-generated editorial drafts.
 *
 * Source-specific policy decides whether a publisher image may be preferred.
 * Source imagery is validated, downloaded through WordPress safe HTTP APIs and
 * stored with provenance. Pexels is a relevance-checked fallback, never a
 * random-image requirement.
 */
class Sektorel_Content_Draft_Media {

    const PEXELS_SEARCH_ENDPOINT = 'https://api.pexels.com/v1/search';
    const REQUEST_TIMEOUT = 22;
    const MAX_IMAGE_BYTES = 12582912; // 12 MB.

    public static function attach_featured_image( $post_id, $article = array() ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return new WP_Error( 'content_media_invalid_post', 'Görsel için geçerli yazı ID’si bulunamadı.' );
        }

        $context = self::candidate_context( $post_id );
        $source_key = sanitize_key( $context['source_key'] ?? get_post_meta( $post_id, '_sektorel_content_source_key', true ) );
        $source_id = absint( $context['source_id'] ?? 0 );
        $policy = class_exists( 'Sektorel_Content_Source_Policy' )
            ? Sektorel_Content_Source_Policy::image_policy( $source_key, $source_id )
            : 'pexels_only';

        $existing = get_post_thumbnail_id( $post_id );
        $existing_provider = sanitize_key( (string) get_post_meta( $post_id, '_sektorel_media_provider', true ) );

        // Never replace an editor/manual image. Only an older system Pexels image
        // may be upgraded to an allowed, more relevant source image.
        if ( $existing && ! ( 'source_preferred' === $policy && 'pexels' === $existing_provider ) ) {
            return array(
                'status'        => 'existing',
                'attachment_id' => absint( $existing ),
                'provider'      => $existing_provider,
            );
        }

        if ( 'source_preferred' === $policy ) {
            $source_result = self::try_source_image( $post_id, $article, $context );
            if ( ! is_wp_error( $source_result ) ) {
                if ( $existing && absint( $source_result['attachment_id'] ?? 0 ) !== absint( $existing ) ) {
                    update_post_meta( $post_id, '_sektorel_media_replaced_attachment_id', absint( $existing ) );
                }
                delete_post_meta( $post_id, '_sektorel_media_source_image_error' );
                return $source_result;
            }

            update_post_meta( $post_id, '_sektorel_media_source_image_error', $source_result->get_error_message() );

            // Existing Pexels image is safer than swapping it for another fallback.
            if ( $existing ) {
                return array(
                    'status'        => 'existing',
                    'attachment_id' => absint( $existing ),
                    'provider'      => 'pexels',
                    'source_error'  => $source_result->get_error_code(),
                );
            }
        }

        if ( 'none' === $policy ) {
            return new WP_Error( 'content_media_disabled_for_source', 'Bu kaynak için otomatik görsel kullanımı kapalı.' );
        }

        return self::attach_pexels_image( $post_id, $article );
    }

    private static function try_source_image( $post_id, $article, $context ) {
        $source_key = sanitize_key( $context['source_key'] ?? '' );
        $source_url = esc_url_raw( $context['source_url'] ?? get_post_meta( $post_id, '_sektorel_content_source_url', true ) );
        $source_title = sanitize_text_field( $context['title'] ?? get_post_meta( $post_id, '_sektorel_content_source_title', true ) );
        $image_url = esc_url_raw( $context['source_image_url'] ?? '' );

        if ( ! $image_url && class_exists( 'Sektorel_Content_Detail_Extractor' ) && $source_key && $source_url ) {
            $image_url = Sektorel_Content_Detail_Extractor::discover_source_image( $source_key, $source_url, $source_title );
        }

        if ( ! $image_url ) {
            return new WP_Error( 'content_media_source_image_missing', 'Kaynak sayfada kullanılabilir haber görseli bulunamadı.' );
        }

        if ( ! self::is_safe_source_image_url( $image_url, $source_url, $source_key ) ) {
            return new WP_Error( 'content_media_source_image_unsafe', 'Kaynak görsel URL’si izinli kaynak hostuyla eşleşmiyor.' );
        }

        $alt = sanitize_text_field( get_the_title( $post_id ) );
        $attachment_id = self::sideload_remote_image( $image_url, $post_id, $alt, $source_url );
        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        $attachment_id = absint( $attachment_id );
        set_post_thumbnail( $post_id, $attachment_id );
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );

        $source_host = sanitize_text_field( (string) wp_parse_url( $source_url, PHP_URL_HOST ) );
        wp_update_post(
            array(
                'ID'           => $attachment_id,
                'post_excerpt' => $source_host ? 'Kaynak görsel: ' . $source_host : 'Kaynak görsel',
            )
        );

        foreach ( array( $attachment_id, $post_id ) as $object_id ) {
            update_post_meta( $object_id, '_sektorel_media_provider', 'source' );
            update_post_meta( $object_id, '_sektorel_media_source_url', esc_url_raw( $image_url ) );
            update_post_meta( $object_id, '_sektorel_media_source_page', esc_url_raw( $source_url ) );
            update_post_meta( $object_id, '_sektorel_media_source_key', $source_key );
        }
        update_post_meta( $post_id, '_sektorel_content_media_status', 'ready' );

        return array(
            'status'        => 'created',
            'attachment_id' => $attachment_id,
            'provider'      => 'source',
            'source_url'    => esc_url_raw( $image_url ),
            'source_page'   => esc_url_raw( $source_url ),
        );
    }

    private static function attach_pexels_image( $post_id, $article ) {
        if ( ! class_exists( 'Sektorel_Core_Settings' ) ) {
            return new WP_Error( 'content_media_settings_missing', 'Sektörel Core medya ayarları yüklenemedi.' );
        }

        $settings = Sektorel_Core_Settings::settings();
        $provider = sanitize_key( $settings['media_provider'] ?? 'pexels_first' );
        if ( 'unsplash' === $provider ) {
            return new WP_Error( 'content_media_provider_unsupported', 'Yerel featured image için Pexels sağlayıcısını seçin.' );
        }

        $api_key = self::pexels_key();
        if ( '' === $api_key ) {
            return new WP_Error( 'content_media_pexels_key_missing', 'Pexels API anahtarı tanımlı değil.' );
        }

        $query = self::image_query( $post_id, $article );
        if ( '' === $query ) {
            return new WP_Error( 'content_media_query_missing', 'Görsel arama sorgusu üretilemedi.' );
        }

        $photo = self::search_pexels( $query, $api_key );
        if ( is_wp_error( $photo ) ) {
            return $photo;
        }

        $image_url = esc_url_raw(
            $photo['src']['landscape'] ??
            $photo['src']['large2x'] ??
            $photo['src']['large'] ??
            $photo['src']['original'] ?? ''
        );
        if ( '' === $image_url ) {
            return new WP_Error( 'content_media_image_url_missing', 'Pexels sonucu kullanılabilir görsel URL’si içermiyor.' );
        }

        $alt = sanitize_text_field( get_the_title( $post_id ) );
        $attachment_id = self::sideload_remote_image( $image_url, $post_id, $alt, 'https://www.pexels.com/' );
        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        $attachment_id = absint( $attachment_id );
        set_post_thumbnail( $post_id, $attachment_id );
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );

        $photographer = sanitize_text_field( $photo['photographer'] ?? '' );
        $photographer_url = esc_url_raw( $photo['photographer_url'] ?? '' );
        $photo_url = esc_url_raw( $photo['url'] ?? '' );
        $pexels_id = absint( $photo['id'] ?? 0 );

        if ( $photographer ) {
            wp_update_post(
                array(
                    'ID'           => $attachment_id,
                    'post_excerpt' => sprintf( 'Fotoğraf: %s / Pexels', $photographer ),
                )
            );
        }

        update_post_meta( $attachment_id, '_sektorel_media_provider', 'pexels' );
        update_post_meta( $attachment_id, '_sektorel_media_source_url', $photo_url );
        update_post_meta( $attachment_id, '_sektorel_media_photographer', $photographer );
        update_post_meta( $attachment_id, '_sektorel_media_photographer_url', $photographer_url );
        update_post_meta( $attachment_id, '_sektorel_media_pexels_id', $pexels_id );
        update_post_meta( $attachment_id, '_sektorel_media_query', $query );

        update_post_meta( $post_id, '_sektorel_media_provider', 'pexels' );
        update_post_meta( $post_id, '_sektorel_media_source_url', $photo_url );
        update_post_meta( $post_id, '_sektorel_media_photographer', $photographer );
        update_post_meta( $post_id, '_sektorel_media_photographer_url', $photographer_url );
        update_post_meta( $post_id, '_sektorel_media_query', $query );
        update_post_meta( $post_id, '_sektorel_content_media_status', 'ready' );

        return array(
            'status'           => 'created',
            'attachment_id'    => $attachment_id,
            'provider'         => 'pexels',
            'source_url'       => $photo_url,
            'photographer'     => $photographer,
            'photographer_url' => $photographer_url,
            'query'            => $query,
        );
    }

    private static function search_pexels( $query, $api_key ) {
        $url = add_query_arg(
            array(
                'query'       => $query,
                'orientation' => 'landscape',
                'size'        => 'large',
                'locale'      => 'en-US',
                'per_page'    => 10,
                'page'        => 1,
            ),
            self::PEXELS_SEARCH_ENDPOINT
        );

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => array(
                    'Authorization' => $api_key,
                    'Accept'        => 'application/json',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'content_media_pexels_request_failed', $response->get_error_message() );
        }

        $code = absint( wp_remote_retrieve_response_code( $response ) );
        $json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( 200 !== $code || ! is_array( $json ) ) {
            return new WP_Error( 'content_media_pexels_http_error', 'Pexels görsel araması başarısız oldu (HTTP ' . $code . ').' );
        }

        $photos = isset( $json['photos'] ) && is_array( $json['photos'] ) ? $json['photos'] : array();
        if ( empty( $photos ) ) {
            return new WP_Error( 'content_media_pexels_empty', 'Pexels üzerinde uygun landscape görsel bulunamadı.' );
        }

        $query_tokens = self::query_tokens( $query );
        $best = null;
        $best_score = -1;
        foreach ( $photos as $photo ) {
            if ( ! is_array( $photo ) ) {
                continue;
            }
            $alt_tokens = self::query_tokens( sanitize_text_field( $photo['alt'] ?? '' ) );
            $overlap = count( array_intersect( $query_tokens, $alt_tokens ) );
            $score = $overlap * 10;
            if ( ! empty( $photo['width'] ) && ! empty( $photo['height'] ) && (int) $photo['width'] > (int) $photo['height'] ) {
                $score += 2;
            }
            if ( $score > $best_score ) {
                $best = $photo;
                $best_score = $score;
            }
        }

        // The API search itself is relevant, but require at least one semantic
        // token match against Pexels alt text when the query has meaningful terms.
        if ( count( $query_tokens ) >= 2 && $best_score < 10 ) {
            return new WP_Error( 'content_media_pexels_irrelevant', 'Pexels sonuçları görsel sorgusuyla yeterince ilgili bulunmadı; alakasız görsel eklenmedi.' );
        }

        return $best ?: new WP_Error( 'content_media_pexels_empty', 'Pexels üzerinde uygun görsel seçilemedi.' );
    }

    private static function sideload_remote_image( $url, $post_id, $alt, $referer = '' ) {
        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => self::REQUEST_TIMEOUT,
                'redirection'         => 4,
                'limit_response_size' => self::MAX_IMAGE_BYTES,
                'user-agent'          => 'Mozilla/5.0 (compatible; SektorelAjandaMediaBot/1.1; +' . home_url( '/' ) . ')',
                'headers'             => array_filter( array(
                    'Accept'  => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                    'Referer' => esc_url_raw( $referer ),
                ) ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'content_media_download_failed', $response->get_error_message() );
        }

        $code = absint( wp_remote_retrieve_response_code( $response ) );
        $content_type = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'content-type' ) ) );
        $body = (string) wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 300 || 0 !== strpos( $content_type, 'image/' ) || strlen( $body ) < 1024 ) {
            return new WP_Error( 'content_media_download_invalid', 'Görsel indirilemedi veya yanıt geçerli bir image dosyası değil.' );
        }

        $tmp = wp_tempnam( $url );
        if ( ! $tmp || false === file_put_contents( $tmp, $body ) ) {
            return new WP_Error( 'content_media_temp_failed', 'Görsel için geçici dosya oluşturulamadı.' );
        }

        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $filename = sanitize_file_name( basename( $path ) );
        $extensions = array(
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
        );
        $mime = trim( explode( ';', $content_type )[0] );
        $ext = $extensions[ $mime ] ?? '';
        if ( ! $filename || ! pathinfo( $filename, PATHINFO_EXTENSION ) ) {
            $filename = 'sektorel-ajanda-' . wp_generate_password( 8, false, false ) . ( $ext ? '.' . $ext : '.jpg' );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $file = array(
            'name'     => $filename,
            'tmp_name' => $tmp,
        );
        $attachment_id = media_handle_sideload( $file, $post_id, $alt );
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return new WP_Error( 'content_media_sideload_failed', $attachment_id->get_error_message() );
        }

        return absint( $attachment_id );
    }

    private static function candidate_context( $post_id ) {
        $candidate_id = absint( get_post_meta( $post_id, '_sektorel_content_candidate_id', true ) );
        if ( ! $candidate_id || ! class_exists( 'Sektorel_Content_Candidates' ) ) {
            return array();
        }

        $candidate = Sektorel_Content_Candidates::get( $candidate_id );
        if ( ! is_array( $candidate ) ) {
            return array();
        }

        $normalized = self::decode_json_array( $candidate['normalized_payload'] ?? '' );
        $evidence = self::decode_json_array( $candidate['evidence_json'] ?? '' );
        $detail = isset( $evidence['detail_extraction'] ) && is_array( $evidence['detail_extraction'] )
            ? $evidence['detail_extraction']
            : array();

        return array(
            'candidate_id'     => $candidate_id,
            'source_id'        => absint( $candidate['source_id'] ?? 0 ),
            'source_key'       => sanitize_key( $candidate['source_key'] ?? '' ),
            'source_url'       => esc_url_raw( ! empty( $candidate['canonical_url'] ) ? $candidate['canonical_url'] : ( $candidate['source_url'] ?? '' ) ),
            'title'            => sanitize_text_field( $candidate['title'] ?? '' ),
            'source_image_url' => esc_url_raw( $normalized['source_image_url'] ?? ( $detail['image_url'] ?? '' ) ),
        );
    }

    private static function is_safe_source_image_url( $image_url, $source_url, $source_key ) {
        if ( ! wp_http_validate_url( $image_url ) || ! wp_http_validate_url( $source_url ) ) {
            return false;
        }

        $image_host = strtolower( (string) wp_parse_url( $image_url, PHP_URL_HOST ) );
        $source_host = strtolower( (string) wp_parse_url( $source_url, PHP_URL_HOST ) );
        if ( ! $image_host || ! $source_host ) {
            return false;
        }

        $allowed = class_exists( 'Sektorel_Content_Source_Policy' )
            ? Sektorel_Content_Source_Policy::allowed_detail_hosts( $source_key )
            : array( $source_host );

        foreach ( $allowed as $allowed_host ) {
            $allowed_host = preg_replace( '/^www\./', '', strtolower( $allowed_host ) );
            $candidate_host = preg_replace( '/^www\./', '', $image_host );
            if ( $candidate_host === $allowed_host || str_ends_with( $candidate_host, '.' . $allowed_host ) ) {
                return true;
            }
        }

        return false;
    }

    private static function image_query( $post_id, $article ) {
        $query = sanitize_text_field( $article['image_query'] ?? '' );
        if ( $query ) {
            return mb_substr( $query, 0, 120 );
        }

        $focus = sanitize_text_field( $article['focus_keyword'] ?? '' );
        if ( $focus ) {
            return mb_substr( $focus, 0, 120 );
        }

        $tags = wp_get_post_tags( $post_id, array( 'fields' => 'names' ) );
        if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) {
            return mb_substr( implode( ' ', array_slice( $tags, 0, 3 ) ), 0, 120 );
        }

        return mb_substr( sanitize_text_field( get_the_title( $post_id ) ), 0, 120 );
    }

    private static function query_tokens( $value ) {
        $value = strtolower( remove_accents( sanitize_text_field( $value ) ) );
        $value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
        $tokens = array_filter( preg_split( '/\s+/', trim( (string) $value ) ) );
        $stop = array( 'the', 'and', 'for', 'with', 'from', 'business', 'news', 'industry', 'company', 'photo', 'image' );
        $tokens = array_filter( $tokens, static function( $token ) use ( $stop ) {
            return strlen( $token ) >= 3 && ! in_array( $token, $stop, true );
        } );
        return array_values( array_unique( $tokens ) );
    }

    private static function pexels_key() {
        if ( defined( 'SEKTOREL_PEXELS_API_KEY' ) && '' !== trim( (string) SEKTOREL_PEXELS_API_KEY ) ) {
            return trim( (string) SEKTOREL_PEXELS_API_KEY );
        }

        return class_exists( 'Sektorel_Core_Settings' )
            ? trim( (string) Sektorel_Core_Settings::secret( 'pexels_api_key' ) )
            : '';
    }

    private static function decode_json_array( $value ) {
        if ( ! $value ) {
            return array();
        }
        $decoded = json_decode( (string) $value, true );
        return is_array( $decoded ) ? $decoded : array();
    }
}
