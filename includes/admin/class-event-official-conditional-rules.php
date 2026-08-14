<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Conditional official rules.
 *
 * These rules do not become calendar Events until an external/business trigger
 * date exists. They are exposed read-only so company/profile workflows can
 * materialize a deadline later without hard-coding legal timing in frontend.
 */
class Sektorel_Event_Official_Conditional_Rules {

    public static function init() {
        add_action( 'graphql_register_types', array( __CLASS__, 'register_graphql' ), 35 );
    }

    public static function rules() {
        return array(
            'kvkk_verbis_information_change' => array(
                'rule_key'       => 'kvkk_verbis_information_change',
                'title'          => 'VERBİS Kayıt Bilgisi Değişikliği Bildirimi',
                'institution'    => 'Kişisel Verileri Koruma Kurumu',
                'trigger_key'    => 'verbis_information_changed_at',
                'offset_value'   => 7,
                'offset_unit'    => 'days',
                'applicability'  => array( 'verbis_registered_data_controller' ),
                'source_url'     => 'https://www.kvkk.gov.tr/Icerik/5442/VERI-SORUMLULARI-SICILI-HAKKINDA-YONETMELIK',
                'description'    => 'VERBİS Sicilinde kayıtlı bilgilerde değişiklik olması halinde değişikliğin meydana geldiği tarihten itibaren yedi gün içerisinde Kuruma bildirim yapılır.',
            ),
            'kvkk_personal_data_breach' => array(
                'rule_key'       => 'kvkk_personal_data_breach',
                'title'          => 'KVKK Kişisel Veri İhlali Kurul Bildirimi',
                'institution'    => 'Kişisel Verileri Koruma Kurumu',
                'trigger_key'    => 'personal_data_breach_learned_at',
                'offset_value'   => 72,
                'offset_unit'    => 'hours',
                'applicability'  => array( 'data_controller' ),
                'source_url'     => 'https://www.kvkk.gov.tr/Icerik/5362/Veri-Ihlali-Bildirimi',
                'description'    => 'Veri sorumlusunun kişisel veri ihlalini öğrendiği tarihten itibaren gecikmeksizin ve en geç 72 saat içinde Kurula bildirim yapması gerekir.',
            ),
        );
    }

    public static function calculate_deadline( $rule_key, $trigger_at ) {
        $rules = self::rules();
        $rule_key = sanitize_key( $rule_key );
        if ( empty( $rules[ $rule_key ] ) ) {
            return new WP_Error( 'unknown_official_conditional_rule', 'Koşullu resmî kural bulunamadı.' );
        }

        try {
            $trigger = new DateTimeImmutable( (string) $trigger_at, wp_timezone() );
        } catch ( Exception $e ) {
            return new WP_Error( 'invalid_official_trigger_date', 'Tetikleyici tarih geçersiz.' );
        }

        $rule = $rules[ $rule_key ];
        $value = absint( $rule['offset_value'] );
        $unit = $rule['offset_unit'];
        if ( 'hours' === $unit ) {
            return $trigger->modify( '+' . $value . ' hours' )->format( DATE_ATOM );
        }
        if ( 'days' === $unit ) {
            return $trigger->modify( '+' . $value . ' days' )->format( DATE_ATOM );
        }

        return new WP_Error( 'unsupported_official_offset_unit', 'Koşullu resmî kural süre birimi desteklenmiyor.' );
    }

    public static function register_graphql() {
        if ( ! function_exists( 'register_graphql_object_type' ) || ! function_exists( 'register_graphql_field' ) ) {
            return;
        }

        register_graphql_object_type( 'OfficialConditionalRule', array(
            'fields' => array(
                'ruleKey'      => array( 'type' => 'String' ),
                'title'        => array( 'type' => 'String' ),
                'institution'  => array( 'type' => 'String' ),
                'triggerKey'   => array( 'type' => 'String' ),
                'offsetValue'  => array( 'type' => 'Int' ),
                'offsetUnit'   => array( 'type' => 'String' ),
                'applicability'=> array( 'type' => array( 'list_of' => 'String' ) ),
                'sourceUrl'    => array( 'type' => 'String' ),
                'description'  => array( 'type' => 'String' ),
            ),
        ) );

        register_graphql_field( 'RootQuery', 'officialConditionalRules', array(
            'type'    => array( 'list_of' => 'OfficialConditionalRule' ),
            'resolve' => static function () {
                $result = array();
                foreach ( Sektorel_Event_Official_Conditional_Rules::rules() as $rule ) {
                    $result[] = array(
                        'ruleKey'       => $rule['rule_key'],
                        'title'         => $rule['title'],
                        'institution'   => $rule['institution'],
                        'triggerKey'    => $rule['trigger_key'],
                        'offsetValue'   => absint( $rule['offset_value'] ),
                        'offsetUnit'    => $rule['offset_unit'],
                        'applicability' => $rule['applicability'],
                        'sourceUrl'     => $rule['source_url'],
                        'description'   => $rule['description'],
                    );
                }
                return $result;
            },
        ) );

        register_graphql_field( 'RootQuery', 'officialConditionalDeadline', array(
            'type' => 'String',
            'args' => array(
                'ruleKey'   => array( 'type' => array( 'non_null' => 'String' ) ),
                'triggerAt' => array( 'type' => array( 'non_null' => 'String' ) ),
            ),
            'resolve' => static function ( $root, $args ) {
                $result = Sektorel_Event_Official_Conditional_Rules::calculate_deadline( $args['ruleKey'], $args['triggerAt'] );
                return is_wp_error( $result ) ? null : $result;
            },
        ) );
    }
}
