<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_HTML_New_Candidate_Panel {

    public static function init() {
        add_action( 'admin_footer', array( __CLASS__, 'render_panel' ), 20 );
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

        $ids = self::recent_html_candidate_ids();
        if ( ! $ids ) {
            return;
        }
        ?>
        <script>
        jQuery(function($){
            var panel = $('#sektorel-html-new-candidates-panel');
            if (panel.length) {
                $('.wrap').last().append(panel);
                panel.show();
            }
        });
        </script>
        <div id="sektorel-html-new-candidates-panel" class="card" style="display:none;max-width:1200px;margin-top:24px;padding:20px;">
            <h2 style="margin-top:0;">Son Yeni HTML Adayları</h2>
            <p>Son 12 saatte HTML parser tarafından oluşturulan en yeni adaylar. Bu alan yalnız teşhis amaçlıdır.</p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Başlık</th>
                        <th>Başlangıç</th>
                        <th>Kaynak</th>
                        <th>Durum</th>
                        <th>Event URL</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $ids as $candidate_id ) :
                    $source_id = absint( get_post_meta( $candidate_id, 'source_id', true ) );
                    $event_url = (string) get_post_meta( $candidate_id, 'event_url', true );
                    $start     = (string) get_post_meta( $candidate_id, 'start_date', true );
                    $status    = (string) get_post_meta( $candidate_id, 'candidate_status', true );
                    $edit_link = get_edit_post_link( $candidate_id );
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
                        <td><?php echo esc_html( $status ? $status : 'new' ); ?></td>
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
        <?php
    }

    private static function recent_html_candidate_ids() {
        $from = gmdate( 'Y-m-d H:i:s', time() - ( 12 * HOUR_IN_SECONDS ) );

        return array_values( array_map( 'absint', get_posts( array(
            'post_type'      => 'event_candidate',
            'post_status'    => 'any',
            'posts_per_page' => 30,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'date_query'     => array(
                array(
                    'after'     => $from,
                    'inclusive' => true,
                    'column'    => 'post_date_gmt',
                ),
            ),
            'meta_query'     => array(
                array(
                    'key'   => 'parser_type',
                    'value' => 'html',
                ),
            ),
            'no_found_rows' => true,
        ) ) ) );
    }
}
