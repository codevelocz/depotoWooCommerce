<?php

/**
 * Plugin Name:     Depoto
 * Plugin URI:      https://www.depoto.cz
 * Description:     Plugin for connecting Woocommerce with Depoto
 * Author:          Depoto
 * Author URI:      https://www.depoto.cz
 * Text Domain:     depoto
 * Domain Path:     /languages
 * Version:         1.2.5
 *
 * @package         Depoto
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Depoto {

	private $is_active_woocommerce = false;
	public $depoto_payments;
	public $depoto_shippings;
	/** @var Depoto_Order_Statuses */
	public $depoto_order_statuses;
	public $depoto_taxes;
	/** @var Depoto_API */
	public $depoto_api;
	public $depoto_products;
	/** @var Depoto_Settings */
	public $depoto_settings;
	public $depoto_webhook;
	public $woocommerce_order;

	function __construct() {
		add_action( 'init', [ $this, 'load_i18n' ] );
		add_action( 'depoto_import_products', [ $this, 'process_products_import' ] );
		add_action( 'plugins_loaded', function () {
			if ( class_exists( 'woocommerce' ) ) {
				$this->is_active_woocommerce = true;
				$this->init_depoto_api();
				$this->init_order();
				$this->init_webhook();
			}
		} );

		if ( is_admin() ) {
			add_action( 'admin_menu', [ $this, 'admin_menu' ] );

			add_action( 'plugins_loaded', function () {
				if ( class_exists( 'woocommerce' ) && ( 'depoto' === filter_input( INPUT_GET, 'page' ) ) ) {
					add_action( 'admin_enqueue_scripts', [ $this, 'add_editor_scripts' ] );
					add_action( 'wp_loaded', function () {
						$this->init_all_sections();
						$this->process_post_submit();
						$this->set_payments_section();
						$this->set_shippings_section();
						$this->set_order_statuses_section();
						$this->set_taxes_section();
						$this->set_settings_section();
					} );
				}
			} );
		}
	}

	function load_i18n() {
		load_plugin_textdomain( 'depoto', false, 'depoto/languages' );
	}

	public function add_editor_scripts() {
		wp_enqueue_style( 'depoto-plugin-editor', plugins_url( 'css/editor.css', __FILE__ ) );
	}

	public function admin_menu() {
		add_menu_page(
			'Depoto',
			'Depoto',
			'manage_options',
			'depoto',
			[ $this, 'admin_page' ],
			'',
			1
		);
	}

	public function admin_page() {
		require_once 'admin-page.php';
	}

	public function show_tab_pairing() {
		if ( ! $this->is_active_woocommerce ) {
			echo '<h2>';
			_e( 'Be sure that you have installed and active Woocommerce.', 'depoto' );
			echo '</h2>';
		} else {
			echo '<form method="post" enctype="multipart/form-data">';
			settings_fields( 'depoto' );
			do_settings_sections( 'depoto' );
			submit_button( __( 'Save Settings', 'depoto' ), 'primary', 'submit', false );
			echo '</form>';
		}
	}

	public function show_tab_products() {
		if ( ! $this->is_active_woocommerce ) {
			echo '<h2>';
			_e( 'Be sure that you have installed and active Woocommerce.', 'depoto' );
			echo '</h2>';
		} else {
			$this->depoto_products->get_woocommerce_products();
			$this->depoto_products->add_import_to_depoto();
		}
	}

	public function show_tab_orders() {
		if ( ! $this->is_active_woocommerce ) {
			echo '<h2>';
			_e( 'Be sure that you have installed and active Woocommerce.', 'depoto' );
			echo '</h2>';
		} else {
			if ( isset( $_POST["send-orders"] ) ) {
				$this->woocommerce_order->process_undelivered_orders();
			}
			echo '<form method="post" enctype="multipart/form-data">';
			submit_button( __( 'Send undelivered orders', 'depoto' ), 'primary', 'send-orders', false );
			echo '</form>';
		}
	}

	public function show_tab_webhook() {
		if ( ! $this->is_active_woocommerce ) {
			echo '<h2>';
			_e( 'Be sure that you have installed and active Woocommerce.', 'depoto' );
			echo '</h2>';
		} else {
			if ( isset( $_POST["process-webhook"] ) ) {
				$this->depoto_webhook->process_webhook_stack();
			}
			$url = get_bloginfo( 'url' );
			_e( 'Webhook for Depoto Events:', 'depoto' );
			echo "<br><b>$url/wp-json/depoto/v1/shop</b><br><br>";
			_e( 'Cron for process webhook stack:', 'depoto' );
			echo "<br><b>$url/wp-json/depoto/v1/process-stack</b><br>";
			echo '<form method="post" enctype="multipart/form-data">';
			submit_button( __( 'Process webhook stack', 'depoto' ), 'primary', 'process-webhook', false );
			echo '</form>';
		}
	}

	public function show_tab_settings() {
		if ( ! $this->is_active_woocommerce ) {
			echo '<h2>';
			_e( 'Be sure that you have installed and active Woocommerce.', 'depoto' );
			echo '</h2>';
		} else {
			echo '<form method="post" enctype="multipart/form-data">';
			if ( $this->depoto_api->is_connected() ) {
				echo '<h2 style="color:var(--wc-green)">' . __( 'Connection established', 'depoto' ) . '</h2>';
			} else {
				echo '<h2 style="color:var(--wc-red)">' . __( 'Connection not established', 'depoto' ) . '</h2>';
			}
			settings_fields( 'depoto_settings' );
			do_settings_sections( 'depoto_settings' );
			submit_button( __( 'Save Settings', 'depoto' ), 'primary', 'submit', false );
			echo '</form>';
		}
	}

	public function process_post_submit(): void {
		if ( isset( $_POST["submit"] ) ) {
			$this->depoto_payments->set_fields_setting();
			$this->depoto_shippings->set_fields_setting();
			$this->depoto_order_statuses->set_fields_setting();
			$this->depoto_taxes->set_fields_setting();
			$this->depoto_settings->set_fields_setting();
		}
	}

	public function init_depoto_api() {
		require_once plugin_dir_path( __FILE__ ) . 'includes/depoto-api/class-depoto-api.php';
		$this->depoto_api = new Depoto_API();
	}

	public function init_order() {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-depoto-order.php';
		$this->woocommerce_order = new Depoto_Order( $this->depoto_api );
	}

	function init_all_sections() {
		$this->init_payments_section();
		$this->init_shippings_section();
		$this->init_order_statuses_section();
		$this->init_taxes_section();
		$this->init_products();
		$this->init_settings_section();
	}

	function init_payments_section() {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-depoto-payments.php';
		$this->depoto_payments = new Depoto_Payments( $this->depoto_api->get_payments_pairs(), $this->depoto_api->get_checkouts() );
	}

	function init_shippings_section() {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-depoto-shippings.php';
		$this->depoto_shippings = new Depoto_Shippings( $this->depoto_api->get_carriers_pairs() );
	}

	function init_order_statuses_section() {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-depoto-order-statuses.php';
		$this->depoto_order_statuses = new Depoto_Order_Statuses( $this->depoto_api->get_order_statuses_pairs() );
	}

	function init_taxes_section() {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-depoto-taxes.php';
		$this->depoto_taxes = new Depoto_Taxes( $this->depoto_api->get_taxes_pairs() );
	}

	function init_settings_section() {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-depoto-settings.php';
		$this->depoto_settings = new Depoto_Settings( $this->depoto_api );
	}

	function init_products() {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-depoto-products.php';
		$this->depoto_products = new Depoto_Products( $this->depoto_api->get_products_pairs() );
	}

	function init_webhook() {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-depoto-webhook.php';
		$this->depoto_webhook = new Depoto_Webhook( $this->depoto_api );
	}

	function set_payments_section() {
		$this->depoto_payments->get_depoto_payments_option();
		$this->depoto_payments->set_admin_hooks();
	}

	function set_shippings_section() {
		$this->depoto_shippings->get_depoto_shippings_option();
		$this->depoto_shippings->set_admin_hooks();
	}

	function set_order_statuses_section() {
		$this->depoto_order_statuses->get_depoto_order_statuses_option();
		$this->depoto_order_statuses->set_admin_hooks();
	}

	function set_taxes_section() {
		$this->depoto_taxes->get_depoto_taxes_option();
		$this->depoto_taxes->set_admin_hooks();
	}

	function set_settings_section() {
		$this->depoto_settings->get_depoto_settings_option();
		$this->depoto_settings->set_admin_hooks();
	}

	function process_products_import() {
		$products_to_import = get_option( 'depoto_products_to_import', [] );

		if ( empty( $products_to_import ) ) {
			return;
		}

		$batches = array_chunk( $products_to_import, 20 );
		// Process the first batch
		$first_batch = array_shift( $batches );
		foreach ( $first_batch as $item ) {
			$p = wc_get_product( $item );
			if ( ! $p ) {
				// remove invalid product ID from the option
				$products_to_import = array_diff( $products_to_import, [ $item ] );
				update_option( 'depoto_products_to_import', $products_to_import );
				continue;
			}
			if ( $p->get_meta( '_depoto_id' ) ) {
				// product already imported, skip it
				$products_to_import = array_diff( $products_to_import, [ $item ] );
				update_option( 'depoto_products_to_import', $products_to_import );
				continue;
			}


			$data = [
				'name'      => $p->get_name(),
				'code'      => $p->get_sku(),
			];

			$ean = $this->get_product_ean( $p );
			if ( $ean ) {
				$data['ean'] = $ean;
			}


			if ($p->get_price()) {
				$data['sellPrice'] = $p->get_price();
			}

			if ( $p->is_type( 'variation' ) ) {
				$parent = wc_get_product( $p->get_parent_id() );
				if ( ! $parent->get_meta( '_depoto_id' ) ) {
					// Parent product not imported, skip this variation.
					$products_to_import = array_diff( $products_to_import, [ $item ] );
					update_option( 'depoto_products_to_import', $products_to_import );
					continue;
				}
				$data['parent'] = $parent->get_meta( '_depoto_id' );
				$name           = trim( str_replace( $parent->get_name(), '', $p->get_name() ) );
				$data['name']   = preg_replace( '/^\s*-\s*/', '', $name );
			}

			if ( $p->get_weight() ) {
				$data['weight'] = $p->get_weight();
			}
			if ( $p->get_length() ) {
				$data['dimensionX'] = $p->get_length();
			}
			if ( $p->get_width() ) {
				$data['dimensionY'] = $p->get_width();
			}
			if ( $p->get_height() ) {
				$data['dimensionZ'] = $p->get_height();
			}

			$result = $this->depoto_api->create_product( $data );
			if ( ! empty( $result ) ) {
				$p->update_meta_data( '_depoto_id', $result );
				$p->save();
				$image_id = $p->get_image_id();
				if ( ! $image_id && $p->is_type( 'variation' ) ) {
					$parent   = wc_get_product( $p->get_parent_id() );
					$image_id = $parent->get_image_id();
				}
				if ( $image_id ) {
					// Upload product image to Depoto
					$data   = [
						'text'             => get_the_title( $image_id ),
						'mimeType'         => get_post_mime_type( $image_id ),
						'originalFilename' => get_the_title( $image_id ),
						'base64Data'       => base64_encode( file_get_contents( get_attached_file( $image_id ) ) ),
						'product'          => $result,
					];
					$result = $this->depoto_api->create_file( $data );
					if ( ! $result ) {
						// TODO: log error
					}
				}
			} else {
				// TODO: log error
			}
			$products_to_import = array_diff( $products_to_import, [ $item ] );
			update_option( 'depoto_products_to_import', $products_to_import );
		}

		// If there are more batches, schedule the next import
		$products_to_import = get_option( 'depoto_products_to_import', [] );
		if ( ! empty( $products_to_import ) ) {
			as_enqueue_async_action( 'depoto_import_products' );
		}
	}


	private function get_product_ean( $product ): string {
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$ean = $product->get_global_unique_id();
			if ( ! empty( $ean ) ) {
				return (string) $ean;
			}
		}

		$meta_keys = [
			'_ean',
			'ean',
			'_alg_ean',
			'_global_unique_id',
			'_wpm_gtin_code',
		];

		foreach ( $meta_keys as $meta_key ) {
			$ean = $product->get_meta( $meta_key );
			if ( ! empty( $ean ) ) {
				return (string) $ean;
			}
		}

		return '';
	}
}

new Depoto();
