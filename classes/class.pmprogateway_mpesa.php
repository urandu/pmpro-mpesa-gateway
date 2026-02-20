<?php
// Load class on WP init.
add_action( 'init', array( 'PMProGateway_mpesa', 'init' ) );

global $mpesa_db_version;
$mpesa_db_version = '2.0';

/**
 * PMProGateway_mpesa Class
 *
 * Handles M-Pesa Daraja STK Push integration for Paid Memberships Pro.
 */
class PMProGateway_mpesa extends PMProGateway {

	/**
	 * Constructor.
	 *
	 * @param string|null $gateway Gateway name.
	 */
	function __construct( $gateway = NULL ) {
		$this->gateway = $gateway;
		return $this->gateway;
	}

	/**
	 * Run on WP init.
	 */
	static function init() {
		// Make sure mpesa is a gateway option.
		add_filter( 'pmpro_gateways', array( 'PMProGateway_mpesa', 'pmpro_gateways' ) );

		// Add fields to payment settings page.
		add_filter( 'pmpro_payment_options', array( 'PMProGateway_mpesa', 'pmpro_payment_options' ) );
		add_filter( 'pmpro_payment_option_fields', array( 'PMProGateway_mpesa', 'pmpro_payment_option_fields' ), 10, 2 );

		// Add some fields to edit user page.
		add_action( 'pmpro_after_membership_level_profile_fields', array( 'PMProGateway_mpesa', 'user_profile_fields' ) );
		add_action( 'profile_update', array( 'PMProGateway_mpesa', 'user_profile_fields_save' ) );

		// Cron hooks.
		add_action( 'pmpro_activation', array( 'PMProGateway_mpesa', 'pmpro_activation' ) );
		add_action( 'pmpro_deactivation', array( 'PMProGateway_mpesa', 'pmpro_deactivation' ) );
		add_action( 'pmpro_cron_mpesa_subscription_updates', array( 'PMProGateway_mpesa', 'pmpro_cron_mpesa_subscription_updates' ) );

		// Checkout-specific hooks when mpesa is the active gateway.
		$gateway = pmpro_getOption( 'gateway' );
		if ( $gateway === 'mpesa' ) {
			add_action( 'pmpro_checkout_before_submit_button', array( 'PMProGateway_mpesa', 'pmpro_checkout_before_submit_button' ) );
			add_action( 'pmpro_billing_before_submit_button', array( 'PMProGateway_mpesa', 'pmpro_checkout_before_submit_button' ) );
			add_filter( 'pmpro_checkout_order', array( 'PMProGateway_mpesa', 'pmpro_checkout_order' ) );
			add_filter( 'pmpro_billing_order', array( 'PMProGateway_mpesa', 'pmpro_checkout_order' ) );
			add_action( 'wp_head', array( 'PMProGateway_mpesa', 'wp_head_hide_billing_fields' ) );
			add_filter( 'pmpro_required_billing_fields', array( 'PMProGateway_mpesa', 'pmpro_required_billing_fields' ) );
			add_filter( 'pmpro_include_payment_information_fields', array( 'PMProGateway_mpesa', 'pmpro_include_payment_information_fields' ) );
		}
	}

	/**
	 * CSS to hide the billing address fields on checkout/billing pages.
	 */
	static function wp_head_hide_billing_fields() {
		global $pmpro_pages;
		if ( empty( $pmpro_pages ) || ( ! is_page( $pmpro_pages['checkout'] ) && ! is_page( $pmpro_pages['billing'] ) ) ) {
			return;
		}
		?>
		<style>
			#pmpro_billing_address_fields { display: none; }
		</style>
		<?php
	}

	/**
	 * Make sure mpesa is in the gateways list.
	 *
	 * @param array $gateways
	 * @return array
	 */
	static function pmpro_gateways( $gateways ) {
		if ( empty( $gateways['mpesa'] ) ) {
			$gateways['mpesa'] = __( 'M-Pesa (Daraja)', 'pmpro' );
		}
		return $gateways;
	}

	/**
	 * Get a list of payment options that the mpesa gateway needs/supports.
	 *
	 * @return array
	 */
	static function getGatewayOptions() {
		return array(
			'sslseal',
			'nuclear_HTTPS',
			'gateway_environment',
			'currency',
			'use_ssl',
			'mpesa_consumer_key',
			'mpesa_consumer_secret',
			'mpesa_short_code',
			'mpesa_passkey',
			'pmpro_mpesa_uid',
			'tax_state',
			'tax_rate',
		);
	}

