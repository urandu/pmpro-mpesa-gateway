<?php
/*
Plugin Name: Mpesa Gateway for Paid Memberships Pro
Description: Mpesa Gateway for Paid Memberships Pro
Version: .1
*/

define("PMPRO_MPESAGATEWAY_DIR", dirname(__FILE__));

//load payment gateway class
require_once(PMPRO_MPESAGATEWAY_DIR . "/classes/class.pmprogateway_mpesa.php");