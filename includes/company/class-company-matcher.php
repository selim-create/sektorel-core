<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic company identity matcher shared by imports and future sources.
 *
 * No fuzzy matching is performed here. Ambiguous or conflicting evidence fails closed.
 */
class Sektorel_Company_Matcher {

    public static function match( $input ) {
        $input = is_array( $input ) ? $input : array();
        $checks = array(
            array( 'method' => 'mersis_number', 'ids' => self::match_meta_exact( self::digits( $input['mersis_number'] ?? '' ), array( 'mersis_number', '_sektorel_mersis_number' ) ) ),
            array( 'method' => 'tax_number', 'ids' => self::match_meta_exact( self::digits( $input['tax_number'] ?? '' ), array( 'tax_number' ) ) ),
            array( 'method' => 'trade_registry_number', 'ids' => self::match_registry( $input ) ),
            array( 'method' => 'domain', 'ids' => self::match_domains( self::domains( $input ) ) ),
            array( 'method' => 'email', 'ids' => self::match_emails( self::emails( $input ) ) ),
            array( 'method' => 'name_exact', 'ids' => self::match_name( self::raw_name( $input ) ) ),
        );

        $resolved_company_id = 0;
        $resolved_method     = '';
        $evidence            = array();

        foreach ( $checks as $check ) {
            $ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $check['ids'] ) ) ) );
            if ( empty( $ids ) ) {
                continue;
            }

            if ( count( $ids ) > 1 ) {
                return self::result( 'review', 0, $check['method'], true, array(
                    'reason'        => 'ambiguous_' . $check['method'],
                    'candidate_ids' => $ids,
                    'evidence'      => $evidence,
                ) );
            }

            $candidate_id = (int) $ids[0];
            $evidence[] = array(
                'method'     => $check['method'],
                'company_id' => $candidate_id,
            );

            if ( ! $resolved_company_id ) {
                $resolved_company_id = $candidate_id;
                $resolved_method     = $check['method'];
                continue;
            }

            if ( $resolved_company_id !== $candidate_id ) {
                return self::result( 'review', 0, 'conflicting_evidence', true, array(
                    'reason'   => 'conflicting_evidence',
                    'evidence' => $evidence,
                ) );
            }
        }

        if ( $resolved_company_id ) {
            return self::result( 'matched', $resolved_company_id, $resolved_method, false, array(
                'evidence' => $evidence,
            ) );
        }

        return self::result( 'new', 0, 'new', false, array(
            'reason' => 'no_deterministic_match',
        ) );
    }

    private static function result( $status, $company_id, $method, $ambiguous, $evidence ) {
        return array(
            'status'    => (string) $status,
            'id'        => (int) $company_id,
            'method'    => (string) $method,
            'ambiguous' => (bool) $ambiguous,
            'evidence'  => (array) $evidence,
        );
    }

    private static function match_meta_exact( $value, $meta_keys ) {
        global $wpdb;
        $value = trim( (string) $value );
        if ( '' === $value || empty( $meta_keys ) ) {
            return array();
        }

        $placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
        $params       = array_merge( array_values( $meta_keys ), array( $value ) );
        $sql = "SELECT DISTINCT pm.post_id
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key IN ({$placeholders})
                  AND pm.meta_value = %s
                  AND p.post_type = 'company'
                  AND p.post_status NOT IN ('trash','auto-draft')
                LIMIT 3";

        return $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
    }

    private static function match_registry( $input ) {
        $number = trim( sanitize_text_field( (string) ( $input['trade_registry_number'] ?? '' ) ) );
        if ( '' === $number ) {
            return array();
        }

        $ids    = self::match_meta_exact( $number, array( 'trade_registry_number' ) );
        $office = self::normalize_text( $input['trade_registry_office'] ?? '' );

        if ( ! $office || count( $ids ) <= 1 ) {
            return $ids;
        }

        return array_values( array_filter( $ids, static function( $id ) use ( $office ) {
            $stored = self::normalize_text( get_post_meta( (int) $id, 'trade_registry_office', true ) );
            return $stored && $stored === $office;
        } ) );
    }

    private static function match_domains( $domains ) {
        global $wpdb;
        $matches = array();

        foreach ( (array) $domains as $domain ) {
            if ( ! $domain ) {
                continue;
            }

            $ids = get_posts( array(
                'post_type'      => 'company',
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => 3,
                'meta_key'       => '_sektorel_import_domain',
                'meta_value'     => $domain,
                'no_found_rows'  => true,
            ) );

            $like = '%' . $wpdb->esc_like( $domain ) . '%';
            $ids  = array_merge( $ids, $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key IN ('website','_sektorel_additional_websites')
                   AND pm.meta_value LIKE %s
                   AND p.post_type = 'company'
                   AND p.post_status NOT IN ('trash','auto-draft')
                 LIMIT 20",
                $like
            ) ) );

            foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
                if ( in_array( $domain, self::company_domains( $id ), true ) ) {
                    $matches[] = $id;
                }
            }
        }

        return array_values( array_unique( $matches ) );
    }

    private static function match_emails( $emails ) {
        global $wpdb;
        $matches = array();

        foreach ( (array) $emails as $email ) {
            if ( ! $email ) {
                continue;
            }

            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT pm.post_id
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key IN ('email','_sektorel_additional_emails')
                   AND pm.meta_value LIKE %s
                   AND p.post_type = 'company'
                   AND p.post_status NOT IN ('trash','auto-draft')
                 LIMIT 20",
                '%' . $wpdb->esc_like( $email ) . '%'
            ) );

            foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
                if ( in_array( $email, self::company_emails( $id ), true ) ) {
                    $matches[] = $id;
                }
            }
        }

        return array_values( array_unique( $matches ) );
    }

    private static function match_name( $raw_name ) {
        global $wpdb;
        $raw_name = trim( wp_strip_all_tags( (string) $raw_name ) );
        if ( '' === $raw_name ) {
            return array();
        }

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID
             FROM {$wpdb->posts}
             WHERE post_type = 'company'
               AND post_status NOT IN ('trash','auto-draft')
               AND post_title = %s
             LIMIT 3",
            $raw_name
        ) );

        $official_ids = get_posts( array(
            'post_type'      => 'company',
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => 3,
            'meta_key'       => 'official_name',
            'meta_value'     => $raw_name,
            'no_found_rows'  => true,
        ) );

        return array_values( array_unique( array_map( 'intval', array_merge( $ids, $official_ids ) ) ) );
    }

    private static function raw_name( $input ) {
        foreach ( array( 'company_name', 'title', 'official_name' ) as $key ) {
            if ( ! empty( $input[ $key ] ) ) {
                return sanitize_text_field( (string) $input[ $key ] );
            }
        }
        return '';
    }

    public static function normalize_text( $value ) {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = remove_accents( $value );
        $value = mb_strtolower( $value, 'UTF-8' );
        $value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value );
        return trim( preg_replace( '/\s+/u', ' ', $value ) );
    }

    public static function domains( $input ) {
        $values = array();
        foreach ( array( 'website', 'additional_websites', 'website_domains' ) as $key ) {
            if ( empty( $input[ $key ] ) ) {
                continue;
            }
            $raw = is_array( $input[ $key ] ) ? $input[ $key ] : preg_split( '/[|,;\r\n]+/', (string) $input[ $key ] );
            foreach ( (array) $raw as $value ) {
                $domain = self::domain( $value );
                if ( $domain ) {
                    $values[] = $domain;
                }
            }
        }
        return array_values( array_unique( $values ) );
    }

    public static function emails( $input ) {
        $values = array();
        foreach ( array( 'email', 'additional_emails' ) as $key ) {
            if ( empty( $input[ $key ] ) ) {
                continue;
            }
            $raw = is_array( $input[ $key ] ) ? $input[ $key ] : preg_split( '/[|,;\r\n]+/', (string) $input[ $key ] );
            foreach ( (array) $raw as $value ) {
                $email = sanitize_email( trim( (string) $value ) );
                if ( $email && is_email( $email ) ) {
                    $values[] = strtolower( $email );
                }
            }
        }
        return array_values( array_unique( $values ) );
    }

    public static function domain( $value ) {
        $value = trim( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( ! $value ) {
            return '';
        }
        if ( false === strpos( $value, '://' ) ) {
            $value = 'https://' . ltrim( $value, '/' );
        }
        $host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
        return preg_replace( '/^www\./i', '', trim( $host, '. ' ) );
    }

    private static function digits( $value ) {
        return preg_replace( '/\D+/', '', (string) $value );
    }

    private static function company_domains( $company_id ) {
        return self::domains( array(
            'website'             => get_post_meta( $company_id, 'website', true ),
            'additional_websites' => get_post_meta( $company_id, '_sektorel_additional_websites', true ),
            'website_domains'     => get_post_meta( $company_id, '_sektorel_import_domain', true ),
        ) );
    }

    private static function company_emails( $company_id ) {
        return self::emails( array(
            'email'             => get_post_meta( $company_id, 'email', true ),
            'additional_emails' => get_post_meta( $company_id, '_sektorel_additional_emails', true ),
        ) );
    }
}
