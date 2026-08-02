<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Company_Members {

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_types' ) );
    }

    public static function register_types() {
        register_graphql_object_type( 'SektorelCompanyMember', array(
            'fields' => array(
                'userId'      => array( 'type' => 'Int' ),
                'displayName' => array( 'type' => 'String' ),
                'email'       => array( 'type' => 'String' ),
                'role'        => array( 'type' => 'String' ),
                'isOwner'     => array( 'type' => 'Boolean' ),
            ),
        ) );

        register_graphql_field( 'RootQuery', 'sektorelCompanyMembers', array(
            'type' => array( 'list_of' => 'SektorelCompanyMember' ),
            'resolve' => function() {
                list( $owner_id, $company_id ) = self::require_owner();

                $members = array();
                $owner = get_userdata( $owner_id );
                if ( $owner ) {
                    $members[] = self::format_member( $owner, 'owner', true );
                }

                $users = get_users( array(
                    'meta_key'   => '_sektorel_member_company_id',
                    'meta_value' => (string) $company_id,
                    'orderby'    => 'display_name',
                    'order'      => 'ASC',
                ) );

                foreach ( $users as $user ) {
                    $members[] = self::format_member(
                        $user,
                        (string) ( get_user_meta( $user->ID, '_sektorel_company_role', true ) ?: 'viewer' ),
                        false
                    );
                }

                return $members;
            },
        ) );

        register_graphql_mutation( 'addSektorelCompanyMember', array(
            'inputFields' => array(
                'email' => array( 'type' => array( 'non_null' => 'String' ) ),
                'role'  => array( 'type' => 'String' ),
            ),
            'outputFields' => self::output_fields(),
            'mutateAndGetPayload' => function( $input ) {
                list( $owner_id, $company_id ) = self::require_owner();
                $email = sanitize_email( $input['email'] ?? '' );
                $role  = self::sanitize_role( $input['role'] ?? 'viewer' );

                if ( ! is_email( $email ) ) {
                    throw new \GraphQL\Error\UserError( 'Geçerli bir e-posta adresi girin.' );
                }

                $user = get_user_by( 'email', $email );
                if ( ! $user ) {
                    throw new \GraphQL\Error\UserError( 'Bu e-posta adresiyle kayıtlı bir kullanıcı bulunamadı.' );
                }
                if ( (int) $user->ID === (int) $owner_id ) {
                    throw new \GraphQL\Error\UserError( 'Firma sahibi zaten ekipte bulunuyor.' );
                }

                $owned_company = Sektorel_Session_Query::get_owned_company_id( $user->ID );
                $member_company = (int) get_user_meta( $user->ID, '_sektorel_member_company_id', true );
                if ( $owned_company || $member_company ) {
                    throw new \GraphQL\Error\UserError( 'Bu kullanıcı başka bir firmaya bağlı.' );
                }

                update_user_meta( $user->ID, '_sektorel_member_company_id', $company_id );
                update_user_meta( $user->ID, '_sektorel_company_role', $role );
                update_user_meta( $user->ID, 'account_type', 'kurumsal' );

                return array(
                    'success' => true,
                    'message' => 'Kullanıcı firmaya eklendi.',
                    'member'  => self::format_member( $user, $role, false ),
                );
            },
        ) );

        register_graphql_mutation( 'updateSektorelCompanyMemberRole', array(
            'inputFields' => array(
                'userId' => array( 'type' => array( 'non_null' => 'Int' ) ),
                'role'   => array( 'type' => array( 'non_null' => 'String' ) ),
            ),
            'outputFields' => self::output_fields(),
            'mutateAndGetPayload' => function( $input ) {
                list( $owner_id, $company_id ) = self::require_owner();
                $user_id = (int) ( $input['userId'] ?? 0 );
                $role = self::sanitize_role( $input['role'] ?? '' );

                if ( $user_id === $owner_id ) {
                    throw new \GraphQL\Error\UserError( 'Firma sahibinin rolü değiştirilemez.' );
                }
                self::require_member_of_company( $user_id, $company_id );
                update_user_meta( $user_id, '_sektorel_company_role', $role );

                $user = get_userdata( $user_id );
                return array(
                    'success' => true,
                    'message' => 'Kullanıcı rolü güncellendi.',
                    'member'  => $user ? self::format_member( $user, $role, false ) : null,
                );
            },
        ) );

        register_graphql_mutation( 'removeSektorelCompanyMember', array(
            'inputFields' => array(
                'userId' => array( 'type' => array( 'non_null' => 'Int' ) ),
            ),
            'outputFields' => self::output_fields(),
            'mutateAndGetPayload' => function( $input ) {
                list( $owner_id, $company_id ) = self::require_owner();
                $user_id = (int) ( $input['userId'] ?? 0 );

                if ( $user_id === $owner_id ) {
                    throw new \GraphQL\Error\UserError( 'Firma sahibi ekipten çıkarılamaz.' );
                }
                self::require_member_of_company( $user_id, $company_id );

                delete_user_meta( $user_id, '_sektorel_member_company_id' );
                delete_user_meta( $user_id, '_sektorel_company_role' );

                return array(
                    'success' => true,
                    'message' => 'Kullanıcı ekipten çıkarıldı.',
                    'member'  => null,
                );
            },
        ) );
    }

    private static function output_fields() {
        return array(
            'success' => array( 'type' => 'Boolean' ),
            'message' => array( 'type' => 'String' ),
            'member'  => array( 'type' => 'SektorelCompanyMember' ),
        );
    }

    private static function require_owner() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            throw new \GraphQL\Error\UserError( 'Bu işlem için giriş yapmanız gerekir.' );
        }

        $company_id = Sektorel_Session_Query::get_owned_company_id( $user_id );
        $company = $company_id ? get_post( $company_id ) : null;
        if ( ! $company || 'company' !== $company->post_type || (int) $company->post_author !== (int) $user_id ) {
            throw new \GraphQL\Error\UserError( 'Ekip yönetimi yalnızca firma sahibine açıktır.' );
        }

        return array( (int) $user_id, (int) $company_id );
    }

    private static function require_member_of_company( $user_id, $company_id ) {
        $current_company = (int) get_user_meta( $user_id, '_sektorel_member_company_id', true );
        if ( $current_company !== (int) $company_id ) {
            throw new \GraphQL\Error\UserError( 'Kullanıcı bu firmaya bağlı değil.' );
        }
    }

    private static function sanitize_role( $role ) {
        $role = sanitize_key( $role );
        if ( ! in_array( $role, array( 'editor', 'viewer' ), true ) ) {
            throw new \GraphQL\Error\UserError( 'Geçersiz kullanıcı rolü.' );
        }
        return $role;
    }

    private static function format_member( $user, $role, $is_owner ) {
        return array(
            'userId'      => (int) $user->ID,
            'displayName' => (string) $user->display_name,
            'email'       => (string) $user->user_email,
            'role'        => (string) $role,
            'isOwner'     => (bool) $is_owner,
        );
    }
}
