<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deterministic official/company compliance calendar.
 *
 * Official obligations do not enter the discovery Candidate queue. They are
 * canonical rule/calendar occurrences and are materialized directly as draft
 * Events with a stable occurrence key. The stage never publishes Events.
 *
 * GIB dates are intentionally year-scoped verified data packs because tax
 * calendar exceptions/holiday extensions can change exact dates. SGK and
 * Ministry of Trade rows are generated only from stable official rules.
 */
class Sektorel_Event_Official_Calendar_Stage {

    const NONCE_ACTION = 'sektorel_official_calendar';
    const QUEUE_TTL    = 2 * HOUR_IN_SECONDS;
    const BATCH_SIZE   = 12;
    const VERSION      = '1480';

    const GIB_SOURCE = 'https://www.gib.gov.tr/vergi-takvimi';
    const SGK_SOURCE = 'https://sgk.gov.tr/Content/Post/1f3a8bec-bca6-4e83-8e5c-f957a7d28af4/Isverenlerin-Prim-Odeme-Islemleri-2025-02-06-03-29-17';
    const TRADE_COMPANY_SOURCE = 'https://ticaret.gov.tr/ic-ticaret/sirketler/sirket-bilgiler';
    const TRADE_BOOK_SOURCE = 'https://ticaret.gov.tr/basin-merkezi/basin-aciklamalari/gumruk-ve-ticaret-bakani-hayati-yazici-ticaret-sicili-tasdiknamesi-uygulamasina-iliskin-aciklamalara-i%CC%87liskin-basin-aciklamasi-20-aralik-2013';
    const TRADE_JOURNAL_SOURCE = 'https://ticaret.gov.tr/haberler/bakan-yazicidan-tacirlere-yevmiye-defteri-uyarisi';

    public static function init() {
        add_action( 'wp_ajax_sektorel_official_calendar_prepare', array( __CLASS__, 'ajax_prepare' ) );
        add_action( 'wp_ajax_sektorel_official_calendar_batch', array( __CLASS__, 'ajax_batch' ) );
    }

    public static function ajax_prepare() {
        self::require_ajax();

        $year = isset( $_POST['year'] ) ? absint( $_POST['year'] ) : (int) current_time( 'Y' );
        if ( $year < 2025 || $year > ( (int) current_time( 'Y' ) + 2 ) ) {
            wp_send_json_error( array( 'message' => 'Resmî takvim yılı geçersiz.' ) );
        }

        $rows  = self::occurrences( $year );
        $token = strtolower( wp_generate_password( 24, false, false ) );

        set_transient(
            self::queue_key( get_current_user_id(), $token ),
            array(
                'year' => $year,
                'rows' => array_values( $rows ),
            ),
            self::QUEUE_TTL
        );

        wp_send_json_success( array(
            'token' => $token,
            'total' => count( $rows ),
        ) );
    }

