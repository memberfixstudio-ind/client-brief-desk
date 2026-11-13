<?php
namespace ClientBriefDesk;
defined( 'ABSPATH' ) || exit;

class API {
    public static function permission() {
        return is_user_logged_in() && current_user_can( 'read' ) ? true : Store::error( __( 'Sign in to your account to use Client Brief Desk.', 'client-brief-desk' ), rest_authorization_required_code() );
    }
    public static function admin_permission() { return Store::admin() ? true : Store::error( __( 'Administrator access required.', 'client-brief-desk' ), rest_authorization_required_code() ); }
    private static function id_arg() {
        return array( 'required' => true, 'validate_callback' => function ( $v ) { return ( is_int( $v ) || ( is_string( $v ) && preg_match( '/^[1-9][0-9]*$/D', $v ) ) ) && false !== filter_var( $v, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ); }, 'sanitize_callback' => function ( $v ) { return (int) $v; } );
    }
    public static function register() {
        register_rest_route( 'client-brief-desk/v1', '/briefs', array(
            array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'listing' ), 'permission_callback' => array( __CLASS__, 'permission' ) ),
            array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create' ), 'permission_callback' => array( __CLASS__, 'admin_permission' ) ),
        ) );
        register_rest_route( 'client-brief-desk/v1', '/briefs/(?P<id>[1-9][0-9]*)', array(
            array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get' ), 'permission_callback' => array( __CLASS__, 'permission' ), 'args' => array( 'id' => self::id_arg() ) ),
            array( 'methods' => 'PATCH', 'callback' => array( __CLASS__, 'save' ), 'permission_callback' => array( __CLASS__, 'permission' ), 'args' => array( 'id' => self::id_arg() ) ),
        ) );
        foreach ( array( 'action' => 'POST', 'export' => 'GET' ) as $route => $method ) {
            register_rest_route( 'client-brief-desk/v1', '/briefs/(?P<id>[1-9][0-9]*)/' . $route, array( 'methods' => $method, 'callback' => array( __CLASS__, $route ), 'permission_callback' => array( __CLASS__, 'permission' ), 'args' => array( 'id' => self::id_arg() ) ) );
        }
        foreach ( array( 'clients', 'presets' ) as $route ) {
            register_rest_route( 'client-brief-desk/v1', '/' . $route, array( 'methods' => 'GET', 'callback' => array( __CLASS__, $route ), 'permission_callback' => array( __CLASS__, 'admin_permission' ) ) );
        }
    }
    private static function response( $data, $status = 200 ) {
        if ( is_wp_error( $data ) ) { return $data; }
        $response = new \WP_REST_Response( $data, $status );
        $response->header( 'Cache-Control', 'private, no-store, max-age=0' );
        $response->header( 'X-Content-Type-Options', 'nosniff' );
        return $response;
    }
    public static function listing( $r ) {
        $page = $r->get_param( 'page' ) ?? 1;
        if ( ! ( is_int( $page ) || ( is_string( $page ) && preg_match( '/^[1-9][0-9]{0,4}$/D', $page ) ) ) ) { return Store::error( __( 'Invalid page.', 'client-brief-desk' ) ); }
        return self::response( Store::listing( $r->get_param( 'status' ) ?? 'all', $r->get_param( 'search' ) ?? '', (int) $page ) );
    }
    public static function create( $r ) {
        $data = $r->get_json_params();
        return is_array( $data ) ? self::response( Store::create( $data ), 201 ) : Store::error( __( 'Send a JSON request.', 'client-brief-desk' ) );
    }
    public static function get( $r ) { return self::response( Store::get( $r['id'] ) ); }
    public static function save( $r ) {
        $data = $r->get_json_params();
        if ( ! is_array( $data ) ) { return Store::error( __( 'Send a JSON request.', 'client-brief-desk' ) ); }
        return self::response( Store::mutate( $r['id'], $data['version'] ?? null, 'save', $data ) );
    }
    public static function action( $r ) {
        $data = $r->get_json_params();
        if ( ! is_array( $data ) || ! is_string( $data['action'] ?? null ) || ! in_array( $data['action'], array( 'submit', 'approve', 'request_changes', 'reopen', 'archive', 'duplicate', 'delete', 'internal_note' ), true ) ) { return Store::error( __( 'Invalid action.', 'client-brief-desk' ) ); }
        return self::response( Store::mutate( $r['id'], $data['version'] ?? null, $data['action'], $data ), $data['action'] === 'duplicate' ? 201 : 200 );
    }
    public static function export( $r ) {
        $brief = Store::get( $r['id'] );
        return self::response( is_wp_error( $brief ) ? $brief : array( 'exported_at' => gmdate( 'c' ), 'brief' => $brief ) );
    }
    public static function clients( $r ) {
        $search = $r->get_param( 'search' ) ?? '';
        if ( ! is_string( $search ) || mb_strlen( $search ) > 80 ) { return Store::error( __( 'Search using at most 80 characters.', 'client-brief-desk' ) ); }
        if ( mb_strlen( trim( $search ) ) < 2 ) { return self::response( array() ); }
        $users = get_users( array( 'blog_id' => get_current_blog_id(), 'number' => 20, 'search' => '*' . $search . '*', 'search_columns' => array( 'display_name', 'user_login' ), 'orderby' => 'display_name', 'order' => 'ASC' ) );
        return self::response( array_map( function ( $u ) { return array( 'id' => (int) $u->ID, 'name' => html_entity_decode( $u->display_name, ENT_QUOTES, 'UTF-8' ) ); }, $users ) );
    }
    public static function presets() {
        $q = function ( $label, $help = '', $required = true ) { return compact( 'label', 'help', 'required' ); };
        return self::response( array(
            'service' => array(
                $q( __( 'What would you like us to help with?', 'client-brief-desk' ), __( 'Describe the work and the outcome you need.', 'client-brief-desk' ) ),
                $q( __( 'What does a successful result look like?', 'client-brief-desk' ) ),
                $q( __( 'What timing or constraints should we know?', 'client-brief-desk' ) ),
                $q( __( 'Anything else that will help us prepare?', 'client-brief-desk' ), '', false ),
            ),
            'website' => array(
                $q( __( 'Describe your business in one paragraph', 'client-brief-desk' ) ),
                $q( __( 'Who is the website for?', 'client-brief-desk' ) ),
                $q( __( 'What should visitors do next?', 'client-brief-desk' ) ),
                $q( __( 'Which services or products should be featured?', 'client-brief-desk' ) ),
                $q( __( 'Share approved wording and reference links', 'client-brief-desk' ), __( 'Use text and public reference links. Do not include passwords or private access tokens.', 'client-brief-desk' ), false ),
            ),
            'event' => array(
                $q( __( 'What is the event and who is it for?', 'client-brief-desk' ) ),
                $q( __( 'What are the date, venue and expected guest count?', 'client-brief-desk' ) ),
                $q( __( 'What needs to be delivered?', 'client-brief-desk' ) ),
                $q( __( 'Are there any access or scheduling constraints?', 'client-brief-desk' ), '', false ),
            ),
        ) );
    }
}
