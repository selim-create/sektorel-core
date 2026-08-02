<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Owned_Content {

    private static $allowed_types = array( 'lead', 'career', 'event' );

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_types' ) );
    }

    public static function register_types() {
        register_graphql_object_type( 'SektorelOwnedContentItem', array(
            'fields' => array(
                'databaseId' => array( 'type' => 'Int' ),
                'title'      => array( 'type' => 'String' ),
                'type'       => array( 'type' => 'String' ),
                'status'     => array( 'type' => 'String' ),
                'date'       => array( 'type' => 'String' ),
                'slug'       => array( 'type' => 'String' ),
            ),
        ) );

        register_graphql_object_type( 'SektorelOwnedContentDetail', array(
            'fields' => array(
                'databaseId'          => array( 'type' => 'Int' ),
                'title'               => array( 'type' => 'String' ),
                'description'         => array( 'type' => 'String' ),
                'type'                => array( 'type' => 'String' ),
                'status'              => array( 'type' => 'String' ),
                'slug'                => array( 'type' => 'String' ),
                'sector'              => array( 'type' => 'String' ),
                'leadType'            => array( 'type' => 'String' ),
                'budgetString'        => array( 'type' => 'String' ),
                'expiryDate'          => array( 'type' => 'String' ),
                'deliveryLocation'    => array( 'type' => 'String' ),
                'isHiddenName'        => array( 'type' => 'Boolean' ),
                'companyName'         => array( 'type' => 'String' ),
                'location'            => array( 'type' => 'String' ),
                'workType'            => array( 'type' => 'String' ),
                'experience'          => array( 'type' => 'String' ),
                'education'           => array( 'type' => 'String' ),
                'salary'              => array( 'type' => 'String' ),
                'deadline'            => array( 'type' => 'String' ),
                'eventType'           => array( 'type' => 'String' ),
                'startDate'           => array( 'type' => 'String' ),
                'endDate'             => array( 'type' => 'String' ),
                'locationType'        => array( 'type' => 'String' ),
                'venue'               => array( 'type' => 'String' ),
                'address'             => array( 'type' => 'String' ),
                'organizer'           => array( 'type' => 'String' ),
                'price'               => array( 'type' => 'String' ),
                'registrationLink'    => array( 'type' => 'String' ),
            ),
        ) );

        register_graphql_field( 'RootQuery', 'sektorelOwnedContent', array(
            'type' => array( 'list_of' => 'SektorelOwnedContentItem' ),
            'args' => array(
                'type' => array( 'type' => 'String' ),
            ),
            'resolve' => function( $root, $args ) {
                $user_id = self::require_user();
                $requested = sanitize_key( $args['type'] ?? '' );
                $post_types = $requested && in_array( $requested, self::$allowed_types, true )
                    ? array( $requested )
                    : self::$allowed_types;

                $posts = get_posts( array(
                    'post_type'      => $post_types,
                    'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
                    'author'         => $user_id,
                    'posts_per_page' => 100,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                ) );

                return array_map( function( $post ) {
                    return array(
                        'databaseId' => (int) $post->ID,
                        'title'      => get_the_title( $post ),
                        'type'       => $post->post_type,
                        'status'     => $post->post_status,
                        'date'       => get_post_time( DATE_ATOM, true, $post ),
                        'slug'       => $post->post_name,
                    );
                }, $posts );
            },
        ) );

        register_graphql_field( 'RootQuery', 'sektorelOwnedContentDetail', array(
            'type' => 'SektorelOwnedContentDetail',
            'args' => array(
                'databaseId' => array( 'type' => array( 'non_null' => 'Int' ) ),
            ),
            'resolve' => function( $root, $args ) {
                $user_id = self::require_user();
                $post = self::get_owned_post( (int) $args['databaseId'], $user_id );
                return self::detail_payload( $post );
            },
        ) );

        register_graphql_mutation( 'updateSektorelOwnedContent', array(
            'inputFields' => array(
                'databaseId'       => array( 'type' => array( 'non_null' => 'Int' ) ),
                'title'            => array( 'type' => array( 'non_null' => 'String' ) ),
                'description'      => array( 'type' => 'String' ),
                'sector'           => array( 'type' => 'String' ),
                'leadType'         => array( 'type' => 'String' ),
                'budgetString'     => array( 'type' => 'String' ),
                'expiryDate'       => array( 'type' => 'String' ),
                'deliveryLocation' => array( 'type' => 'String' ),
                'isHiddenName'     => array( 'type' => 'Boolean' ),
                'companyName'      => array( 'type' => 'String' ),
                'location'         => array( 'type' => 'String' ),
                'workType'         => array( 'type' => 'String' ),
                'experience'       => array( 'type' => 'String' ),
                'education'        => array( 'type' => 'String' ),
                'salary'           => array( 'type' => 'String' ),
                'deadline'         => array( 'type' => 'String' ),
                'eventType'        => array( 'type' => 'String' ),
                'startDate'        => array( 'type' => 'String' ),
                'endDate'          => array( 'type' => 'String' ),
                'locationType'     => array( 'type' => 'String' ),
                'venue'            => array( 'type' => 'String' ),
                'address'          => array( 'type' => 'String' ),
                'organizer'        => array( 'type' => 'String' ),
                'price'            => array( 'type' => 'String' ),
                'registrationLink' => array( 'type' => 'String' ),
            ),
            'outputFields' => array(
                'success' => array( 'type' => 'Boolean' ),
                'message' => array( 'type' => 'String' ),
                'content' => array( 'type' => 'SektorelOwnedContentDetail' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $user_id = self::require_user();
                $post = self::get_owned_post( (int) $input['databaseId'], $user_id );

                $title = sanitize_text_field( $input['title'] ?? '' );
                $description = wp_kses_post( $input['description'] ?? '' );
                if ( mb_strlen( $title ) < 5 ) {
                    throw new \GraphQL\Error\UserError( 'Başlık en az 5 karakter olmalıdır.' );
                }
                if ( mb_strlen( wp_strip_all_tags( $description ) ) < 20 ) {
                    throw new \GraphQL\Error\UserError( 'Açıklama en az 20 karakter olmalıdır.' );
                }

                $updated = wp_update_post( array(
                    'ID'           => $post->ID,
                    'post_title'   => $title,
                    'post_content' => $description,
                    'post_status'  => 'pending',
                ), true );

                if ( is_wp_error( $updated ) ) {
                    throw new \GraphQL\Error\UserError( 'İçerik güncellenemedi.' );
                }

                if ( 'lead' === $post->post_type ) {
                    self::update_lead_meta( $post->ID, $input );
                } elseif ( 'career' === $post->post_type ) {
                    self::update_job_meta( $post->ID, $input );
                } else {
                    self::update_event_meta( $post->ID, $input );
                }

                self::assign_sector( $post->ID, $input['sector'] ?? '' );
                $refreshed = get_post( $post->ID );

                return array(
                    'success' => true,
                    'message' => 'Değişiklikleriniz kaydedildi ve yeniden onaya gönderildi.',
                    'content' => self::detail_payload( $refreshed ),
                );
            },
        ) );

        register_graphql_mutation( 'trashSektorelOwnedContent', array(
            'inputFields' => array(
                'databaseId' => array( 'type' => 'Int' ),
            ),
            'outputFields' => array(
                'success' => array( 'type' => 'Boolean' ),
                'message' => array( 'type' => 'String' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $user_id = self::require_user();
                $post = self::get_owned_post( (int) ( $input['databaseId'] ?? 0 ), $user_id );

                if ( ! wp_trash_post( $post->ID ) ) {
                    throw new \GraphQL\Error\UserError( 'İçerik çöp kutusuna taşınamadı.' );
                }

                return array(
                    'success' => true,
                    'message' => 'İçerik çöp kutusuna taşındı.',
                );
            },
        ) );
    }

    private static function require_user() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            throw new \GraphQL\Error\UserError( 'Bu işlem için giriş yapmanız gerekir.' );
        }
        return (int) $user_id;
    }

    private static function get_owned_post( $post_id, $user_id ) {
        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_type, self::$allowed_types, true ) ) {
            throw new \GraphQL\Error\UserError( 'İçerik bulunamadı.' );
        }
        if ( (int) $post->post_author !== (int) $user_id ) {
            throw new \GraphQL\Error\UserError( 'Bu içerik üzerinde işlem yapma yetkiniz yok.' );
        }
        if ( 'trash' === $post->post_status ) {
            throw new \GraphQL\Error\UserError( 'Çöp kutusundaki içerik düzenlenemez.' );
        }
        return $post;
    }

    private static function detail_payload( $post ) {
        $terms = get_the_terms( $post->ID, 'sector' );
        $sector = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->slug : '';

        return array(
            'databaseId'       => (int) $post->ID,
            'title'            => get_the_title( $post ),
            'description'      => $post->post_content,
            'type'             => $post->post_type,
            'status'           => $post->post_status,
            'slug'             => $post->post_name,
            'sector'           => $sector,
            'leadType'         => get_post_meta( $post->ID, 'lead_type', true ),
            'budgetString'     => get_post_meta( $post->ID, 'budget_string', true ),
            'expiryDate'       => get_post_meta( $post->ID, 'expiry_date', true ),
            'deliveryLocation' => get_post_meta( $post->ID, 'delivery_location', true ),
            'isHiddenName'     => get_post_meta( $post->ID, 'is_hidden_name', true ) === '1',
            'companyName'      => get_post_meta( $post->ID, 'company_name', true ),
            'location'         => get_post_meta( $post->ID, 'location', true ),
            'workType'         => get_post_meta( $post->ID, 'work_type', true ),
            'experience'       => get_post_meta( $post->ID, 'experience', true ),
            'education'        => get_post_meta( $post->ID, 'education', true ),
            'salary'           => get_post_meta( $post->ID, 'salary', true ),
            'deadline'         => get_post_meta( $post->ID, 'deadline', true ),
            'eventType'        => get_post_meta( $post->ID, 'event_type', true ),
            'startDate'        => get_post_meta( $post->ID, 'start_date', true ),
            'endDate'          => get_post_meta( $post->ID, 'end_date', true ),
            'locationType'     => get_post_meta( $post->ID, 'location_type', true ),
            'venue'            => get_post_meta( $post->ID, 'venue', true ),
            'address'          => get_post_meta( $post->ID, 'address', true ),
            'organizer'        => get_post_meta( $post->ID, 'organizer', true ),
            'price'            => get_post_meta( $post->ID, 'price', true ),
            'registrationLink' => get_post_meta( $post->ID, 'registration_link', true ),
        );
    }

    private static function update_lead_meta( $post_id, $input ) {
        $allowed = array( 'alim', 'hizmet', 'bayilik', 'ortaklik', 'satis' );
        $type = sanitize_key( $input['leadType'] ?? 'alim' );
        update_post_meta( $post_id, 'lead_type', in_array( $type, $allowed, true ) ? $type : 'alim' );
        update_post_meta( $post_id, 'status', 'pending' );
        update_post_meta( $post_id, 'budget_string', sanitize_text_field( $input['budgetString'] ?? '' ) );
        update_post_meta( $post_id, 'expiry_date', self::sanitize_date( $input['expiryDate'] ?? '' ) );
        update_post_meta( $post_id, 'delivery_location', sanitize_text_field( $input['deliveryLocation'] ?? '' ) );
        update_post_meta( $post_id, 'is_hidden_name', ! empty( $input['isHiddenName'] ) ? 1 : 0 );
    }

    private static function update_job_meta( $post_id, $input ) {
        update_post_meta( $post_id, 'company_name', sanitize_text_field( $input['companyName'] ?? '' ) );
        update_post_meta( $post_id, 'location', sanitize_text_field( $input['location'] ?? '' ) );
        update_post_meta( $post_id, 'work_type', sanitize_text_field( $input['workType'] ?? '' ) );
        update_post_meta( $post_id, 'experience', sanitize_text_field( $input['experience'] ?? '' ) );
        update_post_meta( $post_id, 'education', sanitize_text_field( $input['education'] ?? '' ) );
        update_post_meta( $post_id, 'salary', sanitize_text_field( $input['salary'] ?? '' ) );
        update_post_meta( $post_id, 'deadline', self::sanitize_date( $input['deadline'] ?? '' ) );
    }

    private static function update_event_meta( $post_id, $input ) {
        update_post_meta( $post_id, 'event_type', sanitize_key( $input['eventType'] ?? 'etkinlik' ) );
        update_post_meta( $post_id, 'start_date', sanitize_text_field( $input['startDate'] ?? '' ) );
        update_post_meta( $post_id, 'end_date', sanitize_text_field( $input['endDate'] ?? '' ) );
        update_post_meta( $post_id, 'location_type', sanitize_key( $input['locationType'] ?? 'physical' ) );
        update_post_meta( $post_id, 'venue', sanitize_text_field( $input['venue'] ?? '' ) );
        update_post_meta( $post_id, 'address', sanitize_text_field( $input['address'] ?? '' ) );
        update_post_meta( $post_id, 'organizer', sanitize_text_field( $input['organizer'] ?? '' ) );
        update_post_meta( $post_id, 'price', sanitize_text_field( $input['price'] ?? '' ) );
        update_post_meta( $post_id, 'registration_link', esc_url_raw( $input['registrationLink'] ?? '' ) );
    }

    private static function assign_sector( $post_id, $value ) {
        $value = sanitize_text_field( $value );
        if ( ! $value ) {
            wp_set_object_terms( $post_id, array(), 'sector' );
            return;
        }
        $term = get_term_by( 'slug', $value, 'sector' ) ?: get_term_by( 'name', $value, 'sector' );
        if ( $term && ! is_wp_error( $term ) ) {
            wp_set_object_terms( $post_id, array( (int) $term->term_id ), 'sector' );
        }
    }

    private static function sanitize_date( $date ) {
        $date = sanitize_text_field( $date );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
    }
}
