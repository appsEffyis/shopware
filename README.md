# 💳 LodinPayment - Shopware 6 Payment Plugin

## 📌 Overview

**LodinPayment** is a custom payment plugin built for Shopware that integrates the LodinPay API into the checkout process.

The plugin enables customers to securely complete payments through LodinPay, while ensuring proper transaction handling, redirection, and webhook-based status updates.

---

## 🚀 Features

* 🔐 Secure integration with LodinPay API
* 🔄 Redirect customers to external payment gateway
* ✅ Handle successful payments (Shopware native success page)
* ❌ Handle failed payments (Shopware native failure page)
* 🔔 Webhook support for real-time payment updates
* 🧾 Transaction state management (authorize, fail, etc.)
* 🏦 Support for bank-based payment flows

---

## 🛠️ Tech Stack

* **Backend:** PHP (Symfony - Shopware 6)
* **Platform:** Shopware Plugin System
* **Database:** MySQL
* **Environment:** Docker (Dockware)
* **API:** REST (LodinPay)

---

## 📂 Project Structure

```
custom/plugins/LodinPayment/
│
├── src/
│   ├── Controller/        # Return & webhook controllers
│   ├── Core/
│   │   └── Checkout/
│   │       └── Payment/   # Payment handler logic
│
├── Resources/             # Config, views, assets
├── composer.json
└── LodinPayment.php       # Plugin entry point
```

---

## ⚙️ Installation

### 1. Clone the repository

```bash
git clone https://github.com/your-repo/shopware.git
cd shopware
```

### 2. Place the plugin

Move the plugin into:

```bash
custom/plugins/LodinPayment
```

### 3. Install & activate

```bash
bin/console plugin:refresh
bin/console plugin:install --activate LodinPayment
```

### 4. Clear cache

```bash
bin/console cache:clear
```

---

## 🔑 Configuration

⚠️ **IMPORTANT: Never commit real credentials to GitHub**

Set your credentials using environment variables:

```env
LODIN_CLIENT_ID=your_client_id
LODIN_CLIENT_SECRET=your_client_secret
```

If using Docker, define them in `docker-compose.yml` or `.env`:

```yaml
environment:
  LODIN_CLIENT_ID: ${LODIN_CLIENT_ID}
  LODIN_CLIENT_SECRET: ${LODIN_CLIENT_SECRET}
```

---

## 🔄 Payment Flow

1. Customer places an order on Shopware
2. Plugin sends payment request to LodinPay API
3. Customer is redirected to LodinPay payment page
4. After payment:

   * ✅ Success → Redirect to `/checkout/finish`
   * ❌ Failure → Redirect to `/checkout/confirm` with error
5. Webhook updates the transaction status automatically

---

## 🧪 Testing

### Test payments

* Place orders via Shopware frontend
* Use sandbox/preprod LodinPay credentials

### Check logs

```bash
docker exec -it shopware cat /var/www/html/var/log/dev.log
```
---
