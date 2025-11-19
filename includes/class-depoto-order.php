<?php

/**
 * @package         Depoto_Order
 */
class Depoto_Order {
	/**@var WC_Order */
	private $order;
	private $depoto_api;
	private $taxes_pairs;
	/** as ['percent' => 'depoto_id'] */

	/**
	 * @param $depoto_api Depoto_API
	 */
	public function __construct( $depoto_api ) {
		$this->depoto_api = $depoto_api;
		add_action( 'woocommerce_checkout_order_created', [ $this, 'schedule_order' ] );
		add_action( 'depoto_create_order', [ $this, 'process_order' ] );
		add_action( 'woocommerce_order_status_changed', [ $this, 'schedule_update_payment_status' ], 10, 3 );
		add_action( 'woocommerce_order_status_cancelled', [ $this, 'schedule_cancel_depoto_order' ], 10, 3 );
		add_action( 'depoto_update_payment_status', [ $this, 'update_order_payment_status' ] );
		add_action( 'depoto_cancel_order', [ $this, 'cancel_order' ] );
		$this->taxes_pairs = $this->get_taxes_pairs();
	}

	public function schedule_order( $order ) {
		as_enqueue_async_action( 'depoto_create_order', [ 'order_id' => $order->get_id() ] );
	}

	/**
	 * Verify if the shipping address is different
	 *
	 * @return bool
	 */
	private function is_different_shipping_address(): bool {
		if ( $this->get_packeta_point_id() ) {
			return true;
		}
		$billing_address  = $this->order->get_address();
		$shipping_address = $this->order->get_address( 'shipping' );

		if ( ! empty( $billing_address ) && ! empty( $shipping_address ) ) {
			foreach ( $billing_address as $billing_address_key => $billing_address_value ) {
				if ( ! empty( $shipping_address[ $billing_address_key ] ) ) {
					$shipping_address_value = $shipping_address[ $billing_address_key ];

					if ( ! empty( $billing_address_value ) && ! empty( $shipping_address_value ) && strcmp( $billing_address_value, $shipping_address_value ) !== 0 ) {
						return true;
					}
				}
			}
		}

		return false;
	}


	private function get_taxes_pairs() {
		$taxes_options = get_option( 'depoto_taxes' );

		if ( ! $taxes_options ) {
			return [];
		}

		$result = [];
		/**
		 * @var string $wc_tax_id format is 'tax_id_2'
		 */
		foreach ( $taxes_options as $wc_tax_id => $depoto_id ) {
			$tax_id                      = substr( $wc_tax_id, 7 ); // format is 'tax_id_2' so we want just the number of id
			$tax_rate_percent            = WC_Tax::get_rate_percent_value( intval( $tax_id ) );
			$result[ $tax_rate_percent ] = $depoto_id;
		}

		return $result;
	}

	private function get_vat_depoto_id( $price_with_tax, $tax ) {
		$res_depoto_vat_id = 0;

		if ( ! empty( $price_with_tax ) && ! empty( $tax ) ) {
			$percent           = intval( round( ( 100 / $price_with_tax ) * $tax ) );
			$res_depoto_vat_id = intval( $this->taxes_pairs[ $percent ] ?? 0 );
		}

		if ( $res_depoto_vat_id ) {
			return $res_depoto_vat_id;
		}

		$keys          = array_keys( $this->taxes_pairs );
		$max_key       = max( $keys );
		$depoto_vat_id = $this->taxes_pairs[ $max_key ];

		return $depoto_vat_id;
	}

	private function get_shipping_vat_id() {
		$taxes = [];
		foreach ( $this->order->get_taxes() as $tax ) {
			$taxes[] = $tax->get_rate_percent();
		}
		$max_tax = max( $taxes );

		$depoto_vat_id = $this->taxes_pairs[ $max_tax ] ?? null;
		if ( empty( $depoto_vat_id ) ) {
			$keys    = array_keys( $this->taxes_pairs );
			$max_key = max( $keys );

			return $this->taxes_pairs[ $max_key ];
		}

		return $depoto_vat_id;
	}

