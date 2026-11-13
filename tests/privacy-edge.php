<?php
/** Run only in a disposable database: wp eval-file tests/privacy-edge.php */
if ( ! defined( 'MFS_TESTS' ) || ! MFS_TESTS || is_multisite() ) { throw new RuntimeException( 'Use a disposable single-site installation with MFS_TESTS=true.' ); }
use ClientBriefDesk\Store;
use ClientBriefDesk\Privacy;
global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
add_filter( 'pre_http_request', function () { return new WP_Error( 'cbd_privacy_edge_offline', 'External HTTP disabled during privacy tests.' ); }, PHP_INT_MAX );
$checks = 0;
$check = function ( $truth, $label ) use ( &$checks ) {
    if ( ! $truth ) { throw new RuntimeException( 'FAIL: ' . $label ); }
    ++$checks; echo 'PASS: ' . $label . "\n";
};
$fault = function ( $pattern, $callback ) use ( $wpdb, $check ) {
    $hits = 0;
    $filter = function ( $sql ) use ( $pattern, &$hits ) {
        if ( preg_match( $pattern, $sql ) ) { ++$hits; return 'SELECT * FROM cbd_privacy_edge_deliberately_absent_table'; }
        return $sql;
    };
    $suppressed = $wpdb->suppress_errors( true ); add_filter( 'query', $filter, PHP_INT_MAX );
    try { $result = $callback(); }
    finally { remove_filter( 'query', $filter, PHP_INT_MAX ); $wpdb->suppress_errors( $suppressed ); }
    $check( $hits === 1, 'Database fault reached the intended read once' );
    return $result;
};
$original_user = get_current_user_id();
$users = array(); $briefs = array();
wp_set_current_user( 1 );
$check( Store::admin(), 'Disposable administrator available' );
$orphan_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbd_briefs b LEFT JOIN {$wpdb->users} u ON u.ID=b.client_id WHERE u.ID IS NULL" );
$check( ! $wpdb->last_error && (int) $orphan_count === 0, 'Fixture starts without orphaned briefs' );
$unknown_email = 'privacy_missing_' . wp_generate_password( 12, false ) . '@example.test';
try {
    $export = Privacy::export( $unknown_email );
    $check( ! is_wp_error( $export ) && $export['done'] && $export['data'] === array(), 'Unknown email exports normally when no orphan exists' );
    $erase = Privacy::erase( $unknown_email );
    $check( $erase['done'] && ! $erase['items_removed'] && ! $erase['items_retained'] && ! $erase['messages'], 'Unknown email erases normally when no orphan exists' );
    foreach ( array( 'deleted', 'known' ) as $kind ) {
        $login = 'privacy_edge_' . wp_generate_password( 12, false );
        $user = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
        $check( is_int( $user ), 'Created ' . $kind . ' test account' );
        $users[ $kind ] = $user;
        $brief = Store::create( array( 'title' => 'Privacy edge ' . $kind, 'client_id' => $user, 'questions' => array( array( 'label' => 'Requirements', 'help' => '', 'required' => true ) ) ) );
        $check( ! is_wp_error( $brief ), 'Created ' . $kind . ' test brief' );
        $briefs[ $kind ] = $brief['id'];
    }
    $email = get_userdata( $users['deleted'] )->user_email;
    wp_set_current_user( $users['deleted'] );
    $brief = Store::mutate( $briefs['deleted'], 1, 'save', array( 'answers' => array( 'q1' => 'Retained private answer' ) ) );
    $check( ! is_wp_error( $brief ), 'Saved real client answer' );
    $brief = Store::mutate( $briefs['deleted'], $brief['version'], 'submit' );
    $check( ! is_wp_error( $brief ) && $brief['events'][0]['snapshot']['answers']['q1'] === 'Retained private answer', 'Created real submitted snapshot' );
    wp_set_current_user( 1 );
    $before = Store::row( $briefs['deleted'] );
    $events_before = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbd_events WHERE brief_id=%d", $briefs['deleted'] ) );
    $check( wp_delete_user( $users['deleted'] ) && ! get_user_by( 'email', $email ), 'Deleted real WordPress account' );
    $check( Store::row( $briefs['deleted'] ) === $before, 'Account deletion retains brief for manual review' );
    $erase = Privacy::erase( $email );
    $check( $erase['done'] && $erase['items_retained'] && ! $erase['items_removed'] && strpos( implode( ' ', $erase['messages'] ), 'cannot associate' ) !== false && strpos( implode( ' ', $erase['messages'] ), 'manually review' ) !== false, 'Deleted-account erasure explicitly reports retained records and manual review' );
    $export = Privacy::export( $email );
    $check( is_wp_error( $export ) && $export->get_error_data()['status'] === 409 && strpos( $export->get_error_message(), 'manually review' ) !== false, 'Deleted-account export requires manual review' );
    $check( Privacy::erase( $unknown_email )['items_retained'] && is_wp_error( Privacy::export( $unknown_email ) ), 'Unrelated unknown email cannot be falsely matched to orphaned records' );
    foreach ( array( '/LEFT JOIN .*users.*u\.ID IS NULL/i', '/SELECT \* FROM .*users WHERE user_email/i' ) as $pattern ) {
        $failed = $fault( $pattern, function () use ( $unknown_email ) { return Privacy::export( $unknown_email ); } );
        $check( is_wp_error( $failed ) && $failed->get_error_data()['status'] === 500, 'Export fails closed on database read failure' );
        $failed = $fault( $pattern, function () use ( $unknown_email ) { return Privacy::erase( $unknown_email ); } );
        $check( $failed['items_retained'] && ! $failed['items_removed'] && ! empty( $failed['messages'] ), 'Erasure fails closed on database read failure' );
    }
    wp_set_current_user( $users['known'] );
    $check( Privacy::export( $email ) === array( 'data' => array(), 'done' => true ) && ! Privacy::erase( $email )['messages'], 'Non-administrator cannot inspect orphan state through privacy callbacks' );
    wp_set_current_user( 1 );
    $known_email = get_userdata( $users['known'] )->user_email;
    $export = Privacy::export( $known_email );
    $check( ! is_wp_error( $export ) && count( $export['data'] ) === 2 && strpos( wp_json_encode( $export ), 'Retained private answer' ) === false, 'Known-user export remains scoped while orphan exists' );
    $erase = Privacy::erase( $known_email );
    $check( $erase['items_removed'] && ! $erase['items_retained'] && ! Store::row( $briefs['known'] ), 'Known-user erasure still removes only assigned briefs' );
    $events_after = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbd_events WHERE brief_id=%d", $briefs['deleted'] ) );
    $check( Store::row( $briefs['deleted'] ) === $before && $events_after === $events_before, 'Privacy checks preserve orphaned answers and snapshots' );
    $check( true === Store::erase_private( $briefs['deleted'], $users['deleted'] ), 'Explicit administrator deletion can resolve orphan' );
    $check( ! Privacy::erase( $email )['items_retained'] && ! is_wp_error( Privacy::export( $email ) ), 'Unknown-email callbacks return normally after orphan resolution' );
} finally {
    wp_set_current_user( 1 );
    foreach ( $briefs as $kind => $id ) {
        if ( Store::row( $id ) ) {
            $deleted = Store::erase_private( $id, $users[ $kind ] );
            if ( is_wp_error( $deleted ) ) { throw new RuntimeException( 'Fixture cleanup failed: ' . $deleted->get_error_message() ); }
        }
    }
    foreach ( $users as $id ) { if ( get_userdata( $id ) ) { wp_delete_user( $id ); } }
    wp_set_current_user( $original_user );
}
echo 'CLIENT_BRIEF_DESK_PRIVACY_EDGE_RESULT: ' . $checks . ' passed; WordPress ' . get_bloginfo( 'version' ) . '; PHP ' . PHP_VERSION . "\n";
