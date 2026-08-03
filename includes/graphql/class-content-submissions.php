<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Content_Submissions {

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_mutations' ) );
    }

    public static function register_mutations() {
        self::register_lead_mutation();
        self::register_job_mutation();
        self::register_event_mutation();
    }

    private static function register_lead_mutation() {
        register_graphql_mutation( 'submitSektorelLead', array(
            'inputFields' => array(
                'title'            => array( 'type' => array( 'non_null' => 'String' ) ),
                'description'      => array( 'type' => 'String' ),
                'leadType'         => array( 'type' => 'String' ),
                'budgetString'     => array( 'type' => 'String' ),
                'expiryDate'       => array( 'type' => 'String' ),
                'deliveryLocation' => array( 'type' => 'String' ),
                'sector'           => array( 'type' => 'String' ),
                'isHiddenName'     => array( 'type' => 'Boolean' ),
            ),
            'outputFields' => self::output_fields(),
            'mutateAndGetPayload' => function( $input ) {
                $context = Sektorel_Company_Access::require_context( true );
                self::enforce_rate_limit( $context['user_id'], 'lead' );
                $post_id = self::create_post( $context, 'lead', $input );

                $allowed_types = array( 'alim', 'hizmet', 'bayilik', 'ortaklik', 'satis' );
                $lead_type = sanitize_key( $input['leadType'] ?? 'alim' );
                if ( ! in_array( $lead_type, $allowed_types, true ) ) {
                    $lead_type = 'alim';
                }

                update_post_meta( $post_id, 'lead_type', $lead_type );
                update_post_meta( $post_id, 'status', 'pending' );
                update_post_meta( $post_id, 'budget_string', sanitize_text_field( $input['budgetString'] ?? '' ) );
                update_post_meta( $post_id, 'expiry_date', self::sanitize_date( $input['expiryDate'] ?? '' ) );
                update_post_meta( $post_id, 'delivery_location', sanitize_text_field( $input['deliveryLocation'] ?? '' ) );
                update_post_meta( $post_id, 'is_hidden_name', ! empty( $input['isHiddenName'] ) ? 1 : 0 );
                update_post_meta( $post_id, 'is_premium', 0 );
                update_post_meta( $post_id, 'view_count', 0 );
                update_post_meta( $post_id, 'offer_count', 0 );
                self::assign_sector( $post_id, $input['sector'] ?? '' );

                return self::success_payload( $post_id, 'Talebiniz onaya gönderildi.' );
            },
        ) );
    }

    private static function register_job_mutation() {
        register_graphql_mutation( 'submitSektorelJob', array(
            'inputFields' => array(
                'title'       => array( 'type' => array( 'non_null' => 'String' ) ),
                'description' => array( 'type' => 'String' ),
                'companyName' => array( 'type' => 'String' ),
                'location'    => array( 'type' => 'String' ),
                'workType'    => array( 'type' => 'String' ),
                'experience'  => array( 'type' => 'String' ),
                'education'   => array( 'type' => 'String' ),
                'salary'      => array( 'type' => 'String' ),
                'deadline'    => array( 'type' => 'String' ),
                'sector'      => array( 'type' => 'String' ),
            ),
            'outputFields' => self::output_fields(),
            'mutateAndGetPayload' => function( $input ) {
                $context = Sektorel_Company_Access::require_context( true );
                self::enforce_rate_limit( $context['user_id'], 'career' );
                $post_id = self::create_post( $context, 'career', $input );

                $company_name = sanitize_text_field( $input['companyName'] ?? '' );
                if ( ! $company_name && $context['company_id'] ) {
                    $company_name = get_the_title( $context['company_id'] );
                }

                update_post_meta( $post_id, 'company_name', $company_name );
                update_post_meta( $post_id, 'location', sanitize_text_field( $input['location'] ?? '' ) );
                update_post_meta( $post_id, 'work_type', sanitize_text_field( $input['workType'] ?? '' ) );
                update_post_meta( $post_id, 'experience', sanitize_text_field( $input['experience'] ?? '' ) );
                update_post_meta( $post_id, 'education', sanitize_text_field( $input['education'] ?? '' ) );
                update_post_meta( $post_id, 'salary', sanitize_text_field( $input['salary'] ?? '' ) );
                update_post_meta( $post_id, 'deadline', self::sanitize_date( $input['deadline'] ?? '' ) );
                update_post_meta( $post_id, 'is_featured', 0 );
                self::assign_sector( $post_id, $input['sector'] ?? '' );

                return self::success_payload( $post_id, 'İş ilanınız onaya gönderildi.' );
            },
        ) );
    }

    private static function register_event_mutation() {
        register_graphql_mutation( 'submitSektorelEvent', array(
            'inputFields' => array(
                'title'            => array( 'type' => array( 'non_null' => 'String' ) ),
                'description'      => array( 'type' => 'String' ),
                'eventType'        => array( 'type' => 'String' ),
                'startDate'        => array( 'type' => array( 'non_null' => 'String' ) ),
                'endDate'          => array( 'type' => 'String' ),
                'locationType'     => array( 'type' => 'String' ),
                'venue'            => array( 'type' => 'String' ),
                'address'          => array( 'type' => 'String' ),
                'organizer'        => array( 'type' => 'String' ),
                'price'            => array( 'type' => 'String' ),
                'registrationLink' => array( 'type' => 'String' ),
            ),
            'outputFields' => self::output_fields(),
            'mutateAndGetPayload' => function( $input ) {
                $context = Sektorel_Company_Access::require_context( true );
                self::enforce_rate_limit( $context['user_id'], 'event' );
                $post_id = self::create_post( $context, 'event', $input );

                update_post_meta( $post_id, 'is_official', 0 );
                update_post_meta( $post_id, 'event_type', sanitize_key( $input['eventType'] ?? 'etkinlik' ) );
                update_post_meta( $post_id, 'start_date', sanitize_text_field( $input['startDate'] ?? '' ) );
                update_post_meta( $post_id, 'end_date', sanitize_text_field( $input['endDate'] ?? '' ) );
                update_post_meta( $post_id, 'location_type', sanitize_key( $input['locationType'] ?? 'physical' ) );
                update_post_meta( $post_id, 'venue', sanitize_text_field( $input['venue'] ?? '' ) );
                update_post_meta( $post_id, 'address', sanitize_text_field( $input['address'] ?? '' ) );
                update_post_meta( $post_id, 'organizer', sanitize_text_field( $input['organizer'] ?? '' ) );
                update_post_meta( $post_id, 'price', sanitize_text_field( $input['price'] ?? '' ) );
                update_post_meta( $post_id, 'registration_link', esc_url_raw( $input['registrationLink'] ?? '' ) );

                return self::success_payload( $post_id, 'Etkinliğiniz onaya gönderildi.' );
            },
        ) );
    }

    private static function output_fields() {
        return array(
            'success' => array( 'type' => 'Boolean' ),
            'message' => array( 'type' => 'String' ),
            'postId'  => array( 'type' => 'Int' ),
        );
    }

    private static function create_post( $context, $post_type, $input ) {
        $title = sanitize_text_field( $input['title'] ?? '' );
        $description = wp_kses_post( $input['description'] ?? '' );

        if ( mb_strlen( $title ) < 5 ) {
            throw new \GraphQL\Error\UserError( 'Başlık en az 5 karakter olmalıdır.' );
        }
        if ( mb_strlen( wp_strip_all_tags( $description ) ) < 20 ) {
            throw new \GraphQL\Error\UserError( 'Açıklama en az 20 karakter olmalıdır.' );
        }

        $post_id = wp_insert_post( array(
            'post_type'    => $post_type,
            'post_status'  => 'pending',
            'post_author'  => (int) $context['user_id'],
            'post_title'   => $title,
            'post_content' => $description,
        ), true );

        if ( is_wp_error( $post_id ) ) {
            throw new \GraphQL\Error\UserError( 'İçerik oluşturulamadı.' );
        }

        Sektorel_Company_Access::attach_post_to_context( $post_id, $context );
        return (int) $post_id;
    }

    private static function enforce_rate_limit( $user_id, $post_type ) {
        $key = 'sektorel_submit_' . $post_type . '_' . $user_id;
        $count = (int) get_transient( $key );
        if ( $count >= 5 ) {
            throw new \GraphQL\Error\UserError( 'Çok fazla gönderim yaptınız. Lütfen bir saat sonra tekrar deneyin.' );
        }
        set_transient( $key, $count + 1, HOUR_IN_SECONDS );
    }

    private static function assign_sector( $post_id, $value ) {
        $value = sanitize_text_field( $value );
        if ( ! $value ) return;
        $term = get_term_by( 'slug', $value, 'sector' ) ?: get_term_by( 'name', $value, 'sector' );
        if ( $term && ! is_wp_error( $term ) ) {
            wp_set_object_terms( $post_id, array( (int) $term->term_id ), 'sector' );
        }
    }

    private static function sanitize_date( $date ) {
        $date = sanitize_text_field( $date );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
    }

    private static function success_payload( $post_id, $message ) {
        return array(
            'success' => true,
            'message' => $message,
            'postId'  => (int) $post_id,
        );
    }
}
