<?php

/**
 * @package         Depoto_Shippings
 */
class Depoto_Shippings
{

	private $methods = [];
	private $depoto_api_carriers_pairs = [];
	private $depoto_shipping_options = [];

	public function __construct($depoto_api_carriers_pairs)
	{
		$this->methods = $this->get_woocommerce_available_shipping_methods();
		$this->depoto_api_carriers_pairs = $depoto_api_carriers_pairs;
	}

	public function get_depoto_shippings_option(): void
	{
		$options = get_option('depoto_shippings');

		$depoto_shipping_options = [];

		foreach ($this->methods as $method_id => $method_title) {
			$depoto_shipping_options[$method_id] = $options[$method_id] ?? '';
		}
		$this->depoto_shipping_options = $depoto_shipping_options;
	}

	public function set_admin_hooks()
	{
		add_action('admin_init', [$this, 'section_init']);
		add_action('admin_init', [$this, 'add_fields']);
	}


	/**
	 * Get available Woocommerce shipping methods as pair ['method_id' => 'method_title']
	 *
	 * @return array
	 */
	private function get_woocommerce_available_shipping_methods(): array
	{
		$available_shippings_pairs = [];

	$zones = WC_Shipping_Zones::get_zones();

	$default_zone = new WC_Shipping_Zone(0);
	$zones[] = [
		'zone_id'   => 0,
		'zone_name' => $default_zone->get_zone_name(),
	];

	foreach ($zones as $zone_data) {
		$zone = new WC_Shipping_Zone($zone_data['zone_id']);
		$methods = $zone->get_shipping_methods(true);

		if (empty($methods)) {
			continue;
		}

		foreach ($methods as $method) {
			if (empty($method) || 'yes' !== $method->enabled) {
				continue;
			}

			$method_id   = $method->id;
			$instance_id = $method->instance_id ?? 0;

			$key = $method_id . ':' . $instance_id;

			$title = $method->get_title();
			if (empty($title)) {
				$title = $method->get_method_title();
			}

			$zone_name = $zone->get_zone_name();

			$available_shippings_pairs[$key] = sprintf(
				'%s (%s)',
				$title,
				$zone_name
			);
		}
	}

	return $available_shippings_pairs;
	}

	public function add_fields()
	{
		foreach ($this->methods as $id => $value) {
			$this->add_field($id, $value, $this->depoto_api_carriers_pairs);
		}
	}

	private function add_field($id, $value, $depoto_shippings_pairs)
	{
		$depoto_shippings_pairs = array_merge(['' => __('-- Select Depoto Shipping --', 'depoto')], $depoto_shippings_pairs);
		add_settings_field(
			$id,
			__($value, 'depoto'),
			function ($args) use ($depoto_shippings_pairs) {
				$id = esc_attr($args['label_for']);
				$id = html_entity_decode($id);

				echo "<select name='$id' id='$id' >";
				foreach ($depoto_shippings_pairs as $depoto_id => $depoto_title) {
					echo "<option value='$depoto_id' " . (($depoto_id === $this->depoto_shipping_options[$id]) ? 'selected' : '') . ">$depoto_title</option>";
				}
				echo '</select>';
			},
			'depoto',
			'depoto_shippings',
			array(
				'label_for' => $id,
				'class' => 'depoto-shipping-' . $id,
			)
		);
	}

	/**
	 * Initializes Depoto Payments section
	 */
	public function section_init(): void
	{
		register_setting('depoto', 'depoto_shippings');

		add_settings_section(
			'depoto_shippings',
			__('Shippings', 'depoto'),
			'',
			'depoto'
		);
	}

	public function set_fields_setting(): bool
	{

		$depoto_shippings = get_option('depoto_shippings');
		if (empty($depoto_shippings)) {
			$depoto_shippings = [];
		}


		foreach ($this->methods as $method_id => $method_title) {
			$method_item = filter_input(INPUT_POST, $method_id, FILTER_DEFAULT);

			if (!empty($method_item)) {
				$depoto_shippings[$method_id] = $method_item;
			}
		}

		update_option('depoto_shippings', $depoto_shippings, true);

		return true;
	}
}
