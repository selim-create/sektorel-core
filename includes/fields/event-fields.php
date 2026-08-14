<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Fields {

    const OFFICIAL_CATEGORIES = array(
        'vergi'            => 'Vergi',
        'sgk'              => 'SGK',
        'beyanname'        => 'Beyanname',
        'tesvik_destek'    => 'Teşvik / Destek',
        'son_basvuru'      => 'Son Başvuru',
        'resmi_yukumluluk' => 'Resmî Yükümlülük',
    );

    const EVENT_TYPES = array(
        'fuar'       => 'Fuar',
        'konferans'  => 'Konferans / Zirve',
        'kongre'     => 'Kongre',
        'seminer'    => 'Seminer',
        'webinar'    => 'Webinar',
        'egitim'     => 'Eğitim',
        'calistay'   => 'Workshop / Çalıştay',
        'festival'   => 'Festival',
        'yarisma'    => 'Yarışma',
        'networking' => 'Networking / Buluşma',
        'demo_day'   => 'Demo Day',
        'resmi'      => 'Resmî Takvim',
        'diger'      => 'Diğer',
    );

    const LOCATION_TYPES = array(
        'physical' => 'Fiziksel',
        'online'   => 'Online',
        'hybrid'   => 'Hibrit',
    );

    private static $type_inference_lock = false;

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
        add_action( 'save_post_event', array( __CLASS__, 'save_post' ), 10, 2 );
        add_action( 'graphql_register_types', array( __CLASS__, 'register_graphql_fields' ) );
        add_action( 'admin_footer', array( __CLASS__, 'admin_footer_scripts' ) );
        add_filter( 'manage_event_posts_columns', array( __CLASS__, 'admin_columns' ), 120 );
        add_action( 'manage_event_posts_custom_column', array( __CLASS__, 'render_admin_column' ), 120, 2 );
        add_filter( 'manage_edit-event_sortable_columns', array( __CLASS__, 'sortable_columns' ), 120 );
        add_action( 'restrict_manage_posts', array( __CLASS__, 'admin_filters' ), 120 );
        add_action( 'pre_get_posts', array( __CLASS__, 'apply_admin_filters' ), 120 );
        add_action( 'added_post_meta', array( __CLASS__, 'maybe_infer_imported_event_type' ), 170, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'maybe_infer_imported_event_type' ), 170, 4 );
    }

    public static function add_meta_boxes() {
        add_meta_box( 'sektorel_event_details', 'Etkinlik Detayları', array( __CLASS__, 'render_metabox' ), 'event', 'normal', 'high' );
    }

    public static function render_metabox( $post ) {
        wp_nonce_field( 'sektorel_event_save', 'sektorel_event_nonce' );
        $val = function ( $key ) use ( $post ) { return get_post_meta( $post->ID, $key, true ); };
        $schedule = get_post_meta( $post->ID, 'schedule', true );
        if ( ! is_array( $schedule ) ) { $schedule = array(); }
        $speakers = get_post_meta( $post->ID, 'speakers', true );
        if ( ! is_array( $speakers ) ) { $speakers = array(); }
        ?>
        <style>
            .sektorel-panel{margin-top:10px}.sektorel-section-title{font-size:14px;font-weight:700;border-bottom:1px solid #ddd;padding:15px 0 5px;margin:10px 0 15px;color:#2c3338;text-transform:uppercase;letter-spacing:.5px}.sektorel-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:15px}.sektorel-field{margin-bottom:15px}.sektorel-field>label{display:block;font-weight:600;margin-bottom:5px;color:#444}.sektorel-field input[type=text],.sektorel-field input[type=url],.sektorel-field input[type=datetime-local],.sektorel-field select,.sektorel-field textarea{width:100%}.sektorel-choice-row{display:flex;gap:18px;flex-wrap:wrap}.sektorel-choice-row label{font-weight:400}.sektorel-repeater-item{border:1px solid #e5e5e5;background:#f9f9f9;padding:10px;margin-bottom:10px;display:flex;gap:10px;align-items:flex-end}.sektorel-repeater-item input{margin-bottom:0!important}.sektorel-repeater-btn{cursor:pointer;color:#d63638;font-weight:700;padding:5px}.sektorel-add-btn{background:#f0f0f1;border:1px solid #8c8f94;color:#2271b1;cursor:pointer;padding:5px 10px;border-radius:3px;font-weight:600}.sektorel-official-fields{margin:5px 0 20px;padding:16px;border-left:4px solid #d63638;background:#fff7f7}.sektorel-help{margin:5px 0 0;color:#646970;font-size:12px}@media(max-width:782px){.sektorel-row{grid-template-columns:1fr}.sektorel-repeater-item{display:block}}
        </style>
        <div class="sektorel-panel">
            <div class="sektorel-row">
                <div class="sektorel-field"><label for="is_official"><input type="checkbox" id="is_official" name="is_official" value="1" <?php checked( $val( 'is_official' ), 1 ); ?> /> Resmî Takvim Etkinliği (Vergi, SGK)</label></div>
                <div class="sektorel-field"><label for="event_type">Etkinlik Türü</label><select id="event_type" name="event_type"><option value="">Tür seçin</option><?php foreach ( self::EVENT_TYPES as $type_key => $type_label ) : ?><option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $val( 'event_type' ), $type_key ); ?>><?php echo esc_html( $type_label ); ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="sektorel-official-fields" id="sektorel-official-fields">
                <div class="sektorel-section-title">Resmî / Mali Takvim Bilgileri</div>
                <div class="sektorel-row"><div class="sektorel-field"><label for="official_category">Kategori</label><select id="official_category" name="official_category"><option value="">Kategori seçin</option><?php foreach ( self::OFFICIAL_CATEGORIES as $category_key => $category_label ) : ?><option value="<?php echo esc_attr( $category_key ); ?>" <?php selected( $val( 'official_category' ), $category_key ); ?>><?php echo esc_html( $category_label ); ?></option><?php endforeach; ?></select></div><div class="sektorel-field"><label for="official_institution">İlgili Kurum</label><input type="text" id="official_institution" name="official_institution" value="<?php echo esc_attr( $val( 'official_institution' ) ); ?>" placeholder="Örn: GİB, SGK, KOSGEB" /></div></div>
                <div class="sektorel-field"><label for="official_source_url">Resmî Kaynak URL</label><input type="url" id="official_source_url" name="official_source_url" value="<?php echo esc_attr( $val( 'official_source_url' ) ); ?>" placeholder="https://..." /></div>
            </div>
            <div class="sektorel-section-title">Zaman ve Yer</div>
            <div class="sektorel-row"><div class="sektorel-field"><label for="start_date">Başlangıç Tarihi</label><input type="datetime-local" id="start_date" name="start_date" value="<?php echo esc_attr( $val( 'start_date' ) ); ?>" /></div><div class="sektorel-field"><label for="end_date">Bitiş Tarihi</label><input type="datetime-local" id="end_date" name="end_date" value="<?php echo esc_attr( $val( 'end_date' ) ); ?>" /></div></div>
            <div class="sektorel-field"><label>Lokasyon Tipi</label><div class="sektorel-choice-row"><?php foreach ( self::LOCATION_TYPES as $location_key => $location_label ) : ?><label><input type="radio" name="location_type" value="<?php echo esc_attr( $location_key ); ?>" <?php checked( $val( 'location_type' ), $location_key ); ?> /> <?php echo esc_html( $location_label ); ?></label><?php endforeach; ?></div></div>
            <div class="sektorel-row"><div class="sektorel-field"><label for="venue">Mekan / Platform Adı</label><input type="text" id="venue" name="venue" value="<?php echo esc_attr( $val( 'venue' ) ); ?>" placeholder="Örn: Tüyap veya Zoom" /></div><div class="sektorel-field"><label for="address">Açık Adres</label><input type="text" id="address" name="address" value="<?php echo esc_attr( $val( 'address' ) ); ?>" /><p class="sektorel-help">Fiziksel ve hibrit etkinliklerde kullanılır.</p></div></div>
            <div class="sektorel-section-title">Kayıt ve Organizasyon</div>
            <div class="sektorel-row"><div class="sektorel-field"><label for="organizer">Organizatör</label><input type="text" id="organizer" name="organizer" value="<?php echo esc_attr( $val( 'organizer' ) ); ?>" /></div><div class="sektorel-field"><label for="price">Ücret Bilgisi</label><input type="text" id="price" name="price" value="<?php echo esc_attr( $val( 'price' ) ); ?>" placeholder="Ücretsiz, 500 TL vb." /></div></div>
            <div class="sektorel-field"><label for="event_url">Etkinlik / Resmî Web Sitesi</label><input type="url" id="event_url" name="event_url" value="<?php echo esc_attr( $val( 'event_url' ) ); ?>" placeholder="https://..." /><p class="sektorel-help">Etkinliğin resmî tanıtım veya detay sayfası.</p></div>
            <div class="sektorel-field"><label for="registration_link">Kayıt / Bilet Linki</label><input type="url" id="registration_link" name="registration_link" value="<?php echo esc_attr( $val( 'registration_link' ) ); ?>" placeholder="https://..." /></div>
            <div class="sektorel-section-title">Program Akışı</div>
            <div id="schedule-container"><?php foreach ( $schedule as $i => $item ) : ?><div class="sektorel-repeater-item"><div style="flex:1"><label style="font-size:10px;">Saat</label><input type="text" name="schedule[<?php echo esc_attr( $i ); ?>][time]" value="<?php echo esc_attr( isset( $item['time'] ) ? $item['time'] : '' ); ?>" placeholder="10:00" /></div><div style="flex:3"><label style="font-size:10px;">Başlık</label><input type="text" name="schedule[<?php echo esc_attr( $i ); ?>][title]" value="<?php echo esc_attr( isset( $item['title'] ) ? $item['title'] : '' ); ?>" /></div><span class="sektorel-repeater-btn remove-row">X</span></div><?php endforeach; ?></div><button type="button" class="sektorel-add-btn" id="add-schedule">+ Akış Ekle</button>
            <div class="sektorel-section-title">Konuşmacılar</div>
            <div id="speakers-container"><?php foreach ( $speakers as $i => $item ) : ?><div class="sektorel-repeater-item"><div style="flex:2"><label style="font-size:10px;">Ad Soyad</label><input type="text" name="speakers[<?php echo esc_attr( $i ); ?>][name]" value="<?php echo esc_attr( isset( $item['name'] ) ? $item['name'] : '' ); ?>" /></div><div style="flex:2"><label style="font-size:10px;">Unvan</label><input type="text" name="speakers[<?php echo esc_attr( $i ); ?>][title]" value="<?php echo esc_attr( isset( $item['title'] ) ? $item['title'] : '' ); ?>" /></div><div style="flex:2"><label style="font-size:10px;">Firma</label><input type="text" name="speakers[<?php echo esc_attr( $i ); ?>][company]" value="<?php echo esc_attr( isset( $item['company'] ) ? $item['company'] : '' ); ?>" /></div><div style="flex:2"><label style="font-size:10px;">Fotoğraf URL</label><input type="url" name="speakers[<?php echo esc_attr( $i ); ?>][image]" value="<?php echo esc_attr( isset( $item['image'] ) ? $item['image'] : '' ); ?>" /></div><span class="sektorel-repeater-btn remove-row">X</span></div><?php endforeach; ?></div><button type="button" class="sektorel-add-btn" id="add-speaker">+ Konuşmacı Ekle</button>
        </div>
        <?php
    }

    public static function admin_footer_scripts() {
        global $post;
        if ( ! $post || 'event' !== $post->post_type ) { return; }
        ?>
        <script>jQuery(function($){function toggleOfficialFields(){$('#sektorel-official-fields').toggle($('#is_official').is(':checked'));}toggleOfficialFields();$('#is_official').on('change',toggleOfficialFields);$('#add-schedule').on('click',function(){var count=$('#schedule-container .sektorel-repeater-item').length;$('#schedule-container').append('<div class="sektorel-repeater-item"><div style="flex:1"><input type="text" name="schedule['+count+'][time]" placeholder="Saat" /></div><div style="flex:3"><input type="text" name="schedule['+count+'][title]" placeholder="Başlık" /></div><span class="sektorel-repeater-btn remove-row">X</span></div>');});$('#add-speaker').on('click',function(){var count=$('#speakers-container .sektorel-repeater-item').length;$('#speakers-container').append('<div class="sektorel-repeater-item"><div style="flex:2"><input type="text" name="speakers['+count+'][name]" placeholder="Ad Soyad" /></div><div style="flex:2"><input type="text" name="speakers['+count+'][title]" placeholder="Unvan" /></div><div style="flex:2"><input type="text" name="speakers['+count+'][company]" placeholder="Firma" /></div><div style="flex:2"><input type="url" name="speakers['+count+'][image]" placeholder="Fotoğraf URL" /></div><span class="sektorel-repeater-btn remove-row">X</span></div>');});$(document).on('click','.remove-row',function(){$(this).closest('.sektorel-repeater-item').remove();});});</script>
        <?php
    }

    public static function save_post( $post_id, $post = null ) {
        if ( ! $post || 'event' !== $post->post_type ) { return; }
        if ( ! isset( $_POST['sektorel_event_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sektorel_event_nonce'] ) ), 'sektorel_event_save' ) ) { return; }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

        $event_type = isset( $_POST['event_type'] ) ? sanitize_key( wp_unslash( $_POST['event_type'] ) ) : '';
        if ( ! array_key_exists( $event_type, self::EVENT_TYPES ) ) { $event_type = ''; }
        update_post_meta( $post_id, 'event_type', $event_type );

        $location_type = isset( $_POST['location_type'] ) ? sanitize_key( wp_unslash( $_POST['location_type'] ) ) : '';
        if ( ! array_key_exists( $location_type, self::LOCATION_TYPES ) ) { $location_type = ''; }
        update_post_meta( $post_id, 'location_type', $location_type );

        foreach ( array( 'start_date', 'end_date', 'venue', 'address', 'organizer', 'price' ) as $field ) { $value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : ''; update_post_meta( $post_id, $field, $value ); }
        foreach ( array( 'event_url', 'registration_link' ) as $field ) { $value = isset( $_POST[ $field ] ) ? esc_url_raw( wp_unslash( $_POST[ $field ] ), array( 'http', 'https' ) ) : ''; update_post_meta( $post_id, $field, $value ); }

        $is_official = isset( $_POST['is_official'] ) ? 1 : 0;
        update_post_meta( $post_id, 'is_official', $is_official );
        if ( $is_official ) {
            $category = isset( $_POST['official_category'] ) ? sanitize_key( wp_unslash( $_POST['official_category'] ) ) : '';
            if ( ! array_key_exists( $category, self::OFFICIAL_CATEGORIES ) ) { $category = ''; }
            update_post_meta( $post_id, 'event_type', 'resmi' );
            update_post_meta( $post_id, 'official_category', $category );
            update_post_meta( $post_id, 'official_institution', isset( $_POST['official_institution'] ) ? sanitize_text_field( wp_unslash( $_POST['official_institution'] ) ) : '' );
            update_post_meta( $post_id, 'official_source_url', isset( $_POST['official_source_url'] ) ? esc_url_raw( wp_unslash( $_POST['official_source_url'] ), array( 'http', 'https' ) ) : '' );
        } else {
            delete_post_meta( $post_id, 'official_category' ); delete_post_meta( $post_id, 'official_institution' ); delete_post_meta( $post_id, 'official_source_url' );
        }

        $clean_schedule = array();
        if ( isset( $_POST['schedule'] ) && is_array( $_POST['schedule'] ) ) { foreach ( wp_unslash( $_POST['schedule'] ) as $item ) { $title = isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : ''; if ( '' === $title ) { continue; } $clean_schedule[] = array( 'time' => isset( $item['time'] ) ? sanitize_text_field( $item['time'] ) : '', 'title' => $title ); } }
        if ( $clean_schedule ) { update_post_meta( $post_id, 'schedule', $clean_schedule ); } else { delete_post_meta( $post_id, 'schedule' ); }

        $clean_speakers = array();
        if ( isset( $_POST['speakers'] ) && is_array( $_POST['speakers'] ) ) { foreach ( wp_unslash( $_POST['speakers'] ) as $item ) { $name = isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : ''; if ( '' === $name ) { continue; } $clean_speakers[] = array( 'name' => $name, 'title' => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '', 'company' => isset( $item['company'] ) ? sanitize_text_field( $item['company'] ) : '', 'image' => isset( $item['image'] ) ? esc_url_raw( $item['image'], array( 'http', 'https' ) ) : '' ); } }
        if ( $clean_speakers ) { update_post_meta( $post_id, 'speakers', $clean_speakers ); } else { delete_post_meta( $post_id, 'speakers' ); }
    }

    public static function admin_columns( $columns ) {
        $final = array();
        if ( isset( $columns['cb'] ) ) { $final['cb'] = $columns['cb']; }
        $final['title'] = 'Etkinlik';
        $final['event_start'] = 'Etkinlik Tarihi';
        $final['event_type_final'] = 'Tür';
        if ( isset( $columns['taxonomy-location'] ) ) { $final['taxonomy-location'] = 'Lokasyon'; }
        if ( isset( $columns['taxonomy-sector'] ) ) { $final['taxonomy-sector'] = 'Sektör'; }
        $final['event_health_final'] = 'Veri Sağlığı';
        $final['event_sources_final'] = 'Kaynak';
        $final['event_status_final'] = 'Durum';
        return $final;
    }

    public static function render_admin_column( $column, $post_id ) {
        if ( 'event_start' === $column ) { echo esc_html( self::format_event_range( $post_id ) ); return; }
        if ( 'event_type_final' === $column ) { $type = sanitize_key( (string) get_post_meta( $post_id, 'event_type', true ) ); echo esc_html( isset( self::EVENT_TYPES[ $type ] ) ? self::EVENT_TYPES[ $type ] : '—' ); return; }
        if ( 'event_health_final' === $column ) { $score = get_post_meta( $post_id, 'event_completeness_score', true ); $conflicts = absint( get_post_meta( $post_id, 'event_conflict_count', true ) ); echo '' === (string) $score ? '—' : '<strong>' . esc_html( (string) absint( $score ) ) . '/100</strong>'; if ( $conflicts ) { echo '<br><span style="color:#b32d2e;">' . esc_html( (string) $conflicts ) . ' çakışma</span>'; } return; }
        if ( 'event_sources_final' === $column ) { $count = max( absint( get_post_meta( $post_id, 'event_source_evidence_count', true ) ), absint( get_post_meta( $post_id, 'event_health_evidence_count', true ) ) ); echo $count ? esc_html( (string) $count . ' kaynak' ) : '—'; return; }
        if ( 'event_status_final' === $column ) { $status = get_post_status_object( get_post_status( $post_id ) ); echo esc_html( $status && ! empty( $status->label ) ? $status->label : get_post_status( $post_id ) ); }
    }

    public static function sortable_columns( $columns ) { $columns['event_start'] = 'event_start'; return $columns; }

    public static function admin_filters() {
        global $typenow;
        if ( 'event' !== $typenow ) { return; }
        $selected_type = isset( $_GET['event_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['event_type_filter'] ) ) : '';
        echo '<select name="event_type_filter"><option value="">Tüm etkinlik türleri</option>'; foreach ( self::EVENT_TYPES as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $selected_type, $key, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select>';
        $selected_scope = isset( $_GET['event_scope_filter'] ) ? sanitize_key( wp_unslash( $_GET['event_scope_filter'] ) ) : '';
        echo '<select name="event_scope_filter"><option value="">Tüm zamanlar</option><option value="future" ' . selected( $selected_scope, 'future', false ) . '>Gelecek / devam eden</option><option value="past" ' . selected( $selected_scope, 'past', false ) . '>Geçmiş</option></select>';
        $selected_location = isset( $_GET['location_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['location_type_filter'] ) ) : '';
        echo '<select name="location_type_filter"><option value="">Tüm lokasyon tipleri</option>'; foreach ( self::LOCATION_TYPES as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $selected_location, $key, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select>';
        $selected_health = isset( $_GET['event_health_filter'] ) ? sanitize_key( wp_unslash( $_GET['event_health_filter'] ) ) : '';
        echo '<select name="event_health_filter"><option value="">Tüm veri sağlıkları</option><option value="complete" ' . selected( $selected_health, 'complete', false ) . '>İyi (85+)</option><option value="partial" ' . selected( $selected_health, 'partial', false ) . '>Eksikleri var</option><option value="weak" ' . selected( $selected_health, 'weak', false ) . '>Zayıf</option><option value="conflict" ' . selected( $selected_health, 'conflict', false ) . '>Çakışma var</option></select>';
    }

    public static function apply_admin_filters( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() || 'event' !== $query->get( 'post_type' ) ) { return; }
        if ( 'event_start' === $query->get( 'orderby' ) ) { $query->set( 'meta_key', 'start_date' ); $query->set( 'orderby', 'meta_value' ); }
        $meta_query = (array) $query->get( 'meta_query' );
        $type = isset( $_GET['event_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['event_type_filter'] ) ) : '';
        if ( array_key_exists( $type, self::EVENT_TYPES ) ) { $meta_query[] = array( 'key' => 'event_type', 'value' => $type ); }
        $location = isset( $_GET['location_type_filter'] ) ? sanitize_key( wp_unslash( $_GET['location_type_filter'] ) ) : '';
        if ( array_key_exists( $location, self::LOCATION_TYPES ) ) { $meta_query[] = array( 'key' => 'location_type', 'value' => $location ); }
        $health = isset( $_GET['event_health_filter'] ) ? sanitize_key( wp_unslash( $_GET['event_health_filter'] ) ) : '';
        if ( in_array( $health, array( 'complete', 'partial', 'weak' ), true ) ) { $meta_query[] = array( 'key' => 'event_completeness_status', 'value' => $health ); } elseif ( 'conflict' === $health ) { $meta_query[] = array( 'key' => 'event_conflict_count', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ); }
        $scope = isset( $_GET['event_scope_filter'] ) ? sanitize_key( wp_unslash( $_GET['event_scope_filter'] ) ) : '';
        $today = current_time( 'Y-m-d' );
        if ( 'future' === $scope ) { $meta_query[] = array( 'relation' => 'OR', array( 'key' => 'end_date', 'value' => $today, 'compare' => '>=', 'type' => 'CHAR' ), array( 'key' => 'start_date', 'value' => $today, 'compare' => '>=', 'type' => 'CHAR' ) ); }
        elseif ( 'past' === $scope ) { $meta_query[] = array( 'relation' => 'OR', array( 'key' => 'end_date', 'value' => $today, 'compare' => '<', 'type' => 'CHAR' ), array( 'relation' => 'AND', array( 'key' => 'end_date', 'compare' => 'NOT EXISTS' ), array( 'key' => 'start_date', 'value' => $today, 'compare' => '<', 'type' => 'CHAR' ) ) ); }
        if ( $meta_query ) { $query->set( 'meta_query', $meta_query ); }
    }

    public static function maybe_infer_imported_event_type( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( self::$type_inference_lock || 'source_candidate_id' !== $meta_key || 'event' !== get_post_type( $object_id ) ) { return; }
        $candidate_id = absint( $meta_value );
        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) ) { return; }
        $type = self::infer_event_type( get_the_title( $object_id ) );
        if ( ! $type ) { return; }
        self::$type_inference_lock = true;
        update_post_meta( absint( $object_id ), 'event_type', $type );
        update_post_meta( absint( $object_id ), 'event_type_inferred_from_candidate', $candidate_id );
        self::$type_inference_lock = false;
    }

    private static function infer_event_type( $title ) {
        $title = strtolower( remove_accents( wp_strip_all_tags( (string) $title ) ) );
        $map = array(
            'demo_day' => array( 'demo day' ), 'festival' => array( 'teknofest', 'festival' ), 'yarisma' => array( 'yarism', 'competition', 'challenge', 'hackathon' ), 'webinar' => array( 'webinar' ), 'calistay' => array( 'calistay', 'workshop' ), 'kongre' => array( 'kongre', 'congress' ), 'seminer' => array( 'seminer', 'seminar' ), 'egitim' => array( 'egitim', 'training' ), 'networking' => array( 'networking', 'bulusma', 'meetup' ), 'fuar' => array( 'fuar', ' fair', 'expo', 'exhibition' ), 'konferans' => array( 'konferans', 'conference', 'zirve', 'summit', 'sempozyum', 'symposium', 'forum' ),
        );
        foreach ( $map as $type => $needles ) { foreach ( $needles as $needle ) { if ( false !== strpos( $title, $needle ) ) { return $type; } } }
        return 'diger';
    }

    private static function format_event_range( $post_id ) {
        $start = self::date_part( get_post_meta( $post_id, 'start_date', true ) ); $end = self::date_part( get_post_meta( $post_id, 'end_date', true ) );
        if ( ! $start ) { return '—'; }
        $start_ts = strtotime( $start . ' 12:00:00' ); $end_ts = $end ? strtotime( $end . ' 12:00:00' ) : 0; $formatted = wp_date( 'd.m.Y', $start_ts );
        if ( $end_ts && $end !== $start ) { $formatted .= ' – ' . wp_date( 'd.m.Y', $end_ts ); }
        return $formatted;
    }

    private static function date_part( $value ) { return preg_match( '/^(\d{4}-\d{2}-\d{2})/', trim( (string) $value ), $matches ) ? $matches[1] : ''; }

    public static function register_graphql_fields() {
        register_graphql_field( 'Event', 'eventDetails', array( 'type' => 'EventDetails', 'resolve' => function ( $post ) { return array(
            'isOfficial' => get_post_meta( $post->ID, 'is_official', true ) === '1', 'eventType' => get_post_meta( $post->ID, 'event_type', true ), 'startDate' => get_post_meta( $post->ID, 'start_date', true ), 'endDate' => get_post_meta( $post->ID, 'end_date', true ), 'locationType' => get_post_meta( $post->ID, 'location_type', true ), 'venue' => get_post_meta( $post->ID, 'venue', true ), 'address' => get_post_meta( $post->ID, 'address', true ), 'organizer' => get_post_meta( $post->ID, 'organizer', true ), 'price' => get_post_meta( $post->ID, 'price', true ), 'eventUrl' => get_post_meta( $post->ID, 'event_url', true ), 'registrationLink' => get_post_meta( $post->ID, 'registration_link', true ), 'sourceUrl' => get_post_meta( $post->ID, 'source_url', true ), 'officialCategory' => get_post_meta( $post->ID, 'official_category', true ), 'officialInstitution' => get_post_meta( $post->ID, 'official_institution', true ), 'officialSourceUrl' => get_post_meta( $post->ID, 'official_source_url', true ), 'schedule' => get_post_meta( $post->ID, 'schedule', true ) ?: array(), 'speakers' => get_post_meta( $post->ID, 'speakers', true ) ?: array(),
        ); } ) );
        register_graphql_object_type( 'EventScheduleItem', array( 'fields' => array( 'time' => array( 'type' => 'String' ), 'title' => array( 'type' => 'String' ) ) ) );
        register_graphql_object_type( 'EventSpeakerItem', array( 'fields' => array( 'name' => array( 'type' => 'String' ), 'title' => array( 'type' => 'String' ), 'company' => array( 'type' => 'String' ), 'image' => array( 'type' => 'String' ) ) ) );
        register_graphql_object_type( 'EventDetails', array( 'fields' => array(
            'isOfficial' => array( 'type' => 'Boolean' ), 'eventType' => array( 'type' => 'String' ), 'startDate' => array( 'type' => 'String' ), 'endDate' => array( 'type' => 'String' ), 'locationType' => array( 'type' => 'String' ), 'venue' => array( 'type' => 'String' ), 'address' => array( 'type' => 'String' ), 'organizer' => array( 'type' => 'String' ), 'price' => array( 'type' => 'String' ), 'eventUrl' => array( 'type' => 'String' ), 'registrationLink' => array( 'type' => 'String' ), 'sourceUrl' => array( 'type' => 'String' ), 'officialCategory' => array( 'type' => 'String' ), 'officialInstitution' => array( 'type' => 'String' ), 'officialSourceUrl' => array( 'type' => 'String' ), 'schedule' => array( 'type' => array( 'list_of' => 'EventScheduleItem' ) ), 'speakers' => array( 'type' => array( 'list_of' => 'EventSpeakerItem' ) ),
        ) ) );
    }
}
