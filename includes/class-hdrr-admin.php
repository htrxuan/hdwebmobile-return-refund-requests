<?php

namespace htrxuan\hdrr;

if (!defined('ABSPATH')) {
    exit;
}

class HDRR_Admin
{
    const NONCE_SETTINGS = 'hdrr_save_settings';

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
        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-hub.php';
        add_filter('hdwebmobile_hub_tabs', array($this, 'register_hub_tabs'));
        add_action('admin_post_hdrr_save_settings', array($this, 'save_settings'));
        add_action('admin_post_hdrr_approve', array($this, 'handle_approve'));
        add_action('admin_post_hdrr_decline', array($this, 'handle_decline'));
    }

    public function register_hub_tabs($tabs)
    {
        $tabs['return-refund-requests'] = array(
            'label'  => __('Return & Refund Requests', 'hdwebmobile-return-refund-requests'),
            'order'  => 16,
            'render' => array($this, 'render_page'),
        );
        return $tabs;
    }

    public function save_settings()
    {
        if (!current_user_can('manage_woocommerce') || !isset($_POST['hdrr_settings_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hdrr_settings_nonce'])), self::NONCE_SETTINGS)) {
            wp_die(esc_html__('You do not have permission to do this.', 'hdwebmobile-return-refund-requests'));
        }

        $days = isset($_POST['hdrr_return_window_days']) ? absint($_POST['hdrr_return_window_days']) : 14;
        update_option('hdrr_return_window_days', max(1, $days));

        wp_safe_redirect(admin_url('admin.php?page=hdwebmobile&tab=return-refund-requests&updated=1'));
        exit;
    }

    /**
     * The refund amount is ALWAYS computed server-side from the order's own remaining
     * refundable total -- there is no field anywhere in this request-handling path that reads
     * an amount from $_GET/$_POST, so a request can never be approved for anything other than
     * exactly what the order's own records say is still owed. This mirrors the same
     * "never trust a client-supplied monetary value" pattern already used for withdrawals in
     * class-hdvm-repository.php and license-key delivery elsewhere in this suite.
     */
    public function handle_approve()
    {
        $id = $this->verify_admin_action('hdrr_approve_');
        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-repository.php';

        $request = HDRR_Repository::find($id);
        if (!$request) {
            wp_die(esc_html__('Request not found.', 'hdwebmobile-return-refund-requests'));
        }

        $order = wc_get_order($request->order_id);
        if (!$order) {
            wp_die(esc_html__('The order for this request no longer exists.', 'hdwebmobile-return-refund-requests'));
        }

        $amount = $order->get_remaining_refund_amount();
        if ($amount > 0) {
            $refund = wc_create_refund(array(
                'order_id'   => $order->get_id(),
                'amount'     => $amount,
                'reason'     => sprintf(
                    /* translators: %s: customer's stated return reason */
                    __('Return request approved: %s', 'hdwebmobile-return-refund-requests'),
                    HDRR_MyAccount::reason_label($request->reason)
                ),
                'restock_items' => true,
            ));

            if (is_wp_error($refund)) {
                wp_die(esc_html($refund->get_error_message()));
            }
        }

        HDRR_Repository::update_status($id, HDRR_Repository::STATUS_REFUNDED);
        $order->add_order_note(__('Return request approved and refunded.', 'hdwebmobile-return-refund-requests'));

        wp_safe_redirect(admin_url('admin.php?page=hdwebmobile&tab=return-refund-requests&processed=1'));
        exit;
    }

    public function handle_decline()
    {
        $id = $this->verify_admin_action('hdrr_decline_');
        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-repository.php';

        $request = HDRR_Repository::find($id);
        if ($request) {
            HDRR_Repository::update_status($id, HDRR_Repository::STATUS_DECLINED, __('Declined by store admin.', 'hdwebmobile-return-refund-requests'));
            $order = wc_get_order($request->order_id);
            if ($order) {
                $order->add_order_note(__('Return request declined.', 'hdwebmobile-return-refund-requests'));
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=hdwebmobile&tab=return-refund-requests&processed=1'));
        exit;
    }

    private function verify_admin_action($nonce_prefix)
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'hdwebmobile-return-refund-requests'));
        }

        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if (!$id || !isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), $nonce_prefix . $id)) {
            wp_die(esc_html__('Security check failed. Please try again.', 'hdwebmobile-return-refund-requests'));
        }

        return $id;
    }

    public function render_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'hdwebmobile-return-refund-requests'));
        }

        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-repository.php';
        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-eligibility.php';
        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-myaccount.php';
        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-admin-list-table.php';

        $table = new HDRR_Admin_List_Table();
        $table->prepare_items();
        ?>
        <p><?php esc_html_e('Customers can request a return or refund on their own completed orders, within the window you set below. Approving a request issues a real WooCommerce refund for the order\'s exact remaining refundable amount -- never a manually-typed figure.', 'hdwebmobile-return-refund-requests'); ?></p>

        <?php if (!empty($_GET['updated']) || !empty($_GET['processed'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success flag, no state change. ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Done.', 'hdwebmobile-return-refund-requests'); ?></p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1.5em;">
            <input type="hidden" name="action" value="hdrr_save_settings" />
            <?php wp_nonce_field(self::NONCE_SETTINGS, 'hdrr_settings_nonce'); ?>
            <label for="hdrr_return_window_days"><?php esc_html_e('Return window (days after order completion):', 'hdwebmobile-return-refund-requests'); ?></label>
            <input type="number" min="1" id="hdrr_return_window_days" name="hdrr_return_window_days" value="<?php echo esc_attr(HDRR_Eligibility::get_window_days()); ?>" style="width:6em;" />
            <?php submit_button(__('Save', 'hdwebmobile-return-refund-requests'), 'secondary', '', false); ?>
        </form>

        <form method="get">
            <input type="hidden" name="page" value="hdwebmobile" />
            <input type="hidden" name="tab" value="return-refund-requests" />
            <?php
            $table->search_box(__('Search notes', 'hdwebmobile-return-refund-requests'), 'hdrr-search');
            $table->display();
            ?>
        </form>
        <?php
    }
}
