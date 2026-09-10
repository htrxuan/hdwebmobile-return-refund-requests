=== HDWebmobile Return & Refund Requests ===
Contributors: htrxuan
Donate link: https://paypal.me/htrxuan/20
Tags: woocommerce, returns, refunds, rma, return management
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let customers request a return or refund on their own orders -- server-computed refund amounts, no file upload anywhere.

== Description ==

HDWebmobile Return & Refund Requests adds a "Request a Return or Refund" option to a customer's completed orders, within a return window you configure. You review each request and, if you approve it, the plugin issues a real WooCommerce refund for the order's own exact remaining amount.

= Why this plugin exists =
The "Return Refund and Exchange" family of competing WooCommerce plugins has had two serious, separately-disclosed vulnerabilities: CVE-2024-13692, an Insecure Direct Object Reference that let an unauthenticated attacker overwrite another customer's refund request message, overwrite their linked refund image, and read their private order messages, because several endpoints trusted a user-controlled key with no ownership check at all; and CVE-2025-6222, an unauthenticated file-upload endpoint that allowed full remote code execution. This plugin closes both vulnerability classes by construction:

* Every return request is looked up and scoped by the real, currently-authenticated customer's own id at the database query itself -- never by trusting a request id or key alone to imply ownership. A request that exists but belongs to someone else is indistinguishable from one that doesn't exist at all.
* A request's owner is set exactly once, at creation, derived directly from the real WooCommerce order object's own customer id -- never from a value read out of the request.
* There is no file upload anywhere in this plugin. A return reason is a fixed dropdown plus a plain text note -- the vulnerable code path from CVE-2025-6222 simply has no equivalent feature to exist in here at all.
* When you approve a request, the refund amount is always computed fresh from the order's own remaining refundable total -- there is no field anywhere that accepts a refund amount from a request.

= Key Features =
* Customers can request a return directly from their order details page, within a configurable return window
* A dedicated "Returns" tab under My Account showing request status
* Approve (issues a real WooCommerce refund and restocks items) or decline, from one admin screen
* Order notes are added automatically at each step for a full audit trail

= Limitations (please read before installing) =
* No photo/file evidence upload in this version -- see "Why this plugin exists" above for why
* Whole-order returns only in this version -- no per-line-item partial returns yet

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/hdwebmobile-return-refund-requests` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress. WooCommerce must already be installed and active.
3. Under WooCommerce > HDWebmobile > Return & Refund Requests, set your return window.

== How to Use ==

= 1. Customer requests a return =
On a completed order's details page (within the return window), the customer picks a reason and submits.

= 2. You review it =
Under WooCommerce > HDWebmobile > Return & Refund Requests, approve (refunds and restocks automatically) or decline.

== Screenshots ==

1. The Return & Refund Requests admin screen.
2. The request form on a customer's order details page.

== Changelog ==

= 1.0.0 =
* Initial release: ownership-scoped requests, no file upload, server-computed refund amounts.
