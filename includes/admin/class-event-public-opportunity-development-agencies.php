<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-event-public-opportunity-development-agencies-v2.php';
require_once __DIR__ . '/class-event-public-opportunity-tkdk-v2.php';

/**
 * Backward-compatible provider name used by the existing public-opportunity
 * extended stage. Implementation lives in the card-scoped V2 parser.
 */
class Sektorel_Event_Public_Opportunity_Development_Agencies extends Sektorel_Event_Public_Opportunity_Development_Agencies_V2 {}

// Keep Source Center copy in sync with the providers owned directly by the
// Extended Stage. This is presentation-only; it does not alter dispatch.
add_filter(
    'sektorel_source_center_stages',
    static function ( $stages ) {
        foreach ( (array) $stages as $index => $stage ) {
            if ( ! is_array( $stage ) || empty( $stage['key'] ) || 'public_opportunities' !== $stage['key'] ) {
                continue;
            }
            $stages[ $index ]['description'] = 'KOSGEB, İŞKUR, TÜBİTAK, Kalkınma Ajansları, Ticaret Bakanlığı, Türk Eximbank ve TKDK/IPARD resmî kaynaklarını kaynağa özel deterministic/canlı adapterlarla tarar; doğrulanmış açık/yaklaşan fırsatları taslak Event olarak günceller.';
        }
        return $stages;
    },
    200
);
