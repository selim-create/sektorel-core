<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Compact, read-only sitemap snapshots for the headless frontend.
 *
 * Purpose: avoid walking 10k+ company records through WPGraphQL in 100-item
 * pages when generating sitemap.xml. All data exposed here is already public.
 */
class Sektorel_Sitemap_Snapshot {

    const NAMESPACE = 'sektorel/v1';
    const MAX_COMPANY_LIMIT = 2000;
    const SNAPSHOT_CACHE_TTL = HOUR_IN_SECONDS;

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route(
            self::NAMESPACE,
            '/sitemap/companies',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'companies' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'after_id' => array(
                        'type'              => 'integer',
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                    ),
                    'limit' => array(
                        'type'              => 'integer',
                        'default'           => 1000,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/sitemap/locations',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'locations' ),
                'permission_callback' => '__return_true',
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/sitemap/city-sectors',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'city_sectors' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    public static function companies( WP_REST_Request $request ) {
        global $wpdb;

        $after_id = absint( $request->get_param( 'after_id' ) );
        $limit     = min( max( absint( $request->get_param( 'limit' ) ), 1 ), self::MAX_COMPANY_LIMIT );
        $fetch     = $limit + 1;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_name, post_modified_gmt
                 FROM {$wpdb->posts}
                 WHERE post_type = %s
                   AND post_status = %s
                   AND ID > %d
                 ORDER BY ID ASC
                 LIMIT %d",
                'company',
                'publish',
                $after_id,
                $fetch
            ),
            ARRAY_A
        );

        $has_more = count( $rows ) > $limit;
        if ( $has_more ) {
            $rows = array_slice( $rows, 0, $limit );
        }

        $nodes = array_map(
            static function( $row ) {
                return array(
                    'id'       => (int) $row['ID'],
                    'slug'     => (string) $row['post_name'],
                    'modified' => self::mysql_gmt_to_iso8601( $row['post_modified_gmt'] ?? '' ),
                );
            },
            $rows
        );

        $next_after_id = ! empty( $rows ) ? (int) end( $rows )['ID'] : $after_id;

