<?php
/**
 * Tests for the M-Pesa Daraja gateway.
 *
 * @package PMProMpesaGateway
 */

use PHPUnit\Framework\TestCase;

/**
 * MpesaGatewayTest
 *
 * Covers:
 *  - Phone number normalisation
 *  - mpesa_get_access_token()
 *  - mpesa_stk_push()
 *  - mpesa_stk_query()
 *  - mpesa_process_stk_callback()
 *  - pmpro_mpesa_ipn_listener()
 */
class MpesaGatewayTest extends TestCase {

	/** @var MockWpdb */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();

		// Reset global state between tests.
		$GLOBALS['_mpesa_test_http_queue']    = array();
		$GLOBALS['_mpesa_test_json_response'] = null;
		$GLOBALS['_mpesa_test_options']       = array(
			'gateway_environment' => 'sandbox',
			'mpesa_consumer_key'  => 'test_consumer_key',
			'mpesa_consumer_secret' => 'test_consumer_secret',
			'mpesa_short_code'    => '174379',
			'mpesa_passkey'       => 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919',
			'pmpro_mpesa_uid'     => 'test-uid-1234',
		);
		$GLOBALS['pmpro_error_fields']        = array();
		$GLOBALS['_GET']                      = array();

		// Fresh wpdb mock.
		$GLOBALS['wpdb'] = new MockWpdb();
		$this->wpdb      = $GLOBALS['wpdb'];
	}

	// ─── Phone normalisation ──────────────────────────────────────────────────

	/**
	 * @dataProvider phone_normalisation_provider
	 */
	public function test_normalize_phone( $input, $expected ) {
		$gw     = new PMProGateway_mpesa( 'mpesa' );
		$method = new ReflectionMethod( PMProGateway_mpesa::class, 'normalize_phone' );
		$method->setAccessible( true );

		$this->assertSame( $expected, $method->invoke( $gw, $input ) );
	}

	public static function phone_normalisation_provider() {
		return array(
			'07xx format'          => array( '0712345678', '254712345678' ),
			'2547xx already set'   => array( '254712345678', '254712345678' ),
			'with leading plus'    => array( '+254712345678', '254712345678' ),
			'with spaces'          => array( '07 12 34 56 78', '254712345678' ),
			'with dashes'          => array( '0712-345-678', '254712345678' ),
		);
	}

	// ─── mpesa_get_access_token() ─────────────────────────────────────────────

	public function test_get_access_token_returns_token_on_success() {
		mpesa_test_queue_http( json_encode( array( 'access_token' => 'tok_abc123' ) ) );

		$token = mpesa_get_access_token();

		$this->assertSame( 'tok_abc123', $token );
	}

	public function test_get_access_token_returns_false_on_connection_error() {
		// Queue a WP_Error (simulate connection failure).
		$GLOBALS['_mpesa_test_http_queue'][] = new WP_Error( 'http_request_failed', 'cURL error' );

		$token = mpesa_get_access_token();

		$this->assertFalse( $token );
	}

	public function test_get_access_token_returns_false_when_token_missing_in_response() {
		mpesa_test_queue_http( json_encode( array( 'error' => 'invalid_client' ) ) );

		$token = mpesa_get_access_token();

		$this->assertFalse( $token );
	}

	public function test_get_access_token_uses_live_endpoint_in_production() {
		$GLOBALS['_mpesa_test_options']['gateway_environment'] = 'live';

		mpesa_test_queue_http( json_encode( array( 'access_token' => 'live_token' ) ) );
		$token = mpesa_get_access_token();

		$this->assertSame( 'live_token', $token );
	}

	// ─── mpesa_stk_push() ─────────────────────────────────────────────────────

	public function test_stk_push_returns_false_when_no_access_token() {
		// No HTTP response queued → access token call returns WP_Error.
		$GLOBALS['_mpesa_test_http_queue'][] = new WP_Error( 'connection', 'fail' );

		$result = mpesa_stk_push( '254712345678', 100, 'ORD001' );

		$this->assertFalse( $result );
	}

	public function test_stk_push_stores_pending_row_on_accepted_request() {
		// Access token response.
		mpesa_test_queue_http( json_encode( array( 'access_token' => 'tok_123' ) ) );
		// STK Push response.
		mpesa_test_queue_http( json_encode( array(
			'MerchantRequestID'   => 'mrq-001',
			'CheckoutRequestID'   => 'chk-001',
			'ResponseCode'        => '0',
			'ResponseDescription' => 'Success',
		) ) );

		$result = mpesa_stk_push( '254712345678', 100, 'ORD001', 'Test Site' );

		// insert() should have been called with the pending row.
		$inserts = array_filter( $this->wpdb->calls, fn( $c ) => $c['method'] === 'insert' );
		$this->assertNotEmpty( $inserts );
		$insert = array_values( $inserts )[0];
		$this->assertSame( '254712345678', $insert['data']['msisdn'] );
		$this->assertSame( 100.0, (float) $insert['data']['amount'] );
		$this->assertSame( -1, $insert['data']['result_code'] );

		// update() should have stored the CheckoutRequestID.
		$updates = array_filter( $this->wpdb->calls, fn( $c ) => $c['method'] === 'update' );
		$this->assertNotEmpty( $updates );
		$update = array_values( $updates )[0];
		$this->assertSame( 'chk-001', $update['data']['checkout_request_id'] );

		$this->assertSame( '0', $result->ResponseCode );
	}

	public function test_stk_push_removes_pending_row_when_api_rejects_request() {
		mpesa_test_queue_http( json_encode( array( 'access_token' => 'tok_123' ) ) );
		mpesa_test_queue_http( json_encode( array(
			'errorCode'    => '400.002.02',
			'errorMessage' => 'Bad Request - Invalid ShortCode',
		) ) );

		$result = mpesa_stk_push( '254712345678', 100, 'ORD001' );

		// Pending row should have been deleted.
		$deletes = array_filter( $this->wpdb->calls, fn( $c ) => $c['method'] === 'delete' );
		$this->assertNotEmpty( $deletes );

		$this->assertObjectHasAttribute( 'errorCode', $result );
	}

	// ─── mpesa_stk_query() ────────────────────────────────────────────────────

	public function test_stk_query_returns_false_when_no_access_token() {
		$GLOBALS['_mpesa_test_http_queue'][] = new WP_Error( 'connection', 'fail' );

		$result = mpesa_stk_query( 'chk-001' );

		$this->assertFalse( $result );
	}

	public function test_stk_query_returns_success_response() {
		mpesa_test_queue_http( json_encode( array( 'access_token' => 'tok_123' ) ) );
		mpesa_test_queue_http( json_encode( array(
			'ResponseCode'        => '0',
			'ResponseDescription' => 'The service request has been accepted successfully.',
			'MerchantRequestID'   => 'mrq-001',
			'CheckoutRequestID'   => 'chk-001',
			'ResultCode'          => '0',
			'ResultDesc'          => 'The service request is processed successfully.',
		) ) );

		$result = mpesa_stk_query( 'chk-001' );

		$this->assertNotFalse( $result );
		$this->assertSame( '0', $result->ResultCode );
		$this->assertSame( 'chk-001', $result->CheckoutRequestID );
	}

	public function test_stk_query_returns_cancelled_result_code() {
		mpesa_test_queue_http( json_encode( array( 'access_token' => 'tok_123' ) ) );
		mpesa_test_queue_http( json_encode( array(
			'ResultCode' => '1032',
			'ResultDesc' => 'Request cancelled by user.',
		) ) );

		$result = mpesa_stk_query( 'chk-002' );

		$this->assertSame( '1032', $result->ResultCode );
	}

	public function test_stk_query_returns_false_on_connection_error() {
		mpesa_test_queue_http( json_encode( array( 'access_token' => 'tok_123' ) ) );
		$GLOBALS['_mpesa_test_http_queue'][] = new WP_Error( 'http_request_failed', 'timeout' );

		$result = mpesa_stk_query( 'chk-003' );

		$this->assertFalse( $result );
	}

	public function test_stk_query_uses_live_endpoint_in_production() {
		$GLOBALS['_mpesa_test_options']['gateway_environment'] = 'live';
		mpesa_test_queue_http( json_encode( array( 'access_token' => 'live_tok' ) ) );
		mpesa_test_queue_http( json_encode( array( 'ResultCode' => '0', 'ResultDesc' => 'OK' ) ) );

		$result = mpesa_stk_query( 'chk-live-001' );

		$this->assertSame( '0', $result->ResultCode );
	}

	// ─── mpesa_process_stk_callback() ────────────────────────────────────────

	public function test_callback_returns_false_for_invalid_payload() {
		$data = json_decode( '{"invalid": true}' );

		$result = mpesa_process_stk_callback( $data, '{"invalid":true}' );

		$this->assertFalse( $result );
	}

	public function test_callback_inserts_new_row_on_successful_payment() {
		$payload = json_decode( json_encode( array(
			'Body' => array(
				'stkCallback' => array(
					'MerchantRequestID' => 'mrq-001',
					'CheckoutRequestID' => 'chk-success-001',
					'ResultCode'        => 0,
					'ResultDesc'        => 'The service request is processed successfully.',
					'CallbackMetadata'  => array(
						'Item' => array(
							array( 'Name' => 'Amount', 'Value' => 1500 ),
							array( 'Name' => 'MpesaReceiptNumber', 'Value' => 'NLJ7RT61SV' ),
							array( 'Name' => 'TransactionDate', 'Value' => 20191219102115 ),
							array( 'Name' => 'PhoneNumber', 'Value' => 254708374149 ),
						),
					),
				),
			),
		) ) );
		$raw = json_encode( $payload );

		// No existing row.
		$this->wpdb->queue_get_var( null );

		$result = mpesa_process_stk_callback( $payload, $raw );

		$this->assertTrue( $result );

		$inserts = array_filter( $this->wpdb->calls, fn( $c ) => $c['method'] === 'insert' );
		$this->assertNotEmpty( $inserts );
		$insert = array_values( $inserts )[0];
		$this->assertSame( 1500.0, (float) $insert['data']['amount'] );
		$this->assertSame( 'NLJ7RT61SV', $insert['data']['mpesa_transaction_id'] );
		$this->assertSame( 0, $insert['data']['result_code'] );
		$this->assertSame( 'chk-success-001', $insert['data']['checkout_request_id'] );
	}

	public function test_callback_updates_existing_row_on_successful_payment() {
		$payload = json_decode( json_encode( array(
			'Body' => array(
				'stkCallback' => array(
					'CheckoutRequestID' => 'chk-update-001',
					'ResultCode'        => 0,
					'ResultDesc'        => 'Success',
					'CallbackMetadata'  => array(
						'Item' => array(
							array( 'Name' => 'Amount', 'Value' => 500 ),
							array( 'Name' => 'MpesaReceiptNumber', 'Value' => 'ABC123XYZ' ),
							array( 'Name' => 'PhoneNumber', 'Value' => 254712345678 ),
						),
					),
				),
			),
		) ) );

		// Simulate that a pending row already exists with id = 42.
		$this->wpdb->queue_get_var( 42 );

		$result = mpesa_process_stk_callback( $payload, json_encode( $payload ) );

		$this->assertTrue( $result );

		$updates = array_filter( $this->wpdb->calls, fn( $c ) => $c['method'] === 'update' );
		$this->assertNotEmpty( $updates );
		$update = array_values( $updates )[0];
		$this->assertSame( 0, $update['data']['result_code'] );
		$this->assertSame( 'ABC123XYZ', $update['data']['mpesa_transaction_id'] );
		$this->assertSame( array( 'id' => 42 ), $update['where'] );
	}

	public function test_callback_updates_result_code_on_failed_payment() {
		$payload = json_decode( json_encode( array(
			'Body' => array(
				'stkCallback' => array(
					'CheckoutRequestID' => 'chk-fail-001',
					'ResultCode'        => 1032,
					'ResultDesc'        => 'Request cancelled by user.',
				),
			),
		) ) );

		// Simulate that a pending row exists with id = 7.
		$this->wpdb->queue_get_var( 7 );

		$result = mpesa_process_stk_callback( $payload, json_encode( $payload ) );

		$this->assertFalse( $result );

		$updates = array_filter( $this->wpdb->calls, fn( $c ) => $c['method'] === 'update' );
		$this->assertNotEmpty( $updates );
		$update = array_values( $updates )[0];
		$this->assertSame( 1032, $update['data']['result_code'] );
	}

	public function test_callback_returns_false_for_failed_payment_with_no_existing_row() {
		$payload = json_decode( json_encode( array(
			'Body' => array(
				'stkCallback' => array(
					'CheckoutRequestID' => 'chk-fail-002',
					'ResultCode'        => 1037,
					'ResultDesc'        => 'DS timeout user cannot be reached.',
				),
			),
		) ) );

		// No existing row.
		$this->wpdb->queue_get_var( null );

		$result = mpesa_process_stk_callback( $payload, json_encode( $payload ) );

		$this->assertFalse( $result );
	}

	// ─── pmpro_mpesa_ipn_listener() ──────────────────────────────────────────

	public function test_ipn_listener_returns_early_without_query_var() {
		$_GET = array();
		// Should return without calling wp_send_json.
		pmpro_mpesa_ipn_listener();

		$this->assertNull( $GLOBALS['_mpesa_test_json_response'] );
	}

	public function test_ipn_listener_rejects_invalid_uid() {
		$_GET = array( 'pmpro_mpesa_ipn' => '1', 'uid' => 'wrong-uid' );

		// pmpro_mpesa_ipn_listener calls wp_send_json and then exit;
		// Our stub records the response without calling exit.
		pmpro_mpesa_ipn_listener();

		$response = $GLOBALS['_mpesa_test_json_response'];
		$this->assertNotNull( $response );
		$this->assertSame( 403, $response['status'] );
		$this->assertSame( 1, $response['data']['ResultCode'] );
	}

	public function test_ipn_listener_rejects_missing_uid() {
		$_GET = array( 'pmpro_mpesa_ipn' => '1' );

		pmpro_mpesa_ipn_listener();

		$response = $GLOBALS['_mpesa_test_json_response'];
		$this->assertNotNull( $response );
		$this->assertSame( 403, $response['status'] );
	}
}
