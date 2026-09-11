=== ifthenpay | Payments for WPForms ===
Contributors: ifthenpay
Tags: ifthenpay, wpforms, payments, ifthen, gateway
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2.0.3
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Adds ifthenpay payment methods to WPForms: cards, wallets, and local payment options; supports secure one-time payments via pay-by-link.

== Description ==

This plugin integrates the ifthenpay payment gateway with WPForms to enable seamless payment collection directly from your forms. Payments are processed through a secure pay-by-link system, ensuring that no sensitive card or banking data is stored on your website. Customers can complete payments using their preferred method via a secure payment page.

In plain terms you get:
* One-time payments through WPForms
* Support for orders
* Merchant backoffice (basic sales) on web + mobile
* Automatic payment confirmations (no card numbers stored)

All settings are managed within WPForms and your ifthenpay Backoffice. The plugin is designed so store owners can handle payments without requiring advanced technical knowledge.

== Key Features ==

1. Full integration with WPForms lite and pro payment fields
2. Secure transactions
3. Automatic payment confirmation (fast access)
4. Support for multiple payment methods (cards, wallets, transfers)
5. Coupon and discount support via WPForms
6. Secure full-page redirect to ifthenpay's hosted payment page
7. Real-time payment status in WPForms
8. Multi-language support (EN, ES, FR, PT)
9. Security-first approach (no card data stored)

== Requirements ==
* An active ifthenpay merchant account.
* A Gateway Key configured for WPForms (request via ifthenpay support).
* Backoffice Key
* WordPress 6.5+ and PHP 8.2+, with WPForms installed and activated.
* HTTPS (SSL) enabled on your site.

== Installation ==
1. Install: Upload the plugin zip via Plugins → Add New → Upload, or install from WordPress.org and Activate.
2. Credentials: Ensure your ifthenpay account has an active WPForms Gateway Key with desired payment methods enabled.
3. Setup: Go to WPForms → Settings → Payments and enter your Backoffice Key.
4. Form config: Create/Edit a form → Payments tab → Add the Ifthenpay field on your form → enable "ifthenpay | Payment Gateway" and select a Gateway Key.
5. Confirmation messages: In the same Payments tab, customize the Popup Confirmation Messages shown to customers for each payment outcome (Paid, Pending, Failed, Cancelled).

== Popup Confirmation Messages ==

Each ifthenpay-enabled form has its own Popup Confirmation Messages, configured in Create/Edit a form → Payments tab. These control what customers see in the ifthenpay payment popup for each outcome, and are separate from — and not affected by — WPForms' own Confirmations tab.

* Customize the message shown for each of the four outcomes: Paid, Pending, Failed, and Cancelled. Each one is pre-filled with sensible default text that you can edit or leave as-is.
* For the Paid outcome only, choose a Confirmation Type: show a Message in the popup, redirect to a WPForms Page, or redirect to a Redirect URL — the same flexibility as WPForms' native confirmations.
* Optionally enable "Show entry preview after confirmation message" on the Paid outcome, so customers see a summary of their submitted entry right after the payment confirmation message.

== Frequently Asked Questions ==

= Does this plugin support recurring payments? =
No. This version supports only one-time payments via pay-by-link.

= Are payment details stored? =
No. The plugin does not store card numbers or full banking details. Only minimal references required for payment matching are stored.

= Which payment methods are supported? =
Any method enabled on your ifthenpay Gateway Key (e.g. Multibanco, MB WAY, Payshop, Credit Card, Google Pay, Apple Pay, Pix).

= How does the payment process work? =
Customers submit a WPForm and are redirected to a secure ifthenpay-hosted payment page. After completing payment, ifthenpay sends a callback to update the payment status automatically.

= Can I use WPForms coupons? =
Yes. WPForms coupon and total fields are fully supported and automatically processed.

= What happens if a payment fails? =
The entry is marked as Failed. Customers can retry payment depending on your form setup.

= Can I customize the payment experience? =
Yes. You can configure button label, payment description, and styling via WPForms, plus a separate Popup Confirmation Message for each payment outcome (Paid, Pending, Failed, Cancelled). See "Popup Confirmation Messages" above.

= What happens if my form also has another payment gateway field (PayPal, Stripe, Square, Authorize.Net)? =
WPForms only allows one active payment method per submission, so the ifthenpay field automatically hides itself (logo, methods, and "Pay now" button) while another gateway's field is visible on the form. It reappears automatically if that other field is hidden again, e.g. by conditional logic. Only PayPal, Stripe, Square, and Authorize.Net fields count as competing gateways; WPForms' own Total, Coupon, and payment item fields do not.

= Is there a sandbox? =
ifthenpay may provide test entities; if unavailable, use a low-value live test.

= How secure is the integration? =
All requests are encrypted over HTTPS; no sensitive card data is stored.

= Does ifthenpay's addon for WPForms accept WEBHOOKS(Callbacks)? =
Yes! ifthenpay's addon for WPForms from version 2.0.0 and future ones accepts webhook(callbacks).

== External Services ==

This plugin integrates with the ifthenpay payment platform to process payments for WPForms submissions. ifthenpay is a third-party service that provides secure payment processing for various methods including cards, wallets, and local bank transfers.

- **WPForms**
  - **What it is and what it is used for**: A form builder plugin used to create payment forms. This plugin extends its payment functionality.

