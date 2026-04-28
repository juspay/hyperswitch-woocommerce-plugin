<?php
/**
 * Plugin Name: Hyperswitch WooCommerce Plugin
 * Plugin URI: https://hyperswitch.io
 * Description: WooCommerce payment gateway integration for Hyperswitch
 * Version: 1.0.0
 * Author: Hyperswitch
 * Author URI: https://hyperswitch.io
 * Text Domain: hyperswitch
 * Domain Path: /locale
 * Requires PHP: 7.0
 */
if ( ! defined( 'ABSPATH' ) )
	exit; // Exit if accessed directly

require_once __DIR__ . '/../hyperswitch-checkout.php';

class Hyperswitch_Webhook {

	protected $hyperswitch;
	const PAYMENT_SUCCEEDED = 'payment_succeeded';
	const PAYMENT_FAILED = 'payment_failed';
	const PAYMENT_PROCESSING = 'payment_processing';
	const ACTION_REQUIRED = 'action_required';
	const REFUND_SUCCEEDED = 'refund_succeeded';

	protected $eventsArray = [ 
		self::PAYMENT_SUCCEEDED,
		self::PAYMENT_FAILED,
		self::PAYMENT_PROCESSING,
		self::ACTION_REQUIRED,
		self::REFUND_SUCCEEDED,
	];

	public function __construct() {
		$this->hyperswitch = new Hyperswitch_Checkout();
		
		// Load plugin text domain
		add_action('init', array($this, 'load_plugin_textdomain'));
	}