    public static function ajax_batch() {
        self::require_ajax();

        $token  = isset( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
        $offset = isset( $_POST['offset'] ) ? max( 0, absint( $_POST['offset'] ) ) : 0;
        if ( ! $token ) {
            wp_send_json_error( array( 'message' => 'Resmî takvim kuyruk anahtarı eksik.' ) );
        }

        $key   = self::queue_key( get_current_user_id(), $token );
        $queue = get_transient( $key );
        if ( ! is_array( $queue ) || empty( $queue['rows'] ) || ! is_array( $queue['rows'] ) ) {
            wp_send_json_error( array( 'message' => 'Resmî takvim kuyruğu bulunamadı veya süresi doldu.' ) );
        }

        $rows      = array_values( $queue['rows'] );
        $batch     = array_slice( $rows, $offset, self::BATCH_SIZE );
        $created   = 0;
        $updated   = 0;
        $unchanged = 0;
        $skipped   = 0;
        $error     = 0;
        $messages  = array();

        foreach ( $batch as $row ) {
            $result = self::upsert_occurrence( $row );
            $label  = ! empty( $row['title'] ) ? $row['title'] : 'Resmî yükümlülük';

            if ( is_wp_error( $result ) ) {
                if ( 'official_event_not_managed' === $result->get_error_code() ) {
                    $skipped++;
                    $messages[] = 'Atlandı: ' . $label . ' — ' . $result->get_error_message();
                } else {
                    $error++;
                    $messages[] = 'Hata: ' . $label . ' — ' . $result->get_error_message();
                }
                continue;
            }

            if ( 'created' === $result['status'] ) {
                $created++;
                $messages[] = 'Yeni resmî taslak: ' . $label . ' → Event #' . absint( $result['event_id'] );
            } elseif ( 'updated' === $result['status'] ) {
                $updated++;
                $messages[] = 'Resmî kayıt güncellendi: ' . $label . ' → Event #' . absint( $result['event_id'] );
            } else {
                $unchanged++;
            }
        }

        $next_offset = min( count( $rows ), $offset + count( $batch ) );
        $done        = $next_offset >= count( $rows );

        if ( $done ) {
            delete_transient( $key );
        } else {
            set_transient( $key, $queue, self::QUEUE_TTL );
        }

        wp_send_json_success( array(
            'next_offset' => $next_offset,
            'done'        => $done,
            'created'     => $created,
            'updated'     => $updated,
            'unchanged'   => $unchanged,
            'skipped'     => $skipped,
            'error'       => $error,
            'messages'    => $messages,
        ) );
    }

    private static function occurrences( $year ) {
        $rows = array_merge(
            self::gib_occurrences( $year ),
            self::sgk_occurrences( $year ),
            self::trade_occurrences( $year )
        );

        $today = current_time( 'Y-m-d' );
        $max   = ( (int) $year + 1 ) . '-12-31';
        $seen  = array();
        $final = array();

        foreach ( $rows as $row ) {
            if ( empty( $row['due_date'] ) || empty( $row['occurrence_key'] ) ) {
                continue;
            }
            if ( $row['due_date'] < $today || $row['due_date'] > $max ) {
                continue;
            }
            $key = sanitize_key( str_replace( ':', '_', $row['occurrence_key'] ) );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $final[]      = $row;
        }

        usort( $final, static function ( $a, $b ) {
            if ( $a['due_date'] === $b['due_date'] ) {
                return strcmp( $a['occurrence_key'], $b['occurrence_key'] );
            }
            return strcmp( $a['due_date'], $b['due_date'] );
        } );

        return $final;
    }

    /**
     * 2026 pack: core obligations that broadly matter to operating companies.
     * Exact dates are kept explicit rather than guessed from a recurrence rule;
     * the official GIB annual calendar may include holiday/sirküler extensions.
     */
    private static function gib_occurrences( $year ) {
        if ( 2026 !== (int) $year ) {
            return array();
        }

        $rows = array();

        $kdv = array(
            '2026-07' => '2026-08-28',
            '2026-08' => '2026-09-28',
            '2026-09' => '2026-10-28',
            '2026-10' => '2026-11-30',
            '2026-11' => '2026-12-28',
        );
        foreach ( $kdv as $period => $due ) {
            $rows[] = self::row(
                'gib:kdv:' . $period,
                self::period_label( $period ) . ' KDV Beyan ve Ödeme Son Günü',
                $due,
                'Gelir İdaresi Başkanlığı',
                'vergi',
                array( 'vat_taxpayer' ),
                'Katma Değer Vergisinin beyanı ve ödemesi için GİB 2026 Vergi Takvimindeki son gündür.',
                self::GIB_SOURCE,
                'gib_kdv_monthly',
                $period,
                'verified_gib_2026_calendar'
            );
        }

        $withholding = array(
            '2026-07' => '2026-08-26',
            '2026-08' => '2026-09-28',
            '2026-09' => '2026-10-26',
            '2026-10' => '2026-11-26',
            '2026-11' => '2026-12-28',
        );
        foreach ( $withholding as $period => $due ) {
            $rows[] = self::row(
                'gib:muhsgk-withholding:' . $period,
                self::period_label( $period ) . ' Tevkifat / MUHSGK Beyan ve Ödeme Son Günü',
                $due,
                'Gelir İdaresi Başkanlığı',
                'beyanname',
                array( 'withholding_taxpayer', 'employer' ),
                'Gelir ve kurumlar vergisi tevkifatlarının Muhtasar ve Prim Hizmet Beyannamesi ile beyan ve ödemesine ilişkin çekirdek şirket takvim kaydıdır.',
                self::GIB_SOURCE,
                'gib_withholding_muhsgk_monthly',
                $period,
                'verified_gib_2026_calendar'
            );
        }

        $rows[] = self::row(
            'gib:corporate-provisional:2026-q2',
            '2026 II. Dönem Kurum Geçici Vergisi Beyan ve Ödeme Son Günü',
            '2026-08-17',
            'Gelir İdaresi Başkanlığı',
            'vergi',
            array( 'corporate_taxpayer' ),
            '2026 II. geçici vergi dönemine ait Kurum Geçici Vergisinin beyan ve ödemesi için son gündür.',
            self::GIB_SOURCE,
            'gib_corporate_provisional_tax',
            '2026-Q2',
            'verified_gib_2026_calendar'
        );
        $rows[] = self::row(
            'gib:beneficial-owner:2026-q2',
            '2026 II. Dönem Gerçek Faydalanıcı Bildirim Formu Son Günü',
            '2026-08-17',
            'Gelir İdaresi Başkanlığı',
            'beyanname',
            array( 'corporate_taxpayer' ),
            'Kurumlar vergisi mükelleflerinin Kurum Geçici Vergi Beyannamesi ekinde gerçek faydalanıcı bildirim formunu vermesi için son gündür.',
            self::GIB_SOURCE,
            'gib_beneficial_owner',
            '2026-Q2',
            'verified_gib_2026_calendar'
        );
        $rows[] = self::row(
            'gib:corporate-provisional:2026-q3',
            '2026 III. Dönem Kurum Geçici Vergisi Beyan ve Ödeme Son Günü',
            '2026-11-17',
            'Gelir İdaresi Başkanlığı',
            'vergi',
            array( 'corporate_taxpayer' ),
            '2026 III. geçici vergi dönemine ait Kurum Geçici Vergisinin beyan ve ödemesi için son gündür.',
            self::GIB_SOURCE,
            'gib_corporate_provisional_tax',
            '2026-Q3',
            'verified_gib_2026_calendar'
        );
        $rows[] = self::row(
            'gib:beneficial-owner:2026-q3',
            '2026 III. Dönem Gerçek Faydalanıcı Bildirim Formu Son Günü',
            '2026-11-17',
            'Gelir İdaresi Başkanlığı',
            'beyanname',
            array( 'corporate_taxpayer' ),
            'Kurumlar vergisi mükelleflerinin Kurum Geçici Vergi Beyannamesi ekinde gerçek faydalanıcı bildirim formunu vermesi için son gündür.',
            self::GIB_SOURCE,
            'gib_beneficial_owner',
            '2026-Q3',
            'verified_gib_2026_calendar'
        );

        $eledger = array(
            '2026-04' => '2026-08-14',
            '2026-05' => '2026-09-14',
            '2026-06' => '2026-10-14',
            '2026-07' => '2026-11-16',
            '2026-08' => '2026-12-14',
        );
        foreach ( $eledger as $period => $due ) {
            $rows[] = self::row(
                'gib:eledger-monthly-corporate:' . $period,
                self::period_label( $period ) . ' e-Defter Berat Yükleme Son Günü',
                $due,
                'Gelir İdaresi Başkanlığı',
                'beyanname',
                array( 'e_ledger_user', 'corporate_taxpayer' ),
                'Aylık yükleme tercihinde bulunmuş diğer mükelleflerin elektronik defter beratlarını yüklemesi için GİB takvimindeki son gündür.',
                self::GIB_SOURCE,
                'gib_eledger_monthly_corporate',
                $period,
                'verified_gib_2026_calendar'
            );
        }

        return $rows;
    }

    private static function sgk_occurrences( $year ) {
        $rows = array();

        // 4/a employers paying wages for the 1st-last day of a month: premium
        // payment deadline is the end of the following month; if the final day
        // is a weekend, the first following business day is used here.
        for ( $month = 1; $month <= 12; $month++ ) {
            $period = sprintf( '%04d-%02d', $year, $month );
            $next_y = $month === 12 ? $year + 1 : $year;
            $next_m = $month === 12 ? 1 : $month + 1;
            $last   = cal_days_in_month( CAL_GREGORIAN, $next_m, $next_y );
            $due    = self::roll_weekend_forward( sprintf( '%04d-%02d-%02d', $next_y, $next_m, $last ) );

            $rows[] = self::row(
                'sgk:4a-employer-premium:' . $period,
                self::period_label( $period ) . ' SGK 4/a İşveren Prim Ödemesi Son Günü',
                $due,
                'Sosyal Güvenlik Kurumu',
                'sgk',
                array( 'employer' ),
                '4/a kapsamında sigortalı çalıştıran ve ayın 1’i-sonu arasındaki çalışmalar için ücret ödeyen işverenlerin sigorta primlerini takip eden ayın sonuna kadar ödeme yükümlülüğüdür. Resmî süre uzatımı duyuruları ayrıca dikkate alınmalıdır.',
                self::SGK_SOURCE,
                'sgk_4a_employer_premium',
                $period,
                'sgk_statutory_rule'
            );
        }

        return $rows;
    }

    private static function trade_occurrences( $year ) {
        $next = (int) $year + 1;

        return array(
            self::row(
                'trade:books-opening:' . $next,
                $next . ' Yılı Ticari Defter Açılış Onayı Son Günü',
                $year . '-12-31',
                'T.C. Ticaret Bakanlığı',
                'resmi_yukumluluk',
                array( 'physical_commercial_books' ),
                'Takvim yılı esasına göre fiziki ticari defter kullanan tacirlerde izleyen faaliyet döneminde kullanılacak defterlerin açılış onayının, faaliyet döneminden önceki ayın sonuna kadar tamamlanmasına ilişkin yükümlülüktür.',
                self::TRADE_BOOK_SOURCE,
                'trade_books_opening_approval',
                (string) $next,
                'trade_statutory_rule'
            ),
            self::row(
                'trade:ordinary-general-assembly:' . $year,
                $year . ' Faaliyet Dönemi Olağan Genel Kurul Son Günü',
                $next . '-03-31',
                'T.C. Ticaret Bakanlığı',
                'resmi_yukumluluk',
                array( 'joint_stock_company', 'limited_company' ),
                'Olağan genel kurul toplantısının her faaliyet dönemi sonundan itibaren üç ay içinde yapılmasına ilişkin şirketler hukuku takvim kaydıdır. Takvim yılı esaslı şirketler için son gün 31 Mart olarak materialize edilmiştir.',
                self::TRADE_COMPANY_SOURCE,
                'trade_ordinary_general_assembly',
                (string) $year,
                'trade_statutory_rule'
            ),
            self::row(
                'trade:journal-closing:' . $year,
                $year . ' Yevmiye Defteri Kapanış Onayı Son Günü',
                $next . '-06-30',
                'T.C. Ticaret Bakanlığı',
                'resmi_yukumluluk',
                array( 'physical_commercial_books' ),
                'Fiziki ortamda tutulan yevmiye defterinin kapanış onayının izleyen faaliyet döneminin altıncı ayının sonuna kadar yaptırılmasına ilişkin yükümlülüktür.',
                self::TRADE_JOURNAL_SOURCE,
                'trade_journal_closing_approval',
                (string) $year,
                'trade_statutory_rule'
            ),
        );
    }

    private static function row( $occurrence_key, $title, $due_date, $institution, $category, $applicability, $description, $source_url, $rule_key, $period, $date_basis ) {
        return array(
            'occurrence_key' => sanitize_key( str_replace( ':', '_', $occurrence_key ) ),
            'title'          => sanitize_text_field( $title ),
            'due_date'       => sanitize_text_field( $due_date ),
            'institution'    => sanitize_text_field( $institution ),
            'category'       => sanitize_key( $category ),
            'applicability'  => array_values( array_unique( array_map( 'sanitize_key', (array) $applicability ) ) ),
            'description'    => sanitize_textarea_field( $description ),
            'source_url'     => esc_url_raw( $source_url, array( 'http', 'https' ) ),
            'rule_key'       => sanitize_key( $rule_key ),
            'period'         => sanitize_text_field( $period ),
            'date_basis'     => sanitize_key( $date_basis ),
        );
    }

    private static function upsert_occurrence( $row ) {
        foreach ( array( 'occurrence_key', 'title', 'due_date', 'institution', 'category', 'source_url', 'rule_key' ) as $required ) {
            if ( empty( $row[ $required ] ) ) {
                return new WP_Error( 'official_row_incomplete', 'Resmî takvim satırında zorunlu alan eksik: ' . $required );
            }
        }

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $row['due_date'] ) ) {
            return new WP_Error( 'official_due_date_invalid', 'Resmî takvim tarihi geçersiz.' );
        }

