<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Source Coverage Registry + source-specific policy contract for the Content Engine.
 *
 * The registry describes what a source is expected to cover without coupling that
 * metadata to candidate routing. Existing deterministic triage stays authoritative;
 * these fields are coverage/provenance inputs for source planning and future
 * source-specific adapters.
 */
class Sektorel_Content_Source_Policy {

    const DETAIL_FEED_ONLY      = 'feed_only';
    const DETAIL_PAGE_PREFERRED = 'detail_page_preferred';
    const DETAIL_PAGE_REQUIRED  = 'detail_page_required';

    const IMAGE_PEXELS_ONLY      = 'pexels_only';
    const IMAGE_SOURCE_PREFERRED = 'source_preferred';
    const IMAGE_NONE             = 'none';

    const SOURCE_TIER_A = 'a';
    const SOURCE_TIER_B = 'b';
    const SOURCE_TIER_C = 'c';
    const SOURCE_TIER_D = 'd';

    /**
     * Canonical coverage metadata contract.
     *
     * primary_desk is singular by design. topic_scope/sector_scope are bounded
     * slug lists. geography is a lightweight coverage label, never a location
     * taxonomy enumeration (the production location taxonomy is intentionally
     * not loaded here).
     */
    public static function coverage_fields() {
        return array(
            'primary_desk',
            'topic_scope',
            'sector_scope',
            'geography',
            'source_tier',
            'detail_strategy',
            'image_policy',
        );
    }

    /**
     * Return a normalized coverage profile for a source key/source post.
     *
     * Stored post meta overrides registry defaults when the field exists. This is
     * deliberately existence-based so an explicit empty primary_desk/topic scope
     * can clear a registry default without deleting the source profile itself.
     */
    public static function coverage_profile( $source_key, $source_id = 0 ) {
        $source_key = sanitize_key( $source_key );
        $source_id  = absint( $source_id );
        $profiles   = self::registered_profiles();
        $profile    = array_merge(
            self::coverage_defaults(),
            isset( $profiles[ $source_key ] ) && is_array( $profiles[ $source_key ] ) ? $profiles[ $source_key ] : array()
        );

        if ( $source_id ) {
            foreach ( self::coverage_fields() as $field ) {
                if ( ! metadata_exists( 'post', $source_id, $field ) ) {
                    continue;
                }
                $profile[ $field ] = get_post_meta( $source_id, $field, true );
            }
        }

        $profile = self::normalize_profile( $profile );
        $profile = apply_filters( 'sektorel_content_source_coverage_profile', $profile, $source_key, $source_id );

        return self::normalize_profile( is_array( $profile ) ? $profile : array() );
    }

    public static function primary_desk( $source_key, $source_id = 0 ) {
        $profile = self::coverage_profile( $source_key, $source_id );
        return $profile['primary_desk'];
    }

    public static function topic_scope( $source_key, $source_id = 0 ) {
        $profile = self::coverage_profile( $source_key, $source_id );
        return $profile['topic_scope'];
    }

    public static function sector_scope( $source_key, $source_id = 0 ) {
        $profile = self::coverage_profile( $source_key, $source_id );
        return $profile['sector_scope'];
    }

    public static function geography( $source_key, $source_id = 0 ) {
        $profile = self::coverage_profile( $source_key, $source_id );
        return $profile['geography'];
    }

    public static function source_tier( $source_key, $source_id = 0 ) {
        $profile = self::coverage_profile( $source_key, $source_id );
        return $profile['source_tier'];
    }

    public static function detail_strategy( $source_key, $source_id = 0 ) {
        $profile  = self::coverage_profile( $source_key, $source_id );
        $strategy = sanitize_key( apply_filters(
            'sektorel_content_detail_strategy',
            $profile['detail_strategy'],
            sanitize_key( $source_key )
        ) );

        return in_array( $strategy, self::detail_strategies(), true ) ? $strategy : self::DETAIL_FEED_ONLY;
    }

    public static function image_policy( $source_key, $source_id = 0 ) {
        $profile = self::coverage_profile( $source_key, $source_id );
        $policy  = sanitize_key( apply_filters(
            'sektorel_content_source_image_policy',
            $profile['image_policy'],
            sanitize_key( $source_key ),
            absint( $source_id )
        ) );

        return in_array( $policy, self::image_policies(), true ) ? $policy : self::IMAGE_PEXELS_ONLY;
    }

    public static function allowed_detail_hosts( $source_key ) {
        $source_key = sanitize_key( $source_key );
        $hosts = array(
            'tcmb_press'         => array( 'tcmb.gov.tr', 'www.tcmb.gov.tr' ),
            'tobb_news'          => array( 'tobb.org.tr', 'www.tobb.org.tr' ),
            'tobb_announcements' => array( 'tobb.org.tr', 'www.tobb.org.tr' ),
        );

        $allowed = $hosts[ $source_key ] ?? array();
        $allowed = apply_filters( 'sektorel_content_detail_allowed_hosts', $allowed, $source_key );

        return array_values( array_unique( array_filter( array_map( static function( $host ) {
            return strtolower( rtrim( trim( (string) $host ), '.' ) );
        }, (array) $allowed ) ) ) );
    }

