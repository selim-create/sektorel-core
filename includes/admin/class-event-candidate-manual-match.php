<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Candidate_Manual_Match {

    const NONCE_ACTION = 'sektorel_candidate_manual_match';
    const MAX_RESULTS  = 12;

    public static function init() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ), 95 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_sektorel_candidate_event_search', array( __CLASS__, 'ajax_event_search' ) );
        add_action( 'admin_post_sektorel_manual_match_candidate', array( __CLASS__, 'handle_manual_match' ) );
        add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 230, 2 );
    }

    public static function add_meta_box() {
        add_meta_box(
            'sektorel_candidate_manual_match',
            'Manuel Etkinlik Eşleştirme',
            array( __CLASS__, 'render_meta_box' ),
            'event_candidate',
            'side',
            'high'
        );
    }

    public static function enqueue_assets( $hook ) {
        if ( 'post.php' !== $hook ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'event_candidate' !== $screen->post_type ) {
            return;
        }
        wp_enqueue_script( 'jquery-ui-autocomplete' );
    }

    public static function render_meta_box( $post ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $matched_event_id = absint( get_post_meta( $post->ID, 'matched_event_id', true ) );
        $candidate_start  = self::date_part( get_post_meta( $post->ID, 'start_date', true ) );
        $role = class_exists( 'Sektorel_Event_Source_Role' )
            ? Sektorel_Event_Source_Role::role_for_candidate( $post->ID )
            : 'discovery';

        if ( $matched_event_id && 'event' === get_post_type( $matched_event_id ) ) {
            echo '<p><strong>Mevcut eşleşme:</strong><br><a href="' . esc_url( get_edit_post_link( $matched_event_id, 'url' ) ) . '">' . esc_html( get_the_title( $matched_event_id ) ) . '</a></p>';
        }

        if ( class_exists( 'Sektorel_Event_Source_Role' ) && ! Sektorel_Event_Source_Role::can_create_event( $role ) ) {
            echo '<p class="description">Bu aday zenginleştirme kaynağından geliyor. Yeni Event oluşturmak yerine mevcut occurrence seçilmelidir.</p>';
        }

        echo '<p><strong>Aday:</strong><br>' . esc_html( get_the_title( $post->ID ) ) . '</p>';
        echo '<p><strong>Başlangıç:</strong> ' . esc_html( $candidate_start ?: '—' ) . '</p>';
        echo '<p><label for="sektorel-manual-match-search"><strong>Mevcut etkinlik ara</strong></label></p>';
        echo '<input type="text" id="sektorel-manual-match-search" class="widefat" autocomplete="off" placeholder="Başlık yazarak ara…">';
        echo '<div id="sektorel-manual-match-selected" style="display:none;margin-top:10px;padding:8px;background:#f6f7f7;border-left:3px solid #2271b1;"></div>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;">';
        wp_nonce_field( self::NONCE_ACTION . '_' . $post->ID, 'sektorel_manual_match_nonce' );
        echo '<input type="hidden" name="action" value="sektorel_manual_match_candidate">';
        echo '<input type="hidden" name="candidate_id" value="' . absint( $post->ID ) . '">';
        echo '<input type="hidden" name="event_id" id="sektorel-manual-match-event-id" value="">';
        echo '<button type="submit" class="button button-primary" id="sektorel-manual-match-submit" disabled>Bu Etkinlikle Eşleştir</button>';
        echo '</form>';
        echo '<p class="description">Seçilen Event üzerinde yalnız boş alanlar tamamlanır. Dolu ve farklı değerler overwrite edilmez.</p>';
        ?>
        <script>
        jQuery(function($){
            var $search=$('#sektorel-manual-match-search'),$eventId=$('#sektorel-manual-match-event-id'),$selected=$('#sektorel-manual-match-selected'),$submit=$('#sektorel-manual-match-submit');
            $search.autocomplete({
                minLength:0,
                delay:180,
                source:function(request,response){
                    $.post(ajaxurl,{action:'sektorel_candidate_event_search',nonce:'<?php echo esc_js( wp_create_nonce( self::NONCE_ACTION . '_search' ) ); ?>',candidate_id:<?php echo absint( $post->ID ); ?>,term:request.term||''}).done(function(r){
                        response(r&&r.success&&r.data&&r.data.items?r.data.items:[]);
                    }).fail(function(){response([]);});
                },
                focus:function(event,ui){event.preventDefault();$search.val(ui.item.label);},
                select:function(event,ui){event.preventDefault();$search.val(ui.item.label);$eventId.val(ui.item.id);$selected.text('Seçilen: '+ui.item.label).show();$submit.prop('disabled',false);}
            }).on('focus',function(){if(!$(this).val()){$(this).autocomplete('search','');}}).on('input',function(){$eventId.val('');$selected.hide().empty();$submit.prop('disabled',true);});
        });
        </script>
        <?php
    }

    public static function ajax_event_search() {
        check_ajax_referer( self::NONCE_ACTION . '_search', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }

        $candidate_id = isset( $_POST['candidate_id'] ) ? absint( $_POST['candidate_id'] ) : 0;
        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) ) {
            wp_send_json_error( array( 'message' => 'Geçersiz aday.' ) );
        }

        $term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
        $candidate_title = self::normalize_text( get_the_title( $candidate_id ) );
        $candidate_date  = self::date_part( get_post_meta( $candidate_id, 'start_date', true ) );

        $ids = get_posts( array(
            'post_type'      => 'event',
            'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ) );

        update_meta_cache( 'post', $ids );
        $term_norm = self::normalize_text( $term );
        $items = array();

        foreach ( $ids as $event_id ) {
            $event_id   = absint( $event_id );
            $title      = self::clean_text( get_the_title( $event_id ) );
            $title_norm = self::normalize_text( $title );
            $event_date = self::date_part( get_post_meta( $event_id, 'start_date', true ) );

            if ( $term_norm && false === strpos( $title_norm, $term_norm ) && self::similarity( $term_norm, $title_norm ) < 35 ) {
                continue;
            }

            $score    = self::similarity( $candidate_title, $title_norm );
            $date_gap = self::date_gap_days( $candidate_date, $event_date );
            if ( 0 === $date_gap ) {
                $score += 60;
            } elseif ( null !== $date_gap && $date_gap <= 7 ) {
                $score += 30;
            } elseif ( null !== $date_gap && $date_gap <= 31 ) {
                $score += 10;
            } elseif ( null !== $date_gap && $date_gap > 60 ) {
                $score -= 40;
            }

            $status = get_post_status( $event_id );
            $status_label = 'publish' === $status ? 'Yayında' : ( 'draft' === $status ? 'Taslak' : ucfirst( (string) $status ) );
            $items[] = array(
                'id'    => $event_id,
                'value' => $title,
                'label' => $title . ' — ' . ( $event_date ?: 'tarih yok' ) . ' — ' . $status_label,
                'score' => $score,
            );
        }

        usort( $items, function( $a, $b ) {
            if ( $a['score'] === $b['score'] ) {
                return $a['id'] <=> $b['id'];
            }
            return $b['score'] <=> $a['score'];
        } );

        wp_send_json_success( array( 'items' => array_slice( $items, 0, self::MAX_RESULTS ) ) );
    }

    public static function handle_manual_match() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }

        $candidate_id = isset( $_POST['candidate_id'] ) ? absint( $_POST['candidate_id'] ) : 0;
        $event_id     = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
        $nonce        = isset( $_POST['sektorel_manual_match_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sektorel_manual_match_nonce'] ) ) : '';

        if ( ! $candidate_id || 'event_candidate' !== get_post_type( $candidate_id ) || ! wp_verify_nonce( $nonce, self::NONCE_ACTION . '_' . $candidate_id ) ) {
            wp_die( 'Geçersiz veya süresi dolmuş eşleştirme isteği.' );
        }
        if ( ! $event_id || 'event' !== get_post_type( $event_id ) || 'trash' === get_post_status( $event_id ) ) {
            wp_die( 'Seçilen etkinlik bulunamadı.' );
        }

        $candidate_date = self::date_part( get_post_meta( $candidate_id, 'start_date', true ) );
        $event_date     = self::date_part( get_post_meta( $event_id, 'start_date', true ) );
        $gap = self::date_gap_days( $candidate_date, $event_date );

        if ( $candidate_date && $event_date && ( substr( $candidate_date, 0, 4 ) !== substr( $event_date, 0, 4 ) || ( null !== $gap && $gap > 60 ) ) ) {
            wp_die( 'Bu iki kayıt farklı yıllara/editionlara ait görünüyor. Manuel eşleştirme güvenlik nedeniyle yapılmadı.' );
        }

        $conflicts = self::safe_fill_missing_event_fields( $candidate_id, $event_id );

        update_post_meta( $candidate_id, 'matched_event_id', $event_id );
        update_post_meta( $candidate_id, 'candidate_status', 'imported' );
        update_post_meta( $candidate_id, 'candidate_match_reason', 'manual_admin_match' );
        update_post_meta( $candidate_id, 'candidate_resolution', 'manual_match' );
        update_post_meta( $candidate_id, 'candidate_manual_match_conflicts', $conflicts );
        update_post_meta( $candidate_id, 'candidate_manually_matched_by', get_current_user_id() );
        update_post_meta( $candidate_id, 'candidate_resolved_at', current_time( 'mysql' ) );
        update_post_meta( $candidate_id, 'imported_event_id', $event_id );

        wp_safe_redirect( add_query_arg( 'sektorel_manual_match', '1', get_edit_post_link( $event_id, 'url' ) ) );
        exit;
    }

    public static function row_actions( $actions, $post ) {
        if ( ! $post || 'event_candidate' !== $post->post_type || ! current_user_can( 'manage_options' ) ) {
            return $actions;
        }

        $status = (string) get_post_meta( $post->ID, 'candidate_status', true );
        if ( in_array( $status, array( 'imported', 'ignored', 'rejected' ), true ) ) {
            return $actions;
        }

        $matched_event_id = absint( get_post_meta( $post->ID, 'matched_event_id', true ) );
        if ( $matched_event_id && 'event' === get_post_type( $matched_event_id ) ) {
            return $actions;
        }

        $url = get_edit_post_link( $post->ID, 'url' );
        if ( $url ) {
            $url .= '#sektorel_candidate_manual_match';
            unset( $actions['enrichment_waiting'] );
            $actions['manual_match'] = '<a href="' . esc_url( $url ) . '" style="font-weight:700;color:#996800;">Eşleştir</a>';
        }

        return $actions;
    }

    private static function safe_fill_missing_event_fields( $candidate_id, $event_id ) {
        $conflicts = array();
        foreach ( array( 'end_date', 'location_type', 'venue', 'address', 'organizer', 'registration_link', 'event_url' ) as $key ) {
            $incoming = trim( (string) get_post_meta( $candidate_id, $key, true ) );
            if ( ! $incoming ) {
                continue;
            }
            $current = trim( (string) get_post_meta( $event_id, $key, true ) );
            if ( ! $current ) {
                update_post_meta( $event_id, $key, in_array( $key, array( 'registration_link', 'event_url' ), true ) ? esc_url_raw( $incoming ) : $incoming );
                continue;
            }
            if ( self::field_identity( $key, $current ) !== self::field_identity( $key, $incoming ) ) {
                $conflicts[] = $key;
            }
        }

        $content = trim( (string) get_post_field( 'post_content', $candidate_id ) );
        $event_content = trim( (string) get_post_field( 'post_content', $event_id ) );
        if ( $content && ! $event_content ) {
            wp_update_post( array( 'ID' => $event_id, 'post_content' => wp_kses_post( $content ) ) );
        } elseif ( $content && $event_content && self::normalize_text( $content ) !== self::normalize_text( $event_content ) ) {
            $conflicts[] = 'description';
        }

        return array_values( array_unique( $conflicts ) );
    }

    private static function field_identity( $key, $value ) {
        if ( in_array( $key, array( 'registration_link', 'event_url' ), true ) ) {
            $host = strtolower( (string) wp_parse_url( $value, PHP_URL_HOST ) );
            $path = rtrim( strtolower( (string) wp_parse_url( $value, PHP_URL_PATH ) ), '/' );
            return preg_replace( '/^www\./', '', $host ) . $path;
        }
        if ( 'end_date' === $key ) {
            return self::date_part( $value );
        }
        return self::normalize_text( $value );
    }

    private static function date_gap_days( $a, $b ) {
        if ( ! $a || ! $b ) {
            return null;
        }
        try {
            $da = new DateTimeImmutable( $a );
            $db = new DateTimeImmutable( $b );
        } catch ( Exception $e ) {
            return null;
        }
        return abs( (int) $da->diff( $db )->format( '%r%a' ) );
    }

    private static function similarity( $a, $b ) {
        if ( ! $a || ! $b ) {
            return 0;
        }
        if ( $a === $b ) {
            return 100;
        }
        similar_text( $a, $b, $percent );
        return (int) round( $percent );
    }

    private static function normalize_text( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = remove_accents( wp_strip_all_tags( $value ) );
        $value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
        $value = preg_replace( '/[^a-z0-9]+/i', ' ', $value );
        return trim( preg_replace( '/\s+/', ' ', $value ) );
    }

    private static function clean_text( $value ) {
        $value = html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) ) );
    }

    private static function date_part( $value ) {
        $value = trim( (string) $value );
        return preg_match( '/^(20\d{2}-\d{2}-\d{2})/', $value, $m ) ? $m[1] : '';
    }
}
