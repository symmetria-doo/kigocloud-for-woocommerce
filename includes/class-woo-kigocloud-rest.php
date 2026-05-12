<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://github.com/symmetria-doo
 * @since      1.0.0
 *
 * @package    Woo_KigoCloud
 * @subpackage Woo_KigoCloud/includes
 */

/**
 * The core plugin class.
 *
 *
 * @since      1.0.0
 * @package    Woo_KigoCloud
 * @subpackage Woo_KigoCloud/includes
 * @author     Dejan Potocic <dpotocic@gmail.com>
 */
class Woo_KigoCloud_Rest
{
    /**
     * @return void
     */
    public function register_sku_endpoint() {
        register_rest_route('wc/v3/kigocloud', '/products/variations/sku/(?P<sku>[\w-]+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_variation_by_sku'],
            'permission_callback' => function () {
                return current_user_can('read'); // Or use any other capability check suitable for your API access level
            },
        ]);
    }

    /**
     * @param $data
     * @return int[]|WP_Error
     */
    public function get_variation_by_sku($data)
    {
        global $wpdb;
        $sku = sanitize_text_field($data['sku']);

        // Query to find a variation with the matching SKU
        $query = $wpdb->prepare("SELECT p.ID as variation_id, p.post_parent as product_id
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'product_variation' 
            AND pm.meta_key = '_sku' 
            AND pm.meta_value = %s
            LIMIT 1", $sku);

        $result = $wpdb->get_row($query);

        if ($result) {
            return [
                'variation_id' => (int)$result->variation_id,
                'product_id' => (int)$result->product_id
            ];
        } else {
            return new WP_Error('no_variation', 'No variation found with that SKU', ['status' => 404]);
        }
    }


	/**
	 * @return void
	 */
	public function register_product_sku_endpoint() {
		register_rest_route('wc/v3/kigocloud', '/products/sku/(?P<sku>[\w-]+)', [
			'methods' => 'GET',
			'callback' => [$this, 'get_product_by_sku'],
			'permission_callback' => function () {
				return current_user_can('read'); // Or use any other capability check suitable for your API access level
			},
		]);
	}

	/**
	 * @param $data
	 *
	 * @return array|WP_Error
	 */
	public function get_product_by_sku( $data ) {
		global $wpdb;
		$sku = sanitize_text_field( $data['sku'] );

		// Combined query: check in product and product_variation post types
		$query = $wpdb->prepare( "
	        SELECT p.ID, p.post_type, p.post_parent
	        FROM {$wpdb->posts} p
	        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
	        WHERE p.post_type IN ('product', 'product_variation')
	        AND pm.meta_key = '_sku'
	        AND pm.meta_value = %s
	        LIMIT 1", $sku );

		$result = $wpdb->get_row( $query );

		if ( $result ) {
			$response = [
				'type' => $result->post_type,
				'sku'  => $sku
			];

			if ( $result->post_type === 'product' ) {
				$response['product_id'] = (int) $result->ID;
			} else {
				$response['product_id']   = (int) $result->post_parent;
				$response['variation_id'] = (int) $result->ID;
			}

			return $response;
		}

		return new WP_Error( 'no_match', 'No product or variation found with that SKU', [ 'status' => 404 ] );
	}


}