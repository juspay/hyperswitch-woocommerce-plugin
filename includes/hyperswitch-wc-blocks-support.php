<?php
if ( ! defined( 'ABSPATH' ) )
	exit; // Exit if accessed directly
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

require_once __DIR__ . '/../hyperswitch-checkout.php';

final class Hyperswitch_Checkout_Blocks extends AbstractPaymentMethodType {
	private $gateway;
	protected $name = 'hyperswitch_checkout';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_hyperswitch_checkout_settings', [] );
		$this->gateway = new Hyperswitch_Checkout();
	}

	public function is_active() {
		return ! empty ( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'hyperswitch_checkout-blocks-integration',
			HYPERSWITCH_PLUGIN_URL . '/js/hyperswitch-checkout-blocks.js',
			[ 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ],
			HYPERSWITCH_CHECKOUT_PLUGIN_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'hyperswitch_checkout-blocks-integration' );
		}

		return [ 'hyperswitch_checkout-blocks-integration' ];
	}

	public function get_payment_method_data() {
		return [ 'title' => $this->gateway->title ];
	}
}