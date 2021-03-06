<?php
//load classes init method
add_action('init', array('PMProGateway_mpesa', 'init'));


global $mpesa_db_version;
$mpesa_db_version = '1.0';

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
        global $pmpro_currencies;
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
        add_action('init', 'pmpro_mpesa_ipn_listener');
        add_action('init', 'mpesa_url_registration');
        add_action('init', 'simulate_c2b');



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
        }

        $pmpro_currencies = array(
            'USD' => __('US Dollars (&#36;)', 'paid-memberships-pro' ),
            'EUR' => array(
                'name' => __('Euros (&euro;)', 'paid-memberships-pro' ),
                'symbol' => '&euro;',
                'position' => apply_filters("pmpro_euro_position", pmpro_euro_position_from_locale())
            ),
            'GBP' => array(
                'name' => __('Pounds Sterling (&pound;)', 'paid-memberships-pro' ),
                'symbol' => '&pound;',
                'position' => 'left'
            ),
            'ARS' => __('Argentine Peso (&#36;)', 'paid-memberships-pro' ),
            'AUD' => __('Australian Dollars (&#36;)', 'paid-memberships-pro' ),
            'BRL' => array(
                'name' => __('Brazilian Real (R&#36;)', 'paid-memberships-pro' ),
                'symbol' => 'R&#36;',
                'position' => 'left'
            ),
            'CAD' => __('Canadian Dollars (&#36;)', 'paid-memberships-pro' ),
            'CNY' => __('Chinese Yuan', 'paid-memberships-pro' ),
            'CZK' => array(
                'name' => __('Czech Koruna', 'paid-memberships-pro' ),
                'decimals' => '0',
                'thousands_separator' => '&nbsp;',
                'decimal_separator' => ',',
                'symbol' => '&nbsp;Kč',
                'position' => 'right',
            ),
            'DKK' => __('Danish Krone', 'paid-memberships-pro' ),
            'HKD' => __('Hong Kong Dollar (&#36;)', 'paid-memberships-pro' ),
            'HUF' => __('Hungarian Forint', 'paid-memberships-pro' ),
            'INR' => __('Indian Rupee', 'paid-memberships-pro' ),
            'IDR' => __('Indonesia Rupiah', 'paid-memberships-pro' ),
            'ILS' => __('Israeli Shekel', 'paid-memberships-pro' ),
            'JPY' => array(
                'name' => __('Japanese Yen (&yen;)', 'paid-memberships-pro' ),
                'symbol' => '&yen;',
                'position' => 'right',
                'decimals' => 0,
            ),
            'KES' => __('Kenyan Shillings', 'paid-memberships-pro' ),
            'MYR' => __('Malaysian Ringgits', 'paid-memberships-pro' ),
            'MXN' => __('Mexican Peso (&#36;)', 'paid-memberships-pro' ),
            'NGN' => __('Nigerian Naira (&#8358;)', 'paid-memberships-pro' ),
            'NZD' => __('New Zealand Dollar (&#36;)', 'paid-memberships-pro' ),
            'NOK' => __('Norwegian Krone', 'paid-memberships-pro' ),
            'PHP' => __('Philippine Pesos', 'paid-memberships-pro' ),
            'PLN' => __('Polish Zloty', 'paid-memberships-pro' ),
            'RUB' => array(
                'name' => __('Russian Ruble (&#8381;)', 'paid-memberships-pro'),
                'symbol' => '&#8381;',
                'position' => 'right'
            ),
            'SGD' => array(
                'name' => __('Singapore Dollar (&#36;)', 'paid-memberships-pro' ),
                'symbol' => '&#36;',
                'position' => 'right'
            ),
            'ZAR' => array(
                'name' => __('South African Rand (R)', 'paid-memberships-pro' ),
                'symbol' => 'R ',
                'position' => 'left'
            ),
            'KRW' => array(
                'name' => __('South Korean Won', 'paid-memberships-pro' ),
                'decimals' => 0,
            ),
            'SEK' => __('Swedish Krona', 'paid-memberships-pro' ),
            'CHF' => __('Swiss Franc', 'paid-memberships-pro' ),
            'TWD' => __('Taiwan New Dollars', 'paid-memberships-pro' ),
            'THB' => __('Thai Baht', 'paid-memberships-pro' ),
            'TRY' => __('Turkish Lira', 'paid-memberships-pro' ),
            'VND' => array(
                'name' => __('Vietnamese Dong', 'paid-memberships-pro' ),
                'decimals' => 0,
            ),
        );

        $pmpro_currencies = apply_filters("pmpro_currencies", $pmpro_currencies);


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
            'mpesa_short_code',
            'pmpro_mpesa_uid',
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

        <?php

        $gateway_environment = pmpro_getOption("gateway_environment");

        if($gateway_environment == "live"){

            ?>

            <tr class="gateway gateway_mpesa" <?php if ($gateway != "mpesa") { ?>style="display: none;"<?php } ?>>
                <th scope="row" valign="top">
                    <label for="rrr"><?php _e('Confirmation URL Registration Production', 'paid-memberships-pro'); ?>:</label>
                </th>


                <td>
                    <?php
                    if (pmpro_getOption("pmpro_mpesa_url_reg_status_production") != 1) {
                        $message = "Not Registered: <a href=\"".home_url( '/?mpesa_url_registration=live')."\" target=\"_blank\">Click here to register confirmation URL</a>";
                    }else{
                        $message = "Confirmation URL Registered: <a href=\"".home_url( '/?mpesa_url_registration=live')."\" target=\"_blank\">Click here to register confirmation URL again</a>";

                    }

                    echo $message;

                    ?>
                </td>


            </tr>
            <?php

        } else{
            ?>
            <tr class="gateway gateway_mpesa" <?php if ($gateway != "mpesa") { ?>style="display: none;"<?php } ?>>
                <th scope="row" valign="top">
                    <label for="rrr"><?php _e('Confirmation URL Registration Sandbox', 'paid-memberships-pro'); ?>:</label>
                </th>


                <td>
                    <?php
                    if (pmpro_getOption("pmpro_mpesa_url_reg_status_production") != 1) {
                        $message = "Not Registered: <a href=\"".home_url( '/?mpesa_url_registration=sandbox')."\" target=\"_blank\">Click here to register confirmation URL</a>";
                    }else{
                        $message = "Confirmation URL Registered: <a href=\"".home_url( '/?mpesa_url_registration=sandbox')."\" target=\"_blank\">Click here to register confirmation URL again</a>";

                    }

                    echo $message;

                    ?>
                </td>


            </tr>

            <?php
        }

        ?>

        <tr class="gateway gateway_mpesa" <?php if ($gateway != "mpesa") { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label for="mpesa_short_code"><?php _e('Short code (paybill/till number)', 'paid-memberships-pro'); ?>:</label>
            </th>
            <td>
                <input type="text" id="mpesa_short_code" name="mpesa_short_code" size="60"
                       value="<?php echo esc_attr($values['mpesa_short_code']) ?>"/>
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
        <tr class="gateway gateway_mpesa" <?php if ($gateway != "mpesa") { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label for="pmpro_mpesa_uid"><?php _e('pmpro-mpesa-gateway secret uid', 'paid-memberships-pro'); ?></label>
            </th>
            <td>
                <?php echo esc_attr($values['pmpro_mpesa_uid']); ?>
                <input type="text" hidden id="pmpro_mpesa_uid" name="pmpro_mpesa_uid" size="60"
                       value="<?php if (!empty($values['pmpro_mpesa_uid'])) {
                           echo esc_attr($values['pmpro_mpesa_uid']);
                       } else {
                           echo wp_generate_uuid4();
                       } ?>"/>
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
        global $pmpro_requirebilling, $msisdn, $pmpro_level, $pmpro_error_fields;

        ?>
        <div id="pmpro_payment_information_fields" class="pmpro_checkout"
             <?php if (!$pmpro_requirebilling || apply_filters("pmpro_hide_payment_information_fields", false)) { ?>style="display: none;"<?php } ?>>
            <h3>
                <span class="pmpro_checkout-h3-name"><?php _e('Payment Information', 'paid-memberships-pro'); ?></span>
                <?php

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
                        <input id="AccountNumber" required name="msisdn"
                               class="input <?php echo pmpro_getClassForField("msisdn"); ?>" type="text"
                               size="25" value="<?php echo esc_attr($msisdn) ?>" data-encrypted-name="msisdn"
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
        $mpesa_msisdn = str_replace("07", "2547", $mpesa_msisdn);
        $mpesa_msisdn = str_replace("+", "", $mpesa_msisdn);
        $mpesa_msisdn = trim($mpesa_msisdn);
        $table_name = $wpdb->prefix . 'pmpro_mpesa';
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


}


