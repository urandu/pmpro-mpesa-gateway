<?php
/**
 * Test bootstrap – provides stubs for WordPress and PMPro functions/globals
 * so that the gateway class can be loaded and tested without a full WordPress
 * installation.
 */

// ──────────────────────────────────────────────────────────────────────────────
// Global test registry
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Queue a mock HTTP response to be consumed by the next wp_remote_* call.
 *
 * @param string $body    Response body JSON.
 * @param int    $status  HTTP status code.
 */
function mpesa_test_queue_http( $body, $status = 200 ) {
	$GLOBALS['_mpesa_test_http_queue'][] = array(
		'body'     => $body,
		'response' => array( 'code' => $status, 'message' => 'OK' ),
		'headers'  => array(),
	);
}

/**
 * Return the next queued HTTP response, or a WP_Error if none is queued.
 *
 * @return array|WP_Error
 */
function _mpesa_test_pop_http() {
	if ( ! empty( $GLOBALS['_mpesa_test_http_queue'] ) ) {
		return array_shift( $GLOBALS['_mpesa_test_http_queue'] );
	}
	return new WP_Error( 'no_mock', 'No mock HTTP response queued.' );
}

// ──────────────────────────────────────────────────────────────────────────────
// WordPress stubs
// ──────────────────────────────────────────────────────────────────────────────

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() { return $this->message; }
}

class PMProGateway {
	public $gateway;
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		return _mpesa_test_pop_http();
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = array() ) {
		return _mpesa_test_pop_http();
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return is_wp_error( $response ) ? '' : $response['body'];
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'wp_send_json' ) ) {
	function wp_send_json( $data, $status = 200 ) {
		$GLOBALS['_mpesa_test_json_response'] = array( 'data' => $data, 'status' => $status );
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '' ) {
		throw new RuntimeException( is_string( $message ) ? $message : 'wp_die called' );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.com' . $path;
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		return 'Test Blog';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}

if ( ! function_exists( '_e' ) ) {
	function _e( $text, $domain = '' ) {
		echo $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = '' ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'pmpro_getOption' ) ) {
	function pmpro_getOption( $key ) {
		return isset( $GLOBALS['_mpesa_test_options'][ $key ] ) ? $GLOBALS['_mpesa_test_options'][ $key ] : '';
	}
}

if ( ! function_exists( 'pmpro_setOption' ) ) {
	function pmpro_setOption( $key, $value ) {
		$GLOBALS['_mpesa_test_options'][ $key ] = $value;
	}
}

if ( ! function_exists( 'pmpro_getClassForField' ) ) {
	function pmpro_getClassForField( $field ) {
		return '';
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}

if ( ! function_exists( 'date_i18n' ) ) {
	function date_i18n( $format, $timestamp = null ) {
		return $timestamp ? gmdate( $format, $timestamp ) : gmdate( $format );
	}
}

if ( ! function_exists( 'pmpro_isLevelTrial' ) ) {
	function pmpro_isLevelTrial( $level ) {
		return false;
	}
}

if ( ! function_exists( 'pmpro_isLevelRecurring' ) ) {
	function pmpro_isLevelRecurring( $level ) {
		return false;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type ) {
		return time();
	}
}

if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event() {}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook() {}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value ) {
		$GLOBALS['_mpesa_test_options'][ $key ] = $value;
	}
}

if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook() {}
}

if ( ! function_exists( 'is_page' ) ) {
	function is_page( $id ) {
		return false;
	}
}

// ──────────────────────────────────────────────────────────────────────────────
// Mock $wpdb global
// ──────────────────────────────────────────────────────────────────────────────

class MockWpdb {
	public $prefix = 'wp_';

	/** @var array Recorded queries / calls for assertions */
	public $calls = array();

	/** @var array Queue of return values for get_var() */
	private $get_var_queue = array();

	/** @var array Queue of return values for get_row() */
	private $get_row_queue = array();

	/** Last insert_id */
	public $insert_id = 1;

	// ── Configuration helpers ────────────────────────────────────────────────

	public function queue_get_var( $value ) {
		$this->get_var_queue[] = $value;
	}

	public function queue_get_row( $value ) {
		$this->get_row_queue[] = $value;
	}

	// ── WordPress API ────────────────────────────────────────────────────────

	public function prepare( $query, ...$args ) {
		// Simple positional replacement for %s, %d, %f.
		$i = 0;
		return preg_replace_callback( '/%[sdf]/', function( $m ) use ( $args, &$i ) {
			$val = isset( $args[ $i ] ) ? $args[ $i++ ] : '';
			if ( $m[0] === '%s' ) return "'" . addslashes( $val ) . "'";
			if ( $m[0] === '%d' ) return (int) $val;
			if ( $m[0] === '%f' ) return (float) $val;
			return $val;
		}, $query );
	}

	public function get_var( $query = null ) {
		$this->calls[] = array( 'method' => 'get_var', 'query' => $query );
		return ! empty( $this->get_var_queue ) ? array_shift( $this->get_var_queue ) : null;
	}

	public function get_row( $query = null, $output = 'OBJECT', $y = 0 ) {
		$this->calls[] = array( 'method' => 'get_row', 'query' => $query );
		return ! empty( $this->get_row_queue ) ? array_shift( $this->get_row_queue ) : null;
	}

	public function insert( $table, $data, $format = null ) {
		$this->calls[] = array( 'method' => 'insert', 'table' => $table, 'data' => $data );
		$this->insert_id++;
		return 1;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->calls[] = array( 'method' => 'update', 'table' => $table, 'data' => $data, 'where' => $where );
		return 1;
	}

	public function delete( $table, $where, $where_format = null ) {
		$this->calls[] = array( 'method' => 'delete', 'table' => $table, 'where' => $where );
		return 1;
	}

	public function query( $query ) {
		$this->calls[] = array( 'method' => 'query', 'query' => $query );
		return 1;
	}
}

// Boot the global $wpdb mock.
$GLOBALS['wpdb'] = new MockWpdb();

// ──────────────────────────────────────────────────────────────────────────────
// WordPress constants / globals expected by the plugin file
// ──────────────────────────────────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

if ( ! defined( 'PMPRO_MPESAGATEWAY_DIR' ) ) {
	define( 'PMPRO_MPESAGATEWAY_DIR', dirname( __DIR__ ) );
}

// dbDelta stub (called by mpesa_install).
if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( $queries = '' ) {}
}

// ──────────────────────────────────────────────────────────────────────────────
// Load the plugin class (without the main plugin file to avoid redefining hooks)
// ──────────────────────────────────────────────────────────────────────────────
require_once dirname( __DIR__ ) . '/classes/class.pmprogateway_mpesa.php';
