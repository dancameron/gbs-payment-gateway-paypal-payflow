<?php

/**
 * Paypal credit card payment processor.
 *
 * @package GBS
 * @subpackage Payment Processing_Processor
 */
class Group_Buying_Paypal_PF extends Group_Buying_Credit_Card_Processors {
	const MODE_TEST = 'sandbox';
	const MODE_LIVE = 'live';
	const API_USERNAME_OPTION = 'gb_paypal_pf_username';
	const API_VENDOR_OPTION = 'gb_paypal_pf_signature';
	const API_PARTNER_OPTION = 'gb_paypal_pf_partner';
	const API_PASSWORD_OPTION = 'gb_paypal_pf_password';
	const API_MODE_OPTION = 'gb_paypal_pf_mode';
	const CURRENCY_CODE_OPTION = 'gb_paypal_pf_currency';
	const PAYMENT_METHOD = 'Credit (PayPal PF)';
	protected static $instance;
	private $api_mode = self::MODE_TEST;
	private $api_username = '';
	private $api_password = '';
	private $api_vendor = '';
	private $api_partner = '';
	private $currency_code = 'USD';
	private $version = '64';

	public static function get_instance() {
		if ( !( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function get_api_mode() {
		if ( $this->api_mode == self::MODE_LIVE ) {
			return 'test';
		} else {
			return 'live';
		}
	}

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	protected function __construct() {
		parent::__construct();
		$this->api_username = get_option( self::API_USERNAME_OPTION, '' );
		$this->api_password = get_option( self::API_PASSWORD_OPTION, '' );
		$this->api_vendor = get_option( self::API_VENDOR_OPTION, '' );
		$this->api_partner = get_option( self::API_PARTNER_OPTION, 'PayPal' );
		$this->api_mode = get_option( self::API_MODE_OPTION, self::MODE_TEST );
		$this->currency_code = get_option( self::CURRENCY_CODE_OPTION, 'USD' );

		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );
		add_action( 'purchase_completed', array( $this, 'capture_purchase' ), 10, 1 );
		add_action( self::CRON_HOOK, array( $this, 'capture_pending_payments' ) );
	}

	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'PayPal Payments Advanced / Payflow' ) );
	}

	public static function accepted_cards() {
		$accepted_cards = array(
			'visa',
			'mastercard',
			'amex',
			// 'diners',
			'discover',
			// 'jcb',
			// 'maestro'
		);
		return apply_filters( 'gb_accepted_credit_cards', $accepted_cards );
	}

	/**
	 * Process a payment
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return Group_Buying_Payment|bool FALSE if the payment failed, otherwise a Payment object
	 */
	public function process_payment( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		if ( $purchase->get_total( $this->get_payment_method() ) < 0.01 ) {
			// Nothing to do here, another payment handler intercepted and took care of everything
			// See if we can get that payment and just return it
			$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance( $payment_id );
				return $payment;
			}
		}

		$paypal_purchase = $this->payflow_purchase( $checkout, $purchase );

		if ( self::DEBUG ) {
			error_log( '----------PayPal purchase response----------' );
			error_log( print_r( $paypal_purchase, TRUE ) );
		}

		if ( $paypal_purchase === FALSE ) {
			error_log( "FAIL: " . print_r( TRUE, true ) );
			return FALSE;
		}

		$deal_info = array();
		foreach ( $purchase->get_products() as $item ) {
			if ( isset( $item['payment_method'][$this->get_payment_method()] ) ) {
				if ( !isset( $deal_info[$item['deal_id']] ) ) {
					$deal_info[$item['deal_id']] = array();
				}
				$deal_info[$item['deal_id']][] = $item;
			}
		}
		if ( isset( $checkout->cache['shipping'] ) ) {
			$shipping_address = array();
			$shipping_address['first_name'] = $checkout->cache['shipping']['first_name'];
			$shipping_address['last_name'] = $checkout->cache['shipping']['last_name'];
			$shipping_address['street'] = $checkout->cache['shipping']['street'];
			$shipping_address['city'] = $checkout->cache['shipping']['city'];
			$shipping_address['zone'] = $checkout->cache['shipping']['zone'];
			$shipping_address['postal_code'] = $checkout->cache['shipping']['postal_code'];
			$shipping_address['country'] = $checkout->cache['shipping']['country'];
		}
		$payment_id = Group_Buying_Payment::new_payment( array(
				'payment_method' => $this->get_payment_method(),
				'purchase' => $purchase->get_id(),
				'amount' => gb_get_number_format( $purchase->get_total( $this->get_payment_method() ) ),
				'data' => array(
					'api_response' => $paypal_purchase,
					'uncaptured_deals' => $deal_info,
				),
				'deals' => $deal_info,
				'shipping_address' => $shipping_address,
			), Group_Buying_Payment::STATUS_AUTHORIZED );
		if ( !$payment_id ) {
			return FALSE;
		}
		$payment = Group_Buying_Payment::get_instance( $payment_id );
		do_action( 'payment_authorized', $payment );

		$this->create_recurring_payment_profiles( $checkout, $purchase );

		return $payment;
	}

	/**
	 * Capture a pre-authorized payment
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function capture_purchase( Group_Buying_Purchase $purchase ) {
		$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			$this->capture_payment( $payment );
		}
	}

	public  function capture_payment( Group_Buying_Payment $payment ) {
		// is this the right payment processor? does the payment still need processing?
		if ( $payment->get_payment_method() == $this->get_payment_method() && $payment->get_status() != Group_Buying_Payment::STATUS_COMPLETE ) {
			$data = $payment->get_data();
			// Do we have a transaction ID to use for the capture?
			if ( isset( $data['api_response']['PNREF'] ) && $data['api_response']['PNREF'] ) {
				$transaction_id = $data['api_response']['PNREF'];

				$items_to_capture = $this->items_to_capture( $payment );
				if ( $items_to_capture ) {

					$status = ( count( $items_to_capture ) < count( $data['uncaptured_deals'] ) )?'NotComplete':'Complete';
					$capture_response = $this->capture_paypal_payment( $transaction_id, $items_to_capture, $status );
					if ( self::DEBUG ) {
						error_log( '----------PayPal Capture response----------' );
						error_log( print_r( $capture_response, TRUE ) );
					}
					if ( $capture_response != FALSE ) {
						foreach ( $items_to_capture as $deal_id => $amount ) {
							unset( $data['uncaptured_deals'][$deal_id] );
						}
						if ( !isset( $data['capture_response'] ) ) {
							$data['capture_response'] = array();
						}
						$data['capture_response'][] = $capture_response;
						$payment->set_data( $data );
						do_action( 'payment_captured', $payment, array_keys( $items_to_capture ) );
						if ( $status == 'Complete' ) {
							$payment->set_status( Group_Buying_Payment::STATUS_COMPLETE );
							do_action( 'payment_complete', $payment );
						} else {
							$payment->set_status( Group_Buying_Payment::STATUS_PARTIAL );
						}
					}
				}
			}
		}
	}

	/**
	 * Try to capture all pending payments
	 *
	 * @return void
	 */
	public function capture_pending_payments() {
		$payments = Group_Buying_Payment::get_pending_payments();
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			$this->capture_payment( $payment );
		}
	}

	/**
	 * The the NVP data for submitting a DoCapture request
	 *
	 * @param string  $transaction_id
	 * @param array   $items
	 * @param string  $status
	 * @return array
	 */
	private function capture_paypal_payment( $transaction_id, $items, $status = 'Complete' ) {
		$total = 0;
		foreach ( $items as $price ) {
			$total += $price;
		}
		require_once 'api/Class.PayFlow.php';

		// Single Transaction
		$PayFlow = new PayFlow( $this->api_vendor, $this->api_partner, $this->api_username, $this->api_password, 'single' );

		$PayFlow->setEnvironment( $this->get_api_mode() );            // test or live
		$PayFlow->setTransactionType( 'D' );                          // S = Sale transaction, R = Recurring, C = Credit, A = Authorization, D = Delayed Capture, V = Void, F = Voice Authorization, I = Inquiry, N = Duplicate transaction
		$PayFlow->setPaymentMethod( 'C' );                            // A = Automated clearinghouse, C = Credit card, D = Pinless debit, K = Telecheck, P = PayPal.
		$PayFlow->setPaymentCurrency( $this->get_currency_code() );   // 'USD', 'EUR', 'GBP', 'CAD', 'JPY', 'AUD'.

		$PayFlow->setAmount( gb_get_number_format( $total ), FALSE );

		// PNREF
		$PayFlow->setCustomField( 'ORIGID', $transaction_id );


		if ( $PayFlow->processTransaction() ) { // Success
			if ( self::DEBUG ) {
				error_log( '---------- Name Value Pair String ----------' );
				error_log( print_r( $PayFlow->debugNVP( 'array' ), TRUE ) );
				error_log( '---------- Response From Paypal ----------' );
				error_log( print_r( $PayFlow->getResponse(), TRUE ) );
			}
			return $PayFlow->getResponse();
		}
		else { // Failure
			if ( self::DEBUG ) {
				error_log( '---------- Name Value Pair String ----------' );
				error_log( print_r( $PayFlow->debugNVP( 'array' ), TRUE ) );
				error_log( '---------- Response From Paypal ----------' );
				error_log( print_r( $PayFlow->getResponse(), TRUE ) );
			}
			return FALSE;
		}
	}

	/**
	 * Grabs error messages from a PayPal response and displays them to the user
	 *
	 * @param array   $response
	 * @param bool    $display
	 * @return void
	 */
	private function set_error_messages( $response, $display = TRUE ) {
		if ( $display && isset($response['RESPMSG']) ) {
			self::set_message( $response['RESPMSG'], self::MESSAGE_STATUS_ERROR );
		} else {
			error_log( $message );
		}
	}

	/**
	 * Single Transaction
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return array
	 */
	private function payflow_purchase( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {

		$user = get_userdata( $purchase->get_user() );

		require_once 'api/Class.PayFlow.php';

		// Single Transaction
		$PayFlow = new PayFlow( $this->api_vendor, $this->api_partner, $this->api_username, $this->api_password, 'single' );

		$PayFlow->setEnvironment( $this->get_api_mode() );            // test or live
		$PayFlow->setTransactionType( 'A' );                          // S = Sale transaction, R = Recurring, C = Credit, A = Authorization, D = Delayed Capture, V = Void, F = Voice Authorization, I = Inquiry, N = Duplicate transaction
		$PayFlow->setPaymentMethod( 'C' );                            // A = Automated clearinghouse, C = Credit card, D = Pinless debit, K = Telecheck, P = PayPal.
		$PayFlow->setPaymentCurrency( $this->get_currency_code() );   // 'USD', 'EUR', 'GBP', 'CAD', 'JPY', 'AUD'.

		$PayFlow->setAmount( gb_get_number_format( $purchase->get_total( $this->get_payment_method() ) ), FALSE );
		$PayFlow->setCCNumber( $this->cc_cache['cc_number'] );
		$PayFlow->setCVV( $this->cc_cache['cc_cvv'] );
		$PayFlow->setExpiration( self::expiration_date( $this->cc_cache['cc_expiration_month'], $this->cc_cache['cc_expiration_year'] ) );
		$PayFlow->setCreditCardName( $checkout->cache['billing']['first_name'] . ' ' . $checkout->cache['billing']['last_name']);

		$PayFlow->setCustomerFirstName( $checkout->cache['billing']['first_name'] );
		$PayFlow->setCustomerLastName( $checkout->cache['billing']['last_name'] );
		$PayFlow->setCustomerAddress( $checkout->cache['billing']['street'] );
		$PayFlow->setCustomerCity( $checkout->cache['billing']['city'] );
		$PayFlow->setCustomerState( $checkout->cache['billing']['zone'] );
		$PayFlow->setCustomerZip( $checkout->cache['billing']['postal_code'] );
		$PayFlow->setCustomerCountry( self::country_code( $checkout->cache['billing']['country'] ) );
		// $PayFlow->setCustomerPhone( '212-123-1234' );
		$PayFlow->setCustomerEmail( $user->user_email );
		$PayFlow->setPaymentComment( 'Purchase ID: ' . $purchase->get_id() );
		// $PayFlow->setPaymentComment2( 'Products: ' );

				error_log( '---------- Name Value Pair String ----------' );
				error_log( print_r( $PayFlow->debugNVP( 'array' ), TRUE ) );

		if ( $PayFlow->processTransaction() ) { // Success
			if ( self::DEBUG ) {
				error_log( '---------- Name Value Pair String ----------' );
				error_log( print_r( $PayFlow->debugNVP( 'array' ), TRUE ) );
				error_log( '---------- Response From Paypal ----------' );
				error_log( print_r( $PayFlow->getResponse(), TRUE ) );
			}
			return $PayFlow->getResponse();
		}
		else { // Failure
			if ( self::DEBUG ) {
				error_log( '---------- Name Value Pair String ----------' );
				error_log( print_r( $PayFlow->debugNVP( 'array' ), TRUE ) );
				error_log( '---------- Response From Paypal ----------' );
				error_log( print_r( $PayFlow->getResponse(), TRUE ) );
			}
			$this->set_error_messages( $PayFlow->getResponse() );
			return FALSE;
		}
	}

	/**
	 * Format the month and year as an expiration date
	 *
	 * @static
	 * @param int     $month
	 * @param int     $year
	 * @return string
	 */
	private static function expiration_date( $month, $year ) {
		return sprintf( '%02d%04d', $month, $year );
	}

	private function get_currency_code() {
		return apply_filters( 'gb_paypal_pf_currency_code', $this->currency_code );
	}

	private static function country_code( $country = null ) {
		if ( null != $country ) {
			return $country;
		}
		return 'US';
	}

	public function register_settings() {
		$page = Group_Buying_Payment_Processors::get_settings_page();
		$section = 'gb_paypal_payflow_settings';
		add_settings_section( $section, self::__( 'PayPal Payflow' ), array( $this, 'display_settings_section' ), $page );
		register_setting( $page, self::API_MODE_OPTION );
		register_setting( $page, self::API_USERNAME_OPTION );
		register_setting( $page, self::API_PASSWORD_OPTION );
		register_setting( $page, self::API_VENDOR_OPTION );
		register_setting( $page, self::API_PARTNER_OPTION );
		register_setting( $page, self::CURRENCY_CODE_OPTION );
		add_settings_field( self::API_MODE_OPTION, self::__( 'Mode' ), array( $this, 'display_api_mode_field' ), $page, $section );
		add_settings_field( self::API_PARTNER_OPTION, self::__( 'Partner ID' ), array( $this, 'display_api_partner_field' ), $page, $section );
		add_settings_field( self::API_VENDOR_OPTION, self::__( 'Vendor ID' ), array( $this, 'display_api_vendor_field' ), $page, $section );
		add_settings_field( self::API_USERNAME_OPTION, self::__( 'Username' ), array( $this, 'display_api_username_field' ), $page, $section );
		add_settings_field( self::API_PASSWORD_OPTION, self::__( 'Password' ), array( $this, 'display_api_password_field' ), $page, $section );
		add_settings_field( self::CURRENCY_CODE_OPTION, self::__( 'Currency Code' ), array( $this, 'display_currency_code_field' ), $page, $section );
	}

	public function display_api_username_field() {
		echo '<input type="text" name="'.self::API_USERNAME_OPTION.'" value="'.$this->api_username.'" size="80" />';
	}

	public function display_api_password_field() {
		echo '<input type="text" name="'.self::API_PASSWORD_OPTION.'" value="'.$this->api_password.'" size="80" />';
	}

	public function display_api_vendor_field() {
		echo '<input type="text" name="'.self::API_VENDOR_OPTION.'" value="'.$this->api_vendor.'" size="80" />';
	}

	public function display_api_partner_field() {
		echo '<input type="text" name="'.self::API_PARTNER_OPTION.'" value="'.$this->api_partner.'" size="80" />';
	}

	public function display_api_mode_field() {
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_LIVE.'" '.checked( self::MODE_LIVE, $this->api_mode, FALSE ).'/> '.self::__( 'Live' ).'</label><br />';
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_TEST.'" '.checked( self::MODE_TEST, $this->api_mode, FALSE ).'/> '.self::__( 'Test' ).'</label>';
	}

	public function display_currency_code_field() {
		echo '<input type="text" name="'.self::CURRENCY_CODE_OPTION.'" value="'.$this->currency_code.'" size="5" />';
		echo "<p class='description'>USD, EUR, GBP, CAD, JPY, AUD</p>";
	}
}
Group_Buying_Paypal_PF::register();