	/**
	 * Set payment options for payment settings page.
	 *
	 * @param array $options
	 * @return array
	 */
	static function pmpro_payment_options( $options ) {
		$mpesa_options = PMProGateway_mpesa::getGatewayOptions();
		$options       = array_merge( $mpesa_options, $options );
		return $options;
	}

	/**
	 * Display fields for mpesa options on the payment settings page.
	 *
	 * @param array  $values  Current saved option values.
	 * @param string $gateway Active gateway slug.
	 */
	static function pmpro_payment_option_fields( $values, $gateway ) {
		$hidden = ( $gateway !== 'mpesa' ) ? ' style="display: none;"' : '';

		// Generate / read the UID used to secure the callback URL.
		$uid          = ! empty( $values['pmpro_mpesa_uid'] ) ? $values['pmpro_mpesa_uid'] : wp_generate_uuid4();
		$callback_url = home_url( '/?pmpro_mpesa_ipn=1&uid=' . $uid );
		?>
		<tr class="pmpro_settings_divider gateway gateway_mpesa"<?php echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<td colspan="2">
				<h3><?php esc_html_e( 'M-Pesa (Daraja) Settings', 'paid-memberships-pro' ); ?></h3>
			</td>
		</tr>

		<tr class="gateway gateway_mpesa"<?php echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<th scope="row" valign="top">
				<label for="mpesa_short_code"><?php esc_html_e( 'Business Short Code', 'paid-memberships-pro' ); ?>:</label>
			</th>
			<td>
				<input type="text" id="mpesa_short_code" name="mpesa_short_code" size="60"
					value="<?php echo esc_attr( $values['mpesa_short_code'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'Your M-Pesa paybill or till number.', 'paid-memberships-pro' ); ?></p>
			</td>
		</tr>

		<tr class="gateway gateway_mpesa"<?php echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<th scope="row" valign="top">
				<label for="mpesa_consumer_key"><?php esc_html_e( 'Consumer Key', 'paid-memberships-pro' ); ?>:</label>
			</th>
			<td>
				<input type="text" id="mpesa_consumer_key" name="mpesa_consumer_key" size="60"
					value="<?php echo esc_attr( $values['mpesa_consumer_key'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'Daraja API consumer key from the Safaricom developer portal.', 'paid-memberships-pro' ); ?></p>
			</td>
		</tr>

		<tr class="gateway gateway_mpesa"<?php echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<th scope="row" valign="top">
				<label for="mpesa_consumer_secret"><?php esc_html_e( 'Consumer Secret', 'paid-memberships-pro' ); ?>:</label>
			</th>
			<td>
				<input type="password" id="mpesa_consumer_secret" name="mpesa_consumer_secret" size="60"
					value="<?php echo esc_attr( $values['mpesa_consumer_secret'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'Daraja API consumer secret from the Safaricom developer portal.', 'paid-memberships-pro' ); ?></p>
			</td>
		</tr>

		<tr class="gateway gateway_mpesa"<?php echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<th scope="row" valign="top">
				<label for="mpesa_passkey"><?php esc_html_e( 'Lipa Na M-Pesa Passkey', 'paid-memberships-pro' ); ?>:</label>
			</th>
			<td>
				<input type="password" id="mpesa_passkey" name="mpesa_passkey" size="60"
					value="<?php echo esc_attr( $values['mpesa_passkey'] ); ?>"/>
				<p class="description"><?php esc_html_e( 'Online passkey from the Safaricom developer portal (used to generate the STK Push password).', 'paid-memberships-pro' ); ?></p>
			</td>
		</tr>

		<tr class="gateway gateway_mpesa"<?php echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<th scope="row" valign="top">
				<label><?php esc_html_e( 'STK Push Callback URL', 'paid-memberships-pro' ); ?></label>
			</th>
			<td>
				<input type="hidden" id="pmpro_mpesa_uid" name="pmpro_mpesa_uid"
					value="<?php echo esc_attr( $uid ); ?>"/>
				<code><?php echo esc_url( $callback_url ); ?></code>
				<p class="description"><?php esc_html_e( 'Add this URL as the callback URL in your Daraja app configuration.', 'paid-memberships-pro' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Copy msisdn from POST into the order object.
	 *
	 * @param object $morder
	 * @return object
	 */
	static function pmpro_checkout_order( $morder ) {
		$morder->mpesa_msisdn = isset( $_REQUEST['msisdn'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['msisdn'] ) ) : '';
		return $morder;
	}

	/**
	 * Hook before submit button.
	 *
	 * @param object $morder
	 * @return object
	 */
	static function pmpro_checkout_before_submit_button( $morder ) {
		return $morder;
	}

	/**
	 * Code to run after checkout.
	 *
	 * @param int    $user_id
	 * @param object $morder
	 */
	static function pmpro_after_checkout( $user_id, $morder ) {
	}

	/**
	 * Render payment information fields at checkout (phone number input + instructions).
	 *
	 * @param bool $include
	 * @return false
	 */
	static function pmpro_include_payment_information_fields( $include ) {
		global $pmpro_requirebilling, $msisdn, $pmpro_level, $pmpro_error_fields;

		$short_code = pmpro_getOption( 'mpesa_short_code' );
		$amount     = isset( $pmpro_level->initial_payment ) ? $pmpro_level->initial_payment : 0;

		if ( ! empty( $pmpro_error_fields['partial_payment'] ) ) {
			$total_paid = $pmpro_error_fields['partial_payment'];
			$balance    = isset( $pmpro_error_fields['balance_amount'] ) ? $pmpro_error_fields['balance_amount'] : ( $amount - $total_paid );
			unset( $pmpro_error_fields['balance_amount'], $pmpro_error_fields['partial_payment'] );
			/* translators: 1: amount already received  2: outstanding balance */
			$info_message = sprintf(
				esc_html__( 'Received KES %1$s. Please complete the remaining KES %2$s via M-Pesa prompt on your phone.', 'paid-memberships-pro' ),
				esc_html( $total_paid ),
				esc_html( $balance )
			);
		} else {
			/* translators: 1: amount to pay  2: business short code */
			$info_message = sprintf(
				esc_html__( 'You will receive an M-Pesa prompt on your phone to pay KES %1$s to %2$s. Enter your M-Pesa PIN to complete payment, then click Submit again.', 'paid-memberships-pro' ),
				esc_html( $amount ),
				esc_html( $short_code )
			);
		}
		?>
		<div id="pmpro_payment_information_fields" class="pmpro_checkout"
			<?php if ( ! $pmpro_requirebilling || apply_filters( 'pmpro_hide_payment_information_fields', false ) ) { ?>style="display: none;"<?php } ?>>
			<h3>
				<span class="pmpro_checkout-h3-name"><?php esc_html_e( 'Payment Information', 'paid-memberships-pro' ); ?></span>
			</h3>
			<p class="pmpro_message"><?php echo wp_kses_post( $info_message ); ?></p>
			<?php $sslseal = pmpro_getOption( 'sslseal' ); ?>
			<?php if ( ! empty( $sslseal ) ) { ?>
			<div class="pmpro_checkout-fields-display-seal">
			<?php } ?>
				<div class="pmpro_checkout-fields">
					<div class="pmpro_checkout-field pmpro_payment-account-number">
						<label for="msisdn"><?php esc_html_e( 'M-Pesa Phone Number', 'paid-memberships-pro' ); ?></label>
						<input id="msisdn" name="msisdn" required
							class="input <?php echo esc_attr( pmpro_getClassForField( 'msisdn' ) ); ?>"
							type="tel" size="25"
							value="<?php echo esc_attr( $msisdn ); ?>"
							placeholder="e.g. 0712345678"
							autocomplete="tel"/>
					</div>
				</div>
			<?php if ( ! empty( $sslseal ) ) { ?>
				<div class="pmpro_checkout-fields-rightcol pmpro_sslseal"><?php echo wp_kses_post( stripslashes( $sslseal ) ); ?></div>
			</div>
			<?php } ?>
		</div>
		<?php
		// Do not include the default payment fields.
		return false;
	}

	/**
	 * Set required billing fields for M-Pesa (only the phone number).
	 *
	 * @param array $fields
	 * @return array
	 */
	static function pmpro_required_billing_fields( $fields ) {
		$fields['msisdn'] = true;
		unset(
			$fields['bfirstname'],
			$fields['blastname'],
			$fields['baddress1'],
			$fields['bcity'],
			$fields['bstate'],
			$fields['bzipcode'],
			$fields['bphone'],
			$fields['bemail'],
			$fields['bcountry'],
			$fields['CardType'],
			$fields['AccountNumber'],
			$fields['ExpirationMonth'],
			$fields['ExpirationYear'],
			$fields['CVV']
		);
		return $fields;
	}

	/**
	 * Fields shown on edit user page.
	 *
	 * @param WP_User $user
	 */
	static function user_profile_fields( $user ) {
	}

	/**
	 * Process fields from the edit user page.
	 *
	 * @param int $user_id
	 */
	static function user_profile_fields_save( $user_id ) {
	}

	/**
	 * Cron activation for subscription updates.
	 */
	static function pmpro_activation() {
		wp_schedule_event( time(), 'daily', 'pmpro_cron_mpesa_subscription_updates' );
	}

	/**
	 * Cron deactivation for subscription updates.
	 */
	static function pmpro_deactivation() {
		wp_clear_scheduled_hook( 'pmpro_cron_mpesa_subscription_updates' );
	}

	/**
	 * Cron job for subscription updates.
	 */
	static function pmpro_cron_mpesa_subscription_updates() {
	}

	/**
	 * Process checkout – handles zero-payment authorisation and charged orders.
	 *
	 * @param object $order
	 * @return bool
	 */
	function process( &$order ) {
		if ( floatval( $order->InitialPayment ) == 0 ) {
			if ( $this->authorize( $order ) ) {
				if ( ! pmpro_isLevelTrial( $order->membership_level ) ) {
					$order->ProfileStartDate      = date_i18n( 'Y-m-d' ) . 'T0:0:0';
					$order->TrialBillingPeriod    = $order->BillingPeriod;
					$order->TrialBillingFrequency = $order->BillingFrequency;
					$order->TrialBillingCycles    = 1;
					$order->TrialAmount           = 0;
					if ( ! empty( $order->TotalBillingCycles ) ) {
						$order->TotalBillingCycles++;
					}
				} elseif ( $order->InitialPayment == 0 && $order->TrialAmount == 0 ) {
					$order->ProfileStartDate = date_i18n( 'Y-m-d' ) . 'T0:0:0';
					$order->TrialBillingCycles++;
					if ( ! empty( $order->TotalBillingCycles ) ) {
						$order->TotalBillingCycles++;
					}
				} else {
					$order->ProfileStartDate = date_i18n( 'Y-m-d', strtotime( '+ ' . $order->BillingFrequency . ' ' . $order->BillingPeriod, current_time( 'timestamp' ) ) ) . 'T0:0:0';
				}
				$order->ProfileStartDate = apply_filters( 'pmpro_profile_start_date', $order->ProfileStartDate, $order );
				return $this->subscribe( $order );
			} else {
				if ( empty( $order->error ) ) {
					$order->error = __( 'Unknown error: Authorization failed.', 'paid-memberships-pro' );
				}
				return false;
			}
		} else {
			if ( $this->charge( $order ) ) {
				if ( pmpro_isLevelRecurring( $order->membership_level ) ) {
					if ( ! pmpro_isLevelTrial( $order->membership_level ) ) {
						$order->ProfileStartDate      = date_i18n( 'Y-m-d' ) . 'T0:0:0';
						$order->TrialBillingPeriod    = $order->BillingPeriod;
						$order->TrialBillingFrequency = $order->BillingFrequency;
						$order->TrialBillingCycles    = 1;
						$order->TrialAmount           = 0;
						if ( ! empty( $order->TotalBillingCycles ) ) {
							$order->TotalBillingCycles++;
						}
					} elseif ( $order->InitialPayment == 0 && $order->TrialAmount == 0 ) {
						$order->ProfileStartDate = date_i18n( 'Y-m-d' ) . 'T0:0:0';
						$order->TrialBillingCycles++;
						if ( ! empty( $order->TotalBillingCycles ) ) {
							$order->TotalBillingCycles++;
						}
					} else {
						$order->ProfileStartDate = date_i18n( 'Y-m-d', strtotime( '+ ' . $order->BillingFrequency . ' ' . $order->BillingPeriod, current_time( 'timestamp' ) ) ) . 'T0:0:0';
					}
					$order->ProfileStartDate = apply_filters( 'pmpro_profile_start_date', $order->ProfileStartDate, $order );
					if ( $this->subscribe( $order ) ) {
						return true;
					} else {
						if ( $this->void( $order ) ) {
							if ( ! $order->error ) {
								$order->error = __( 'Unknown error: Payment failed.', 'paid-memberships-pro' );
							}
						} else {
							if ( ! $order->error ) {
								$order->error = __( 'Unknown error: Payment failed.', 'paid-memberships-pro' );
							}
							$order->error .= ' ' . __( 'A partial payment was made that we could not void. Please contact the site owner immediately to correct this.', 'paid-memberships-pro' );
						}
						return false;
					}
				} else {
					$order->status = 'success';
					return true;
				}
			} else {
				if ( empty( $order->error ) ) {
					$order->error = __( 'Unknown error: Payment failed.', 'paid-memberships-pro' );
				}
				return false;
			}
		}
	}

	/**
	 * Authorize – used when the initial payment is zero.
	 *
	 * @param object $order
	 * @return bool
	 */
	function authorize( &$order ) {
		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}
		$order->payment_transaction_id = 'mpesa_' . $order->code;
		$order->updateStatus( 'authorized' );
		return true;
	}

	/**
	 * Charge via Daraja STK Push (Lipa Na M-Pesa Online).
	 *
	 * Flow:
	 *   1. Check for a recently confirmed STK Push for this MSISDN → complete order.
	 *   2. Check for a pending (in-flight) push → ask user to wait.
	 *   3. Otherwise initiate a new STK Push → ask user to check their phone.
	 *
	 * @param object $order
	 * @return bool
	 */
	function charge( &$order ) {
		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}

		$order->subtotal = $order->InitialPayment;
		$tax             = $order->getTax( true );
		$amount          = round( (float) $order->subtotal + (float) $tax, 2 );

		global $wpdb;
		$table_name  = $wpdb->prefix . 'pmpro_mpesa';
		$mpesa_msisdn = $this->normalize_phone( $order->mpesa_msisdn );

		// 1. Check if there is already a confirmed (result_code = 0) payment for this number.
		$confirmed = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, amount, mpesa_transaction_id FROM {$table_name} WHERE msisdn = %s AND order_id = '-1' AND result_code = 0 ORDER BY time DESC LIMIT 1",
				$mpesa_msisdn
			)
		);

		if ( $confirmed ) {
			if ( (float) $confirmed->amount >= $amount ) {
				// Full payment confirmed – link the transaction to this order.
				$wpdb->update(
					$table_name,
					array( 'order_id' => $order->code ),
					array( 'id'       => $confirmed->id ),
					array( '%s' ),
					array( '%d' )
				);
				$order->payment_transaction_id = $confirmed->mpesa_transaction_id;
				$order->updateStatus( 'success' );
				return true;
			}

			// Partial payment received.
			global $pmpro_error_fields;
			$balance = $amount - (float) $confirmed->amount;
			$pmpro_error_fields['partial_payment'] = $confirmed->amount;
			$pmpro_error_fields['balance_amount']  = $balance;
			$order->error      = sprintf(
				/* translators: 1: amount received  2: outstanding balance */
				__( 'Received KES %1$s. Please complete the remaining KES %2$s via M-Pesa prompt.', 'paid-memberships-pro' ),
				$confirmed->amount,
				$balance
			);
			$order->errorcode  = 'mpesa_partial_payment';
			$order->shorterror = __( 'Partial payment received.', 'paid-memberships-pro' );
			return false;
		}

		// 2. Check for a pending (in-flight) STK Push in the last 5 minutes.
		$pending = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE msisdn = %s AND order_id = '-1' AND result_code = -1 AND time > DATE_SUB(NOW(), INTERVAL 5 MINUTE) LIMIT 1",
				$mpesa_msisdn
			)
		);

		if ( $pending ) {
			$order->error      = __( 'Payment request already sent. Please check your phone, enter your M-Pesa PIN, then click Submit again.', 'paid-memberships-pro' );
			$order->errorcode  = 'mpesa_stk_pending';
			$order->shorterror = __( 'Awaiting M-Pesa payment.', 'paid-memberships-pro' );
			return false;
		}

		// 3. No payment found – initiate a new STK Push.
		$result = mpesa_stk_push( $mpesa_msisdn, $amount, $order->code, get_bloginfo( 'name' ) );

		if ( ! $result ) {
			$order->error      = __( 'Could not connect to M-Pesa. Please try again.', 'paid-memberships-pro' );
			$order->errorcode  = 'mpesa_connect_error';
			$order->shorterror = __( 'M-Pesa connection error.', 'paid-memberships-pro' );
			return false;
		}

		if ( isset( $result->errorCode ) ) {
			$order->error      = isset( $result->errorMessage ) ? $result->errorMessage : __( 'M-Pesa error. Please try again.', 'paid-memberships-pro' );
			$order->errorcode  = 'mpesa_api_error';
			$order->shorterror = __( 'M-Pesa API error.', 'paid-memberships-pro' );
			return false;
		}

		if ( isset( $result->ResponseCode ) && $result->ResponseCode === '0' ) {
			// STK Push sent – prompt user to check their phone.
			$order->error      = __( 'Please check your phone and enter your M-Pesa PIN to complete payment, then click Submit again.', 'paid-memberships-pro' );
			$order->errorcode  = 'mpesa_stk_sent';
			$order->shorterror = __( 'Awaiting M-Pesa payment.', 'paid-memberships-pro' );
			return false;
		}

		$order->error      = isset( $result->ResponseDescription )
			? $result->ResponseDescription
			: __( 'M-Pesa payment failed. Please try again.', 'paid-memberships-pro' );
		$order->errorcode  = 'mpesa_stk_failed';
		$order->shorterror = __( 'M-Pesa payment failed.', 'paid-memberships-pro' );
		return false;
	}

	/**
	 * Set up a recurring subscription.
	 *
	 * @param object $order
	 * @return bool
	 */
	function subscribe( &$order ) {
		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}
		$order = apply_filters( 'pmpro_subscribe_order', $order, $this );
		$order->status                      = 'success';
		$order->subscription_transaction_id = 'mpesa_' . $order->code;
		return true;
	}

	/**
	 * Normalize a Kenyan phone number to international format (254XXXXXXXXX).
	 *
	 * @param string $phone
	 * @return string
	 */
	private function normalize_phone( $phone ) {
		$phone = trim( str_replace( array( ' ', '-', '+' ), '', $phone ) );
		if ( substr( $phone, 0, 1 ) === '0' ) {
			$phone = '254' . substr( $phone, 1 );
		}
		return $phone;
	}
}


