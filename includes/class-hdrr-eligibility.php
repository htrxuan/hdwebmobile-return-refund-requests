<?php

namespace htrxuan\hdrr;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether an order can still have a return requested. Checked server-side both when rendering
 * the request form (cosmetic) AND again inside the actual submission handler (the real gate) --
 * a customer can never submit a request for an ineligible order just because the form happened
 * to still be visible in a stale page load.
 */
final class HDRR_Eligibility
{
    const OPTION_WINDOW_DAYS  = 'hdrr_return_window_days';
    const DEFAULT_WINDOW_DAYS = 14;

    public static function get_window_days()
    {
        return max(1, (int) get_option(self::OPTION_WINDOW_DAYS, self::DEFAULT_WINDOW_DAYS));
    }

    public static function is_eligible(\WC_Order $order)
    {
        if (!$order->has_status('completed')) {
            return false;
        }

        $completed_date = $order->get_date_completed();
        if (!$completed_date) {
            return false;
        }

        $days_since = (time() - $completed_date->getTimestamp()) / DAY_IN_SECONDS;
        return $days_since <= self::get_window_days();
    }
}
