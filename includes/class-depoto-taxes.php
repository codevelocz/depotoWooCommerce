<?php

/**
 * @package         Depoto_Taxes
 */
class Depoto_Taxes
{

	private $taxes = [];
	private $depoto_api_taxes_pairs = [];
	private $depoto_taxes_options = [];

	public function __construct($depoto_api_taxes_pairs)
	{
		$this->depoto_api_taxes_pairs = $depoto_api_taxes_pairs;
		$this->taxes = $this->get_woocommerce_available_taxes();
	}

	public function get_depoto_taxes_option(): void
	{
		$options = get_option('depoto_taxes');

		$depoto_taxes_options = [];

		foreach ($this->taxes as $tax_id => $tax_title) {
			$depoto_taxes_options[$tax_id] = $options[$tax_id] ?? '';
		}
		$this->depoto_taxes_options = $depoto_taxes_options;
	}

	public function set_admin_hooks()
	{
		add_action('admin_init', [$this, 'section_init']);
		add_action('admin_init', [$this, 'add_fields']);
	}


	/**
	 * Get available Woocommerce taxes as pair ['tax_id' => ['percent' => xx, 'tax_title' => 'Tax Title']
	 *
	 * @return array
	 */
	private function get_woocommerce_available_taxes(): array
	{
		if ( ! wc_tax_enabled() ) {
			return [
				'tax_id_0' => __( 'Tax when WooCommerce taxes are disabled', 'depoto' ),
			];
		}

		$class_slugs = WC_Tax::get_tax_class_slugs();
		array_unshift($class_slugs, ''); // Standard rate has no slug, so we have to add it this way

		$tax_rates = [];

		foreach ($class_slugs as $class_slug) {
			$tax_rates[] = WC_Tax::get_rates_for_tax_class($class_slug);
		}


		$available_taxes_pairs = [];
		foreach ($tax_rates as $tax_id => $tax_obj) {
			$tax_obj = current($tax_obj); // tax_obj is an array with just one object, so we get the first one element of the array
			if (!empty($tax_obj)) {
				$available_taxes_pairs['tax_id_' . $tax_obj->tax_rate_id] =  $tax_obj->tax_rate_name;
			}
		}

		return $available_taxes_pairs;
	}

	public function add_fields()
	{
		foreach ($this->taxes as $id => $value) {
			$this->add_field($id, $value, $this->depoto_api_taxes_pairs);
		}
	}

	private function add_field($id, $value, $depoto_taxes_pairs)
	{
		$depoto_taxes_pairs = array_replace(['' => __('-- Select --', 'depoto')], $depoto_taxes_pairs);
		add_settings_field(
			$id,
			__($value, 'depoto'),
			function ($args) use ($depoto_taxes_pairs) {
				$id = esc_attr($args['label_for']);

				echo "<select name='$id' id='$id' >";
				foreach ($depoto_taxes_pairs as $depoto_id => $depoto_title) {
					$selected = '';
					$depoto_tax_id = $this->depoto_taxes_options[$id] ?? '';
					if (!empty($depoto_tax_id) && $depoto_id == $depoto_tax_id) {
						$selected  = 'selected';
					}
					echo "<option value='$depoto_id' " . $selected . ">$depoto_title</option>";
				}
				echo '</select>';
			},
			'depoto',
			'depoto_taxes',
			array(
				'label_for' => $id,
				'class' => 'depoto-taxes-' . $id,
			)
		);
	}

	/**
	 * Initializes Depoto taxes section
	 */
	public function section_init(): void
	{
		register_setting('depoto', 'depoto_taxes');

		add_settings_section(
			'depoto_taxes',
			__('Taxes', 'depoto'),
			'',
			'depoto'
		);
	}

	public function set_fields_setting(): bool
	{

		$depoto_taxes = get_option('depoto_taxes');
		if (empty($depoto_taxes)) {
			$depoto_taxes = [];
		}


		foreach ($this->taxes as $tax_id => $tax_title) {
			$tax_item = filter_input(INPUT_POST, $tax_id, FILTER_DEFAULT);

			if (!empty($tax_item)) {
				$depoto_taxes[$tax_id] = $tax_item;
			}
		}

		update_option('depoto_taxes', $depoto_taxes, true);

		return true;
	}
}
