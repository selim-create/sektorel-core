<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Company_Media {

    const MAX_FILE_SIZE = 5242880; // 5 MB.
    const MAX_GALLERY_ITEMS = 12;

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( 'sektorel/v1', '/company-media', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'upload' ),
            'permission_callback' => array( __CLASS__, 'permissions_check' ),
            'args'                => array(
                'type' => array(
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_key',
                    'validate_callback' => function( $value ) {
                        return in_array( $value, array( 'logo', 'cover', 'gallery' ), true );
                    },
                ),
            ),
        ) );
    }

    public static function permissions_check() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'sektorel_auth_required', 'Görsel yüklemek için giriş yapmanız gerekir.', array( 'status' => 401 ) );
        }

        $company_id = Sektorel_Session_Query::get_owned_company_id( $user_id );
        if ( ! $company_id ) {
            return new WP_Error( 'sektorel_company_missing', 'Bu hesaba bağlı bir firma bulunamadı.', array( 'status' => 403 ) );
        }

        $post = get_post( $company_id );
        if ( ! $post || 'company' !== $post->post_type || (int) $post->post_author !== (int) $user_id ) {
            return new WP_Error( 'sektorel_company_forbidden', 'Bu firmanın görsellerini değiştirme yetkiniz bulunmuyor.', array( 'status' => 403 ) );
        }

        return true;
    }

    public static function upload( WP_REST_Request $request ) {
        $type = sanitize_key( $request->get_param( 'type' ) );
        if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
            return new WP_Error( 'sektorel_file_missing', 'Yüklenecek görsel bulunamadı.', array( 'status' => 400 ) );
        }

        $file = $_FILES['file'];
        if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
            return new WP_Error( 'sektorel_upload_error', 'Görsel yüklenemedi. Lütfen dosyayı yeniden seçin.', array( 'status' => 400 ) );
        }

        if ( empty( $file['size'] ) || (int) $file['size'] > self::MAX_FILE_SIZE ) {
            return new WP_Error( 'sektorel_file_size', 'Görsel en fazla 5 MB olabilir.', array( 'status' => 400 ) );
        }

        $allowed_mimes = array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
        );
        $checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_mimes );
        if ( empty( $checked['type'] ) || ! in_array( $checked['type'], array_values( $allowed_mimes ), true ) ) {
            return new WP_Error( 'sektorel_file_type', 'Yalnızca JPG, PNG veya WebP görseller yüklenebilir.', array( 'status' => 400 ) );
        }

        $user_id = get_current_user_id();
        $company_id = Sektorel_Session_Query::get_owned_company_id( $user_id );

        if ( 'gallery' === $type && count( self::gallery_urls( $company_id ) ) >= self::MAX_GALLERY_ITEMS ) {
            return new WP_Error( 'sektorel_gallery_limit', 'Galeriye en fazla 12 görsel ekleyebilirsiniz.', array( 'status' => 400 ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // REST multipart isteklerinde klasik form nonce alanı bulunmaz.
        $uploaded = wp_handle_upload(
            $file,
            array(
                'test_form' => false,
                'mimes'     => $allowed_mimes,
            )
        );

        if ( ! empty( $uploaded['error'] ) ) {
            return new WP_Error(
                'sektorel_media_upload_failed',
                'Görsel WordPress medya kütüphanesine kaydedilemedi: ' . sanitize_text_field( $uploaded['error'] ),
                array( 'status' => 500 )
            );
        }

        $attachment_id = wp_insert_attachment(
            array(
                'post_mime_type' => $uploaded['type'],
                'post_title'     => sanitize_text_field( pathinfo( $file['name'], PATHINFO_FILENAME ) ),
                'post_content'   => '',
                'post_status'    => 'inherit',
                'post_parent'    => $company_id,
            ),
            $uploaded['file'],
            $company_id,
            true
        );

        if ( is_wp_error( $attachment_id ) ) {
            wp_delete_file( $uploaded['file'] );
            return new WP_Error(
                'sektorel_attachment_failed',
                'Görsel medya kaydı oluşturulamadı: ' . $attachment_id->get_error_message(),
                array( 'status' => 500 )
            );
        }

        $metadata = wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] );
        if ( is_array( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        $url = wp_get_attachment_url( $attachment_id );
        if ( ! $url ) {
            wp_delete_attachment( $attachment_id, true );
            return new WP_Error( 'sektorel_media_url_missing', 'Yüklenen görselin adresi oluşturulamadı.', array( 'status' => 500 ) );
        }

        update_post_meta( $attachment_id, '_sektorel_company_id', (int) $company_id );
        update_post_meta( $attachment_id, '_sektorel_media_type', $type );

        if ( 'logo' === $type ) {
            update_post_meta( $company_id, 'logo_image', esc_url_raw( $url ) );
            set_post_thumbnail( $company_id, $attachment_id );
        } elseif ( 'cover' === $type ) {
            update_post_meta( $company_id, 'cover_image', esc_url_raw( $url ) );
        } else {
            $gallery = self::gallery_urls( $company_id );
            $gallery[] = esc_url_raw( $url );
            $gallery = array_slice( array_values( array_unique( array_filter( $gallery ) ) ), 0, self::MAX_GALLERY_ITEMS );
            update_post_meta( $company_id, 'gallery_urls', implode( "\n", $gallery ) );
        }

        clean_post_cache( $company_id );

        return rest_ensure_response( array(
            'success'      => true,
            'attachmentId' => (int) $attachment_id,
            'type'         => $type,
            'url'          => esc_url_raw( $url ),
            'thumbnailUrl' => wp_get_attachment_image_url( $attachment_id, 'medium' ) ?: esc_url_raw( $url ),
        ) );
    }

    private static function gallery_urls( $company_id ) {
        $raw = (string) get_post_meta( $company_id, 'gallery_urls', true );
        $items = preg_split( '/\r\n|\r|\n/', $raw );
        return array_values( array_filter( array_map( 'esc_url_raw', $items ) ) );
    }
}
