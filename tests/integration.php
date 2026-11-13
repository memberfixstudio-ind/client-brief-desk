<?php
/** Run only in a disposable WordPress database: wp eval-file tests/integration.php */
if ( ! defined( 'MFS_TESTS' ) || ! MFS_TESTS ) { throw new RuntimeException( 'Define MFS_TESTS=true on a disposable site.' ); }
use ClientBriefDesk\Store;
use ClientBriefDesk\Privacy;
global $wpdb, $passed;
$passed = 0;
function cbd_check( $truth, $label ) { global $passed; if ( ! $truth ) { throw new RuntimeException( 'FAIL: ' . $label ); } ++$passed; echo 'PASS: ' . $label . "\n"; }
function cbd_error_status( $value, $status ) { return is_wp_error( $value ) && ( $value->get_error_data()['status'] ?? 0 ) === $status; }
function cbd_request( $method, $path, $data = null ) {
    $r = new WP_REST_Request( $method, '/client-brief-desk/v1' . $path );
    if ( $data !== null ) { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $data ) ); }
    return rest_do_request( $r );
}
function cbd_fault( $pattern, $fn ) {
    global $wpdb;
    $hits = 0; $filter = function ( $sql ) use ( $pattern, &$hits ) { if ( preg_match( $pattern, $sql ) ) { ++$hits; return 'SELECT * FROM cbd_deliberately_absent_table'; } return $sql; };
    $suppressed = $wpdb->suppress_errors( true ); add_filter( 'query', $filter, PHP_INT_MAX );
    try { $result = $fn(); } finally { remove_filter( 'query', $filter, PHP_INT_MAX ); $wpdb->suppress_errors( $suppressed ); }
    cbd_check( $hits === 1, 'Fault injection reached intended SQL statement once' ); return $result;
}
wp_set_current_user( 1 ); Store::install();
$client = wp_insert_user( array( 'user_login' => 'cbd_' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password(), 'user_email' => wp_generate_password( 10, false ) . '@example.test', 'role' => 'subscriber', 'display_name' => 'Avery & Stone' ) );
$other = wp_insert_user( array( 'user_login' => 'cbd_' . wp_generate_password( 10, false ), 'user_pass' => wp_generate_password(), 'user_email' => wp_generate_password( 10, false ) . '@example.test', 'role' => 'subscriber', 'display_name' => 'Morgan Reed' ) );
cbd_check( is_int( $client ) && is_int( $other ), 'Disposable client accounts created' );
$data = array( 'title' => 'Workshop launch brief', 'client_id' => $client, 'due_date' => '2027-01-15', 'questions' => array( array( 'label' => 'What do you need?', 'help' => 'A clear outcome', 'required' => true ), array( 'label' => 'Anything else?', 'help' => '', 'required' => false ) ) );
$brief = Store::create( $data ); cbd_check( ! is_wp_error( $brief ) && $brief['status'] === 'open' && $brief['version'] === 1, 'Administrator creates open version-one brief' ); $id = $brief['id'];
cbd_check( $brief['client_name'] === 'Avery & Stone', 'Display names decode stored entities for text-only rendering' );
cbd_check( $brief['questions'][0]['id'] === 'q1' && count( $brief['events'] ) === 1, 'Stable question IDs and creation audit' );
$bad = $data; $bad['due_date'] = '2026-02-30'; cbd_check( cbd_error_status( Store::create( $bad ), 400 ), 'Invalid calendar date refused' );
$bad = $data; $bad['client_id'] = 0; cbd_check( cbd_error_status( Store::create( $bad ), 400 ), 'Unassigned brief refused' );
$bad = $data; $bad['questions'] = array_fill( 0, 21, $data['questions'][0] ); cbd_check( cbd_error_status( Store::create( $bad ), 400 ), 'Question count bounded' );
$bad = $data; $bad['questions'][0]['required'] = 'true'; cbd_check( cbd_error_status( Store::create( $bad ), 400 ), 'Question booleans validated without coercion' );
cbd_check( cbd_error_status( Store::mutate( $id, 1, 'save', array( 'answers' => array( 'q1' => 'staff impersonation' ) ) ), 403 ), 'Staff cannot impersonate client answers' );
$brief = Store::mutate( $id, 1, 'internal_note', array( 'note' => 'STAFF_PRIVATE_SENTINEL' ) ); cbd_check( $brief['version'] === 2, 'Internal note increments version' );
wp_set_current_user( $other );
cbd_check( cbd_error_status( Store::get( $id ), 404 ), 'Another client cannot read the brief' );
cbd_check( Store::listing()['total'] === 0, 'Another client cannot list private briefs' );
cbd_check( cbd_error_status( Store::mutate( $id, 2, 'save', array( 'answers' => array( 'q1' => 'attack' ) ) ), 404 ), 'Another client cannot modify the brief' );
cbd_check( cbd_request( 'GET', '/briefs/' . $id . '/export' )->get_status() === 404, 'Another client cannot export answers' );
cbd_check( cbd_request( 'POST', '/briefs', $data )->get_status() === 403, 'Clients cannot create briefs' );
cbd_check( cbd_request( 'GET', '/clients' )->get_status() === 403, 'Client cannot enumerate accounts' );
wp_set_current_user( $client );
$public = Store::get( $id ); cbd_check( ! isset( $public['internal_note'] ) && strpos( wp_json_encode( $public ), 'STAFF_PRIVATE_SENTINEL' ) === false, 'Staff note absent from client details and history' );
cbd_check( strpos( wp_json_encode( cbd_request( 'GET', '/briefs/' . $id . '/export' )->get_data() ), 'STAFF_PRIVATE_SENTINEL' ) === false, 'Staff note absent from client export' );
cbd_check( Store::listing()['total'] === 1 && ! isset( Store::listing()['items'][0]['client_name'] ), 'Client list scoped and minimized' );
cbd_check( cbd_error_status( Store::mutate( $id, 2, 'approve' ), 403 ), 'Client cannot approve their own submission' );
cbd_check( cbd_error_status( Store::mutate( $id, 2, 'submit' ), 400 ), 'Required answer enforced on submission' );
cbd_check( cbd_error_status( Store::mutate( $id, 2, 'save', array( 'answers' => array( 'unknown' => 'x' ) ) ), 400 ), 'Unknown question cannot be injected' );
cbd_check( cbd_error_status( Store::mutate( $id, 2, 'save', array( 'answers' => array( 'q1' => array( 'x' ) ) ) ), 400 ), 'Nested answer rejected' );
cbd_check( cbd_error_status( Store::mutate( $id, 2, 'save', array( 'answers' => array( 'q1' => str_repeat( 'x', 10001 ) ) ) ), 400 ), 'Answer length bounded' );
$brief = Store::mutate( $id, 2, 'save', array( 'answers' => array( 'q1' => 'Build a booking information page.', 'q2' => '<script>alert(1)</script>Plain text' ) ) );
cbd_check( ! is_wp_error( $brief ) && $brief['version'] === 3, 'Client saves valid answers' );
cbd_check( strpos( $brief['answers']['q2'], '<script>' ) === false, 'Stored answer is sanitized plain text' );
cbd_check( cbd_error_status( Store::mutate( $id, 2, 'save', array( 'answers' => array( 'q1' => 'stale overwrite' ) ) ), 409 ), 'Stale client save refused' );
cbd_check( Store::get( $id )['answers']['q1'] === 'Build a booking information page.', 'Stale write preserves latest answer' );
$brief = Store::mutate( $id, 3, 'submit' ); cbd_check( $brief['status'] === 'submitted' && $brief['version'] === 4, 'Client submits exact saved version' );
cbd_check( $brief['events'][0]['snapshot']['answers']['q1'] === $brief['answers']['q1'], 'Submission captures immutable answer snapshot' );
cbd_check( cbd_error_status( Store::mutate( $id, 4, 'save', array( 'answers' => array() ) ), 409 ), 'Submitted brief locked against client edits' );
wp_set_current_user( 1 );
cbd_check( cbd_error_status( Store::mutate( $id, 3, 'approve' ), 409 ), 'Stale reviewer cannot approve newer submission' );
cbd_check( cbd_error_status( Store::mutate( $id, 4, 'request_changes' ), 400 ), 'Revision request requires clear feedback' );
$brief = Store::mutate( $id, 4, 'request_changes', array( 'note' => 'Please include the opening hours.' ) ); cbd_check( $brief['status'] === 'changes_requested', 'Staff requests revision' );
wp_set_current_user( $client );
cbd_check( Store::get( $id )['client_note'] === 'Please include the opening hours.', 'Review feedback visible to client' );
$brief = Store::mutate( $id, 5, 'save', array( 'answers' => array( 'q1' => 'Booking page. Open 9–5 weekdays.' ) ) );
$brief = Store::mutate( $id, $brief['version'], 'submit' );
wp_set_current_user( 1 );
$before = Store::row( $id );
$failed = cbd_fault( '/^INSERT INTO .*cbd_events/i', function () use ( $id, $brief ) { return Store::mutate( $id, $brief['version'], 'approve' ); } );
cbd_check( cbd_error_status( $failed, 500 ) && Store::row( $id ) === $before, 'Audit insertion failure rolls back approval and version' );
$failed = cbd_fault( '/^COMMIT$/', function () use ( $id, $brief ) { return Store::mutate( $id, $brief['version'], 'approve' ); } );
cbd_check( cbd_error_status( $failed, 500 ) && Store::row( $id ) === $before, 'Commit failure rolls back approval and version' );
$brief = Store::mutate( $id, $brief['version'], 'approve', array( 'note' => 'Ready for delivery.' ) );
cbd_check( $brief['status'] === 'approved', 'Staff approves submitted version' );
cbd_check( $brief['events'][0]['snapshot']['answers']['q1'] === 'Booking page. Open 9–5 weekdays.', 'Approval snapshot matches revised answers' );
$submission_events = array_values( array_filter( $brief['events'], function ( $e ) { return $e['action'] === 'submit'; } ) );
cbd_check( count( $submission_events ) === 2 && $submission_events[1]['snapshot']['answers']['q1'] === 'Build a booking information page.', 'Earlier submitted snapshot remains intact after revision' );
wp_set_current_user( $client ); cbd_check( cbd_error_status( Store::mutate( $id, $brief['version'], 'save', array( 'answers' => array() ) ), 409 ), 'Approved answers remain locked' );
wp_set_current_user( 1 );
$history_export = Privacy::export( get_userdata( $client )->user_email );
cbd_check( strpos( wp_json_encode( $history_export ), 'Build a booking information page.' ) !== false && strpos( wp_json_encode( $history_export ), 'STAFF_PRIVATE_SENTINEL' ) === false, 'Privacy export includes historical submissions and excludes internal notes' );
$duplicate = Store::mutate( $id, $brief['version'], 'duplicate' ); cbd_check( $duplicate['id'] !== $id && $duplicate['answers'] === array() && $duplicate['internal_note'] === '' && count( $duplicate['events'] ) === 1, 'Duplicate starts a clean brief with no copied private notes/history' );
$brief = Store::mutate( $id, $brief['version'], 'reopen', array( 'note' => 'Client requested a changed timeline.' ) ); cbd_check( $brief['status'] === 'changes_requested', 'Explicit reopen invalidates current approved status' );
$brief = Store::mutate( $id, $brief['version'], 'archive' ); cbd_check( $brief['status'] === 'archived', 'Staff can archive completed work' );
cbd_check( cbd_error_status( Store::mutate( $duplicate['id'], 1, 'delete' ), 409 ), 'Deletion requires archived state' );
cbd_check( cbd_error_status( Store::mutate( $id, $brief['version'] - 1, 'delete' ), 409 ), 'Stale deletion refused' );
$deleted = Store::mutate( $id, $brief['version'], 'delete' ); cbd_check( $deleted['deleted'] === true && ! Store::row( $id ), 'Archived brief deletion succeeds' );
cbd_check( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbd_events WHERE brief_id=%d", $id ) ) === 0, 'Deletion removes associated snapshots/history' );
$privacy = Privacy::export( get_userdata( $client )->user_email ); cbd_check( count( $privacy['data'] ) === 2 && strpos( wp_json_encode( $privacy ), 'STAFF_PRIVATE_SENTINEL' ) === false, 'WordPress privacy export includes client brief without staff notes' );
$erased = Privacy::erase( get_userdata( $client )->user_email ); cbd_check( $erased['items_removed'] && ! $erased['items_retained'] && ! Store::row( $duplicate['id'] ), 'WordPress privacy erasure removes client briefs and snapshots' );
$test_prefix = 'Page test ' . wp_generate_password( 8, false );
for ( $i = 0; $i < 23; ++$i ) { $data['title'] = $test_prefix . ' ' . $i; Store::create( $data ); }
$p1 = Store::listing( 'all', $test_prefix, 1 ); $p2 = Store::listing( 'all', $test_prefix, 2 );
cbd_check( count( $p1['items'] ) === 20 && count( $p2['items'] ) === 3 && ! array_intersect( array_column( $p1['items'], 'id' ), array_column( $p2['items'], 'id' ) ), 'Bounded pagination has no duplicate rows' );
cbd_check( Store::listing( 'all', "%_\" OR 1=1 --" )['total'] === 0, 'Search metacharacters cannot widen results' );
wp_set_current_user( $other ); cbd_check( Store::listing()['counts']['open'] === 0, 'Counts do not disclose another client workload' );
wp_set_current_user( 0 );
foreach ( array( '/briefs', '/briefs/' . $p1['items'][0]['id'], '/briefs/' . $p1['items'][0]['id'] . '/export', '/clients', '/presets' ) as $path ) { cbd_check( cbd_request( 'GET', $path )->get_status() === 401, 'Anonymous access denied: ' . $path ); }
cbd_check( cbd_request( 'POST', '/briefs', $data )->get_status() === 401, 'Anonymous creation denied' );
wp_set_current_user( 1 );
$response = cbd_request( 'GET', '/briefs' ); cbd_check( strpos( $response->get_headers()['Cache-Control'], 'no-store' ) !== false, 'Private API responses cannot be stored in shared caches' );
wp_set_current_user( 1 );
$history_brief = Store::create( $data );
for ( $n = 0; $n < 51; $n++ ) { $history_brief = Store::mutate( $history_brief['id'], $history_brief['version'], 'internal_note', array( 'note' => 'History boundary ' . $n ) ); }
$history_export = cbd_request( 'GET', '/briefs/' . $history_brief['id'] . '/export' )->get_data();
cbd_check( count( $history_export['brief']['events'] ) === 50 && $history_export['brief']['history']['has_more'] === true, 'Bounded JSON export explicitly reports omitted older history' );
wp_set_current_user( $client );
$client_history = Store::get( $history_brief['id'] );
cbd_check( $client_history['history']['has_more'] === false && count( $client_history['events'] ) === 1, 'Client history metadata cannot expose hidden staff events' );
echo "CLIENT_BRIEF_DESK_RESULT: $passed passed; WordPress " . get_bloginfo( 'version' ) . '; PHP ' . PHP_VERSION . "\n";
