<?php
namespace ClientBriefDesk;
defined( 'ABSPATH' ) || exit;

class Store {
    const STATUSES = array( 'open', 'submitted', 'changes_requested', 'approved', 'archived' );
    public static function error( $message, $status = 400 ) { return new \WP_Error( 'cbd_error', $message, array( 'status' => $status ) ); }
    public static function admin() { return current_user_can( 'manage_options' ); }
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collation = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$wpdb->prefix}cbd_briefs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(120) NOT NULL,
            client_id bigint(20) unsigned NOT NULL,
            created_by bigint(20) unsigned NOT NULL,
            due_date varchar(10) NOT NULL DEFAULT '',
            status varchar(24) NOT NULL DEFAULT 'open',
            version bigint(20) unsigned NOT NULL DEFAULT 1,
            questions longtext NOT NULL,
            answers longtext NOT NULL,
            client_note text NOT NULL,
            internal_note text NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY client_status (client_id,status),
            KEY updated_at (updated_at)
        ) ENGINE=InnoDB $collation;" );
        dbDelta( "CREATE TABLE {$wpdb->prefix}cbd_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            brief_id bigint(20) unsigned NOT NULL,
            actor_id bigint(20) unsigned NOT NULL,
            action varchar(32) NOT NULL,
            version bigint(20) unsigned NOT NULL,
            note text NOT NULL,
            snapshot longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY brief_id (brief_id)
        ) ENGINE=InnoDB $collation;" );
        if ( self::transactional() ) { update_option( 'cbd_version', CBD_VERSION, false ); }
    }
    private static function transactional() {
        global $wpdb;
        foreach ( array( 'cbd_briefs', 'cbd_events' ) as $suffix ) {
            $table = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $wpdb->prefix . $suffix ), ARRAY_A );
            if ( ! $table || $table['Engine'] !== 'InnoDB' ) { return false; }
        }
        return true;
    }
    public static function row( $id, $lock = false ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cbd_briefs WHERE id=%d" . ( $lock ? ' FOR UPDATE' : '' ), $id ), ARRAY_A );
    }
    private static function accessible( $row ) {
        return $row && get_current_user_id() && ( self::admin() || (int) $row['client_id'] === get_current_user_id() );
    }
    private static function display( $row ) {
        $row['id'] = (int) $row['id']; $row['version'] = (int) $row['version'];
        $row['client_id'] = (int) $row['client_id']; $row['created_by'] = (int) $row['created_by'];
        $row['questions'] = json_decode( $row['questions'], true ) ?: array();
        $row['answers'] = json_decode( $row['answers'], true ) ?: array();
        if ( self::admin() ) {
            $user = get_userdata( $row['client_id'] );
            $row['client_name'] = $user ? html_entity_decode( $user->display_name, ENT_QUOTES, 'UTF-8' ) : __( 'Deleted account', 'client-brief-desk' );
        } else { unset( $row['internal_note'], $row['created_by'] ); }
        return $row;
    }
    public static function get( $id ) {
        global $wpdb;
        $row = self::row( $id );
        if ( $wpdb->last_error ) { return self::error( __( 'The brief could not be read.', 'client-brief-desk' ), 500 ); }
        if ( ! self::accessible( $row ) ) { return self::error( __( 'Brief not found.', 'client-brief-desk' ), 404 ); }
        $brief = self::display( $row );
        $private = self::admin() ? '' : " AND action <> 'internal_note'";
        $events = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cbd_events WHERE brief_id=%d $private ORDER BY id DESC LIMIT 51", $id ), ARRAY_A );
        if ( $wpdb->last_error ) { return self::error( __( 'The brief history could not be read.', 'client-brief-desk' ), 500 ); }
        $brief['history'] = array( 'limit' => 50, 'returned' => min( 50, count( $events ) ), 'has_more' => count( $events ) > 50 );
        $brief['events'] = array_map( function ( $event ) {
            $actor = get_userdata( $event['actor_id'] );
            return array( 'id' => (int) $event['id'], 'action' => $event['action'], 'actor_name' => $actor ? html_entity_decode( $actor->display_name, ENT_QUOTES, 'UTF-8' ) : __( 'Deleted account', 'client-brief-desk' ), 'version' => (int) $event['version'], 'note' => $event['note'], 'created_at' => $event['created_at'], 'snapshot' => $event['snapshot'] ? json_decode( $event['snapshot'], true ) : null );
        }, array_slice( $events, 0, 50 ) );
        return $brief;
    }
    public static function listing( $status = 'all', $search = '', $page = 1 ) {
        global $wpdb;
        if ( ! get_current_user_id() ) { return self::error( __( 'Sign in to view briefs.', 'client-brief-desk' ), 401 ); }
        if ( ! in_array( $status, array_merge( array( 'all' ), self::STATUSES ), true ) || ! is_int( $page ) || $page < 1 || $page > 10000 || ! is_string( $search ) || mb_strlen( $search ) > 120 ) { return self::error( __( 'Invalid list filters.', 'client-brief-desk' ) ); }
        $where = self::admin() ? '1=1' : $wpdb->prepare( 'client_id=%d', get_current_user_id() );
        $counts = array_fill_keys( self::STATUSES, 0 );
        $groups = $wpdb->get_results( "SELECT status,COUNT(*) AS n FROM {$wpdb->prefix}cbd_briefs WHERE $where GROUP BY status", ARRAY_A );
        if ( $wpdb->last_error ) { return self::error( __( 'The brief list could not be read.', 'client-brief-desk' ), 500 ); }
        foreach ( $groups as $g ) { $counts[ $g['status'] ] = (int) $g['n']; }
        if ( $status !== 'all' ) { $where .= $wpdb->prepare( ' AND status=%s', $status ); }
        if ( $search !== '' ) { $where .= $wpdb->prepare( ' AND title LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' ); }
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbd_briefs WHERE $where" );
        if ( $wpdb->last_error ) { return self::error( __( 'The brief list could not be read.', 'client-brief-desk' ), 500 ); }
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cbd_briefs WHERE $where ORDER BY updated_at DESC,id DESC LIMIT 20 OFFSET %d", ( $page - 1 ) * 20 ), ARRAY_A );
        if ( $wpdb->last_error ) { return self::error( __( 'The brief list could not be read.', 'client-brief-desk' ), 500 ); }
        $items = array();
        foreach ( $rows as $row ) {
            $row = self::display( $row ); $answered = 0; $required = 0;
            foreach ( $row['questions'] as $q ) {
                $required += $q['required'] ? 1 : 0;
                if ( trim( $row['answers'][ $q['id'] ] ?? '' ) !== '' ) { ++$answered; }
            }
            $items[] = array_intersect_key( $row, array_flip( array( 'id', 'title', 'status', 'version', 'due_date', 'updated_at', 'client_name' ) ) ) + array( 'answered' => $answered, 'required' => $required, 'total_questions' => count( $row['questions'] ) );
        }
        return array( 'items' => $items, 'total' => $total, 'page' => $page, 'pages' => max( 1, (int) ceil( $total / 20 ) ), 'counts' => $counts, 'is_admin' => self::admin(), 'timezone' => wp_timezone_string() );
    }
    private static function text( $value, $max, $multiline = false ) {
        if ( ! is_string( $value ) || mb_strlen( $value ) > $max ) { return null; }
        return $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
    }
    public static function create( $data ) {
        global $wpdb;
        if ( ! self::admin() ) { return self::error( __( 'Only administrators can create briefs.', 'client-brief-desk' ), 403 ); }
        $title = self::text( $data['title'] ?? '', 120 ); $due = $data['due_date'] ?? '';
        $client = $data['client_id'] ?? 0;
        if ( ! $title || ! is_int( $client ) || $client < 1 || ! get_userdata( $client ) || ! is_user_member_of_blog( $client ) || ! is_string( $due ) || ( $due !== '' && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $due ) || ! checkdate( (int) substr( $due, 5, 2 ), (int) substr( $due, 8, 2 ), (int) substr( $due, 0, 4 ) ) ) ) ) { return self::error( __( 'Enter a title, an existing client account and a valid due date.', 'client-brief-desk' ) ); }
        $questions = $data['questions'] ?? null;
        if ( ! is_array( $questions ) || ! array_is_list( $questions ) || ! count( $questions ) || count( $questions ) > 20 ) { return self::error( __( 'Use between one and twenty questions.', 'client-brief-desk' ) ); }
        $clean = array();
        foreach ( $questions as $i => $q ) {
            if ( ! is_array( $q ) ) { return self::error( __( 'Invalid question.', 'client-brief-desk' ) ); }
            $label = self::text( $q['label'] ?? '', 200 ); $help = self::text( $q['help'] ?? '', 500, true );
            if ( ! $label || $help === null || ! isset( $q['required'] ) || ! is_bool( $q['required'] ) ) { return self::error( __( 'Every question needs a label and a required/optional choice.', 'client-brief-desk' ) ); }
            $clean[] = array( 'id' => 'q' . ( $i + 1 ), 'label' => $label, 'help' => $help, 'required' => $q['required'] );
        }
        $started = self::begin(); if ( is_wp_error( $started ) ) { return $started; }
        $now = gmdate( 'Y-m-d H:i:s' );
        try {
            $ok = $wpdb->insert( $wpdb->prefix . 'cbd_briefs', array( 'title' => $title, 'client_id' => $client, 'created_by' => get_current_user_id(), 'due_date' => $due, 'status' => 'open', 'version' => 1, 'questions' => wp_json_encode( $clean ), 'answers' => '{}', 'client_note' => '', 'internal_note' => '', 'created_at' => $now, 'updated_at' => $now ) );
            if ( ! $ok ) { throw new \RuntimeException(); }
            $id = (int) $wpdb->insert_id;
            if ( ! self::event( $id, 'created', 1, '' ) || false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException(); }
        } catch ( \Throwable $e ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'The brief could not be saved. No change was committed.', 'client-brief-desk' ), 500 ); }
        return self::get( $id );
    }
    private static function begin() {
        global $wpdb;
        if ( ! self::transactional() || false === $wpdb->query( 'START TRANSACTION' ) ) { return self::error( __( 'Brief updates require available InnoDB tables.', 'client-brief-desk' ), 503 ); }
        return true;
    }
    private static function event( $id, $action, $version, $note, $snapshot = null ) {
        global $wpdb;
        return false !== $wpdb->insert( $wpdb->prefix . 'cbd_events', array( 'brief_id' => $id, 'actor_id' => get_current_user_id(), 'action' => $action, 'version' => $version, 'note' => $note, 'snapshot' => $snapshot ? wp_json_encode( $snapshot ) : '', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
    }
    public static function mutate( $id, $version, $action, $payload = array() ) {
        global $wpdb;
        if ( ! is_int( $version ) || $version < 1 || ! is_string( $action ) || ! is_array( $payload ) ) { return self::error( __( 'A valid brief version is required.', 'client-brief-desk' ) ); }
        $started = self::begin(); if ( is_wp_error( $started ) ) { return $started; }
        try {
            $raw = self::row( $id, true );
            if ( $wpdb->last_error ) { throw new \RuntimeException(); }
            if ( ! self::accessible( $raw ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'Brief not found.', 'client-brief-desk' ), 404 ); }
            if ( (int) $raw['version'] !== $version ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'Someone changed this brief. Keep your answers and reload the latest version before trying again.', 'client-brief-desk' ), 409 ); }
            $brief = self::display( $raw ); $update = array(); $snapshot = null; $note = '';
            if ( in_array( $action, array( 'save', 'submit' ), true ) ) {
                if ( (int) $raw['client_id'] !== get_current_user_id() ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'Only the assigned client can answer or submit.', 'client-brief-desk' ), 403 ); }
                if ( ! in_array( $raw['status'], array( 'open', 'changes_requested' ), true ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'This brief is read-only until the administrator reopens it.', 'client-brief-desk' ), 409 ); }
                if ( $action === 'save' ) {
                    $answers = $payload['answers'] ?? null;
                    if ( ! is_array( $answers ) || count( $answers ) > 20 || strlen( wp_json_encode( $answers ) ) > 200000 ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'Invalid answers.', 'client-brief-desk' ) ); }
                    $ids = array_column( $brief['questions'], 'id' ); $clean = array(); $length = 0;
                    foreach ( $answers as $key => $answer ) {
                        $text = self::text( $answer, 10000, true );
                        if ( ! in_array( $key, $ids, true ) || $text === null ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'An answer is invalid or too long.', 'client-brief-desk' ) ); }
                        $clean[ $key ] = $text; $length += mb_strlen( $text );
                    }
                    if ( $length > 50000 ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'Use at most 50,000 characters per brief.', 'client-brief-desk' ) ); }
                    $update['answers'] = wp_json_encode( (object) $clean );
                } else {
                    foreach ( $brief['questions'] as $q ) {
                        if ( $q['required'] && trim( $brief['answers'][ $q['id'] ] ?? '' ) === '' ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'Complete all required questions before submitting.', 'client-brief-desk' ) ); }
                    }
                    $update['status'] = 'submitted';
                    $snapshot = array( 'questions' => $brief['questions'], 'answers' => (object) $brief['answers'], 'title' => $brief['title'] );
                }
            } else {
                if ( ! self::admin() ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'Only administrators can review briefs.', 'client-brief-desk' ), 403 ); }
                $note = self::text( $payload['note'] ?? '', 5000, true );
                if ( $note === null ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'The note is too long.', 'client-brief-desk' ) ); }
                if ( $action === 'approve' && $raw['status'] === 'submitted' ) {
                    $update['status'] = 'approved';
                    $snapshot = array( 'questions' => $brief['questions'], 'answers' => (object) $brief['answers'], 'title' => $brief['title'] );
                } elseif ( ( $action === 'request_changes' && $raw['status'] === 'submitted' ) || ( $action === 'reopen' && $raw['status'] === 'approved' ) ) {
                    if ( trim( $note ) === '' ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'Explain what the client needs to change.', 'client-brief-desk' ) ); }
                    $update['status'] = 'changes_requested'; $update['client_note'] = $note;
                } elseif ( $action === 'internal_note' && $raw['status'] !== 'archived' ) { $update['internal_note'] = $note;
                } elseif ( $action === 'archive' && $raw['status'] !== 'archived' ) { $update['status'] = 'archived';
                } elseif ( $action === 'delete' && $raw['status'] === 'archived' ) {
                    if ( false === $wpdb->delete( $wpdb->prefix . 'cbd_events', array( 'brief_id' => $id ) ) || 1 !== $wpdb->delete( $wpdb->prefix . 'cbd_briefs', array( 'id' => $id ) ) || false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException(); }
                    return array( 'deleted' => true );
                } elseif ( $action === 'duplicate' ) {
                    // No source change: close the read lock before creating a separate request.
                    $wpdb->query( 'ROLLBACK' );
                    return self::create( array( 'title' => mb_substr( $brief['title'] . ' — copy', 0, 120 ), 'client_id' => (int) $raw['client_id'], 'due_date' => '', 'questions' => $brief['questions'] ) );
                } else { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'This action is not available in the current state.', 'client-brief-desk' ), 409 ); }
            }
            $update['version'] = $version + 1; $update['updated_at'] = gmdate( 'Y-m-d H:i:s' );
            if ( 1 !== $wpdb->update( $wpdb->prefix . 'cbd_briefs', $update, array( 'id' => $id, 'version' => $version ) ) || ! self::event( $id, $action, $version + 1, $note, $snapshot ) || false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException(); }
        } catch ( \Throwable $e ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'The update could not be committed. Reload to check the current state.', 'client-brief-desk' ), 500 ); }
        return self::get( $id );
    }
    public static function erase_private( $id, $client_id ) {
        global $wpdb;
        if ( ! self::admin() ) { return self::error( __( 'Administrator access required.', 'client-brief-desk' ), 403 ); }
        $started = self::begin(); if ( is_wp_error( $started ) ) { return $started; }
        $row = self::row( $id, true );
        if ( ! $row || (int) $row['client_id'] !== $client_id ) { $wpdb->query( 'ROLLBACK' ); return self::error( __( 'The client record changed; retry the erasure.', 'client-brief-desk' ), 409 ); }
        if ( false === $wpdb->delete( $wpdb->prefix . 'cbd_events', array( 'brief_id' => $id ) ) || 1 !== $wpdb->delete( $wpdb->prefix . 'cbd_briefs', array( 'id' => $id ) ) || false === $wpdb->query( 'COMMIT' ) ) {
            $wpdb->query( 'ROLLBACK' ); return self::error( __( 'The client brief could not be erased. Retry after checking the database.', 'client-brief-desk' ), 500 );
        }
        return true;
    }

}
