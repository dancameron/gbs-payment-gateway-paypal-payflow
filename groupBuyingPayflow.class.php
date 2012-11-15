<?php

class Group_Buying_Payflow extends Group_Buying_Credit_Card_Processors {

	const MODE_TEST = 'sandbox';
	const MODE_LIVE = 'live';


	const API_ID_OPTION = 'gb_payflow_customer_id';
	const API_USERNAME_OPTION = 'gb_payflow_username';
	const API_PASSWORD_OPTION = 'gb_payflow_password';
	const API_MODE_OPTION = 'gb_payflow_mode';

	const USER_META_PROFILE_ID = 'gb_payflow_token_profile_id';
	const USE_PROFILES_OPTION = 'gb_payflow_token_profiles'; // Set to TRUE if you want to store profiles

	const PAYMENT_METHOD = 'Credit (Paypal Payflow)';
	protected static $instance;
	private static $api_mode = self::MODE_TEST;
	private static $api_id = '';
	private static $api_username = '';
	private static $api_password = '';
	private static $use_profiles = '';

	public static function get_instance() {
		if ( !( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function get_api_url() {
		if ( self::$api_mode == self::MODE_LIVE ) {
			return self::API_ENDPOINT_LIVE;
		} else {
			return self::API_ENDPOINT_SANDBOX;
		}
	}

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	protected function __construct() {

		parent::__construct();
		self::$api_id = get_option( self::API_ID_OPTION, '' );
		self::$api_username = get_option(self::API_USERNAME_OPTION, '');
		self::$api_password = get_option(self::API_PASSWORD_OPTION, '');
		self::$api_mode = get_option( self::API_MODE_OPTION, self::MODE_TEST );
		self::$use_profiles = get_option( self::USE_PROFILES_OPTION, 0 );

		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );
		add_action( 'purchase_completed', array( $this, 'complete_purchase' ), 10, 1 );
		if ( GBS_DEV ) {
			add_action( 'init', array( $this, 'capture_pending_payments' ) );
		} else {
			add_action( self::CRON_HOOK, array( $this, 'capture_pending_payments' ) );
		}

		if ( self::$use_profiles ) {
			// Modify checkout 
			add_filter( 'wp_head', array( $this, 'credit_card_template_js' ) );
			add_filter( 'gb_payment_fields', array( $this, 'filter_payment_fields' ), 100, 3 );
			add_filter( 'gb_payment_review_fields', array( $this, 'payment_review_fields' ), 100, 3 );

			remove_action( 'gb_checkout_action_'.Group_Buying_Checkouts::PAYMENT_PAGE, array( $this, 'process_payment_page' ) );
			add_action( 'gb_checkout_action_'.Group_Buying_Checkouts::PAYMENT_PAGE, array( $this, 'process_payment_page' ), 20, 1 );
		}

	}

	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'eWAY' ) );
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

		// Create customer account
		$profile_id = $this->create_profile( $checkout, $purchase );
		if ( !$profile_id )
			return FALSE;

