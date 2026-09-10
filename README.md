# HDWebmobile Return & Refund Requests

Let customers request a return or refund on their own orders -- server-computed refund amounts, no file upload anywhere.

- **WordPress.org:** https://wordpress.org/plugins/hdwebmobile-return-refund-requests/
- **Requires:** WordPress 6.9+, WooCommerce, PHP 7.4+
- **License:** GPLv2 or later

## Description

HDWebmobile Return & Refund Requests adds a "Request a Return or Refund" option to a customer's completed orders, within a return window you configure. You review each request and, if you approve it, the plugin issues a real WooCommerce refund for the order's own exact remaining amount.

## Why this plugin exists

The "Return Refund and Exchange" family of competing WooCommerce plugins has had two serious, separately-disclosed vulnerabilities: CVE-2024-13692, an Insecure Direct Object Reference that let an unauthenticated attacker overwrite another customer's refund request message, overwrite their linked refund image, and read their private order messages, because several endpoints trusted a user-controlled key with no ownership check at all; and CVE-2025-6222, an unauthenticated file-upload endpoint that allowed full remote code execution. This plugin closes both by construction:

* Every return request is looked up and scoped by the real, currently-authenticated customer's own id at the database query itself -- never by trusting a request id or key alone to imply ownership.
* A request's owner is set exactly once, at creation, derived directly from the real WooCommerce order object's own customer id.
* There is no file upload anywhere in this plugin -- a return reason is a fixed dropdown plus a plain text note.
* Refund amounts are always computed fresh from the order's own remaining refundable total -- there is no field anywhere that accepts a refund amount from a request.

## Features

* Customers can request a return directly from their order details page, within a configurable return window
* A dedicated "Returns" tab under My Account showing request status
* Approve (issues a real WooCommerce refund and restocks items) or decline, from one admin screen
* Order notes added automatically at each step for a full audit trail

## Limitations (v1)

* No photo/file evidence upload -- see "Why this plugin exists" above
* Whole-order returns only -- no per-line-item partial returns yet

## Installation

1. Upload the plugin to `/wp-content/plugins/hdwebmobile-return-refund-requests`, or install through the WordPress plugins screen.
2. Activate the plugin. WooCommerce must already be installed and active.
3. Under WooCommerce > HDWebmobile > Return & Refund Requests, set your return window.

## License

GPLv2 or later. See [LICENSE](LICENSE).
