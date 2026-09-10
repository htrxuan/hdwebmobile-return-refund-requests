<?php

/**
 * Plugin Name: HDWebmobile Return & Refund Requests
 * Plugin URI: https://hdwebmobile.com/plugins/hdwebmobile-return-refund-requests/
 * Description: Let customers request a return or refund on their own completed orders. Every request, message, and admin action is scoped to the order's real customer id, never a client-supplied identifier -- and there is no file upload anywhere in this plugin at all.
 * Version: 1.0.0
 * Author: htrxuan - Han Tran
 * Author URI: https://hdwebmobile.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hdwebmobile-return-refund-requests
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Requires at least: 6.9
 */

namespace htrxuan\hdrr;

if (!defined('ABSPATH')) {
    exit;
}

// Define Constants
define('HDRR_VERSION', '1.0.0');
define('HDRR_PLUGIN_FILE', __FILE__);
define('HDRR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HDRR_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-activator.php';

register_activation_hook(HDRR_PLUGIN_FILE, array(HDRR_Activator::class, 'activate'));
add_action('before_woocommerce_init', array(HDRR_Activator::class, 'declare_hpos_compatibility'));

add_action('plugins_loaded', function () {
    require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-core.php';
    HDRR_Core::get_instance();
});

add_filter('plugin_action_links_' . plugin_basename(HDRR_PLUGIN_FILE), function ($links) {
    $donate_link = '<a href="https://paypal.me/htrxuan/20" target="_blank" style="color:#d54e21;font-weight:bold;">' . __('Donate', 'hdwebmobile-return-refund-requests') . '</a>';
    array_unshift($links, $donate_link);
    return $links;
});
