<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_GraphQL_Types {

    public static function register() {
        
        // Firma objesine 'fullAddress' adında hesaplanmış bir alan ekleyelim
        register_graphql_field( 'Company', 'fullAddress', array(
            'type' => 'String',
            'description' => 'Şehir, İlçe ve Açık adresi birleştirir.',
            'resolve' => function( $post ) {
                // ACF verisini al
                $address = get_field( 'address', $post->ID );
                
                // Taxonomy (Şehir) verisini al
                $terms = get_the_terms( $post->ID, 'location' );
                $city = '';
                if ( $terms && ! is_wp_error( $terms ) ) {
                    $city = $terms[0]->name;
                }

                return $address . ', ' . $city;
            }
        ));

        // İleride buraya başka özel Resolver'lar eklenebilir.
        // Örn: Bir firmanın aktif ilan sayısını döndüren 'activeLeadCount' alanı.
    }
}