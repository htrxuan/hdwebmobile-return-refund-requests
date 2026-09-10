<?php

namespace htrxuan\hdrr;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class HDRR_Admin_List_Table extends \WP_List_Table
{

    public function __construct()
    {
        parent::__construct(array(
            'singular' => 'return request',
            'plural'   => 'return requests',
            'ajax'     => false,
        ));
    }

    public function get_columns()
    {
        return array(
            'order_id'    => __('Order', 'hdwebmobile-return-refund-requests'),
            'customer_id' => __('Customer', 'hdwebmobile-return-refund-requests'),
            'reason'      => __('Reason', 'hdwebmobile-return-refund-requests'),
            'note'        => __('Note', 'hdwebmobile-return-refund-requests'),
            'status'      => __('Status', 'hdwebmobile-return-refund-requests'),
            'created_at'  => __('Requested', 'hdwebmobile-return-refund-requests'),
        );
    }

    protected function get_sortable_columns()
    {
        return array(
            'created_at' => array('created_at', true),
            'status'     => array('status', false),
        );
    }

    protected function extra_tablenav($which)
    {
        if ('top' !== $which) {
            return;
        }

        $current_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter param, same pattern as core WP_List_Table screens.
        $statuses = array(
            'all'      => __('All statuses', 'hdwebmobile-return-refund-requests'),
            'pending'  => __('Pending', 'hdwebmobile-return-refund-requests'),
            'approved' => __('Approved', 'hdwebmobile-return-refund-requests'),
            'declined' => __('Declined', 'hdwebmobile-return-refund-requests'),
            'refunded' => __('Refunded', 'hdwebmobile-return-refund-requests'),
        );
        ?>
        <div class="alignleft actions">
            <select name="status">
                <?php foreach ($statuses as $value => $label) : ?>
                    <option value="<?php echo esc_attr($value); ?>" <?php selected($current_status, $value); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <?php submit_button(__('Filter', 'hdwebmobile-return-refund-requests'), '', 'filter_action', false); ?>
        </div>
        <?php
    }

    public function column_order_id($item)
    {
        $order = wc_get_order($item->order_id);
        if (!$order) {
            return '#' . esc_html($item->order_id) . ' ' . esc_html__('(no longer available)', 'hdwebmobile-return-refund-requests');
        }
        return sprintf('<a href="%s">#%s</a>', esc_url($order->get_edit_order_url()), esc_html($order->get_order_number()));
    }

    public function column_customer_id($item)
    {
        $user = get_user_by('id', $item->customer_id);
        return $user ? esc_html($user->display_name) : esc_html__('(guest)', 'hdwebmobile-return-refund-requests');
    }

    public function column_reason($item)
    {
        require_once HDRR_PLUGIN_DIR . 'includes/class-hdrr-myaccount.php';
        return esc_html(HDRR_MyAccount::reason_label($item->reason));
    }

    /**
     * Row actions live here (WP_List_Table's own convention -- they only ever render via
     * $this->row_actions(), never via column_default(), which is never called at all once
     * every column already has its own dedicated column_$name() method as this table's do).
     */
    public function column_status($item)
    {
        $label = esc_html(ucfirst($item->status));

        if ('pending' !== $item->status) {
            return $label;
        }

        $base    = admin_url('admin-post.php');
        $actions = array(
            'approve' => sprintf(
                '<a href="%s" onclick="return confirm(\'%s\')">%s</a>',
                esc_url(wp_nonce_url(add_query_arg(array('action' => 'hdrr_approve', 'id' => $item->id), $base), 'hdrr_approve_' . $item->id)),
                esc_js(__('Approve and refund this order? This cannot be undone.', 'hdwebmobile-return-refund-requests')),
                esc_html__('Approve & Refund', 'hdwebmobile-return-refund-requests')
            ),
            'decline' => sprintf(
                '<a href="%s">%s</a>',
                esc_url(wp_nonce_url(add_query_arg(array('action' => 'hdrr_decline', 'id' => $item->id), $base), 'hdrr_decline_' . $item->id)),
                esc_html__('Decline', 'hdwebmobile-return-refund-requests')
            ),
        );

        return $label . $this->row_actions($actions);
    }

    public function column_note($item)
    {
        $note = wp_trim_words($item->note, 12);
        return esc_html($note);
    }

    public function column_created_at($item)
    {
        return esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item->created_at)));
    }

    public function prepare_items()
    {
        $per_page = 20;
        $paged    = $this->get_pagenum();

        $status  = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter/search/sort params, same pattern as core WP_List_Table screens.
        $search  = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : 'created_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order   = isset($_GET['order']) ? sanitize_text_field(wp_unslash($_GET['order'])) : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $result = HDRR_Repository::get_for_list_table(array(
            'status'   => $status,
            's'        => $search,
            'per_page' => $per_page,
            'paged'    => $paged,
            'orderby'  => $orderby,
            'order'    => $order,
        ));

        $this->items = $result['items'];

        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());

        $this->set_pagination_args(array(
            'total_items' => $result['total'],
            'per_page'    => $per_page,
            'total_pages' => ceil($result['total'] / $per_page),
        ));
    }
}
