<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Central configuration and cost-control service for Sektörel Core.
 *
 * Runtime precedence:
 * 1. wp-config.php constants
 * 2. encrypted/sanitized WordPress options managed by Sektörel Core
 * 3. conservative built-in defaults
 */
class Sektorel_Core_Settings {

    const OPTION = 'sektorel_core_settings';
    const OPTION_SECRETS = 'sektorel_core_secrets';
    const DEFAULT_DAILY_LIMIT = 20;
    const DEFAULT_MONTHLY_BUDGET = 15.00;

    public static function init() {
        self::define_runtime_constants();
        if ( is_admin() ) {
            add_action( 'wp_ajax_sektorel_content_ai_draft_batch', array( __CLASS__, 'guard_monthly_budget' ), 0 );
        }
    }

    public static function settings() {
        $saved = get_option( self::OPTION, array() );
        $saved = is_array( $saved ) ? $saved : array();

        return wp_parse_args(
            $saved,
            array(
                'openai_model'          => '',
                'content_daily_limit'   => self::DEFAULT_DAILY_LIMIT,
                'monthly_budget_usd'    => self::DEFAULT_MONTHLY_BUDGET,
                'budget_warning_pct'    => 80,
                'media_provider'        => 'pexels_first',
            )
        );
    }

    public static function update_settings( $input ) {
        $input = is_array( $input ) ? $input : array();
        $current = self::settings();

        $models = array_keys( self::model_catalog() );
        $model = isset( $input['openai_model'] ) ? sanitize_text_field( wp_unslash( $input['openai_model'] ) ) : $current['openai_model'];
        if ( $model && ! in_array( $model, $models, true ) ) {
            $model = $current['openai_model'];
        }

        $daily = isset( $input['content_daily_limit'] ) ? absint( $input['content_daily_limit'] ) : (int) $current['content_daily_limit'];
        $daily = max( 1, min( 200, $daily ) );

        $budget = isset( $input['monthly_budget_usd'] ) ? (float) str_replace( ',', '.', (string) $input['monthly_budget_usd'] ) : (float) $current['monthly_budget_usd'];
        $budget = max( 0, min( 100000, round( $budget, 2 ) ) );

        $warning = isset( $input['budget_warning_pct'] ) ? absint( $input['budget_warning_pct'] ) : (int) $current['budget_warning_pct'];
        $warning = max( 50, min( 100, $warning ) );

        $media_provider = isset( $input['media_provider'] ) ? sanitize_key( $input['media_provider'] ) : $current['media_provider'];
        if ( ! in_array( $media_provider, array( 'pexels_first', 'unsplash_first', 'pexels', 'unsplash' ), true ) ) {
            $media_provider = 'pexels_first';
        }

        update_option(
            self::OPTION,
            array(
                'openai_model'        => $model,
                'content_daily_limit' => $daily,
                'monthly_budget_usd'  => $budget,
                'budget_warning_pct'  => $warning,
                'media_provider'      => $media_provider,
            ),
            false
        );
    }

    public static function update_secret( $key, $plain_value, $clear = false ) {
        $allowed = array( 'openai_api_key', 'pexels_api_key', 'unsplash_access_key', 'unsplash_secret_key' );
        if ( ! in_array( $key, $allowed, true ) ) {
            return new WP_Error( 'invalid_secret_key', 'Geçersiz secret alanı.' );
        }

        $all = get_option( self::OPTION_SECRETS, array() );
        $all = is_array( $all ) ? $all : array();

        if ( $clear ) {
            unset( $all[ $key ] );
            update_option( self::OPTION_SECRETS, $all, false );
            return true;
        }

        $plain_value = trim( (string) $plain_value );
        if ( '' === $plain_value ) {
            return true; // Blank fields preserve the existing secret.
        }

        $encrypted = self::encrypt( $plain_value );
        if ( is_wp_error( $encrypted ) ) {
            return $encrypted;
        }

        $all[ $key ] = $encrypted;
        update_option( self::OPTION_SECRETS, $all, false );
        return true;
    }

    public static function secret( $key ) {
        $constant_map = array(
            'openai_api_key'       => 'SEKTOREL_OPENAI_API_KEY',
            'pexels_api_key'       => 'SEKTOREL_PEXELS_API_KEY',
            'unsplash_access_key'  => 'SEKTOREL_UNSPLASH_ACCESS_KEY',
            'unsplash_secret_key'  => 'SEKTOREL_UNSPLASH_SECRET_KEY',
        );

        if ( ! empty( $constant_map[ $key ] ) && defined( $constant_map[ $key ] ) ) {
            return trim( (string) constant( $constant_map[ $key ] ) );
        }

        $all = get_option( self::OPTION_SECRETS, array() );
        $all = is_array( $all ) ? $all : array();
        if ( empty( $all[ $key ] ) ) {
            return '';
        }

        $decrypted = self::decrypt( $all[ $key ] );
        return is_wp_error( $decrypted ) ? '' : trim( (string) $decrypted );
    }

