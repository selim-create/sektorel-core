<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Sector_Fields {

    public static function init() {
        // Yeni Sektör Ekleme Formuna Alanları Ekle
        add_action( 'sector_add_form_fields', array( __CLASS__, 'add_form_fields' ) );
        
        // Sektör Düzenleme Formuna Alanları Ekle
        add_action( 'sector_edit_form_fields', array( __CLASS__, 'edit_form_fields' ) );
        
        // Kaydetme İşlemleri (Hem yeni oluştururken hem düzenlerken)
        add_action( 'created_sector', array( __CLASS__, 'save_term_meta' ) );
        add_action( 'edited_sector', array( __CLASS__, 'save_term_meta' ) );
        
        // GraphQL Entegrasyonu
        add_action( 'graphql_register_types', array( __CLASS__, 'register_graphql_fields' ) );
    }

    // Yeni Sektör Ekleme Ekranındaki Form Alanları
    public static function add_form_fields() {
        ?>
        <div class="form-field">
            <label for="sector_icon">Sektör İkonu (URL)</label>
            <input type="text" name="sector_icon" id="sector_icon" value="">
            <p>Sektör listesinde görünecek ikon URL'si (SVG veya PNG önerilir).</p>
        </div>
        <div class="form-field">
            <label for="sector_icon_name">İkon Adı (Lucide React)</label>
            <input type="text" name="sector_icon_name" id="sector_icon_name" value="" placeholder="Örn: Building2, Leaf, Cpu">
            <p>Eğer görsel yüklenmezse kullanılacak Lucide React ikon bileşeninin adı.</p>
        </div>
        <div class="form-field">
            <label for="sector_featured_image">Kapak Görseli (URL)</label>
            <input type="text" name="sector_featured_image" id="sector_featured_image" value="">
            <p>Sektör detay sayfasının (Hero alanı) üst kısmındaki geniş görsel URL'si.</p>
        </div>
        <div class="form-field">
            <label for="sector_color">Sektör Rengi</label>
            <input type="color" name="sector_color" id="sector_color" value="#ea580c">
            <p>Sektörü temsil eden ana renk kodu.</p>
        </div>
        <?php
    }

    // Sektör Düzenleme Ekranındaki Form Alanları
    public static function edit_form_fields( $term ) {
        // Mevcut verileri çek
        $icon = get_term_meta( $term->term_id, 'sector_icon', true );
        $icon_name = get_term_meta( $term->term_id, 'sector_icon_name', true );
        $featured_image = get_term_meta( $term->term_id, 'sector_featured_image', true );
        $color = get_term_meta( $term->term_id, 'sector_color', true );
        ?>
        <tr class="form-field">
            <th scope="row"><label for="sector_icon">Sektör İkonu (URL)</label></th>
            <td>
                <input type="text" name="sector_icon" id="sector_icon" value="<?php echo esc_attr( $icon ); ?>">
                <p class="description">Sektör listesinde görünecek ikon URL'si (SVG veya PNG).</p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="sector_icon_name">İkon Adı (Lucide React)</label></th>
            <td>
                <input type="text" name="sector_icon_name" id="sector_icon_name" value="<?php echo esc_attr( $icon_name ); ?>" placeholder="Örn: Building2">
                <p class="description">Eğer görsel yüklenmezse kullanılacak Lucide React ikon bileşeninin adı.</p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="sector_featured_image">Kapak Görseli (URL)</label></th>
            <td>
                <input type="text" name="sector_featured_image" id="sector_featured_image" value="<?php echo esc_attr( $featured_image ); ?>">
                <p class="description">Sektör detay sayfasının (Hero alanı) üst kısmındaki geniş görsel URL'si.</p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="sector_color">Sektör Rengi</label></th>
            <td>
                <input type="color" name="sector_color" id="sector_color" value="<?php echo esc_attr( $color ? $color : '#ea580c' ); ?>">
                <p class="description">Sektörü temsil eden ana renk kodu.</p>
            </td>
        </tr>
        <?php
    }

    // Verileri Veritabanına Kaydetme
    public static function save_term_meta( $term_id ) {
        if ( isset( $_POST['sector_icon'] ) ) {
            update_term_meta( $term_id, 'sector_icon', sanitize_text_field( $_POST['sector_icon'] ) );
        }
        if ( isset( $_POST['sector_icon_name'] ) ) {
            update_term_meta( $term_id, 'sector_icon_name', sanitize_text_field( $_POST['sector_icon_name'] ) );
        }
        if ( isset( $_POST['sector_featured_image'] ) ) {
            update_term_meta( $term_id, 'sector_featured_image', sanitize_text_field( $_POST['sector_featured_image'] ) );
        }
        if ( isset( $_POST['sector_color'] ) ) {
            update_term_meta( $term_id, 'sector_color', sanitize_hex_color( $_POST['sector_color'] ) );
        }
    }

    // GraphQL Şemasına Ekleme
    public static function register_graphql_fields() {
        register_graphql_field( 'Sector', 'sectorDetails', array(
            'type' => 'SectorDetails', // Aşağıda tanımladığımız tip
            'description' => 'Sektöre ait ekstra meta alanları',
            'resolve' => function( $term ) {
                return array(
                    'icon'          => get_term_meta( $term->term_id, 'sector_icon', true ),
                    'iconName'      => get_term_meta( $term->term_id, 'sector_icon_name', true ),
                    'featuredImage' => get_term_meta( $term->term_id, 'sector_featured_image', true ),
                    'color'         => get_term_meta( $term->term_id, 'sector_color', true ),
                );
            }
        ));

        // GraphQL Obje Tipini Tanımla
        register_graphql_object_type( 'SectorDetails', array(
            'description' => 'Sektör detay alanları',
            'fields' => array(
                'icon'          => array( 'type' => 'String' ),
                'iconName'      => array( 'type' => 'String' ),
                'featuredImage' => array( 'type' => 'String' ),
                'color'         => array( 'type' => 'String' ),
            ),
        ));
    }
}