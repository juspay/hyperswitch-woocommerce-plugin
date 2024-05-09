<?php
/**
 * Plugin Name: Hyperswitch Checkout for WooCommerce
 * Plugin URI: https://hyperswitch.io/
 * Description: Hyperswitch checkout plugin for WooCommerce
 * Author: Hyperswitch
 * Author URI: https://hyperswitch.io/
 * Version: 1.6.1
 * License: GPLv2 or later
 *
 * WC requires at least: 4.0.0
 * WC tested up to: 8.6.1
 *
 * Copyright (c) 2023 Hyperswitch
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

define( 'HYPERSWITCH_CHECKOUT_PLUGIN_VERSION', '1.6.1' );
define( 'HYPERSWITCH_PLUGIN_URL', untrailingslashit( plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) ) );

require_once __DIR__ . '/includes/hyperswitch-webhook.php';

add_action( 'plugins_loaded', 'hyperswitch_init_payment_class', 0 );
add_action( 'admin_post_nopriv_hyperswitch_wc_webhook', 'hyperswitch_webhook_init', 10 );
add_action( 'wp_ajax_nopriv_hyperswitch_create_or_update_payment_intent', 'hyperswitch_create_or_update_payment_intent', 5 );
add_action( 'wp_ajax_hyperswitch_create_or_update_payment_intent', 'hyperswitch_create_or_update_payment_intent', 5 );
add_action( 'before_woocommerce_init', 'hyperswitch_declare_compatibility', 5 );

function hyperswitch_init_payment_class() {

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	class Hyperswitch_Checkout extends WC_Payment_Gateway {

		public function __construct() {
			$this->enabled = $this->get_option( 'enabled' );
			$this->id = 'hyperswitch_checkout';
			$this->method_title = __( 'Hyperswitch' );
			$this->method_description = __( 'Allow customers to securely pay via Hyperswitch', 'hyperswitch-checkout' );

			$this->has_fields = true;

			$this->init_form_fields();
			$this->init_settings();

			$this->title = $this->get_option( 'method_title' );
			$this->icon = HYPERSWITCH_PLUGIN_URL . '/assets/images/default.svg';
			$this->plugin_url = HYPERSWITCH_PLUGIN_URL . '/assets/images/';
			$this->environment = $this->get_option( 'environment' );
			$this->enable_saved_payment_methods = $this->get_option( 'enable_saved_payment_methods' ) === 'yes';
			$this->show_card_from_by_default = $this->get_option( 'show_card_from_by_default' ) === 'yes';
			$this->supports = [ 
				'products',
				'refunds'
			];
			$this->processing_payment_order_status = $this->get_option( 'hold_order' ) === 'yes' ? 'on-hold' : 'pending';
			switch ( $this->environment ) {
				case "sandbox":
					$this->hyperswitch_url = 'https://sandbox.hyperswitch.io';
					$script_url = 'https://beta.hyperswitch.io/v1/HyperLoader.js';
					break;
				case "production":
					$this->hyperswitch_url = 'https://api.hyperswitch.io';
					$script_url = 'https://checkout.hyperswitch.io/v0/HyperLoader.js';
					break;
				default:
					$this->hyperswitch_url = 'https://sandbox.hyperswitch.io';
					$script_url = 'https://beta.hyperswitch.io/v1/HyperLoader.js';
					break;
			}

			$this->notify_url = home_url( '/wc-api/wc_hyperswitch' );

			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'plugin_action_links' ] );

			if ( $this->enabled == 'yes' ) {
				add_action( 'woocommerce_api_wc_hyperswitch', array( $this, 'check_hyperswitch_response' ) );
				wp_enqueue_style( 'hyperswitchcss', plugins_url( '/css/hyperswitch.css', __FILE__ ), array(), HYPERSWITCH_CHECKOUT_PLUGIN_VERSION );
				$client_data = [ 
					'publishable_key' => $this->get_option( 'publishable_key' ),
					'appearance_obj' => $this->get_option( 'appearance' ),
					'layout' => $this->get_option( 'layout' ),
					'enable_saved_payment_methods' => $this->get_option( 'enable_saved_payment_methods' ) === 'yes',
					'show_card_from_by_default' => $this->get_option( 'show_card_from_by_default' ) === 'yes',
					'endpoint' => $this->hyperswitch_url,
					'plugin_url' => $this->plugin_url,
					'plugin_version' => HYPERSWITCH_CHECKOUT_PLUGIN_VERSION,
				];

				wp_enqueue_script( 'hyperswitch-hyperloader', $script_url );

				wp_register_script(
					'hyperswitch-hyperservice',
					plugins_url( '/js/hyperswitch-hyperservice.js', __FILE__ ),
					array( 'hyperswitch-hyperloader' ),
					'HYPERSWITCH_CHECKOUT_PLUGIN_VERSION'
				);
				wp_localize_script( 'hyperswitch-hyperservice', 'clientdata', $client_data );
				wp_enqueue_script( 'hyperswitch-hyperservice' );
				add_action( "woocommerce_receipt_" . $this->id, array( $this, 'receipt_page' ) );
				add_action( 'woocommerce_order_actions', array( $this, 'hyperswitch_add_manual_actions' ) );
				add_action( 'woocommerce_order_action_wc_manual_capture_action', array( $this, 'hyperswitch_process_manual_capture_action' ) );
				add_action( 'woocommerce_order_action_wc_manual_sync_action', array( $this, 'hyperswitch_process_manual_sync_action' ) );
				add_filter( 'woocommerce_order_button_html', array( $this, 'place_order_custom_button' ) );
				add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'hyperswitch_thankyou' ) );
			}
		}

		function plugin_action_links( $links ) {
			$plugin_links = [ 
				'<a href="admin.php?page=wc-settings&tab=checkout&section=hyperswitch_checkout">' . esc_html__( 'Settings', 'hyperswitch-checkout' ) . '</a>',
			];
			return array_merge( $plugin_links, $links );
		}

		function hyperswitch_thankyou( $esc_html__ ) {
			$order_id = wc_get_order_id_by_order_key( $_GET['key'] );
			$order = wc_get_order( $order_id );
			$payment_method = $order->get_payment_method();
			if ( $payment_method == 'hyperswitch_checkout' ) {
				$payment_id = $order->get_transaction_id();
				$paymentResponse = $this->retrieve_payment_intent( $payment_id );
				$status = $paymentResponse['status'];
				$pm = $paymentResponse['payment_method'];
				$pmt = $paymentResponse['payment_method_type'];
				$intermediate_status = array( "processing", "requires_merchant_action", "requires_customer_action", "requires_confirmation", "requires_capture" );
				$msg = $esc_html__;

				global $woocommerce;
				if ( $status == 'succeeded' ) {
					$woocommerce->cart->empty_cart();
				} else if ( in_array( $status, $intermediate_status ) ) {
					if ( $status == 'requires_capture' ) {
						$msg = "Thank you for shopping with us. Please note that your payment has been authorized and can now be captured by the merchant. Kindly check the status of your order after some time.";
					} else {
						$msg = "Thank you for shopping with us. Please note that your payment is currently being processed. Kindly check the status of your order after some time.";
					}
					$woocommerce->cart->empty_cart();
				} else {
					$msg = "Thank you for shopping with us. However, the payment has failed. Please retry the payment.";
				}
				$this->post_log(
					"WC_THANK_YOU_MESSAGE",
					array(
						"payment_method" => $pm,
						"payment_method_type" => $pmt,
						"order_id" => $order_id,
						"payment_id" => $payment_id,
						"message" => $msg
					)
				);
				return esc_html( $msg );
			} else {
				return $esc_html__;
			}
		}

		function place_order_custom_button( $button_html ) {
			// not escaping here as the only variable is $button_html which is returned by default even otherwise
			echo
				'<div onclick="' .
				'const paymentMethod = new URLSearchParams(jQuery(\'form.checkout\').serialize()).get(\'payment_method\');' .
				'if (paymentMethod == \'hyperswitch_checkout\') {' .
				'event.preventDefault();' .
				'handleHyperswitchAjax();' .
				'}' .
				'">' . $button_html . '</div>'
			;
		}


		function process_refund( $order_id, $amount = NULL, $reason = '' ) {
			$refund = new WC_Order_Refund;
			$order = wc_get_order( $order_id );
			$payment_id = $order->get_transaction_id();
			$refund_num = $refund->id;
			$responseObj = $this->create_refund( $payment_id, $amount, $reason, $refund_num, $order_id );
			$status = $responseObj['status'];
			$refund_id = $responseObj['refund_id'];
			$intermediate_status = array( "pending" );
			if ( $status == 'succeeded' ) {
				$refund->set_refunded_payment( true );
				$order->add_order_note( 'Refund Successful (Hyperswitch Refund ID: ' . $refund_id . ')' );
			} else if ( in_array( $status, $intermediate_status ) ) {
				$order->add_order_note( 'Refund processing (Hyperswitch Refund ID: ' . $refund_id . ')' );
				$refund->set_refunded_payment( true );
				$refund->set_status( "processing" );
			} else {
				$refund->set_refunded_payment( false );
				$order->add_order_note( 'Refund failed with error message: ' . $responseObj['error']['message'] );
				return false;
			}
			$this->post_log( "WC_MANUAL_REFUND", $status, $payment_id );
			return true;
		}

		function hyperswitch_add_manual_actions( $actions ) {
			global $theorder;
			$this->method_title = __( 'Hyperswitch' );
			$payment_id = $theorder->get_transaction_id();
			$terminal_status = array( "processing", "refunded" );
			// bail if the order has been paid for or this action has been run
			if ( ! ( $theorder->is_paid() || $theorder->get_meta( 'payment_captured' ) == 'yes' || strlen( $payment_id ) < 3 || in_array( $theorder->status, $terminal_status ) ) ) {
				$actions['wc_manual_capture_action'] = __( 'Capture Payment with Hyperswitch', 'hyperswitch-checkout' );
			}

			if ( ! ( in_array( $theorder->status, $terminal_status ) || strlen( $payment_id ) < 3 ) ) {
				$actions['wc_manual_sync_action'] = __( 'Sync Payment with Hyperswitch', 'hyperswitch-checkout' );
			}
			return $actions;
		}

		function hyperswitch_process_manual_capture_action( $order ) {
			$payment_id = $order->get_transaction_id();
			$responseObj = $this->manual_capture( $payment_id );
			$status = $responseObj['status'];
			$payment_method = $responseObj['payment_method'];
			$intermediate_status = array( "processing", "requires_merchant_action", "requires_customer_action", "requires_confirmation", "requires_capture" );
			if ( $status == 'succeeded' ) {
				if ( $order->status !== 'processing' && $order->status !== 'refunded' ) {
					$order->payment_complete( $payment_id );
					$order->add_order_note( 'Manual Capture successful (Hyperswitch Payment ID: ' . $payment_id . ')' );
					$order->add_order_note( 'Payment successful via ' . $payment_method . ' (Hyperswitch Payment ID: ' . $payment_id . ')' );
					$order->update_meta_data( 'payment_captured', 'yes' );
					$this->post_log( "WC_ORDER_PLACED", null, $payment_id );
				}
			} else if ( in_array( $status, $intermediate_status ) ) {
				$order->update_status( $this->processing_payment_order_status );
				$order->add_order_note( 'Manual Capture processing (Hyperswitch Payment ID: ' . $payment_id . ')' );
			} else {
				$order->update_status( $this->processing_payment_order_status );
				$errorMessage = $responseObj['error']['message'] ?? $responseObj['error_code'] ?? "NA";
				$order->add_order_note( 'Manual Capture failed (Hyperswitch Payment ID: ' . $payment_id . ') with error message: ' . $errorMessage );
			}
			$this->post_log( "WC_MANUAL_CAPTURE", $status, $payment_id );
		}

		function hyperswitch_process_manual_sync_action( $order ) {
			$payment_id = $order->get_transaction_id();
			$responseObj = $this->retrieve_payment_intent( $payment_id );
			$status = $responseObj['status'];
			$payment_method = $responseObj['payment_method'];
			$intermediate_status = array( "processing", "requires_merchant_action", "requires_customer_action", "requires_confirmation", "requires_capture" );
			$order->add_order_note( 'Synced Payment Status (Hyperswitch Payment ID: ' . $payment_id . ')' );
			if ( $status == 'succeeded' ) {
				if ( $order->status !== 'processing' && $order->status !== 'refunded' ) {
					$this->post_log( "WC_ORDER_PLACED", null, $payment_id );
				}
				if ( $order->status !== 'refunded' ) {
					$order->add_order_note( 'Payment successful via ' . $payment_method . ' (Hyperswitch Payment ID: ' . $payment_id . ')' );
					$order->payment_complete( $payment_id );
				}
			} else if ( in_array( $status, $intermediate_status ) ) {
				$order->add_order_note( 'Payment processing via ' . $payment_method . ' (Hyperswitch Payment ID: ' . $payment_id . ')' );
			} else {
				$order->add_order_note( 'Payment failed via ' . $payment_method . ' (Hyperswitch Payment ID: ' . $payment_id . ')' );
			}
			$this->post_log( "WC_MANUAL_SYNC", $status, $payment_id );
		}

		public function init_form_fields() {

			$webhookUrl = esc_url( admin_url( 'admin-post.php' ) ) . '?action=hyperswitch_wc_webhook';

			$this->form_fields = array(
				'enabled' => array(
					'title' => __( 'Enable/Disable', 'hyperswitch-checkout' ),
					'label' => __( 'Enable Hyperswitch', 'hyperswitch-checkout' ),
					'type' => 'checkbox',
					'description' => '',
					'default' => 'yes'
				),
				'method_title' => array(
					'title' => __( 'Title', 'hyperswitch-checkout' ),
					'type' => 'textarea',
					'description' => __( 'The title to be displayed for Hyperswitch Payment Method (in case of multiple WooCommerce Payment Gateways/ Methods)', 'hyperswitch-checkout' ),
					'default' => __( 'Credit, Debit Card and Wallet Payments (powered by Hyperswitch)', 'hyperswitch-checkout' )
				),
				'environment' => array(
					'title' => __( 'Environment' ),
					'label' => __( 'Select Environment' ),
					'type' => 'select',
					'options' => array(
						'production' => __( 'Production', 'hyperswitch-checkout' ),
						'sandbox' => __( 'Sandbox', 'hyperswitch-checkout' ),
					),
					'default' => 'sandbox',
				),
				'api_key' => array(
					'title' => 'Api Key',
					'type' => 'password',
					'description' => __( 'Find this on Developers > API Keys section of Hyperswitch Dashboard', 'hyperswitch-checkout' )
				),
				'publishable_key' => array(
					'title' => 'Publishable key',
					'type' => 'text',
					'description' => __( 'Find this on Developers > API Keys section of Hyperswitch Dashboard', 'hyperswitch-checkout' )
				),
				'webhook_secret_key' => array(
					'title' => 'Payment Response Hash Key',
					'type' => 'password',
					'description' => __( 'Find this on Developers > API Keys section of Hyperswitch Dashboard', 'hyperswitch-checkout' )
				),
				'profile_id' => array(
					'title' => 'Business Profile ID',
					'type' => 'text',
					'description' => __( 'Find this on Settings > Business profiles section of Hyperswitch Dashboard', 'hyperswitch-checkout' )
				),
				'enable_webhook' => array(
					'title' => __( 'Enable Webhook', 'hyperswitch-checkout' ),
					'type' => 'checkbox',
					'description' => "Allow webhooks from Hyperswitch to receive real time updates of payments to update orders.<br/><br/><span>$webhookUrl</span><br/><br/>Use this URL to be entered as Webhook URL on Hyperswitch dashboard",
					'label' => __( 'Enable Hyperswitch Webhook', 'hyperswitch-checkout' ),
					'default' => 'yes'
				),
				'capture_method' => array(
					'title' => __( 'Capture Method', 'hyperswitch-checkout' ),
					'label' => __( 'Select Capture Method', 'hyperswitch-checkout' ),
					'type' => 'select',
					'description' => __( "Specify whether you want to capture payments manually or automatically", 'hyperswitch-checkout' ),
					'options' => array(
						'automatic' => __( 'Automatic', 'hyperswitch-checkout' ),
						'manual' => __( 'Manual', 'hyperswitch-checkout' ),
					),
					'default' => 'automatic',
				),
				'enable_saved_payment_methods' => array(
					'title' => __( 'Enable Saved Payment Methods', 'hyperswitch-checkout' ),
					'type' => 'checkbox',
					'description' => __( 'Allow registered customers to pay via saved payment methods', 'hyperswitch-checkout' ),
					'label' => __( 'Enable Saved Payment Methods', 'hyperswitch-checkout' ),
					'default' => 'yes'
				),
				'show_card_from_by_default' => array(
					'title' => __( 'Show Card Form Always', 'hyperswitch-checkout' ),
					'type' => 'checkbox',
					'label' => __( 'Show Card Form before Payment Methods List has loaded', 'hyperswitch-checkout' ),
					'default' => 'yes'
				),
				'appearance' => array(
					'title' => 'Appearance',
					'type' => 'textarea',
					'default' => '{}',
					'description' => __( 'Use the above parameter to pass appearance config (in json format) to the checkout.', 'hyperswitch-checkout' ),
				),
				'layout' => array(
					'title' => 'Layout',
					'label' => 'Select Layout',
					'type' => 'select',
					'description' => __( "Choose a layout that fits well with your UI pattern.", 'hyperswitch-checkout' ),
					'options' => array(
						'tabs' => __( 'Tabs', 'hyperswitch-checkout' ),
						'accordion' => __( 'Accordion', 'hyperswitch-checkout' ),
						'spaced' => __( 'Spaced Accordion', 'hyperswitch-checkout' ),
					),
					'default' => 'tabs',
				),
				'hold_order' => array(
					'title' => __( 'Hold Order on Processing Payments', 'hyperswitch-checkout' ),
					'type' => 'checkbox',
					'description' => __( "Disable this only if you do not want to reduce stock levels until the payment is successful.", 'hyperswitch-checkout' ),
					'label' => __( 'Whether to hold order, reduce stock if a payment goes into processing status.', 'hyperswitch-checkout' ),
					'default' => 'yes'
				),
			);
		}

		function receipt_page( $payment_id ) {
			$payment_intent = $this->create_payment_intent( $payment_id );
			if ( isset( $payment_intent['clientSecret'] ) && isset( $payment_intent['paymentId'] ) ) {
				$client_secret = $payment_intent['clientSecret'];
				$payment_id = $payment_intent['paymentId'];
				$this->post_log( "WC_PAYMENT_INTENT_CREATED", null, $payment_id );
				$return_url = $this->notify_url . '/?payment_id=' . $payment_id;
				echo '
                    <form id="payment-form" data-client-secret="' . esc_html( $client_secret ) . '">
                        <div id="unified-checkout"><!--hyperLoader injects the Unified Checkout--></div>
                        <div id="payment-message" class="hidden"></div>
                    </form>
                    <script>
                        renderHyperswitchSDK("' . esc_html( $client_secret ) . '", "' . esc_url( $return_url ) . '");
                    </script>
                    '
				;
			} else {
				global $woocommerce;
				$order = new WC_Order( $payment_id );
				$error = $payment_intent['body'];
				$order->add_order_note( __( 'Unable to Create Hyperswitch Payment Intent.', 'hyperswitch-checkout' ) );
				$order->add_order_note( 'Error: ' . $error );
				$this->post_log( "WC_FAILED_TO_CREATE_PAYMENT_INTENT", $error );
				if ( function_exists( 'wc_add_notice' ) ) {
					wc_add_notice( __( "Something went wrong. Please contact support for assistance.", 'hyperswitch-checkout' ), 'error' );

				} else {
					$woocommerce->add_error( __( "Something went wrong. Please contact support for assistance.", 'hyperswitch-checkout' ) );
					$woocommerce->set_messages();
				}
				$redirect_url = get_permalink( wc_get_page_id( 'cart' ) );
				wp_redirect( $redirect_url );
				exit;
			}
		}

		function render_payment_sheet( $order_id, $client_secret = null ) {
			$payment_intent = $this->create_payment_intent( $order_id, $client_secret );
			if ( isset( $payment_intent['clientSecret'] ) && isset( $payment_intent['paymentId'] ) ) {
				$client_secret = $payment_intent['clientSecret'];
				$payment_id = $payment_intent['paymentId'];
				$this->post_log( "WC_PAYMENT_INTENT_CREATED", null, $payment_id );
				$return_url = $this->notify_url . '/?payment_id=' . $payment_id;
				$return_html = '
                    <form id="payment-form" data-client-secret="' . esc_html( $client_secret ) . '">
                         <div id="unified-checkout"><!--hyperLoader injects the Unified Checkout--></div>
                        <div id="payment-message" class="hidden"></div>
                    </form>

                    <script>
                        renderHyperswitchSDK("' . esc_html( $client_secret ) . '", "' . esc_url( $return_url ) . '");
                    </script>
                    ';
				return array(
					"payment_sheet" => $return_html
				);
			} else {
				global $woocommerce;
				$order = new WC_Order( $order_id );
				$error = $payment_intent['body'];
				$order->add_order_note( __( 'Unable to Create Hyperswitch Payment Intent.', 'hyperswitch-checkout' ) );
				$order->add_order_note( 'Error: ' . $error );
				$this->post_log( "WC_FAILED_TO_CREATE_PAYMENT_INTENT", $error );
				if ( function_exists( 'wc_add_notice' ) ) {
					wc_add_notice( __( "Something went wrong. Please contact support for assistance.", 'hyperswitch-checkout' ), 'error' );

				} else {
					$woocommerce->add_error( __( "Something went wrong. Please contact support for assistance.", 'hyperswitch-checkout' ) );
					$woocommerce->set_messages();
				}
				$redirect_url = get_permalink( woocommerce_get_page_id( 'cart' ) );
				return array(
					"redirect_url" => $redirect_url
				);
			}
		}

		function create_payment_intent( $order_id, $client_secret = null ) {
			global $woocommerce;
			$order = wc_get_order( $order_id );
			$apiKey = $this->get_option( 'api_key' );
			$profileId = $this->get_option( 'profile_id' );
			$publishable_key = $this->get_option( 'publishable_key' );
			if ( isset( $client_secret ) ) {
				$payment_id = "";
				$parts = explode( "_secret", $client_secret );
				if ( count( $parts ) === 2 ) {
					$payment_id = $parts[0];
				}
				$url = $this->hyperswitch_url . "/payments/" . $payment_id;
			} else {
				$url = $this->hyperswitch_url . "/payments";
			}

			$payload = array();
			$currency = get_woocommerce_currency();
			$amount = (int) ( $woocommerce->cart->total * 100 );
			$billing = array( "phone" => null, "address" => null );
			$shipping = array( "phone" => null, "address" => null );
			$return_url = $this->notify_url;
			$capture_method = $this->get_option( 'capture_method' );
			if ( $order ) {
				$amount = (int) ( $order->get_total() * 100 );
				//billing details
				$billing_city = $order->get_billing_city();
				$billing_state = $order->get_billing_state();
				$billing_country = $order->get_billing_country();
				$billing_zip = $order->get_billing_postcode();
				$billing_first_name = $order->get_billing_first_name();
				$billing_last_name = $order->get_billing_last_name();
				$billing_line1 = $order->get_billing_address_1();
				$billing_line2 = $order->get_billing_address_2();
				$billing_phone = $order->get_billing_phone();
				//shipping details
				$shipping_city = $order->get_shipping_city();
				$shipping_state = $order->get_shipping_state();
				$shipping_country = $order->get_shipping_country();
				$shipping_zip = $order->get_shipping_postcode();
				$shipping_first_name = $order->get_shipping_first_name();
				$shipping_last_name = $order->get_shipping_last_name();
				$shipping_line1 = $order->get_shipping_address_1();
				$shipping_line2 = $order->get_shipping_address_2();
				$shipping_phone = $order->get_shipping_phone();

				$currency = $order->get_currency();
				$phone = $order->get_billing_phone();
				$email = $order->get_billing_email();

				$order_details = array();
				foreach ( $order->get_items() as $item ) {
					$product = wc_get_product( $item->get_product_id() );
					$order_details[] = array(
						"product_name" => $item->get_name(),
						"quantity" => (int) ( $item->get_quantity() ),
						"amount" => (int) ( $product->get_price() * 100 )
					);
				}
				$order_note = $order->get_customer_note();
				$billing_address = array(
					"city" => $billing_city,
					"state" => $billing_state,
					"country" => $billing_country,
					"zip" => $billing_zip,
					"first_name" => $billing_first_name,
					"last_name" => $billing_last_name,
					"line1" => $billing_line1,
					"line2" => $billing_line2
				);

				$billing_phone = array(
					"number" => $billing_phone
				);

				$shipping_address = array(
					"city" => strlen( str_replace( $shipping_city, '"', '' ) ) > 0 ? $shipping_city : $billing_city,
					"state" => strlen( str_replace( $shipping_state, '"', '' ) ) > 0 ? $shipping_state : $billing_state,
					"country" => strlen( str_replace( $shipping_country, '"', '' ) ) > 0 ? $shipping_country : $billing_country,
					"zip" => strlen( str_replace( $shipping_zip, '"', '' ) ) > 0 ? $shipping_zip : $billing_zip,
					"first_name" => strlen( str_replace( $shipping_first_name, '"', '' ) ) > 0 ? $shipping_first_name : $billing_first_name,
					"last_name" => strlen( str_replace( $shipping_last_name, '"', '' ) ) > 0 ? $shipping_last_name : $billing_last_name,
					"line1" => strlen( str_replace( $shipping_line1, '"', '' ) ) > 0 ? $shipping_line1 : $billing_line1,
					"line2" => strlen( str_replace( $shipping_line2, '"', '' ) ) > 0 ? $shipping_line2 : $billing_line2
				);

				if ( strlen( str_replace( $shipping_phone, '"', '' ) ) > 0 ) {
					$shipping_phone = array(
						"phone" => $shipping_phone
					);
				} else {
					$shipping_phone = $billing_phone;
				}

				$billing = array(
					"address" => $billing_address,
					"phone" => $billing_phone
				);

				$shipping = array(
					"address" => $shipping_address,
					"phone" => $shipping_phone
				);
			}

			$customer = wp_get_current_user();
			if ( $customer->ID === 0 ) {
				$customer_id = "guest";
				$customer_id_hash = substr( hash_hmac( 'sha512', $customer_id, time() . "" . $publishable_key ), 0, 16 );
				$customer_id = "guest_" . $customer_id_hash;
			} else {
				$customer_id = $customer->ID . "";
				$customer_registered = $customer->user_registered;
				$customer_id_hash = substr( hash_hmac( 'sha512', $customer_id, $customer_registered . "" . $publishable_key ), 0, 16 );
				$customer_id = "cust_" . $customer_id_hash;
			}

			$customer_created = true;
			$customer_logged_in = str_starts_with( $customer_id, "cust" );

			$metadata = array(
				"customer_created" => $customer_created ? "true" : "false",
				"customer_logged_in" => $customer_logged_in ? "true" : "false",
			);

			if ( $order ) {
				$metadata["order_num"] = $order_id;
				$metadata["customer_note"] = $order_note;
				$payload["email"] = $email;
				$payload["name"] = $billing_first_name . " " . $billing_last_name;
				$payload["phone"] = $phone;
				$payload["order_details"] = $order_details;
			}
			$payload["customer_id"] = $customer_id;
			$payload["metadata"] = $metadata;
			$payload["return_url"] = $return_url;
			$payload["shipping"] = $shipping;
			$payload["billing"] = $billing;
			$payload["capture_method"] = $capture_method;
			$payload["amount"] = $amount;
			$payload["currency"] = $currency;
			if ( isset( $profileId ) ) {
				$payload["profile_id"] = $profileId;
			}

			$args = array(
				'body' => wp_json_encode( $payload ),
				'timeout' => 45,
				'redirection' => 5,
				'httpversion' => 1.0,
				'blocking' => true,
				'data_format' => 'body',
				'headers' => array(
					'Content-Type' => 'application/json',
					'api-key' => $apiKey
				)
			);

			$response = wp_remote_retrieve_body( wp_remote_post( $url, $args ) );

			// Parse the 'client_secret' key from the response
			$responseData = json_decode( $response, true );
			$clientSecret = isset( $responseData['client_secret'] ) ? $responseData['client_secret'] : null;
			$paymentId = isset( $responseData['payment_id'] ) ? $responseData['payment_id'] : null;
			$error = isset( $responseData['error'] ) ? $responseData['error'] : null;
			return array(
				"clientSecret" => $clientSecret,
				"paymentId" => $paymentId,
				"error" => json_encode( $error ),
				"body" => wp_json_encode( $payload ),
			);
		}

		function retrieve_payment_intent( $payment_id ) {
			$apiKey = $this->get_option( 'api_key' );

			$url = $this->hyperswitch_url . "/payments/" . $payment_id;

			$args = array(
				'timeout' => 45,
				'redirection' => 5,
				'httpversion' => 1.0,
				'blocking' => true,
				'headers' => array(
					'Content-Type' => 'application/json',
					'api-key' => $apiKey
				)
			);

			// Execute the request
			$response = wp_remote_retrieve_body( wp_remote_get( $url, $args ) );

			return json_decode( $response, true );
		}

		public function create_customer( $customer_id, $name, $email ) {
			$apiKey = $this->get_option( 'api_key' );

			$url = $this->hyperswitch_url . "/customers";
			$payload = array(
				"customer_id" => $customer_id,
				"email" => $email,
				"name" => $name,
				"description" => __( "Customer created via Woocommerce Application", 'hyperswitch-checkout' )
			);
			$args = array(
				'body' => wp_json_encode( $payload ),
				'timeout' => 45,
				'redirection' => 5,
				'httpversion' => 1.0,
				'blocking' => true,
				'data_format' => 'body',
				'headers' => array(
					'Content-Type' => 'application/json',
					'api-key' => $apiKey
				)
			);

			$response = wp_remote_retrieve_body( wp_remote_post( $url, $args ) );
			return json_decode( $response, true );
		}

		public function post_log( $event_name, $value = null, $payment_id = null ) {
			$publishable_key = $this->get_option( 'publishable_key' );

			switch ( $this->environment ) {
				case "sandbox":
					$url = "https://sandbox.hyperswitch.io/logs/sdk";
					break;
				case "production":
					$url = "https://api.hyperswitch.io/logs/sdk";
					break;
				default:
					$url = "https://sandbox.hyperswitch.io/logs/sdk";
					break;
			}

			if ( str_contains( $event_name, "ERROR" ) ) {
				$log_type = "ERROR";
			} else {
				$log_type = "INFO";
			}

			$payload = array(
				"merchant_id" => $publishable_key,
				"event_name" => $event_name,
				"component" => "PLUGIN",
				"version" => HYPERSWITCH_CHECKOUT_PLUGIN_VERSION,
				"source" => "WORDPRESS",
				"log_type" => $log_type,
				"log_category" => "MERCHANT_EVENT",
				"timestamp" => floor( microtime( true ) * 1000 ) . ""
			);

			if ( isset( $payment_id ) ) {
				$payload["payment_id"] = $payment_id;
			}

			if ( isset( $value ) ) {
				$payload["value"] = $value;
			}

			$data = array(
				"data" => [ $payload ]
			);

			$args = array(
				'body' => wp_json_encode( $data ),
				'timeout' => 45,
				'redirection' => 5,
				'httpversion' => 1.0,
				'blocking' => false,
				'data_format' => 'body',
				'headers' => array(
					'Content-Type' => 'application/json'
				)
			);

			$response = wp_remote_retrieve_body( wp_remote_post( $url, $args ) );
			return json_decode( $response, true );
		}

		public function manual_capture( $payment_id ) {
			$apiKey = $this->get_option( 'api_key' );

			$url = $this->hyperswitch_url . "/payments/" . $payment_id . "/capture";

			// Execute the request
			$args = array(
				'timeout' => 45,
				'redirection' => 5,
				'httpversion' => 1.0,
				'blocking' => false,
				'headers' => array(
					'Content-Type' => 'application/json',
					'api-key' => $apiKey
				)
			);

			$response = wp_remote_retrieve_body( wp_remote_post( $url, $args ) );
			return json_decode( $response, true );
		}

		public function create_refund( $payment_id, $amount, $reason, $refund_num, $order_id ) {
			$apiKey = $this->get_option( 'api_key' );

			$url = $this->hyperswitch_url . "/refunds";

			$metadata = array(
				"refund_num" => $refund_num,
				"order_num" => $order_id
			);

			$payload = array(
				"payment_id" => $payment_id,
				"amount" => ( (int) $amount * 100 ),
				"reason" => $reason,
				"refund_type" => "instant",
				"metadata" => $metadata
			);

			// Execute the request
			$args = array(
				'body' => wp_json_encode( $payload ),
				'timeout' => 45,
				'redirection' => 5,
				'httpversion' => 1.0,
				'blocking' => false,
				'data_format' => 'body',
				'headers' => array(
					'Content-Type' => 'application/json',
					'api-key' => $apiKey
				)
			);

			$response = wp_remote_retrieve_body( wp_remote_post( $url, $args ) );

			return json_decode( $response, true );
		}

		/**
		 * Process the payment and return the result
		 **/
		function process_payment( $payment_id ) {
			$nonce = $_POST['woocommerce-process-checkout-nonce'];
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'woocommerce-process_checkout' ) ) {
				return array( 'result' => 'failure', 'nonce' => 'failed' );
			}
			$order = new WC_Order( $payment_id );
			return array( 'result' => 'success', 'redirect' => $order->get_checkout_payment_url( true ) );
		}

		public function check_hyperswitch_response() {

			global $woocommerce;
			$payment_id = $_GET['payment_id'];

			$msg['class'] = 'error';
			$msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";

			$paymentResponse = $this->retrieve_payment_intent( $payment_id );
			$status = $paymentResponse['status'];
			$payment_method = $paymentResponse['payment_method'];
			$intermediate_status = array( "processing", "requires_merchant_action", "requires_customer_action", "requires_confirmation", "requires_capture" );
			$order_num = ( $paymentResponse['metadata'] )['order_num'];
			$order = new WC_Order( $order_num );
			if ( $status == 'succeeded' ) {
				$msg['class'] = 'success';
				$order->payment_complete( $payment_id );
				$order->add_order_note( 'Payment successful via ' . $payment_method . ' (Hyperswitch Payment ID: ' . $payment_id . ')' );
				$woocommerce->cart->empty_cart();
				$this->post_log( "WC_ORDER_PLACED", null, $payment_id );
			} else if ( in_array( $status, $intermediate_status ) ) {
				$original_status = $order->status;
				if ( $status == 'requires_capture' ) {
					$order->add_order_note( 'Payment authorized via ' . $payment_method . ' (Hyperswitch Payment ID: ' . $payment_id . '). Note: Requires Capture' );
				} else {
					$order->add_order_note( 'Payment processing via ' . $payment_method . ' (Hyperswitch Payment ID: ' . $payment_id . ')' );
				}
				$order->set_transaction_id( $payment_id );
				$order->update_status( $this->processing_payment_order_status );
				$woocommerce->cart->empty_cart();
				$msg['class'] = 'success';
				$this->post_log( "WC_ORDER_ON_HOLD", $status, $payment_id );
			} else {
				$errorMessage = $paymentResponse['error']['message'] ?? $paymentResponse['error_code'] ?? "NA";
				$order->add_order_note( 'Payment failed via ' . $payment_method . ' (Hyperswitch Payment ID: ' . $payment_id . ') with error message: ' . $errorMessage );
				$order->update_status( 'pending' );
				$msg['class'] = 'error';
				$msg['message'] = "Thank you for shopping with us. However, the payment has failed. Please retry the payment.";
				$this->post_log( "WC_ERROR_IN_PLACING_ORDER", $errorMessage, $payment_id );
			}

			if ( $paymentResponse['capture_method'] === "automatic" ) {
				$order->update_meta_data( 'payment_captured', 'yes' );
			}

			if ( $msg['class'] != 'success' ) {
				if ( function_exists( 'wc_add_notice' ) ) {
					wc_add_notice( $msg['message'], $msg['class'] );

				} else {
					$woocommerce->add_error( $msg['message'] );
					$woocommerce->set_messages();
				}
			}

			if ( $msg['class'] == 'success' ) {
				$redirect_url = $this->get_return_url( $order );
			} else {
				$redirect_url = get_permalink( woocommerce_get_page_id( 'cart' ) );
			}
			$order->save();
			wp_redirect( $redirect_url );
			exit;
		}
	}

	function hyperswitch_add_payment_class( $gateways ) {
		$gateways[] = 'Hyperswitch_Checkout';
		return $gateways;
	}

	add_filter( 'woocommerce_payment_gateways', 'hyperswitch_add_payment_class' );

}