    public static function source_tiers() {
        return array(
            self::SOURCE_TIER_A,
            self::SOURCE_TIER_B,
            self::SOURCE_TIER_C,
            self::SOURCE_TIER_D,
        );
    }

    public static function detail_strategies() {
        return array(
            self::DETAIL_FEED_ONLY,
            self::DETAIL_PAGE_PREFERRED,
            self::DETAIL_PAGE_REQUIRED,
        );
    }

    public static function image_policies() {
        return array(
            self::IMAGE_PEXELS_ONLY,
            self::IMAGE_SOURCE_PREFERRED,
            self::IMAGE_NONE,
        );
    }

    private static function coverage_defaults() {
        return array(
            'primary_desk'    => '',
            'topic_scope'     => array(),
            'sector_scope'    => array(),
            'geography'       => 'unspecified',
            'source_tier'     => self::SOURCE_TIER_D,
            'detail_strategy' => self::DETAIL_FEED_ONLY,
            'image_policy'    => self::IMAGE_PEXELS_ONLY,
        );
    }

    /**
     * Initial registry profiles for already-deployed official sources.
     *
     * Broad TOBB feeds intentionally have no forced primary desk. Candidate
     * triage must keep failing closed when text evidence is weak.
     */
    private static function registered_profiles() {
        return array(
            'tcmb_press' => array(
                'primary_desk'    => 'ekonomi-piyasalar',
                'topic_scope'     => array( 'para-politikasi', 'fiyat-istikrari', 'odeme-sistemleri' ),
                'sector_scope'    => array( 'finans' ),
                'geography'       => 'tr-national',
                'source_tier'     => self::SOURCE_TIER_A,
                'detail_strategy' => self::DETAIL_PAGE_REQUIRED,
                'image_policy'    => self::IMAGE_SOURCE_PREFERRED,
            ),
            'tobb_news' => array(
                'primary_desk'    => '',
                'topic_scope'     => array( 'is-dunyasi' ),
                'sector_scope'    => array( 'multi-sector' ),
                'geography'       => 'tr-national',
                'source_tier'     => self::SOURCE_TIER_A,
                'detail_strategy' => self::DETAIL_PAGE_PREFERRED,
                'image_policy'    => self::IMAGE_SOURCE_PREFERRED,
            ),
            'tobb_announcements' => array(
                'primary_desk'    => '',
                'topic_scope'     => array( 'duyurular' ),
                'sector_scope'    => array( 'multi-sector' ),
                'geography'       => 'tr-national',
                'source_tier'     => self::SOURCE_TIER_A,
                'detail_strategy' => self::DETAIL_PAGE_PREFERRED,
                'image_policy'    => self::IMAGE_SOURCE_PREFERRED,
            ),
        );
    }

    private static function normalize_profile( $profile ) {
        $defaults = self::coverage_defaults();
        $profile  = array_merge( $defaults, is_array( $profile ) ? $profile : array() );

        $profile['primary_desk'] = sanitize_title( (string) $profile['primary_desk'] );
        $profile['topic_scope']  = self::normalize_slug_list( $profile['topic_scope'] );
        $profile['sector_scope'] = self::normalize_slug_list( $profile['sector_scope'] );
        $profile['geography']    = sanitize_title( (string) $profile['geography'] );
        if ( '' === $profile['geography'] ) {
            $profile['geography'] = 'unspecified';
        }

        $profile['source_tier'] = sanitize_key( (string) $profile['source_tier'] );
        if ( ! in_array( $profile['source_tier'], self::source_tiers(), true ) ) {
            $profile['source_tier'] = self::SOURCE_TIER_D;
        }

        $profile['detail_strategy'] = sanitize_key( (string) $profile['detail_strategy'] );
        if ( ! in_array( $profile['detail_strategy'], self::detail_strategies(), true ) ) {
            $profile['detail_strategy'] = self::DETAIL_FEED_ONLY;
        }

        $profile['image_policy'] = sanitize_key( (string) $profile['image_policy'] );
        if ( ! in_array( $profile['image_policy'], self::image_policies(), true ) ) {
            $profile['image_policy'] = self::IMAGE_PEXELS_ONLY;
        }

        return $profile;
    }

    private static function normalize_slug_list( $value ) {
        if ( is_string( $value ) ) {
            $value = explode( ',', $value );
        }

        return array_values( array_unique( array_filter( array_map( static function( $item ) {
            return sanitize_title( trim( (string) $item ) );
        }, (array) $value ) ) ) );
    }
}
