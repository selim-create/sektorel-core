<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Canonical company listing priority and origin/ownership metadata.
 *
 * Priority tiers:
 * 1. Explicitly featured companies.
 * 2. Companies owned by a real customer account.
 * 3. Admin/manual/imported companies.
 * 4. Automatically discovered, still-unclaimed companies.
 */
class Sektorel_Company_Ranking {

    const META_FEATURED = '_sektorel_company_featured';
    const META_ORIGIN   = '_sektorel_company_origin';

    const ORIGIN_USER_CREATED  = 'user_created';
    const ORIGIN_ADMIN_MANUAL  = 'admin_manual';
    const ORIGIN_ADMIN_IMPORT  = 'admin_import';
    const ORIGIN_AUTO_REGISTRY = 'auto_registry';

    public static function init() {
        add_action( 'save_post_company', array( __CLASS__, 'ensure_origin' ), 20, 3 );
        add_action( 'add_meta_boxes_company', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'save_post_company', array( __CLASS__, 'save_admin_fields' ), 40, 3 );
        add_action( 'graphql_register_types', array( __CLASS__, 'register_graphql_fields' ) );
    }

    public static function origins() {
        return array(
            self::ORIGIN_USER_CREATED  => 'Kullanıcı oluşturdu',
            self::ORIGIN_ADMIN_MANUAL  => 'Admin manuel',
            self::ORIGIN_ADMIN_IMPORT  => 'Admin içe aktarma',
            self::ORIGIN_AUTO_REGISTRY => 'Otomatik keşif',
        );
    }

    public static function add_meta_box() {
        add_meta_box(
            'sektorel_company_ranking',
            'Firma Listeleme & Kaynak',
            array( __CLASS__, 'render_meta_box' ),
            'company',
            'side',
            'high'
        );
    }