        return self::response(
            array(
                'nodes'         => $nodes,
                'has_more'      => $has_more,
                'next_after_id' => $has_more ? $next_after_id : null,
                'limit'         => $limit,
            ),
            DAY_IN_SECONDS
        );
    }

    public static function locations() {
        $cache_key = 'sektorel_sitemap_locations_v1';
        $cached    = get_transient( $cache_key );

        if ( is_array( $cached ) ) {
            return self::response( $cached, DAY_IN_SECONDS );
        }

        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT
                t.term_id,
                t.slug,
                tt.parent,
                tm.meta_value AS location_type
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_id = t.term_id
               AND tt.taxonomy = 'location'
             INNER JOIN {$wpdb->termmeta} tm
                ON tm.term_id = t.term_id
               AND tm.meta_key = 'location_type'
             WHERE tm.meta_value IN ('city', 'district')
             ORDER BY t.term_id ASC",
            ARRAY_A
        );

        $cities_by_id = array();
        foreach ( $rows as $row ) {
            if ( 'city' !== (string) $row['location_type'] ) {
                continue;
            }
            $cities_by_id[ (int) $row['term_id'] ] = (string) $row['slug'];
        }

        $cities    = array();
        $districts = array();

        foreach ( $rows as $row ) {
            $type = (string) $row['location_type'];
            $slug = (string) $row['slug'];
            $id   = (int) $row['term_id'];

            if ( 'city' === $type ) {
                $cities[] = array(
                    'id'   => $id,
                    'slug' => $slug,
                );
                continue;
            }

            $parent_id = (int) $row['parent'];
            if ( ! isset( $cities_by_id[ $parent_id ] ) ) {
                continue;
            }

            $districts[] = array(
                'id'        => $id,
                'slug'      => $slug,
                'city_slug' => $cities_by_id[ $parent_id ],
            );
        }

        $payload = array(
            'cities'    => $cities,
            'districts' => $districts,
        );

        set_transient( $cache_key, $payload, self::SNAPSHOT_CACHE_TTL );

        return self::response( $payload, DAY_IN_SECONDS );
    }

    public static function city_sectors() {
        $cache_key = 'sektorel_sitemap_city_sectors_v1';
        $cached    = get_transient( $cache_key );

        if ( is_array( $cached ) ) {
            return self::response( array( 'routes' => $cached ), DAY_IN_SECONDS );
        }

        global $wpdb;

        $sql = "SELECT DISTINCT
                    CASE
                        WHEN location_type.meta_value = 'city' THEN location_term.slug
                        WHEN location_type.meta_value = 'district'
                             AND parent_type.meta_value = 'city' THEN parent_term.slug
                        ELSE NULL
                    END AS city_slug,
                    sector_term.slug AS sector_slug
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} sector_rel
                    ON sector_rel.object_id = p.ID
                INNER JOIN {$wpdb->term_taxonomy} sector_tt
                    ON sector_tt.term_taxonomy_id = sector_rel.term_taxonomy_id
                   AND sector_tt.taxonomy = 'sector'
                INNER JOIN {$wpdb->terms} sector_term
                    ON sector_term.term_id = sector_tt.term_id
                INNER JOIN {$wpdb->term_relationships} location_rel
                    ON location_rel.object_id = p.ID
                INNER JOIN {$wpdb->term_taxonomy} location_tt
                    ON location_tt.term_taxonomy_id = location_rel.term_taxonomy_id
                   AND location_tt.taxonomy = 'location'
                INNER JOIN {$wpdb->terms} location_term
                    ON location_term.term_id = location_tt.term_id
                INNER JOIN {$wpdb->termmeta} location_type
                    ON location_type.term_id = location_term.term_id
                   AND location_type.meta_key = 'location_type'
                LEFT JOIN {$wpdb->term_taxonomy} parent_tt
                    ON parent_tt.term_id = location_tt.parent
                   AND parent_tt.taxonomy = 'location'
                LEFT JOIN {$wpdb->terms} parent_term
                    ON parent_term.term_id = parent_tt.term_id
                LEFT JOIN {$wpdb->termmeta} parent_type
                    ON parent_type.term_id = parent_term.term_id
                   AND parent_type.meta_key = 'location_type'
                WHERE p.post_type = 'company'
                  AND p.post_status = 'publish'
                  AND (
                    location_type.meta_value = 'city'
                    OR (
                        location_type.meta_value = 'district'
                        AND parent_type.meta_value = 'city'
                    )
                  )
                ORDER BY city_slug ASC, sector_slug ASC";

        $rows = $wpdb->get_results( $sql, ARRAY_A );

        $routes = array_values(
            array_filter(
                array_map(
                    static function( $row ) {
                        $city   = sanitize_title( $row['city_slug'] ?? '' );
                        $sector = sanitize_title( $row['sector_slug'] ?? '' );

                        if ( ! $city || ! $sector ) {
                            return null;
                        }

                        return $city . '/' . $sector . '-firmalari';
                    },
                    $rows
                )
            )
        );

        set_transient( $cache_key, $routes, self::SNAPSHOT_CACHE_TTL );

        return self::response( array( 'routes' => $routes ), DAY_IN_SECONDS );
    }

    private static function response( $data, $max_age ) {
        $response = rest_ensure_response( $data );
        $max_age  = max( 60, absint( $max_age ) );

        $response->header( 'Cache-Control', 'public, max-age=300, s-maxage=' . $max_age . ', stale-while-revalidate=86400' );
        $response->header( 'X-Robots-Tag', 'noindex, nofollow' );

        return $response;
    }

    private static function mysql_gmt_to_iso8601( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
            return null;
        }

        $timestamp = strtotime( $value . ' UTC' );
        return false === $timestamp ? null : gmdate( 'c', $timestamp );
    }
}
