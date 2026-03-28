# Custom EmailPDF for Magento 2

A lightweight, zero-dependency Magento 2 module that automatically generates a PDF order confirmation and emails it to the store's General Contact email address as soon as a customer places an order.

**Key Features:**
- **Zero Composer Dependencies:** Uses a self-contained, pure-PHP PDF builder. It does not rely on `mPDF`, `Dompdf`, or `tcpdf`, making it extremely easy to install and guaranteed to work in local environments like XAMPP.
- **No Admin Backend Clutter:** Runs entirely in the background via Magento's event system (`sales_order_place_after`) without taking up space in the admin panel.
- **Built-in Developer Test CLI:** Includes a command-line test script (`test_pdf.php`) that allows you to trigger the email for any order without placing a new test order through the storefront.
- **SMTP Compatible:** Leverages Magento's native `\Magento\Framework\Mail\EmailMessageInterface` Laminas transport system, meaning it works flawlessly with Mageplaza SMTP, Magepal SMTP, and native Sendmail.

---

## 📦 Installation

Since this module has no external dependencies, simply place it into your `app/code` directory:

```bash
# 1. Create the module directory
mkdir -p app/code/Custom/Emailpdf

# 2. Copy the module files into the directory (from this repo)
# app/code/Custom/Emailpdf/registration.php
# app/code/Custom/Emailpdf/composer.json
# ...

# 3. Enable the module
php bin/magento module:enable Custom_Emailpdf

# 4. Run setup upgrade and flush cache
php bin/magento setup:upgrade
php bin/magento cache:flush
```

---

## ⚙️ Configuration

There is no admin configuration required. The module will automatically use the store's configuration:
- **Sender:** General Contact Email (Set via *Stores → Configuration → General → Store Email Addresses*)
- **Recipient:** General Contact Email (It sends the PDF *to* the store admin).

### Module Setup in Code
If you want to manually redirect the emails to a different address or enable testing mode, open `app/code/Custom/Emailpdf/Observer/SendOrderPdf.php` and edit the constants at the top:

```php
    // =========================================================
    // TEST / DEBUG CONTROLS
    // =========================================================
    // Set to true to bypass normal event flow and use a test order
    private const TEST_MODE          = false;
    
    // An existing increment_id of an order to use for testing
    private const TEST_ORDER_ID      = '000000002';         
    
    // Set to your email (e.g., 'dev@domain.com') to redirect all PDFs there
    private const OVERRIDE_RECIPIENT = ''; 
    // =========================================================
```

---

## 🧪 Testing via CLI

You don't need to perform an actual checkout to test the PDF layout and email dispatch. You can use the included CLI test script:

1. Open `app/code/Custom/Emailpdf/test_pdf.php`
2. Update the config variables at the top of the file:
   ```php
   $testOrderIncrementId = '000000001';     // An existing order increment ID in your store
   $overrideEmail        = 'your@email.com'; // Where to send the test email
   $savePdfToDisk        = true;            // Optionally save the PDF to var/ for inspection
   ```
3. Run the script from the Magento root directory:
   ```bash
   php app/code/Custom/Emailpdf/test_pdf.php
   ```

---

## 📝 Logging

The module logs all successes and errors to a dedicated log file independently of Magento's PSR logger. 
You can view the logs at: `var/log/custom_emailpdf.log`

---

## 📄 License
MIT License. Feel free to use, modify, and distribute as needed.
