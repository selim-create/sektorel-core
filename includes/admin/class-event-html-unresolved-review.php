<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Read-only review surface for unresolved HTML candidates.
 *
 * This class does not classify, update or resolve candidates. It only exposes
 * the current unresolved pool on the HTML scan page and allows an admin to
 * export the same dataset for offline/manual review.
 */
class Sektorel_Event_HTML_Unresolved_Review {

    const NONCE_ACTION = 'sektorel_export_unresolved_html_candidates';

    public static function init() {
        add_action( 'admin_footer', array( __CLASS__, 'render_panel' ), 30 );
        add_action( 'admin_post_sektorel_export_unresolved_html_candidates', array( __CLASS__, 'export_csv' ) );
    }

    public static function render_panel() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

        if ( ! $screen || 'event' !== $screen->post_type || 'sektorel-html-events' !== $page ) {
            return;
        }

        $ids = self::unresolved_ids();
        $export_url = wp_nonce_url(
            admin_url( 'admin-post.php?action=sektorel_export_unresolved_html_candidates' ),
            self::NONCE_ACTION
        );
        ?>
        <script>
        jQuery(function($){
            var panel = $('#sektorel-html-unresolved-review-panel');
            if (panel.length) {
                $('.wrap').last().append(panel);
                panel.show();
            }
        });
        </script>
        <div id="sektorel-html-unresolved-review-panel" class="card" style="display:none;max-width:1400px;margin-top:24px;padding:20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                <div>
                    <h2 style="margin:0 0 6px;">Unresolved HTML Review</h2>
                    <p style="margin:0;">Yeni / incomplete durumda kalan HTML adayları. Bu alan yalnız inceleme amaçlıdır; kayıt durumlarını değiştirmez.</p>
                </div>
                <a class="button button-secondary" href="<?php echo esc_url( $export_url ); ?>">CSV olarak indir</a>
            </div>

            <p style="margin-top:16px;"><strong><?php echo esc_html( count( $ids ) ); ?></strong> unresolved HTML adayı.</p>

            <?php if ( ! $ids ) : ?>
                <p>İncelenecek unresolved HTML adayı bulunmuyor.</p>
            <?php else : ?>
                <div style="overflow:auto;max-height:650px;">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Başlık</th>
                                <th>Başlangıç</th>
                                <th>Kaynak</th>
                                <th>Durum</th>
                                <th>Güven</th>
                                <th>Eşleşme</th>
                                <th>Mekân / Organizatör</th>
                                <th>Event URL</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $ids as $candidate_id ) :
                            $source_id   = absint( get_post_meta( $candidate_id, 'source_id', true ) );
                            $start       = (string) get_post_meta( $candidate_id, 'start_date', true );
                            $status      = (string) get_post_meta( $candidate_id, 'candidate_status', true );
                            $confidence  = (string) get_post_meta( $candidate_id, 'candidate_confidence_level', true );
                            $conf_score  = get_post_meta( $candidate_id, 'candidate_confidence_score', true );
                            $match_score = get_post_meta( $candidate_id, 'candidate_match_score', true );
                            $match_reason= (string) get_post_meta( $candidate_id, 'candidate_match_reason', true );
                            $venue       = (string) get_post_meta( $candidate_id, 'venue', true );
                            $organizer   = (string) get_post_meta( $candidate_id, 'organizer', true );
                            $event_url   = (string) get_post_meta( $candidate_id, 'event_url', true );
                            $edit_link   = get_edit_post_link( $candidate_id );
                            ?>
                            <tr>
                                <td><?php echo esc_html( $candidate_id ); ?></td>
                                <td>
                                    <?php if ( $edit_link ) : ?>
                                        <a href="<?php echo esc_url( $edit_link ); ?>"><strong><?php echo esc_html( get_the_title( $candidate_id ) ); ?></strong></a>
                                    <?php else : ?>
                                        <?php echo esc_html( get_the_title( $candidate_id ) ); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $start ? $start : '—' ); ?></td>
                                <td><?php echo esc_html( $source_id ? get_the_title( $source_id ) : '—' ); ?></td>
                                <td><?php echo esc_html( $status ? $status : '—' ); ?></td>
                                <td><?php echo esc_html( $confidence ? $confidence . ( '' !== (string) $conf_score ? ' ' . absint( $conf_score ) . '/100' : '' ) : '—' ); ?></td>
                                <td><?php echo esc_html( '' !== (string) $match_score ? absint( $match_score ) . '/100 ' . $match_reason : ( $match_reason ? $match_reason : '—' ) ); ?></td>
                                <td>
                                    <?php echo esc_html( $venue ? $venue : '—' ); ?>
                                    <?php if ( $organizer ) : ?><br><small><?php echo esc_html( $organizer ); ?></small><?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( $event_url ) : ?>
                                        <a href="<?php echo esc_url( $event_url ); ?>" target="_blank" rel="noopener noreferrer">Kaynağı aç</a>
                                    <?php else : ?>—<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function export_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Yetkisiz işlem.' );
        }

        check_admin_referer( self::NONCE_ACTION );

        $ids = self::unresolved_ids();
        $filename = 'unresolved-html-candidates-' . gmdate( 'Ymd-His' ) . '.csv';

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        if ( false === $out ) {
            wp_die( 'CSV çıktısı oluşturulamadı.' );
        }

        // UTF-8 BOM keeps Turkish characters readable in Excel.
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, array(
            'candidate_id',
            'title',
            'start_date',
            'end_date',
            'source_id',
            'source_title',
            'candidate_status',
            'confidence_level',
            'confidence_score',
            'match_score',
            'match_reason',
            'quality_reason',
            'venue',
            'address',
            'organizer',
            'location_type',
            'event_url',
            'registration_link',
            'source_url',
        ), ';' );

        foreach ( $ids as $candidate_id ) {
            $source_id = absint( get_post_meta( $candidate_id, 'source_id', true ) );
            fputcsv( $out, array(
                $candidate_id,
                get_the_title( $candidate_id ),
                (string) get_post_meta( $candidate_id, 'start_date', true ),
                (string) get_post_meta( $candidate_id, 'end_date', true ),
                $source_id,
                $source_id ? get_the_title( $source_id ) : '',
                (string) get_post_meta( $candidate_id, 'candidate_status', true ),
                (string) get_post_meta( $candidate_id, 'candidate_confidence_level', true ),
                (string) get_post_meta( $candidate_id, 'candidate_confidence_score', true ),
                (string) get_post_meta( $candidate_id, 'candidate_match_score', true ),
                (string) get_post_meta( $candidate_id, 'candidate_match_reason', true ),
                (string) get_post_meta( $candidate_id, 'candidate_quality_reason', true ),
                (string) get_post_meta( $candidate_id, 'venue', true ),
                (string) get_post_meta( $candidate_id, 'address', true ),
                (string) get_post_meta( $candidate_id, 'organizer', true ),
                (string) get_post_meta( $candidate_id, 'location_type', true ),
                (string) get_post_meta( $candidate_id, 'event_url', true ),
                (string) get_post_meta( $candidate_id, 'registration_link', true ),
                (string) get_post_meta( $candidate_id, 'source_url', true ),
            ), ';' );
        }

        fclose( $out );
        exit;
    }

    private static function unresolved_ids() {
        return array_values( array_map( 'absint', get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                'relation' => 'AND',
                array( 'key' => 'parser_type', 'value' => 'html' ),
                array( 'key' => 'candidate_status', 'value' => array( 'new', 'incomplete' ), 'compare' => 'IN' ),
            ),
        ) ) ) );
    }
}
