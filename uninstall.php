<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
if ( ! defined( 'CBD_DELETE_DATA' ) || ! CBD_DELETE_DATA ) { return; }
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cbd_events" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cbd_briefs" );
delete_option( 'cbd_version' );
