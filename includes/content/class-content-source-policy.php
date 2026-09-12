<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Source-specific policy registry for the Content Engine.
 *
 * Unknown sources stay conservative. Official sources can opt into deterministic
 * detail-page enrichment and source-first imagery without leaking those rules
 * into the generic scanner/AI pipeline.
 */
class Sektorel_Content_Source_Policy {

    const DETAIL_FEED_ONLY       = 'feed_only';
    const DETAIL_PAGE_PREFERRED  = 'detail_page_preferred';
    const DETAIL_PAGE_REQUIRED   = 'detail_page_required';

    const IMAGE_PEXELS_ONLY      = 'pexels_only';
    const IMAGE_SOURCE_PREFERRED = 'source_preferred';
    const IMAGE_NONE             = 'none';

    public static function detail_strategy( $source_key ) {
        $source_key = sanitize_key( $source_key );
        $strategies = array(
            'tcmb_press'         => self::DETAIL_PAGE_REQUIRED,
            'tobb_news'          => self::DETAIL_PAGE_PREFERRED,
            'tobb_announcements' => self::DETAIL_PAGE_PREFERRED,
        );

        $strategy = $strategies[ $source_key ] ?? self::DETAIL_FEED_ONLY;
        return sanitize_key( apply_filters( 'sektorel_content_detail_strategy', $strategy, $source_key ) );
    }

    public static function image_policy( $source_key, $source_id = 0 ) {
        $source_key = sanitize_key( $source_key );
        $source_id  = absint( $source_id );

        if ( $source_id ) {
            $stored = sanitize_key( (string) get_post_meta( $source_id, 'image_policy', true ) );
            if ( in_array( $stored, self::image_policies(), true ) ) {
                return $stored;
            }
        }

        $defaults = array(
            'tcmb_press'         => self::IMAGE_SOURCE_PREFERRED,
            'tobb_news'          => self::IMAGE_SOURCE_PREFERRED,
            'tobb_announcements' => self::IMAGE_SOURCE_PREFERRED,
        );
        $policy = $defaults[ $source_key ] ?? self::IMAGE_PEXELS_ONLY;

        $policy = sanitize_key( apply_filters( 'sektorel_content_source_image_policy', $policy, $source_key, $source_id ) );
        return in_array( $policy, self::image_policies(), true ) ? $policy : self::IMAGE_PEXELS_ONLY;
    }

    public static function allowed_detail_hosts( $source_key ) {
        $source_key = sanitize_key( $source_key );
        $hosts = array(
            'tcmb_press' => array( 'tcmb.gov.tr', 'www.tcmb.gov.tr' ),
            'tobb_news' => array( 'tobb.org.tr', 'www.tobb.org.tr' ),
            'tobb_announcements' => array( 'tobb.org.tr', 'www.tobb.org.tr' ),
        );

        $allowed = $hosts[ $source_key ] ?? array();
        $allowed = apply_filters( 'sektorel_content_detail_allowed_hosts', $allowed, $source_key );

        return array_values( array_unique( array_filter( array_map( static function( $host ) {
            return strtolower( rtrim( trim( (string) $host ), '.' ) );
        }, (array) $allowed ) ) ) );
    }

    public static function image_policies() {
        return array(
            self::IMAGE_PEXELS_ONLY,
            self::IMAGE_SOURCE_PREFERRED,
            self::IMAGE_NONE,
        );
    }
}