    public static function secret_source( $key ) {
        $constant_map = array(
            'openai_api_key'       => 'SEKTOREL_OPENAI_API_KEY',
            'pexels_api_key'       => 'SEKTOREL_PEXELS_API_KEY',
            'unsplash_access_key'  => 'SEKTOREL_UNSPLASH_ACCESS_KEY',
            'unsplash_secret_key'  => 'SEKTOREL_UNSPLASH_SECRET_KEY',
        );
        if ( ! empty( $constant_map[ $key ] ) && defined( $constant_map[ $key ] ) && '' !== trim( (string) constant( $constant_map[ $key ] ) ) ) {
            return 'wp-config';
        }
        return self::secret( $key ) ? 'panel' : 'missing';
    }

    public static function effective_model() {
        if ( defined( 'SEKTOREL_OPENAI_MODEL' ) && '' !== trim( (string) SEKTOREL_OPENAI_MODEL ) ) {
            return trim( (string) SEKTOREL_OPENAI_MODEL );
        }
        $settings = self::settings();
        return ! empty( $settings['openai_model'] ) ? $settings['openai_model'] : 'gpt-5-mini';
    }

    public static function effective_daily_limit() {
        if ( defined( 'SEKTOREL_CONTENT_AI_DAILY_LIMIT' ) ) {
            return max( 1, min( 200, absint( SEKTOREL_CONTENT_AI_DAILY_LIMIT ) ) );
        }
        $settings = self::settings();
        return max( 1, min( 200, absint( $settings['content_daily_limit'] ) ) );
    }

    public static function monthly_budget() {
        $settings = self::settings();
        return max( 0, (float) $settings['monthly_budget_usd'] );
    }

    public static function model_catalog() {
        return array(
            'gpt-5.6-sol' => array(
                'label'        => 'GPT-5.6 Sol',
                'input'        => 4.00,
                'output'       => 20.00,
                'tier'         => 'En yüksek kalite',
                'recommended'  => false,
                'description'  => 'Karmaşık analiz, kritik editoryal kalite ve zor içerikler için.',
            ),
            'gpt-5.6-terra' => array(
                'label'        => 'GPT-5.6 Terra',
                'input'        => 2.00,
                'output'       => 12.00,
                'tier'         => 'Kalite / maliyet dengesi',
                'recommended'  => true,
                'description'  => 'Yeni üretim iş yükleri için güçlü kalite ve kontrollü maliyet dengesi.',
            ),
            'gpt-5.6-luna' => array(
                'label'        => 'GPT-5.6 Luna',
                'input'        => 0.20,
                'output'       => 1.20,
                'tier'         => 'Yüksek hacim',
                'recommended'  => false,
                'description'  => 'Yüksek hacimli, iyi yapılandırılmış ve maliyet hassas akışlar için.',
            ),
            'gpt-5-mini' => array(
                'label'        => 'GPT-5 Mini',
                'input'        => 0.25,
                'output'       => 2.00,
                'tier'         => 'Mevcut / ekonomik',
                'recommended'  => false,
                'description'  => 'Mevcut pipeline ile uyumlu, düşük maliyetli ve iyi tanımlı işler için.',
            ),
        );
    }

    public static function estimate_cost( $model, $input_tokens, $output_tokens ) {
        $catalog = self::model_catalog();
        if ( empty( $catalog[ $model ] ) ) {
            return 0.0;
        }
        $input_tokens = max( 0, (int) $input_tokens );
        $output_tokens = max( 0, (int) $output_tokens );
        return round(
            ( $input_tokens / 1000000 ) * (float) $catalog[ $model ]['input'] +
            ( $output_tokens / 1000000 ) * (float) $catalog[ $model ]['output'],
            6
        );
    }

    public static function usage_summary( $period = 'month' ) {
        global $wpdb;
        if ( ! class_exists( 'Sektorel_Content_Candidates' ) || ! Sektorel_Content_Candidates::maybe_install() ) {
            return array( 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0 );
        }

        $table = Sektorel_Content_Candidates::table_name();
        $now = current_time( 'timestamp', true );
        if ( 'day' === $period ) {
            $from = gmdate( 'Y-m-d 00:00:00', $now );
        } else {
            $from = gmdate( 'Y-m-01 00:00:00', $now );
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ai_model, ai_input_tokens, ai_output_tokens
                 FROM {$table}
                 WHERE ai_status = 'done' AND processed_at IS NOT NULL AND processed_at >= %s",
                $from
            ),
            ARRAY_A
        );

        $summary = array( 'requests' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost' => 0.0 );
        foreach ( (array) $rows as $row ) {
            $summary['requests']++;
            $summary['input_tokens'] += absint( $row['ai_input_tokens'] ?? 0 );
            $summary['output_tokens'] += absint( $row['ai_output_tokens'] ?? 0 );
            $summary['cost'] += self::estimate_cost(
                sanitize_text_field( $row['ai_model'] ?? '' ),
                absint( $row['ai_input_tokens'] ?? 0 ),
                absint( $row['ai_output_tokens'] ?? 0 )
            );
        }
        $summary['cost'] = round( $summary['cost'], 6 );
        return $summary;
    }

