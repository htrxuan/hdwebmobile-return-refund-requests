<?php

namespace htrxuan\hdrr;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Closes two real, currently-disclosed vulnerability classes found across the "Return Refund
 * and Exchange" family of competing WooCommerce plugins:
 *
 *  - CVE-2024-13692 (IDOR): several endpoints trusted a user-controlled key with no ownership
 *    validation, letting an unauthenticated attacker overwrite another customer's refund
 *    request message, overwrite their linked refund image, and read their order messages.
 *  - CVE-2025-6222: an unauthenticated file-upload endpoint allowed remote code execution.
 *
 * This class closes both by construction, not mitigation:
 *
 *  1. Every read of "this customer's own requests" is scoped by customer_id at the SQL query
 *     itself (find_for_customer()), never by trusting a request id alone to imply ownership.
 *     create_request() takes a real WC_Order object (not a raw order id) and re-derives
 *     customer_id from $order->get_customer_id() itself -- it never accepts a customer_id
 *     parameter from a caller at all, so there is no code path, present or future, that could
 *     let a request be created (or, since customer_id is never updated after creation, ever
 *     reassigned) against a WordPress user id the request itself supplied.
 *  2. There is no file upload anywhere in this plugin -- no upload field, no upload handler, no
 *     attachment-creation code path at all. A customer's return reason is a fixed dropdown plus
 *     a plain text note; there is simply no attack surface for CVE-2025-6222's vulnerability
 *     class to exist in, matching the same "eliminate the risky feature entirely" approach this
 *     suite already used for License Key Delivery's bulk-import (never a file upload, either).
 *
 * Direct queries against a custom table are unavoidable here -- there is no WP API for this
 * data -- so DirectDatabaseQuery/NoCaching advisories are expected and accepted for this class.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
class HDRR_Repository
{
    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_DECLINED = 'declined';
    const STATUS_REFUNDED = 'refunded';

    const REASONS = array('damaged', 'wrong_item', 'not_as_described', 'no_longer_needed', 'other');

    public static function get_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'hdrr_requests';
    }

    public static function get_schema_sql()
    {
        global $wpdb;
        $table           = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            order_item_id BIGINT UNSIGNED DEFAULT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(30) NOT NULL,
            note TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            admin_note TEXT NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY customer_id (customer_id),
            KEY order_id (order_id)
        ) {$charset_collate};";
    }

    /**
     * The ONLY place a request row is ever created, and the ONLY place customer_id is ever
     * written. Takes a real WC_Order object -- not an order id read from a request -- and
     * derives customer_id from it directly, so this method itself cannot be misused to create
     * a request against someone else's account even if a caller tried to pass one in.
     *
     * Callers (class-hdrr-myaccount.php) must still confirm $order->get_customer_id() matches
     * get_current_user_id() BEFORE calling this, exactly like every other ownership-sensitive
     * repository method in this suite -- this method's own guarantee is narrower but load-
     * bearing: it is architecturally impossible for a stored row's customer_id to be anything
     * other than the real order's own customer.
     */
    public static function create_request(\WC_Order $order, $order_item_id, $reason, $note)
    {
        global $wpdb;

        if (!in_array($reason, self::REASONS, true)) {
            return false;
        }

        $inserted = $wpdb->insert(
            self::get_table_name(),
            array(
                'order_id'      => $order->get_id(),
                'order_item_id' => $order_item_id ? (int) $order_item_id : null,
                'customer_id'   => $order->get_customer_id(),
                'reason'        => $reason,
                'note'          => sanitize_textarea_field($note),
                'status'        => self::STATUS_PENDING,
                'created_at'    => current_time('mysql'),
                'updated_at'    => current_time('mysql'),
            ),
            array('%d', $order_item_id ? '%d' : null, '%d', '%s', '%s', '%s', '%s', '%s')
        );

        return false !== $inserted ? self::find($wpdb->insert_id) : false;
    }

    public static function find($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::get_table_name(), (int) $id));
    }

    /**
     * Ownership is enforced entirely by this query's WHERE clause -- always call with
     * get_current_user_id(), never a value taken from the request.
     */
    public static function find_for_customer($customer_id)
    {
        global $wpdb;
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) {
            return array();
        }
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE customer_id = %d ORDER BY created_at DESC',
            self::get_table_name(),
            $customer_id
        ));
    }

    /**
     * A single request, but ONLY if it belongs to the given customer -- this is the actual
     * fix for CVE-2024-13692's IDOR: a request id alone (guessable, sequential) is never
     * sufficient to read or act on a row. Returns null for a request that exists but belongs
     * to someone else, indistinguishable from a request that doesn't exist at all.
     */
    public static function find_for_customer_by_id($id, $customer_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d AND customer_id = %d',
            self::get_table_name(),
            (int) $id,
            (int) $customer_id
        ));
    }

    public static function has_existing_request($order_id, $order_item_id)
    {
        global $wpdb;

        if ($order_item_id) {
            $count = $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE order_id = %d AND order_item_id = %d',
                self::get_table_name(),
                (int) $order_id,
                (int) $order_item_id
            ));
        } else {
            $count = $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE order_id = %d AND order_item_id IS NULL',
                self::get_table_name(),
                (int) $order_id
            ));
        }

        return $count > 0;
    }

    /**
     * Admin-only mutation (caller in class-hdrr-admin.php already checked manage_woocommerce +
     * nonce). Never touches customer_id -- a request can never be reassigned to a different
     * customer through this or any other method in this class.
     */
    public static function update_status($id, $status, $admin_note = '')
    {
        global $wpdb;
        return false !== $wpdb->update(
            self::get_table_name(),
            array(
                'status'     => $status,
                'admin_note' => sanitize_textarea_field($admin_note),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => (int) $id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }

    /**
     * @param array $args { status, s (search by note), per_page, paged, orderby, order }
     * @return array { items: array, total: int }
     */
    public static function get_for_list_table(array $args)
    {
        global $wpdb;

        $where  = array('1=1');
        $params = array();

        if (!empty($args['status']) && 'all' !== $args['status']) {
            $where[]  = 'status = %s';
            $params[] = $args['status'];
        }

        if (!empty($args['s'])) {
            $where[]  = 'note LIKE %s';
            $params[] = '%' . $wpdb->esc_like($args['s']) . '%';
        }

        $where_sql = implode(' AND ', $where);

        $allowed_orderby = array('created_at', 'status', 'order_id');
        $orderby         = in_array($args['orderby'] ?? '', $allowed_orderby, true) ? $args['orderby'] : 'created_at';
        $order           = 'ASC' === strtoupper($args['order'] ?? '') ? 'ASC' : 'DESC';

        $per_page = max(1, (int) ($args['per_page'] ?? 20));
        $paged    = max(1, (int) ($args['paged'] ?? 1));
        $offset   = ($paged - 1) * $per_page;

        $total = (int) $wpdb->get_var($wpdb->prepare( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
            "SELECT COUNT(*) FROM %i WHERE {$where_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_merge(array(self::get_table_name()), $params)
        ));

        $items = $wpdb->get_results($wpdb->prepare( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            "SELECT * FROM %i WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            array_merge(array(self::get_table_name()), $params, array($per_page, $offset))
        ));

        return array(
            'items' => $items,
            'total' => $total,
        );
    }
}
