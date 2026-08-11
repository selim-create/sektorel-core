<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cross-source event evidence and conservative duplicate collapse.
 *
 * The existing candidate engine remains source-specific by design. This layer
 * runs when a candidate is resolved to an event and records where the data came
 * from. When a conversion has just created a fresh event, it performs one final
 * occurrence-level duplicate check so two sources cannot create two events for
 * the same occurrence during the same bulk operation.
 */
class Sektorel_Event_Source_Evidence {

    const META_KEY        = 'event_source_evidence';
    const MATCH_THRESHOLD = 75;
    const VERSION         = '1360';

    private static $lock = false;

    public static function init() {
        add_action( 'added_post_meta', array( __CLASS__, 'maybe_capture_candidate_resolution' ), 130, 4 );
        add_action( 'updated_post_meta', array( __CLASS__, 'maybe_capture_candidate_resolution' ), 130, 4 );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 90 );
    }

    public static function maybe_capture_candidate_resolution( $meta_id, $object_id, $meta_key, $meta_value ) {
        if ( self::$lock || 'imported_event_id' !== $meta_key || 'event_candidate' !== get_post_type( $object_id ) ) {
            return;
        }

        $candidate_id = absint( $object_id );
        $event_id     = absint( $meta_value );

        if ( ! $candidate_id || ! $event_id || 'event' !== get_post_type( $event_id ) ) {
            return;
        }

        self::$lock = true;

        $target_event_id = $event_id;
        $fresh_event     = absint( get_post_meta( $event_id, 'source_candidate_id', true ) ) === $candidate_id;

        if ( $fresh_event ) {
            $match = self::find_existing_event_match( $candidate_id, $event_id );

            if ( $match && ! empty( $match['event_id'] ) ) {
                $target_event_id = absint( $match['event_id'] );

                self::merge_missing_event_data( $target_event_id, $event_id );
                self::append_evidence( $target_event_id, $candidate_id, $match );

                update_post_meta( $candidate_id, 'imported_event_id', $target_event_id );
                update_post_meta( $candidate_id, 'matched_event_id', $target_event_id );
                update_post_meta( $candidate_id, 'candidate_resolution', 'merged_existing' );
                update_post_meta( $candidate_id, 'candidate_cross_source_match_score', absint( $match['score'] ) );
                update_post_meta( $candidate_id, 'candidate_cross_source_match_signals', array_values( (array) $match['signals'] ) );

                update_post_meta( $event_id, 'duplicate_merged_into_event_id', $target_event_id );
                update_post_meta( $event_id, 'duplicate_merged_at', current_time( 'mysql' ) );
                wp_trash_post( $event_id );
            }
        }

        if ( $target_event_id === $event_id ) {
            self::append_evidence( $target_event_id, $candidate_id );
        }

        self::$lock = false;
    }

    public static function add_meta_boxes() {
        add_meta_box(
            'sektorel_event_source_evidence',
            'Kaynak Kanıtları',
            array( __CLASS__, 'render_meta_box' ),
            'event',
            'side',
            'default'
        );
    }

    public static function render_meta_box( $post ) {
        $evidence = self::evidence_for_event( $post->ID );

        if ( ! $evidence ) {
            echo '<p class="description">Henüz bu etkinliğe bağlanmış kaynak kanıtı yok.</p>';
            return;
        }

        echo '<p><strong>' . esc_html( (string) count( $evidence ) ) . ' kaynak</strong> bu etkinliği destekliyor.</p>';

        foreach ( $evidence as $entry ) {
            $source_name = ! empty( $entry['source_name'] ) ? $entry['source_name'] : 'Kaynak';
            $source_url  = ! empty( $entry['source_url'] ) ? $entry['source_url'] : '';
            $parser      = ! empty( $entry['parser_type'] ) ? strtoupper( $entry['parser_type'] ) : '';
            $last_seen   = ! empty( $entry['last_seen_at'] ) ? $entry['last_seen_at'] : '';
            $values      = ! empty( $entry['values'] ) && is_array( $entry['values'] ) ? $entry['values'] : array();

            echo '<div style="margin:10px 0;padding:10px;border:1px solid #dcdcde;background:#fff;">';
            echo '<div style="font-weight:700;">' . esc_html( $source_name ) . '</div>';

            if ( $source_url ) {
                echo '<div><a href="' . esc_url( $source_url ) . '" target="_blank" rel="noopener noreferrer">Kaynağı aç</a></div>';
            }

            $meta = array_filter( array( $parser, $last_seen ) );
            if ( $meta ) {
                echo '<div style="margin-top:4px;font-size:11px;color:#646970;">' . esc_html( implode( ' · ', $meta ) ) . '</div>';
            }

            $summary = array();
            foreach ( array( 'start_date' => 'Başlangıç', 'end_date' => 'Bitiş', 'venue' => 'Mekan', 'organizer' => 'Organizatör' ) as $key => $label ) {
                if ( ! empty( $values[ $key ] ) ) {
                    $summary[] = $label . ': ' . $values[ $key ];
                }
            }
            if ( $summary ) {
                echo '<div style="margin-top:7px;font-size:11px;color:#50575e;">' . esc_html( implode( ' | ', $summary ) ) . '</div>';
            }

            echo '</div>';
        }
    }

    private static function find_existing_event_match( $candidate_id, $exclude_event_id ) {
        $candidate = self::candidate_record( $candidate_id );
        if ( ! $candidate['title_norm'] || ! $candidate['start_date'] ) {
            return null;
        }

        $ids = get_posts( array(
            'post_type'      => 'event',
            'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'post__not_in'   => array( absint( $exclude_event_id ) ),
            'no_found_rows'  => true,
        ) );

        if ( ! $ids ) {
            return null;
        }

        update_meta_cache( 'post', $ids );
        $best = null;

        foreach ( $ids as $event_id ) {
            $event = self::event_record( absint( $event_id ) );
            $score = self::score_occurrence( $candidate, $event );
            if ( ! $score || $score['score'] < self::MATCH_THRESHOLD ) {
                continue;
            }
            if ( null === $best || $score['score'] > $best['score'] || ( $score['score'] === $best['score'] && $event['id'] < $best['event_id'] ) ) {
                $best = array(
                    'event_id' => $event['id'],
                    'score'    => $score['score'],
                    'signals'  => $score['signals'],
                );
            }
        }

        return $best;
    }

    private static function score_occurrence( $candidate, $event ) {
        if ( self::date_part( $candidate['start_date'] ) !== self::date_part( $event['start_date'] ) ) {
            return null;
        }

        $title_similarity = self::similarity( $candidate['title_norm'], $event['title_norm'] );
        if ( $title_similarity < 60 ) {
            return null;
        }

        $score   = 35;
        $signals = array( 'start_date_exact' );

        if ( 100 === $title_similarity ) {
            $score += 55;
            $signals[] = 'title_exact';
        } elseif ( $title_similarity >= 90 ) {
            $score += 50;
            $signals[] = 'title_90';
        } elseif ( $title_similarity >= 80 ) {
            $score += 40;
            $signals[] = 'title_80';
        } elseif ( $title_similarity >= 70 ) {
            $score += 30;
            $signals[] = 'title_70';
        } else {
            $score += 20;
            $signals[] = 'title_60';
        }

        if ( $candidate['end_date'] && $event['end_date'] && self::date_part( $candidate['end_date'] ) === self::date_part( $event['end_date'] ) ) {
            $score += 10;
            $signals[] = 'end_date_exact';
        }

        $candidate_urls = self::record_urls( $candidate );
        $event_urls     = self::record_urls( $event );
        if ( array_intersect( $candidate_urls['identities'], $event_urls['identities'] ) ) {
            $score += 25;
            $signals[] = 'url_exact';
        } elseif ( array_intersect( $candidate_urls['hosts'], $event_urls['hosts'] ) ) {
            $score += 10;
            $signals[] = 'host_exact';
        }

        $venue_similarity = self::similarity( $candidate['venue_norm'], $event['venue_norm'] );
        if ( $candidate['venue_norm'] && $event['venue_norm'] ) {
            if ( 100 === $venue_similarity ) {
                $score += 15;
                $signals[] = 'venue_exact';
            } elseif ( $venue_similarity >= 70 ) {
                $score += 8;
                $signals[] = 'venue_similar';
            } elseif ( $venue_similarity < 35 && ! in_array( 'url_exact', $signals, true ) && ! in_array( 'host_exact', $signals, true ) ) {
                $score -= 20;
                $signals[] = 'venue_conflict_penalty';
            }
        }

        if ( $candidate['organizer_norm'] && $event['organizer_norm'] && $candidate['organizer_norm'] === $event['organizer_norm'] ) {
            $score += 10;
            $signals[] = 'organizer_exact';
        }

        return array(
            'score'   => max( 0, min( 100, $score ) ),
            'signals' => array_values( array_unique( $signals ) ),
        );
    }

    private static function candidate_record( $candidate_id ) {
        return array(
            'id'                => absint( $candidate_id ),
            'title'             => self::clean_text( get_the_title( $candidate_id ) ),
            'title_norm'        => self::normalize_text( get_the_title( $candidate_id ) ),
            'start_date'        => trim( (string) get_post_meta( $candidate_id, 'start_date', true ) ),
            'end_date'          => trim( (string) get_post_meta( $candidate_id, 'end_date', true ) ),
            'venue'             => trim( (string) get_post_meta( $candidate_id, 'venue', true ) ),
            'venue_norm'        => self::normalize_text( get_post_meta( $candidate_id, 'venue', true ) ),
            'organizer'         => trim( (string) get_post_meta( $candidate_id, 'organizer', true ) ),
            'organizer_norm'    => self::normalize_text( get_post_meta( $candidate_id, 'organizer', true ) ),
            'registration_link' => trim( (string) get_post_meta( $candidate_id, 'registration_link', true ) ),
            'event_url'         => trim( (string) get_post_meta( $candidate_id, 'event_url', true ) ),
            'source_url'        => trim( (string) get_post_meta( $candidate_id, 'source_url', true ) ),
        );
    }

    private static function event_record( $event_id ) {
        $record = array(
            'id'                => absint( $event_id ),
            'title'             => self::clean_text( get_the_title( $event_id ) ),
            'title_norm'        => self::normalize_text( get_the_title( $event_id ) ),
            'start_date'        => trim( (string) get_post_meta( $event_id, 'start_date', true ) ),
            'end_date'          => trim( (string) get_post_meta( $event_id, 'end_date', true ) ),
            'venue'             => trim( (string) get_post_meta( $event_id, 'venue', true ) ),
            'venue_norm'        => self::normalize_text( get_post_meta( $event_id, 'venue', true ) ),
            'organizer'         => trim( (string) get_post_meta( $event_id, 'organizer', true ) ),
            'organizer_norm'    => self::normalize_text( get_post_meta( $event_id, 'organizer', true ) ),
            'registration_link' => trim( (string) get_post_meta( $event_id, 'registration_link', true ) ),
            'event_url'         => trim( (string) get_post_meta( $event_id, 'event_url', true ) ),
            'source_url'        => trim( (string) get_post_meta( $event_id, 'source_url', true ) ),
            'evidence'          => self::evidence_for_event( $event_id ),
        );

        return $record;
    }

    private static function record_urls( $record ) {
        $urls = array();
        foreach ( array( 'event_url', 'source_url', 'registration_link' ) as $key ) {
            if ( ! empty( $record[ $key ] ) ) {
                $urls[] = $record[ $key ];
            }
        }

        if ( ! empty( $record['evidence'] ) && is_array( $record['evidence'] ) ) {
            foreach ( $record['evidence'] as $entry ) {
                foreach ( array( 'event_url', 'source_url' ) as $key ) {
                    if ( ! empty( $entry[ $key ] ) ) {
                        $urls[] = $entry[ $key ];
                    }
                }
            }
        }

        $identities = array();
        $hosts      = array();
        foreach ( array_unique( $urls ) as $url ) {
            $identity = self::url_identity( $url );
            $host     = self::url_host( $url );
            if ( $identity ) {
                $identities[] = $identity;
            }
            if ( $host ) {
                $hosts[] = $host;
            }
        }

        return array(
            'identities' => array_values( array_unique( $identities ) ),
            'hosts'      => array_values( array_unique( $hosts ) ),
        );
    }

    private static function append_evidence( $event_id, $candidate_id, $match = array() ) {
        if ( 'event' !== get_post_type( $event_id ) || 'event_candidate' !== get_post_type( $candidate_id ) ) {
            return;
        }

        $source_id  = absint( get_post_meta( $candidate_id, 'source_id', true ) );
        $source_url = trim( (string) get_post_meta( $candidate_id, 'source_url', true ) );
        $event_url  = trim( (string) get_post_meta( $candidate_id, 'event_url', true ) );
        $parser     = sanitize_key( (string) get_post_meta( $candidate_id, 'parser_type', true ) );
        $source_name = $source_id && 'event_source' === get_post_type( $source_id ) ? self::clean_text( get_the_title( $source_id ) ) : '';

        $key = $source_id
            ? 'source_' . $source_id
            : 'url_' . sha1( self::url_identity( $source_url ?: $event_url ) ?: ( $source_url ?: $event_url ?: (string) $candidate_id ) );

        $evidence = self::evidence_for_event( $event_id );
        $now      = current_time( 'mysql' );
        $existing = isset( $evidence[ $key ] ) && is_array( $evidence[ $key ] ) ? $evidence[ $key ] : array();

        $entry = array(
            'version'        => self::VERSION,
            'source_id'      => $source_id,
            'source_name'    => $source_name,
            'source_url'     => esc_url_raw( $source_url, array( 'http', 'https' ) ),
            'event_url'      => esc_url_raw( $event_url, array( 'http', 'https' ) ),
            'parser_type'    => $parser,
            'candidate_id'   => absint( $candidate_id ),
            'first_seen_at'  => ! empty( $existing['first_seen_at'] ) ? $existing['first_seen_at'] : $now,
            'last_seen_at'   => $now,
            'match_score'    => ! empty( $match['score'] ) ? absint( $match['score'] ) : 0,
            'match_signals'  => ! empty( $match['signals'] ) ? array_values( (array) $match['signals'] ) : array(),
            'values'         => self::candidate_values( $candidate_id ),
        );

        $evidence[ $key ] = $entry;
        update_post_meta( $event_id, self::META_KEY, $evidence );
        update_post_meta( $event_id, 'event_source_evidence_count', count( $evidence ) );
        update_post_meta( $event_id, 'event_source_evidence_updated_at', $now );
    }

    private static function candidate_values( $candidate_id ) {
        $description = self::clean_text( get_post_field( 'post_content', $candidate_id ) );
        if ( function_exists( 'mb_substr' ) ) {
            $description = mb_substr( $description, 0, 500, 'UTF-8' );
        } else {
            $description = substr( $description, 0, 500 );
        }

        return array(
            'title'               => self::clean_text( get_the_title( $candidate_id ) ),
            'start_date'          => trim( (string) get_post_meta( $candidate_id, 'start_date', true ) ),
            'end_date'            => trim( (string) get_post_meta( $candidate_id, 'end_date', true ) ),
            'location_type'       => trim( (string) get_post_meta( $candidate_id, 'location_type', true ) ),
            'venue'               => self::clean_text( get_post_meta( $candidate_id, 'venue', true ) ),
            'address'             => self::clean_text( get_post_meta( $candidate_id, 'address', true ) ),
            'organizer'           => self::clean_text( get_post_meta( $candidate_id, 'organizer', true ) ),
            'registration_link'   => trim( (string) get_post_meta( $candidate_id, 'registration_link', true ) ),
            'description_excerpt' => $description,
        );
    }

    private static function merge_missing_event_data( $primary_id, $duplicate_id ) {
        foreach ( array( 'start_date', 'end_date', 'location_type', 'venue', 'address', 'organizer', 'price', 'registration_link', 'event_url', 'source_url' ) as $key ) {
            $primary_value   = get_post_meta( $primary_id, $key, true );
            $duplicate_value = get_post_meta( $duplicate_id, $key, true );
            if ( self::is_empty( $primary_value ) && ! self::is_empty( $duplicate_value ) ) {
                update_post_meta( $primary_id, $key, $duplicate_value );
            }
        }

        $primary_content   = trim( (string) get_post_field( 'post_content', $primary_id ) );
        $duplicate_content = trim( (string) get_post_field( 'post_content', $duplicate_id ) );
        if ( '' === $primary_content && '' !== $duplicate_content ) {
            wp_update_post( array( 'ID' => $primary_id, 'post_content' => $duplicate_content ) );
        }

        $duplicate_evidence = self::evidence_for_event( $duplicate_id );
        if ( $duplicate_evidence ) {
            $primary_evidence = self::evidence_for_event( $primary_id );
            foreach ( $duplicate_evidence as $key => $entry ) {
                if ( ! isset( $primary_evidence[ $key ] ) ) {
                    $primary_evidence[ $key ] = $entry;
                }
            }
            update_post_meta( $primary_id, self::META_KEY, $primary_evidence );
            update_post_meta( $primary_id, 'event_source_evidence_count', count( $primary_evidence ) );
        }
    }

    private static function evidence_for_event( $event_id ) {
        $evidence = get_post_meta( $event_id, self::META_KEY, true );
        return is_array( $evidence ) ? $evidence : array();
    }

    private static function similarity( $left, $right ) {
        if ( ! $left || ! $right ) {
            return 0;
        }
        if ( $left === $right ) {
            return 100;
        }
        $percent = 0.0;
        similar_text( $left, $right, $percent );
        return (int) round( $percent );
    }

    private static function normalize_text( $value ) {
        $value = strtolower( remove_accents( self::clean_text( $value ) ) );
        $value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }

    private static function clean_text( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = wp_strip_all_tags( $value );
        $value = str_replace( "\xC2\xA0", ' ', $value );
        return sanitize_text_field( preg_replace( '/\s+/u', ' ', trim( $value ) ) );
    }

    private static function date_part( $value ) {
        return preg_match( '/^(\d{4}-\d{2}-\d{2})/', trim( (string) $value ), $matches ) ? $matches[1] : '';
    }

    private static function url_identity( $url ) {
        $parts = wp_parse_url( trim( (string) $url ) );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return '';
        }

        $scheme = ! empty( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
        $host   = strtolower( rtrim( $parts['host'], '.' ) );
        $path   = isset( $parts['path'] ) ? '/' . ltrim( $parts['path'], '/' ) : '/';
        $path   = '/' === $path ? '/' : untrailingslashit( $path );

        return $scheme . '://' . $host . $path;
    }

    private static function url_host( $url ) {
        $host = wp_parse_url( trim( (string) $url ), PHP_URL_HOST );
        return $host ? strtolower( rtrim( $host, '.' ) ) : '';
    }

    private static function is_empty( $value ) {
        return '' === $value || null === $value || array() === $value;
    }
}