	private function get_packeta_point_id() {
		$result = $this->order->get_meta('zasilkovna_id_pobocky');
		if (!$result) {
			global $wpdb;
			$result = $wpdb->get_var(
				"SELECT point_id FROM {$wpdb->prefix}packetery_order WHERE id={$this->order->get_id()}"
			);

		}

		return $result;
	}

	public function process_undelivered_orders() {
		$initial_date = date( 'Y-m-d', strtotime( "-1 week" ) );
		$final_date   = date( 'Y-m-d' );
		$orders       = wc_get_orders(
			[
				'limit'        => - 1,
				'type'         => 'shop_order',
				'date_created' => $initial_date . '...' . $final_date,
			]
		);

		echo 'Total ' . count( $orders ) . ' orders<br>';

		foreach ( $orders as $order ) {
			$this->process_order( $order->get_id() );
		}
	}

	public function process_order( $order_id ) {
		try {
			$this->order = wc_get_order( $order_id );

			if ( ! empty( $depoto_order_id = $this->order->get_meta( '_depoto_order_id', true ) ) ) {
				if ( is_admin() ) {
					printf( __( 'Order %s already has depoto order id %s', 'depoto' ), $order_id, $depoto_order_id );
					echo '<br>';
				}

				return;
			}

			if ( $this->is_different_shipping_address() ) {
				$shipping_address = $this->process_address( 0 );
			} else {
				$shipping_address = null;
			}

			$billing_address = $this->process_address( 1 );

			$data_for_depoto = [
				'status'            => 'reservation',
				'checkout'          => (int) get_option( 'depoto_checkout_id' ),
				//'customer' => $resultCustomer['data']['id'], // Nepovinné
				'invoiceAddress'    => $billing_address,
				'shippingAddress'   => $shipping_address ?? $billing_address,
				'currency'          => $this->order->get_currency(),
				'carrier'           => $this->process_carrier(),
				'items'             => $this->process_order_items(),
				'reservationNumber' => $this->order->get_order_number(),
				'paymentItems'      => [
					[
						'payment' => $this->process_payment(),
						'amount'  => $this->order->get_total(),
						'isPaid'  => false, // Zaplaceno - true/false
					],
				],
			];


			$result = $this->depoto_api->create_order( $data_for_depoto );
			$this->order->update_meta_data( '_depoto_order_id', $result );
			$this->order->save_meta_data();
			if ( is_admin() ) {
				printf( __( 'Order %s successfuly sent and received order depoto ID %s', 'depoto' ), $order_id, $result );
				echo '<br>';
			}
		} catch ( Exception $e ) {
			$this->order->add_order_note( $e->getMessage() );
			return false;
		}

		return true;
	}

