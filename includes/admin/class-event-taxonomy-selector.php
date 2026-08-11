<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Compact searchable taxonomy selector for event edit screens.
 *
 * The default hierarchical WordPress meta boxes render the complete sector /
 * location trees. With a large location hierarchy this makes the event editor
 * extremely long. This class replaces those boxes for `event` only, without
 * changing taxonomy registration, term data or frontend/GraphQL relations.
 */
class Sektorel_Event_Taxonomy_Selector {

    const NONCE_ACTION = 'sektorel_event_taxonomy_selector';
    const NONCE_NAME   = 'sektorel_event_taxonomy_selector_nonce';

    public static function init() {
        add_action( 'add_meta_boxes_event', array( __CLASS__, 'replace_default_boxes' ), 99 );
        add_action( 'wp_ajax_sektorel_event_taxonomy_search', array( __CLASS__, 'ajax_search' ) );
        add_action( 'save_post_event', array( __CLASS__, 'save_terms' ), 85, 2 );
        add_action( 'admin_footer-post.php', array( __CLASS__, 'footer_script' ) );
        add_action( 'admin_footer-post-new.php', array( __CLASS__, 'footer_script' ) );
    }

    public static function replace_default_boxes() {
        remove_meta_box( 'sector-div', 'event', 'side' );
        remove_meta_box( 'location-div', 'event', 'side' );

        // WordPress may use the taxonomy slug based `tagsdiv-*` id depending on
        // registration/callback details; remove both forms defensively.
        remove_meta_box( 'tagsdiv-sector', 'event', 'side' );
        remove_meta_box( 'tagsdiv-location', 'event', 'side' );

        add_meta_box(
            'sektorel-event-sector-selector',
            'Sektörler',
            array( __CLASS__, 'render_sector_box' ),
            'event',
            'side',
            'default'
        );

        add_meta_box(
            'sektorel-event-location-selector',
            'Lokasyonlar',
            array( __CLASS__, 'render_location_box' ),
            'event',
            'side',
            'default'
        );
    }

    public static function render_sector_box( $post ) {
        self::render_box( $post, 'sector', 'Sektör ara…' );
    }

    public static function render_location_box( $post ) {
        self::render_box( $post, 'location', 'Ülke, şehir veya ilçe ara…' );
    }

    private static function render_box( $post, $taxonomy, $placeholder ) {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            echo '<p>Taksonomi bulunamadı.</p>';
            return;
        }

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

