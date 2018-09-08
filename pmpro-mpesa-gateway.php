<?php
/*
Plugin Name: Mpesa Gateway for Paid Memberships Pro
Description: Mpesa Gateway for Paid Memberships Pro
Version: 1.0
*/

define("PMPRO_MPESAGATEWAY_DIR", dirname(__FILE__));

//load payment gateway class
require_once(PMPRO_MPESAGATEWAY_DIR . "/classes/class.pmprogateway_mpesa.php");
register_activation_hook( __FILE__, 'mpesa_install' );
add_action('init', 'pmpro_mpesa_ipn_listener');