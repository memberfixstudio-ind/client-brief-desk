<?php
/** Disposable InnoDB test; run through concurrency.sh. Requires visibility of INNODB_TRX lock waits. */
if ( ! defined( 'MFS_TESTS' ) || ! MFS_TESTS || is_multisite() ) {
    throw new RuntimeException( 'Use a disposable single-site installation with MFS_TESTS=true.' );
}
use ClientBriefDesk\Store;
global $wpdb;
$dir = getenv( 'CBD_TEST_DIR' );
$mode = getenv( 'CBD_TEST_MODE' );
if ( ! $dir || ! is_dir( $dir ) || ! in_array( $mode, array( 'setup', 'first', 'second', 'verify', 'cleanup' ), true ) ) {
    throw new RuntimeException( 'Run tests/concurrency.sh with wp on PATH.' );
}
$write = function ( $name, $data ) use ( $dir ) {
    $json = wp_json_encode( $data );
    if ( false === $json || false === file_put_contents( $dir . '/' . $name . '.json', $json, LOCK_EX ) ) {
        throw new RuntimeException( 'Could not persist concurrency test state.' );
    }
};
$read = function ( $name ) use ( $dir ) {
    $path = $dir . '/' . $name . '.json';
    return is_file( $path ) ? json_decode( file_get_contents( $path ), true ) : null;
};
wp_set_current_user( 1 );
if ( ! current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Fixture setup/cleanup requires the disposable administrator (ID 1).' ); }
require_once ABSPATH . 'wp-admin/includes/user.php';

if ( 'setup' === $mode ) {
    $login = 'cbd_race_' . bin2hex( random_bytes( 8 ) );
    $fixture = array( 'login' => $login, 'title' => 'CBD concurrency ' . $login, 'version' => 1 );
    $write( 'fixture', $fixture );
    $client = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
    if ( is_wp_error( $client ) ) { throw new RuntimeException( $client->get_error_message() ); }
    $fixture['client_id'] = $client;
    $write( 'fixture', $fixture );
    $brief = Store::create( array( 'title' => $fixture['title'], 'client_id' => $client, 'questions' => array( array( 'label' => 'Concurrent answer?', 'help' => '', 'required' => true ) ) ) );
    if ( is_wp_error( $brief ) ) { throw new RuntimeException( $brief->get_error_message() ); }
    $fixture['brief_id'] = $brief['id'];
    $write( 'fixture', $fixture );
    echo "SETUP: isolated subscriber and version-one brief created\n";
    return;
}

$fixture = $read( 'fixture' );
if ( 'cleanup' === $mode ) {
    if ( ! $fixture ) { return; }
    $client = get_user_by( 'login', $fixture['login'] );
    if ( $client ) {
        if ( $client->user_email !== $fixture['login'] . '@example.test' ) { throw new RuntimeException( 'Fixture identity changed; cleanup refused.' ); }
        $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cbd_briefs WHERE client_id=%d AND title=%s LIMIT 1", $client->ID, $fixture['title'] ) );
        if ( $wpdb->last_error ) { throw new RuntimeException( 'Could not locate the fixture for cleanup.' ); }
        if ( $id && true !== Store::erase_private( $id, (int) $client->ID ) ) { throw new RuntimeException( 'Could not remove fixture brief/events.' ); }
        if ( ! wp_delete_user( $client->ID ) ) { throw new RuntimeException( 'Could not remove fixture subscriber.' ); }
        if ( $id && ( Store::row( $id ) || (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbd_events WHERE brief_id=%d", $id ) ) ) ) {
            throw new RuntimeException( 'Fixture brief/events remain after cleanup.' );
        }
    }
    echo "CLEANUP: fixture subscriber, brief and events removed\n";
    return;
}
if ( empty( $fixture['client_id'] ) || empty( $fixture['brief_id'] ) ) { throw new RuntimeException( 'Fixture setup did not complete.' ); }

if ( 'first' === $mode || 'second' === $mode ) {
    wp_set_current_user( $fixture['client_id'] );
    if ( current_user_can( 'manage_options' ) ) { throw new RuntimeException( 'Save workers must be the assigned non-admin client.' ); }
    $wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 10' );
    $connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
    $filter = null;
    if ( 'first' === $mode ) {
        $filter = function ( $sql ) use ( $wpdb, $write, $read, $fixture, $connection ) {
            if ( 'COMMIT' !== $sql ) { return $sql; }
            $write( 'locked', array( 'connection' => $connection ) );
            $deadline = microtime( true ) + 8;
            do {
                $second = $read( 'attempt' );
                if ( $second ) {
                    $waiting = $wpdb->get_var( $wpdb->prepare(
                        "SELECT trx_mysql_thread_id FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=%d AND trx_state='LOCK WAIT' AND trx_query=%s",
                        $second['connection'], $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cbd_briefs WHERE id=%d FOR UPDATE", $fixture['brief_id'] )
                    ) );
                    if ( $wpdb->last_error ) { throw new RuntimeException( 'Cannot observe INNODB_TRX; use a disposable DB account with PROCESS visibility.' ); }
                    if ( $waiting ) {
                        $write( 'observed', array( 'first' => $connection, 'second' => (int) $waiting ) );
                        return $sql;
                    }
                }
                usleep( 50000 );
            } while ( microtime( true ) < $deadline );
            throw new RuntimeException( 'Timed out waiting for the second connection to block on this brief.' );
        };
        add_filter( 'query', $filter, PHP_INT_MAX );
    } else {
        $write( 'attempt', array( 'connection' => $connection ) );
    }
    $request = new WP_REST_Request( 'PATCH', '/client-brief-desk/v1/briefs/' . $fixture['brief_id'] );
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_body( wp_json_encode( array( 'version' => $fixture['version'], 'answers' => array( 'q1' => $mode . ' worker answer' ) ) ) );
    try { $response = rest_do_request( $request ); }
    finally { if ( $filter ) { remove_filter( 'query', $filter, PHP_INT_MAX ); } }
    $write( $mode, array( 'pid' => getmypid(), 'connection' => $connection, 'expected_version' => $fixture['version'], 'status' => $response->get_status() ) );
    echo strtoupper( $mode ) . ': HTTP ' . $response->get_status() . "\n";
    if ( ( 'first' === $mode ? 200 : 409 ) !== $response->get_status() ) { throw new RuntimeException( 'Unexpected save result: ' . wp_json_encode( $response->get_data() ) ); }
    return;
}

$checks = 0;
$check = function ( $truth, $label ) use ( &$checks ) {
    if ( ! $truth ) { throw new RuntimeException( 'FAIL: ' . $label ); }
    ++$checks;
    echo 'PASS: ' . $label . "\n";
};
$first = $read( 'first' ); $second = $read( 'second' ); $observed = $read( 'observed' );
$check( $first && $second && $first['pid'] !== $second['pid'], 'Two independent PHP processes performed the saves' );
$check( $first['connection'] !== $second['connection'], 'Workers used separate database connections' );
$check( $observed && $observed['first'] === $first['connection'] && $observed['second'] === $second['connection'], 'Second connection was observed in LOCK WAIT on the same brief before first committed' );
$check( 1 === $first['expected_version'] && $first['expected_version'] === $second['expected_version'], 'Both saves used the same expected version' );
$check( 200 === $first['status'] && 409 === $second['status'], 'Exactly one client save succeeded and one returned HTTP 409' );
$row = Store::row( $fixture['brief_id'] );
$check( $row && 2 === (int) $row['version'] && array( 'q1' => 'first worker answer' ) === json_decode( $row['answers'], true ), 'Final version and answer belong to the winning save' );
$events = $wpdb->get_results( $wpdb->prepare( "SELECT action,actor_id,version FROM {$wpdb->prefix}cbd_events WHERE brief_id=%d ORDER BY id", $fixture['brief_id'] ), ARRAY_A );
$check( ! $wpdb->last_error && 2 === count( $events ) && array( 'created', 'save' ) === array_column( $events, 'action' ), 'History contains creation and exactly one save event' );
$check( (int) $events[1]['actor_id'] === $fixture['client_id'] && 2 === (int) $events[1]['version'], 'Save event records the winning client and version' );
echo "CLIENT_BRIEF_DESK_CONCURRENCY_RESULT: {$checks} passed; WordPress " . get_bloginfo( 'version' ) . '; PHP ' . PHP_VERSION . '; DB ' . $wpdb->get_var( 'SELECT VERSION()' ) . "\n";
