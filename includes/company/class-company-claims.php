<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Secure claim workflow for existing company profiles.
 *
 * A public company is never transferred by the request itself. Logged-in users
 * create a pending claim; an administrator explicitly approves or rejects it.
 */
class Sektorel_Company_Claims {

    const POST_TYPE = 'company_claim';
    const ACTION    = 'sektorel_company_claim_decide';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_post_type' ), 6 );
        add_action( 'graphql_register_types', array( __CLASS__, 'register_graphql' ) );

        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'add_admin_menu' ), 36 );
            add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_admin_decision' ) );
        }
    }

    public static function register_post_type() {
        register_post_type( self::POST_TYPE, array(
            'labels' => array(
                'name'          => 'Firma Sahiplenme Talepleri',
                'singular_name' => 'Firma Sahiplenme Talebi',
            ),
            'public'              => false,
            'show_ui'             => false,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'supports'            => array( 'title', 'author' ),
            'capability_type'      => 'post',
            'map_meta_cap'         => true,
            'exclude_from_search'  => true,
            'publicly_queryable'   => false,
            'query_var'            => false,
            'rewrite'              => false,
        ) );
    }

    public static function register_graphql() {
        register_graphql_object_type( 'SektorelCompanyClaimStatus', array(
            'fields' => array(
                'ownershipStatus' => array( 'type' => 'String' ),
                'myRequestStatus' => array( 'type' => 'String' ),
                'canRequest'      => array( 'type' => 'Boolean' ),
            ),
        ) );

        register_graphql_field( 'Company', 'sektorelClaimStatus', array(
            'type'    => 'SektorelCompanyClaimStatus',
            'resolve' => function( $source ) {
                $company_id = self::source_id( $source );
                if ( ! $company_id ) {
                    return null;
                }

                $user_id   = get_current_user_id();
                $ownership = Sektorel_Company_Ranking::ownership_status( $company_id );
                $request   = $user_id ? self::find_latest_request( $company_id, $user_id ) : null;

                return array(
                    'ownershipStatus' => $ownership,
                    'myRequestStatus' => $request ? self::request_status( $request->ID ) : '',
                    'canRequest'      => $user_id ? self::can_user_request( $company_id, $user_id ) : false,
                );
            },
        ) );

        register_graphql_mutation( 'requestCompanyClaim', array(
            'description' => 'Mevcut bir firma profili için yönetici onaylı sahiplenme talebi oluşturur.',
            'inputFields' => array(
                'companyId' => array( 'type' => 'ID' ),
                'note'      => array( 'type' => 'String' ),
            ),
            'outputFields' => array(
                'success'   => array( 'type' => 'Boolean' ),
                'message'   => array( 'type' => 'String' ),
                'requestId' => array( 'type' => 'ID' ),
                'status'    => array( 'type' => 'String' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $user_id = get_current_user_id();
                if ( ! $user_id ) {
                    throw new \GraphQL\Error\UserError( 'Firma sahiplenmek için giriş yapmanız gerekir.' );
                }

                $company_id = absint( $input['companyId'] ?? 0 );
                if ( ! $company_id || 'company' !== get_post_type( $company_id ) ) {
                    throw new \GraphQL\Error\UserError( 'Geçerli bir firma seçilmedi.' );
                }

                if ( ! self::can_user_request( $company_id, $user_id ) ) {
                    throw new \GraphQL\Error\UserError( self::request_block_reason( $company_id, $user_id ) );
                }

                $existing = self::find_latest_request( $company_id, $user_id );
                if ( $existing && 'pending' === self::request_status( $existing->ID ) ) {
                    return array(
                        'success'   => true,
                        'message'   => 'Bu firma için sahiplenme talebiniz zaten incelemede.',
                        'requestId' => (int) $existing->ID,
                        'status'    => 'pending',
                    );
                }

                $company = get_post( $company_id );
                $request_id = wp_insert_post( array(
                    'post_type'   => self::POST_TYPE,
                    'post_status' => 'publish',
                    'post_author' => $user_id,
                    'post_title'  => sprintf( '%s — %s', get_the_title( $company_id ), get_the_author_meta( 'display_name', $user_id ) ),
                ), true );

                if ( is_wp_error( $request_id ) ) {
                    throw new \GraphQL\Error\UserError( 'Sahiplenme talebi oluşturulamadı.' );
                }

                update_post_meta( $request_id, '_sektorel_claim_company_id', $company_id );
                update_post_meta( $request_id, '_sektorel_claim_user_id', $user_id );
                update_post_meta( $request_id, '_sektorel_claim_status', 'pending' );
                update_post_meta( $request_id, '_sektorel_claim_note', sanitize_textarea_field( $input['note'] ?? '' ) );
                update_post_meta( $request_id, '_sektorel_claim_requested_at', current_time( 'mysql', true ) );

                return array(
                    'success'   => true,
                    'message'   => 'Firma sahiplenme talebiniz alındı. Yönetici onayından sonra hesabınıza bağlanacaktır.',
                    'requestId' => (int) $request_id,
                    'status'    => 'pending',
                );
            },
        ) );
    }

    public static function can_user_request( $company_id, $user_id ) {
        $company_id = (int) $company_id;
        $user_id    = (int) $user_id;

        if ( ! $company_id || ! $user_id || 'company' !== get_post_type( $company_id ) ) {
            return false;
        }
        if ( 'claimed' === Sektorel_Company_Ranking::ownership_status( $company_id ) ) {
            return false;
        }
        if ( Sektorel_Session_Query::get_owned_company_id( $user_id ) ) {
            return false;
        }
        if ( Sektorel_Session_Query::get_member_company_id( $user_id ) ) {
            return false;
        }
        $pending = self::find_pending_request_for_company( $company_id );
        return ! $pending || (int) $pending->post_author === $user_id;
    }

    private static function request_block_reason( $company_id, $user_id ) {
        if ( 'claimed' === Sektorel_Company_Ranking::ownership_status( $company_id ) ) {
            return 'Bu firma başka bir hesap tarafından sahiplenilmiş.';
        }
        if ( Sektorel_Session_Query::get_owned_company_id( $user_id ) ) {
            return 'Hesabınıza bağlı bir firma zaten bulunuyor.';
        }
        if ( Sektorel_Session_Query::get_member_company_id( $user_id ) ) {
            return 'Bir firma ekibine bağlı hesap yeni sahiplenme talebi oluşturamaz.';
        }
        if ( self::find_pending_request_for_company( $company_id ) ) {
            return 'Bu firma için başka bir sahiplenme talebi zaten incelemede.';
        }
        return 'Bu firma için sahiplenme talebi oluşturulamıyor.';
    }

    private static function find_latest_request( $company_id, $user_id ) {
        $posts = get_posts( array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'author'         => (int) $user_id,
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                array(
                    'key'     => '_sektorel_claim_company_id',
                    'value'   => (int) $company_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ),
            ),
        ) );
        return $posts ? $posts[0] : null;
    }

    private static function find_pending_request_for_company( $company_id ) {
        $posts = get_posts( array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'all',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => '_sektorel_claim_company_id',
                    'value'   => (int) $company_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ),
                array(
                    'key'     => '_sektorel_claim_status',
                    'value'   => 'pending',
                    'compare' => '=',
                ),
            ),
        ) );
        return $posts ? $posts[0] : null;
    }

    private static function request_status( $request_id ) {
        $status = sanitize_key( (string) get_post_meta( (int) $request_id, '_sektorel_claim_status', true ) );
        return in_array( $status, array( 'pending', 'approved', 'rejected' ), true ) ? $status : 'pending';
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=company',
            'Firma Sahiplenme Talepleri',
            'Sahiplenme Talepleri',
            'manage_options',
            'sektorel-company-claims',
            array( __CLASS__, 'render_admin_page' )
        );
    }

    public static function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu sayfayı görüntüleme yetkiniz yok.', 'sektorel-core' ) );
        }

        $requests = get_posts( array(
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );
        ?>
        <div class="wrap">
            <h1>Firma Sahiplenme Talepleri</h1>
            <p>Kullanıcı talepleri burada manuel olarak onaylanır veya reddedilir. Onay verilmeden firma sahipliği değişmez.</p>
            <table class="widefat striped">
                <thead><tr><th>Talep</th><th>Firma</th><th>Kullanıcı</th><th>Not</th><th>Durum</th><th>İşlem</th></tr></thead>
                <tbody>
                <?php if ( ! $requests ) : ?>
                    <tr><td colspan="6">Henüz sahiplenme talebi yok.</td></tr>
                <?php else : foreach ( $requests as $request ) :
                    $company_id = (int) get_post_meta( $request->ID, '_sektorel_claim_company_id', true );
                    $user_id    = (int) get_post_meta( $request->ID, '_sektorel_claim_user_id', true );
                    $status     = self::request_status( $request->ID );
                    $user       = get_userdata( $user_id );
                    ?>
                    <tr>
                        <td>#<?php echo (int) $request->ID; ?><br><small><?php echo esc_html( get_the_date( 'Y-m-d H:i', $request ) ); ?></small></td>
                        <td><?php if ( $company_id ) : ?><a href="<?php echo esc_url( get_edit_post_link( $company_id ) ); ?>"><?php echo esc_html( get_the_title( $company_id ) ); ?></a><?php else : ?>—<?php endif; ?></td>
                        <td><?php echo esc_html( $user ? $user->display_name . ' (' . $user->user_email . ')' : '#' . $user_id ); ?></td>
                        <td><?php echo esc_html( (string) get_post_meta( $request->ID, '_sektorel_claim_note', true ) ); ?></td>
                        <td><strong><?php echo esc_html( $status ); ?></strong></td>
                        <td>
                            <?php if ( 'pending' === $status ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:6px;">
                                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
                                    <input type="hidden" name="request_id" value="<?php echo (int) $request->ID; ?>">
                                    <input type="hidden" name="decision" value="approve">
                                    <?php wp_nonce_field( self::ACTION . '_' . (int) $request->ID ); ?>
                                    <button class="button button-primary" type="submit">Onayla</button>
                                </form>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
                                    <input type="hidden" name="request_id" value="<?php echo (int) $request->ID; ?>">
                                    <input type="hidden" name="decision" value="reject">
                                    <?php wp_nonce_field( self::ACTION . '_' . (int) $request->ID ); ?>
                                    <button class="button" type="submit">Reddet</button>
                                </form>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function handle_admin_decision() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu işlemi yapma yetkiniz yok.', 'sektorel-core' ) );
        }

        $request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
        $decision   = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
        check_admin_referer( self::ACTION . '_' . $request_id );

        if ( ! $request_id || self::POST_TYPE !== get_post_type( $request_id ) || 'pending' !== self::request_status( $request_id ) ) {
            self::redirect_admin( 'Talep geçersiz veya daha önce işlenmiş.' );
        }

        if ( 'reject' === $decision ) {
            update_post_meta( $request_id, '_sektorel_claim_status', 'rejected' );
            update_post_meta( $request_id, '_sektorel_claim_decided_at', current_time( 'mysql', true ) );
            update_post_meta( $request_id, '_sektorel_claim_decided_by', get_current_user_id() );
            self::redirect_admin( '', 'rejected' );
        }

        if ( 'approve' !== $decision ) {
            self::redirect_admin( 'Geçersiz karar.' );
        }

        $company_id = (int) get_post_meta( $request_id, '_sektorel_claim_company_id', true );
        $user_id    = (int) get_post_meta( $request_id, '_sektorel_claim_user_id', true );
        if ( ! self::can_user_request( $company_id, $user_id ) ) {
            self::redirect_admin( self::request_block_reason( $company_id, $user_id ) );
        }

        $company = get_post( $company_id );
        if ( ! $company || 'company' !== $company->post_type ) {
            self::redirect_admin( 'Firma artık mevcut değil.' );
        }

        $updated = wp_update_post( array(
            'ID'          => $company_id,
            'post_author' => $user_id,
        ), true );
        if ( is_wp_error( $updated ) ) {
            self::redirect_admin( 'Firma sahipliği güncellenemedi.' );
        }

        update_user_meta( $user_id, '_sektorel_company_id', $company_id );
        update_user_meta( $user_id, 'account_type', 'kurumsal' );
        update_user_meta( $user_id, 'company_name', get_the_title( $company_id ) );

        update_post_meta( $request_id, '_sektorel_claim_status', 'approved' );
        update_post_meta( $request_id, '_sektorel_claim_decided_at', current_time( 'mysql', true ) );
        update_post_meta( $request_id, '_sektorel_claim_decided_by', get_current_user_id() );

        self::redirect_admin( '', 'approved' );
    }

    private static function redirect_admin( $error = '', $done = '' ) {
        $args = array(
            'post_type' => 'company',
            'page'      => 'sektorel-company-claims',
        );
        if ( $error ) {
            $args['claim_error'] = $error;
        }
        if ( $done ) {
            $args['claim_done'] = $done;
        }
        wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php' ) ) );
        exit;
    }

    private static function source_id( $source ) {
        if ( is_object( $source ) ) {
            return (int) ( $source->databaseId ?? $source->ID ?? 0 );
        }
        if ( is_array( $source ) ) {
            return (int) ( $source['databaseId'] ?? $source['ID'] ?? 0 );
        }
        return 0;
    }
}
