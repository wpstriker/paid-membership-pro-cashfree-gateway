<?php

// Require the default PMPro Gateway Class.
require_once PMPRO_DIR . '/classes/gateways/class.pmprogateway.php';

// load classes init method
add_action( 'init', array( 'PMProGateway_Cashfree', 'init' ) );

class PMProGateway_Cashfree {

	function __construct( $gateway = null ) {
		$this->gateway = $gateway;
		return $this->gateway;
	}

	/**
	 * Run on WP init
	 *
	 * @since 1.8
	 */
	static function init() {
		// make sure Cashfree is a gateway option
		add_filter( 'pmpro_gateways', array( 'PMProGateway_Cashfree', 'pmpro_gateways' ) );

		// add fields to payment settings
		add_filter( 'pmpro_payment_options', array( 'PMProGateway_Cashfree', 'pmpro_payment_options' ) );

		add_filter( 'pmpro_payment_option_fields', array( 'PMProGateway_Cashfree', 'pmpro_payment_option_fields' ), 10, 2 );

		if ( pmpro_getOption( 'gateway' ) == 'cashfree' ) {
			add_filter( 'pmpro_include_billing_address_fields', '__return_false' );
			add_filter( 'pmpro_include_payment_information_fields', '__return_false' );
			
			add_filter( 'pmpro_required_billing_fields', '__return_empty_array' );
			//add_filter( 'pmpro_include_billing_address_fields', array( 'PMProGateway_Cashfree', 'pmpro_include_billing_address_fields' ), 20, 1 );
			//add_filter( 'pmpro_required_billing_fields', array( 'PMProGateway_Cashfree', 'pmpro_required_billing_fields' ), 20, 1 );
		}
		
		add_filter( 'pmpro_checkout_before_submit_button', array( 'PMProGateway_Cashfree', 'pmpro_checkout_before_submit_button' ) );
		add_filter( 'pmpro_checkout_before_change_membership_level', array( 'PMProGateway_Cashfree', 'pmpro_checkout_before_change_membership_level' ), 10, 2 );

		add_filter( 'pmpro_gateways_with_pending_status', array( 'PMProGateway_Cashfree', 'pmpro_gateways_with_pending_status' ) );
		
		add_action( 'wp', array( 'PMProGateway_Cashfree', 'handle_return' ) );
	}

