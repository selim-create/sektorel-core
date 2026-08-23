<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lightweight AJAX UI for TOBB taxonomy mapping.
 *
 * Replaces the legacy select-heavy admin page without changing mapping options
 * or the deterministic TOBB taxonomy application logic.
 */
class Sektorel_Event_Source_TOBB_Taxonomy_UI {

    const NONCE_ACTION = 'sektorel_tobb_taxonomy_ui';
    const SAVE_ACTION  = 'sektorel_save_tobb_taxonomy_mapping_v2';
    const SEARCH_ACTION = 'sektorel_tobb_taxonomy_term_search';
    const PAGE_SLUG = 'sektorel-tobb-taxonomy-mapping';
    const MAX_RESULTS = 20;

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'replace_page' ), 120 );
        add_action( 'wp_ajax_' . self::SEARCH_ACTION, array( __CLASS__, 'ajax_term_search' ) );
        add_action( 'admin_post_' . self::SAVE_ACTION, array( __CLASS__, 'handle_save' ) );
    }

    public static function replace_page() {
        remove_submenu_page( 'edit.php?post_type=event', self::PAGE_SLUG );

        add_submenu_page(
            'edit.php?post_type=event',
            'TOBB Taxonomy Eşlemeleri',
            'TOBB Eşlemeleri',
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }

        $usage        = self::candidate_usage();
        $sector_map   = self::sector_map();
        $location_map = self::location_map();
        $saved        = ! empty( $_GET['tobb_mapping_saved'] );

        if ( $saved ) {
            echo '<div class="notice notice-success is-dismissible"><p>TOBB taxonomy eşlemeleri kaydedildi.</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>TOBB Taxonomy Eşlemeleri</h1>
            <p>Bu ekran yalnızca ihtiyaç duyduğunuz terimleri AJAX ile arar. 626 sektör ve on binlerce lokasyon sayfa açılışında yüklenmez.</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
                <?php wp_nonce_field( self::NONCE_ACTION, 'sektorel_tobb_taxonomy_ui_nonce' ); ?>

                <div class="card" style="max-width:none;padding:20px;margin-top:20px;">
                    <h2 style="margin-top:0;">Sektör Eşlemeleri</h2>
                    <p class="description">TOBB konu kodunu Sektörel Ajanda sektörüne bağlamak için en az 2 karakter yazıp sonuçtan seçim yapın.</p>
                    <?php if ( empty( $usage['topics'] ) ) : ?>
                        <p>Henüz TOBB adayı bulunamadı.</p>
                    <?php else : ?>
                        <table class="widefat striped" style="margin-top:14px;">
                            <thead><tr><th style="width:70px;">Kod</th><th>TOBB Konu Grup Başlığı</th><th style="width:90px;">Kullanım</th><th>Örnek Fuar</th><th style="width:360px;">Sektörel Ajanda Sektörü</th></tr></thead>
                            <tbody>
                            <?php foreach ( $usage['topics'] as $code => $item ) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html( (string) $code ); ?></strong></td>
                                    <td><?php echo esc_html( self::topic_label( $code ) ); ?></td>
                                    <td><?php echo esc_html( (string) $item['count'] ); ?></td>
                                    <td><?php echo esc_html( $item['sample'] ); ?></td>
                                    <td><?php self::render_picker( 'sector', 'sector_map[' . absint( $code ) . ']', isset( $sector_map[ $code ] ) ? absint( $sector_map[ $code ] ) : 0, 'Sektör ara…', 'Eşlemeyi kaldır' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="card" style="max-width:none;padding:20px;margin-top:20px;">
                    <h2 style="margin-top:0;">Şehir / Lokasyon Eşlemeleri</h2>
                    <p class="description">Otomatik şehir eşleşmesi önce dar bir arama yapar. Manuel override için lokasyon yazıp sonuçtan seçim yapın.</p>
                    <?php if ( empty( $usage['cities'] ) ) : ?>
                        <p>Henüz TOBB şehir verisi bulunamadı.</p>
                    <?php else : ?>
                        <table class="widefat striped" style="margin-top:14px;">
                            <thead><tr><th style="width:180px;">TOBB Şehri</th><th style="width:90px;">Kullanım</th><th>Otomatik Eşleşme</th><th style="width:380px;">Manuel Override</th></tr></thead>
                            <tbody>
                            <?php foreach ( $usage['cities'] as $city_key => $item ) : ?>
                                <?php $auto_term = self::auto_location_term( $item['name'] ); ?>
                                <tr>
                                    <td><strong><?php echo esc_html( $item['name'] ); ?></strong><input type="hidden" name="city_keys[]" value="<?php echo esc_attr( $city_key ); ?>" /></td>
                                    <td><?php echo esc_html( (string) $item['count'] ); ?></td>
                                    <td>
                                        <?php if ( $auto_term ) : ?>
                                            <span style="color:#008a20;font-weight:600;">✓ <?php echo esc_html( self::term_label( $auto_term ) ); ?></span>
                                        <?php else : ?>
                                            <span style="color:#b32d2e;font-weight:600;">Eşleşme yok / belirsiz</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php self::render_picker( 'location', 'location_map[' . esc_attr( $city_key ) . ']', isset( $location_map[ $city_key ] ) ? absint( $location_map[ $city_key ] ) : 0, 'Ülke, şehir veya ilçe ara…', 'Otomatik eşleşmeyi kullan' ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <p class="submit"><button type="submit" class="button button-primary button-hero">Eşlemeleri Kaydet</button></p>
            </form>
        </div>

        <style>
            .sektorel-tobb-picker{position:relative}.sektorel-tobb-picker input[type=search]{width:100%}.sektorel-tobb-results{display:none;position:absolute;left:0;right:0;z-index:9999;max-height:240px;overflow:auto;background:#fff;border:1px solid #8c8f94;box-shadow:0 2px 8px rgba(0,0,0,.15)}.sektorel-tobb-result{display:block;width:100%;border:0;border-bottom:1px solid #f0f0f1;background:#fff;padding:8px 10px;text-align:left;cursor:pointer}.sektorel-tobb-result:hover,.sektorel-tobb-result:focus{background:#f0f6fc;color:#135e96}.sektorel-tobb-selected{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:7px;padding:6px 8px;background:#f6f7f7;border-left:3px solid #2271b1}.sektorel-tobb-clear{white-space:nowrap}
        </style>
        <script>
        jQuery(function($){
            var nonce='<?php echo esc_js( wp_create_nonce( self::NONCE_ACTION ) ); ?>';
            $('.sektorel-tobb-picker').each(function(){
                var root=$(this),input=root.find('.sektorel-tobb-search'),results=root.find('.sektorel-tobb-results'),hidden=root.find('.sektorel-tobb-value'),selected=root.find('.sektorel-tobb-selected'),timer=null,taxonomy=root.data('taxonomy');
                input.on('input',function(){
                    clearTimeout(timer);var q=$.trim(input.val());
                    if(q.length<2){results.hide().empty();return;}
                    timer=setTimeout(function(){
                        $.get(ajaxurl,{action:'<?php echo esc_js( self::SEARCH_ACTION ); ?>',nonce:nonce,taxonomy:taxonomy,q:q}).done(function(r){
                            results.empty();
                            if(!r||!r.success||!r.data.items.length){results.append('<div style="padding:8px 10px;color:#646970;">Sonuç bulunamadı.</div>').show();return;}
                            r.data.items.forEach(function(item){
                                $('<button type="button" class="sektorel-tobb-result"></button>').text(item.label).attr('data-id',item.id).attr('data-label',item.label).appendTo(results);
                            });
                            results.show();
                        });
                    },220);
                });
                results.on('click','.sektorel-tobb-result',function(){
                    var button=$(this);hidden.val(button.data('id'));selected.find('.sektorel-tobb-selected-label').text(button.data('label'));selected.show();input.val('');results.hide().empty();
                });
                root.on('click','.sektorel-tobb-clear',function(){hidden.val('0');selected.hide();input.val('').focus();});
            });
            $(document).on('click',function(e){if(!$(e.target).closest('.sektorel-tobb-picker').length){$('.sektorel-tobb-results').hide();}});
        });
        </script>
        <?php
    }

    private static function render_picker( $taxonomy, $name, $selected_id, $placeholder, $clear_label ) {
        $selected_term = $selected_id ? get_term( $selected_id, $taxonomy ) : null;
        $selected_ok   = $selected_term && ! is_wp_error( $selected_term );
        ?>
        <div class="sektorel-tobb-picker" data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>">
            <input type="search" class="sektorel-tobb-search" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="off" />
            <input type="hidden" class="sektorel-tobb-value" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $selected_ok ? $selected_id : 0 ); ?>" />
            <div class="sektorel-tobb-results"></div>
            <div class="sektorel-tobb-selected" <?php echo $selected_ok ? '' : 'style="display:none;"'; ?>>
                <span class="sektorel-tobb-selected-label"><?php echo $selected_ok ? esc_html( self::term_label( $selected_term ) ) : ''; ?></span>
                <button type="button" class="button-link-delete sektorel-tobb-clear"><?php echo esc_html( $clear_label ); ?></button>
            </div>
        </div>
        <?php
    }

    public static function ajax_term_search() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }

        $taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
        $query     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        if ( ! in_array( $taxonomy, array( 'sector', 'location' ), true ) || mb_strlen( $query ) < 2 ) {
            wp_send_json_success( array( 'items' => array() ) );
        }

        $terms = get_terms( array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'search'     => $query,
            'number'     => self::MAX_RESULTS,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );
        if ( is_wp_error( $terms ) ) {
            wp_send_json_error( array( 'message' => $terms->get_error_message() ) );
        }

        $items = array();
        foreach ( $terms as $term ) {
            $items[] = array( 'id' => absint( $term->term_id ), 'label' => self::term_label( $term ) );
        }
        wp_send_json_success( array( 'items' => $items ) );
    }

    public static function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }
        check_admin_referer( self::NONCE_ACTION, 'sektorel_tobb_taxonomy_ui_nonce' );

        $sector_map = self::sector_map();
        $submitted_sector_map = isset( $_POST['sector_map'] ) && is_array( $_POST['sector_map'] ) ? wp_unslash( $_POST['sector_map'] ) : array();
        foreach ( $submitted_sector_map as $raw_code => $raw_term_id ) {
            $code = absint( $raw_code );
            if ( ! $code ) { continue; }
            $term_id = absint( $raw_term_id );
            if ( ! $term_id ) { unset( $sector_map[ $code ] ); continue; }
            $term = get_term( $term_id, 'sector' );
            if ( $term && ! is_wp_error( $term ) ) { $sector_map[ $code ] = $term_id; }
        }
        update_option( Sektorel_Event_Source_TOBB_Taxonomy::SECTOR_MAP_OPTION, $sector_map, false );

        $location_map = self::location_map();
        $submitted_location_map = isset( $_POST['location_map'] ) && is_array( $_POST['location_map'] ) ? wp_unslash( $_POST['location_map'] ) : array();
        $city_keys = isset( $_POST['city_keys'] ) && is_array( $_POST['city_keys'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['city_keys'] ) ) : array();
        foreach ( array_unique( $city_keys ) as $city_key ) {
            if ( ! $city_key ) { continue; }
            $term_id = isset( $submitted_location_map[ $city_key ] ) ? absint( $submitted_location_map[ $city_key ] ) : 0;
            if ( ! $term_id ) { unset( $location_map[ $city_key ] ); continue; }
            $term = get_term( $term_id, 'location' );
            if ( $term && ! is_wp_error( $term ) ) { $location_map[ $city_key ] = $term_id; }
        }
        update_option( Sektorel_Event_Source_TOBB_Taxonomy::LOCATION_MAP_OPTION, $location_map, false );

        wp_safe_redirect( add_query_arg( array( 'post_type' => 'event', 'page' => self::PAGE_SLUG, 'tobb_mapping_saved' => 1 ), admin_url( 'edit.php' ) ) );
        exit;
    }

    private static function candidate_usage() {
        $ids = get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => 'source_adapter',
            'meta_value'     => Sektorel_Event_Source_TOBB_Taxonomy::ADAPTER,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ) );
        if ( $ids ) { update_meta_cache( 'post', $ids ); }

        $topics = array();
        $cities = array();
        foreach ( $ids as $candidate_id ) {
            $title = get_the_title( $candidate_id );
            foreach ( array( 'tobb_topic_1', 'tobb_topic_2', 'tobb_topic_3' ) as $key ) {
                $code = absint( get_post_meta( $candidate_id, $key, true ) );
                if ( ! $code ) { continue; }
                if ( ! isset( $topics[ $code ] ) ) { $topics[ $code ] = array( 'count' => 0, 'sample' => $title ); }
                $topics[ $code ]['count']++;
            }
            $city = trim( (string) get_post_meta( $candidate_id, 'tobb_city', true ) );
            $city_key = self::normalize_key( $city );
            if ( $city_key ) {
                if ( ! isset( $cities[ $city_key ] ) ) { $cities[ $city_key ] = array( 'name' => $city, 'count' => 0 ); }
                $cities[ $city_key ]['count']++;
            }
        }
        ksort( $topics, SORT_NUMERIC );
        uasort( $cities, static function( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
        return array( 'topics' => $topics, 'cities' => $cities );
    }

    private static function auto_location_term( $city ) {
        $query = trim( (string) $city );
        if ( '' === $query ) { return null; }
        $terms = get_terms( array( 'taxonomy' => 'location', 'hide_empty' => false, 'search' => $query, 'number' => self::MAX_RESULTS ) );
        if ( is_wp_error( $terms ) ) { return null; }
        $needle = self::normalize_key( $city );
        $matches = array();
        foreach ( $terms as $term ) {
            if ( self::normalize_key( $term->name ) === $needle ) { $matches[] = $term; }
        }
        return 1 === count( $matches ) ? $matches[0] : null;
    }

    private static function topic_label( $code ) {
        $groups = Sektorel_Event_Source_TOBB_Taxonomy::TOPIC_GROUPS;
        return isset( $groups[ $code ] ) ? $groups[ $code ] : 'Bilinmeyen TOBB kodu';
    }

    private static function sector_map() {
        $map = get_option( Sektorel_Event_Source_TOBB_Taxonomy::SECTOR_MAP_OPTION, array() );
        return is_array( $map ) ? $map : array();
    }

    private static function location_map() {
        $map = get_option( Sektorel_Event_Source_TOBB_Taxonomy::LOCATION_MAP_OPTION, array() );
        return is_array( $map ) ? $map : array();
    }

    private static function term_label( $term ) {
        if ( ! $term || is_wp_error( $term ) ) { return ''; }
        $parts = array( $term->name );
        $parent_id = absint( $term->parent );
        $guard = 0;
        while ( $parent_id && $guard < 5 ) {
            $parent = get_term( $parent_id, $term->taxonomy );
            if ( ! $parent || is_wp_error( $parent ) ) { break; }
            array_unshift( $parts, $parent->name );
            $parent_id = absint( $parent->parent );
            $guard++;
        }
        return implode( ' › ', $parts );
    }

    private static function normalize_key( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        $value = remove_accents( $value );
        $value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
        $value = preg_replace( '/[^a-z0-9]+/i', '', $value );
        return sanitize_key( (string) $value );
    }
}
