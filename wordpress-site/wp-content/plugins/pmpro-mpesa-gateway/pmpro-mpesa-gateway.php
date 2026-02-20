<?php
/*
Plugin Name: M-Pesa Gateway for Paid Memberships Pro
Description: M-Pesa Daraja STK Push gateway for Paid Memberships Pro.
Version: 1.0
*/

define( 'PMPRO_MPESAGATEWAY_DIR', dirname( __FILE__ ) );

require_once PMPRO_MPESAGATEWAY_DIR . '/classes/class.pmprogateway_mpesa.php';

register_activation_hook( __FILE__, 'mpesa_install' );
add_action( 'init', 'pmpro_mpesa_ipn_listener' );
add_action( 'init', 'mpesa_url_registration' );
add_action( 'init', 'simulate_c2b' );
