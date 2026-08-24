<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic company identity matcher shared by imports and future sources.
 *
 * No fuzzy matching is performed here. Ambiguous evidence always fails closed.
 */
class Sektorel_Company_Matcher {

    public static function match( $input ) {
        $input = is_array( $input ) ? $input : array();

        $checks = array(
            array( 'method' => 'mersis_number', 'value' => self::digits( $input['mersis_number'] ?? '' ), 'resolver' => 'match_meta_exact', 'meta_keys' => array( 'mersis_number', '_sektorel_mersis_number' ) ),
            array( 'method' => 'tax_number', 'value' => self::digits( $input['tax_number'] ?? '' ), 'resolver' => 'match_meta_exact', 'meta_keys' => array( 'tax_number' ) ),
            array( 'method' => 'trade_registry_number', 'value' => self::normalize_text( $input['trade_registry_number'] ?? '' ), 'resolver' => 'match_registry' ),
            array( 'method' => 'domain', 'value' => self::domains( $input ), 'resolver' => 'match_domains' ),
            array( 'method' => 'email', 'value' => self::emails( $input ), 'resolver' => 'match_emails' ),
            array( 'method' => 'name_exact', 'value' => self::normalize_text( $input['company_name'] ?? $input['title'] ?? $input['official_name'] ?? '' ), 'resolver' => 'match_name' ),
        );

        $evidence = array();
        foreach ( $checks as $check ) {
            if ( empty( $check['value'] ) ) {
                continue;
            }

            $resolver = $check['resolver'];
            if ( 'match_meta_exact' === $resolver ) {
                $ids = self::match_meta_exact( $check['value'], $check['meta_keys'] );
            } elseif ( 'match_registry' === $resolver ) {
                $ids = self::match_registry( $input );
            } else {
                $ids = self::$resolver( $check['value'] );
            }

            $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
            if ( count( $ids ) > 1 ) {
                return self::result( 'review', 0, $check['method'], true, array(
                    'reason' => 'ambiguous_' . $check['method'],
                    'candidate_ids' => $ids,
                ) );
            }
            if ( 1 === count( $ids ) ) {
                $company_id = (int) $ids[0];
                $evidence[] = array( 'method' => $check['method'], 'company_id' => $company_id );

                // A later strong signal pointing elsewhere must fail closed.
                foreach ( array_slice( $checks, array_search( $check, $checks, true ) + 1 ) as $later_check ) {
                    if ( empty( $later_check['value'] ) ) {
                        continue;
                    }
                    $later_ids = array();
                    if ( 'match_meta_exact' === $later_check['resolver'] ) {
                        $later_ids = self::match_meta_exact( $later_check['value'], $later_check['meta_keys'] );
                    } elseif ( 'match_registry' === $later_check['resolver'] ) {
                        $later_ids = self::match_registry( $input );
                    } else {
                        $later_resolver = $later_check['resolver'];
                        $later_ids = self::$later_resolver( $later_check['value'] );
                    }
                    $later_ids = array_values( array_unique( array_filter( array_map( 'intval', $later_ids ) ) ) );
                    if ( $later_ids && ( 1 !== count( $later_ids ) || (int) $later_ids[0] !== $company_id ) ) {
                        return self::result( 'review', 0, 'conflicting_evidence', true, array(
                            'reason' => 'conflicting_evidence',
                            'primary' => $evidence,
                            'conflict_method' => $later_check['method'],
                            'candidate_ids' => $later_ids,
                        ) );
                    }
                }

                return self::result( 'matched', $company_id, $check['method'], false, array(
                    'evidence' => $evidence,
                ) );
            }
        }

        return self::result( 'new', 0, 'new', false, array( 'reason' => 'no_deterministic_match' ) );
    }

    private static function result( $status, $company_id, $method, $ambiguous, $evidence ) {
        return array(
            'status'     => $status,
            'id'         => (int) $company_id,
            'method'     => (string) $method,
            'ambiguous'  => (bool) $ambiguous,
            'evidence'   => $evidence,
        );
    }

    private static function match_meta_exact( $value, $meta_keys ) {
        global $wpdb;
        if ( '' === (string) $value ) {
            return array();
        }

        $placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
        $params = array_merge( $meta_keys, array( (string) $value ) );
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
        $number = self::normalize_text( $input['trade_registry_number'] ?? '' );
        if ( '' === $number ) {
            return array();
        }

        $ids = self::match_meta_exact( $number, array( 'trade_registry_number' ) );
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
            $ids = array_merge( $ids, $wpdb->get_col( $wpdb->prepare(
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
                $candidate_domains = self::company_domains( $id );
                if ( in_array( $domain, $candidate_domains, true ) ) {
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

    private static function match_name( $normalized_name ) {
        global $wpdb;
        if ( '' === $normalized_name ) {
            return array();
        }

        $ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'company'
               AND post_status NOT IN ('trash','auto-draft')
             ORDER BY ID DESC"
        );

        $matches = array();
        foreach ( $ids as $id ) {
            $title = self::normalize_text( get_the_title( (int) $id ) );
            $official = self::normalize_text( get_post_meta( (int) $id, 'official_name', true ) );
            if ( $normalized_name === $title || ( $official && $normalized_name === $official ) ) {
                $matches[] = (int) $id;
                if ( count( $matches ) >= 3 ) {
                    break;
                }
            }
        }
        return $matches;
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
        $input = array(
            'website' => get_post_meta( $company_id, 'website', true ),
            'additional_websites' => get_post_meta( $company_id, '_sektorel_additional_websites', true ),
            'website_domains' => get_post_meta( $company_id, '_sektorel_import_domain', true ),
        );
        return self::domains( $input );
    }

    private static function company_emails( $company_id ) {
        return self::emails( array(
            'email' => get_post_meta( $company_id, 'email', true ),
            'additional_emails' => get_post_meta( $company_id, '_sektorel_additional_emails', true ),
        ) );
    }
}
