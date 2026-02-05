<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Fields {

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
        add_action( 'save_post', array( __CLASS__, 'save_post' ) );
        add_action( 'graphql_register_types', array( __CLASS__, 'register_graphql_fields' ) );
        // Admin tarafında repeater scripti için footer'a hook atıyoruz
        add_action( 'admin_footer', array( __CLASS__, 'admin_footer_scripts' ) );
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'sektorel_event_details',
            'Etkinlik Detayları',
            array( __CLASS__, 'render_metabox' ),
            'event',
            'normal',
            'high'
        );
    }

    public static function render_metabox( $post ) {
        wp_nonce_field( 'sektorel_event_save', 'sektorel_event_nonce' );

        // Verileri Çek
        $val = function($key) use ($post) {
            return get_post_meta( $post->ID, $key, true );
        };

        // Repeater Verileri (JSON olarak saklayacağız veya serialize edilmiş array)
        $schedule = get_post_meta( $post->ID, 'schedule', true ); 
        if (!is_array($schedule)) $schedule = [];

        $speakers = get_post_meta( $post->ID, 'speakers', true );
        if (!is_array($speakers)) $speakers = [];

        ?>
        <style>
            .sektorel-panel { margin-top: 10px; }
            .sektorel-section-title { 
                font-size: 14px; font-weight: 700; border-bottom: 1px solid #ddd; 
                padding: 15px 0 5px 0; margin: 10px 0 15px 0; color: #2c3338; 
                text-transform: uppercase; letter-spacing: 0.5px;
            }
            .sektorel-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
            .sektorel-field { margin-bottom: 15px; }
            .sektorel-field label { display: block; font-weight: 600; margin-bottom: 5px; color: #444; }
            .sektorel-field input[type="text"], .sektorel-field input[type="url"], 
            .sektorel-field input[type="datetime-local"], .sektorel-field select, 
            .sektorel-field textarea { width: 100%; }
            
            /* Repeater Styles */
            .sektorel-repeater-item { border: 1px solid #e5e5e5; background: #f9f9f9; padding: 10px; margin-bottom: 10px; display: flex; gap: 10px; align-items: flex-end; }
            .sektorel-repeater-item input { margin-bottom: 0 !important; }
            .sektorel-repeater-btn { cursor: pointer; color: #d63638; font-weight: bold; padding: 5px; }
            .sektorel-add-btn { background: #f0f0f1; border: 1px solid #8c8f94; color: #2271b1; cursor: pointer; padding: 5px 10px; border-radius: 3px; font-weight: 600; }
        </style>

        <div class="sektorel-panel">
            
            <!-- Genel -->
            <div class="sektorel-row">
                <div class="sektorel-field">
                    <label for="is_official">
                        <input type="checkbox" id="is_official" name="is_official" value="1" <?php checked( $val('is_official'), 1 ); ?> />
                        Resmi Takvim Etkinliği (Vergi, SGK)
                    </label>
                </div>
                <div class="sektorel-field">
                    <label for="event_type">Etkinlik Türü</label>
                    <select id="event_type" name="event_type">
                        <option value="fuar" <?php selected($val('event_type'), 'fuar'); ?>>Fuar</option>
                        <option value="webinar" <?php selected($val('event_type'), 'webinar'); ?>>Webinar</option>
                        <option value="konferans" <?php selected($val('event_type'), 'konferans'); ?>>Konferans/Zirve</option>
                        <option value="egitim" <?php selected($val('event_type'), 'egitim'); ?>>Eğitim</option>
                        <option value="resmi" <?php selected($val('event_type'), 'resmi'); ?>>Resmi Takvim</option>
                    </select>
                </div>
            </div>

            <!-- Zaman -->
            <div class="sektorel-section-title">Zaman ve Yer</div>
            <div class="sektorel-row">
                <div class="sektorel-field">
                    <label>Başlangıç Tarihi</label>
                    <input type="datetime-local" name="start_date" value="<?php echo esc_attr($val('start_date')); ?>" />
                </div>
                <div class="sektorel-field">
                    <label>Bitiş Tarihi</label>
                    <input type="datetime-local" name="end_date" value="<?php echo esc_attr($val('end_date')); ?>" />
                </div>
            </div>

            <div class="sektorel-field">
                <label>Lokasyon Tipi</label>
                <label style="margin-right:15px; font-weight:normal;">
                    <input type="radio" name="location_type" value="physical" <?php checked($val('location_type'), 'physical'); ?> /> Fiziksel
                </label>
                <label style="font-weight:normal;">
                    <input type="radio" name="location_type" value="online" <?php checked($val('location_type'), 'online'); ?> /> Online
                </label>
            </div>

            <div class="sektorel-row">
                <div class="sektorel-field">
                    <label>Mekan / Platform Adı</label>
                    <input type="text" name="venue" value="<?php echo esc_attr($val('venue')); ?>" placeholder="Örn: Tüyap veya Zoom" />
                </div>
                <div class="sektorel-field">
                    <label>Açık Adres (Fiziksel İse)</label>
                    <input type="text" name="address" value="<?php echo esc_attr($val('address')); ?>" />
                </div>
            </div>

            <!-- Kayıt & Organizasyon -->
            <div class="sektorel-section-title">Kayıt ve Organizasyon</div>
            <div class="sektorel-row">
                <div class="sektorel-field">
                    <label>Organizatör Firma</label>
                    <input type="text" name="organizer" value="<?php echo esc_attr($val('organizer')); ?>" />
                </div>
                <div class="sektorel-field">
                    <label>Ücret Bilgisi</label>
                    <input type="text" name="price" value="<?php echo esc_attr($val('price')); ?>" placeholder="Ücretsiz, 500 TL vb." />
                </div>
            </div>
            <div class="sektorel-field">
                <label>Kayıt Linki</label>
                <input type="url" name="registration_link" value="<?php echo esc_attr($val('registration_link')); ?>" />
            </div>

            <!-- Program Akışı (Repeater) -->
            <div class="sektorel-section-title">Program Akışı</div>
            <div id="schedule-container">
                <?php if (!empty($schedule)) : foreach ($schedule as $i => $item) : ?>
                    <div class="sektorel-repeater-item">
                        <div style="flex:1">
                            <label style="font-size:10px;">Saat</label>
                            <input type="text" name="schedule[<?php echo $i; ?>][time]" value="<?php echo esc_attr($item['time']); ?>" placeholder="10:00" />
                        </div>
                        <div style="flex:3">
                            <label style="font-size:10px;">Başlık</label>
                            <input type="text" name="schedule[<?php echo $i; ?>][title]" value="<?php echo esc_attr($item['title']); ?>" />
                        </div>
                        <span class="sektorel-repeater-btn remove-row">X</span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <button type="button" class="sektorel-add-btn" id="add-schedule">+ Akış Ekle</button>

            <!-- Konuşmacılar (Repeater) -->
            <div class="sektorel-section-title">Konuşmacılar</div>
            <div id="speakers-container">
                <?php if (!empty($speakers)) : foreach ($speakers as $i => $item) : ?>
                    <div class="sektorel-repeater-item">
                        <div style="flex:2">
                            <label style="font-size:10px;">Ad Soyad</label>
                            <input type="text" name="speakers[<?php echo $i; ?>][name]" value="<?php echo esc_attr($item['name']); ?>" />
                        </div>
                        <div style="flex:2">
                            <label style="font-size:10px;">Unvan</label>
                            <input type="text" name="speakers[<?php echo $i; ?>][title]" value="<?php echo esc_attr($item['title']); ?>" />
                        </div>
                        <div style="flex:2">
                            <label style="font-size:10px;">Firma</label>
                            <input type="text" name="speakers[<?php echo $i; ?>][company]" value="<?php echo esc_attr($item['company']); ?>" />
                        </div>
                        <div style="flex:2">
                            <label style="font-size:10px;">Fotoğraf URL</label>
                            <input type="text" name="speakers[<?php echo $i; ?>][image]" value="<?php echo esc_attr($item['image']); ?>" />
                        </div>
                        <span class="sektorel-repeater-btn remove-row">X</span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <button type="button" class="sektorel-add-btn" id="add-speaker">+ Konuşmacı Ekle</button>

        </div>
        <?php
    }

    // Admin panelinde repeater'ların çalışması için basit JS
    public static function admin_footer_scripts() {
        global $post;
        if ( ! $post || 'event' !== $post->post_type ) return;
        ?>
        <script>
        jQuery(document).ready(function($){
            // Akış Ekleme
            $('#add-schedule').click(function(){
                var count = $('#schedule-container .sektorel-repeater-item').length;
                var html = `
                    <div class="sektorel-repeater-item">
                        <div style="flex:1"><input type="text" name="schedule[${count}][time]" placeholder="Saat" /></div>
                        <div style="flex:3"><input type="text" name="schedule[${count}][title]" placeholder="Başlık" /></div>
                        <span class="sektorel-repeater-btn remove-row">X</span>
                    </div>`;
                $('#schedule-container').append(html);
            });

            // Konuşmacı Ekleme
            $('#add-speaker').click(function(){
                var count = $('#speakers-container .sektorel-repeater-item').length;
                var html = `
                    <div class="sektorel-repeater-item">
                        <div style="flex:2"><input type="text" name="speakers[${count}][name]" placeholder="Ad Soyad" /></div>
                        <div style="flex:2"><input type="text" name="speakers[${count}][title]" placeholder="Unvan" /></div>
                        <div style="flex:2"><input type="text" name="speakers[${count}][company]" placeholder="Firma" /></div>
                        <div style="flex:2"><input type="text" name="speakers[${count}][image]" placeholder="URL" /></div>
                        <span class="sektorel-repeater-btn remove-row">X</span>
                    </div>`;
                $('#speakers-container').append(html);
            });

            // Silme
            $(document).on('click', '.remove-row', function(){
                $(this).closest('.sektorel-repeater-item').remove();
            });
        });
        </script>
        <?php
    }

    public static function save_post( $post_id ) {
        if ( ! isset( $_POST['sektorel_event_nonce'] ) || ! wp_verify_nonce( $_POST['sektorel_event_nonce'], 'sektorel_event_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // Basit Alanlar
        $fields = ['event_type', 'start_date', 'end_date', 'location_type', 'venue', 'address', 'organizer', 'price', 'registration_link'];
        foreach ($fields as $field) {
            if (isset($_POST[$field])) update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
        }

        // Checkbox
        $is_official = isset($_POST['is_official']) ? 1 : 0;
        update_post_meta($post_id, 'is_official', $is_official);

        // Repeater Alanları (Array olarak kaydet)
        if (isset($_POST['schedule']) && is_array($_POST['schedule'])) {
            $schedule = array_values($_POST['schedule']); // Indexleri sıfırla
            // Basit sanitization (array_map deep gerekebilir, şimdilik basit döngü)
            $clean_schedule = [];
            foreach($schedule as $item) {
                if(!empty($item['title'])) $clean_schedule[] = array_map('sanitize_text_field', $item);
            }
            update_post_meta($post_id, 'schedule', $clean_schedule);
        } else {
            delete_post_meta($post_id, 'schedule');
        }

        if (isset($_POST['speakers']) && is_array($_POST['speakers'])) {
            $speakers = array_values($_POST['speakers']);
            $clean_speakers = [];
            foreach($speakers as $item) {
                if(!empty($item['name'])) $clean_speakers[] = array_map('sanitize_text_field', $item);
            }
            update_post_meta($post_id, 'speakers', $clean_speakers);
        } else {
            delete_post_meta($post_id, 'speakers');
        }
    }

    public static function register_graphql_fields() {
        
        // Ana Etkinlik Alanları
        register_graphql_field( 'Event', 'eventDetails', array(
            'type' => 'EventDetails',
            'resolve' => function( $post ) {
                return array(
                    'isOfficial'       => get_post_meta( $post->ID, 'is_official', true ) === '1',
                    'eventType'        => get_post_meta( $post->ID, 'event_type', true ),
                    'startDate'        => get_post_meta( $post->ID, 'start_date', true ),
                    'endDate'          => get_post_meta( $post->ID, 'end_date', true ),
                    'locationType'     => get_post_meta( $post->ID, 'location_type', true ),
                    'venue'            => get_post_meta( $post->ID, 'venue', true ),
                    'address'          => get_post_meta( $post->ID, 'address', true ),
                    'organizer'        => get_post_meta( $post->ID, 'organizer', true ),
                    'price'            => get_post_meta( $post->ID, 'price', true ),
                    'registrationLink' => get_post_meta( $post->ID, 'registration_link', true ),
                    // Repeaterlar
                    'schedule'         => get_post_meta( $post->ID, 'schedule', true ) ?: [],
                    'speakers'         => get_post_meta( $post->ID, 'speakers', true ) ?: [],
                );
            }
        ));

        // Alt Tipler (Repeater İçin)
        register_graphql_object_type( 'EventScheduleItem', array(
            'fields' => array(
                'time'  => array( 'type' => 'String' ),
                'title' => array( 'type' => 'String' ),
            )
        ));

        register_graphql_object_type( 'EventSpeakerItem', array(
            'fields' => array(
                'name'    => array( 'type' => 'String' ),
                'title'   => array( 'type' => 'String' ),
                'company' => array( 'type' => 'String' ),
                'image'   => array( 'type' => 'String' ),
            )
        ));

        // Ana EventDetails Tipi
        register_graphql_object_type( 'EventDetails', array(
            'fields' => array(
                'isOfficial'       => array( 'type' => 'Boolean' ),
                'eventType'        => array( 'type' => 'String' ),
                'startDate'        => array( 'type' => 'String' ),
                'endDate'          => array( 'type' => 'String' ),
                'locationType'     => array( 'type' => 'String' ),
                'venue'            => array( 'type' => 'String' ),
                'address'          => array( 'type' => 'String' ),
                'organizer'        => array( 'type' => 'String' ),
                'price'            => array( 'type' => 'String' ),
                'registrationLink' => array( 'type' => 'String' ),
                'schedule'         => array( 'type' => ['list_of' => 'EventScheduleItem'] ),
                'speakers'         => array( 'type' => ['list_of' => 'EventSpeakerItem'] ),
            ),
        ));
    }
}