// This is set to a priority of 10
function hyperswitch_webhook_init() {
	$hyperswitchWebhook = new Hyperswitch_Webhook();
	$hyperswitchWebhook->process();
}

function hyperswitch_create_or_update_payment_intent() {
	$nonce = $_POST['wc_nonce'];
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $nonce ) ), 'woocommerce-process_checkout' ) ) {
		wp_send_json(
			array(
				"messages" => __( "Something went wrong. Please try again or reload the page.", 'hyperswitch-checkout' )
			)
		);
	} else {
		$hyperswitch = new Hyperswitch_Checkout();
		$order_id = $_POST['order_id'];
		$client_secret = $_POST['client_secret'];
		if ( ! isset( $client_secret ) ) {
			$hyperswitch->post_log( "WC_ORDER_CREATE", null, $order_id );
		} else {
			$payment_id = "";
			$parts = explode( "_secret", $client_secret );
			if ( count( $parts ) === 2 ) {
				$payment_id = $parts[0];
			}
			$hyperswitch->post_log( "WC_ORDER_UPDATE", $payment_id, $order_id );
		}
		$payment_sheet = $hyperswitch->render_payment_sheet( $order_id, $client_secret );
		if ( isset( $payment_sheet['payment_sheet'] ) ) {
			$hyperswitch->post_log( "WC_CHECKOUT_INITIATED", $order_id, $order_id );
			wp_send_json(
				array(
					"order_id" => $order_id,
					"payment_sheet" => $payment_sheet['payment_sheet']
				)
			);
		} else {
			$hyperswitch->post_log( "WC_INTEGRATION_ERROR", $order_id, $order_id );
			wp_send_json(
				array(
					"order_id" => $order_id,
					"redirect" => $payment_sheet['redirect_url']
				)
			);
		}
	}
}

function hyperswitch_declare_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
}