// ─── Standalone plugin functions ──────────────────────────────────────────────

/**
 * Create or upgrade the M-Pesa transactions database table.
 */
function mpesa_install() {
	global $wpdb, $mpesa_db_version;

	$table_name      = $wpdb->prefix . 'pmpro_mpesa';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
		id bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
		msisdn varchar(20) NOT NULL,
		time datetime DEFAULT CURRENT_TIMESTAMP,
		user_id varchar(255),
		amount float NOT NULL DEFAULT 0,
		order_id varchar(255) NOT NULL DEFAULT '-1',
		payload longtext,
		mpesa_transaction_id varchar(50),
		checkout_request_id varchar(100),
		result_code int NOT NULL DEFAULT -1
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'mpesa_db_version', $mpesa_db_version );
}

/**
 * Handle the Daraja STK Push payment callback (called by Safaricom servers).
 *
 * URL: /?pmpro_mpesa_ipn=1&uid=<secret>
 */
function pmpro_mpesa_ipn_listener() {
	if ( ! isset( $_GET['pmpro_mpesa_ipn'] ) ) {
		return;
	}

	$stored_uid  = (string) pmpro_getOption( 'pmpro_mpesa_uid' );
	$request_uid = isset( $_GET['uid'] ) ? sanitize_text_field( wp_unslash( $_GET['uid'] ) ) : '';

	if ( empty( $request_uid ) || ! hash_equals( $stored_uid, $request_uid ) ) {
		wp_send_json( array( 'ResultCode' => 1, 'ResultDesc' => 'Invalid UID' ), 403 );
		exit;
	}

	$raw  = file_get_contents( 'php://input' );
	$data = json_decode( $raw );

	if ( empty( $data ) ) {
		wp_send_json( array( 'ResultCode' => 1, 'ResultDesc' => 'Invalid payload' ), 400 );
		exit;
	}

	mpesa_process_stk_callback( $data, $raw );

	wp_send_json( array( 'ResultCode' => 0, 'ResultDesc' => 'Success' ) );
	exit;
}

