<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Job_Application_Files {

    const MAX_FILE_SIZE = 5242880; // 5 MB.
    const TOKEN_TTL = HOUR_IN_SECONDS;
    const CIPHER = 'aes-256-gcm';
    const FILE_PREFIX = 'SAJ1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( 'sektorel/v1', '/job-application-cv', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( __CLASS__, 'upload' ),
            'permission_callback' => array( __CLASS__, 'upload_permissions_check' ),
        ) );

        register_rest_route( 'sektorel/v1', '/job-applications/(?P<id>\d+)/cv', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'download' ),
            'permission_callback' => array( __CLASS__, 'download_permissions_check' ),
            'args'                => array(
                'id' => array(
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );
    }

    public static function upload_permissions_check() {
        return get_current_user_id()
            ? true
            : new WP_Error(
                'sektorel_auth_required',
                'CV yüklemek için giriş yapmanız gerekir.',
                array( 'status' => 401 )
            );
    }

    public static function download_permissions_check( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error(
                'sektorel_auth_required',
                'CV dosyasını görüntülemek için giriş yapmanız gerekir.',
                array( 'status' => 401 )
            );
        }

        if ( ! Sektorel_Job_Applications::can_access_application( (int) $request['id'], $user_id ) ) {
            return new WP_Error(
                'sektorel_cv_forbidden',
                'Bu CV dosyasını görüntüleme yetkiniz bulunmuyor.',
                array( 'status' => 403 )
            );
        }

        return true;
    }

    public static function upload() {
        $user_id = get_current_user_id();
        if ( empty( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) ) {
            return new WP_Error( 'sektorel_cv_missing', 'Yüklenecek CV dosyası bulunamadı.', array( 'status' => 400 ) );
        }

        $file = $_FILES['file'];
        if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
            return new WP_Error( 'sektorel_cv_upload_error', 'CV dosyası yüklenemedi.', array( 'status' => 400 ) );
        }

        $size = (int) ( $file['size'] ?? 0 );
        if ( $size < 1 || $size > self::MAX_FILE_SIZE ) {
            return new WP_Error( 'sektorel_cv_size', 'CV dosyası en fazla 5 MB olabilir.', array( 'status' => 400 ) );
        }

        $original_name = sanitize_file_name( wp_unslash( $file['name'] ?? 'cv' ) );
        $allowed_mimes = array(
            'pdf'  => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );
        $checked = wp_check_filetype_and_ext( $file['tmp_name'], $original_name, $allowed_mimes );
        $mime = (string) ( $checked['type'] ?? '' );
        $extension = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );

        if ( ! isset( $allowed_mimes[ $extension ] ) || $mime !== $allowed_mimes[ $extension ] ) {
            return new WP_Error( 'sektorel_cv_type', 'Yalnızca PDF veya DOCX dosyası yükleyebilirsiniz.', array( 'status' => 400 ) );
        }

        $contents = file_get_contents( $file['tmp_name'] );
        if ( false === $contents || '' === $contents ) {
            return new WP_Error( 'sektorel_cv_read', 'CV dosyası okunamadı.', array( 'status' => 400 ) );
        }

        $directory = self::private_directory();
        if ( is_wp_error( $directory ) ) {
            return $directory;
        }

        self::cleanup_expired_files( $directory );

        $nonce = random_bytes( 12 );
        $tag = '';
        $encrypted = openssl_encrypt(
            $contents,
            self::CIPHER,
            self::encryption_key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ( false === $encrypted || 16 !== strlen( $tag ) ) {
            return new WP_Error( 'sektorel_cv_encrypt', 'CV dosyası güvenli biçimde saklanamadı.', array( 'status' => 500 ) );
        }

        $file_name = wp_generate_uuid4() . '.sajcv';
        $path = trailingslashit( $directory ) . $file_name;
        $payload = self::FILE_PREFIX . $nonce . $tag . $encrypted;

        if ( false === file_put_contents( $path, $payload, LOCK_EX ) ) {
            return new WP_Error( 'sektorel_cv_store', 'CV dosyası sunucuya kaydedilemedi.', array( 'status' => 500 ) );
        }

        @chmod( $path, 0600 );

        $token = bin2hex( random_bytes( 32 ) );
        set_transient(
            self::token_key( $user_id, $token ),
            array(
                'path' => $path,
                'name' => $original_name,
                'mime' => $mime,
                'size' => $size,
            ),
            self::TOKEN_TTL
        );

        return rest_ensure_response( array(
            'success'  => true,
            'cvToken'  => $token,
            'fileName' => $original_name,
            'fileSize' => $size,
            'mimeType' => $mime,
        ) );
    }

    public static function download( WP_REST_Request $request ) {
        $application_id = (int) $request['id'];
        $path = (string) get_post_meta( $application_id, 'cv_file_path', true );
        $name = (string) get_post_meta( $application_id, 'cv_file_name', true );
        $mime = (string) get_post_meta( $application_id, 'cv_file_mime', true );

        if ( ! $path || ! is_readable( $path ) ) {
            return new WP_Error( 'sektorel_cv_not_found', 'CV dosyası bulunamadı.', array( 'status' => 404 ) );
        }

        $decrypted = self::decrypt_file( $path );
        if ( is_wp_error( $decrypted ) ) {
            return $decrypted;
        }

        nocache_headers();
        header( 'Content-Type: ' . ( $mime ?: 'application/octet-stream' ) );
        header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ?: 'cv' ) . '"' );
        header( 'Content-Length: ' . strlen( $decrypted ) );
        header( 'X-Content-Type-Options: nosniff' );
        echo $decrypted; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public static function consume_upload_token( $user_id, $token ) {
        if ( ! $token || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            return new WP_Error( 'sektorel_cv_token_invalid', 'CV yükleme anahtarı geçersiz.' );
        }

        $key = self::token_key( $user_id, $token );
        $data = get_transient( $key );
        delete_transient( $key );

        if ( ! is_array( $data ) || empty( $data['path'] ) || ! is_readable( $data['path'] ) ) {
            return new WP_Error( 'sektorel_cv_token_expired', 'CV yükleme süresi dolmuş. Dosyayı yeniden yükleyin.' );
        }

        return $data;
    }

    public static function delete_file( $path ) {
        if ( is_string( $path ) && $path && file_exists( $path ) ) {
            wp_delete_file( $path );
        }
    }

    private static function decrypt_file( $path ) {
        $payload = file_get_contents( $path );
        if ( false === $payload || strlen( $payload ) < 33 || self::FILE_PREFIX !== substr( $payload, 0, 4 ) ) {
            return new WP_Error( 'sektorel_cv_corrupt', 'CV dosyası okunamadı.', array( 'status' => 500 ) );
        }

        $nonce = substr( $payload, 4, 12 );
        $tag = substr( $payload, 16, 16 );
        $encrypted = substr( $payload, 32 );
        $decrypted = openssl_decrypt(
            $encrypted,
            self::CIPHER,
            self::encryption_key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        return false === $decrypted
            ? new WP_Error( 'sektorel_cv_decrypt', 'CV dosyasının şifresi çözülemedi.', array( 'status' => 500 ) )
            : $decrypted;
    }

    private static function token_key( $user_id, $token ) {
        return 'sektorel_cv_' . (int) $user_id . '_' . substr( hash( 'sha256', $token ), 0, 32 );
    }

    private static function encryption_key() {
        return hash( 'sha256', wp_salt( 'auth' ) . '|sektorel-job-cv', true );
    }

    private static function private_directory() {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return new WP_Error( 'sektorel_upload_directory', 'Dosya dizini hazırlanamadı.', array( 'status' => 500 ) );
        }

        $directory = trailingslashit( $uploads['basedir'] ) . 'sektorel-private-cv';
        if ( ! wp_mkdir_p( $directory ) ) {
            return new WP_Error( 'sektorel_private_directory', 'Özel CV dizini oluşturulamadı.', array( 'status' => 500 ) );
        }

        $index = trailingslashit( $directory ) . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php\n// Silence is golden.\n", LOCK_EX );
        }

        $htaccess = trailingslashit( $directory ) . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Require all denied\nDeny from all\n", LOCK_EX );
        }

        return $directory;
    }

    private static function cleanup_expired_files( $directory ) {
        $threshold = time() - ( 2 * HOUR_IN_SECONDS );
        foreach ( glob( trailingslashit( $directory ) . '*.sajcv' ) ?: array() as $path ) {
            if ( is_file( $path ) && filemtime( $path ) < $threshold && ! self::is_attached_file( $path ) ) {
                self::delete_file( $path );
            }
        }
    }

    private static function is_attached_file( $path ) {
        $applications = get_posts( array(
            'post_type'      => 'job_application',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => 'cv_file_path',
            'meta_value'     => $path,
        ) );
        return ! empty( $applications );
    }
}
