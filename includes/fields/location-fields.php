<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Location_Fields {

    public static function init() {
        // Yeni Ekleme Formu
        add_action( 'location_add_form_fields', array( __CLASS__, 'add_form_fields' ) );
        // Düzenleme Formu
        add_action( 'location_edit_form_fields', array( __CLASS__, 'edit_form_fields' ) );
        
        // Kaydetme
        add_action( 'created_location', array( __CLASS__, 'save_term_meta' ) );
        add_action( 'edited_location', array( __CLASS__, 'save_term_meta' ) );
        
        // GraphQL
        add_action( 'graphql_register_types', array( __CLASS__, 'register_graphql_fields' ) );
    }

    // Yeni Ekleme Formu HTML
    public static function add_form_fields() {
        ?>
        <div class="form-field">
            <label for="location_type">Lokasyon Tipi</label>
            <select name="location_type" id="location_type">
                <option value="country">Ülke</option>
                <option value="city">Şehir / İl</option>
                <option value="district">İlçe / Semt</option>
            </select>
            <p>Bu lokasyonun hiyerarşik seviyesini belirler.</p>
        </div>
        <div class="form-field">
            <label for="map_lat">Enlem (Latitude)</label>
            <input type="text" name="map_lat" id="map_lat" value="">
        </div>
        <div class="form-field">
            <label for="map_lng">Boylam (Longitude)</label>
            <input type="text" name="map_lng" id="map_lng" value="">
        </div>
        <?php
    }

    // Düzenleme Formu HTML
    public static function edit_form_fields( $term ) {
        $type = get_term_meta( $term->term_id, 'location_type', true );
        $lat = get_term_meta( $term->term_id, 'map_lat', true );
        $lng = get_term_meta( $term->term_id, 'map_lng', true );
        ?>
        <tr class="form-field">
            <th scope="row"><label for="location_type">Lokasyon Tipi</label></th>
            <td>
                <select name="location_type" id="location_type">
                    <option value="country" <?php selected( $type, 'country' ); ?>>Ülke</option>
                    <option value="city" <?php selected( $type, 'city' ); ?>>Şehir / İl</option>
                    <option value="district" <?php selected( $type, 'district' ); ?>>İlçe / Semt</option>
                </select>
                <p class="description">Bu lokasyonun hiyerarşik seviyesini belirler.</p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="map_lat">Enlem (Latitude)</label></th>
            <td>
                <input type="text" name="map_lat" id="map_lat" value="<?php echo esc_attr( $lat ); ?>">
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="map_lng">Boylam (Longitude)</label></th>
            <td>
                <input type="text" name="map_lng" id="map_lng" value="<?php echo esc_attr( $lng ); ?>">
            </td>
        </tr>
        <?php
    }

    // Kaydetme
    public static function save_term_meta( $term_id ) {
        if ( isset( $_POST['location_type'] ) ) {
            update_term_meta( $term_id, 'location_type', sanitize_text_field( $_POST['location_type'] ) );
        }
        if ( isset( $_POST['map_lat'] ) ) {
            update_term_meta( $term_id, 'map_lat', sanitize_text_field( $_POST['map_lat'] ) );
        }
        if ( isset( $_POST['map_lng'] ) ) {
            update_term_meta( $term_id, 'map_lng', sanitize_text_field( $_POST['map_lng'] ) );
        }
    }

    // GraphQL Şeması
    public static function register_graphql_fields() {
        register_graphql_field( 'Location', 'locationDetails', array(
            'type' => 'LocationDetails',
            'description' => 'Lokasyon tipi ve koordinatları',
            'resolve' => function( $term ) {
                return array(
                    'type' => get_term_meta( $term->term_id, 'location_type', true ),
                    'lat'  => get_term_meta( $term->term_id, 'map_lat', true ),
                    'lng'  => get_term_meta( $term->term_id, 'map_lng', true ),
                );
            }
        ));

        register_graphql_object_type( 'LocationDetails', array(
            'fields' => array(
                'type' => array( 'type' => 'String' ),
                'lat'  => array( 'type' => 'String' ),
                'lng'  => array( 'type' => 'String' ),
            ),
        ));
    }
}