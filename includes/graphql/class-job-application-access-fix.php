<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Job_Application_Access_Fix {

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_graphql' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    public static function register_graphql() {
        register_graphql_field( 'RootQuery', 'sektorelIncomingJobApplicationsV2', array(
            'type' => array( 'list_of' => 'SektorelJobApplicationItem' ),
            'args' => array(
                'jobSlug' => array( 'type' => 'String' ),
            ),
            'resolve' => function( $root, $args ) {
                $context = Sektorel_Company_Access::require_context( false );
                if ( empty( $context['can_edit'] ) ) {
                    throw new \GraphQL\Error\UserError( 'Başvuruları görüntüleme yetkiniz bulunmuyor.' );
                }

                $slug = ! empty( $args['jobSlug'] ) ? sanitize_title( $args['jobSlug'] ) : '';
                $applications = get_posts( array(
                    'post_type'      => 'job_application',
                    'post_status'    => 'publish',
                    'posts_per_page' => 500,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ) );

                $applications = array_values( array_filter( $applications, function( $application ) use ( $context, $slug ) {
                    $job = get_post( (int) get_post_meta( $application->ID, 'job_id', true ) );
                    if ( ! $job || 'career' !== $job->post_type ) {
                        return false;
                    }
                    if ( $slug && $job->post_name !== $slug ) {
                        return false;
                    }
                    return self::can_manage_job( $job, $context );
                } ) );

                return array_map( array( __CLASS__, 'format_application' ), $applications );
            },
        ) );

        register_graphql_mutation( 'updateSektorelJobApplicationStatusV2', array(
            'inputFields' => array(
                'applicationId' => array( 'type' => array( 'non_null' => 'Int' ) ),
                'status'        => array( 'type' => array( 'non_null' => 'String' ) ),
            ),
            'outputFields' => array(
                'success'     => array( 'type' => 'Boolean' ),
                'message'     => array( 'type' => 'String' ),
                'application' => array( 'type' => 'SektorelJobApplicationItem' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $context = Sektorel_Company_Access::require_context( false );
                if ( empty( $context['can_edit'] ) ) {
                    throw new \GraphQL\Error\UserError( 'Başvuru durumunu değiştirme yetkiniz bulunmuyor.' );
                }

                $application = get_post( (int) ( $input['applicationId'] ?? 0 ) );
                if ( ! $application || 'job_application' !== $application->post_type || 'publish' !== $application->post_status ) {
                    throw new \GraphQL\Error\UserError( 'Başvuru bulunamadı.' );
                }

                $job = get_post( (int) get_post_meta( $application->ID, 'job_id', true ) );
                if ( ! $job || ! self::can_manage_job( $job, $context ) ) {
                    throw new \GraphQL\Error\UserError( 'Bu başvuruyu yönetme yetkiniz yok.' );
                }

                $status = sanitize_key( $input['status'] ?? '' );
                if ( ! in_array( $status, array( 'pending', 'reviewing', 'accepted', 'rejected' ), true ) ) {
                    throw new \GraphQL\Error\UserError( 'Geçersiz başvuru durumu.' );
                }

                update_post_meta( $application->ID, 'application_status', $status );

                return array(
                    'success'     => true,
                    'message'     => 'Başvuru durumu güncellendi.',
                    'application' => self::format_application( $application ),
                );
            },
        ) );
    }

    public static function register_rest_routes() {
        register_rest_route( 'sektorel/v1', '/job-applications/(?P<id>\d+)/cv-v2', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( __CLASS__, 'serve_cv' ),
            'permission_callback' => array( __CLASS__, 'cv_permissions' ),
            'args'                => array(
                'id' => array(
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ),
                'view' => array(
                    'required'          => false,
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );
    }

    public static function cv_permissions( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'sektorel_auth_required', 'CV dosyasını görüntülemek için giriş yapmanız gerekir.', array( 'status' => 401 ) );
        }

        $application = get_post( (int) $request['id'] );
        if ( ! $application || 'job_application' !== $application->post_type || 'publish' !== $application->post_status ) {
            return new WP_Error( 'sektorel_application_missing', 'Başvuru bulunamadı.', array( 'status' => 404 ) );
        }

        if ( (int) $application->post_author === (int) $user_id ) {
            return true;
        }

        $job = get_post( (int) get_post_meta( $application->ID, 'job_id', true ) );
        $context = Sektorel_Company_Access::get_context( $user_id );
        if ( ! $job || ! self::can_manage_job( $job, $context ) ) {
            return new WP_Error( 'sektorel_cv_forbidden', 'Bu CV dosyasını görüntüleme yetkiniz bulunmuyor.', array( 'status' => 403 ) );
        }

        return true;
    }

    public static function serve_cv( WP_REST_Request $request ) {
        $application_id = (int) $request['id'];
        $path = (string) get_post_meta( $application_id, 'cv_file_path', true );
        $name = (string) get_post_meta( $application_id, 'cv_file_name', true );
        $mime = (string) get_post_meta( $application_id, 'cv_file_mime', true );

        if ( ! $path || ! is_readable( $path ) ) {
            return new WP_Error( 'sektorel_cv_not_found', 'CV dosyası bulunamadı.', array( 'status' => 404 ) );
        }

        $payload = file_get_contents( $path );
        if ( false === $payload || strlen( $payload ) < 33 || 'SAJ1' !== substr( $payload, 0, 4 ) ) {
            return new WP_Error( 'sektorel_cv_corrupt', 'CV dosyası okunamadı.', array( 'status' => 500 ) );
        }

        $nonce = substr( $payload, 4, 12 );
        $tag = substr( $payload, 16, 16 );
        $encrypted = substr( $payload, 32 );
        $key = hash( 'sha256', wp_salt( 'auth' ) . '|sektorel-job-cv', true );
        $decrypted = openssl_decrypt( $encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );

        if ( false === $decrypted ) {
            return new WP_Error( 'sektorel_cv_decrypt', 'CV dosyasının şifresi çözülemedi.', array( 'status' => 500 ) );
        }

        while ( ob_get_level() ) {
            ob_end_clean();
        }

        $safe_name = sanitize_file_name( $name ?: 'cv' );
        $inline = ! empty( $request['view'] ) && 'application/pdf' === $mime;

        nocache_headers();
        header( 'Content-Type: ' . ( $mime ?: 'application/octet-stream' ) );
        header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . $safe_name . '"' );
        header( 'Content-Length: ' . strlen( $decrypted ) );
        header( 'Content-Transfer-Encoding: binary' );
        header( 'X-Content-Type-Options: nosniff' );
        echo $decrypted; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public static function format_application( $application ) {
        $item = Sektorel_Job_Applications::format_application( $application );
        if ( is_array( $item ) ) {
            $item['cvDownloadUrl'] = rest_url( 'sektorel/v1/job-applications/' . (int) $application->ID . '/cv-v2' );
        }
        return $item;
    }

    private static function can_manage_job( $job, $context ) {
        if ( ! $job instanceof WP_Post || 'career' !== $job->post_type || empty( $context['user_id'] ) || empty( $context['can_edit'] ) ) {
            return false;
        }

        if ( Sektorel_Company_Access::can_view_post( $job, $context ) ) {
            return true;
        }

        if ( (int) $job->post_author === (int) $context['user_id'] ) {
            return true;
        }

        if ( empty( $context['company_id'] ) ) {
            return false;
        }

        $job_company_id = (int) get_post_meta( $job->ID, '_sektorel_company_id', true );
        if ( $job_company_id && $job_company_id === (int) $context['company_id'] ) {
            return true;
        }

        $owner_id = Sektorel_Company_Access::get_company_owner_id( (int) $context['company_id'] );
        return $owner_id && (int) $job->post_author === (int) $owner_id;
    }
}