/**
 * Persist an STK Push callback to the database.
 *
 * @param object $data        Decoded JSON callback body.
 * @param string $raw_payload Raw JSON string (stored for audit purposes).
 * @return bool True on success, false on failure.
 */
function mpesa_process_stk_callback( $data, $raw_payload ) {
	if ( empty( $data->Body->stkCallback ) ) {
		return false;
	}

	$callback            = $data->Body->stkCallback;
	$checkout_request_id = isset( $callback->CheckoutRequestID ) ? sanitize_text_field( $callback->CheckoutRequestID ) : '';
	$result_code         = isset( $callback->ResultCode ) ? (int) $callback->ResultCode : -1;

	global $wpdb;
	$table_name = $wpdb->prefix . 'pmpro_mpesa';

	// Prevent duplicate processing.
	$existing_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$table_name} WHERE checkout_request_id = %s",
			$checkout_request_id
		)
	);

	if ( $result_code !== 0 ) {
		// Failed or cancelled – update the pending record if it exists.
		if ( $existing_id ) {
			$wpdb->update(
				$table_name,
				array( 'result_code' => $result_code, 'payload' => $raw_payload ),
				array( 'id' => $existing_id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		}
		return false;
	}

	// Successful payment – extract metadata items.
	$amount       = 0;
	$receipt      = '';
	$phone_number = '';

	if ( ! empty( $callback->CallbackMetadata->Item ) ) {
		foreach ( $callback->CallbackMetadata->Item as $item ) {
			switch ( $item->Name ) {
				case 'Amount':
					$amount = floatval( $item->Value );
					break;
				case 'MpesaReceiptNumber':
					$receipt = sanitize_text_field( $item->Value );
					break;
				case 'PhoneNumber':
					$phone_number = sanitize_text_field( $item->Value );
					break;
			}
		}
	}

	if ( $existing_id ) {
		$wpdb->update(
			$table_name,
			array(
				'amount'               => $amount,
				'msisdn'               => $phone_number,
				'mpesa_transaction_id' => $receipt,
				'result_code'          => $result_code,
				'payload'              => $raw_payload,
			),
			array( 'id' => $existing_id ),
			array( '%f', '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);
	} else {
		$wpdb->insert(
			$table_name,
			array(
				'msisdn'               => $phone_number,
				'amount'               => $amount,
				'mpesa_transaction_id' => $receipt,
				'checkout_request_id'  => $checkout_request_id,
				'result_code'          => $result_code,
				'payload'              => $raw_payload,
			),
			array( '%s', '%f', '%s', '%s', '%d', '%s' )
		);
	}

	return true;
}

/**
 * Obtain a Daraja OAuth 2.0 access token.
 *
 * @return string|false Access token string, or false on failure.
 */
function mpesa_get_access_token() {
	$consumer_key    = pmpro_getOption( 'mpesa_consumer_key' );
	$consumer_secret = pmpro_getOption( 'mpesa_consumer_secret' );
	$environment     = pmpro_getOption( 'gateway_environment' );

	$endpoint = ( $environment === 'live' )
		? 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'
		: 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';

	$response = wp_remote_get(
		$endpoint,
		array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $consumer_key . ':' . $consumer_secret ),
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return false;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ) );
	return isset( $body->access_token ) ? $body->access_token : false;
}

/**
 * Initiate a Daraja STK Push (Lipa Na M-Pesa Online / M-Pesa Express).
 *
 * Inserts a pending row in the transactions table before the API call so
 * that the callback can be matched by checkout_request_id.
 *
 * @param string $phone       Phone number in international format (e.g. 254712345678).
 * @param float  $amount      Amount to request.
 * @param string $reference   Account reference – typically the order code.
 * @param string $description Short transaction description.
 * @return object|false Decoded Daraja API response, or false on connection failure.
 */
function mpesa_stk_push( $phone, $amount, $reference, $description = '' ) {
	$access_token = mpesa_get_access_token();
	if ( ! $access_token ) {
		return false;
	}

	$short_code  = pmpro_getOption( 'mpesa_short_code' );
	$passkey     = pmpro_getOption( 'mpesa_passkey' );
	$environment = pmpro_getOption( 'gateway_environment' );
	$mpesa_uid   = pmpro_getOption( 'pmpro_mpesa_uid' );

	$timestamp    = gmdate( 'YmdHis' );
	$password     = base64_encode( $short_code . $passkey . $timestamp );
	$callback_url = home_url( '/?pmpro_mpesa_ipn=1&uid=' . $mpesa_uid );

	$endpoint = ( $environment === 'live' )
		? 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
		: 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';

	$body = array(
		'BusinessShortCode' => $short_code,
		'Password'          => $password,
		'Timestamp'         => $timestamp,
		'TransactionType'   => 'CustomerPayBillOnline',
		'Amount'            => (int) ceil( $amount ),
		'PartyA'            => $phone,
		'PartyB'            => $short_code,
		'PhoneNumber'       => $phone,
		'CallBackURL'       => $callback_url,
		'AccountReference'  => substr( $reference, 0, 12 ),
		'TransactionDesc'   => substr( $description ? $description : $reference, 0, 13 ),
	);

	// Insert a pending record so the callback can be matched.
	global $wpdb;
	$table_name = $wpdb->prefix . 'pmpro_mpesa';
	$wpdb->insert(
		$table_name,
		array(
			'msisdn'      => $phone,
			'amount'      => (float) $amount,
			'result_code' => -1,
		),
		array( '%s', '%f', '%d' )
	);
	$pending_id = $wpdb->insert_id;

	$response = wp_remote_post(
		$endpoint,
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		$wpdb->delete( $table_name, array( 'id' => $pending_id ), array( '%d' ) );
		return false;
	}

	$result = json_decode( wp_remote_retrieve_body( $response ) );

	if ( isset( $result->CheckoutRequestID ) ) {
		// Store the CheckoutRequestID so the callback can update the right row.
		$wpdb->update(
			$table_name,
			array( 'checkout_request_id' => $result->CheckoutRequestID ),
			array( 'id' => $pending_id ),
			array( '%s' ),
			array( '%d' )
		);
	} else {
		// API rejected the request – remove the pending row.
		$wpdb->delete( $table_name, array( 'id' => $pending_id ), array( '%d' ) );
	}

	return $result;
}

/**
 * Register C2B confirmation and validation URLs with Daraja.
 *
 * This is optional when using STK Push, but available for C2B integrations.
 * Triggered by visiting /?mpesa_url_registration=live|sandbox
 */
function mpesa_url_registration() {
	if ( ! isset( $_GET['mpesa_url_registration'] ) ) {
		return;
	}

	$access_token = mpesa_get_access_token();
	if ( ! $access_token ) {
		wp_die( esc_html__( 'Could not obtain M-Pesa access token. Check your Consumer Key and Secret.', 'paid-memberships-pro' ) );
	}

	$environment = pmpro_getOption( 'gateway_environment' );
	$short_code  = pmpro_getOption( 'mpesa_short_code' );
	$mpesa_uid   = pmpro_getOption( 'pmpro_mpesa_uid' );
	$confirm_url = home_url( '/?pmpro_mpesa_ipn=1&uid=' . $mpesa_uid );

	$endpoint = ( $environment === 'live' )
		? 'https://api.safaricom.co.ke/mpesa/c2b/v1/registerurl'
		: 'https://sandbox.safaricom.co.ke/mpesa/c2b/v1/registerurl';

	$response = wp_remote_post(
		$endpoint,
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'ShortCode'       => $short_code,
				'ResponseType'    => 'Completed',
				'ConfirmationURL' => $confirm_url,
				'ValidationURL'   => $confirm_url . '&validation=1',
			) ),
		)
	);

	if ( is_wp_error( $response ) ) {
		wp_die( esc_html__( 'Could not connect to M-Pesa. Please try again.', 'paid-memberships-pro' ) );
	}

	$msg    = json_decode( wp_remote_retrieve_body( $response ) );
	$status = isset( $msg->ResponseDescription ) ? $msg->ResponseDescription : __( 'Could not register URLs.', 'paid-memberships-pro' );

	pmpro_setOption(
		'pmpro_mpesa_url_reg_status_production',
		( stripos( $status, 'success' ) !== false ) ? 1 : 0
	);

	wp_die( esc_html( $status ) );
}

/**
 * Simulate a C2B transaction on the Daraja sandbox (for testing).
 *
 * Triggered by visiting /?simulate_c2b
 */
function simulate_c2b() {
	if ( ! isset( $_GET['simulate_c2b'] ) ) {
		return;
	}

	$access_token = mpesa_get_access_token();
	if ( ! $access_token ) {
		wp_die( esc_html__( 'Could not obtain M-Pesa access token.', 'paid-memberships-pro' ) );
	}

	$short_code = pmpro_getOption( 'mpesa_short_code' );

	$response = wp_remote_post(
		'https://sandbox.safaricom.co.ke/mpesa/c2b/v1/simulate',
		array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'ShortCode'     => $short_code,
				'CommandID'     => 'CustomerPayBillOnline',
				'Amount'        => '200',
				'Msisdn'        => '254708374149',
				'BillRefNumber' => 'test',
			) ),
		)
	);

	if ( is_wp_error( $response ) ) {
		wp_die( esc_html__( 'Could not connect to M-Pesa sandbox.', 'paid-memberships-pro' ) );
	}

	wp_die( esc_html( wp_remote_retrieve_body( $response ) ) );
}