function mpesa_install()
{
    global $wpdb;
    global $mpesa_db_version;

    $table_name = $wpdb->prefix . 'pmpro_mpesa';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
		id bigint PRIMARY KEY NOT NULL AUTO_INCREMENT,
        msisdn varchar(20) NOT NULL,
        time datetime DEFAULT CURRENT_TIMESTAMP,
        user_id varchar(255),
        amount float NOT NULL,
        order_id varchar(255) NOT NULL DEFAULT -1,
        payload longtext,
        mpesa_transaction_id varchar(50)
	  ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    add_option('mpesa_db_version', $mpesa_db_version);
}

function pmpro_mpesa_ipn_listener()
{
    // check for your custom query var
    if (!isset($_GET['pmpro_mpesa_ipn'])) {
        // if query var is not present just return

        return;

    }

    if (!isset($_GET['uid'])) {

        $_400_response = Array(
            "status" => "error",
            "message" => "uid not set"
        );

        echo(json_encode($_400_response));
        //return;
    }
    print(pmpro_getOption("pmrpo_mpesa_uid"));
    if (2 ==  5) {
        $_403_response = Array(
            "status" => "error",
            "message" => "uid invalid"
        );

        echo(json_encode($_403_response));
        //return;
    }

    // todo validate request is from mpesa using IP address
    // todo validate_payload
    c2b_confirmation_request();
    echo("{
	\"ResultDesc\":\"Validation Service request accepted succesfully\",
	\"ResultCode\":\"0\"
}");
    exit;
}


