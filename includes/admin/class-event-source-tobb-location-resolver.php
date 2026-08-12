<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolves ambiguous TOBB city names to existing city-level location terms.
 *
 * Important: no scans run during normal admin page load. Resolution/reapply is
 * explicit via a protected admin-post action so the TOBB mapping screen remains
 * cheap and render-safe on constrained hosting.
 */
class Sektorel_Event_Source_TOBB_Location_Resolver {

    const ADAPTER             = 'tobb_fair_calendar';
    const LOCATION_MAP_OPTION = 'sektorel_tobb_location_map_v1';
    const NONCE_ACTION        = 'sektorel_tobb_resolve_city_locations';

    public static function init() {
        add_action( 'admin_post_sektorel_tobb_resolve_city_locations', array( __CLASS__, 'handle_resolve' ) );
        add_action( 'admin_notices', array( __CLASS__, 'render_mapping_notice' ) );
    }

    public static function render_mapping_notice() {
        if ( ! current_user_can( 'manage_options' ) || ! self::is_mapping_page() ) {
            return;
        }

        $resolved  = isset( $_GET['tobb_city_resolved'] ) ? absint( $_GET['tobb_city_resolved'] ) : null;
        $reapplied = isset( $_GET['tobb_city_reapplied'] ) ? absint( $_GET['tobb_city_reapplied'] ) : null;

        if ( null !== $resolved || null !== $reapplied ) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo 'TOBB şehir çözümleme tamamlandı. ';
            echo 'Yeni güvenli şehir eşlemesi: <strong>' . esc_html( (string) absint( $resolved ) ) . '</strong>, ';
            echo 'yeniden uygulanan mevcut etkinlik: <strong>' . esc_html( (string) absint( $reapplied ) ) . '</strong>.';
            echo '</p></div>';
        }

