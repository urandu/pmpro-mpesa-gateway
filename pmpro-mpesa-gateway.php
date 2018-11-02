<?php
/*
Plugin Name: Mpesa Gateway for Paid Memberships Pro
Description: Mpesa Gateway for Paid Memberships Pro
Version: .1
*/

define("PMPRO_MPESAGATEWAY_DIR", dirname(__FILE__));

//load payment gateway class small change
require_once(PMPRO_MPESAGATEWAY_DIR . "/classes/class.pmprogateway_mpesa.php");
register_activation_hook( __FILE__, 'mpesa_install' );
add_action('init', 'pmpro_mpesa_ipn_listener');
add_action('init', 'mpesa_url_registration');
add_action('init', 'simulate_c2b');
