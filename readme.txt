=== Post-Purchase Upsell ===
Contributors: tusherikbal
Tags: woocommerce, upsell, order bump, checkout, conversion
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight post-purchase upsell offers and checkout order bumps for WooCommerce -- no funnel builder required.

== Description ==

After checkout completes (but before the customer reaches the Thank You page), Post-Purchase Upsell can show a one-click "add this too" offer that charges the customer's already-saved payment method -- no re-entering card details.

Features:

* Post-purchase offers: pick a trigger product and an offer product, set a fixed or percentage discount, and priority when multiple offers match.
* One-click accept, charged via the customer's saved payment token on a tokenization-capable gateway (e.g. Stripe).
* Checkout order bumps: a simple checkbox add-on on the checkout page itself, kept administratively and visually separate from post-purchase offers.
* Basic analytics: views, accepts, declines, conversion rate, and revenue per offer.

Requires a WooCommerce payment gateway that supports saved-card tokenization (e.g. the official WooCommerce Stripe Gateway) for the one-click charge to actually run -- without one, post-purchase offers are simply skipped and customers go straight to the normal Thank You page.

A server- or CDN-level full-page cache placed in front of PHP cannot be bypassed by any plugin setting; if your host caches pages before WordPress runs, exclude the offer page URL pattern manually.

== Changelog ==

= 1.0.0 =
* Initial release: offer management (Custom Post Type, admin UI).