		/*
		 * Purchase since payment was successful above.
		 */
		$deal_info = array(); // creating purchased products array for payment below
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
				'amount' => $purchase->get_total( $this->get_payment_method() ), // TODO CHANGE to NVP_DATA Match
				'data' => array(
					'profile_id' => $profile_id,
					'reference_id' => $purchase->get_id(),
					'api_response' => $response,
					'masked_cc_number' => $this->mask_card_number( $this->cc_cache['cc_number'] ), // save for possible credits later,
					'uncaptured_deals' => $deal_info
				),
				'deals' => $deal_info,
				'shipping_address' => $shipping_address,
			), Group_Buying_Payment::STATUS_AUTHORIZED );
		if ( !$payment_id ) {
			return FALSE;
		}
		$payment = Group_Buying_Payment::get_instance( $payment_id );
		do_action( 'payment_authorized', $payment );
		return $payment;
	}

	///////////////
	// capturing //
	///////////////

	/**
	 * Attempt to capture the payment after purchase is complete
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function complete_purchase( Group_Buying_Purchase $purchase ) {
		$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			$this->capture_payment( $payment );
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


	public  function capture_payment( Group_Buying_Payment $payment ) {

		// is this the right payment processor? does the payment still need processing?
		if ( $payment->get_payment_method() == $this->get_payment_method() && $payment->get_status() != Group_Buying_Payment::STATUS_COMPLETE ) {
			$data = $payment->get_data();
			// Do we have a transaction ID to use for the capture?
			if ( isset( $data['profile_id'] ) ) {
				$total = 0;
				$items_to_capture = $this->items_to_capture( $payment );
				if ( $items_to_capture ) {
					$status = ( count( $items_to_capture ) < count( $data['uncaptured_deals'] ) ) ? 'NotComplete' : 'Complete';

					// Total to capture
					foreach ( $items_to_capture as $price ) {
						$total += $price;
					}

					$response = $this->create_payment( $data['profile_id'], $total, $data['reference_id'] );
					$transaction_id = $response->payflowTrxnNumber;

					if ( $transaction_id ) { // Check to make sure the response was valid
						// Reset uncaptured deals
						foreach ( $items_to_capture as $deal_id => $amount ) {
							unset( $data['uncaptured_deals'][$deal_id] );
						}
						// Set data
						if ( !isset( $data['capture_response'] ) ) {
							$data['capture_response'] = array();
						}
						$data['capture_response'][] = $response;
						$payment->set_data( $data );

						// Complete or not?
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

	/////////////
	// Utility //
	/////////////

	public function create_client() {

		$client = new SoapClient( 
			self::get_api_url(), 
			array( 'trace' => 1, 'exceptions' => 1 ) );

		$header_data = new stdClass;
		$header_data->eWAYCustomerID = self::$api_id;
		$header_data->Username = self::$api_username;
		$header_data->Password = self::$api_password;

		$header = new SoapHeader( "https://www.payflow.com.au/gatpayflow/managedpayment", "eWAYHeader", $header_data, false );
		$client->__setSoapHeaders( $header );
		
		return $client;
	}

	/**
	 * 
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return array
	 */
	private function create_profile( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		$account_id = $purchase->get_account_id();
		$user_id = $purchase->get_user();
		$user = get_userdata( $user_id );
		$profile_id = 0;
		
		// Create customer object
		$customer = new stdClass;
		$customer->customerRef = $account_id;
		$customer->Title = "";
		$customer->FirstName = $checkout->cache['billing']['first_name'];
		$customer->LastName = $checkout->cache['billing']['last_name'];
		$customer->Email = $user->user_email;
		$customer->State = $checkout->cache['billing']['zone'];
		$customer->Address =  $checkout->cache['billing']['street'];
		$customer->PostCode = $checkout->cache['billing']['postal_code'];
		$customer->Country = $checkout->cache['billing']['country'];

		// If a token payment don't try to update the CC
		if ( isset( $this->cc_cache['cc_number'] ) ) {
			$customer->CCNumber = $this->cc_cache['cc_number'];
			$customer->CCNameOnCard = $this->cc_cache['cc_number'];
			$customer->CCExpiryMonth = $this->cc_cache['cc_expiration_month'];
			$customer->CCExpiryYear = substr( $this->cc_cache['cc_expiration_year'], -2 );
			$customer->CVN = $this->cc_cache['cc_cvv'];
		}

		// SOAP client
		$client = $this->create_client();

		// If the customer already has a profile just update it.
		if ( $this->has_profile_id( $user_id ) ) {
			// The customer profile id
			$profile_id = $this->get_customer_profile_id( $user_id );

			try { 
				// Update profile
				$customer->managedCustomerID = $profile_id;
				$result = $client->UpdateCustomer( $customer );
				if( GBS_DEV ) error_log( "update customer: " . print_r( $result, true ) );
			} catch ( SoapFault $fault ) { 
				if( GBS_DEV ) error_log( "UpdateCustomer error Result: " . print_r( $client->__getLastRequest(), true ) );
				self::set_message( $fault->getMessage(), self::MESSAGE_STATUS_ERROR );
				return FALSE;
			}

			// Validate the response
			if ( !$result ) // If the update fails than a new profile should be created
				$profile_id = 0;
		}

		// Create customer
		if ( !$profile_id ) {

			try { 
				$result = $client->CreateCustomer( $customer );
				if( GBS_DEV ) error_log( "CreateCustomer result: " . print_r( $result, true ) );
			} catch ( SoapFault $fault ) { 
				if( GBS_DEV ) error_log( "CreateCustomer error Result: " . print_r( $client->__getLastRequest(), true ) );
				self::set_message( $fault->getMessage(), self::MESSAGE_STATUS_ERROR );
				return FALSE;
			}

			// The customer profile id
			$profile_id = $result->CreateCustomerResult;

			// Save Profile ID
			update_user_meta( $user_id, self::USER_META_PROFILE_ID, $profile_id );
		}

		return $profile_id;

	}

	public function create_payment( $profile_id, $total, $reference_id ) {
		// SOAP Client
		$client = $this->create_client();
		
		// Create payment object
		$payment = new stdClass;
		$payment->managedCustomerID = $profile_id;
		$payment->amount = $total;
		$payment->invoiceReference = $reference_id;

		$result = $client->ProcessPayment( $payment );
		if( GBS_DEV ) error_log( "process payment: " . print_r( $result, true ) );

		if ( $result->payflowResponse->payflowTrxnStatus == "True" ) {
			return $result->payflowResponse;
		}
		return FALSE;
	}

	//////////////
	// Customer //
	//////////////

	public static function get_customer_profile_id( $user_id = 0 ) {

		if ( !self::$use_profiles ) // Trick in order to keep profiles to be used
			return FALSE;

		if ( !$user_id ) {
			$user_id = get_current_user_id();
		}
		$profile_id = get_user_meta( $user_id, self::USER_META_PROFILE_ID, TRUE );

		if ( !$profile_id )
			return FALSE;

		return $profile_id;
	}

	public static function has_profile_id( $user_id = 0 ) {
		if ( !$user_id ) {
			$user_id = get_current_user_id();
		}
		return self::get_customer_profile_id( $user_id );
	}

	public static function get_customer_profile( $profile_id = 0 ) {

		$profile_id = self::get_customer_profile_id();
		if ( !$profile_id ) {
			return FALSE;
		}
		
		$client = self::create_client();
		$customer = new stdClass;
		$customer->managedCustomerID = $profile_id; // $profile_id

		$result = $client->QueryCustomer( $customer );

		return $result->QueryCustomerResult;
	}

	public function query_payments( $profile_id = 0 ) {
		
		if ( !$profile_id ) {
			$profile_id = self::get_customer_profile_id();
		}

		$client = $this->create_client();
		$customer = new stdClass;
		$customer->managedCustomerID = $profile_id;

		$result = $client->QueryPayment( $customer );

		if( GBS_DEV ) error_log( "query payments: " . print_r( $result, true ) );
	}

	///////////
	// Misc. //
	///////////

	private function convert_money_to_cents( $value ) {
		// strip out commas
		$value = preg_replace( "/\,/i", "", $value );
		// strip out all but numbers, dash, and dot
		$value = preg_replace( "/([^0-9\.\-])/i", "", $value );
		// make sure we are dealing with a proper number now, no +.4393 or 3...304 or 76.5895,94
		if ( !is_numeric( $value ) ) {
			return 0.00;
		}
		// convert to a float explicitly
		$value = (float)$value;
		return round( $value, 2 )*100;
	}

	/**
	 * Grabs error messages from a Authorize response and displays them to the user
	 *
	 * @param array   $response
	 * @param bool    $display
	 * @return void
	 */
	private function set_error_messages( $response, $display = TRUE ) {
		if ( $display ) {
			self::set_message( $response, self::MESSAGE_STATUS_ERROR );
		} else {
			if( GBS_DEV ) error_log( $response );
		}
	}

	/////////////
	// Checkout //
	/////////////

	public function credit_card_template_js() {
		if ( self::has_profile_id() ) {
?>
<script type="text/javascript" charset="utf-8">
	jQuery(document).ready(function() {
		jQuery(function() {
			jQuery('.gb_credit_card_field_wrap').fadeOut();
			jQuery('[name="gb_credit_payment_method"]').live( 'click', function(){
				var selected = jQuery(this).val();   // get value of checked radio button
				if (selected == 'token') {
					jQuery('.gb_credit_card_field_wrap').fadeOut();
				} else {
					jQuery('.gb_credit_card_field_wrap').fadeIn();
				}
			});
		});
	});
</script>
			<?php
		}
	}

	public function filter_payment_fields( $fields ) {
		if ( self::has_profile_id() ) {
			$customer_profile = self::get_customer_profile();
			if( GBS_DEV ) error_log( "customer profile payment fields: " . print_r( $customer_profile, true ) );
			
			$fields['payment_method'] = array(
				'type' => 'radios',
				'weight' => -10,
				'label' => self::__( 'Payment Method' ),
				'required' => TRUE,
				'options' => array(
					'token' => self::__( 'Credit Card: ' ) . $customer_profile->CCNumber,
					'cc' => self::__( 'Use Different Credit Card' )
				),
				'default' => 'token',
			);
		}
		return $fields;
	}

	public function payment_review_fields( $fields, $processor, Group_Buying_Checkouts $checkout ) {
		if ( isset( $_POST['gb_credit_payment_method'] ) && $_POST['gb_credit_payment_method'] == 'token' ) {
			$fields['cim'] = array(
				'label' => self::__( 'Primary Method' ),
				'value' => self::__( 'Credit Card' ),
				'weight' => 10,
			);
			unset( $fields['cc_name'] );
			unset( $fields['cc_number'] );
			unset( $fields['cc_expiration'] );
			unset( $fields['cc_cvv'] );
		}
		return $fields;
	}

	/**
	 * Validate the submitted credit card info
	 * Store the submitted credit card info in memory for processing the payment later
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @return void
	 */
	public function process_payment_page( Group_Buying_Checkouts $checkout ) {
		// Don't try to validate a Payflow payment
		if ( !isset( $_POST['gb_credit_payment_method'] ) || ( isset( $_POST['gb_credit_payment_method'] ) && $_POST['gb_credit_payment_method'] != 'token' ) ) {
			$fields = $this->payment_fields( $checkout );
			foreach ( array_keys( $fields ) as $key ) {
				if ( $key == 'cc_number' ) { // catch the cc_number so it can be sanatized
					if ( isset( $_POST['gb_credit_cc_number'] ) && strlen( $_POST['gb_credit_cc_number'] ) > 0 ) {
						$this->cc_cache['cc_number'] = preg_replace( '/\D+/', '', $_POST['gb_credit_cc_number'] );
					}
				}
				elseif ( isset( $_POST['gb_credit_'.$key] ) && strlen( $_POST['gb_credit_'.$key] ) > 0 ) {
					$this->cc_cache[$key] = $_POST['gb_credit_'.$key];
				}
			}
			$this->validate_credit_card( $this->cc_cache, $checkout );
		}
	}

	//////////////
	// Settings //
	//////////////

	public function register_settings() {
		$page = Group_Buying_Payment_Processors::get_settings_page();
		$section = 'gb_authorizenet_settings';
		add_settings_section( $section, self::__( 'payflow' ), array( $this, 'display_settings_section' ), $page );
		register_setting( $page, self::API_MODE_OPTION );
		register_setting( $page, self::API_ID_OPTION );
		register_setting( $page, self::API_USERNAME_OPTION );
		register_setting( $page, self::API_PASSWORD_OPTION );
		register_setting( $page, self::USE_PROFILES_OPTION );
		add_settings_field( self::API_MODE_OPTION, self::__( 'Mode' ), array( $this, 'display_api_mode_field' ), $page, $section );
		add_settings_field( self::USE_PROFILES_OPTION, self::__( 'Use Profiles' ), array( $this, 'display_api_token_option' ), $page, $section );
		add_settings_field( self::API_ID_OPTION, self::__( 'Customer ID' ), array( $this, 'display_api_id_field' ), $page, $section );
		add_settings_field(self::API_USERNAME_OPTION, self::__('Username'), array($this, 'display_api_username_field'), $page, $section);
		add_settings_field(self::API_PASSWORD_OPTION, self::__('Password'), array($this, 'display_api_password_field'), $page, $section);
		//add_settings_field(null, self::__('Currency'), array($this, 'display_currency_code_field'), $page, $section);
	}

	public function display_api_id_field() {
		echo '<input type="text" name="'.self::API_ID_OPTION.'" value="'.self::$api_id.'" size="80" />';
		echo '<p class="description">Your unique 8 digit eWAY customer ID assigned to you when you join eWAY e.g. 1xxxxxxx.</p>';
	}

	public function display_api_username_field() {
		echo '<input type="text" name="'.self::API_USERNAME_OPTION.'" value="'.self::$api_username.'" size="80" />';
		echo '<p class="description">Your username which is used to login to eWAY Business Center.</p>';
	}

	public function display_api_password_field() {
		echo '<input type="text" name="'.self::API_PASSWORD_OPTION.'" value="'.self::$api_password.'" size="80" />';
		echo '<p class="description">Your password which is used to login to eWAY Business Center.</p>';
	}

	public function display_api_mode_field() {
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_LIVE.'" '.checked( self::MODE_LIVE, self::$api_mode, FALSE ).'/> '.self::__( 'Live' ).'</label><br />';
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_TEST.'" '.checked( self::MODE_TEST, self::$api_mode, FALSE ).'/> '.self::__( 'Test' ).'</label>';
	}

	public function display_api_token_option() {
		echo '<label><input type="radio" name="'.self::USE_PROFILES_OPTION.'" value="1" '.checked( '1', self::$use_profiles, FALSE ).'/> '.self::__( 'Allow returning customers to use their stored CC on eWAY for payment (token payments).' ).'</label><br />';
		echo '<label><input type="radio" name="'.self::USE_PROFILES_OPTION.'" value="0" '.checked( '0', self::$use_profiles, FALSE ).'/> '.self::__( 'Do not show stored payment profile at checkout. Token payments will be used in the background.' ).'</label>';
	}

	public function display_currency_code_field() {
		echo '<input type="text" name="'.self::API_PASSWORD_OPTION.'" value="'.self::$api_password.'" size="80" />';
		echo '<p class="description">Your password which is used to login to eWAY Business Center.</p>';
		echo "'USD', 'EUR', 'GBP', 'CAD', 'JPY', or 'AUD'.";
	}
}
Group_Buying_Payflow::register();