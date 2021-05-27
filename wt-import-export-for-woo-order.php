<?php

/**
 * Order import export
 *
 *
 * @link              https://www.webtoffee.com/
 * @since             1.0.0
 * @package           Wt_Import_Export_For_Woo
 *
 * @wordpress-plugin
 * Plugin Name:       Order Import Export for WooCommerce
 * Plugin URI:        https://www.webtoffee.com/product/import-export-woocommerce/
 * Description:       Order Import Export for WooCommerce
 * Version:           1.0.1
 * Author:            Webtoffee
 * Author URI:        https://www.webtoffee.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wt-import-export-for-woo
 * Domain Path:       /languages
 * WC tested up to:   4.5
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/* Plugin page links */
add_filter('plugin_action_links_'.plugin_basename(__FILE__), 'wt_iew_plugin_action_links_order');

function wt_iew_plugin_action_links_order($links)
{
	if(defined('WT_IEW_PLUGIN_ID')) /* main plugin is available */
	{
		$links[] = '<a href="'.admin_url('admin.php?page='.WT_IEW_PLUGIN_ID).'">'.__('Settings').'</a>';
	}

	$links[] = '<a href="https://www.webtoffee.com/" target="_blank">'.__('Documentation').'</a>';
	$links[] = '<a href="https://www.webtoffee.com/support/" target="_blank">'.__('Support').'</a>';
	return $links;
}

/**
 * Check if Basic plugin is active
 */
//register_activation_hook(__FILE__, 'wt_iew_activation_hook_callback_order');
//
//function wt_iew_activation_hook_callback_order() {
//    if (is_plugin_active('order-import-export-for-woocommerce/order-import-export-for-woocommerce.php')) {
//        deactivate_plugins(basename(__FILE__));
//        wp_die(__("Looks like you have both free and premium version installed on your site! Prior to activating premium, deactivate and delete the free version. For any issue kindly contact our support team here: <a target='_blank' href='https://www.webtoffee.com/support/'>support</a>"), "", array('back_link' => 1));
//    }
//}
