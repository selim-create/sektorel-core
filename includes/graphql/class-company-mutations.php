<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Company_Mutations {

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_mutations' ) );
    }

    public static function register_mutations() {
        register_graphql_mutation( 'submitCompany', array(
            'description' => 'Frontend üzerinden yeni firma ekler (Onay bekleyen).',
            'inputFields' => array(
                'title'       => array( 'type' => 'String', 'description' => 'Firma Adı' ),
                'officialName'=> array( 'type' => 'String', 'description' => 'Resmi Ünvan' ),
                'sector'      => array( 'type' => 'String', 'description' => 'Ana Sektör Slug' ),
                'companyType' => array( 'type' => 'String', 'description' => 'Ltd, A.Ş. vb.' ),
                'description' => array( 'type' => 'String', 'description' => 'Hakkımızda' ),
                'email'       => array( 'type' => 'String' ),
                'phone'       => array( 'type' => 'String' ),
                'website'     => array( 'type' => 'String' ),
                'city'        => array( 'type' => 'String' ),
                'district'    => array( 'type' => 'String' ),
                'postalCode'  => array( 'type' => 'String' ), // EKLENDİ
                'address'     => array( 'type' => 'String' ),
            ),
            'outputFields' => array(
                'success' => array( 'type' => 'Boolean' ),
                'message' => array( 'type' => 'String' ),
                'postId'  => array( 'type' => 'ID' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                try {
                    $title = isset($input['title']) ? sanitize_text_field($input['title']) : '(İsimsiz Firma)';
                    $desc = isset($input['description']) ? wp_kses_post($input['description']) : '';

                    // 1. Post Oluştur (Pending durumunda)
                    $post_data = array(
                        'post_title'   => $title,
                        'post_content' => $desc,
                        'post_status'  => 'pending',
                        'post_type'    => 'company'
                    );

                    $post_id = wp_insert_post( $post_data );

                    if ( is_wp_error( $post_id ) ) {
                        error_log('Sektorel Mutation Error: ' . $post_id->get_error_message());
                        return array( 'success' => false, 'message' => 'Firma oluşturulamadı: ' . $post_id->get_error_message() );
                    }

                    // 2. Meta Verileri Kaydet
                    if (isset($input['companyType'])) update_post_meta( $post_id, 'company_type', sanitize_text_field( $input['companyType'] ) );
                    if (isset($input['officialName'])) update_post_meta( $post_id, 'official_name', sanitize_text_field( $input['officialName'] ) ); // official_name field'ı company-fields.php'de tanımlı olmayabilir, company_type gibi custom field ise tanımlanmalı.
                    
                    if (isset($input['email'])) update_post_meta( $post_id, 'email', sanitize_email( $input['email'] ) );
                    if (isset($input['phone'])) update_post_meta( $post_id, 'phone', sanitize_text_field( $input['phone'] ) );
                    if (isset($input['website'])) update_post_meta( $post_id, 'website', esc_url_raw( $input['website'] ) );
                    if (isset($input['postalCode'])) update_post_meta( $post_id, 'postal_code', sanitize_text_field( $input['postalCode'] ) ); // EKLENDİ
                    if (isset($input['address'])) update_post_meta( $post_id, 'address', sanitize_textarea_field( $input['address'] ) );

                    // 3. Taksonomiler
                    if ( ! empty( $input['sector'] ) ) {
                        $sector_term = get_term_by( 'slug', $input['sector'], 'sector' );
                        // Slug ile bulamazsa (veya slug name gibi geldiyse)
                        if ( ! $sector_term ) $sector_term = get_term_by( 'name', $input['sector'], 'sector' );
                        
                        if ( $sector_term ) {
                            wp_set_object_terms( $post_id, (int)$sector_term->term_id, 'sector' );
                        }
                    }

                    if ( ! empty( $input['city'] ) ) {
                        $city_term = get_term_by( 'slug', $input['city'], 'location' );
                        if (!$city_term) $city_term = get_term_by( 'name', $input['city'], 'location' );

                        if ( $city_term ) {
                            $terms = array( (int)$city_term->term_id );
                            
                            if ( ! empty( $input['district'] ) ) {
                                $dist_term = get_term_by( 'slug', $input['district'], 'location' );
                                if (!$dist_term) $dist_term = get_term_by( 'name', $input['district'], 'location' );
                                
                                if ( $dist_term ) {
                                    $terms[] = (int)$dist_term->term_id;
                                }
                            }
                            wp_set_object_terms( $post_id, $terms, 'location' );
                        }
                    }

                    return array(
                        'success' => true,
                        'message' => 'Firma başvurunuz alındı. Teşekkürler!',
                        'postId'  => $post_id
                    );

                } catch (Exception $e) {
                    error_log('Sektorel Mutation Exception: ' . $e->getMessage());
                    return array( 'success' => false, 'message' => 'Sunucu hatası: ' . $e->getMessage() );
                }
            }
        ));
    }
}