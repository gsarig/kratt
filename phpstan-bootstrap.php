<?php

/**
 * PHPStan bootstrap stubs.
 * Defines constants that are normally set at runtime by WordPress,
 * so that static analysis can resolve them without a full WP environment.
 */

defined( 'KRATT_VERSION' ) || define( 'KRATT_VERSION', '0.1.0' );
defined( 'KRATT_DIR' )     || define( 'KRATT_DIR', __DIR__ . '/' );
defined( 'KRATT_URL' )     || define( 'KRATT_URL', '' );
defined( 'KRATT_FILE' )    || define( 'KRATT_FILE', __DIR__ . '/kratt.php' );