function c2b_confirmation_request()
{
    $callbackJSONData = file_get_contents('php://input');
    $callbackData = json_decode($callbackJSONData);
    $transaction_id = $callbackData->TransID;
    $transaction_amount = $callbackData->TransAmount;
    $msisdn = $callbackData->MSISDN;

    $payload = $callbackJSONData;
    global $wpdb;

    //to use account_number for paybills.
    $table_name = $wpdb->prefix . 'pmpro_mpesa';
    $sql_string = sprintf("SELECT COUNT(*) FROM %s WHERE mpesa_transaction_id='%s'", $table_name, $transaction_id);
    $transaction_exists = $wpdb->get_var($sql_string);
    if (!empty($transaction_exists)) {
        return false;
    } else {
        //save transaction in db
        $insert_query = sprintf("INSERT INTO %s (msisdn, amount, payload, mpesa_transaction_id) VALUES (%s, %s, '%s','%s');", $table_name, $msisdn, $transaction_amount, $payload, $transaction_id);
        $wpdb->query($insert_query);
        // todo confirm result of the query
        return true;
    }

}

function mpesa_authorize()
{
    $api_key = pmpro_getOption("mpesa_api_key");
    $secret_key = pmpro_getOption("mpesa_secret_key");
    $gateway_environment = pmpro_getOption("gateway_environment");
    $endpoint = ( $gateway_environment == 'live' ) ? 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' : 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    $credentials = base64_encode( $api_key.':'.$secret_key );
    $curl = curl_init();
    curl_setopt( $curl, CURLOPT_URL, $endpoint );
    curl_setopt( $curl, CURLOPT_HTTPHEADER, array( 'Authorization: Basic '.$credentials ) );
    curl_setopt( $curl, CURLOPT_HEADER, false );
    curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
    curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, false );
    $curl_response = curl_exec( $curl );
    print("pala");
    print_r($curl_response);
    return json_decode( $curl_response )->access_token;
}

/**
 * Register confirmation endpoint
 */
function mpesa_url_registration()
{

    // check for your custom query var
    if (!isset($_GET['mpesa_url_registration'])) {
        // if query var is not present just return

        return;

    }
    $token = mpesa_authorize();
    $gateway_environment = pmpro_getOption("gateway_environment");
    $short_code = pmpro_getOption("mpesa_short_code");
    $mpesa_uid = pmpro_getOption("pmpro_mpesa_uid");
    $comfirmation_url = home_url( '/?pmpro_mpesa_ipn=1&uid='.$mpesa_uid);

    $endpoint = ( $gateway_environment == 'live' ) ? 'https://api.safaricom.co.ke/mpesa/c2b/v1/registerurl' : 'https://sandbox.safaricom.co.ke/mpesa/c2b/v1/registerurl';
    $curl = curl_init();
    curl_setopt( $curl, CURLOPT_URL, $endpoint );
    curl_setopt( $curl, CURLOPT_HTTPHEADER, array( 'Content-Type:application/json','Authorization:Bearer '.$token ) );

    $curl_post_data = array(
        'ShortCode' 		=> $short_code,
        'ResponseType' 		=> 'Completed',
        'ConfirmationURL' 	=> $comfirmation_url,
        'ValidationURL' 	=> $comfirmation_url."&validation=1"
    );
    print_r($curl_post_data);
    $data_string = json_encode( $curl_post_data );
    curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $curl, CURLOPT_POST, true );
    curl_setopt( $curl, CURLOPT_POSTFIELDS, $data_string );
    curl_setopt( $curl, CURLOPT_HEADER, false );
    $content = curl_exec( $curl );
    if ( $content ) {
        $msg = json_decode( $content );
        $status = isset( $msg->ResponseDescription ) ? $msg->ResponseDescription : "Coud not register URLs";
    } else {
        $status = "Sorry could not connect to Daraja. Check your configuration and try again.";
    }
    print_r( array( 'Registration status' => $status ));
    exit;

}

function simulate_c2b(){

    // check for your custom query var
    if (!isset($_GET['simulate_c2b'])) {
        // if query var is not present just return

        return;

    }
    $url = 'https://sandbox.safaricom.co.ke/mpesa/c2b/v1/simulate';
    $token = mpesa_authorize();
    $short_code = pmpro_getOption("mpesa_short_code");


    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt( $curl, CURLOPT_HTTPHEADER, array( 'Content-Type:application/json','Authorization:Bearer '.$token ) );

    $curl_post_data = array(
        //Fill in the request parameters with valid values
        'ShortCode' => $short_code,
        'CommandID' => 'CustomerPayBillOnline',
        'Amount' => '200',
        'Msisdn' => '254708374149',
        'BillRefNumber' => 'ioioio'
    );

    $data_string = json_encode($curl_post_data);

    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);

    $curl_response = curl_exec($curl);
    print_r($curl_response);

    echo $curl_response;
    exit;
}
