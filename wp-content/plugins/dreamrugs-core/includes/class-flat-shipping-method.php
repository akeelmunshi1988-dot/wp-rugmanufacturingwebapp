<?php
/**
 * The WooCommerce shipping method behind DRC_Shipping — loaded on
 * woocommerce_shipping_init, once WC_Shipping_Method exists.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Flat_Shipping_Method extends WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = DRC_Shipping::METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = 'DreamRugs flat shipping';
		$this->method_description = 'Charges the "Default Shipping Rate" from DreamRugs → Settings once per order. Free when a free-shipping coupon is applied.';
		$this->supports           = array( 'shipping-zones', 'instance-settings' );
		$this->title              = 'Shipping';
		$this->enabled            = 'yes';
		$this->init_instance_settings();
		$this->title = $this->get_option( 'title', 'Shipping' );
	}

	public function get_instance_form_fields() {
		return array(
			'title' => array(
				'title'   => 'Label shown at checkout',
				'type'    => 'text',
				'default' => 'Shipping',
			),
		);
	}

	public function calculate_shipping( $package = array() ) {
		$cost = (float) DRC_Settings::get( 'default_shipping_rate' );
		foreach ( WC()->cart ? WC()->cart->get_applied_coupons() : array() as $code ) {
			$coupon = new WC_Coupon( $code );
			if ( $coupon->get_free_shipping() ) {
				$cost = 0.0;
				break;
			}
		}
		$this->add_rate( array(
			'id'    => $this->get_rate_id(),
			'label' => $cost > 0 ? $this->title : $this->title . ' (free)',
			'cost'  => DRC_Multi_Currency::convert( $cost ),
		) );
	}
}
