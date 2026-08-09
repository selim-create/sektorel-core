<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sektorel_Event_Reminders {

    const CRON_HOOK = 'sektorel_process_event_reminders';
    const ALLOWED_DAYS = array( 1, 3, 7 );

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_types' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'process_due_reminders' ) );
        add_action( 'init', array( __CLASS__, 'ensure_cron' ), 20 );
        add_action( 'save_post', array( __CLASS__, 'reschedule_event_reminders' ), 30, 3 );
    }

    public static function ensure_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
        }
    }

    public static function register_types() {
        register_graphql_object_type( 'SektorelEventReminder', array(
            'fields' => array(
                'databaseId' => array( 'type' => 'Int' ),
                'eventId'    => array( 'type' => 'Int' ),
                'eventTitle' => array( 'type' => 'String' ),
                'eventSlug'  => array( 'type' => 'String' ),
                'daysBefore' => array( 'type' => 'Int' ),
                'remindAt'   => array( 'type' => 'String' ),
                'status'     => array( 'type' => 'String' ),
            ),
        ) );

        register_graphql_field( 'RootQuery', 'sektorelEventReminder', array(
            'type' => 'SektorelEventReminder',
            'args' => array(
                'eventSlug' => array( 'type' => array( 'non_null' => 'String' ) ),
            ),
            'resolve' => function( $root, $args ) {
                $user_id = self::require_user_id();
                $event = self::get_event_by_slug( $args['eventSlug'] ?? '' );
                $reminder = self::find_active_reminder( $user_id, (int) $event->ID );
                return $reminder ? self::format_reminder( $reminder ) : null;
            },
        ) );

        register_graphql_field( 'RootQuery', 'sektorelEventReminders', array(
            'type' => array( 'list_of' => 'SektorelEventReminder' ),
            'resolve' => function() {
                $user_id = self::require_user_id();
                $posts = get_posts( array(
                    'post_type'      => 'event_reminder',
                    'post_status'    => 'private',
                    'author'         => $user_id,
                    'posts_per_page' => 100,
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                    'meta_query'     => array(
                        array(
                            'key'     => 'reminder_status',
                            'value'   => array( 'pending', 'sent' ),
                            'compare' => 'IN',
                        ),
                    ),
                ) );

                return array_map( array( __CLASS__, 'format_reminder' ), $posts );
            },
        ) );

        register_graphql_mutation( 'saveSektorelEventReminder', array(
            'inputFields' => array(
                'eventSlug'  => array( 'type' => array( 'non_null' => 'String' ) ),
                'daysBefore' => array( 'type' => array( 'non_null' => 'Int' ) ),
            ),
            'outputFields' => array(
                'success'  => array( 'type' => 'Boolean' ),
                'message'  => array( 'type' => 'String' ),
                'reminder' => array( 'type' => 'SektorelEventReminder' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $user_id = self::require_user_id();
                $event = self::get_event_by_slug( $input['eventSlug'] ?? '' );
                $days = (int) ( $input['daysBefore'] ?? 0 );

                if ( ! in_array( $days, self::ALLOWED_DAYS, true ) ) {
                    throw new \GraphQL\Error\UserError( 'Hatırlatma süresi 1, 3 veya 7 gün olabilir.' );
                }

                $remind_at = self::calculate_remind_at( $event, $days );
                if ( ! $remind_at ) {
                    throw new \GraphQL\Error\UserError( 'Etkinliğin geçerli bir başlangıç tarihi bulunmuyor.' );
                }
                if ( $remind_at <= time() ) {
                    throw new \GraphQL\Error\UserError( 'Bu hatırlatma zamanı geçmişte kaldığı için oluşturulamaz.' );
                }

                $existing = self::find_active_reminder( $user_id, (int) $event->ID );

                if ( $existing ) {
                    $reminder_id = (int) $existing->ID;
                    wp_update_post( array(
                        'ID'         => $reminder_id,
                        'post_title' => sprintf( '%s – %d gün önce', get_the_title( $event ), $days ),
                    ) );
                } else {
                    $reminder_id = wp_insert_post( array(
                        'post_type'   => 'event_reminder',
                        'post_status' => 'private',
                        'post_author' => $user_id,
                        'post_title'  => sprintf( '%s – %d gün önce', get_the_title( $event ), $days ),
                    ), true );

                    if ( is_wp_error( $reminder_id ) ) {
                        throw new \GraphQL\Error\UserError( 'Hatırlatma oluşturulamadı.' );
                    }
                }

                update_post_meta( $reminder_id, 'event_id', (int) $event->ID );
                update_post_meta( $reminder_id, 'days_before', $days );
                update_post_meta( $reminder_id, 'remind_at', $remind_at );
                update_post_meta( $reminder_id, 'reminder_status', 'pending' );
                update_post_meta( $reminder_id, 'attempts', 0 );
                delete_post_meta( $reminder_id, 'sent_at' );

                return array(
                    'success'  => true,
                    'message'  => sprintf( 'Etkinlikten %d gün önce e-posta hatırlatması gönderilecek.', $days ),
                    'reminder' => self::format_reminder( get_post( $reminder_id ) ),
                );
            },
        ) );

        register_graphql_mutation( 'cancelSektorelEventReminder', array(
            'inputFields' => array(
                'eventSlug' => array( 'type' => array( 'non_null' => 'String' ) ),
            ),
            'outputFields' => array(
                'success' => array( 'type' => 'Boolean' ),
                'message' => array( 'type' => 'String' ),
            ),
            'mutateAndGetPayload' => function( $input ) {
                $user_id = self::require_user_id();
                $event = self::get_event_by_slug( $input['eventSlug'] ?? '' );
                $reminder = self::find_active_reminder( $user_id, (int) $event->ID );

                if ( ! $reminder ) {
                    return array(
                        'success' => true,
                        'message' => 'Aktif hatırlatma bulunmuyor.',
                    );
                }

                update_post_meta( $reminder->ID, 'reminder_status', 'cancelled' );

                return array(
                    'success' => true,
                    'message' => 'Hatırlatma iptal edildi.',
                );
            },
        ) );
    }

    public static function process_due_reminders() {
        $now = time();
        $reminders = get_posts( array(
            'post_type'      => 'event_reminder',
            'post_status'    => 'private',
            'posts_per_page' => 50,
            'orderby'        => 'meta_value_num',
            'meta_key'       => 'remind_at',
            'order'          => 'ASC',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'   => 'reminder_status',
                    'value' => 'pending',
                ),
                array(
                    'key'     => 'remind_at',
                    'value'   => $now,
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ),
            ),
        ) );

        foreach ( $reminders as $reminder ) {
            self::send_reminder( $reminder );
        }
    }

    public static function reschedule_event_reminders( $post_id, $post, $update ) {
        if ( ! $post || 'event' !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        $reminders = get_posts( array(
            'post_type'      => 'event_reminder',
            'post_status'    => 'private',
            'posts_per_page' => 200,
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => 'event_id',
                    'value'   => (int) $post_id,
                    'type'    => 'NUMERIC',
                ),
                array(
                    'key'   => 'reminder_status',
                    'value' => 'pending',
                ),
            ),
        ) );

        foreach ( $reminders as $reminder ) {
            $days = (int) get_post_meta( $reminder->ID, 'days_before', true );
            $remind_at = self::calculate_remind_at( $post, $days );

            if ( ! $remind_at || $remind_at <= time() ) {
                update_post_meta( $reminder->ID, 'reminder_status', 'expired' );
                continue;
            }

            update_post_meta( $reminder->ID, 'remind_at', $remind_at );
        }
    }

    private static function send_reminder( $reminder ) {
        update_post_meta( $reminder->ID, 'reminder_status', 'processing' );

        $event_id = (int) get_post_meta( $reminder->ID, 'event_id', true );
        $event = get_post( $event_id );
        $user = get_userdata( (int) $reminder->post_author );
        $days = (int) get_post_meta( $reminder->ID, 'days_before', true );

        if ( ! $event || 'event' !== $event->post_type || ! $user || ! is_email( $user->user_email ) ) {
            update_post_meta( $reminder->ID, 'reminder_status', 'failed' );
            return;
        }

        $start = self::get_event_start( $event );
        if ( ! $start ) {
            update_post_meta( $reminder->ID, 'reminder_status', 'failed' );
            return;
        }

        $frontend = untrailingslashit( apply_filters( 'sektorel_frontend_url', 'https://sektorelajanda.com' ) );
        $event_url = $frontend . '/ajanda/' . $event->post_name;
        $subject = sprintf( 'Hatırlatma: %s', get_the_title( $event ) );
        $message = sprintf(
            "Merhaba %s,\n\n%s etkinliği için kurduğunuz hatırlatmanın zamanı geldi.\n\nEtkinlik: %s\nTarih: %s\nHatırlatma: %d gün önce\n\nDetaylar: %s\n\nSektörel Ajanda",
            $user->display_name ?: 'Üye',
            get_the_title( $event ),
            get_the_title( $event ),
            wp_date( 'd.m.Y H:i', $start->getTimestamp(), wp_timezone() ),
            $days,
            $event_url
        );

        $sent = Sektorel_Mail_Observability::send(
            $user->user_email,
            $subject,
            $message,
            array( 'Content-Type: text/plain; charset=UTF-8' ),
            array(),
            'event_reminder'
        );

        if ( $sent ) {
            update_post_meta( $reminder->ID, 'reminder_status', 'sent' );
            update_post_meta( $reminder->ID, 'sent_at', time() );
            return;
        }

        $attempts = (int) get_post_meta( $reminder->ID, 'attempts', true ) + 1;
        update_post_meta( $reminder->ID, 'attempts', $attempts );
        update_post_meta( $reminder->ID, 'reminder_status', $attempts >= 3 ? 'failed' : 'pending' );
    }

    private static function get_event_start( $event ) {
        $raw = (string) get_post_meta( $event->ID, 'start_date', true );
        if ( ! $raw ) {
            return null;
        }

        $timezone = wp_timezone();
        $format = strlen( $raw ) > 16 ? 'Y-m-d\TH:i:s' : 'Y-m-d\TH:i';
        $date = DateTimeImmutable::createFromFormat( $format, $raw, $timezone );

        if ( ! $date ) {
            try {
                $date = new DateTimeImmutable( $raw, $timezone );
            } catch ( Exception $e ) {
                return null;
            }
        }

        return $date;
    }

    private static function calculate_remind_at( $event, $days ) {
        if ( ! in_array( (int) $days, self::ALLOWED_DAYS, true ) ) {
            return 0;
        }

        $start = self::get_event_start( $event );
        if ( ! $start ) {
            return 0;
        }

        return $start->modify( '-' . (int) $days . ' days' )->getTimestamp();
    }

    private static function require_user_id() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            throw new \GraphQL\Error\UserError( 'Hatırlatma oluşturmak için giriş yapmanız gerekir.' );
        }
        return (int) $user_id;
    }

    private static function get_event_by_slug( $slug ) {
        $slug = sanitize_title( $slug );
        $event = get_page_by_path( $slug, OBJECT, 'event' );
        if ( ! $event || 'publish' !== $event->post_status ) {
            throw new \GraphQL\Error\UserError( 'Etkinlik bulunamadı.' );
        }
        return $event;
    }

    private static function find_active_reminder( $user_id, $event_id ) {
        $posts = get_posts( array(
            'post_type'      => 'event_reminder',
            'post_status'    => 'private',
            'author'         => (int) $user_id,
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'     => 'event_id',
                    'value'   => (int) $event_id,
                    'type'    => 'NUMERIC',
                ),
                array(
                    'key'     => 'reminder_status',
                    'value'   => array( 'pending', 'processing' ),
                    'compare' => 'IN',
                ),
            ),
        ) );

        return ! empty( $posts[0] ) ? $posts[0] : null;
    }

    public static function format_reminder( $reminder ) {
        $event_id = (int) get_post_meta( $reminder->ID, 'event_id', true );
        $event = get_post( $event_id );
        $remind_at = (int) get_post_meta( $reminder->ID, 'remind_at', true );

        return array(
            'databaseId' => (int) $reminder->ID,
            'eventId'    => $event_id,
            'eventTitle' => $event ? get_the_title( $event ) : 'Silinmiş etkinlik',
            'eventSlug'  => $event ? $event->post_name : '',
            'daysBefore' => (int) get_post_meta( $reminder->ID, 'days_before', true ),
            'remindAt'   => $remind_at ? gmdate( DATE_ATOM, $remind_at ) : '',
            'status'     => (string) get_post_meta( $reminder->ID, 'reminder_status', true ),
        );
    }
}
