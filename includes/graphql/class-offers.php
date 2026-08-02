<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Offers {

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_types' ) );
    }

    public static function register_types() {
        register_graphql_object_type( 'SektorelOfferItem', array(
            'fields' => array(
                'databaseId'       => array( 'type' => 'Int' ),
                'leadDatabaseId'   => array( 'type' => 'Int' ),
                'leadTitle'        => array( 'type' => 'String' ),
                'leadSlug'         => array( 'type' => 'String' ),
                'bidderName'       => array( 'type' => 'String' ),
                'bidderCompany'    => array( 'type' => 'String' ),
                'amount'           => array( 'type' => 'String' ),
                'currency'         => array( 'type' => 'String' ),
                'deliveryDays'     => array( 'type' => 'Int' ),
                'validityDays'     => array( 'type' => 'Int' ),
                'includesShipping' => array( 'type' => 'Boolean' ),
                'message'          => array( 'type' => 'String' ),
                'status'           => array( 'type' => 'String' ),
                'date'             => array( 'type' => 'String' ),
            ),
        ) );

        register_graphql_field( 'RootQuery', 'sektorelIncomingOffers', array(
            'type' => array( 'list_of' => 'SektorelOfferItem' ),
            'resolve' => function() {
                $context = self::require_company_owner();
                $lead_posts = Sektorel_Company_Access::get_accessible_posts(
                    $context['user_id'],
                    array( 'lead' ),
                    200
                );
                $lead_ids = array_map( function( $lead ) {
                    return (int) $lead->ID;
                }, $lead_posts );

                if ( empty( $lead_ids ) ) {
                    return array();
                }

                $offers = get_posts( array(
                    'post_type'      => 'offer',
                    'post_status'    => 'publish',
                    'posts_per_page' => 200,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                    'meta_query'     => array(
                        array(
                            'key'     => 'lead_id',
                            'value'   => $lead_ids,
                            'compare' => 'IN',
                            'type'    => 'NUMERIC',
                        ),
                    ),
                ) );

                return array_map( array( __CLASS__, 'format_offer' ), $offers );
            },
        ) );

        register_graphql_mutation( 'submitSektorelOffer', array(
            'inputFields' => array(
                'leadSlug'         => array( 'type' => array( 'non_null' => 'String' ) ),
                'amount'           => array( 'type' => array( 'non_null' => 'String' ) ),
                'currency'         => array( 'type' => 'String' ),
                'deliveryDays'     => array( 'type' => 'Int' ),
                'validityDays'     => array( 'type' => 'Int' ),
                'includesShipping' => array( 'type' => 'Boolean' ),
                'message'          => array( 'type' => 'String' ),
            ),
            'outputFields' => array(
                'success' => array( 'type' => 'Boolean' ),
                'message' => array( 'type' => 'String' ),
                'offerId' => array( 'type' => 'Int' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $context = Sektorel_Company_Access::require_context( false );
                $user_id = (int) $context['user_id'];
                $slug = sanitize_title( $input['leadSlug'] ?? '' );
                $lead = get_page_by_path( $slug, OBJECT, 'lead' );

                if ( ! $lead || 'publish' !== $lead->post_status ) {
                    throw new \GraphQL\Error\UserError( 'Teklif verilebilecek ilan bulunamadı.' );
                }

                $lead_company_id = Sektorel_Company_Access::get_content_company_id( $lead );
                if ( $lead_company_id && $context['company_id'] && (int) $lead_company_id === (int) $context['company_id'] ) {
                    throw new \GraphQL\Error\UserError( 'Firmanızın kendi ilanına teklif veremezsiniz.' );
                }
                if ( ! $lead_company_id && (int) $lead->post_author === $user_id ) {
                    throw new \GraphQL\Error\UserError( 'Kendi ilanınıza teklif veremezsiniz.' );
                }

                $existing = get_posts( array(
                    'post_type'      => 'offer',
                    'post_status'    => 'publish',
                    'author'         => $user_id,
                    'posts_per_page' => 1,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        'relation' => 'AND',
                        array( 'key' => 'lead_id', 'value' => (int) $lead->ID, 'type' => 'NUMERIC' ),
                        array( 'key' => 'offer_status', 'value' => array( 'pending', 'accepted' ), 'compare' => 'IN' ),
                    ),
                ) );

                if ( ! empty( $existing ) ) {
                    throw new \GraphQL\Error\UserError( 'Bu ilana daha önce teklif verdiniz.' );
                }

                $amount = self::sanitize_amount( $input['amount'] ?? '' );
                if ( '' === $amount || (float) $amount <= 0 ) {
                    throw new \GraphQL\Error\UserError( 'Geçerli bir teklif tutarı girin.' );
                }

                $currency = strtoupper( sanitize_key( $input['currency'] ?? 'TRY' ) );
                if ( ! in_array( $currency, array( 'TRY', 'USD', 'EUR' ), true ) ) {
                    $currency = 'TRY';
                }

                $delivery_days = max( 1, min( 365, (int) ( $input['deliveryDays'] ?? 1 ) ) );
                $validity_days = max( 1, min( 90, (int) ( $input['validityDays'] ?? 7 ) ) );
                $message = sanitize_textarea_field( $input['message'] ?? '' );

                $offer_id = wp_insert_post( array(
                    'post_type'    => 'offer',
                    'post_status'  => 'publish',
                    'post_author'  => $user_id,
                    'post_title'   => sprintf( '%s için teklif', get_the_title( $lead ) ),
                    'post_content' => $message,
                ), true );

                if ( is_wp_error( $offer_id ) ) {
                    throw new \GraphQL\Error\UserError( 'Teklif oluşturulamadı.' );
                }

                update_post_meta( $offer_id, 'lead_id', (int) $lead->ID );
                update_post_meta( $offer_id, 'amount', $amount );
                update_post_meta( $offer_id, 'currency', $currency );
                update_post_meta( $offer_id, 'delivery_days', $delivery_days );
                update_post_meta( $offer_id, 'validity_days', $validity_days );
                update_post_meta( $offer_id, 'includes_shipping', ! empty( $input['includesShipping'] ) ? 1 : 0 );
                update_post_meta( $offer_id, 'offer_status', 'pending' );
                if ( $context['company_id'] ) {
                    update_post_meta( $offer_id, '_sektorel_bidder_company_id', (int) $context['company_id'] );
                }

                self::refresh_offer_count( (int) $lead->ID );

                return array(
                    'success' => true,
                    'message' => 'Teklifiniz ilan sahibine gönderildi.',
                    'offerId' => (int) $offer_id,
                );
            },
        ) );

        register_graphql_mutation( 'updateSektorelOfferStatus', array(
            'inputFields' => array(
                'offerId' => array( 'type' => array( 'non_null' => 'Int' ) ),
                'status'  => array( 'type' => array( 'non_null' => 'String' ) ),
            ),
            'outputFields' => array(
                'success' => array( 'type' => 'Boolean' ),
                'message' => array( 'type' => 'String' ),
                'offer'   => array( 'type' => 'SektorelOfferItem' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $context = self::require_company_owner();
                $offer_id = (int) ( $input['offerId'] ?? 0 );
                $offer = get_post( $offer_id );

                if ( ! $offer || 'offer' !== $offer->post_type || 'publish' !== $offer->post_status ) {
                    throw new \GraphQL\Error\UserError( 'Teklif bulunamadı.' );
                }

                $lead_id = (int) get_post_meta( $offer_id, 'lead_id', true );
                $lead = get_post( $lead_id );
                if ( ! $lead || ! Sektorel_Company_Access::can_view_post( $lead, $context ) ) {
                    throw new \GraphQL\Error\UserError( 'Bu teklifi yönetme yetkiniz yok.' );
                }

                $status = sanitize_key( $input['status'] ?? '' );
                if ( ! in_array( $status, array( 'accepted', 'rejected' ), true ) ) {
                    throw new \GraphQL\Error\UserError( 'Geçersiz teklif durumu.' );
                }

                update_post_meta( $offer_id, 'offer_status', $status );

                return array(
                    'success' => true,
                    'message' => 'Teklif durumu güncellendi.',
                    'offer'   => self::format_offer( $offer ),
                );
            },
        ) );
    }

    public static function format_offer( $offer ) {
        $lead_id = (int) get_post_meta( $offer->ID, 'lead_id', true );
        $lead = get_post( $lead_id );
        $bidder = get_userdata( (int) $offer->post_author );
        $company_id = (int) get_post_meta( $offer->ID, '_sektorel_bidder_company_id', true );
        if ( ! $company_id ) {
            $company_id = Sektorel_Session_Query::get_accessible_company_id( (int) $offer->post_author );
        }

        return array(
            'databaseId'       => (int) $offer->ID,
            'leadDatabaseId'   => $lead_id,
            'leadTitle'        => $lead ? get_the_title( $lead ) : 'Silinmiş ilan',
            'leadSlug'         => $lead ? $lead->post_name : '',
            'bidderName'       => $bidder ? $bidder->display_name : 'Üye',
            'bidderCompany'    => $company_id ? get_the_title( $company_id ) : '',
            'amount'           => (string) get_post_meta( $offer->ID, 'amount', true ),
            'currency'         => (string) get_post_meta( $offer->ID, 'currency', true ),
            'deliveryDays'     => (int) get_post_meta( $offer->ID, 'delivery_days', true ),
            'validityDays'     => (int) get_post_meta( $offer->ID, 'validity_days', true ),
            'includesShipping' => '1' === (string) get_post_meta( $offer->ID, 'includes_shipping', true ),
            'message'          => $offer->post_content,
            'status'           => (string) get_post_meta( $offer->ID, 'offer_status', true ),
            'date'             => get_post_time( DATE_ATOM, true, $offer ),
        );
    }

    private static function require_company_owner() {
        $context = Sektorel_Company_Access::require_context( false );
        if ( empty( $context['is_owner'] ) || empty( $context['company_id'] ) ) {
            throw new \GraphQL\Error\UserError( 'Gelen teklifleri yalnızca firma sahibi yönetebilir.' );
        }
        return $context;
    }

    private static function sanitize_amount( $value ) {
        $value = str_replace( ',', '.', sanitize_text_field( $value ) );
        return preg_match( '/^\d+(?:\.\d{1,2})?$/', $value ) ? $value : '';
    }

    private static function refresh_offer_count( $lead_id ) {
        $count = count( get_posts( array(
            'post_type'      => 'offer',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'meta_query'     => array(
                array( 'key' => 'lead_id', 'value' => $lead_id, 'type' => 'NUMERIC' ),
            ),
        ) ) );
        update_post_meta( $lead_id, 'offer_count', $count );
    }
}
