<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Company_Mutations {

    const CLAIM_POST_TYPE = 'company_claim';
    const CLAIM_ACTION    = 'sektorel_company_claim_decide';

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_claim_post_type' ), 6 );
        add_action( 'graphql_register_types', array( __CLASS__, 'register_mutations' ) );
        add_action( 'graphql_register_types', array( __CLASS__, 'register_claim_graphql' ) );

        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'add_claim_admin_menu' ), 36 );
            add_action( 'admin_post_' . self::CLAIM_ACTION, array( __CLASS__, 'handle_claim_decision' ) );
        }
    }

    public static function register_claim_post_type() {
        register_post_type( self::CLAIM_POST_TYPE, array(
            'labels' => array(
                'name'          => 'Firma Sahiplenme Talepleri',
                'singular_name' => 'Firma Sahiplenme Talebi',
            ),
            'public'             => false,
            'show_ui'            => false,
            'show_in_menu'       => false,
            'show_in_rest'       => false,
            'supports'           => array( 'title', 'author' ),
            'exclude_from_search'=> true,
            'publicly_queryable' => false,
            'query_var'          => false,
            'rewrite'            => false,
        ) );
    }

    public static function register_mutations() {
        register_graphql_mutation( 'submitCompany', array(
            'description' => 'Giriş yapan kullanıcı adına onay bekleyen firma kaydı oluşturur.',
            'inputFields' => array(
                'title'        => array( 'type' => 'String' ),
                'officialName' => array( 'type' => 'String' ),
                'sector'       => array( 'type' => 'String' ),
                'companyType'  => array( 'type' => 'String' ),
                'description'  => array( 'type' => 'String' ),
                'email'        => array( 'type' => 'String' ),
                'phone'        => array( 'type' => 'String' ),
                'website'      => array( 'type' => 'String' ),
                'city'         => array( 'type' => 'String' ),
                'district'     => array( 'type' => 'String' ),
                'postalCode'   => array( 'type' => 'String' ),
                'address'      => array( 'type' => 'String' ),
            ),
            'outputFields' => array(
                'success' => array( 'type' => 'Boolean' ),
                'message' => array( 'type' => 'String' ),
                'postId'  => array( 'type' => 'ID' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $user_id = get_current_user_id();
                if ( ! $user_id ) {
                    throw new \GraphQL\Error\UserError( 'Firma eklemek için giriş yapmanız gerekir.' );
                }

                $existing_company_id = Sektorel_Session_Query::get_owned_company_id( $user_id );
                if ( $existing_company_id ) {
                    throw new \GraphQL\Error\UserError( 'Bu hesaba bağlı bir firma kaydı zaten bulunuyor.' );
                }

                $title = sanitize_text_field( $input['title'] ?? '' );
                if ( '' === $title ) {
                    throw new \GraphQL\Error\UserError( 'Firma adı zorunludur.' );
                }

                $post_id = wp_insert_post( array(
                    'post_title'   => $title,
                    'post_content' => wp_kses_post( $input['description'] ?? '' ),
                    'post_status'  => 'pending',
                    'post_type'    => 'company',
                    'post_author'  => $user_id,
                ), true );

                if ( is_wp_error( $post_id ) ) {
                    throw new \GraphQL\Error\UserError( 'Firma oluşturulamadı: ' . $post_id->get_error_message() );
                }

                self::save_company_meta( $post_id, $input );
                self::save_company_terms( $post_id, $input );

                update_user_meta( $user_id, '_sektorel_company_id', $post_id );
                update_user_meta( $user_id, 'account_type', 'kurumsal' );
                update_user_meta( $user_id, 'company_name', $title );

                return array(
                    'success' => true,
                    'message' => 'Firma başvurunuz alındı. İnceleme sonrası yayına alınacaktır.',
                    'postId'  => $post_id,
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

                if ( ! self::can_user_claim( $company_id, $user_id ) ) {
                    throw new \GraphQL\Error\UserError( self::claim_block_reason( $company_id, $user_id ) );
                }

                $existing = self::find_latest_claim( $company_id, $user_id );
                if ( $existing && 'pending' === self::claim_status( $existing->ID ) ) {
                    return array(
                        'success'   => true,
                        'message'   => 'Bu firma için sahiplenme talebiniz zaten incelemede.',
                        'requestId' => (int) $existing->ID,
                        'status'    => 'pending',
                    );
                }

                $request_id = wp_insert_post( array(
                    'post_type'   => self::CLAIM_POST_TYPE,
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

    public static function register_claim_graphql() {
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

                $user_id = get_current_user_id();
                $request = $user_id ? self::find_latest_claim( $company_id, $user_id ) : null;

                return array(
                    'ownershipStatus' => Sektorel_Company_Ranking::ownership_status( $company_id ),
                    'myRequestStatus' => $request ? self::claim_status( $request->ID ) : '',
                    'canRequest'      => $user_id ? self::can_user_claim( $company_id, $user_id ) : false,
                );
            },
        ) );
    }

    public static function create_company_for_user( $user_id, $input ) {
        $title = sanitize_text_field( $input['companyName'] ?? '' );
        if ( ! $user_id || '' === $title ) {
            return 0;
        }

        $post_id = wp_insert_post( array(
            'post_title'  => $title,
            'post_status' => 'pending',
            'post_type'   => 'company',
            'post_author' => (int) $user_id,
        ), true );

        if ( is_wp_error( $post_id ) ) {
            return 0;
        }

        update_post_meta( $post_id, 'official_name', $title );
        update_post_meta( $post_id, 'tax_office', sanitize_text_field( $input['taxOffice'] ?? '' ) );
        update_post_meta( $post_id, 'tax_number', sanitize_text_field( $input['taxNumber'] ?? '' ) );

        if ( ! empty( $input['sector'] ) ) {
            self::assign_term( $post_id, $input['sector'], 'sector' );
        }

        update_user_meta( $user_id, '_sektorel_company_id', $post_id );
        return (int) $post_id;
    }

    public static function add_claim_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=company',
            'Firma Sahiplenme Talepleri',
            'Sahiplenme Talepleri',
            'manage_options',
            'sektorel-company-claims',
            array( __CLASS__, 'render_claim_admin_page' )
        );
    }

    public static function render_claim_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu sayfayı görüntüleme yetkiniz yok.', 'sektorel-core' ) );
        }

        $requests = get_posts( array(
            'post_type'      => self::CLAIM_POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );
        ?>
        <div class="wrap">
            <h1>Firma Sahiplenme Talepleri</h1>
            <p>Onay verilmeden firma sahipliği değişmez. Onaylanan firma mevcut köken/provenance bilgisini korur ve sahiplik nedeniyle listeleme katmanı 2'ye yükselir.</p>
            <?php if ( isset( $_GET['claim_error'] ) ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['claim_error'] ) ) ); ?></p></div>
            <?php elseif ( isset( $_GET['claim_done'] ) ) : ?>
                <div class="notice notice-success"><p>Talep işlendi: <strong><?php echo esc_html( sanitize_key( wp_unslash( $_GET['claim_done'] ) ) ); ?></strong></p></div>
            <?php endif; ?>
            <table class="widefat striped">
                <thead><tr><th>Talep</th><th>Firma</th><th>Kullanıcı</th><th>Not</th><th>Durum</th><th>İşlem</th></tr></thead>
                <tbody>
                <?php if ( ! $requests ) : ?>
                    <tr><td colspan="6">Henüz sahiplenme talebi yok.</td></tr>
                <?php else : foreach ( $requests as $request ) :
                    $company_id = (int) get_post_meta( $request->ID, '_sektorel_claim_company_id', true );
                    $user_id    = (int) get_post_meta( $request->ID, '_sektorel_claim_user_id', true );
                    $status     = self::claim_status( $request->ID );
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
                                    <input type="hidden" name="action" value="<?php echo esc_attr( self::CLAIM_ACTION ); ?>">
                                    <input type="hidden" name="request_id" value="<?php echo (int) $request->ID; ?>">
                                    <input type="hidden" name="decision" value="approve">
                                    <?php wp_nonce_field( self::CLAIM_ACTION . '_' . (int) $request->ID ); ?>
                                    <button class="button button-primary" type="submit">Onayla</button>
                                </form>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
                                    <input type="hidden" name="action" value="<?php echo esc_attr( self::CLAIM_ACTION ); ?>">
                                    <input type="hidden" name="request_id" value="<?php echo (int) $request->ID; ?>">
                                    <input type="hidden" name="decision" value="reject">
                                    <?php wp_nonce_field( self::CLAIM_ACTION . '_' . (int) $request->ID ); ?>
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

    public static function handle_claim_decision() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Bu işlemi yapma yetkiniz yok.', 'sektorel-core' ) );
        }

        $request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
        $decision   = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
        check_admin_referer( self::CLAIM_ACTION . '_' . $request_id );

        if ( ! $request_id || self::CLAIM_POST_TYPE !== get_post_type( $request_id ) || 'pending' !== self::claim_status( $request_id ) ) {
            self::claim_redirect( 'Talep geçersiz veya daha önce işlenmiş.' );
        }

        if ( 'reject' === $decision ) {
            self::mark_claim_decided( $request_id, 'rejected' );
            self::claim_redirect( '', 'rejected' );
        }

        if ( 'approve' !== $decision ) {
            self::claim_redirect( 'Geçersiz karar.' );
        }

        $company_id = (int) get_post_meta( $request_id, '_sektorel_claim_company_id', true );
        $user_id    = (int) get_post_meta( $request_id, '_sektorel_claim_user_id', true );

        if ( ! self::can_user_claim( $company_id, $user_id ) ) {
            self::claim_redirect( self::claim_block_reason( $company_id, $user_id ) );
        }

        $updated = wp_update_post( array(
            'ID'          => $company_id,
            'post_author' => $user_id,
        ), true );
        if ( is_wp_error( $updated ) ) {
            self::claim_redirect( 'Firma sahipliği güncellenemedi.' );
        }

        update_user_meta( $user_id, '_sektorel_company_id', $company_id );
        update_user_meta( $user_id, 'account_type', 'kurumsal' );
        update_user_meta( $user_id, 'company_name', get_the_title( $company_id ) );
        self::mark_claim_decided( $request_id, 'approved' );

        self::claim_redirect( '', 'approved' );
    }

    private static function can_user_claim( $company_id, $user_id ) {
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

        $pending = self::find_pending_claim_for_company( $company_id );
        return ! $pending || (int) $pending->post_author === $user_id;
    }

    private static function claim_block_reason( $company_id, $user_id ) {
        if ( 'claimed' === Sektorel_Company_Ranking::ownership_status( $company_id ) ) {
            return 'Bu firma başka bir hesap tarafından sahiplenilmiş.';
        }
        if ( Sektorel_Session_Query::get_owned_company_id( $user_id ) ) {
            return 'Hesabınıza bağlı bir firma zaten bulunuyor.';
        }
        if ( Sektorel_Session_Query::get_member_company_id( $user_id ) ) {
            return 'Bir firma ekibine bağlı hesap yeni sahiplenme talebi oluşturamaz.';
        }
        if ( self::find_pending_claim_for_company( $company_id ) ) {
            return 'Bu firma için başka bir sahiplenme talebi zaten incelemede.';
        }
        return 'Bu firma için sahiplenme talebi oluşturulamıyor.';
    }

    private static function find_latest_claim( $company_id, $user_id ) {
        $posts = get_posts( array(
            'post_type'      => self::CLAIM_POST_TYPE,
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

    private static function find_pending_claim_for_company( $company_id ) {
        $posts = get_posts( array(
            'post_type'      => self::CLAIM_POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 1,
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

    private static function claim_status( $request_id ) {
        $status = sanitize_key( (string) get_post_meta( (int) $request_id, '_sektorel_claim_status', true ) );
        return in_array( $status, array( 'pending', 'approved', 'rejected' ), true ) ? $status : 'pending';
    }

    private static function mark_claim_decided( $request_id, $status ) {
        update_post_meta( $request_id, '_sektorel_claim_status', sanitize_key( $status ) );
        update_post_meta( $request_id, '_sektorel_claim_decided_at', current_time( 'mysql', true ) );
        update_post_meta( $request_id, '_sektorel_claim_decided_by', get_current_user_id() );
    }

    private static function claim_redirect( $error = '', $done = '' ) {
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

    private static function save_company_meta( $post_id, $input ) {
        $meta_fields = array(
            'company_type' => 'companyType',
            'official_name'=> 'officialName',
            'phone'        => 'phone',
            'postal_code'  => 'postalCode',
            'address'      => 'address',
        );

        foreach ( $meta_fields as $meta_key => $input_key ) {
            if ( isset( $input[ $input_key ] ) ) {
                update_post_meta( $post_id, $meta_key, sanitize_text_field( $input[ $input_key ] ) );
            }
        }

        if ( isset( $input['email'] ) ) {
            update_post_meta( $post_id, 'email', sanitize_email( $input['email'] ) );
        }
        if ( isset( $input['website'] ) ) {
            update_post_meta( $post_id, 'website', esc_url_raw( $input['website'] ) );
        }
    }

    private static function save_company_terms( $post_id, $input ) {
        if ( ! empty( $input['sector'] ) ) {
            self::assign_term( $post_id, $input['sector'], 'sector' );
        }

        $location_ids = array();
        foreach ( array( 'city', 'district' ) as $key ) {
            if ( empty( $input[ $key ] ) ) {
                continue;
            }
            $term = self::find_term( $input[ $key ], 'location' );
            if ( $term ) {
                $location_ids[] = (int) $term->term_id;
            }
        }

        if ( $location_ids ) {
            wp_set_object_terms( $post_id, array_values( array_unique( $location_ids ) ), 'location' );
        }
    }

    private static function assign_term( $post_id, $value, $taxonomy ) {
        $term = self::find_term( $value, $taxonomy );
        if ( $term ) {
            wp_set_object_terms( $post_id, array( (int) $term->term_id ), $taxonomy );
        }
    }

    private static function find_term( $value, $taxonomy ) {
        $value = sanitize_text_field( $value );
        return get_term_by( 'slug', $value, $taxonomy ) ?: get_term_by( 'name', $value, $taxonomy );
    }
}
