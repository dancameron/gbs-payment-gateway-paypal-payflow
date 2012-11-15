<?php
/*
Plugin Name: Group Buying Payment Processor - Paypal Payflow
Version: .1
Plugin URI: http://sproutventure.com/wordpress/group-buying
Description: Paypal Payflow Payments Add-on.
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