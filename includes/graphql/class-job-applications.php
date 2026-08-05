<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Job_Applications {

    const ALLOWED_STATUSES = array( 'pending', 'reviewing', 'accepted', 'rejected' );

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_types' ) );
    }

    public static function register_types() {
        register_graphql_object_type( 'SektorelJobApplicationItem', array(
            'fields' => array(
                'databaseId'     => array( 'type' => 'Int' ),
                'jobDatabaseId'  => array( 'type' => 'Int' ),
                'jobTitle'       => array( 'type' => 'String' ),
                'jobSlug'        => array( 'type' => 'String' ),
                'applicantName'  => array( 'type' => 'String' ),
                'applicantEmail' => array( 'type' => 'String' ),
                'applicantPhone' => array( 'type' => 'String' ),
                'applicantCity'  => array( 'type' => 'String' ),
                'coverLetter'    => array( 'type' => 'String' ),
                'cvFileName'     => array( 'type' => 'String' ),
                'cvDownloadUrl'  => array( 'type' => 'String' ),
                'status'         => array( 'type' => 'String' ),
                'date'           => array( 'type' => 'String' ),
            ),
        ) );

        register_graphql_field( 'RootQuery', 'sektorelMyJobApplications', array(
            'type' => array( 'list_of' => 'SektorelJobApplicationItem' ),
            'resolve' => function() {
                $user_id = get_current_user_id();
                if ( ! $user_id ) {
                    throw new \GraphQL\Error\UserError( 'Başvurularınızı görmek için giriş yapmanız gerekir.' );
                }

                $applications = get_posts( array(
                    'post_type'      => 'job_application',
                    'post_status'    => 'publish',
                    'posts_per_page' => 100,
                    'author'         => (int) $user_id,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ) );

                return array_map( array( __CLASS__, 'format_application' ), $applications );
            },
        ) );

        register_graphql_field( 'RootQuery', 'sektorelIncomingJobApplications', array(
            'type' => array( 'list_of' => 'SektorelJobApplicationItem' ),
            'args' => array(
                'jobSlug' => array( 'type' => 'String' ),
            ),
            'resolve' => function( $root, $args ) {
                $context = Sektorel_Company_Access::require_context( false );
                if ( empty( $context['can_edit'] ) ) {
                    throw new \GraphQL\Error\UserError( 'Başvuruları görüntüleme yetkiniz bulunmuyor.' );
                }

                $jobs = Sektorel_Company_Access::get_accessible_posts(
                    $context['user_id'],
                    array( 'career' ),
                    300
                );

                if ( ! empty( $args['jobSlug'] ) ) {
                    $slug = sanitize_title( $args['jobSlug'] );
                    $jobs = array_values( array_filter( $jobs, function( $job ) use ( $slug ) {
                        return $job instanceof WP_Post && $job->post_name === $slug;
                    } ) );
                }

                $job_ids = array_map( function( $job ) {
                    return (int) $job->ID;
                }, $jobs );

                if ( empty( $job_ids ) ) {
                    return array();
                }

                $applications = get_posts( array(
                    'post_type'      => 'job_application',
                    'post_status'    => 'publish',
                    'posts_per_page' => 300,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                    'meta_query'     => array(
                        array(
                            'key'     => 'job_id',
                            'value'   => $job_ids,
                            'compare' => 'IN',
                            'type'    => 'NUMERIC',
                        ),
                    ),
                ) );

                return array_map( array( __CLASS__, 'format_application' ), $applications );
            },
        ) );

        register_graphql_mutation( 'submitSektorelJobApplication', array(
            'inputFields' => array(
                'jobSlug'    => array( 'type' => array( 'non_null' => 'String' ) ),
                'fullName'   => array( 'type' => array( 'non_null' => 'String' ) ),
                'email'      => array( 'type' => array( 'non_null' => 'String' ) ),
                'phone'      => array( 'type' => array( 'non_null' => 'String' ) ),
                'city'       => array( 'type' => 'String' ),
                'coverLetter'=> array( 'type' => 'String' ),
                'cvToken'    => array( 'type' => array( 'non_null' => 'String' ) ),
            ),
            'outputFields' => array(
                'success'       => array( 'type' => 'Boolean' ),
                'message'       => array( 'type' => 'String' ),
                'applicationId' => array( 'type' => 'Int' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $context = Sektorel_Company_Access::require_context( false );
                $user_id = (int) $context['user_id'];
                self::enforce_rate_limit( $user_id );

                $slug = sanitize_title( $input['jobSlug'] ?? '' );
                $job = get_page_by_path( $slug, OBJECT, 'career' );
                if ( ! $job || 'publish' !== $job->post_status ) {
                    throw new \GraphQL\Error\UserError( 'Başvuru yapılabilecek iş ilanı bulunamadı.' );
                }

                $deadline = (string) get_post_meta( $job->ID, 'deadline', true );
                if ( $deadline && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $deadline ) && $deadline < gmdate( 'Y-m-d' ) ) {
                    throw new \GraphQL\Error\UserError( 'Bu ilanın başvuru süresi sona ermiş.' );
                }

                $job_company_id = Sektorel_Company_Access::get_content_company_id( $job );
                if ( $job_company_id && $context['company_id'] && (int) $job_company_id === (int) $context['company_id'] ) {
                    throw new \GraphQL\Error\UserError( 'Firmanızın kendi iş ilanına başvuramazsınız.' );
                }
                if ( ! $job_company_id && (int) $job->post_author === $user_id ) {
                    throw new \GraphQL\Error\UserError( 'Kendi iş ilanınıza başvuramazsınız.' );
                }

                $existing = get_posts( array(
                    'post_type'      => 'job_application',
                    'post_status'    => 'publish',
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'author'         => $user_id,
                    'meta_query'     => array(
                        'relation' => 'AND',
                        array( 'key' => 'job_id', 'value' => (int) $job->ID, 'type' => 'NUMERIC' ),
                        array( 'key' => 'application_status', 'value' => array( 'pending', 'reviewing', 'accepted' ), 'compare' => 'IN' ),
                    ),
                ) );
                if ( ! empty( $existing ) ) {
                    throw new \GraphQL\Error\UserError( 'Bu ilana daha önce başvurdunuz.' );
                }

                $full_name = sanitize_text_field( $input['fullName'] ?? '' );
                $email = sanitize_email( $input['email'] ?? '' );
                $phone = sanitize_text_field( $input['phone'] ?? '' );
                $city = sanitize_text_field( $input['city'] ?? '' );
                $cover_letter = sanitize_textarea_field( $input['coverLetter'] ?? '' );

                if ( mb_strlen( $full_name ) < 3 ) {
                    throw new \GraphQL\Error\UserError( 'Ad soyad en az 3 karakter olmalıdır.' );
                }
                if ( ! is_email( $email ) ) {
                    throw new \GraphQL\Error\UserError( 'Geçerli bir e-posta adresi girin.' );
                }
                if ( mb_strlen( preg_replace( '/\D+/', '', $phone ) ) < 10 ) {
                    throw new \GraphQL\Error\UserError( 'Geçerli bir telefon numarası girin.' );
                }
                if ( mb_strlen( $cover_letter ) > 5000 ) {
                    throw new \GraphQL\Error\UserError( 'Ön yazı en fazla 5000 karakter olabilir.' );
                }

                $file = Sektorel_Job_Application_Files::consume_upload_token(
                    $user_id,
                    sanitize_text_field( $input['cvToken'] ?? '' )
                );
                if ( is_wp_error( $file ) ) {
                    throw new \GraphQL\Error\UserError( $file->get_error_message() );
                }

                $application_id = wp_insert_post( array(
                    'post_type'    => 'job_application',
                    'post_status'  => 'publish',
                    'post_author'  => $user_id,
                    'post_title'   => sprintf( '%s – %s', $full_name, get_the_title( $job ) ),
                    'post_content' => '',
                ), true );

                if ( is_wp_error( $application_id ) ) {
                    Sektorel_Job_Application_Files::delete_file( $file['path'] );
                    throw new \GraphQL\Error\UserError( 'Başvuru kaydı oluşturulamadı.' );
                }

                update_post_meta( $application_id, 'job_id', (int) $job->ID );
                update_post_meta( $application_id, 'applicant_name', $full_name );
                update_post_meta( $application_id, 'applicant_email', $email );
                update_post_meta( $application_id, 'applicant_phone', $phone );
                update_post_meta( $application_id, 'applicant_city', $city );
                update_post_meta( $application_id, 'cover_letter', $cover_letter );
                update_post_meta( $application_id, 'application_status', 'pending' );
                update_post_meta( $application_id, 'cv_file_path', $file['path'] );
                update_post_meta( $application_id, 'cv_file_name', $file['name'] );
                update_post_meta( $application_id, 'cv_file_mime', $file['mime'] );
                update_post_meta( $application_id, 'cv_file_size', (int) $file['size'] );

                self::notify_job_owner( $job, $full_name, $email );

                return array(
                    'success'       => true,
                    'message'       => 'Başvurunuz işverene iletildi.',
                    'applicationId' => (int) $application_id,
                );
            },
        ) );

        register_graphql_mutation( 'updateSektorelJobApplicationStatus', array(
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
                if ( ! $job || ! Sektorel_Company_Access::can_edit_post( $job, $context ) ) {
                    throw new \GraphQL\Error\UserError( 'Bu başvuruyu yönetme yetkiniz yok.' );
                }

                $status = sanitize_key( $input['status'] ?? '' );
                if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
                    throw new \GraphQL\Error\UserError( 'Geçersiz başvuru durumu.' );
                }

                update_post_meta( $application->ID, 'application_status', $status );
                self::notify_applicant_status( $application, $job, $status );

                return array(
                    'success'     => true,
                    'message'     => 'Başvuru durumu güncellendi.',
                    'application' => self::format_application( $application ),
                );
            },
        ) );
    }

    public static function format_application( $application ) {
        if ( ! $application instanceof WP_Post ) {
            $application = get_post( $application );
        }
        if ( ! $application ) {
            return null;
        }

        $job_id = (int) get_post_meta( $application->ID, 'job_id', true );
        $job = get_post( $job_id );

        return array(
            'databaseId'     => (int) $application->ID,
            'jobDatabaseId'  => $job_id,
            'jobTitle'       => $job ? get_the_title( $job ) : 'Silinmiş iş ilanı',
            'jobSlug'        => $job ? $job->post_name : '',
            'applicantName'  => (string) get_post_meta( $application->ID, 'applicant_name', true ),
            'applicantEmail' => (string) get_post_meta( $application->ID, 'applicant_email', true ),
            'applicantPhone' => (string) get_post_meta( $application->ID, 'applicant_phone', true ),
            'applicantCity'  => (string) get_post_meta( $application->ID, 'applicant_city', true ),
            'coverLetter'    => (string) get_post_meta( $application->ID, 'cover_letter', true ),
            'cvFileName'     => (string) get_post_meta( $application->ID, 'cv_file_name', true ),
            'cvDownloadUrl'  => rest_url( 'sektorel/v1/job-applications/' . (int) $application->ID . '/cv' ),
            'status'         => (string) get_post_meta( $application->ID, 'application_status', true ),
            'date'           => get_post_time( DATE_ATOM, true, $application ),
        );
    }

    public static function can_access_application( $application_id, $user_id = 0 ) {
        $application = get_post( (int) $application_id );
        if ( ! $application || 'job_application' !== $application->post_type || 'publish' !== $application->post_status ) {
            return false;
        }

        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }

        if ( (int) $application->post_author === (int) $user_id ) {
            return true;
        }

        $job = get_post( (int) get_post_meta( $application->ID, 'job_id', true ) );
        if ( ! $job ) {
            return false;
        }

        $context = Sektorel_Company_Access::get_context( $user_id );
        return ! empty( $context['can_edit'] ) && Sektorel_Company_Access::can_view_post( $job, $context );
    }

    private static function enforce_rate_limit( $user_id ) {
        $key = 'sektorel_job_application_' . (int) $user_id;
        $count = (int) get_transient( $key );
        if ( $count >= 10 ) {
            throw new \GraphQL\Error\UserError( 'Çok fazla başvuru yaptınız. Lütfen bir saat sonra tekrar deneyin.' );
        }
        set_transient( $key, $count + 1, HOUR_IN_SECONDS );
    }

    private static function notify_job_owner( $job, $applicant_name, $applicant_email ) {
        $company_id = Sektorel_Company_Access::get_content_company_id( $job );
        $owner_id = $company_id
            ? Sektorel_Company_Access::get_company_owner_id( $company_id )
            : (int) $job->post_author;
        $owner = get_userdata( $owner_id );
        if ( ! $owner || ! is_email( $owner->user_email ) ) {
            return;
        }

        wp_mail(
            $owner->user_email,
            'Yeni iş başvurusu: ' . get_the_title( $job ),
            sprintf(
                "%s (%s) ilanınıza başvurdu. Başvuruyu Sektörel Ajanda hesabınızdan inceleyebilirsiniz.",
                $applicant_name,
                $applicant_email
            )
        );
    }

    private static function notify_applicant_status( $application, $job, $status ) {
        $email = (string) get_post_meta( $application->ID, 'applicant_email', true );
        if ( ! is_email( $email ) ) {
            return;
        }

        $labels = array(
            'pending'   => 'alındı',
            'reviewing' => 'incelemeye alındı',
            'accepted'  => 'olumlu sonuçlandı',
            'rejected'  => 'olumsuz sonuçlandı',
        );

        wp_mail(
            $email,
            'İş başvurusu durumu güncellendi',
            sprintf(
                '“%s” ilanına yaptığınız başvuru %s.',
                get_the_title( $job ),
                $labels[ $status ] ?? 'güncellendi'
            )
        );
    }
}