        $event_id = self::find_event_id( $row['occurrence_key'] );
        $created  = false;

        if ( ! $event_id ) {
            $event_id = wp_insert_post(
                array(
                    'post_type'    => 'event',
                    'post_status'  => 'draft',
                    'post_title'   => $row['title'],
                    'post_content' => $row['description'],
                ),
                true
            );
            if ( is_wp_error( $event_id ) ) {
                return $event_id;
            }
            $event_id = absint( $event_id );
            $created  = true;
        } elseif ( '1' !== (string) get_post_meta( $event_id, 'official_calendar_managed', true ) ) {
            return new WP_Error( 'official_event_not_managed', 'Aynı occurrence anahtarına sahip kayıt otomatik yönetilen resmî Event değil.' );
        }

        $changed = false;
        $post    = get_post( $event_id );
        if ( $post && ( $post->post_title !== $row['title'] || $post->post_content !== $row['description'] ) ) {
            $updated = wp_update_post(
                array(
                    'ID'           => $event_id,
                    'post_title'   => $row['title'],
                    'post_content' => $row['description'],
                ),
                true
            );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
            $changed = true;
        }

        $date_value = $row['due_date'] . 'T23:59';
        $meta = array(
            'is_official'                     => 1,
            'event_type'                      => 'resmi',
            'start_date'                      => $date_value,
            'end_date'                        => $date_value,
            'organizer'                       => $row['institution'],
            'event_url'                       => $row['source_url'],
            'source_url'                      => $row['source_url'],
            'official_category'               => $row['category'],
            'official_institution'            => $row['institution'],
            'official_source_url'             => $row['source_url'],
            'official_applicability'           => $row['applicability'],
            'official_rule_key'               => $row['rule_key'],
            'official_occurrence_key'         => $row['occurrence_key'],
            'official_period'                 => $row['period'],
            'official_date_basis'             => $row['date_basis'],
            'official_calendar_managed'       => 1,
            'official_calendar_engine_version'=> self::VERSION,
        );

