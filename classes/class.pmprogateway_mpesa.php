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
        <tr class="gateway gateway_mpesa" <?php if ($gateway != "mpesa") { ?>style="display: none;"<?php } ?>>
            <th scope="row" valign="top">
                <label><?php _e('Web Hook URL', 'paid-memberships-pro'); ?>:</label>
            </th>
            <td>
                <p>
                    <?php _e('To fully integrate with mpesa, be sure to set your Web Hook URL to', 'paid-memberships-pro'); ?>
                <pre><?php
                    //echo admin_url("admin-ajax.php") . "?action=mpesa_webhook";
                    echo add_query_arg('action', 'mpesa_webhook', admin_url('admin-ajax.php'));
                    ?></pre>
                </p>
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
        if(isset($_REQUEST['msisdn']))
            $mpesa_msisdn = sanitize_text_field($_REQUEST['number']);
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
        global $pmpro_requirebilling, $pmpro_show_discount_code, $discount_code, $CardType, $AccountNumber, $ExpirationMonth, $ExpirationYear, $current_user, $morder, $order_id, $pmpro_level;

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

                print("<pre>");
                $amount =$pmpro_level->initial_payment;

                print("</pre>");
                ?>
                <span class="pmpro_checkout-h3-name"><?php printf(__('To pay, go to mpesa and pay %s to till number %s'), $amount, "11111111"); ?></span>
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
        unset($fields['CVV']);
        unset($fields['bfirstname']);
        unset($fields['blastname']);
        unset($fields['baddress1']);
        unset($fields['baddress2']);
        unset($fields['bcity']);
        unset($fields['bstate']);
        unset($fields['bzipcode']);
        unset($fields['bcountry']);
        unset($fields['bphone']);
        $fields['msisdn'] = true;
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
        if(floatval($order->InitialPayment) == 0)
        {
            //auth first, then process
            if($this->authorize($order))
            {
                $this->void($order);
                if(!pmpro_isLevelTrial($order->membership_level))
                {
                    //subscription will start today with a 1 period trial
                    $order->ProfileStartDate = date_i18n("Y-m-d") . "T0:0:0";
                    $order->TrialBillingPeriod = $order->BillingPeriod;
                    $order->TrialBillingFrequency = $order->BillingFrequency;
                    $order->TrialBillingCycles = 1;
                    $order->TrialAmount = 0;

                    //add a billing cycle to make up for the trial, if applicable
                    if(!empty($order->TotalBillingCycles))
                        $order->TotalBillingCycles++;
                }
                elseif($order->InitialPayment == 0 && $order->TrialAmount == 0)
                {
                    //it has a trial, but the amount is the same as the initial payment, so we can squeeze it in there
                    $order->ProfileStartDate = date_i18n("Y-m-d") . "T0:0:0";
                    $order->TrialBillingCycles++;

                    //add a billing cycle to make up for the trial, if applicable
                    if(!empty($order->TotalBillingCycles))
                        $order->TotalBillingCycles++;
                }
                else
                {
                    //add a period to the start date to account for the initial payment
                    $order->ProfileStartDate = date_i18n("Y-m-d", strtotime("+ " . $order->BillingFrequency . " " . $order->BillingPeriod, current_time("timestamp"))) . "T0:0:0";
                }

                $order->ProfileStartDate = apply_filters("pmpro_profile_start_date", $order->ProfileStartDate, $order);
                return $this->subscribe($order);
            }
            else
            {
                if(empty($order->error))
                    $order->error = __("Unknown error: Authorization failed.", 'paid-memberships-pro' );
                return false;
            }
        }
        else
        {
            //charge first payment
            if($this->charge($order))
            {
                //set up recurring billing
                if(pmpro_isLevelRecurring($order->membership_level))
                {
                    if(!pmpro_isLevelTrial($order->membership_level))
                    {
                        //subscription will start today with a 1 period trial
                        $order->ProfileStartDate = date_i18n("Y-m-d") . "T0:0:0";
                        $order->TrialBillingPeriod = $order->BillingPeriod;
                        $order->TrialBillingFrequency = $order->BillingFrequency;
                        $order->TrialBillingCycles = 1;
                        $order->TrialAmount = 0;

                        //add a billing cycle to make up for the trial, if applicable
                        if(!empty($order->TotalBillingCycles))
                            $order->TotalBillingCycles++;
                    }
                    elseif($order->InitialPayment == 0 && $order->TrialAmount == 0)
                    {
                        //it has a trial, but the amount is the same as the initial payment, so we can squeeze it in there
                        $order->ProfileStartDate = date_i18n("Y-m-d") . "T0:0:0";
                        $order->TrialBillingCycles++;

                        //add a billing cycle to make up for the trial, if applicable
                        if(!empty($order->TotalBillingCycles))
                            $order->TotalBillingCycles++;
                    }
                    else
                    {
                        //add a period to the start date to account for the initial payment
                        $order->ProfileStartDate = date_i18n("Y-m-d", strtotime("+ " . $order->BillingFrequency . " " . $order->BillingPeriod, current_time("timestamp"))) . "T0:0:0";
                    }

                    $order->ProfileStartDate = apply_filters("pmpro_profile_start_date", $order->ProfileStartDate, $order);
                    if($this->subscribe($order))
                    {
                        return true;
                    }
                    else
                    {
                        if($this->void($order))
                        {
                            if(!$order->error)
                                $order->error = __("Unknown error: Payment failed.", 'paid-memberships-pro' );
                        }
                        else
                        {
                            if(!$order->error)
                                $order->error = __("Unknown error: Payment failed.", 'paid-memberships-pro' );
                            $order->error .= " " . __("A partial payment was made that we could not void. Please contact the site owner immediately to correct this.", 'paid-memberships-pro' );
                        }

                        return false;
                    }
                }
                else
                {
                    //only a one time charge
                    $order->status = "success";	//saved on checkout page
                    return true;
                }
            }
            else
            {
                if(empty($order->error))
                    $order->error = __("Unknown error: Payment failed.", 'paid-memberships-pro' );

                return false;
            }
        }
    }

    function authorize(&$order)
    {
        if(empty($order->code))
            $order->code = $order->getRandomCode();

        if(empty($order->gateway_environment))
            $gateway_environment = pmpro_getOption("gateway_environment");
        else
            $gateway_environment = $order->gateway_environment;
        if($gateway_environment == "live")
            $host = "secure.authorize.net";
        else
            $host = "test.authorize.net";

        //check db for transaction associated with phone_number
        global $wpdb;

        //to use account_number for paybills.
        $mpesa_msisdn = $order->mpesa_msisdn;
        $table_name = $wpdb->prefix . 'mpesa_pmpro';
        $total_amount_paid_by_msisdn = $wpdb->get_var( "SELECT SUM(amount) AS total_amount FROM $table_name WHERE msisdn=$mpesa_msisdn AND order_id=-1;" );

        $path = "/gateway/transact.dll";
        $post_url = "https://" . $host . $path;

        $post_url = apply_filters("pmpro_authorizenet_post_url", $post_url, $gateway_environment);

        //what amount to authorize? just $1 to test.
        $amount = "1.00";

        //combine address
        $address = $order->Address1;
        if(!empty($order->Address2))
            $address .= "\n" . $order->Address2;

        //customer stuff
        $customer_email = $order->Email;
        $customer_phone = $order->billing->phone;

        if(!isset($order->membership_level->name))
            $order->membership_level->name = "";

        $post_values = array(

            // the API Login ID and Transaction Key must be replaced with valid values
            "x_login"			=> pmpro_getOption("loginname"),
            "x_tran_key"		=> pmpro_getOption("transactionkey"),

            "x_version"			=> "3.1",
            "x_delim_data"		=> "TRUE",
            "x_delim_char"		=> "|",
            "x_relay_response"	=> "FALSE",

            "x_type"			=> "AUTH_ONLY",
            "x_method"			=> "CC",
            "x_card_type"		=> $order->cardtype,
            "x_card_num"		=> $order->accountnumber,
            "x_exp_date"		=> $order->ExpirationDate,

            "x_amount"			=> $amount,
            "x_description"		=> $order->membership_level->name . " " . __("Membership", 'paid-memberships-pro' ),

            "x_first_name"		=> $order->FirstName,
            "x_last_name"		=> $order->LastName,
            "x_address"			=> $address,
            "x_city"			=> $order->billing->city,
            "x_state"			=> $order->billing->state,
            "x_zip"				=> $order->billing->zip,
            "x_country"			=> $order->billing->country,
            "x_invoice_num"		=> $order->code,
            "x_phone"			=> $customer_phone,
            "x_email"			=> $order->Email
            // Additional fields can be added here as outlined in the AIM integration
            // guide at: http://developer.authorize.net
        );

        if(!empty($order->CVV2))
            $post_values["x_card_code"] = $order->CVV2;

        // This section takes the input fields and converts them to the proper format
        // for an http post.  For example: "x_login=username&x_tran_key=a1B2c3D4"
        $post_string = "";
        foreach( $post_values as $key => $value )
        { $post_string .= "$key=" . urlencode( str_replace("#", "%23", $value) ) . "&"; }
        $post_string = rtrim( $post_string, "& " );

        //curl
        $request = curl_init($post_url); // initiate curl object
        curl_setopt($request, CURLOPT_HEADER, 0); // set to 0 to eliminate header info from response
        curl_setopt($request, CURLOPT_RETURNTRANSFER, 1); // Returns response data instead of TRUE(1)
        curl_setopt($request, CURLOPT_POSTFIELDS, $post_string); // use HTTP POST to send form data
        curl_setopt($request, CURLOPT_SSL_VERIFYPEER, FALSE); // uncomment this line if you get no gateway response.
        curl_setopt($request, CURLOPT_USERAGENT, PMPRO_USER_AGENT); // setting the user agent
        $post_response = curl_exec($request); // execute curl post and store results in $post_response
        // additional options may be required depending upon your server configuration
        // you can find documentation on curl options at http://www.php.net/curl_setopt
        curl_close ($request); // close curl object

        // This line takes the response and breaks it into an array using the specified delimiting character
        $response_array = explode($post_values["x_delim_char"],$post_response);

        if($response_array[0] == 1)
        {
            $order->payment_transaction_id = $response_array[6];
            $order->updateStatus("authorized");

            return true;
        }
        else
        {
            //$order->status = "error";
            $order->errorcode = $response_array[2];
            $order->error = $response_array[3];
            $order->shorterror = $response_array[3];
            return false;
        }
    }

    function void(&$order)
    {
        if(empty($order->payment_transaction_id))
            return false;

        if(empty($order->gateway_environment))
            $gateway_environment = pmpro_getOption("gateway_environment");
        else
            $gateway_environment = $order->gateway_environment;
        if($gateway_environment == "live")
            $host = "secure.authorize.net";
        else
            $host = "test.authorize.net";

        $path = "/gateway/transact.dll";
        $post_url = "https://" . $host . $path;

        $post_url = apply_filters("pmpro_authorizenet_post_url", $post_url, $gateway_environment);

        $post_values = array(

            // the API Login ID and Transaction Key must be replaced with valid values
            "x_login"			=> pmpro_getOption("loginname"),
            "x_tran_key"		=> pmpro_getOption("transactionkey"),

            "x_version"			=> "3.1",
            "x_delim_data"		=> "TRUE",
            "x_delim_char"		=> "|",
            "x_relay_response"	=> "FALSE",

            "x_type"			=> "VOID",
            "x_trans_id"			=> $order->payment_transaction_id
            // Additional fields can be added here as outlined in the AIM integration
            // guide at: http://developer.authorize.net
        );

        // This section takes the input fields and converts them to the proper format
        // for an http post.  For example: "x_login=username&x_tran_key=a1B2c3D4"
        $post_string = "";
        foreach( $post_values as $key => $value )
        { $post_string .= "$key=" . urlencode( str_replace("#", "%23", $value) ) . "&"; }
        $post_string = rtrim( $post_string, "& " );

        //curl
        $request = curl_init($post_url); // initiate curl object
        curl_setopt($request, CURLOPT_HEADER, 0); // set to 0 to eliminate header info from response
        curl_setopt($request, CURLOPT_RETURNTRANSFER, 1); // Returns response data instead of TRUE(1)
        curl_setopt($request, CURLOPT_POSTFIELDS, $post_string); // use HTTP POST to send form data
        curl_setopt($request, CURLOPT_SSL_VERIFYPEER, FALSE); // uncomment this line if you get no gateway response.
        $post_response = curl_exec($request); // execute curl post and store results in $post_response
        // additional options may be required depending upon your server configuration
        // you can find documentation on curl options at http://www.php.net/curl_setopt
        curl_close ($request); // close curl object

        // This line takes the response and breaks it into an array using the specified delimiting character
        $response_array = explode($post_values["x_delim_char"],$post_response);
        if($response_array[0] == 1)
        {
            $order->payment_transaction_id = $response_array[4];
            $order->updateStatus("voided");
            return true;
        }
        else
        {
            //$order->status = "error";
            $order->errorcode = $response_array[2];
            $order->error = $response_array[3];
            $order->shorterror = $response_array[3];
            return false;
        }
    }

    function charge(&$order)
    {
        if(empty($order->code))
            $order->code = $order->getRandomCode();

        if(!empty($order->gateway_environment))
            $gateway_environment = $order->gateway_environment;
        if(empty($gateway_environment))
            $gateway_environment = pmpro_getOption("gateway_environment");
        if($gateway_environment == "live")
            $host = "secure.authorize.net";
        else
            $host = "test.authorize.net";

        $path = "/gateway/transact.dll";
        $post_url = "https://" . $host . $path;

        $post_url = apply_filters("pmpro_authorizenet_post_url", $post_url, $gateway_environment);

        //what amount to charge?
        $amount = $order->InitialPayment;

        //tax
        $order->subtotal = $amount;
        $tax = $order->getTax(true);
        $amount = round((float)$order->subtotal + (float)$tax, 2);

        //combine address
        $address = $order->Address1;
        if(!empty($order->Address2))
            $address .= "\n" . $order->Address2;

        //customer stuff
        $customer_email = $order->Email;
        $customer_phone = $order->billing->phone;

        if(!isset($order->membership_level->name))
            $order->membership_level->name = "";

        $post_values = array(

            // the API Login ID and Transaction Key must be replaced with valid values
            "x_login"			=> pmpro_getOption("loginname"),
            "x_tran_key"		=> pmpro_getOption("transactionkey"),

            "x_version"			=> "3.1",
            "x_delim_data"		=> "TRUE",
            "x_delim_char"		=> "|",
            "x_relay_response"	=> "FALSE",

            "x_type"			=> "AUTH_CAPTURE",
            "x_method"			=> "CC",
            "x_card_type"		=> $order->cardtype,
            "x_card_num"		=> $order->accountnumber,
            "x_exp_date"		=> $order->ExpirationDate,

            "x_amount"			=> $amount,
            "x_tax"				=> $tax,
            "x_description"		=> $order->membership_level->name . " Membership",

            "x_first_name"		=> $order->FirstName,
            "x_last_name"		=> $order->LastName,
            "x_address"			=> $address,
            "x_city"			=> $order->billing->city,
            "x_state"			=> $order->billing->state,
            "x_zip"				=> $order->billing->zip,
            "x_country"			=> $order->billing->country,
            "x_invoice_num"		=> $order->code,
            "x_phone"			=> $customer_phone,
            "x_email"			=> $order->Email

            // Additional fields can be added here as outlined in the AIM integration
            // guide at: http://developer.authorize.net
        );

        if(!empty($order->CVV2))
            $post_values["x_card_code"] = $order->CVV2;

        // This section takes the input fields and converts them to the proper format
        // for an http post.  For example: "x_login=username&x_tran_key=a1B2c3D4"
        $post_string = "";
        foreach( $post_values as $key => $value )
        { $post_string .= "$key=" . urlencode( str_replace("#", "%23", $value) ) . "&"; }
        $post_string = rtrim( $post_string, "& " );

        //curl
        $request = curl_init($post_url); // initiate curl object
        curl_setopt($request, CURLOPT_HEADER, 0); // set to 0 to eliminate header info from response
        curl_setopt($request, CURLOPT_RETURNTRANSFER, 1); // Returns response data instead of TRUE(1)
        curl_setopt($request, CURLOPT_POSTFIELDS, $post_string); // use HTTP POST to send form data
        curl_setopt($request, CURLOPT_SSL_VERIFYPEER, FALSE); // uncomment this line if you get no gateway response.
        $post_response = curl_exec($request); // execute curl post and store results in $post_response
        // additional options may be required depending upon your server configuration
        // you can find documentation on curl options at http://www.php.net/curl_setopt
        curl_close ($request); // close curl object

        // This line takes the response and breaks it into an array using the specified delimiting character
        $response_array = explode($post_values["x_delim_char"],$post_response);
        if($response_array[0] == 1)
        {
            $order->payment_transaction_id = $response_array[6];
            $order->updateStatus("success");
            return true;
        }
        else
        {
            //$order->status = "error";
            $order->errorcode = $response_array[2];
            $order->error = $response_array[3];
            $order->shorterror = $response_array[3];
            return false;
        }
    }

    function subscribe(&$order)
    {
        //define variables to send

        if(empty($order->code))
            $order->code = $order->getRandomCode();

        //filter order before subscription. use with care.
        $order = apply_filters("pmpro_subscribe_order", $order, $this);

        if(!empty($order->gateway_environment))
            $gateway_environment = $order->gateway_environment;
        if(empty($gateway_environment))
            $gateway_environment = pmpro_getOption("gateway_environment");
        if($gateway_environment == "live")
            $host = "api.authorize.net";
        else
            $host = "apitest.authorize.net";

        $path = "/xml/v1/request.api";

        $loginname = pmpro_getOption("loginname");
        $transactionkey = pmpro_getOption("transactionkey");

        $amount = $order->PaymentAmount;
        $refId = $order->code;
        $name = $order->membership_name;
        $length = (int)$order->BillingFrequency;

        if($order->BillingPeriod == "Month")
            $unit = "months";
        elseif($order->BillingPeriod == "Day")
            $unit = "days";
        elseif($order->BillingPeriod == "Year" && $order->BillingFrequency == 1)
        {
            $unit = "months";
            $length = 12;
        }
        elseif($order->BillingPeriod == "Week")
        {
            $unit = "days";
            $length = $length * 7;	//converting weeks to days
        }
        else
            return false;	//authorize.net only supports months and days

        $startDate = substr($order->ProfileStartDate, 0, 10);
        if(!empty($order->TotalBillingCycles))
            $totalOccurrences = (int)$order->TotalBillingCycles;
        if(empty($totalOccurrences))
            $totalOccurrences = 9999;
        if(isset($order->TrialBillingCycles))
            $trialOccurrences = (int)$order->TrialBillingCycles;
        else
            $trialOccurrences = 0;
        if(isset($order->TrialAmount))
            $trialAmount = $order->TrialAmount;
        else
            $trialAmount = NULL;

        //taxes
        $amount_tax = $order->getTaxForPrice($amount);
        $trial_tax = $order->getTaxForPrice($trialAmount);

        $amount = round((float)$amount + (float)$amount_tax, 2);
        $trialAmount = round((float)$trialAmount + (float)$trial_tax, 2);

        //authorize.net doesn't support different periods between trial and actual

        if(!empty($order->TrialBillingPeriod) && $order->TrialBillingPeriod != $order->BillingPeriod)
        {
            echo "F";
            return false;
        }

        $cardNumber = $order->accountnumber;
        $expirationDate = $order->ExpirationDate_YdashM;
        $cardCode = $order->CVV2;

        $firstName = $order->FirstName;
        $lastName = $order->LastName;

        //do address stuff then?
        $address = $order->Address1;
        if(!empty($order->Address2))
            $address .= "\n" . $order->Address2;
        $city = $order->billing->city;
        $state = $order->billing->state;
        $zip = $order->billing->zip;
        $country = $order->billing->country;

        //customer stuff
        $customer_email = $order->Email;
        if(strpos($order->billing->phone, "+") === false)
            $customer_phone = $order->billing->phone;
        else
            $customer_phone = "";

        //make sure the phone is in an okay format
        $customer_phone = preg_replace("/[^0-9]/", "", $customer_phone);
        if(strlen($customer_phone) > 10)
            $customer_phone = "";

        //build xml to post
        $this->content =
            "<?xml version=\"1.0\" encoding=\"utf-8\"?>" .
            "<ARBCreateSubscriptionRequest xmlns=\"AnetApi/xml/v1/schema/AnetApiSchema.xsd\">" .
            "<merchantAuthentication>".
            "<name>" . $loginname . "</name>".
            "<transactionKey>" . $transactionkey . "</transactionKey>".
            "</merchantAuthentication>".
            "<refId><![CDATA[" . substr($refId, 0, 20) . "]]></refId>".
            "<subscription>".
            "<name><![CDATA[" . substr($name, 0, 50) . "]]></name>".
            "<paymentSchedule>".
            "<interval>".
            "<length>". $length ."</length>".
            "<unit>". $unit ."</unit>".
            "</interval>".
            "<startDate>" . $startDate . "</startDate>".
            "<totalOccurrences>". $totalOccurrences . "</totalOccurrences>";
        if(!empty($trialOccurrences))
            $this->content .=
                "<trialOccurrences>". $trialOccurrences . "</trialOccurrences>";
        $this->content .=
            "</paymentSchedule>".
            "<amount>". $amount ."</amount>";
        if(!empty($trialOccurrences))
            $this->content .=
                "<trialAmount>" . $trialAmount . "</trialAmount>";
        $this->content .=
            "<payment>".
            "<creditCard>".
            "<cardNumber>" . $cardNumber . "</cardNumber>".
            "<expirationDate>" . $expirationDate . "</expirationDate>";
        if(!empty($cardCode))
            $this->content .= "<cardCode>" . $cardCode . "</cardCode>";
        $this->content .=
            "</creditCard>".
            "</payment>".
            "<order><invoiceNumber>" . substr($order->code, 0, 20) . "</invoiceNumber></order>".
            "<customer>".
            "<email>". substr($customer_email, 0, 255) . "</email>".
            "<phoneNumber>". substr($customer_phone, 0, 25) . "</phoneNumber>".
            "</customer>".
            "<billTo>".
            "<firstName><![CDATA[". substr($firstName, 0, 50) . "]]></firstName>".
            "<lastName><![CDATA[" . substr($lastName, 0, 50) . "]]></lastName>".
            "<address><![CDATA[". substr($address, 0, 60) . "]]></address>".
            "<city><![CDATA[" . substr($city, 0, 40) . "]]></city>".
            "<state>". substr($state, 0, 2) . "</state>".
            "<zip>" . substr($zip, 0, 20) . "</zip>".
            "<country>". substr($country, 0, 60) . "</country>".
            "</billTo>".
            "</subscription>".
            "</ARBCreateSubscriptionRequest>";

        //send the xml via curl
        $this->response = $this->send_request_via_curl($host,$path,$this->content);
        //if curl is unavilable you can try using fsockopen
        /*
        $response = send_request_via_fsockopen($host,$path,$content);
        */

        if(!empty($this->response)) {
            list ($refId, $resultCode, $code, $text, $subscriptionId) = $this->parse_return($this->response);
            if($resultCode == "Ok")
            {
                $order->status = "success";	//saved on checkout page
                $order->subscription_transaction_id = $subscriptionId;
                return true;
            }
            else
            {
                $order->status = "error";
                $order->errorcode = $code;
                $order->error = $text;
                $order->shorterror = $text;
                return false;
            }
        } else  {
            $order->status = "error";
            $order->error = "Could not connect to Authorize.net";
            $order->shorterror = "Could not connect to Authorize.net";
            return false;
        }
    }

    function update(&$order)
    {
        //define variables to send
        $gateway_environment = $order->gateway_environment;
        if(empty($gateway_environment))
            $gateway_environment = pmpro_getOption("gateway_environment");
        if($gateway_environment == "live")
            $host = "api.authorize.net";
        else
            $host = "apitest.authorize.net";

        $path = "/xml/v1/request.api";

        $loginname = pmpro_getOption("loginname");
        $transactionkey = pmpro_getOption("transactionkey");

        //$amount = $order->PaymentAmount;
        $refId = $order->code;
        $subscriptionId = $order->subscription_transaction_id;

        $cardNumber = $order->accountnumber;
        $expirationDate = $order->ExpirationDate_YdashM;
        $cardCode = $order->CVV2;

        $firstName = $order->FirstName;
        $lastName = $order->LastName;

        //do address stuff then?
        $address = $order->Address1;
        if(!empty($order->Address2))
            $address .= "\n" . $order->Address2;
        $city = $order->billing->city;
        $state = $order->billing->state;
        $zip = $order->billing->zip;
        $country = $order->billing->country;

        //customer stuff
        $customer_email = $order->Email;
        if(strpos($order->billing->phone, "+") === false)
            $customer_phone = $order->billing->phone;


        //build xml to post
        $this->content =
            "<?xml version=\"1.0\" encoding=\"utf-8\"?>" .
            "<ARBUpdateSubscriptionRequest xmlns=\"AnetApi/xml/v1/schema/AnetApiSchema.xsd\">".
            "<merchantAuthentication>".
            "<name><![CDATA[" . $loginname . "]]></name>".
            "<transactionKey>" . $transactionkey . "</transactionKey>".
            "</merchantAuthentication>".
            "<refId>" . substr($refId, 0, 20) . "</refId>".
            "<subscriptionId>" . $subscriptionId . "</subscriptionId>".
            "<subscription>".
            "<payment>".
            "<creditCard>".
            "<cardNumber>" . $cardNumber . "</cardNumber>".
            "<expirationDate>" . $expirationDate . "</expirationDate>";
        if(!empty($cardCode))
            $this->content .= "<cardCode>" . $cardCode . "</cardCode>";
        $this->content .=
            "</creditCard>".
            "</payment>".
            "<customer>".
            "<email>". substr($customer_email, 0, 255) . "</email>".
            "<phoneNumber>". substr(str_replace("1 (", "(", formatPhone($customer_phone)), 0, 25) . "</phoneNumber>".
            "</customer>".
            "<billTo>".
            "<firstName><![CDATA[". substr($firstName, 0, 50) . "]]></firstName>".
            "<lastName><![CDATA[" . substr($lastName, 0, 50) . "]]></lastName>".
            "<address><![CDATA[". substr($address, 0, 60) . "]]></address>".
            "<city><![CDATA[" . substr($city, 0, 40) . "]]></city>".
            "<state><![CDATA[". substr($state, 0, 2) . "]]></state>".
            "<zip>" . substr($zip, 0, 20) . "</zip>".
            "<country>". substr($country, 0, 60) . "</country>".
            "</billTo>".
            "</subscription>".
            "</ARBUpdateSubscriptionRequest>";

        //send the xml via curl
        $this->response = $this->send_request_via_curl($host,$path,$this->content);
        //if curl is unavilable you can try using fsockopen
        /*
        $response = send_request_via_fsockopen($host,$path,$order->content);
        */


        if(!empty($this->response)) {
            list ($resultCode, $code, $text, $subscriptionId) = $this->parse_return($this->response);

            if($resultCode == "Ok" || $code == "Ok")
            {
                return true;
            }
            else
            {
                $order->status = "error";
                $order->errorcode = $code;
                $order->error = $text;
                $order->shorterror = $text;
                return false;
            }
        } else  {
            $order->status = "error";
            $order->error = "Could not connect to Authorize.net";
            $order->shorterror = "Could not connect to Authorize.net";
            return false;
        }
    }

    function cancel(&$order)
    {
        //define variables to send
        if(!empty($order->subscription_transaction_id))
            $subscriptionId = $order->subscription_transaction_id;
        else
            $subscriptionId = "";
        $loginname = pmpro_getOption("loginname");
        $transactionkey = pmpro_getOption("transactionkey");

        if(!empty($order->gateway_environment))
            $gateway_environment = $order->gateway_environment;
        else
            $gateway_environment = pmpro_getOption("gateway_environment");

        if($gateway_environment == "live")
            $host = "api.authorize.net";
        else
            $host = "apitest.authorize.net";

        $path = "/xml/v1/request.api";

        if(!$subscriptionId || !$loginname || !$transactionkey)
            return false;

        //build xml to post
        $content =
            "<?xml version=\"1.0\" encoding=\"utf-8\"?>".
            "<ARBCancelSubscriptionRequest xmlns=\"AnetApi/xml/v1/schema/AnetApiSchema.xsd\">".
            "<merchantAuthentication>".
            "<name>" . $loginname . "</name>".
            "<transactionKey>" . $transactionkey . "</transactionKey>".
            "</merchantAuthentication>" .
            "<subscriptionId>" . $subscriptionId . "</subscriptionId>".
            "</ARBCancelSubscriptionRequest>";

        //send the xml via curl
        $response = $this->send_request_via_curl($host,$path,$content);
        //if curl is unavilable you can try using fsockopen
        /*
        $response = send_request_via_fsockopen($host,$path,$content);
        */

        //if the connection and send worked $response holds the return from Authorize.net
        if ($response)
        {
            list ($resultCode, $code, $text, $subscriptionId) = $this->parse_return($response);

            if($resultCode == "Ok" || $code == "Ok")
            {
                $order->updateStatus("cancelled");
                return true;
            }
            else
            {
                //$order->status = "error";
                $order->errorcode = $code;
                $order->error = $text;
                $order->shorterror = $text;
                return false;
            }
        }
        else
        {
            $order->status = "error";
            $order->error = __("Could not connect to Authorize.net", 'paid-memberships-pro' );
            $order->shorterror = __("Could not connect to Authorize.net", 'paid-memberships-pro' );
            return false;
        }
    }

    function getSubscriptionStatus(&$order)
    {
        //define variables to send
        if(!empty($order->subscription_transaction_id))
            $subscriptionId = $order->subscription_transaction_id;
        else
            $subscriptionId = "";
        $loginname = pmpro_getOption("loginname");
        $transactionkey = pmpro_getOption("transactionkey");

        if(!empty($order->gateway_environment))
            $gateway_environment = $order->gateway_environment;
        else
            $gateway_environment = pmpro_getOption("gateway_environment");

        if($gateway_environment == "live")
            $host = "api.authorize.net";
        else
            $host = "apitest.authorize.net";

        $path = "/xml/v1/request.api";

        if(!$subscriptionId || !$loginname || !$transactionkey)
            return false;

        //build xml to post
        $content =
            "<?xml version=\"1.0\" encoding=\"utf-8\"?>".
            "<ARBGetSubscriptionStatusRequest xmlns=\"AnetApi/xml/v1/schema/AnetApiSchema.xsd\">".
            "<merchantAuthentication>".
            "<name>" . $loginname . "</name>".
            "<transactionKey>" . $transactionkey . "</transactionKey>".
            "</merchantAuthentication>" .
            "<subscriptionId>" . $subscriptionId . "</subscriptionId>".
            "</ARBGetSubscriptionStatusRequest>";

        //send the xml via curl
        $response = $this->send_request_via_curl($host,$path,$content);

        //if curl is unavilable you can try using fsockopen
        /*
        $response = send_request_via_fsockopen($host,$path,$content);
        */

        //if the connection and send worked $response holds the return from Authorize.net
        if($response)
        {
            list ($resultCode, $code, $text, $subscriptionId) = $this->parse_return($response);

            $status = $this->substring_between($response,'<status>','</status>');

            if($resultCode == "Ok" || $code == "Ok")
            {
                return $status;
            }
            else
            {
                $order->status = "error";
                $order->errorcode = $resultCode;
                $order->error = $message;
                $order->shorterror = $text;
            }
        }
        else
        {
            $order->status = "error";
            $order->errorcode = $resultCode;
            $order->error = $message;
            $order->shorterror = $text;
        }
    }

    //Authorize.net Function
    //function to send xml request via fsockopen
    function send_request_via_fsockopen($host,$path,$content)
    {
        $posturl = "ssl://" . $host;
        $header = "Host: $host\r\n";
        $header .= "User-Agent: PHP Script\r\n";
        $header .= "Content-Type: text/xml\r\n";
        $header .= "Content-Length: ".strlen($content)."\r\n";
        $header .= "Connection: close\r\n\r\n";
        $fp = fsockopen($posturl, 443, $errno, $errstr, 30);
        if (!$fp)
        {
            $response = false;
        }
        else
        {
            error_reporting(E_ERROR);
            fputs($fp, "POST $path  HTTP/1.1\r\n");
            fputs($fp, $header.$content);
            fwrite($fp, $out);
            $response = "";
            while (!feof($fp))
            {
                $response = $response . fgets($fp, 128);
            }
            fclose($fp);
            error_reporting(E_ALL ^ E_NOTICE);
        }
        return $response;
    }

    //Authorize.net Function
    //function to send xml request via curl
    function send_request_via_curl($host,$path,$content)
    {
        $posturl = "https://" . $host . $path;
        $posturl = apply_filters("pmpro_authorizenet_post_url", $posturl, pmpro_getOption("gateway_environment"));
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $posturl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, Array("Content-Type: text/xml"));
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, PMPRO_USER_AGENT);	//set user agent
        $response = curl_exec($ch);
        return $response;
    }


    //Authorize.net Function
    //function to parse Authorize.net response
    function parse_return($content)
    {
        $refId = $this->substring_between($content,'<refId>','</refId>');
        $resultCode = $this->substring_between($content,'<resultCode>','</resultCode>');
        $code = $this->substring_between($content,'<code>','</code>');
        $text = $this->substring_between($content,'<text>','</text>');
        $subscriptionId = $this->substring_between($content,'<subscriptionId>','</subscriptionId>');
        return array ($refId, $resultCode, $code, $text, $subscriptionId);
    }

    //Authorize.net Function
    //helper function for parsing response
    function substring_between($haystack,$start,$end)
    {
        if (strpos($haystack,$start) === false || strpos($haystack,$end) === false)
        {
            return false;
        }
        else
        {
            $start_position = strpos($haystack,$start)+strlen($start);
            $end_position = strpos($haystack,$end);
            return substr($haystack,$start_position,$end_position-$start_position);
        }
    }

}
//
//    function process(&$order)
//    {
//        //check for initial payment
//        if (floatval($order->InitialPayment) == 0) {
//            //auth first, then process
//            if ($this->authorize($order)) {
//                $this->void($order);
//                if (!pmpro_isLevelTrial($order->membership_level)) {
//                    //subscription will start today with a 1 period trial (initial payment charged separately)
//                    $order->ProfileStartDate = date("Y-m-d") . "T0:0:0";
//                    $order->TrialBillingPeriod = $order->BillingPeriod;
//                    $order->TrialBillingFrequency = $order->BillingFrequency;
//                    $order->TrialBillingCycles = 1;
//                    $order->TrialAmount = 0;
//
//                    //add a billing cycle to make up for the trial, if applicable
//                    if (!empty($order->TotalBillingCycles))
//                        $order->TotalBillingCycles++;
//                } elseif ($order->InitialPayment == 0 && $order->TrialAmount == 0) {
//                    //it has a trial, but the amount is the same as the initial payment, so we can squeeze it in there
//                    $order->ProfileStartDate = date("Y-m-d") . "T0:0:0";
//                    $order->TrialBillingCycles++;
//
//                    //add a billing cycle to make up for the trial, if applicable
//                    if ($order->TotalBillingCycles)
//                        $order->TotalBillingCycles++;
//                } else {
//                    //add a period to the start date to account for the initial payment
//                    $order->ProfileStartDate = date("Y-m-d", strtotime("+ " . $order->BillingFrequency . " " . $order->BillingPeriod, current_time("timestamp"))) . "T0:0:0";
//                }
//
//                $order->ProfileStartDate = apply_filters("pmpro_profile_start_date", $order->ProfileStartDate, $order);
//                return $this->subscribe($order);
//            } else {
//                if (empty($order->error))
//                    $order->error = __("Unknown error: Authorization failed.", "pmpro");
//                return false;
//            }
//        } else {
//            //charge first payment
//            if ($this->charge($order)) {
//                //set up recurring billing
//                if (pmpro_isLevelRecurring($order->membership_level)) {
//                    if (!pmpro_isLevelTrial($order->membership_level)) {
//                        //subscription will start today with a 1 period trial
//                        $order->ProfileStartDate = date("Y-m-d") . "T0:0:0";
//                        $order->TrialBillingPeriod = $order->BillingPeriod;
//                        $order->TrialBillingFrequency = $order->BillingFrequency;
//                        $order->TrialBillingCycles = 1;
//                        $order->TrialAmount = 0;
//
//                        //add a billing cycle to make up for the trial, if applicable
//                        if (!empty($order->TotalBillingCycles))
//                            $order->TotalBillingCycles++;
//                    } elseif ($order->InitialPayment == 0 && $order->TrialAmount == 0) {
//                        //it has a trial, but the amount is the same as the initial payment, so we can squeeze it in there
//                        $order->ProfileStartDate = date("Y-m-d") . "T0:0:0";
//                        $order->TrialBillingCycles++;
//
//                        //add a billing cycle to make up for the trial, if applicable
//                        if (!empty($order->TotalBillingCycles))
//                            $order->TotalBillingCycles++;
//                    } else {
//                        //add a period to the start date to account for the initial payment
//                        $order->ProfileStartDate = date("Y-m-d", strtotime("+ " . $this->BillingFrequency . " " . $this->BillingPeriod, current_time("timestamp"))) . "T0:0:0";
//                    }
//
//                    $order->ProfileStartDate = apply_filters("pmpro_profile_start_date", $order->ProfileStartDate, $order);
//                    if ($this->subscribe($order)) {
//                        return true;
//                    } else {
//                        if ($this->void($order)) {
//                            if (!$order->error)
//                                $order->error = __("Unknown error: Payment failed.", "pmpro");
//                        } else {
//                            if (!$order->error)
//                                $order->error = __("Unknown error: Payment failed.", "pmpro");
//
//                            $order->error .= " " . __("A partial payment was made that we could not void. Please contact the site owner immediately to correct this.", "pmpro");
//                        }
//
//                        return false;
//                    }
//                } else {
//                    //only a one time charge
//                    $order->status = "success";    //saved on checkout page
//                    return true;
//                }
//            } else {
//                if (empty($order->error))
//                    $order->error = __("Unknown error: Payment failed.", "pmpro");
//
//                return false;
//            }
//        }
//    }
//
//    /*
//        Run an authorization at the gateway.
//
//        Required if supporting recurring subscriptions
//        since we'll authorize $1 for subscriptions
//        with a $0 initial payment.
//    */
//    function authorize(&$order)
//    {
//        //create a code for the order
//        if (empty($order->code))
//            $order->code = $order->getRandomCode();
//
//        //code to authorize with gateway and test results would go here
//
//        //simulate a successful authorization
//        $order->payment_transaction_id = "TEST" . $order->code;
//        $order->updateStatus("authorized");
//        return true;
//    }
//
//    /*
//        Void a transaction at the gateway.
//
//        Required if supporting recurring transactions
//        as we void the authorization test on subs
//        with a $0 initial payment and void the initial
//        payment if subscription setup fails.
//    */
//    function void(&$order)
//    {
//        //need a transaction id
//        if (empty($order->payment_transaction_id))
//            return false;
//
//        //code to void an order at the gateway and test results would go here
//
//        //simulate a successful void
//        $order->payment_transaction_id = "TEST" . $order->code;
//        $order->updateStatus("voided");
//        return true;
//    }
//
//    /*
//        Make a charge at the gateway.
//
//        Required to charge initial payments.
//    */
//    function charge(&$order)
//    {
//        //create a code for the order
//        if (empty($order->code))
//            $order->code = $order->getRandomCode();
//
//        //code to charge with gateway and test results would go here
//
//        //simulate a successful charge
//        $order->payment_transaction_id = "TEST" . $order->code;
//        $order->updateStatus("success");
//        return true;
//    }
//
//    /*
//        Setup a subscription at the gateway.
//
//        Required if supporting recurring subscriptions.
//    */
//    function subscribe(&$order)
//    {
//        //create a code for the order
//        if (empty($order->code))
//            $order->code = $order->getRandomCode();
//
//        //filter order before subscription. use with care.
//        $order = apply_filters("pmpro_subscribe_order", $order, $this);
//
//        //code to setup a recurring subscription with the gateway and test results would go here
//
//        //simulate a successful subscription processing
//        $order->status = "success";
//        $order->subscription_transaction_id = "TEST" . $order->code;
//        return true;
//    }
//
//    /*
//        Update billing at the gateway.
//
//        Required if supporting recurring subscriptions and
//        processing credit cards on site.
//    */
//    function update(&$order)
//    {
//        //code to update billing info on a recurring subscription at the gateway and test results would go here
//
//        //simulate a successful billing update
//        return true;
//    }
//
//    /*
//        Cancel a subscription at the gateway.
//
//        Required if supporting recurring subscriptions.
//    */
//    function cancel(&$order)
//    {
//        //require a subscription id
//        if (empty($order->subscription_transaction_id))
//            return false;
//
//        //code to cancel a subscription at the gateway and test results would go here
//
//        //simulate a successful cancel
//        $order->updateStatus("cancelled");
//        return true;
//    }
//
//    /*
//        Get subscription status at the gateway.
//
//        Optional if you have code that needs this or
//        want to support addons that use this.
//    */
//    function getSubscriptionStatus(&$order)
//    {
//        //require a subscription id
//        if (empty($order->subscription_transaction_id))
//            return false;
//
//        //code to get subscription status at the gateway and test results would go here
//
//        //this looks different for each gateway, but generally an array of some sort
//        return array();
//    }
//
//    /*
//        Get transaction status at the gateway.
//
//        Optional if you have code that needs this or
//        want to support addons that use this.
//    */
//    function getTransactionStatus(&$order)
//    {
//        //code to get transaction status at the gateway and test results would go here
//
//        //this looks different for each gateway, but generally an array of some sort
//        return array();
//    }
//}