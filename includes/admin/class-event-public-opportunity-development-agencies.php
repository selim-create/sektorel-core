<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-event-public-opportunity-development-agencies-v2.php';
require_once __DIR__ . '/class-event-public-opportunity-tkdk.php';
require_once __DIR__ . '/class-event-public-opportunity-tkdk-bridge.php';

/**
 * Backward-compatible provider name used by the existing public-opportunity
 * extended stage. Implementation lives in the card-scoped V2 parser.
 */
class Sektorel_Event_Public_Opportunity_Development_Agencies extends Sektorel_Event_Public_Opportunity_Development_Agencies_V2 {}

Sektorel_Event_Public_Opportunity_TKDK_Bridge::init();