        $selected = wp_get_object_terms(
            $post->ID,
            $taxonomy,
            array( 'orderby' => 'name', 'order' => 'ASC' )
        );
        if ( is_wp_error( $selected ) ) {
            $selected = array();
        }
        ?>
        <div class="sektorel-tax-selector" data-taxonomy="<?php echo esc_attr( $taxonomy ); ?>">
            <input
                type="search"
                class="widefat sektorel-tax-search"
                placeholder="<?php echo esc_attr( $placeholder ); ?>"
                autocomplete="off"
            />
            <div class="sektorel-tax-results" style="display:none;"></div>
            <div class="sektorel-tax-selected">
                <?php foreach ( $selected as $term ) : ?>
                    <?php self::render_chip( $taxonomy, $term ); ?>
                <?php endforeach; ?>
            </div>
            <p class="description" style="margin-top:8px;">Arayın ve sonuçtan seçin. Birden fazla seçim yapabilirsiniz.</p>
        </div>
        <?php
    }

    private static function render_chip( $taxonomy, $term ) {
        $label = self::term_label( $term );
        ?>
        <span class="sektorel-tax-chip" data-term-id="<?php echo esc_attr( $term->term_id ); ?>">
            <span><?php echo esc_html( $label ); ?></span>
            <button type="button" class="sektorel-tax-remove" aria-label="Seçimi kaldır">×</button>
            <input type="hidden" name="sektorel_event_tax[<?php echo esc_attr( $taxonomy ); ?>][]" value="<?php echo esc_attr( $term->term_id ); ?>" />
        </span>
        <?php
    }

    public static function ajax_search() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }

        $taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
        if ( ! in_array( $taxonomy, array( 'sector', 'location' ), true ) || ! taxonomy_exists( $taxonomy ) ) {
            wp_send_json_error( array( 'message' => 'Geçersiz taksonomi.' ), 400 );
        }

        $query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        if ( mb_strlen( $query ) < 2 ) {
            wp_send_json_success( array( 'items' => array() ) );
        }

        $terms = get_terms( array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'search'     => $query,
            'number'     => 20,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );

        if ( is_wp_error( $terms ) ) {
            wp_send_json_error( array( 'message' => $terms->get_error_message() ) );
        }

        $items = array();
        foreach ( $terms as $term ) {
            $items[] = array(
                'id'    => absint( $term->term_id ),
                'name'  => $term->name,
                'label' => self::term_label( $term ),
            );
        }

        wp_send_json_success( array( 'items' => $items ) );
    }

    public static function save_terms( $post_id, $post ) {
        if ( ! $post || 'event' !== $post->post_type || wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $submitted = isset( $_POST['sektorel_event_tax'] ) && is_array( $_POST['sektorel_event_tax'] )
            ? wp_unslash( $_POST['sektorel_event_tax'] )
            : array();

        foreach ( array( 'sector', 'location' ) as $taxonomy ) {
            $ids = isset( $submitted[ $taxonomy ] ) && is_array( $submitted[ $taxonomy ] )
                ? array_values( array_unique( array_filter( array_map( 'absint', $submitted[ $taxonomy ] ) ) ) )
                : array();

            // Ensure submitted ids really belong to the expected taxonomy.
            $valid = array();
            foreach ( $ids as $term_id ) {
                $term = get_term( $term_id, $taxonomy );
                if ( $term && ! is_wp_error( $term ) ) {
                    $valid[] = $term_id;
                }
            }

            wp_set_object_terms( $post_id, $valid, $taxonomy, false );
        }
    }

    public static function footer_script() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'event' !== $screen->post_type || 'post' !== $screen->base ) {
            return;
        }

        $nonce = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <style>
            .sektorel-tax-selector { position:relative; }
            .sektorel-tax-results { position:absolute; left:0; right:0; z-index:1000; background:#fff; border:1px solid #8c8f94; box-shadow:0 2px 8px rgba(0,0,0,.12); max-height:220px; overflow:auto; }
            .sektorel-tax-result { display:block; width:100%; text-align:left; border:0; border-bottom:1px solid #f0f0f1; background:#fff; padding:8px 10px; cursor:pointer; }
            .sektorel-tax-result:hover, .sektorel-tax-result:focus { background:#f0f6fc; color:#135e96; }
            .sektorel-tax-selected { display:flex; flex-wrap:wrap; gap:6px; margin-top:10px; }
            .sektorel-tax-chip { display:inline-flex; align-items:center; gap:5px; max-width:100%; padding:4px 7px; border:1px solid #c3c4c7; border-radius:12px; background:#f6f7f7; font-size:12px; line-height:1.35; }
            .sektorel-tax-chip > span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
            .sektorel-tax-remove { border:0; padding:0; background:transparent; color:#646970; font-size:16px; line-height:1; cursor:pointer; }
        </style>
        <script>
        jQuery(function($){
            var nonce = '<?php echo esc_js( $nonce ); ?>';
            var timer = null;

            function escapeHtml(value) {
                return $('<div>').text(value || '').html();
            }

            $('.sektorel-tax-selector').each(function(){
                var root = $(this);
                var taxonomy = root.data('taxonomy');
                var input = root.find('.sektorel-tax-search');
                var results = root.find('.sektorel-tax-results');
                var selected = root.find('.sektorel-tax-selected');

                input.on('input', function(){
                    clearTimeout(timer);
                    var q = $.trim(input.val());
                    if (q.length < 2) {
                        results.hide().empty();
                        return;
                    }

                    timer = setTimeout(function(){
                        $.get(ajaxurl, {
                            action: 'sektorel_event_taxonomy_search',
                            nonce: nonce,
                            taxonomy: taxonomy,
                            q: q
                        }).done(function(response){
                            results.empty();
                            if (!response || !response.success || !response.data.items.length) {
                                results.append('<div style="padding:8px 10px;color:#646970;">Sonuç bulunamadı.</div>').show();
                                return;
                            }
                            response.data.items.forEach(function(item){
                                if (selected.find('[data-term-id="'+Number(item.id)+'"]').length) return;
                                results.append('<button type="button" class="sektorel-tax-result" data-id="'+Number(item.id)+'" data-label="'+escapeHtml(item.label)+'">'+escapeHtml(item.label)+'</button>');
                            });
                            results.show();
                        });
                    }, 250);
                });

                results.on('click', '.sektorel-tax-result', function(){
                    var button = $(this);
                    var id = Number(button.data('id'));
                    var label = button.data('label') || button.text();
                    if (!id || selected.find('[data-term-id="'+id+'"]').length) return;

                    selected.append(
                        '<span class="sektorel-tax-chip" data-term-id="'+id+'">' +
                        '<span>'+escapeHtml(label)+'</span>' +
                        '<button type="button" class="sektorel-tax-remove" aria-label="Seçimi kaldır">×</button>' +
                        '<input type="hidden" name="sektorel_event_tax['+taxonomy+'][]" value="'+id+'" />' +
                        '</span>'
                    );
                    input.val('');
                    results.hide().empty();
                });
            });

            $(document).on('click', '.sektorel-tax-remove', function(){
                $(this).closest('.sektorel-tax-chip').remove();
            });

            $(document).on('click', function(e){
                if (!$(e.target).closest('.sektorel-tax-selector').length) {
                    $('.sektorel-tax-results').hide();
                }
            });
        });
        </script>
        <?php
    }

    private static function term_label( $term ) {
        if ( ! $term || is_wp_error( $term ) ) {
            return '';
        }

        $parts = array( $term->name );
        $parent_id = absint( $term->parent );
        $guard = 0;

        while ( $parent_id && $guard < 5 ) {
            $parent = get_term( $parent_id, $term->taxonomy );
            if ( ! $parent || is_wp_error( $parent ) ) {
                break;
            }
            array_unshift( $parts, $parent->name );
            $parent_id = absint( $parent->parent );
            $guard++;
        }

        return implode( ' › ', $parts );
    }
}