- **ifthenpay Backoffice & Integrations**
  - **What it is and what it is used for**: The ifthenpay Backoffice is the merchant dashboard used to manage integrations and payment configurations. The plugin uses the ifthenpay API to generate payment links and validate transactions.
  - **What data is sent and when**:
    - During setup: Backoffice Key and Gateway Key for authentication and configuration retrieval.
    - During payment processing: Order reference ID, amount, description, enabled payment method accounts, success/error/cancel return URLs, language, and optionally the selected payment method, customer email, customer name, and form field data.
    - During webhook registration: the Gateway Key and this site's callback URL, so ifthenpay can notify the site directly when a payment resolves.
    - During payment method activation requests: when an admin requests activation of a new payment method from WPForms → Settings → Payments, an email is sent to ifthenpay support (suporte@ifthenpay.com) containing the Backoffice Key, Gateway Key, the requested payment method, the admin's email address, site URL, site name, WordPress version, WPForms version, and plugin version.
    - During callbacks: Payment status and payment method.
  - **End-User License Agreement (EULA)**: [EULA](https://ifthenpay.com/eula/)
  - **Privacy Policy**: [Privacy Policy](https://ifthenpay.com/politica-de-privacidade/)

All network requests are performed server-side over HTTPS. Sensitive credentials are stored securely and are not publicly exposed. No raw card or bank details are stored.

== Screenshots ==
1. (Admin Only) Backoffice Synchronization under WPForms Settings Payments
2. (Admin Only) WPForms's admin page (Creation/Editing Form -> Payments)
3. (Admin Only) Adding ifthenpay's Payment field to the selected form
4. (Customers Experience) Payment Gateway field display varies by WPForms settings
5. (Customers Experience) ifthenpay's Secure Payment Page
6. (Customers Experience) Payment Message (either paid, pending, cancelled or failed)
7. (Admin Only) Payment Details
8. (Admin Only) Payment Entries

== Changelog ==
= 2.0.3 =
*Fixed: optimize payment confirmation speed on frontend and improve server-side transaction tracking.*

= 2.0.2 =
*Fixed: plugin version metadata (Stable tag, plugin header) was not bumped for the 2.0.1 release; this maintenance release corrects it and re-syncs the WordPress.org listing.*

= 2.0.1 =
*Fixed: Sanitization function bug on the callback url.*

= 2.0.0 =
*Added: full webhook (callback) support — ifthenpay now notifies the site directly when a payment resolves, instead of relying on the customer's browser returning to the site.
*Added: customizable Popup Confirmation Messages per payment outcome (Paid, Pending, Failed, Cancelled), configurable per form in the Payments tab. For the Paid outcome, choose a Confirmation Type — show a message in the popup, redirect to a WPForms page, or redirect to a custom URL — plus an optional entry preview shown after the message.
*Added: two ready-made ifthenpay form templates (Simple and multi-page Complex), available from Add New Form with branded thumbnails.
*Added: an "ifthenpay" light/dark theme preset selectable from WPForms' Themes tab.
*Added: a daily cron that automatically expires stale "pending" payments left behind by abandoned or timed-out Pay By Link sessions.
*Changed: payments now use a full-page redirect to ifthenpay's hosted payment page instead of the legacy modal/popup display; the unused transaction ID tracking that display relied on was removed.
*Changed: when another WPForms payment gateway field (PayPal, Stripe, Square, Authorize.Net) is active on the form, the ifthenpay field now hides itself instead of showing a warning message.
*Changed: a saved default payment method is now ignored if it's no longer enabled on the gateway, falling back to an available method instead of keeping a stale default.
*Fixed: a real, non-zero order could be rejected with "Amount cannot be lower than 0" — a stray or empty submitted quantity for a field that doesn't actually support quantities (e.g. a payment checkbox) was being trusted and could zero out the total. Quantity is now only read from fields that have quantity enabled, matching WPForms' own core logic.
*Fixed: pending, cancelled, and failed Pay By Link attempts now create a real WPForms entry and payment record (previously only completed payments did), so they show up correctly in Payments and Entries.
*Fixed: the payment outcome popup no longer reopens on its own after being dismissed, e.g. when switching back to the browser tab, unless the payment status actually changed.
*Security: payment completion is now confirmed exclusively via the verified webhook, rather than trusting the customer's browser return.

= 1.0.0 =
* Initial release: WPForms integration, ifthenpay payments, multi-method support, modal.

== Upgrade Notice ==

= 2.0.3 =
Update for the optimized optimize payment server-side transaction tracking.

= 2.0.2 =
Maintenance release correcting version metadata from 2.0.1. No functional changes.

= 2.0.1 =
This version fixes the callback url bug from version 2.0.0.

= 2.0.0 =
This version adds Webhooks and fixes a bug where 0-quantity products added to the total value. Upgrade immediately.


= 1.0.0 =
Initial release. Review gateway settings payments before going live.

== License ==
This plugin is licensed under the GPLv3.

== Support ==

For assistance use the [WordPress.org support forum](https://wordpress.org/support/plugin):

Pre-checks before posting:
* Payment method enabled on Gateway Key AND mapped to Integration
* Running current recommended versions of WordPress, PHP & WPForms

Commercial helpdesk available (no direct email required): [helpdesk.ifthenpay.com](https://helpdesk.ifthenpay.com/)
* ifthenpay support: [suporte@ifthenpay.com](mailto:suporte@ifthenpay.com)
* WPForms docs: [WPForms docs](https://wpforms.com/docs/)
