# M-Pesa Gateway for Paid Memberships Pro

[![GitHub Release](https://img.shields.io/github/v/release/urandu/pmpro-mpesa-gateway)](https://github.com/urandu/pmpro-mpesa-gateway/releases/latest)
[![Tests](https://img.shields.io/badge/tests-25%20passing-brightgreen)](#tests)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-blue)](LICENSE)

Accept M-Pesa payments on your WordPress membership site powered by
[Paid Memberships Pro](https://www.paidmembershipspro.com/) using the
**Safaricom Daraja STK Push** (Lipa Na M-Pesa Online / M-Pesa Express) API.

> **Live page:** [urandu.github.io/pmpro-mpesa-gateway](https://urandu.github.io/pmpro-mpesa-gateway)

---

## Features

- 🚀 **Daraja STK Push** – customers receive an M-Pesa PIN prompt directly on their phone; no manual "go to M-Pesa and pay" instructions
- 🔄 **Automatic status polling** – `mpesa_stk_query()` checks Daraja if the async callback hasn't arrived yet, so checkout completes on the second form submission
- 🔒 **Secure callback endpoint** – HMAC-safe secret UID validates every Safaricom server-to-server call
- 🛡️ **SQL-injection free** – all database operations use `wpdb->prepare()` / `wpdb->insert/update/delete()`
- ♻️ **WordPress HTTP API** – no raw cURL; respects WordPress proxy settings
- 🧪 **25 PHPUnit tests** included

---

## Requirements

| Requirement | Minimum version |
|---|---|
| PHP | 7.4 or higher |
| WordPress | 5.6 or higher |
| Paid Memberships Pro | 2.9 or higher |
| Safaricom Daraja API | v1 (sandbox + production) |

---

## Installation

### From a GitHub Release (recommended)

1. Go to the [Releases](https://github.com/urandu/pmpro-mpesa-gateway/releases/latest) page and download the latest `pmpro-mpesa-gateway-x.x.x.zip`.
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Upload the zip file and click **Install Now**, then **Activate**.

### Manual / Git

```bash
cd wp-content/plugins
git clone https://github.com/urandu/pmpro-mpesa-gateway.git
```

Then activate the plugin from **Plugins → Installed Plugins**.

---

## Configuration

### 1 – Get your Daraja API credentials

1. Log in to the [Safaricom Developer Portal](https://developer.safaricom.co.ke/).
2. Create an app (or use an existing one) and note down:
   - **Consumer Key**
   - **Consumer Secret**
3. Under **Lipa Na M-Pesa Online**, retrieve your **Passkey** for your shortcode.

### 2 – Configure the plugin

Go to **WordPress Admin → Memberships → Payment Settings** and select **M-Pesa (Daraja)** as the gateway, then fill in:

| Field | Description |
|---|---|
| **Business Short Code** | Your Safaricom paybill or till number |
| **Consumer Key** | From the Daraja developer portal |
| **Consumer Secret** | From the Daraja developer portal |
| **Lipa Na M-Pesa Passkey** | Online passkey for your shortcode |

The settings page also shows your auto-generated **STK Push Callback URL** – copy this value.

### 3 – Configure your Daraja app

In the [Safaricom Developer Portal](https://developer.safaricom.co.ke/):

1. Open your app's settings.
2. Paste the **Callback URL** from step 2 into the **CallbackURL** field.
3. Save.

### 4 – Sandbox vs Production

In WordPress Admin → Memberships → Payment Settings, set **Gateway Environment** to:

- `sandbox` – use the Safaricom sandbox (test credentials, test phone `254708374149`)
- `live` – use the production Safaricom API

---

## How It Works

```
Customer enters phone number and clicks "Submit"
        │
        ▼
Plugin calls Daraja STK Push API
        │
        ▼
Customer receives PIN prompt on their phone
        │
        ├─ Customer enters PIN
        │       │
        │       ├─ Safaricom POSTs callback → result_code=0 saved in DB
        │       │
        │       └─ Customer clicks "Submit" again
        │               │
        │               └─ Plugin finds confirmed row → order marked SUCCESS ✅
        │
        └─ Callback not received yet?
                │
                └─ Customer clicks "Submit" again
                        │
                        └─ Plugin calls mpesa_stk_query() → polls Daraja directly
                                │
                                ├─ Confirmed → order marked SUCCESS ✅
                                ├─ Cancelled/expired → new STK Push initiated
                                └─ Still pending → "Please check your phone"
```

---

## API Reference

### `mpesa_get_access_token(): string|false`

Obtains an OAuth 2.0 access token from the Daraja API using the saved Consumer Key and Consumer Secret.

Returns the token string on success, `false` on failure.

---

### `mpesa_stk_push( string $phone, float $amount, string $reference, string $description = '' ): object|false`

Initiates a Daraja STK Push request (M-Pesa Express / Lipa Na M-Pesa Online).

| Parameter | Type | Description |
|---|---|---|
| `$phone` | `string` | Phone number in international format e.g. `254712345678` |
| `$amount` | `float` | Amount to request (will be rounded up to the nearest integer) |
| `$reference` | `string` | Account reference – typically the order code (max 12 chars) |
| `$description` | `string` | Short transaction description (max 13 chars) |

Returns the decoded Daraja API response object, or `false` on connection failure.

**Important:** a pending DB row is inserted *before* the API call so the async callback can be matched by `checkout_request_id`. If the API rejects the request the row is cleaned up automatically.

---

### `mpesa_stk_query( string $checkout_request_id ): object|false`

Queries the Daraja STK Push Query API for the status of a previously initiated push.

| Parameter | Type | Description |
|---|---|---|
| `$checkout_request_id` | `string` | The `CheckoutRequestID` returned by `mpesa_stk_push()` |

Returns the decoded Daraja API response. Key field: `ResultCode`.

| ResultCode | Meaning |
|---|---|
| `0` | Transaction completed successfully |
| `1032` | Request cancelled by the user |
| `1037` | DS timeout – user did not respond |
| `17` | Request already in process |

---

### `mpesa_process_stk_callback( object $data, string $raw_payload ): bool`

Persists a Daraja STK Push callback to the `wp_pmpro_mpesa` table.

Called automatically by `pmpro_mpesa_ipn_listener()`. Can also be called programmatically if you handle the callback yourself.

---

## Database Schema

The plugin creates and manages a `wp_pmpro_mpesa` table (prefix may vary):

| Column | Type | Description |
|---|---|---|
| `id` | `bigint` | Primary key, auto-increment |
| `msisdn` | `varchar(20)` | Phone number in international format |
| `time` | `datetime` | Row creation timestamp |
| `user_id` | `varchar(255)` | WordPress user ID (optional) |
| `amount` | `float` | Amount paid / requested |
| `order_id` | `varchar(255)` | PMPro order code, `-1` until confirmed |
| `payload` | `longtext` | Raw Daraja callback JSON |
| `mpesa_transaction_id` | `varchar(50)` | M-Pesa receipt number |
| `checkout_request_id` | `varchar(100)` | Daraja `CheckoutRequestID` for status queries |
| `result_code` | `int` | `-1` pending · `0` success · `>0` failed |

---

## Tests

The plugin ships with a PHPUnit test suite. No extra packages are required – the globally installed PHPUnit 8.5+ is used.

```bash
cd pmpro-mpesa-gateway
phpunit --testdox
```

Expected output:

```
Mpesa Gateway
 ✔ Normalize phone (5 formats)
 ✔ Get access token – success / connection error / missing token / live endpoint
 ✔ STK Push – no token / pending row / checkout_request_id stored / API rejection cleanup
 ✔ STK Query – no token / success / cancelled / connection error / live endpoint
 ✔ Callback – invalid payload / new row / update row / failed payment / no existing row
 ✔ IPN listener – no query var / invalid UID / missing UID
```

---

## Changelog

### 1.0.0
- Complete rewrite using the Daraja STK Push (Lipa Na M-Pesa Online) API
- Replaced raw cURL with the WordPress HTTP API
- Fixed PHP 4-style constructor
- Fixed SQL injection vulnerabilities
- Added `mpesa_stk_query()` for live payment status polling
- Added `checkout_request_id` and `result_code` DB columns
- Added 25-test PHPUnit test suite
- Renamed settings: `mpesa_api_key` → `mpesa_consumer_key`, `mpesa_secret_key` → `mpesa_consumer_secret`
- Added `mpesa_passkey` setting

---

## Contributing

Pull requests and issues are welcome! Please open an issue first to discuss what you'd like to change.

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-feature`)
3. Run the tests (`phpunit --testdox`)
4. Push and open a Pull Request

---

## License

GPL-2.0-or-later – see [LICENSE](LICENSE).

