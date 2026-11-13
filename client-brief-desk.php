<?php
/**
 * Plugin Name: Client Brief Desk
 * Description: Collect client answers, request revisions and approve an exact version in a private WordPress workspace.
 * Version: 1.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * Author: Rohan Salave
 * Author URI: https://www.linkedin.com/in/rohan-salave-362475189/
 * License: GPL-2.0-or-later
 * Text Domain: client-brief-desk
 * Domain Path: /languages
 */
defined( 'ABSPATH' ) || exit;
define( 'CBD_VERSION', '1.0.0' );
define( 'CBD_FILE', __FILE__ );
require_once __DIR__ . '/includes/class-store.php';
require_once __DIR__ . '/includes/class-api.php';
require_once __DIR__ . '/includes/class-ui.php';
require_once __DIR__ . '/includes/class-privacy.php';
\ClientBriefDesk\Privacy::register();
register_activation_hook( __FILE__, array( 'ClientBriefDesk\\Store', 'install' ) );
add_action( 'init', function () {
    load_plugin_textdomain( 'client-brief-desk', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    if ( get_option( 'cbd_version' ) !== CBD_VERSION ) { \ClientBriefDesk\Store::install(); }
    \ClientBriefDesk\UI::register();
} );
add_action( 'rest_api_init', array( 'ClientBriefDesk\\API', 'register' ) );
