<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Career_Fields {

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
        add_action( 'save_post', array( __CLASS__, 'save_post' ) );
        add_action( 'graphql_register_types', array( __CLASS__, 'register_graphql_fields' ) );
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'sektorel_career_details',
            'İlan Detayları',
            array( __CLASS__, 'render_metabox' ),
            'career',
            'normal',
            'high'
        );
    }

    public static function render_metabox( $post ) {
        wp_nonce_field( 'sektorel_career_save', 'sektorel_career_nonce' );

        $val = function($key) use ($post) {
            return get_post_meta( $post->ID, $key, true );
        };

        ?>
        <style>
            .sektorel-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
            .sektorel-field { margin-bottom: 15px; }
            .sektorel-field label { display: block; font-weight: 600; margin-bottom: 5px; }
            .sektorel-field input, .sektorel-field select { width: 100%; }
        </style>

        <div class="sektorel-row">
            <div class="sektorel-field">
                <label>Firma Adı (İlişki yoksa manuel)</label>
                <input type="text" name="company_name" value="<?php echo esc_attr($val('company_name')); ?>" placeholder="Örn: Tech A.Ş." />
            </div>
            <div class="sektorel-field">
                <label>Lokasyon</label>
                <input type="text" name="location" value="<?php echo esc_attr($val('location')); ?>" placeholder="İstanbul / Hibrit" />
            </div>
        </div>

        <div class="sektorel-row">
            <div class="sektorel-field">
                <label>Çalışma Şekli</label>
                <select name="work_type">
                    <option value="Tam Zamanlı" <?php selected($val('work_type'), 'Tam Zamanlı'); ?>>Tam Zamanlı</option>
                    <option value="Yarı Zamanlı" <?php selected($val('work_type'), 'Yarı Zamanlı'); ?>>Yarı Zamanlı</option>
                    <option value="Uzaktan" <?php selected($val('work_type'), 'Uzaktan'); ?>>Uzaktan</option>
                    <option value="Hibrit" <?php selected($val('work_type'), 'Hibrit'); ?>>Hibrit</option>
                    <option value="Staj" <?php selected($val('work_type'), 'Staj'); ?>>Staj</option>
                </select>
            </div>
            <div class="sektorel-field">
                <label>Deneyim</label>
                <select name="experience">
                    <option value="Tecrübesiz" <?php selected($val('experience'), 'Tecrübesiz'); ?>>Tecrübesiz</option>
                    <option value="1-3 Yıl" <?php selected($val('experience'), '1-3 Yıl'); ?>>1-3 Yıl</option>
                    <option value="3-5 Yıl" <?php selected($val('experience'), '3-5 Yıl'); ?>>3-5 Yıl</option>
                    <option value="5-10 Yıl" <?php selected($val('experience'), '5-10 Yıl'); ?>>5-10 Yıl</option>
                    <option value="10+ Yıl" <?php selected($val('experience'), '10+ Yıl'); ?>>10+ Yıl</option>
                </select>
            </div>
        </div>

        <div class="sektorel-row">
            <div class="sektorel-field">
                <label>Eğitim Seviyesi</label>
                <input type="text" name="education" value="<?php echo esc_attr($val('education')); ?>" placeholder="Lisans" />
            </div>
            <div class="sektorel-field">
                <label>Maaş Bilgisi</label>
                <input type="text" name="salary" value="<?php echo esc_attr($val('salary')); ?>" placeholder="Belirtilmedi" />
            </div>
        </div>

        <div class="sektorel-row">
            <div class="sektorel-field">
                <label>Son Başvuru Tarihi</label>
                <input type="date" name="deadline" value="<?php echo esc_attr($val('deadline')); ?>" />
            </div>
            <div class="sektorel-field">
                <label>Öne Çıkan İlan</label>
                <select name="is_featured">
                    <option value="0" <?php selected($val('is_featured'), '0'); ?>>Hayır</option>
                    <option value="1" <?php selected($val('is_featured'), '1'); ?>>Evet</option>
                </select>
            </div>
        </div>
        <?php
    }

    public static function save_post( $post_id ) {
        if ( ! isset( $_POST['sektorel_career_nonce'] ) || ! wp_verify_nonce( $_POST['sektorel_career_nonce'], 'sektorel_career_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $fields = ['company_name', 'location', 'work_type', 'experience', 'education', 'salary', 'deadline', 'is_featured'];
        foreach ($fields as $field) {
            if (isset($_POST[$field])) update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
        }
    }

    public static function register_graphql_fields() {
        register_graphql_field( 'Job', 'jobDetails', array(
            'type' => 'JobDetails',
            'resolve' => function( $post ) {
                return array(
                    'companyName' => get_post_meta( $post->ID, 'company_name', true ),
                    'location'    => get_post_meta( $post->ID, 'location', true ),
                    'workType'    => get_post_meta( $post->ID, 'work_type', true ),
                    'experience'  => get_post_meta( $post->ID, 'experience', true ),
                    'education'   => get_post_meta( $post->ID, 'education', true ),
                    'salary'      => get_post_meta( $post->ID, 'salary', true ),
                    'deadline'    => get_post_meta( $post->ID, 'deadline', true ),
                    'isFeatured'  => get_post_meta( $post->ID, 'is_featured', true ) === '1',
                );
            }
        ));

        register_graphql_object_type( 'JobDetails', array(
            'fields' => array(
                'companyName' => array( 'type' => 'String' ),
                'location'    => array( 'type' => 'String' ),
                'workType'    => array( 'type' => 'String' ),
                'experience'  => array( 'type' => 'String' ),
                'education'   => array( 'type' => 'String' ),
                'salary'      => array( 'type' => 'String' ),
                'deadline'    => array( 'type' => 'String' ),
                'isFeatured'  => array( 'type' => 'Boolean' ),
            ),
        ));
    }
}