        $action_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=sektorel_tobb_resolve_city_locations' ),
            self::NONCE_ACTION
        );

        echo '<div class="notice notice-info"><p>';
        echo '<strong>TOBB şehir eşleştirmesi:</strong> Aynı isimli il / merkez ilçe kayıtlarında şehir seviyesini güvenli biçimde çözmek için bu işlem yalnızca isteğe bağlı çalışır. ';
        echo '<a class="button button-secondary" style="margin-left:8px;" href="' . esc_url( $action_url ) . '">Şehir eşlemelerini düzelt</a>';
        echo '</p></div>';
    }

    public static function handle_resolve() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }

        check_admin_referer( self::NONCE_ACTION );

        $resolved  = self::sync_city_overrides();
        $reapplied = self::reapply_imported_candidates();

        wp_safe_redirect(
            add_query_arg(
                array(
                    'post_type'           => 'event',
                    'page'                => 'sektorel-tobb-taxonomy-mapping',
                    'tobb_city_resolved'  => absint( $resolved ),
                    'tobb_city_reapplied' => absint( $reapplied ),
                ),
                admin_url( 'edit.php' )
            )
        );
        exit;
    }

    private static function sync_city_overrides() {
        if ( ! taxonomy_exists( 'location' ) ) {
            return 0;
        }

        $cities = self::used_tobb_cities();
        if ( ! $cities ) {
            return 0;
        }

        $map = get_option( self::LOCATION_MAP_OPTION, array() );
        if ( ! is_array( $map ) ) {
            $map = array();
        }

        $terms = get_terms(
            array(
                'taxonomy'   => 'location',
                'hide_empty' => false,
            )
        );
        if ( is_wp_error( $terms ) || ! $terms ) {
            return 0;
        }

        $resolved = 0;

        foreach ( $cities as $city ) {
            $city_key = self::normalize_key( $city );
            if ( ! $city_key || ! empty( $map[ $city_key ] ) ) {
                continue;
            }

            $term = self::preferred_city_term( $city, $terms );
            if ( ! $term ) {
                continue;
            }

            $map[ $city_key ] = absint( $term->term_id );
            $resolved++;
        }

        if ( $resolved > 0 ) {
            update_option( self::LOCATION_MAP_OPTION, $map, false );
        }

        return $resolved;
    }

    private static function preferred_city_term( $city, $terms ) {
        $city_key = self::normalize_key( $city );
        if ( ! $city_key ) {
            return null;
        }

        $exact = array();
        foreach ( $terms as $term ) {
            if ( self::normalize_key( $term->name ) === $city_key ) {
                $exact[] = $term;
            }
        }

        if ( ! $exact ) {
            return null;
        }

        $city_level = array();
        foreach ( $exact as $term ) {
            if ( 'city' === sanitize_key( (string) get_term_meta( $term->term_id, 'location_type', true ) ) ) {
                $city_level[] = $term;
            }
        }

        if ( 1 === count( $city_level ) ) {
            return $city_level[0];
        }

        if ( 0 === count( $city_level ) ) {
            $country_children = array();
            foreach ( $exact as $term ) {
                $parent_id = absint( $term->parent );
                if ( ! $parent_id ) {
                    continue;
                }

                $parent = get_term( $parent_id, 'location' );
                if ( ! $parent || is_wp_error( $parent ) ) {
                    continue;
                }

                if ( 'country' === sanitize_key( (string) get_term_meta( $parent->term_id, 'location_type', true ) ) ) {
                    $country_children[] = $term;
                }
            }

            if ( 1 === count( $country_children ) ) {
                return $country_children[0];
            }
        }

        return 1 === count( $exact ) ? $exact[0] : null;
    }

    private static function used_tobb_cities() {
        $ids = get_posts(
            array(
                'post_type'      => 'event_candidate',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_key'       => 'source_adapter',
                'meta_value'     => self::ADAPTER,
                'no_found_rows'  => true,
            )
        );

        if ( ! $ids ) {
            return array();
        }

        update_meta_cache( 'post', $ids );
        $cities = array();

        foreach ( $ids as $candidate_id ) {
            $city = trim( (string) get_post_meta( $candidate_id, 'tobb_city', true ) );
            if ( ! $city ) {
                continue;
            }

            $key = self::normalize_key( $city );
            if ( $key ) {
                $cities[ $key ] = $city;
            }
        }

        return array_values( $cities );
    }

    private static function reapply_imported_candidates() {
        if ( ! class_exists( 'Sektorel_Event_Source_TOBB_Taxonomy' ) ) {
            return 0;
        }

        $ids = get_posts(
            array(
                'post_type'      => 'event_candidate',
                'post_status'    => 'any',
                'posts_per_page' => 250,
                'fields'         => 'ids',
                'meta_query'     => array(
                    'relation' => 'AND',
                    array(
                        'key'   => 'source_adapter',
                        'value' => self::ADAPTER,
                    ),
                    array(
                        'key'     => 'imported_event_id',
                        'compare' => 'EXISTS',
                    ),
                ),
                'no_found_rows'  => true,
            )
        );

        if ( ! $ids ) {
            return 0;
        }

        update_meta_cache( 'post', $ids );
        $count = 0;

        foreach ( $ids as $candidate_id ) {
            $event_id = absint( get_post_meta( $candidate_id, 'imported_event_id', true ) );
            if ( ! $event_id || 'event' !== get_post_type( $event_id ) || 'trash' === get_post_status( $event_id ) ) {
                continue;
            }

            $result = Sektorel_Event_Source_TOBB_Taxonomy::apply_candidate_to_event( $candidate_id, $event_id );
            if ( ! is_wp_error( $result ) ) {
                $count++;
            }
        }

        return $count;
    }

    private static function normalize_key( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        $value = remove_accents( $value );
        $value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
        $value = preg_replace( '/[^a-z0-9]+/i', '', $value );
        return sanitize_key( (string) $value );
    }

    private static function is_mapping_page() {
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
        $page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

        return 'event' === $post_type && 'sektorel-tobb-taxonomy-mapping' === $page;
    }
}
