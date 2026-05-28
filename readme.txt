=== ifthenpay | Payments for WPForms ===
Contributors: ifthenpay
Tags: ifthenpay, wpforms, payments, pay by link, gateway
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 1.0.0
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
6. Modal or popup payment display modes
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

== Frequently Asked Questions ==

= Does this plugin support recurring payments? =
No. This version supports only one-time payments via pay-by-link.

= Are payment details stored? =
No. The plugin does not store card numbers or full banking details. Only minimal references required for payment matching are stored.

= Which payment methods are supported? =
Any method enabled on your ifthenpay Gateway Key (e.g. Multibanco, MB WAY, Payshop, Credit Card, Cofidis, Google Pay, Apple Pay, Pix).

= How does the payment process work? =
Customers submit a WPForm and are presented with a secure payment page (modal or popup). After completing payment, ifthenpay sends a callback to update the payment status automatically.

= Can I use WPForms coupons? =
Yes. WPForms coupon and total fields are fully supported and automatically processed.

= What happens if a payment fails? =
The entry is marked as Failed. Customers can retry payment depending on your form setup.

= Can I customize the payment experience? =
Yes. You can configure display mode (modal/popup), button label, payment description, and styling via WPForms.

= Is there a sandbox? =
ifthenpay may provide test entities; if unavailable, use a low-value live test.

= How secure is the integration? =
All requests are encrypted over HTTPS; no sensitive card data is stored.

== External Services ==

This plugin integrates with the ifthenpay payment platform to process payments for WPForms submissions. ifthenpay is a third-party service that provides secure payment processing for various methods including cards, wallets, and local bank transfers.

- **WPForms**
  - **What it is and what it is used for**: A form builder plugin used to create payment forms. This plugin extends its payment functionality.

- **ifthenpay Backoffice & Integrations**
  - **What it is and what it is used for**: The ifthenpay Backoffice is the merchant dashboard used to manage integrations and payment configurations. The plugin uses the ifthenpay API to generate payment links and validate transactions.
  - **What data is sent and when**:
    - During setup: Backoffice Key and Gateway Key for authentication and configuration retrieval.
    - During payment processing: Transaction ID, amount, description, enabled payment method accounts, success/error/cancel return URLs, language, and optionally the selected payment method, customer email, customer name, and form field data.
    - During callbacks: Payment status, Transaction ID, and payment method.
  - **End-User License Agreement (EULA)**: [EULA](https://ifthenpay.com/eula/)
  - **Privacy Policy**: [Privacy Policy](https://ifthenpay.com/politica-de-privacidade/)

All network requests are performed server-side over HTTPS. Sensitive credentials are stored securely and are not publicly exposed. No raw card or bank details are stored.

== Screenshots ==
1. **(Admin Only) Backoffice Synchronization under WPForms Settings Payments**
2. **(Admin Only) WPForms's admin page (Creation/Editing Form -> Payments)**
3. **(Admin Only) Adding ifthenpay's Payment field to the selected form**
4. **(Admin Only) ifthenpay's Payment field Basic configuration options**
5. **(Admin Only) ifthenpay's Payment field Advanced configuration options**
6. **(Customers Experience) Payment Gateway field display varies by WPForms settings**
7. **(Customers Experience) Payment Modal Window**

== Changelog ==

= 1.0.0 =
* Initial release: WPForms integration, ifthenpay payments, multi-method support, modal.

== Upgrade Notice ==

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
