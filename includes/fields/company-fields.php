<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Company_Fields {

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
        add_action( 'save_post', array( __CLASS__, 'save_post' ) );
        add_action( 'graphql_register_types', array( __CLASS__, 'register_graphql_fields' ) );
        add_action( 'admin_footer', array( __CLASS__, 'admin_footer_scripts' ) );
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'sektorel_company_details',
            'Firma Detayları & Ayarlar',
            array( __CLASS__, 'render_metabox' ),
            'company',
            'normal',
            'high'
        );
    }

    public static function render_metabox( $post ) {
        wp_nonce_field( 'sektorel_company_save', 'sektorel_company_nonce' );

        $val = function($key) use ($post) {
            return get_post_meta( $post->ID, $key, true );
        };

        $services = get_post_meta( $post->ID, 'company_services', true ) ?: [];
        $hours = get_post_meta( $post->ID, 'working_hours', true ) ?: [];
        $related_news_ids = get_post_meta( $post->ID, 'related_news_ids', true ) ?: [];

        $all_news = get_posts(array('post_type' => 'post', 'numberposts' => 50, 'post_status' => 'publish'));

        ?>
        <style>
            .sektorel-panel { margin-top: 10px; }
            .sektorel-section-title { font-size: 14px; font-weight: 700; border-bottom: 1px solid #ddd; padding: 15px 0 5px 0; margin: 10px 0 15px 0; color: #2c3338; text-transform: uppercase; letter-spacing: 0.5px; }
            .sektorel-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
            .sektorel-field { margin-bottom: 15px; }
            .sektorel-field label { display: block; font-weight: 600; margin-bottom: 5px; color: #444; }
            .sektorel-field input, .sektorel-field select, .sektorel-field textarea { width: 100%; }
            .sektorel-repeater-item { border: 1px solid #e5e5e5; background: #f9f9f9; padding: 10px; margin-bottom: 10px; display: flex; gap: 10px; align-items: flex-end; }
            .sektorel-repeater-btn { cursor: pointer; color: #d63638; font-weight: bold; padding: 5px; }
            .sektorel-add-btn { background: #f0f0f1; border: 1px solid #8c8f94; color: #2271b1; cursor: pointer; padding: 5px 10px; border-radius: 3px; font-weight: 600; }
        </style>

        <div class="sektorel-panel">
            
            <div class="sektorel-row">
                <div class="sektorel-field">
                    <label for="company_type">Firma Tipi</label>
                    <select id="company_type" name="company_type">
                        <option value="limited" <?php selected($val('company_type'), 'limited'); ?>>Limited Şirket</option>
                        <option value="anonim" <?php selected($val('company_type'), 'anonim'); ?>>Anonim Şirket</option>
                        <option value="sahis" <?php selected($val('company_type'), 'sahis'); ?>>Şahıs Firması</option>
                        <option value="diger" <?php selected($val('company_type'), 'diger'); ?>>Diğer</option>
                    </select>
                </div>
                <div class="sektorel-field">
                    <label>Onay Durumu</label>
                    <label style="font-weight:normal;">
                        <input type="checkbox" name="is_verified" value="1" <?php checked($val('is_verified'), 1); ?> style="width:auto;"/> Onaylı Firma
                    </label>
                </div>
            </div>

            <div class="sektorel-section-title">İletişim & Lokasyon</div>
            <div class="sektorel-row">
                <div class="sektorel-field"><label>E-Posta</label><input type="email" name="email" value="<?php echo esc_attr($val('email')); ?>" /></div>
                <div class="sektorel-field"><label>Telefon</label><input type="text" name="phone" value="<?php echo esc_attr($val('phone')); ?>" /></div>
            </div>
            <div class="sektorel-row">
                <div class="sektorel-field"><label>Web Sitesi</label><input type="url" name="website" value="<?php echo esc_attr($val('website')); ?>" /></div>
                <div class="sektorel-field"><label>Posta Kodu</label><input type="text" name="postal_code" value="<?php echo esc_attr($val('postal_code')); ?>" /></div>
            </div>
            <div class="sektorel-field"><label>Açık Adres</label><textarea name="address" rows="2"><?php echo esc_textarea($val('address')); ?></textarea></div>
            <div class="sektorel-row">
                <div class="sektorel-field"><label>Lat</label><input type="text" name="map_lat" value="<?php echo esc_attr($val('map_lat')); ?>" /></div>
                <div class="sektorel-field"><label>Lng</label><input type="text" name="map_lng" value="<?php echo esc_attr($val('map_lng')); ?>" /></div>
            </div>

            <div class="sektorel-section-title">Kurumsal Bilgiler</div>
            <div class="sektorel-row">
                <div class="sektorel-field"><label>Kuruluş Yılı</label><input type="number" name="foundation_year" value="<?php echo esc_attr($val('foundation_year')); ?>" /></div>
                <div class="sektorel-field"><label>Çalışan Sayısı</label><input type="text" name="employee_count" value="<?php echo esc_attr($val('employee_count')); ?>" /></div>
            </div>
            <div class="sektorel-row">
                <div class="sektorel-field"><label>Vergi Dairesi</label><input type="text" name="tax_office" value="<?php echo esc_attr($val('tax_office')); ?>" /></div>
                <div class="sektorel-field"><label>Ticaret Sicil No</label><input type="text" name="trade_registry_number" value="<?php echo esc_attr($val('trade_registry_number')); ?>" /></div>
            </div>
            <div class="sektorel-field"><label>Kapak Görseli URL</label><input type="text" name="cover_image" value="<?php echo esc_attr($val('cover_image')); ?>" /></div>
            <div class="sektorel-field"><label>Faaliyet Belgesi URL</label><input type="text" name="activity_certificate" value="<?php echo esc_attr($val('activity_certificate')); ?>" /></div>
            
            <div class="sektorel-section-title">Medya & Sosyal</div>
            <div class="sektorel-field"><label>Galeri URL'leri (Her satıra bir)</label><textarea name="gallery_urls" rows="4"><?php echo esc_textarea($val('gallery_urls')); ?></textarea></div>
            <div class="sektorel-row">
                <div class="sektorel-field"><label>LinkedIn</label><input type="text" name="linkedin_url" value="<?php echo esc_attr($val('linkedin_url')); ?>" /></div>
                <div class="sektorel-field"><label>Facebook</label><input type="text" name="facebook_url" value="<?php echo esc_attr($val('facebook_url')); ?>" /></div>
            </div>
            <div class="sektorel-row">
                <div class="sektorel-field"><label>Twitter</label><input type="text" name="twitter_url" value="<?php echo esc_attr($val('twitter_url')); ?>" /></div>
                <div class="sektorel-field"><label>Instagram</label><input type="text" name="instagram_url" value="<?php echo esc_attr($val('instagram_url')); ?>" /></div>
            </div>

            <!-- Repeaters -->
            <div class="sektorel-section-title">Çalışma Saatleri</div>
            <div id="hours-container">
                <?php if (!empty($hours)) : foreach ($hours as $i => $h) : ?>
                    <div class="sektorel-repeater-item">
                        <div style="flex:1"><input type="text" name="working_hours[<?php echo $i; ?>][days]" value="<?php echo esc_attr($h['days']); ?>" placeholder="Pzt-Cum" /></div>
                        <div style="flex:1"><input type="text" name="working_hours[<?php echo $i; ?>][time]" value="<?php echo esc_attr($h['time']); ?>" placeholder="09:00 - 18:00" /></div>
                        <span class="sektorel-repeater-btn remove-row">X</span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <button type="button" class="sektorel-add-btn" id="add-hours">+ Saat Ekle</button>

            <div class="sektorel-section-title">Hizmetler</div>
            <div id="services-container">
                <?php if (!empty($services)) : foreach ($services as $i => $s) : ?>
                    <div class="sektorel-repeater-item">
                        <div style="flex:1"><input type="text" name="company_services[<?php echo $i; ?>][icon]" value="<?php echo esc_attr($s['icon']); ?>" placeholder="Icon" /></div>
                        <div style="flex:2"><input type="text" name="company_services[<?php echo $i; ?>][title]" value="<?php echo esc_attr($s['title']); ?>" placeholder="Başlık" /></div>
                        <div style="flex:3"><input type="text" name="company_services[<?php echo $i; ?>][desc]" value="<?php echo esc_attr($s['desc']); ?>" placeholder="Açıklama" /></div>
                        <span class="sektorel-repeater-btn remove-row">X</span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <button type="button" class="sektorel-add-btn" id="add-service">+ Hizmet Ekle</button>

             <!-- İlişkili Haberler -->
             <div class="sektorel-section-title">İlişkili Haberler</div>
             <div class="sektorel-field">
                <select name="related_news_ids[]" multiple style="height: 100px;">
                    <?php foreach($all_news as $news): ?>
                        <option value="<?php echo $news->ID; ?>" <?php echo in_array($news->ID, $related_news_ids) ? 'selected' : ''; ?>>
                            <?php echo esc_html($news->post_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
             </div>

        </div>
        <?php
    }

    public static function admin_footer_scripts() {
        global $post;
        if ( ! $post || 'company' !== $post->post_type ) return;
        ?>
        <script>
        jQuery(document).ready(function($){
            $('#add-hours').click(function(){
                var count = $('#hours-container .sektorel-repeater-item').length;
                var html = `<div class="sektorel-repeater-item"><div style="flex:1"><input type="text" name="working_hours[${count}][days]" placeholder="Gün" /></div><div style="flex:1"><input type="text" name="working_hours[${count}][time]" placeholder="Saat" /></div><span class="sektorel-repeater-btn remove-row">X</span></div>`;
                $('#hours-container').append(html);
            });
            $('#add-service').click(function(){
                var count = $('#services-container .sektorel-repeater-item').length;
                var html = `<div class="sektorel-repeater-item"><div style="flex:1"><input type="text" name="company_services[${count}][icon]" placeholder="Icon" /></div><div style="flex:2"><input type="text" name="company_services[${count}][title]" placeholder="Başlık" /></div><div style="flex:3"><input type="text" name="company_services[${count}][desc]" placeholder="Açıklama" /></div><span class="sektorel-repeater-btn remove-row">X</span></div>`;
                $('#services-container').append(html);
            });
            $(document).on('click', '.remove-row', function(){ $(this).closest('.sektorel-repeater-item').remove(); });
        });
        </script>
        <?php
    }

    public static function save_post( $post_id ) {
        if ( ! isset( $_POST['sektorel_company_nonce'] ) || ! wp_verify_nonce( $_POST['sektorel_company_nonce'], 'sektorel_company_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $fields = [
            'company_type', 'email', 'phone', 'website', 'postal_code', 'address', 'map_lat', 'map_lng',
            'foundation_year', 'employee_count', 'tax_office', 'trade_registry_number', 'cover_image', 'activity_certificate',
            'gallery_urls', 'linkedin_url', 'facebook_url', 'twitter_url', 'instagram_url', 'kep_address'
        ];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $sanitized = ($field === 'gallery_urls' || $field === 'address') ? sanitize_textarea_field($_POST[$field]) : sanitize_text_field($_POST[$field]);
                update_post_meta($post_id, $field, $sanitized);
            }
        }
        
        update_post_meta($post_id, 'is_verified', isset($_POST['is_verified']) ? 1 : 0);

        if (isset($_POST['working_hours']) && is_array($_POST['working_hours'])) {
            update_post_meta($post_id, 'working_hours', array_values($_POST['working_hours']));
        } else {
            delete_post_meta($post_id, 'working_hours');
        }

        if (isset($_POST['company_services']) && is_array($_POST['company_services'])) {
            update_post_meta($post_id, 'company_services', array_values($_POST['company_services']));
        } else {
            delete_post_meta($post_id, 'company_services');
        }

        if (isset($_POST['related_news_ids']) && is_array($_POST['related_news_ids'])) {
            update_post_meta($post_id, 'related_news_ids', array_map('intval', $_POST['related_news_ids']));
        } else {
            delete_post_meta($post_id, 'related_news_ids');
        }
    }

    public static function register_graphql_fields() {
        register_graphql_object_type( 'CompanyWorkingHour', array(
            'fields' => array( 'days' => array('type'=>'String'), 'time' => array('type'=>'String') )
        ));
        register_graphql_object_type( 'CompanyService', array(
            'fields' => array( 'icon' => array('type'=>'String'), 'title' => array('type'=>'String'), 'desc' => array('type'=>'String') )
        ));
        register_graphql_object_type( 'CompanySocial', array(
            'fields' => array( 'linkedin' => array('type'=>'String'), 'facebook' => array('type'=>'String'), 'twitter' => array('type'=>'String'), 'instagram' => array('type'=>'String') )
        ));

        register_graphql_object_type( 'CompanyDetails', array(
            'description' => 'Firma detay alanları',
            'fields' => array(
                'companyType'         => array( 'type' => 'String' ),
                'isVerified'          => array( 'type' => 'Boolean' ),
                'email'               => array( 'type' => 'String' ),
                'phone'               => array( 'type' => 'String' ),
                'website'             => array( 'type' => 'String' ),
                'address'             => array( 'type' => 'String' ),
                'postalCode'          => array( 'type' => 'String' ),
                'mapLat'              => array( 'type' => 'String' ),
                'mapLng'              => array( 'type' => 'String' ),
                'kepAddress'          => array( 'type' => 'String' ),
                'foundationYear'      => array( 'type' => 'String' ),
                'employeeCount'       => array( 'type' => 'String' ),
                'taxOffice'           => array( 'type' => 'String' ),
                'tradeRegistryNumber' => array( 'type' => 'String' ),
                'activityCertificate' => array( 'type' => 'String' ),
                'coverImage'          => array( 'type' => 'String' ),
                'galleryUrls'         => array( 'type' => 'String' ),
                'social'              => array( 'type' => 'CompanySocial' ),
                'workingHours'        => array( 'type' => ['list_of' => 'CompanyWorkingHour'] ),
                'services'            => array( 'type' => ['list_of' => 'CompanyService'] ),
                'relatedNews'         => array(
                    'type' => array( 'list_of' => 'Post' ),
                    'resolve' => function( $source ) {
                        if ( empty($source['relatedNewsIds']) || !is_array($source['relatedNewsIds']) ) return [];
                        $ids = array_map('intval', $source['relatedNewsIds']);
                        if (empty($ids)) return [];
                        return get_posts(array(
                            'post_type' => 'post',
                            'post__in' => $ids,
                            'orderby' => 'post__in',
                            'numberposts' => -1,
                            'suppress_filters' => false
                        ));
                    }
                ),
            ),
        ));

        register_graphql_field( 'Company', 'companyDetails', array(
            'type' => 'CompanyDetails',
            'resolve' => function( $post ) {
                $id = isset($post->databaseId) ? $post->databaseId : (isset($post->ID) ? $post->ID : 0);
                if (!$id) return null;

                // String değerlerin null dönmesini engellemek için casting yapıyoruz
                return array(
                    'companyType'         => (string) get_post_meta( $id, 'company_type', true ),
                    'isVerified'          => get_post_meta( $id, 'is_verified', true ) === '1',
                    'email'               => (string) get_post_meta( $id, 'email', true ),
                    'phone'               => (string) get_post_meta( $id, 'phone', true ),
                    'website'             => (string) get_post_meta( $id, 'website', true ),
                    'address'             => (string) get_post_meta( $id, 'address', true ),
                    'postalCode'          => (string) get_post_meta( $id, 'postal_code', true ),
                    'mapLat'              => (string) get_post_meta( $id, 'map_lat', true ),
                    'mapLng'              => (string) get_post_meta( $id, 'map_lng', true ),
                    'kepAddress'          => (string) get_post_meta( $id, 'kep_address', true ),
                    'foundationYear'      => (string) get_post_meta( $id, 'foundation_year', true ),
                    'employeeCount'       => (string) get_post_meta( $id, 'employee_count', true ),
                    'taxOffice'           => (string) get_post_meta( $id, 'tax_office', true ),
                    'tradeRegistryNumber' => (string) get_post_meta( $id, 'trade_registry_number', true ),
                    'activityCertificate' => (string) get_post_meta( $id, 'activity_certificate', true ),
                    'coverImage'          => (string) get_post_meta( $id, 'cover_image', true ),
                    'galleryUrls'         => (string) get_post_meta( $id, 'gallery_urls', true ),
                    'social'              => array(
                        'linkedin'  => (string) get_post_meta( $id, 'linkedin_url', true ),
                        'facebook'  => (string) get_post_meta( $id, 'facebook_url', true ),
                        'twitter'   => (string) get_post_meta( $id, 'twitter_url', true ),
                        'instagram' => (string) get_post_meta( $id, 'instagram_url', true ),
                    ),
                    'workingHours'        => get_post_meta( $id, 'working_hours', true ) ?: [],
                    'services'            => get_post_meta( $id, 'company_services', true ) ?: [],
                    'relatedNewsIds'      => get_post_meta( $id, 'related_news_ids', true ) ?: [],
                );
            }
        ));
    }
}