	/**
	 * Prepare address and creates it in the depoto
	 *
	 * @param int $is_billing can be 0 or 1, parametr for depoto
	 *
	 * @return int address id returned from depoto
	 */
	public function process_address( $is_billing ) {
		if ( $is_billing ) {
			$street = $this->order->get_billing_address_1();
			if ($this->order->get_billing_address_2()) {
				$street .= ' ' . $this->order->get_billing_address_2();
			}

			/* Billing */
			$return_array['firstName']   = $this->order->get_billing_first_name() ?? '';
			$return_array['lastName']    = $this->order->get_billing_last_name() ?? '';
			$return_array['companyName'] = $this->order->get_billing_company() ?? '';
			$return_array['street']      = $street;
			$return_array['city']        = $this->order->get_billing_city() ?? '';
			$return_array['zip']         = $this->order->get_billing_postcode() ?? '';
			$return_array['country']     = $this->order->get_billing_country() ?? '';
			$return_array['email']       = $this->order->get_billing_email() ?? '';
			$return_array['phone']       = $this->order->get_billing_phone() ?? '';
			$return_array['isBilling']   = $is_billing;
		} else {
			$street = $this->order->get_shipping_address_1();
			if ($this->order->get_shipping_address_2()) {
				$street .= ' ' . $this->order->get_shipping_address_2();
			}

			/* Shipping */
			$return_array['firstName']   = $this->order->get_shipping_first_name() ?? '';
			$return_array['lastName']    = $this->order->get_shipping_last_name() ?? '';
			$return_array['companyName'] = $this->order->get_shipping_company() ?? '';
			$return_array['street']      = $street;
			$return_array['city']        = $this->order->get_shipping_city() ?? '';
			$return_array['zip']         = $this->order->get_shipping_postcode() ?? '';
			$return_array['country']     = $this->order->get_shipping_country() ?? '';
			$return_array['email']       = $this->order->get_billing_email() ?? ''; // here is the billing value because of there is nothing as get_shipping_email
			$return_array['phone']       = $this->order->get_billing_phone() ?? ''; // here is the billing value because of there is nothing as get_shipping_phone
			if ( $point_id = $this->get_packeta_point_id() ) {
				$return_array['branchId'] = $point_id;
			}

			$return_array['isBilling'] = $is_billing;
		}

		return $this->depoto_api->create_address( $return_array );
	}

	/**
	 * Get order sipping method and pair it according to depoto method
	 * Woocommerce shipping method and depoto shipping method are paired in plugin Depoto.
	 *
	 * @return string depoto carrier id
	 */
	private function process_carrier(): string {
		$carrier = current( $this->order->get_shipping_methods() );

		/**@var WC_Order_Item_Shipping */
		$carrier_method_id    = $carrier->get_method_id();
		$paired_depoto_method = get_option( 'depoto_shippings' )[ $carrier_method_id ];

		return $paired_depoto_method ?? '';
	}

	/**
	 * Get order payment method and pair it according to depoto method
	 * Woocommerce payment method and depoto payment method are paired in plugin Depoto.
	 *
	 * @return string depoto payment id
	 */
	private function process_payment(): string {
		$payment_method_id    = $this->order->get_payment_method();
		$paired_depoto_method = get_option( 'depoto_payments' )[ $payment_method_id ];
		if ( empty( $paired_depoto_method ) ) {
			return '';
		}

		return intval( substr( $paired_depoto_method, 3 ) );
	}

