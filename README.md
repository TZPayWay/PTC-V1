# TZPayWay Payment Gateway Module (PTC-V1)

[![Laravel](https://img.shields.io/badge/Laravel-8.x%20--%2011.x-red.svg)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.1%20|%208.2%20|%208.3%20|%208.4-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Official **TZPayWay** payment gateway integration module for **PTC / Viserlab Laravel Scripts** (such as PTCLab, HyipLab, MicroLab, etc.). 

Accept payments seamlessly with local payment methods (bKash, Nagad, Rocket, Upay), Bank Transfer, and Crypto directly from your website users with automated instant payment verification (IPN).

---

## 📁 Repository Structure

```text
PTC-V1/
├── core/
│   └── app/
│       └── Http/
│           └── Controllers/
│               └── Gateway/
│                   └── TZPAYWAY/
│                       └── ProcessController.php
├── routes/
│   └── ipn.php
├── database.sql
├── .gitignore
└── README.md
```

---

## 🚀 Installation & Integration Guide

### Step 1: Upload Files to Your Laravel Script
1. Copy the `core` directory to your Laravel script root directory:
   - File target: `core/app/Http/Controllers/Gateway/TZPAYWAY/ProcessController.php`
2. Open your website's `routes/ipn.php` file and append the following route definitions:

```php
// TZPayWay Webhook IPN
Route::post('TZPAYWAY', 'TZPAYWAY\ProcessController@ipn')->name('TZPAYWAY');
Route::post('tzpayway', 'TZPAYWAY\ProcessController@ipn')->name('tzpayway');
```

---

### Step 2: Import Database SQL
Open your database management tool (**phpMyAdmin**, MySQL CLI, or TablePlus), select your script's database, and run `database.sql`:

```sql
INSERT INTO `gateways` (`code`, `name`, `alias`, `image`, `status`, `gateway_parameters`, `supported_currencies`, `crypto`, `description`, `created_at`, `updated_at`) 
VALUES 
(133, 'TZPAYWAY', 'TZPAYWAY', 'tzpayway.png', 1, '{"api_key":{"title":"API Key","global":true,"value":""},"api_url":{"title":"API Base URL","global":true,"value":"https://tzpayway.com"}}', '{"BDT":"BDT","USD":"USD"}', 0, 'Pay securely via bKash, Nagad, Rocket, Upay, Bank Transfer, and Crypto through TZPayWay.', NOW(), NOW())
ON DUPLICATE KEY UPDATE 
`name` = VALUES(`name`),
`alias` = VALUES(`alias`),
`gateway_parameters` = VALUES(`gateway_parameters`),
`supported_currencies` = VALUES(`supported_currencies`),
`updated_at` = NOW();
```

---

### Step 3: Configure Gateway in Admin Panel
1. Log in to your script's **Admin Dashboard**.
2. Navigate to **Payment Gateways** > **Automatic Gateways**.
3. Locate **TZPAYWAY** in the list and click **Edit**.
4. Configure your credentials:
   - **API Key**: Paste your Merchant API Key obtained from your [TZPayWay Merchant Dashboard](https://tzpayway.com/user/api-keys).
   - **API Base URL**: `https://tzpayway.com`
5. Click **Add Currency** (or configure existing):
   - **Currency**: `BDT` (or `USD`)
   - **Conversion Rate**: Set conversion rate relative to your site base currency (e.g., `1 USD = 120 BDT`).
   - **Min / Max Limits**: Set allowed minimum and maximum deposit amounts.
   - **Charges**: Configure optional fixed or percentage deposit fees.
6. Toggle the status to **Enabled** and save.

---

## 🔔 How Instant Payment Notifications (IPN) Work
1. When a user creates a deposit, they are redirected to TZPayWay's checkout window.
2. Upon customer payment confirmation, TZPayWay immediately dispatches a secure webhook notification to:
   `https://yourdomain.com/ipn/TZPAYWAY`
3. `ProcessController@ipn` receives the payload, verifies the transaction reference (`track` / `trx_id`), checks payment completion with TZPayWay, marks the deposit as approved, and credits the user's account balance instantly.

---

## 🔒 Security & Verification
- **Header Authentication**: Payment requests are signed with `X-API-KEY`.
- **Double Verification**: The IPN handler includes automatic direct server-to-server verification with TZPayWay's status API to ensure valid payments.
- **Idempotency Protection**: Already processed transactions are acknowledged without double-crediting.

---

## 💻 Requirements
- **PHP**: 8.1 or higher
- **cURL Extension**: Enabled
- **Active TZPayWay Merchant Account**: [Register at TZPayWay](https://tzpayway.com/register)

---

## 🤝 Support
- **Developer Documentation**: [https://tzpayway.com/api-docs](https://tzpayway.com/api-docs)
- **GitHub Repository**: [https://github.com/tzpayway/PTC-V1](https://github.com/tzpayway/PTC-V1)
- **Support Email**: support@tzpayway.com