	function handle_return() {
		if( ! isset( $_GET['_pmpro_cashfree'] ) )
			return;
			
		$cf_subReferenceId	= $_POST['cf_subReferenceId']; 
		update_option( 'cashfree_' . $cf_subReferenceId, $_POST );
			
		//last order for this subscription //getOldOrderFromInvoiceEvent($pmpro_stripe_event);
		$morder = new MemberOrder();
		$morder->getLastMemberOrderBySubscriptionTransactionID( $cf_subReferenceId );
		
		//get some more order info
		$morder->getMembershipLevel();
		$morder->getUser();
		
		global $wpdb;

		//filter for level
		$morder->membership_level = apply_filters( "pmpro_ipnhandler_level", $morder->membership_level, $morder->user_id );
	
		//set the start date to current_time('timestamp') but allow filters  (documented in preheaders/checkout.php)
		$startdate = apply_filters( "pmpro_checkout_start_date", "'" . current_time( 'mysql' ) . "'", $morder->user_id, $morder->membership_level );
	
		//fix expiration date
		if ( ! empty( $morder->membership_level->expiration_number ) ) {
			$enddate = "'" . date_i18n( "Y-m-d", strtotime( "+ " . $morder->membership_level->expiration_number . " " . $morder->membership_level->expiration_period, current_time( "timestamp" ) ) ) . "'";
		} else {
			$enddate = "NULL";
		}
	
		//filter the enddate (documented in preheaders/checkout.php)
		$enddate = apply_filters( "pmpro_checkout_end_date", $enddate, $morder->user_id, $morder->membership_level, $startdate );
	
		//get discount code
		$morder->getDiscountCode();
		if ( ! empty( $morder->discount_code ) ) {
			//update membership level
			$morder->getMembershipLevel( true );
			$discount_code_id = $morder->discount_code->id;
		} else {
			$discount_code_id = "";
		}
	
	
		//custom level to change user to
		$custom_level = array(
			'user_id'         => $morder->user_id,
			'membership_id'   => $morder->membership_level->id,
			'code_id'         => $discount_code_id,
			'initial_payment' => $morder->membership_level->initial_payment,
			'billing_amount'  => $morder->membership_level->billing_amount,
			'cycle_number'    => $morder->membership_level->cycle_number,
			'cycle_period'    => $morder->membership_level->cycle_period,
			'billing_limit'   => $morder->membership_level->billing_limit,
			'trial_amount'    => $morder->membership_level->trial_amount,
			'trial_limit'     => $morder->membership_level->trial_limit,
			'startdate'       => $startdate,
			'enddate'         => $enddate
		);
	
		global $pmpro_error;
		if ( ! empty( $pmpro_error ) ) {
			echo $pmpro_error;
			ipnlog( $pmpro_error );
		}
		
		//change level and continue "checkout"
		if ( pmpro_changeMembershipLevel( $custom_level, $morder->user_id, 'changed' ) !== false ) {
			//update order status and transaction ids
			$morder->status 	= "success";			
			$morder->saveOrder();
			
			//add discount code use
			if ( ! empty( $discount_code ) && ! empty( $use_discount_code ) ) {
	
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$wpdb->pmpro_discount_codes_uses} 
							( code_id, user_id, order_id, timestamp ) 
							VALUES( %d, %d, %s, %s )",
						$discount_code_id),
						$morder->user_id,
						$morder->id,
						current_time( 'mysql' )
					);
			}
	
			//save first and last name fields
			/*if ( ! empty( $_POST['first_name'] ) ) {
				$old_firstname = get_user_meta( $morder->user_id, "first_name", true );
				if ( empty( $old_firstname ) ) {
					update_user_meta( $morder->user_id, "first_name", $_POST['first_name'] );
				}
			}
			if ( ! empty( $_POST['last_name'] ) ) {
				$old_lastname = get_user_meta( $morder->user_id, "last_name", true );
				if ( empty( $old_lastname ) ) {
					update_user_meta( $morder->user_id, "last_name", $_POST['last_name'] );
				}
			}*/
	
			//hook
			do_action( "pmpro_after_checkout", $morder->user_id, $morder );
	
			//setup some values for the emails
			if ( ! empty( $morder ) ) {
				$invoice = new MemberOrder( $morder->id );
			} else {
				$invoice = null;
			}
	
			$user                   = get_userdata( $morder->user_id );
			$user->membership_level = $morder->membership_level;        //make sure they have the right level info
	
			//send email to member
			$pmproemail = new PMProEmail();
			$pmproemail->sendCheckoutEmail( $user, $invoice );
	
			//send email to admin
			$pmproemail = new PMProEmail();
			$pmproemail->sendCheckoutAdminEmail( $user, $invoice );
	
			//return true;
		} else {
			//return false;
		}					
		
		wp_redirect( add_query_arg( 'level', $morder->membership_level->id, pmpro_url("confirmation" ) ) );		
		exit;	
	}

	/**
	 * Add cashfree to the list of allowed gateways.
	 *
	 * @return array
	 */
	static function pmpro_gateways_with_pending_status( $gateways ) {
		$gateways[] = 'cashfree';

		return $gateways;
	}

	/**
	 * Make sure this gateway is in the gateways list
	 *
	 * @since 1.8
	 */
	static function pmpro_gateways( $gateways ) {
		if ( empty( $gateways['cashfree'] ) ) {
			$gateways['cashfree'] = __( 'Cashfree' );
		}

		return $gateways;
	}

	/**
	 * Get a list of payment options that the this gateway needs/supports.
	 *
	 * @since 1.8
	 */
	static function getGatewayOptions() {
		$options = array(
			'cashfree_app_id',
			'cashfree_secret_key',
			'currency',
			'use_ssl',
			'tax_state',
			'tax_rate',
		);

		return $options;
	}

	/**
	 * Set payment options for payment settings page.
	 *
	 * @since 1.8
	 */
	static function pmpro_payment_options( $options ) {
		// get stripe options
		$cashfree_options = self::getGatewayOptions();

		// merge with others.
		$options = array_merge( $cashfree_options, $options );

		return $options;
	}

	/**
	 * Display fields for this gateway's options.
	 *
	 * @since 1.8
	 */
	static function pmpro_payment_option_fields( $values, $gateway ) {      ?>
		<tr class="gateway gateway_cashfree" 
			<?php
			if ( $gateway != 'cashfree' ) {
				?>
			style="display: none;"<?php } ?>>
			 <th scope="row" valign="top">
				 <label for="cashfree_app_id"><?php _e( 'Cashfree App ID' ); ?>:</label>
			 </th>
			 <td>
				 <input id="cashfree_app_id" name="cashfree_app_id" value="<?php echo esc_attr( $values['cashfree_app_id'] ); ?>" />
			 </td>
		 </tr>
		 <tr class="gateway gateway_cashfree" 
			 <?php
				if ( $gateway != 'cashfree' ) {
					?>
				style="display: none;"<?php } ?>>
			 <th scope="row" valign="top">
				 <label for="cashfree_secret_key"><?php _e( 'Cashfree Secret Key' ); ?>:</label>
			 </th>
			 <td>
				 <input id="cashfree_secret_key" name="cashfree_secret_key" value="<?php echo esc_attr( $values['cashfree_secret_key'] ); ?>" />
			 </td>
		 </tr>		 
		<script>
			//trigger the payment gateway dropdown to make sure fields show up correctly
			jQuery(document).ready(function() {
				pmpro_changeGateway(jQuery('#gateway').val());
			});
		</script>
			<?php
	}
		
	/**
	 * Remove required billing fields
	 *
	 * @since 1.8
	 */
	static function pmpro_required_billing_fields( $fields ) {
		
		unset( $fields['bfirstname'] );
		unset( $fields['blastname'] );
		unset( $fields['baddress1'] );
		unset( $fields['bcity'] );
		unset( $fields['bstate'] );
		unset( $fields['bzipcode'] );
		//unset( $fields['bphone'] );
		//unset( $fields['bemail'] );
		unset( $fields['bcountry'] );
		unset( $fields['CardType'] );
		unset( $fields['AccountNumber'] );
		unset( $fields['ExpirationMonth'] );
		unset( $fields['ExpirationYear'] );
		unset( $fields['CVV'] );

		return $fields;
	}

	/**
	 * Show information before PMPro's checkout button.
	 *
	 * @todo: Add a filter to show/hide this notice.
	 * @since 1.8
	 */
	static function pmpro_checkout_before_submit_button() {
		global $gateway, $pmpro_requirebilling;

		// Bail if gateway isn't cashfree.
		if ( $gateway != 'cashfree' ) {
			return;
		}

		// see if Pay By Check Add On is active, if it's selected let's hide the cashfree information.
		if ( defined( 'PMPROPBC_VER' ) ) {
			?>
			<script type="text/javascript">
				jQuery(document).ready(function() { 
					jQuery('input:radio[name=gateway]').on( 'click', function() { 
						 var val = jQuery(this).val();

						 if ( val === 'check' ) {
							 jQuery( '#pmpro_cashfree_before_checkout' ).hide();
						 } else {
							 jQuery( '#pmpro_cashfree_before_checkout' ).show();
						 }
					});
				});	
			</script>
			<?php } ?>

		<div id="pmpro_cashfree_before_checkout" style="text-align:center;">
			<span id="pmpro_cashfree_checkout" 
			<?php
			if ( $gateway != 'cashfree' || ! $pmpro_requirebilling ) {
				?>
				style="display: none;"<?php } ?>>
				<input type="hidden" name="submit-checkout" value="1" />
				<p><img src="<?php echo plugins_url( 'img/cashfree_logo.png', __DIR__ ); ?>" width="100px" /></p>
			</span>
		</div>
			<?php
	}

	/**
	 * Instead of change membership levels, send users to cashfree to pay.
	 *
	 * @since 1.8
	 */
	static function pmpro_checkout_before_change_membership_level( $user_id, $morder ) {
		global $discount_code_id, $wpdb;

		// if no order, no need to pay
		if ( empty( $morder ) ) {
			return;
		}

		// bail if the current gateway is not set to cashfree.
		if ( 'cashfree' != $morder->gateway ) {
			return;
		}

		$morder->user_id = $user_id;
		$morder->saveOrder();

		// if global is empty by query is available.
		if ( empty( $discount_code) && isset( $_REQUEST['discount_code'] ) ) {
			$discount_code_id = $wpdb->get_var( "SELECT id FROM $wpdb->pmpro_discount_codes WHERE code = '" . esc_sql( sanitize_text_field( $_REQUEST['discount_code'] ) ) . "'" );
		}

		// save discount code use
		if ( ! empty( $discount_code_id ) ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO $wpdb->pmpro_discount_codes_uses 
					(code_id, user_id, order_id, timestamp) 
					VALUES( %d , %d, %d, %s )",
					$discount_code_id,
					$user_id,
					$morder->id,
					current_time( 'mysql' )
				)
			);
		}

		do_action( 'pmpro_before_send_to_cashfree', $user_id, $morder );

		$morder->Gateway->sendToCashfree( $morder );
	}

	function process( &$order ) {

		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}

		// clean up a couple values
		$order->payment_type = 'Cashfree';
		$order->CardType     = '';
		$order->cardtype     = '';
		
		$order->status = "review";
		$order->saveOrder();

		return true;
	}

	/**
	 * @param $order
	 */
	function sendToCashfree( &$order ) {
		global $pmpro_currency;

		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}

		$order->payment_type = 'Cashfree';
		$order->CardType = "";
		$order->cardtype = "";

		// taxes on initial payment
		$initial_payment     = $order->InitialPayment;
		$initial_payment_tax = $order->getTaxForPrice( $initial_payment );
		$initial_payment     = round( (float) $initial_payment + (float) $initial_payment_tax, 2 );

		// taxes on the amount
		$amount          = $order->PaymentAmount;
		$amount_tax      = $order->getTaxForPrice( $amount );
		$order->subtotal = $amount;
		$amount          = round( (float) $amount + (float) $amount_tax, 2 );

		// merchant details
		$cashfree_app_id  		= pmpro_getOption( 'cashfree_app_id' );
		$cashfree_secret_key 	= pmpro_getOption( 'cashfree_secret_key' );

		// build Cashfree Redirect
		$environment = pmpro_getOption( 'gateway_environment' );
		if ( 'sandbox' === $environment || 'beta-sandbox' === $environment ) {
			$cashfree_url = 'https://test.cashfree.com';
		} else {
			$cashfree_url = 'https://api.cashfree.com';
		}

		$plan_id	= "PLAN_" . $order->user_id . '_' . $order->code;
		$cycles 	= $order->membership_level->billing_limit;
		
		// create cashfree plan
		$cf_request 	= array(
							'planId' 		=> $plan_id, 
							'planName' 		=> "Plan level ID: " . $order->membership_level->id,
							'type' 			=> "PERIODIC",
							//'maxCycles' 	=> "24",
							'amount' 		=> $amount, 
							'intervalType' 	=> strtolower( $order->BillingPeriod ), 
							'intervals' 	=> $cycles ? $cycles : 1,
							'description' 	=> "Plan Created via API, User ID: " . $order->user_id,	
							);
		
		$response	= wp_remote_post(
							$cashfree_url . '/api/v2/subscription-plans',
							array(
								'method'  	=> 'POST',
								'timeout' 	=> 60,
								'headers' 	=> array(
									'Content-Type'		=> 'application/json',
									'X-Client-Id'		=> $cashfree_app_id,
									'X-Client-Secret'	=> $cashfree_secret_key,
								),
								'body'		=> json_encode( $cf_request ) 
							)
						);
		$response_code    	= wp_remote_retrieve_response_code( $response );
		$response_message 	= wp_remote_retrieve_body( $response );
		$response_message	= json_decode( trim( $response_message ), true );
						
		// create cashfree subscription
		$user		= get_userdata( $order->user_id );
		$user_phone	= get_user_meta( $order->user_id, 'billing_phone', true );
		$user_phone	= $user_phone ? $user_phone : '9999999999';
				
		$cf_request 	= array(
							'subscriptionId' => "SUB_" . $order->user_id . '_' . $order->code,
							'planId' 		 => $plan_id,
							'customerName'	 => $user->first_name . ' ' . $user->last_name,
							'customerEmail'  => $user->user_email,
							'customerPhone'  => $user_phone,
							'expiresOn'		 => date( 'Y-m-d 23:59:59', strtotime( date( 'Y-m-d H:i:s' ) . "+2 years" )  ),
							//'returnUrl'		 => add_query_arg( 'level', $order->membership_level->id, pmpro_url("confirmation" ) ),
							'returnUrl'		 => add_query_arg( '_pmpro_cashfree', 1, site_url( '/' ) )
							);
		
		$response	= wp_remote_post(
							$cashfree_url . '/api/v2/subscriptions',
							array(
								'method'  	=> 'POST',
								'timeout' 	=> 60,
								'headers' 	=> array(
									'Content-Type'		=> 'application/json',
									'X-Client-Id'		=> $cashfree_app_id,
									'X-Client-Secret'	=> $cashfree_secret_key,
								),
								'body'		=> json_encode( $cf_request ) 
							)
						);
			
		$response_code    	= wp_remote_retrieve_response_code( $response );
		$response_message 	= wp_remote_retrieve_body( $response );
		$response_message	= json_decode( trim( $response_message ), true );
		
		$return_url = $response_message['authLink'];
		
		//$order->status                      	= 'success';
		$order->payment_transaction_id      	= "SUB_" . $order->user_id . '_' . $order->code;
		$order->subscription_transaction_id 	= $response_message['subReferenceId']; 
		$order->saveOrder();

		wp_redirect( $return_url );
		exit;
	}

	function subscribe( &$order ) {
		global $pmpro_currency;

		if ( empty( $order->code ) ) {
			$order->code = $order->getRandomCode();
		}

		// filter order before subscription. use with care.
		$order = apply_filters( 'pmpro_subscribe_order', $order, $this );
	
		// taxes on initial amount
		$initial_payment     = $order->InitialPayment;
		$initial_payment_tax = $order->getTaxForPrice( $initial_payment );
		$initial_payment     = round( (float) $initial_payment + (float) $initial_payment_tax, 2 );

		// taxes on the amount
		$amount     = $order->PaymentAmount;
		$amount_tax = $order->getTaxForPrice( $amount );
		// $amount = round((float)$amount + (float)$amount_tax, 2);
		
		$order->status                      = 'success';
		//$order->payment_transaction_id      = $order->code;
		//$order->subscription_transaction_id = $order->code;

		// update order
		$order->saveOrder();

		return true;
	}

	function cancel( &$order ) {

		// Check to see if the order has a token and try to cancel it at the gateway. Only recurring subscriptions should have a token.
		if ( ! empty( $order->subscription_transaction_id ) ) {

			// cancel order status immediately.
			$order->updateStatus( 'cancelled' );
						
			// merchant details
			$cashfree_app_id  		= pmpro_getOption( 'cashfree_app_id' );
			$cashfree_secret_key 	= pmpro_getOption( 'cashfree_secret_key' );
	
			// build Cashfree Redirect
			$environment = pmpro_getOption( 'gateway_environment' );
			if ( 'sandbox' === $environment || 'beta-sandbox' === $environment ) {
				$cashfree_url = 'https://test.cashfree.com';
			} else {
				$cashfree_url = 'https://api.cashfree.com';
			}
			
			$cf_request 	= array();
			
			$response	= wp_remote_post(
								$cashfree_url . '/api/v2/subscriptions/' . $order->subscription_transaction_id . '/cancel',
								array(
									'method'  	=> 'POST',
									'timeout' 	=> 60,
									'headers' 	=> array(
										'Content-Type'		=> 'application/json',
										'X-Client-Id'		=> $cashfree_app_id,
										'X-Client-Secret'	=> $cashfree_secret_key,
									),
									'body'		=> json_encode( $cf_request ) 
								)
							);
				
			$response_code    	= wp_remote_retrieve_response_code( $response );
			$response_body 		= wp_remote_retrieve_body( $response );
			$response_body		= json_decode( trim( $response_body ), true );
			$response_message 	= wp_remote_retrieve_response_message( $response );

			if ( 200 == $response_code ) {
				return true;
			} else {
				$order->updateStatus( 'error' );
				$order->errorcode  = $response_code;
				$order->error      = $response_message;
				$order->shorterror = $response_message;

				return false;
			}
		}
	}
} //end of class