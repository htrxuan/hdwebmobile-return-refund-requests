<?php

namespace htrxuan\hdrr;

if (!defined('ABSPATH')) {
    exit;
}

final class HDRR_Core
{

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->includes();
        $this->init_hooks();
    }

    private function __clone()
    {
    }

    private function includes()
    {
        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-repository.php';
        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-eligibility.php';
        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-myaccount.php';
        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-admin.php';
    }

    private function init_hooks()
    {
        add_action('admin_notices', array($this, 'render_missing_woocommerce_notice'));
        add_action('admin_init', array(HDRR_Activator::class, 'maybe_upgrade_db'));

        if (!class_exists('WooCommerce')) {
            return;
        }

        HDRR_MyAccount::get_instance();

        // HDRR_Admin registers admin-menu/settings hooks itself, but this must load
        // unconditionally (not only when is_admin()) since it also owns the
        // hdwebmobile_hub_tabs registration used by the shared hub page.
        HDRR_Admin::get_instance();
    }

    public function render_missing_woocommerce_notice()
    {
        $screen = get_current_screen();
        if (!$screen || 'plugins' !== $screen->id) {
            return;
        }

        if (!get_transient('hdrr_wc_missing_notice')) {
            return;
        }
        delete_transient('hdrr_wc_missing_notice');
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php esc_html_e('HDWebmobile Return & Refund Requests requires WooCommerce to be installed and active. The plugin has been deactivated.', 'hdwebmobile-return-refund-requests'); ?>
            </p>
        </div>
        <?php
    }
}