	/**
	 * Load plugin textdomain.
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'hyperswitch',
			false,
			dirname(plugin_basename(__FILE__)) . '/locale/'
		);
	}

	public function process() {
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
			$raw_post_data = file_get_contents( 'php://input' );
			if ( ! empty( $raw_post_data ) ) {
				$data = json_decode( $raw_post_data, true );

				$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE_512'];

				if ( json_last_error() !== 0 ) {
					return;
				}

				$enabled = $this->hyperswitch->get_option( 'enable_webhook' );

				if ( ( $enabled === 'yes' ) && ( ! empty( $data['event_type'] ) ) ) {
					$payment_id = $data['content']['object']['payment_id'];
					$this->hyperswitch->post_log( 
						/* translators: Webhook received log message */
						__( 'WC_WEBHOOK_RECEIVED', 'hyperswitch' ), 
						$data['event_type'], 
						$payment_id 
					);
					
					if ( $this->shouldConsumeWebhook( $data, $signature, $raw_post_data ) === false ) {
						return;
					}
					
					switch ( $data['event_type'] ) {
						case self::PAYMENT_SUCCEEDED:
							return $this->paymentAuthorized( $data );

						case self::PAYMENT_FAILED:
							return $this->paymentHandle( $data, __( 'failed', 'hyperswitch' ) );

						case self::PAYMENT_PROCESSING:
							return $this->paymentHandle( $data, __( 'processing', 'hyperswitch' ) );

						case self::ACTION_REQUIRED:
							return $this->paymentHandle( $data, __( 'action required', 'hyperswitch' ) );

						case self::REFUND_SUCCEEDED:
							return $this->refundedCreated( $data );

						default:
							return;
					}
				}
			}
		}
	}

	protected function paymentAuthorized( array $data ) {
		$order_id = $data['content']['object']['metadata']['order_num'];
		$order = wc_get_order( $order_id );

		if ( $order ) {
			$payment_id = $data['content']['object']['payment_id'];
			$payment_method = $data['content']['object']['payment_method'];

			if ( $order->status !== 'processing' ) {
				$this->hyperswitch->post_log( 
					/* translators: Order placed log message */
					__( 'WC_ORDER_PLACED', 'hyperswitch' ), 
					null, 
					$payment_id 
				);
			}
			
			$order->payment_complete( $payment_id );
			$order->add_order_note( sprintf( 
				/* translators: 1: Payment method 2: Payment ID */
				__( 'Payment successful via %1$s (Hyperswitch Payment ID: %2$s) (via Hyperswitch Webhook)', 'hyperswitch' ), 
				$payment_method, 
				$payment_id 
			));

			if ( $data['object']['capture_method'] === "automatic" ) {
				$order->update_meta_data( 'payment_captured', 'yes' );
			}
			
			$this->hyperswitch->post_log( 
				/* translators: Payment succeeded webhook log message */
				__( 'WC_PAYMENT_SUCCEEDED_WEBHOOK', 'hyperswitch' ), 
				null, 
				$payment_id 
			);
		} else {
			exit; // Not a WooCommerce Order
		}

		exit;
	}

	protected function paymentHandle( array $data, $status ) {
		$order_id = $data['content']['object']['metadata']['order_num'];
		$order = wc_get_order( $order_id );

		if ( $order ) {
			$payment_id = $data['content']['object']['payment_id'];
			$payment_method = $data['content']['object']['payment_method'];
			$order->set_transaction_id( $payment_id );

			if ( $status == __( 'processing', 'hyperswitch' ) ) {
				$order->update_status( $this->hyperswitch->processing_payment_order_status );
			} else {
				$order->update_status( __( 'pending', 'hyperswitch' ) );
			}

			$order->add_order_note( sprintf( 
				/* translators: 1: Payment status 2: Payment method 3: Payment ID */
				__( 'Payment %1$s via %2$s (Hyperswitch Payment ID: %3$s) (via Hyperswitch Webhook)', 'hyperswitch' ), 
				$status, 
				$payment_method, 
				$payment_id 
			));
			
			$this->hyperswitch->post_log( 
				/* translators: Payment status webhook log message */
				__( sprintf('WC_PAYMENT_%s_WEBHOOK', str_replace( " ", "_", strtoupper( $status ) )), 'hyperswitch' ), 
				null, 
				$payment_id 
			);
		}

		exit;
	}

	protected function refundedCreated( array $data ) {
		$payment_id = $data['content']['object']['payment_id'];
		if ( isset( $data['content']['object']['metadata']['order_num'] ) ) {
			$order_id = $data['content']['object']['metadata']['order_num'];
		} else {
			$payment_intent = $this->hyperswitch->retrieve_payment_intent( $payment_id );
			$order_id = $payment_intent['metadata']['order_num'];
		}

		$refund_num = $data['content']['object']['metadata']['refund_num'];
		$refund_id = $data['content']['object']['refund_id'];

		$order = wc_get_order( $order_id );

		if ( $order ) {
			$refunds = $order->get_refunds();
			$targetRefund = null;
			if ( isset( $refund_num ) ) {
				foreach ( $refunds as $refund ) {
					if ( $refund->id == $refund_num ) {
						$targetRefund = $refund;
						break;
					}
				}
			}

			if ( ! isset( $targetRefund ) ) { // Refund initiated via Hyperswitch Dashboard
				$refund = new WC_Order_Refund;
				$amount = $data['content']['object']['amount'];
				$reason = $data['content']['object']['reason'];
				$refund->set_amount( (float) $amount / 100 );
				$refund->set_reason( $reason );
			}

			$order->add_order_note( sprintf( 
				/* translators: %s: Refund ID */
				__( 'Refund Successful (Hyperswitch Refund ID: %1$s) (via Hyperswitch Webhook)', 'hyperswitch' ), 
				$refund_id 
			));
			$refund->set_refunded_payment( true );
		} else {
			exit; // Not a refund for a WooCommerce Order
		}

		exit;
	}

	protected function shouldConsumeWebhook( $data, $signature, $payload ) {
		$webhook_key = $this->hyperswitch->get_option( 'webhook_secret_key' );
		$generated_signature = hash_hmac( 'sha512', $payload, $webhook_key );
		$signature_verification_result = $generated_signature === $signature;

		if (
			isset( $data['event_type'] ) && 
			in_array( $data['event_type'], $this->eventsArray ) && 
			$signature_verification_result
		) {
			return true;
		}

		return false;
	}
}