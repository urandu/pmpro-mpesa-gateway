<?php
//load classes init method
add_action('init', array('PMProGateway_mpesa', 'init'));


global $mpesa_db_version;
$mpesa_db_version = '1.0';

function mpesa_install()
{
    global $wpdb;
    global $mpesa_db_version;

    $table_name = $wpdb->prefix . 'mpesa_pmpro';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
		id bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
        msisdn varchar(20) NOT NULL,
        time datetime DEFAULT CURRENT_TIMESTAMP,
        user_id varchar(255) NOT NULL,
        amount float NOT NULL,
        order_id varchar(255) NOT NULL DEFAULT -1,
        payload longtext
	  ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    add_option('mpesa_db_version', $mpesa_db_version);
}


/**
 * PMProGateway_gatewayname Class
 *
 * Handles mpesa integration.
 *
 */
class PMProGateway_mpesa extends PMProGateway
{
    function PMProGateway($gateway = NULL)
    {
        $this->gateway = $gateway;
        return $this->gateway;
    }

    /**
     * Run on WP init
     *
     * @since 1.8
     */
    static function init()
    {
        //make sure mpesa is a gateway option
        add_filter('pmpro_gateways', array('PMProGateway_mpesa', 'pmpro_gateways'));

        //add fields to payment settings
        add_filter('pmpro_payment_options', array('PMProGateway_mpesa', 'pmpro_payment_options'));
        add_filter('pmpro_payment_option_fields', array('PMProGateway_mpesa', 'pmpro_payment_option_fields'), 10, 2);

        //add some fields to edit user page (Updates)
        add_action('pmpro_after_membership_level_profile_fields', array('PMProGateway_mpesa', 'user_profile_fields'));
        add_action('profile_update', array('PMProGateway_mpesa', 'user_profile_fields_save'));

        //updates cron
        add_action('pmpro_activation', array('PMProGateway_mpesa', 'pmpro_activation'));
        add_action('pmpro_deactivation', array('PMProGateway_mpesa', 'pmpro_deactivation'));
        add_action('pmpro_cron_mpesa_subscription_updates', array('PMProGateway_mpesa', 'pmpro_cron_mpesa_subscription_updates'));


        //code to add at checkout if mpesa is the current gateway
        $gateway = pmpro_getOption("gateway");
        if ($gateway == "mpesa") {
            add_action('pmpro_checkout_before_submit_button', array('PMProGateway_mpesa', 'pmpro_checkout_before_submit_button'));
            add_action('pmpro_billing_before_submit_button', array('PMProGateway_mpesa', 'pmpro_checkout_before_submit_button'));
            add_filter('pmpro_checkout_order', array('PMProGateway_mpesa', 'pmpro_checkout_order'));
            add_action('wp_head', array('PMProGateway_mpesa', 'wp_head_hide_billing_fields'));
            add_filter('pmpro_checkout_order', array('PMProGateway_mpesa', 'pmpro_checkout_order'));
            add_filter('pmpro_billing_order', array('PMProGateway_mpesa', 'pmpro_checkout_order'));
            add_filter('pmpro_required_billing_fields', array('PMProGateway_mpesa', 'pmpro_required_billing_fields'));
            add_filter('pmpro_include_payment_information_fields', array('PMProGateway_mpesa', 'pmpro_include_payment_information_fields'));
            add_action( 'init', 'pmpro_mpesa_ipn_listener' );
        }


    }


