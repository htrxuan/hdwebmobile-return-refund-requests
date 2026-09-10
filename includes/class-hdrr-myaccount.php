<?php

namespace htrxuan\hdrr;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Everything a customer can do: request a return on their OWN completed order, and view their
 * OWN requests. Every ownership check here re-derives the truth from the real WC_Order object
 * (never from a client-supplied customer id) -- see class-hdrr-repository.php's docblock for
 * the CVE-2024-13692 IDOR class this closes.
 */
final class HDRR_MyAccount
{
    const ENDPOINT     = 'returns';
    const NONCE_ACTION = 'hdrr_request_return';

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
        add_action('init', array($this, 'add_endpoint'));
        add_filter('query_vars', array($this, 'add_query_var'));
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_item'));
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array($this, 'render_returns_page'));
        add_action('woocommerce_order_details_after_order_table', array($this, 'render_request_form'));
        add_action('admin_post_hdrr_request_return', array($this, 'handle_request'));
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_assets'));
    }

    public function maybe_enqueue_assets()
    {
        if (is_account_page()) {
            wp_enqueue_style('hdrr-frontend', HDRR_PLUGIN_URL . 'assets/css/hdrr-frontend.css', array(), HDRR_VERSION);
        }
    }

    public function add_endpoint()
    {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public function add_query_var($vars)
    {
        $vars[] = self::ENDPOINT;
        return $vars;
    }

    public function add_menu_item($items)
    {
        $new = array();
        foreach ($items as $key => $label) {
            $new[$key] = $label;
            if ('orders' === $key) {
                $new[self::ENDPOINT] = __('Returns', 'hdwebmobile-return-refund-requests');
            }
        }
        return $new;
    }

    public function render_returns_page()
    {
        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-repository.php';
        $requests = HDRR_Repository::find_for_customer(get_current_user_id());

        if (empty($requests)) {
            echo '<p>' . esc_html__('You have no return or refund requests.', 'hdwebmobile-return-refund-requests') . '</p>';
            return;
        }

        echo '<table class="woocommerce-table"><thead><tr>';
        echo '<th>' . esc_html__('Order', 'hdwebmobile-return-refund-requests') . '</th>';
        echo '<th>' . esc_html__('Reason', 'hdwebmobile-return-refund-requests') . '</th>';
        echo '<th>' . esc_html__('Status', 'hdwebmobile-return-refund-requests') . '</th>';
        echo '<th>' . esc_html__('Admin note', 'hdwebmobile-return-refund-requests') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($requests as $request) {
            echo '<tr>';
            echo '<td><a href="' . esc_url(wc_get_endpoint_url('view-order', $request->order_id, wc_get_page_permalink('myaccount'))) . '">#' . esc_html($request->order_id) . '</a></td>';
            echo '<td>' . esc_html(self::reason_label($request->reason)) . '</td>';
            echo '<td>' . esc_html(ucfirst($request->status)) . '</td>';
            echo '<td>' . esc_html($request->admin_note) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    public function render_request_form($order)
    {
        if (!$order instanceof \WC_Order || $order->get_customer_id() !== get_current_user_id()) {
            return; // Never render for an order that isn't genuinely this visitor's own.
        }

        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-repository.php';
        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-eligibility.php';

        if (!HDRR_Eligibility::is_eligible($order)) {
            return;
        }
        if (HDRR_Repository::has_existing_request($order->get_id(), null)) {
            echo '<p>' . esc_html__('A return request has already been submitted for this order.', 'hdwebmobile-return-refund-requests') . '</p>';
            return;
        }

        ?>
        <h2><?php esc_html_e('Request a Return or Refund', 'hdwebmobile-return-refund-requests'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="hdrr_request_return" />
            <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>" />
            <?php wp_nonce_field(self::NONCE_ACTION . '_' . $order->get_id(), 'hdrr_nonce'); ?>
            <p>
                <label for="hdrr_reason"><?php esc_html_e('Reason', 'hdwebmobile-return-refund-requests'); ?></label><br />
                <select id="hdrr_reason" name="hdrr_reason" required>
                    <?php foreach (HDRR_Repository::REASONS as $reason) : ?>
                        <option value="<?php echo esc_attr($reason); ?>"><?php echo esc_html(self::reason_label($reason)); ?></option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p>
                <label for="hdrr_note"><?php esc_html_e('Tell us more', 'hdwebmobile-return-refund-requests'); ?></label><br />
                <textarea id="hdrr_note" name="hdrr_note" rows="3" style="width:100%;max-width:500px;"></textarea>
            </p>
            <button type="submit" class="button"><?php esc_html_e('Submit Request', 'hdwebmobile-return-refund-requests'); ?></button>
        </form>
        <?php
    }

    /**
     * The only place a request is ever created from a customer-facing request. Ownership is
     * verified against the REAL order object before anything else happens -- a request_id or
     * order_id alone is never trusted to imply the requester owns it.
     */
    public function handle_request()
    {
        if (!is_user_logged_in()) {
            wp_die(esc_html__('You must be logged in to do this.', 'hdwebmobile-return-refund-requests'));
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order    = $order_id ? wc_get_order($order_id) : false;

        if (!$order || $order->get_customer_id() !== get_current_user_id()) {
            // Deliberately the same generic failure whether the order doesn't exist or belongs
            // to someone else -- never confirm or deny which, to a requester who isn't its owner.
            wp_die(esc_html__('This order could not be found.', 'hdwebmobile-return-refund-requests'));
        }

        if (!isset($_POST['hdrr_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hdrr_nonce'])), self::NONCE_ACTION . '_' . $order_id)) {
            wp_die(esc_html__('Security check failed. Please try again.', 'hdwebmobile-return-refund-requests'));
        }

        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-eligibility.php';
        if (!HDRR_Eligibility::is_eligible($order)) {
            wp_die(esc_html__('This order is no longer eligible for a return.', 'hdwebmobile-return-refund-requests'));
        }

        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-repository.php';
        if (HDRR_Repository::has_existing_request($order->get_id(), null)) {
            wp_die(esc_html__('A return request has already been submitted for this order.', 'hdwebmobile-return-refund-requests'));
        }

        $reason = isset($_POST['hdrr_reason']) ? sanitize_text_field(wp_unslash($_POST['hdrr_reason'])) : '';
        $note   = isset($_POST['hdrr_note']) ? sanitize_textarea_field(wp_unslash($_POST['hdrr_note'])) : '';

        HDRR_Repository::create_request($order, null, $reason, $note);

        $order->add_order_note(sprintf(
            /* translators: %s: return reason */
            __('Customer submitted a return request. Reason: %s', 'hdwebmobile-return-refund-requests'),
            self::reason_label($reason)
        ));

        wp_safe_redirect(wc_get_endpoint_url('view-order', $order_id, wc_get_page_permalink('myaccount')));
        exit;
    }

    public static function reason_label($reason)
    {
        $labels = array(
            'damaged'           => __('Item arrived damaged', 'hdwebmobile-return-refund-requests'),
            'wrong_item'        => __('Wrong item received', 'hdwebmobile-return-refund-requests'),
            'not_as_described'  => __('Not as described', 'hdwebmobile-return-refund-requests'),
            'no_longer_needed'  => __('No longer needed', 'hdwebmobile-return-refund-requests'),
            'other'             => __('Other', 'hdwebmobile-return-refund-requests'),
        );
        return $labels[$reason] ?? $reason;
    }
}