    public static function render_meta_box( $post ) {
        wp_nonce_field( 'sektorel_company_ranking_save', 'sektorel_company_ranking_nonce' );
        $origin           = self::origin( $post->ID );
        $ownership_status = self::ownership_status( $post->ID );
        $tier             = self::priority_tier( $post->ID );
        ?>
        <p>
            <label>
                <input type="checkbox" name="sektorel_company_featured" value="1" <?php checked( self::is_featured( $post->ID ) ); ?> />
                <strong>Öne çıkarılan firma</strong>
            </label>
        </p>
        <p class="description">Öne çıkarılan firmalar firma rehberinde her zaman ilk grupta listelenir.</p>
        <hr>
        <p><strong>Köken</strong></p>
        <select name="sektorel_company_origin" style="width:100%;">
            <?php foreach ( self::origins() as $key => $label ) : ?>
                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $origin, $key ); ?>><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>
        <p class="description">Otomatik kaynak motoru ileride yalnız <code>Otomatik keşif</code> kökenini kullanacak.</p>
        <hr>
        <p><strong>Sahiplik:</strong> <?php echo esc_html( 'claimed' === $ownership_status ? 'Müşteri sahipliğinde' : 'Sahipsiz / admin' ); ?></p>
        <p><strong>Listeleme katmanı:</strong> <?php echo esc_html( (string) $tier ); ?> / 4</p>
        <?php
    }

    public static function save_admin_fields( $post_id, $post, $update ) {
        if ( ! is_admin() || ! $post instanceof WP_Post || 'company' !== $post->post_type ) {
            return;
        }
        if ( ! isset( $_POST['sektorel_company_ranking_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sektorel_company_ranking_nonce'] ) ), 'sektorel_company_ranking_save' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        update_post_meta( $post_id, self::META_FEATURED, isset( $_POST['sektorel_company_featured'] ) ? '1' : '0' );

        $origin = isset( $_POST['sektorel_company_origin'] ) ? sanitize_key( wp_unslash( $_POST['sektorel_company_origin'] ) ) : '';
        if ( array_key_exists( $origin, self::origins() ) ) {
            update_post_meta( $post_id, self::META_ORIGIN, $origin );
        }
    }

    public static function ensure_origin( $post_id, $post, $update ) {
        if ( ! $post instanceof WP_Post || 'company' !== $post->post_type ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( get_post_meta( $post_id, self::META_ORIGIN, true ) ) {
            return;
        }

        $author_id = (int) $post->post_author;
        $origin    = self::ORIGIN_ADMIN_MANUAL;

        if ( $author_id && self::user_owns_company( $author_id, $post_id ) ) {
            $origin = self::ORIGIN_USER_CREATED;
        } elseif ( self::has_import_evidence( $post_id ) ) {
            $origin = self::ORIGIN_ADMIN_IMPORT;
        }

        update_post_meta( $post_id, self::META_ORIGIN, $origin );
    }

    public static function set_origin( $company_id, $origin ) {
        $origin = sanitize_key( $origin );
        if ( ! array_key_exists( $origin, self::origins() ) ) {
            return false;
        }
        update_post_meta( (int) $company_id, self::META_ORIGIN, $origin );
        return true;
    }

    public static function is_featured( $company_id ) {
        return '1' === (string) get_post_meta( (int) $company_id, self::META_FEATURED, true );
    }

    public static function origin( $company_id ) {
        $stored = sanitize_key( (string) get_post_meta( (int) $company_id, self::META_ORIGIN, true ) );
        if ( array_key_exists( $stored, self::origins() ) ) {
            return $stored;
        }

        $post = get_post( (int) $company_id );
        if ( $post && self::user_owns_company( (int) $post->post_author, (int) $company_id ) ) {
            return self::ORIGIN_USER_CREATED;
        }
        if ( self::has_import_evidence( (int) $company_id ) ) {
            return self::ORIGIN_ADMIN_IMPORT;
        }
        return self::ORIGIN_ADMIN_MANUAL;
    }

    public static function ownership_status( $company_id ) {
        $post = get_post( (int) $company_id );
        if ( ! $post || 'company' !== $post->post_type ) {
            return 'unclaimed';
        }
        return self::user_owns_company( (int) $post->post_author, (int) $company_id ) ? 'claimed' : 'unclaimed';
    }

    public static function priority_tier( $company_id ) {
        if ( self::is_featured( $company_id ) ) {
            return 1;
        }
        if ( 'claimed' === self::ownership_status( $company_id ) ) {
            return 2;
        }
        if ( self::ORIGIN_AUTO_REGISTRY === self::origin( $company_id ) ) {
            return 4;
        }
        return 3;
    }

    public static function user_owns_company( $user_id, $company_id ) {
        $user_id    = (int) $user_id;
        $company_id = (int) $company_id;
        if ( ! $user_id || ! $company_id ) {
            return false;
        }

        $linked_company_id = (int) get_user_meta( $user_id, '_sektorel_company_id', true );
        if ( $linked_company_id !== $company_id ) {
            return false;
        }

        $company = get_post( $company_id );
        return $company && 'company' === $company->post_type && (int) $company->post_author === $user_id;
    }

    private static function has_import_evidence( $company_id ) {
        foreach ( array( '_sektorel_import_domain', '_sektorel_source_sites', '_sektorel_source_detail_urls' ) as $key ) {
            if ( get_post_meta( (int) $company_id, $key, true ) ) {
                return true;
            }
        }
        return false;
    }

    public static function register_graphql_fields() {
        register_graphql_object_type( 'SektorelCompanyDirectoryMeta', array(
            'description' => 'Firma listeleme önceliği, kökeni ve sahiplik durumu.',
            'fields'      => array(
                'isFeatured'      => array( 'type' => 'Boolean' ),
                'origin'          => array( 'type' => 'String' ),
                'ownershipStatus' => array( 'type' => 'String' ),
                'priorityTier'    => array( 'type' => 'Int' ),
            ),
        ) );

        register_graphql_field( 'Company', 'sektorelDirectoryMeta', array(
            'type'        => 'SektorelCompanyDirectoryMeta',
            'description' => 'Firma rehberi sıralama metadatası.',
            'resolve'     => function( $source ) {
                $company_id = 0;
                if ( is_object( $source ) ) {
                    $company_id = (int) ( $source->databaseId ?? $source->ID ?? 0 );
                } elseif ( is_array( $source ) ) {
                    $company_id = (int) ( $source['databaseId'] ?? $source['ID'] ?? 0 );
                }
                if ( ! $company_id ) {
                    return null;
                }

                return array(
                    'isFeatured'      => self::is_featured( $company_id ),
                    'origin'          => self::origin( $company_id ),
                    'ownershipStatus' => self::ownership_status( $company_id ),
                    'priorityTier'    => self::priority_tier( $company_id ),
                );
            },
        ) );
    }
}