	/**
	 * Get items from Woocommerce order and prepare array of items for depoto
	 *
	 * @return array
	 */
	private function process_order_items(): array {
		$items = $this->order->get_items();


		$return_array = [];

		/** @var WC_Order_Item_Product */
		foreach ( $items as $item_id => $item ) {
			$product_item = [];

			$product = $item->get_product();

			$depoto_id = get_post_meta( $product->get_id(), '_depoto_id', true );
			if ( ! empty( $depoto_id ) ) {
				$product_item['product'] = $depoto_id;
			}

			// WPML compatibility - get SKU from translated product.
			if ( defined( 'ICL_SITEPRESS_VERSION' ) && !$product_item['product']) {
				$default_lang = apply_filters('wpml_default_language', NULL );
				if (method_exists($product, 'get_variation_id')) {
					$id = $product->get_variation_id() ?: $product->get_id();
				} else {
					$id = $product->get_id();
				}

				$original_id  = apply_filters( 'wpml_object_id', $product->get_id(), get_post_type($id), false, $default_lang );
				if ($original_id) {
					$depoto_id = get_post_meta( $original_id, '_depoto_id', true );
					if ( ! empty( $depoto_id ) ) {
						$product_item['product'] = $depoto_id;
					}
				}
			}

			$product_item['code']     = $product->get_sku();
			$product_item['name']     = $item->get_name();
			$product_item['type']     = 'product';
			$product_item['quantity'] = $item->get_quantity();
			$product_item['price']    = $product->get_price();
			$product_item['vat']      = (int)$this->get_vat_depoto_id( $item->get_subtotal(), $item->get_subtotal_tax() );

			$return_array[] = $product_item;
		}

		/* Get shipment item */

		/** @var WC_Order_Item_Shipping $shipping_method */
		$shipping_method          = current( $this->order->get_items( [ 'shipping' ] ) );
		$shipping_total           = $this->order->get_shipping_total();
		$shipping_tax             = $this->order->get_shipping_tax();
		$shpping_item             = [];
		$shpping_item['code']     = $shipping_method->get_method_id();
		$shpping_item['name']     = $shipping_method->get_name();
		$shpping_item['type']     = 'shipping';
		$shpping_item['quantity'] = 1;
		$shpping_item['price']    = round( $shipping_total + $shipping_tax );
		$shpping_item['vat']      = (int) $this->get_shipping_vat_id();
		$return_array[]           = $shpping_item;

		/* Get payment item */

		$fees = $this->order->get_fees();
		/**@var WC_Order_item_Fee $fee */
		if ( ! empty( $fees ) ) {
			$fee = current( $fees );

			$fee_total_with_tax = round( $fee->get_total() + $fee->get_total_tax() );
			$vat_depoto_id      = $this->get_vat_depoto_id( $fee->get_total(), $fee->get_total_tax() );
		} else {
			$fee_total_with_tax = 0;
			$vat_depoto_id      = $this->get_vat_depoto_id( 0, 0 );
		}

		$payment_item             = [];
		$payment_item['code']     = $this->order->get_payment_method();
		$payment_item['name']     = $this->order->get_payment_method_title();
		$payment_item['type']     = 'payment';
		$payment_item['quantity'] = 1;
		$payment_item['price']    = $fee_total_with_tax;
		$payment_item['vat']      = (int) $vat_depoto_id;

		$return_array[] = $payment_item;

		return $return_array;
	}

	public function schedule_update_payment_status( $order_id, $old_status, $new_status ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$payment_method = $order->get_payment_method();
		if ( ! $payment_method ) {
			return;
		}
		$id                  = 'depoto_paid_order_statuses_' . $payment_method;
		$paid_order_statuses = get_option( $id ) ?: [];
		$paid_order_statuses = array_map( fn( $item ) => str_replace( 'wc-', '', $item ), $paid_order_statuses );
		if ( ! in_array( $new_status, $paid_order_statuses ) ) {
			return;
		}

		as_enqueue_async_action( 'depoto_update_payment_status', [ 'order_id' => $order_id ] );
	}

	public function schedule_cancel_depoto_order( $order_id ) {
		as_enqueue_async_action( 'depoto_cancel_order', [ 'order_id' => $order_id ] );
	}


	public function update_order_payment_status( $order_id ) {
		$this->order     = wc_get_order( $order_id );
		$depoto_order_id = $this->order->get_meta( '_depoto_order_id' );
		if ( ! $depoto_order_id ) {
			return;
		}

		try {
			$this->depoto_api->update_order(
				[
					'id'           => $depoto_order_id,
					'paymentItems' => [
						[
							'payment' => $this->process_payment(),
							'amount'  => $this->order->get_total(),
							'isPaid'  => true,
						],
					],
				]
			);
			$this->order->add_order_note( __( 'Depoto order set to paid.', 'depoto' ) );
		} catch ( Exception $e ) {
			$this->order->add_order_note( sprintf( __( 'Depoto order payment status update failed, error: %s', 'depoto' ), $e->getMessage() ) );
		}
	}

	public function cancel_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! empty( $depoto_order_id = $order->get_meta( '_depoto_order_id', true ) ) ) {
			try {
				$this->depoto_api->cancel_order(
					$depoto_order_id
				);
				$order->add_order_note( __( 'Depoto order cancelled.', 'depoto' ) );
			} catch ( Exception $e ) {
				$order->add_order_note( sprintf( __( 'Depoto order cancellation failed, error: %s', 'depoto' ), $e->getMessage() ) );
			}
		}
	}
}