        foreach ( $meta as $key => $value ) {
            if ( get_post_meta( $event_id, $key, true ) !== $value ) {
                update_post_meta( $event_id, $key, $value );
                $changed = true;
            }
        }

        self::store_evidence( $event_id, $row );

        return array(
            'event_id' => $event_id,
            'status'   => $created ? 'created' : ( $changed ? 'updated' : 'unchanged' ),
        );
    }

    private static function find_event_id( $occurrence_key ) {
        $ids = get_posts( array(
            'post_type'      => 'event',
            'post_status'    => array( 'publish', 'draft', 'future', 'pending', 'private' ),
            'posts_per_page' => 2,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array(
                    'key'   => 'official_occurrence_key',
                    'value' => sanitize_key( $occurrence_key ),
                ),
            ),
        ) );

        if ( count( $ids ) > 1 ) {
            return 0;
        }

        return $ids ? absint( $ids[0] ) : 0;
    }

    private static function store_evidence( $event_id, $row ) {
        $evidence = get_post_meta( $event_id, 'event_source_evidence', true );
        $evidence = is_array( $evidence ) ? $evidence : array();
        $kept     = array();

        foreach ( $evidence as $entry ) {
            if ( ! is_array( $entry ) || ( isset( $entry['official_occurrence_key'] ) && $entry['official_occurrence_key'] === $row['occurrence_key'] ) ) {
                continue;
            }
            $kept[] = $entry;
        }

        $kept[] = array(
            'source_id'               => 0,
            'source_name'             => $row['institution'],
            'source_url'              => $row['source_url'],
            'parser_type'             => 'official',
            'last_seen_at'            => current_time( 'mysql' ),
            'official_occurrence_key' => $row['occurrence_key'],
            'values'                  => array(
                'start_date' => $row['due_date'] . 'T23:59',
                'end_date'   => $row['due_date'] . 'T23:59',
                'organizer'  => $row['institution'],
                'event_url'  => $row['source_url'],
            ),
        );

        update_post_meta( $event_id, 'event_source_evidence', array_values( $kept ) );
        update_post_meta( $event_id, 'event_source_evidence_count', count( $kept ) );
    }

    private static function period_label( $period ) {
        if ( ! preg_match( '/^(\d{4})-(\d{2})$/', (string) $period, $m ) ) {
            return $period;
        }
        $months = array( 1=>'Ocak', 2=>'Şubat', 3=>'Mart', 4=>'Nisan', 5=>'Mayıs', 6=>'Haziran', 7=>'Temmuz', 8=>'Ağustos', 9=>'Eylül', 10=>'Ekim', 11=>'Kasım', 12=>'Aralık' );
        $month  = absint( $m[2] );
        return ( isset( $months[ $month ] ) ? $months[ $month ] : $m[2] ) . ' ' . $m[1];
    }

    private static function roll_weekend_forward( $date ) {
        try {
            $dt = new DateTimeImmutable( $date, wp_timezone() );
            while ( in_array( (int) $dt->format( 'N' ), array( 6, 7 ), true ) ) {
                $dt = $dt->modify( '+1 day' );
            }
            return $dt->format( 'Y-m-d' );
        } catch ( Exception $e ) {
            return $date;
        }
    }

    private static function queue_key( $user_id, $token ) {
        return 'sektorel_official_calendar_' . absint( $user_id ) . '_' . sanitize_key( $token );
    }

    private static function require_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Yetkisiz işlem.' ), 403 );
        }
    }
}
