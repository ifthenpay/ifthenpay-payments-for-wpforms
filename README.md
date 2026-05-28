# ifthenpay | Payments for WPForms

Adds ifthenpay payment methods to WPForms: cards, wallets, and local payment options; supports secure one-time payments via pay-by-link.

---

## Table of Contents

- [Description](#description)
- [Key Features](#key-features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Form Setup](#form-setup)
- [Frequently Asked Questions](#frequently-asked-questions)
- [External Services](#external-services)
- [Screenshots](#screenshots)
- [Support](#support)

## Description

This plugin integrates the ifthenpay payment gateway with WPForms to enable seamless payment collection directly from your forms. Payments are processed through a secure pay-by-link system, ensuring that no sensitive card or banking data is stored on your website. Customers can complete payments using their preferred method via a secure payment page. After submitting a form, users are shown a payment interface (modal or popup) where they complete the transaction; ifthenpay then sends a server-side callback to update the payment status automatically.

### In plain terms you get:

- One-time payments directly from WPForms
- Support for coupons and automatic total calculations
- Merchant backoffice (basic sales) on web + mobile
- Secure automatic payment confirmations (no card numbers stored)

All settings are made in WPForms and in your ifthenpay Backoffice. The plugin is built so site owners can manage payments without needing deep technical knowledge.

## Key Features

1. Full integration with WPForms Lite and Pro payment flow
2. Secure transactions
3. Automatic payment confirmation
4. Support for multiple payment methods (cards, wallets, transfers)
5. Coupon and discount support via WPForms
6. Modal or popup payment display modes
7. Real-time payment status in WPForms entries
8. Multi-language support (EN, ES, FR, PT)
9. Security first (no card data stored)

## Requirements

- An active ifthenpay merchant account — [subscribe here](https://ifthenpay.com/aderir/) to obtain your credentials.
- A WPForms Gateway Key (request this from ifthenpay support/helpdesk).
- The payment methods you want enabled on that Gateway Key (our helpdesk team will guide you).
- WordPress 6.5+ and PHP 8.2+, and WPForms installed and activated.
- HTTPS (SSL) enabled on your site.

## Installation

1. **Install:** Upload the plugin zip via `Plugins → Add New → Upload`, or install from WordPress.org and Activate.
2. **Credentials:** Ensure your ifthenpay account has an active WPForms Gateway Key with the desired payment methods enabled.
3. **Setup:** Go to `WPForms → Settings → Payments` and enter your Backoffice Key.
4. **Form config:** `Create/Edit a form → Payments tab → Add the Ifthenpay field on your form → enable "ifthenpay | Payment Gateway"` and select a Gateway Key. Next, choose which payment methods to activate from those available in your gateway, and set your default payment method. Finally, add a payment description, which will be displayed on the ifthenpay payment page for all transactions.

## Frequently Asked Questions

<details>
<summary><strong>Does this plugin require WPForms?</strong></summary>
Yes. WPForms must be installed and active to use this plugin.
</details>

<details>
<summary><strong>Does it support recurring payments?</strong></summary>
No. This version supports only one-time payments via pay-by-link.
</details>

<details>
<summary><strong>Are payment details stored?</strong></summary>
No. The plugin does not store card numbers or full bank details. Only minimal references required for payment matching are kept.
</details>

<details>
<summary><strong>Does it support WPForms coupons?</strong></summary>
Yes. WPForms coupon fields are fully supported and discounts are automatically calculated.
</details>

<details>
<summary><strong>Which payment methods are supported?</strong></summary>
Any ifthenpay method attached to your Gateway Key (e.g. Multibanco, MB WAY, Payshop, Credit Card, Cofidis, Google Pay, Apple Pay, Pix).
</details>

<details>
<summary><strong>How does the payment process work?</strong></summary>
After form submission, users are presented with a secure payment page (modal or popup). Once payment is completed, the status is updated automatically via callback.
</details>

<details>
<summary><strong>What happens if a payment fails?</strong></summary>
The entry is marked as Failed. Users can retry the payment depending on your configuration.
</details>

<details>
<summary><strong>Can I customize the payment experience?</strong></summary>
Yes. You can configure display mode, button label, description, and styling within WPForms.
</details>

<details>
<summary><strong>Is there a sandbox?</strong></summary>
ifthenpay may provide test entities; if unavailable, use a low-value live test.
</details>

<details>
<summary><strong>How secure is the integration?</strong></summary>
Requests are encrypted over HTTPS; no sensitive payment data is stored.
</details>

## External Services

This plugin integrates with the ifthenpay payment platform to process payments for WPForms submissions. ifthenpay is a third-party service that provides secure payment processing for cards, wallets, and local bank transfers.

- **WPForms**
  - **What it is and what it is used for**: A form builder plugin used to create payment forms. This plugin extends its payment capabilities.

- **ifthenpay Backoffice & Integrations**
  - **What it is and what it is used for**: The ifthenpay Backoffice is the merchant dashboard used to manage integrations and payment configurations. The plugin uses the ifthenpay API to generate payment links and validate transactions.
  - **What data is sent and when**:
    - During setup: Backoffice Key and Gateway Key for authentication and configuration retrieval.
    - During payment processing: Transaction ID, amount, description, enabled payment method accounts, success/error/cancel return URLs, language, and optionally the selected payment method, customer email, customer name, and form field data.
    - During callbacks: Payment status, Transaction ID, and payment method.
  - **End-User License Agreement (EULA)**: [EULA](https://ifthenpay.com/eula/)
  - **Privacy Policy**: [Privacy Policy](https://ifthenpay.com/politica-de-privacidade/)

All network requests are performed server-side over HTTPS. Sensitive credentials are stored securely and are not publicly exposed. No raw card or bank details are stored.

## Screenshots

Below are screenshots demonstrating key features and interfaces of the plugin:

1. **(Admin Only) Backoffice Synchronization under WPForms Settings Payments**
2. **(Admin Only) WPForms's admin page (Creation/Editing Form -> Payments)**
3. **(Admin Only) Adding ifthenpay's Payment field to the selected form**
4. **(Admin Only) ifthenpay's Payment field Basic configuration options**
5. **(Admin Only) ifthenpay's Payment field Advanced configuration options**
6. **(Customers Experience) Payment Gateway field display varies by WPForms settings**
7. **(Customers Experience) Payment Modal Window**

## Support

For assistance use the [WordPress.org support forum](https://wordpress.org/support):

Pre-checks:

- Payment method enabled on Gateway Key AND mapped to Integration
- Running current recommended versions of WordPress, PHP, & WPForms

Commercial helpdesk available (no direct email required): [helpdesk.ifthenpay.com](https://helpdesk.ifthenpay.com/)

- **ifthenpay support**: [suporte@ifthenpay.com](mailto:suporte@ifthenpay.com)
- **WPForms docs**: [WPForms docs](https://wpforms.com/docs/)
