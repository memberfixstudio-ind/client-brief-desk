<?php
namespace ClientBriefDesk;
defined( 'ABSPATH' ) || exit;

class Privacy {
    public static function register() {
        add_filter( 'wp_privacy_personal_data_exporters', function ( $exporters ) {
            $exporters['client-brief-desk'] = array( 'exporter_friendly_name' => 'Client Brief Desk', 'callback' => array( __CLASS__, 'export' ) ); return $exporters;
        } );
        add_filter( 'wp_privacy_personal_data_erasers', function ( $erasers ) {
            $erasers['client-brief-desk'] = array( 'eraser_friendly_name' => 'Client Brief Desk', 'callback' => array( __CLASS__, 'erase' ) ); return $erasers;
        } );
    }
    private static function find_client( $email ) {
        global $wpdb;
        $wpdb->last_error = '';
        $user = get_user_by( 'email', $email );
        if ( $wpdb->last_error ) { return Store::error( __( 'Client Brief Desk could not check client records. Check the database and retry.', 'client-brief-desk' ), 500 ); }
        if ( $user ) { return $user; }
        $orphan = $wpdb->get_var( "SELECT b.id FROM {$wpdb->prefix}cbd_briefs b LEFT JOIN {$wpdb->users} u ON u.ID=b.client_id WHERE u.ID IS NULL LIMIT 1" );
        if ( $wpdb->last_error ) { return Store::error( __( 'Client Brief Desk could not check orphaned briefs. Check the database and retry.', 'client-brief-desk' ), 500 ); }
        if ( $orphan !== null ) {
            return Store::error( __( 'Orphaned client briefs exist, but Client Brief Desk cannot associate a deleted account email with those records. An administrator must manually review and delete orphaned briefs as appropriate before retrying.', 'client-brief-desk' ), 409 );
        }
        return false;
    }
    public static function export( $email, $page = 1 ) {
        global $wpdb;
        $data = array();
        if ( ! Store::admin() ) { return array( 'data' => $data, 'done' => true ); }
        $user = self::find_client( $email );
        if ( is_wp_error( $user ) ) { return $user; }
        if ( ! $user ) { return array( 'data' => $data, 'done' => true ); }
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,title,due_date,status,questions,answers,client_note,created_at FROM {$wpdb->prefix}cbd_briefs WHERE client_id=%d ORDER BY id LIMIT 20 OFFSET %d", $user->ID, ( max( 1, (int) $page ) - 1 ) * 20 ), ARRAY_A );
        if ( $wpdb->last_error ) { return Store::error( __( 'Client Brief Desk records could not be exported. Check the database and retry.', 'client-brief-desk' ), 500 ); }
        foreach ( $rows as $row ) {
            $data[] = array( 'group_id' => 'client-brief-desk', 'group_label' => __( 'Client briefs', 'client-brief-desk' ), 'item_id' => 'cbd-' . $row['id'], 'data' => array_map( function ( $key, $value ) { return array( 'name' => $key, 'value' => (string) $value ); }, array_keys( $row ), array_values( $row ) ) );
        }
        $events = $wpdb->get_results( $wpdb->prepare( "SELECT e.id,e.brief_id,e.action,e.version,e.note,e.snapshot,e.created_at FROM {$wpdb->prefix}cbd_events e INNER JOIN {$wpdb->prefix}cbd_briefs b ON b.id=e.brief_id WHERE b.client_id=%d AND e.action <> 'internal_note' ORDER BY e.id LIMIT 20 OFFSET %d", $user->ID, ( max( 1, (int) $page ) - 1 ) * 20 ), ARRAY_A );
        if ( $wpdb->last_error ) { return Store::error( __( 'Client brief history could not be exported. Check the database and retry.', 'client-brief-desk' ), 500 ); }
        foreach ( $events as $event ) {
            $data[] = array( 'group_id' => 'client-brief-desk-history', 'group_label' => __( 'Client brief history', 'client-brief-desk' ), 'item_id' => 'cbd-event-' . $event['id'], 'data' => array_map( function ( $key, $value ) { return array( 'name' => $key, 'value' => (string) $value ); }, array_keys( $event ), array_values( $event ) ) );
        }
        return array( 'data' => $data, 'done' => count( $rows ) < 20 && count( $events ) < 20 );
    }
    public static function erase( $email, $page = 1 ) {
        global $wpdb;
        $result = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
        if ( ! Store::admin() ) { return $result; }
        $user = self::find_client( $email );
        if ( is_wp_error( $user ) ) { $result['items_retained'] = true; $result['messages'][] = $user->get_error_message(); return $result; }
        if ( ! $user ) { return $result; }
        // Always take the remaining first batch because deletion shifts pagination.
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cbd_briefs WHERE client_id=%d ORDER BY id LIMIT 20", $user->ID ) );
        if ( $wpdb->last_error ) { $result['items_retained'] = true; $result['messages'][] = __( 'Client Brief Desk could not read the records. Retry after checking the database.', 'client-brief-desk' ); return $result; }
        foreach ( $ids as $id ) {
            $deleted = Store::erase_private( (int) $id, (int) $user->ID );
            if ( is_wp_error( $deleted ) ) { $result['items_retained'] = true; $result['messages'][] = $deleted->get_error_message(); return $result; }
            $result['items_removed'] = true;
        }
        $result['done'] = count( $ids ) < 20;
        return $result;
    }
}