    public static function budget_status() {
        $budget = self::monthly_budget();
        $usage = self::usage_summary( 'month' );
        $spent = (float) $usage['cost'];
        $pct = $budget > 0 ? min( 999, ( $spent / $budget ) * 100 ) : 0;
        $settings = self::settings();
        return array(
            'budget' => $budget,
            'spent' => $spent,
            'remaining' => $budget > 0 ? max( 0, $budget - $spent ) : 0,
            'percent' => round( $pct, 1 ),
            'warning' => $budget > 0 && $pct >= (float) $settings['budget_warning_pct'],
            'blocked' => $budget > 0 && $spent >= $budget,
        );
    }

    public static function guard_monthly_budget() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $budget = self::budget_status();
        if ( ! empty( $budget['blocked'] ) ) {
            wp_send_json_error(
                array(
                    'message' => sprintf(
                        'Aylık AI maliyet sınırı doldu. Harcama: $%s / Limit: $%s.',
                        number_format( $budget['spent'], 4, '.', '' ),
                        number_format( $budget['budget'], 2, '.', '' )
                    ),
                ),
                429
            );
        }
    }

    public static function define_runtime_constants() {
        $settings = self::settings();

        if ( ! defined( 'SEKTOREL_OPENAI_API_KEY' ) ) {
            $value = self::secret( 'openai_api_key' );
            if ( $value ) {
                define( 'SEKTOREL_OPENAI_API_KEY', $value );
            }
        }
        if ( ! defined( 'SEKTOREL_OPENAI_MODEL' ) && ! empty( $settings['openai_model'] ) ) {
            define( 'SEKTOREL_OPENAI_MODEL', $settings['openai_model'] );
        }
        if ( ! defined( 'SEKTOREL_CONTENT_AI_DAILY_LIMIT' ) ) {
            define( 'SEKTOREL_CONTENT_AI_DAILY_LIMIT', self::effective_daily_limit() );
        }
        if ( ! defined( 'SEKTOREL_PEXELS_API_KEY' ) ) {
            $value = self::secret( 'pexels_api_key' );
            if ( $value ) {
                define( 'SEKTOREL_PEXELS_API_KEY', $value );
            }
        }
        if ( ! defined( 'SEKTOREL_UNSPLASH_ACCESS_KEY' ) ) {
            $value = self::secret( 'unsplash_access_key' );
            if ( $value ) {
                define( 'SEKTOREL_UNSPLASH_ACCESS_KEY', $value );
            }
        }
        if ( ! defined( 'SEKTOREL_UNSPLASH_SECRET_KEY' ) ) {
            $value = self::secret( 'unsplash_secret_key' );
            if ( $value ) {
                define( 'SEKTOREL_UNSPLASH_SECRET_KEY', $value );
            }
        }
    }

    private static function encrypt( $plain ) {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return new WP_Error( 'openssl_missing', 'OpenSSL bulunamadığı için API anahtarı güvenli biçimde kaydedilemedi.' );
        }
        try {
            $key = self::crypto_key();
            $iv = random_bytes( 16 );
            $cipher = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
            if ( false === $cipher ) {
                return new WP_Error( 'secret_encrypt_failed', 'API anahtarı şifrelenemedi.' );
            }
            $mac = hash_hmac( 'sha256', $iv . $cipher, $key, true );
            return base64_encode( wp_json_encode( array(
                'iv' => base64_encode( $iv ),
                'cipher' => base64_encode( $cipher ),
                'mac' => base64_encode( $mac ),
            ) ) );
        } catch ( Exception $e ) {
            return new WP_Error( 'secret_encrypt_failed', $e->getMessage() );
        }
    }

    private static function decrypt( $encoded ) {
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return new WP_Error( 'openssl_missing', 'OpenSSL bulunamadı.' );
        }
        $json = base64_decode( (string) $encoded, true );
        $data = $json ? json_decode( $json, true ) : null;
        if ( ! is_array( $data ) || empty( $data['iv'] ) || empty( $data['cipher'] ) || empty( $data['mac'] ) ) {
            return new WP_Error( 'secret_format_invalid', 'Secret formatı geçersiz.' );
        }
        $iv = base64_decode( $data['iv'], true );
        $cipher = base64_decode( $data['cipher'], true );
        $mac = base64_decode( $data['mac'], true );
        if ( false === $iv || false === $cipher || false === $mac ) {
            return new WP_Error( 'secret_format_invalid', 'Secret çözümlenemedi.' );
        }
        $key = self::crypto_key();
        $expected = hash_hmac( 'sha256', $iv . $cipher, $key, true );
        if ( ! hash_equals( $expected, $mac ) ) {
            return new WP_Error( 'secret_tampered', 'Secret bütünlük kontrolünden geçemedi.' );
        }
        $plain = openssl_decrypt( $cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
        return false === $plain ? new WP_Error( 'secret_decrypt_failed', 'Secret çözümlenemedi.' ) : $plain;
    }

    private static function crypto_key() {
        return hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ) . '|sektorel-core', true );
    }
}
