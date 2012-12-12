<?php
/*
Plugin Name: Group Buying Payment Processor - Paypal Payflow/PayPal Payments Advanced
Version: .1
Plugin URI: http://sproutventure.com/wordpress/group-buying
Description: Paypal Payflow Payments Add-on. Combine with Paypal standard for offsite Paypal purchasing.
Author: Sprout Venture
Author URI: http://sproutventure.com/wordpress
Plugin Author: Dan Cameron
Contributors: Dan Cameron
Text Domain: group-buying
Domain Path: /lang
*/

add_action('gb_register_processors', 'gb_load_payflow');

function gb_load_payflow() {
	require_once('groupBuyingPayflow.class.php');
}