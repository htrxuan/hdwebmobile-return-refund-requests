<?php

namespace htrxuan\hdrr;

if (!defined('ABSPATH')) {
    exit;
}

class HDRR_Activator
{

    public static function activate()
    {
        if (!self::is_woocommerce_active()) {
            deactivate_plugins(plugin_basename(HDRR_PLUGIN_FILE));
            set_transient('hdrr_wc_missing_notice', true, 30);
            return;
        }

        self::maybe_upgrade_db();

        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-myaccount.php';
        add_rewrite_endpoint(HDRR_MyAccount::ENDPOINT, EP_ROOT | EP_PAGES);
        // The endpoint is also (re-)registered on every request via HDRR_MyAccount's own
        // 'init' hook -- this activation-time call just seeds the very first flush so the
        // rewrite rule exists before that hook has ever run.
        flush_rewrite_rules();
    }

    public static function is_woocommerce_active()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('woocommerce/woocommerce.php') || class_exists('WooCommerce');
    }

    public static function maybe_upgrade_db()
    {
        if (get_option('hdrr_db_version') === HDRR_VERSION) {
            return;
        }

        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-repository.php';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(HDRR_Repository::get_schema_sql());

        update_option('hdrr_db_version', HDRR_VERSION);
    }

    public static function declare_hpos_compatibility()
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', HDRR_PLUGIN_FILE, true);
        }
    }
}
