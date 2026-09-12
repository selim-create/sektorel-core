<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Featured-image service for AI-generated editorial drafts.
 *
 * Source images are never copied from the publisher page. The first production
 * implementation uses the configured Pexels API key and stores attribution
 * metadata alongside the WordPress attachment and post.
 */
class Sektorel_Content_Draft_Media {

    const PEXELS_SEARCH_ENDPOINT = 'https://api.pexels.com/v1/search';
    const REQUEST_TIMEOUT = 20;

    public static function attach_featured_image( $post_id, $article = array() ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return new WP_Error( 'content_media_invalid_post', 'Görsel için geçerli yazı ID’si bulunamadı.' );
        }

        $existing = get_post_thumbnail_id( $post_id );
        if ( $existing ) {
            return array(
                'status'        => 'existing',
                'attachment_id' => absint( $existing ),
                'provider'      => (string) get_post_meta( $post_id, '_sektorel_media_provider', true ),
            );
        }

        if ( ! class_exists( 'Sektorel_Core_Settings' ) ) {
            return new WP_Error( 'content_media_settings_missing', 'Sektörel Core medya ayarları yüklenemedi.' );
        }

        $settings = Sektorel_Core_Settings::settings();
        $provider = sanitize_key( $settings['media_provider'] ?? 'pexels_first' );

        // Unsplash requires a hotlinking-oriented delivery flow. Do not silently
        // sideload it into the Media Library. Pexels is the supported local-media
        // provider for this pipeline.
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

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $alt = sanitize_text_field( get_the_title( $post_id ) );
        $attachment_id = media_sideload_image( $image_url, $post_id, $alt, 'id' );
        if ( is_wp_error( $attachment_id ) ) {
            return new WP_Error( 'content_media_sideload_failed', $attachment_id->get_error_message() );
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
                'locale'      => 'tr-TR',
                'per_page'    => 5,
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

        return $photos[0];
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

    private static function pexels_key() {
        if ( defined( 'SEKTOREL_PEXELS_API_KEY' ) && '' !== trim( (string) SEKTOREL_PEXELS_API_KEY ) ) {
            return trim( (string) SEKTOREL_PEXELS_API_KEY );
        }

        return class_exists( 'Sektorel_Core_Settings' )
            ? trim( (string) Sektorel_Core_Settings::secret( 'pexels_api_key' ) )
            : '';
    }
}
