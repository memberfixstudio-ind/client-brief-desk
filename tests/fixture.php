<?php
if ( ! defined( 'MFS_TESTS' ) || ! MFS_TESTS ) { throw new RuntimeException( 'Disposable site only.' ); }
use ClientBriefDesk\Store;
wp_set_current_user( 1 );
$login = 'brief-client-' . strtolower( wp_generate_password( 5, false ) );
$password = 'local-fixture-only';
$client = wp_insert_user( array( 'user_login' => $login, 'user_pass' => $password, 'user_email' => $login . '@example.test', 'display_name' => 'Avery · Cedar & Co.', 'role' => 'subscriber' ) );
$presets = ClientBriefDesk\API::presets()->get_data();
$names = array( 'Cedar · Website refresh', 'Cedar · Autumn workshop', 'Cedar · Photography brief', 'Cedar · Welcome pack' );
$ids = array();
foreach ( $names as $index => $title ) {
    wp_set_current_user( 1 );
    $brief = Store::create( array( 'title' => $title, 'client_id' => $client, 'due_date' => gmdate( 'Y-m-d', time() + ( $index + 2 ) * DAY_IN_SECONDS ), 'questions' => $presets[ $index === 1 ? 'event' : 'service' ] ) );
    $ids[] = $brief['id'];
    if ( $index === 0 || $index === 3 ) {
        wp_set_current_user( $client );
        $answers = array( 'q1' => 'A warm, easy-to-use website for our independent plant shop. Customers should be able to find workshops and plan a visit.', 'q2' => 'Visitors understand what we offer and can find the right workshop without calling the shop.', 'q3' => 'Launch before the winter workshop programme. Keep the copy friendly and the mobile pages clear.', 'q4' => 'Our public reference pages and approved copy will follow after the first review.' );
        $brief = Store::mutate( $brief['id'], $brief['version'], 'save', array( 'answers' => $answers ) );
        $brief = Store::mutate( $brief['id'], $brief['version'], 'submit' );
        wp_set_current_user( 1 );
        if ( $index === 3 ) { Store::mutate( $brief['id'], $brief['version'], 'approve', array( 'note' => 'Clear and ready for the next stage.' ) ); }
    }
}
wp_set_current_user( 1 );
$page = wp_insert_post( array( 'post_title' => 'Your project briefs', 'post_content' => '[client_brief_desk]', 'post_status' => 'publish', 'post_type' => 'page' ) );
echo wp_json_encode( array( 'client_id' => $client, 'client_login' => $login, 'client_password' => $password, 'brief_ids' => $ids, 'portal' => get_permalink( $page ) ) );