    //css to hide the fields
    function wp_head_hide_billing_fields()
    {
        global $post, $pmpro_pages;
        if (empty($pmpro_pages) || (!is_page($pmpro_pages['checkout']) && !is_page($pmpro_pages['billing'])))
            return;
        ?>
        <style>
            #pmpro_billing_address_fields {
                display: none;
            }
        </style>
        <?php
    }

    /**
     * Make sure mpesa is in the gateways list
     *
     * @since 1.8
     */
    static function pmpro_gateways($gateways)
    {
        if (empty($gateways['mpesa']))
            $gateways['mpesa'] = __('mpesa', 'pmpro');

        return $gateways;
    }

    /**
     * Get a list of payment options that the mpesa gateway needs/supports.
     *
     * @since 1.8
     */
    static function getGatewayOptions()
    {
        $options = array(
            'sslseal',
            'nuclear_HTTPS',
            'gateway_environment',
            'currency',
            'use_ssl',
            'mpesa_secret_key',
            'mpesa_api_key',
            'tax_state',
            'tax_rate',
            'accepted_credit_cards'
        );

        return $options;
    }

    /**
     * Set payment options for payment settings page.
     *
     * @since 1.8
     */
    static function pmpro_payment_options($options)
    {
        //get mpesa options
        $mpesa_options = PMProGateway_mpesa::getGatewayOptions();

        //merge with others.
        $options = array_merge($mpesa_options, $options);

        return $options;
    }

    /**
     * Display fields for mpesa options.
     *
     * @since 1.8
     */
    static function pmpro_payment_option_fields($values, $gateway)
    {
        ?>
        <tr class="pmpro_settings_divider gateway gateway_mpesa"
            <?php if ($gateway != "mpesa") { ?>style="display: none;"<?php } ?>>
            <td colspan="2">
                <?php _e('Mpesa Settings', 'paid-memberships-pro'); ?>
            </td>
        </tr>
        <tr class="gateway gateway_mpesa" <?php if ($gateway != "mpesa") { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label for="mpesa_secret_key"><?php _e('Secret key', 'paid-memberships-pro'); ?>:</label>
            </th>
            <td>
                <input type="text" id="mpesa_secret_key" name="mpesa_secret_key" size="60"
                       value="<?php echo esc_attr($values['mpesa_secret_key']) ?>"/>
            </td>
        </tr>
        <tr class="gateway gateway_mpesa" <?php if ($gateway != "mpesa") { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label for="mpesa_api_key"><?php _e('API Key', 'paid-memberships-pro'); ?>:</label>
            </th>
            <td>
                <input type="text" id="mpesa_api_key" name="mpesa_api_key" size="60"
                       value="<?php echo esc_attr($values['mpesa_api_key']) ?>"/>
            </td>
        </tr>
        <?php
    }

    /**
     * Filtering orders at checkout.
     *
     * @since 1.8
     */
    static function pmpro_checkout_order($morder)
    {
        //load up values
        if (isset($_REQUEST['msisdn']))
            $mpesa_msisdn = sanitize_text_field($_REQUEST['msisdn']);
        else
            $mpesa_msisdn = "";

        $morder->mpesa_msisdn = $mpesa_msisdn;

        return $morder;
    }

    /**
     * Filtering orders at checkout.
     *
     * @since 1.8
     */
    static function pmpro_checkout_before_submit_button($morder)
    {

        return $morder;
    }

    /**
     * Code to run after checkout
     *
     * @since 1.8
     */
    static function pmpro_after_checkout($user_id, $morder)
    {
    }

    /**
     * Use our own payment fields at checkout. (Remove the name attributes.)
     * @since 1.8
     */
    static function pmpro_include_payment_information_fields($include)
    {
        //global vars
        global $pmpro_requirebilling, $pmpro_show_discount_code, $discount_code, $CardType, $AccountNumber, $ExpirationMonth, $ExpirationYear, $current_user, $morder, $order_id, $pmpro_level, $order, $pmpro_error_fields;

        //get accepted credit cards
        $pmpro_accepted_credit_cards = pmpro_getOption("accepted_credit_cards");
        $pmpro_accepted_credit_cards = explode(",", $pmpro_accepted_credit_cards);
        $pmpro_accepted_credit_cards_string = pmpro_implodeToEnglish($pmpro_accepted_credit_cards);

        //include ours
        ?>
        <div id="pmpro_payment_information_fields" class="pmpro_checkout"
             <?php if (!$pmpro_requirebilling || apply_filters("pmpro_hide_payment_information_fields", false)) { ?>style="display: none;"<?php } ?>>
            <h3>
                <span class="pmpro_checkout-h3-name"><?php _e('Payment Information', 'paid-memberships-pro'); ?></span>
                <?php

                //                print("<pre>");
                $amount = $pmpro_level->initial_payment;
                if (!empty($pmpro_error_fields["partial_payment"])) {
                    $total_amount_paid_by_msisdn = $pmpro_error_fields["partial_payment"];
                    $balance_amount = $pmpro_error_fields["balance_amount"];
                    unset($pmpro_error_fields["balance_amount"]);
                    unset($pmpro_error_fields["partial_payment"]);
                    $info_message = sprintf('Received KES %s, please pay KES %s to complete the payment.<br> 
                    To pay, go to mpesa and pay %s to till number %s then press the submit button below'
                        , $total_amount_paid_by_msisdn, $balance_amount, $balance_amount, "11111111");

                } else {
                    $info_message = sprintf('To pay, go to mpesa and pay %s to till number %s', $amount, "11111111");
                }


                //                print("</pre>");
                ?>
                <span class="pmpro_checkout-h3-name"><?php print(__($info_message)); ?></span>
            </h3>
            <?php $sslseal = pmpro_getOption("sslseal"); ?>
            <?php if (!empty($sslseal)) { ?>
            <div class="pmpro_checkout-fields-display-seal">
                <?php } ?>
                <div class="pmpro_checkout-fields">
                    <div class="pmpro_checkout-field pmpro_payment-account-number">
                        <label for="AccountNumber"><?php _e('Phone Number', 'paid-memberships-pro'); ?></label>
                        <input id="AccountNumber" name="msisdn"
                               class="input <?php echo pmpro_getClassForField("AccountNumber"); ?>" type="text"
                               size="25" value="<?php echo esc_attr($AccountNumber) ?>" data-encrypted-name="msisdn"
                               autocomplete="off"/>
                    </div>

                </div> <!-- end pmpro_checkout-fields -->
                <?php if (!empty($sslseal)) { ?>
                <div class="pmpro_checkout-fields-rightcol pmpro_sslseal"><?php echo stripslashes($sslseal); ?></div>
            </div> <!-- end pmpro_checkout-fields-display-seal -->
        <?php } ?>
        </div> <!-- end pmpro_payment_information_fields -->
        <?php

        //don't include the default
        return false;
    }

    /**
     * Don't require the CVV, but look for cvv (lowercase) that braintree sends
     *
     */
    static function pmpro_required_billing_fields($fields)
    {
        $fields['msisdn'] = true;
        unset($fields["bfirstname"]);
        unset($fields["blastname"]);
        unset($fields["baddress1"]);
        unset($fields["bcity"]);
        unset($fields["bstate"]);
        unset($fields["bzipcode"]);
        unset($fields["bphone"]);
        unset($fields["bemail"]);
        unset($fields["bcountry"]);
        unset($fields["CardType"]);
        unset($fields["AccountNumber"]);
        unset($fields["ExpirationMonth"]);
        unset($fields["ExpirationYear"]);
        unset($fields["CVV"]);
        return $fields;
    }

    /**
     * Fields shown on edit user page
     *
     * @since 1.8
     */
    static function user_profile_fields($user)
    {
    }


    /**
     * Process fields from the edit user page
     *
     * @since 1.8
     */
    static function user_profile_fields_save($user_id)
    {
    }

    /**
     * Cron activation for subscription updates.
     *
     * @since 1.8
     */
    static function pmpro_activation()
    {
        wp_schedule_event(time(), 'daily', 'pmpro_cron_mpesa_subscription_updates');
    }

    /**
     * Cron deactivation for subscription updates.
     *
     * @since 1.8
     */
    static function pmpro_deactivation()
    {
        wp_clear_scheduled_hook('pmpro_cron_mpesa_subscription_updates');
    }

    /**
     * Cron job for subscription updates.
     *
     * @since 1.8
     */
    static function pmpro_cron_mpesa_subscription_updates()
    {
    }

    /**
     * Process checkout.
     *
     */
    function process(&$order)
    {
        //check for initial payment
        if (floatval($order->InitialPayment) == 0) {
            //auth first, then process
            if ($this->authorize($order)) {
                //$this->void($order);
                if (!pmpro_isLevelTrial($order->membership_level)) {
                    //subscription will start today with a 1 period trial
                    $order->ProfileStartDate = date_i18n("Y-m-d") . "T0:0:0";
                    $order->TrialBillingPeriod = $order->BillingPeriod;
                    $order->TrialBillingFrequency = $order->BillingFrequency;
                    $order->TrialBillingCycles = 1;
                    $order->TrialAmount = 0;

                    //add a billing cycle to make up for the trial, if applicable
                    if (!empty($order->TotalBillingCycles))
                        $order->TotalBillingCycles++;
                } elseif ($order->InitialPayment == 0 && $order->TrialAmount == 0) {
                    //it has a trial, but the amount is the same as the initial payment, so we can squeeze it in there
                    $order->ProfileStartDate = date_i18n("Y-m-d") . "T0:0:0";
                    $order->TrialBillingCycles++;

                    //add a billing cycle to make up for the trial, if applicable
                    if (!empty($order->TotalBillingCycles))
                        $order->TotalBillingCycles++;
                } else {
                    //add a period to the start date to account for the initial payment
                    $order->ProfileStartDate = date_i18n("Y-m-d", strtotime("+ " . $order->BillingFrequency . " " . $order->BillingPeriod, current_time("timestamp"))) . "T0:0:0";
                }

                $order->ProfileStartDate = apply_filters("pmpro_profile_start_date", $order->ProfileStartDate, $order);
                return $this->subscribe($order);
            } else {
                if (empty($order->error))
                    $order->error = __("Unknown error: Authorization failed.", 'paid-memberships-pro');
                return false;
            }
        } else {
            //charge first payment
            if ($this->charge($order)) {
                //set up recurring billing
                if (pmpro_isLevelRecurring($order->membership_level)) {
                    if (!pmpro_isLevelTrial($order->membership_level)) {
                        //subscription will start today with a 1 period trial
                        $order->ProfileStartDate = date_i18n("Y-m-d") . "T0:0:0";
                        $order->TrialBillingPeriod = $order->BillingPeriod;
                        $order->TrialBillingFrequency = $order->BillingFrequency;
                        $order->TrialBillingCycles = 1;
                        $order->TrialAmount = 0;

                        //add a billing cycle to make up for the trial, if applicable
                        if (!empty($order->TotalBillingCycles))
                            $order->TotalBillingCycles++;
                    } elseif ($order->InitialPayment == 0 && $order->TrialAmount == 0) {
                        //it has a trial, but the amount is the same as the initial payment, so we can squeeze it in there
                        $order->ProfileStartDate = date_i18n("Y-m-d") . "T0:0:0";
                        $order->TrialBillingCycles++;

                        //add a billing cycle to make up for the trial, if applicable
                        if (!empty($order->TotalBillingCycles))
                            $order->TotalBillingCycles++;
                    } else {
                        //add a period to the start date to account for the initial payment
                        $order->ProfileStartDate = date_i18n("Y-m-d", strtotime("+ " . $order->BillingFrequency . " " . $order->BillingPeriod, current_time("timestamp"))) . "T0:0:0";
                    }

                    $order->ProfileStartDate = apply_filters("pmpro_profile_start_date", $order->ProfileStartDate, $order);
                    if ($this->subscribe($order)) {
                        return true;
                    } else {
                        if ($this->void($order)) {
                            if (!$order->error)
                                $order->error = __("Unknown error: Payment failed.", 'paid-memberships-pro');
                        } else {
                            if (!$order->error)
                                $order->error = __("Unknown error: Payment failed.", 'paid-memberships-pro');
                            $order->error .= " " . __("A partial payment was made that we could not void. Please contact the site owner immediately to correct this.", 'paid-memberships-pro');
                        }

                        return false;
                    }
                } else {
                    //only a one time charge
                    $order->status = "success";    //saved on checkout page
                    return true;
                }
            } else {
                if (empty($order->error))
                    $order->error = __("Unknown error: Payment failed.", 'paid-memberships-pro');

                return false;
            }
        }
    }

    function authorize(&$order)
    {
        // because the initial payment is 0 shillings, we shall always return true
        if (empty($order->code))
            $order->code = $order->getRandomCode();

        //create a code for the order
        if (empty($order->code))
            $order->code = $order->getRandomCode();


        //simulate a successful authorization
        $order->payment_transaction_id = "mpesa_" . $order->code;
        $order->updateStatus("authorized");
        return true;

    }

    function charge(&$order)
    {
        if (empty($order->code))
            $order->code = $order->getRandomCode();

        //what amount to charge?
        $amount = $order->InitialPayment;

        //tax
        $order->subtotal = $amount;
        $tax = $order->getTax(true);
        $amount = round((float)$order->subtotal + (float)$tax, 2);


        //check db for transaction associated with phone_number
        global $wpdb;

        //to use account_number for paybills.
        $mpesa_msisdn = $order->mpesa_msisdn;
        $table_name = $wpdb->prefix . 'mpesa_pmpro';
        $total_amount_paid_by_msisdn = $wpdb->get_var("SELECT SUM(amount) AS total_amount FROM $table_name WHERE msisdn=$mpesa_msisdn AND order_id=-1;");

        if ($total_amount_paid_by_msisdn >= $amount) {
            //payment successful
            //todo use-mpesa-transaction_id
            $order->payment_transaction_id = "MPESA_" . $order->getRandomCode();;
            $order->updateStatus("success");

            // update mpesa transactions table
            $wpdb->query($wpdb->prepare("UPDATE $table_name 
                SET order_id = %s 
             WHERE msisdn = %s AND order_id=-1", $order->code, $mpesa_msisdn)
            );
            return true;
        } else {
            // the amount is not fully paid return error to checkout page

            if ($total_amount_paid_by_msisdn > 0) {
                //partial payment
                global $pmpro_error_fields;
                $balance_amount = $amount - $total_amount_paid_by_msisdn;
                $pmpro_error_fields["partial_payment"] = $total_amount_paid_by_msisdn;
                $pmpro_error_fields["balance_amount"] = $balance_amount;
                $message = sprintf("Received KES %s, please pay KES %s to complete the subscription.",
                    $total_amount_paid_by_msisdn, $balance_amount);
            } else {
                //no money received
                $message = sprintf("No payment has been received from the msisdn %s.", $mpesa_msisdn);
            }

            //$order->status = "error";
            $order->errorcode = "transaction failed 1";
            $order->error = $message;
            $order->shorterror = "transaction failed 3";
            return false;

        }

    }

    function subscribe(&$order)
    {
        //create a code for the order
        if (empty($order->code))
            $order->code = $order->getRandomCode();

        //filter order before subscription. use with care.
        $order = apply_filters("pmpro_subscribe_order", $order, $this);

        //code to setup a recurring subscription with the gateway and test results would go here

        //simulate a successful subscription processing
        $order->status = "success";
        $order->subscription_transaction_id = "mpesa" . $order->code;
        return true;
    }

    function pmpro_mpesa_ipn_listener() {
        // check for your custom query var
        if ( ! isset( $_GET['pmpro_mpesa_ipn'] ) ) {
            // if query var is not present just return
            return;
        }

        // validate_payload
        //
        // do the processsing here

        // don't forget to exit when you are done
        exit;
    }


}
