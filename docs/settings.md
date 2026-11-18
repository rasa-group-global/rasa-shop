# Store Settings & Interface Specification — Rasa Kopi (`digitalCommerce`)

Operational baseline for the WooCommerce storefront (`shop-dev.rasakopi.coffee` / production `rasakopi.coffee`).
Every configuration below is either automated idempotently in [`setup/settings.sh`](../setup/settings.sh) + runtime theme hooks in [`theme/rasa-kopi/inc/rasa-store-ops.php`](../theme/rasa-kopi/inc/rasa-store-ops.php), or documented here when WP-CLI cannot safely configure host infrastructure, secrets, or interactive UI state alone.

---

## 1. Tax (`EUR`, incl. VAT, 19% / 7% Classes, B2B Net Prices + VAT ID Field)

| Date | Setting / Feature | Value | Enforced In | Reason |
| --- | --- | --- | --- | --- |
| 2026-11-18 | Store country & currency | `DE`, `EUR`, comma decimal `,`, dot thousand `.`, symbol right with space (`79,00 €`) | `setup/settings.sh` & `.env.example` | German storefront parity with live `shop.rasakopi.coffee`. |
| 2026-11-18 | Tax calculation & gross input | `woocommerce_calc_taxes = yes`, `woocommerce_prices_include_tax = yes`, `tax_based_on = shipping`, `default_customer_address = base` | `setup/settings.sh` | German PAngV requires B2C retail prices to be entered and displayed inclusive of VAT; EU distance-selling tax follows shipping country. |
| 2026-11-18 | Shop & cart tax display | B2C: `incl`; B2B role (`b2b_customer`): `excl` (`pre_option_woocommerce_tax_display_shop` / `_cart`) | `setup/settings.sh` + `rasa-store-ops.php` | Consumer visitors always see gross retail prices; logged-in B2B accounts automatically see net (`zzgl. MwSt.`) prices across shop, PDP, cart, and Store API. |
| 2026-11-18 | Tax rates (DE) & classes | Standard `` (`19.0000%`, `MwSt. 19%`); Reduced `reduced-rate` (`7.0000%`, `MwSt. 7%`); `woocommerce_tax_total_display = itemized`; `woocommerce_shipping_tax_class = inherit` | `setup/settings.sh` | Under German UStG § 12 Abs. 2, roasted coffee beans & food items carry 7% VAT while merchandise/apparel/hardware carry 19% VAT; itemized totals + inherited shipping tax comply with mixed-cart DE invoices. Note: assign each coffee SKU to **Reduced rate** in WooCommerce Product Data. |
| 2026-11-18 | VAT ID field (`USt-IdNr.`) | Optional field `rasa-kopi/vat-id` (Block Checkout `contact` location) + `billing_vat_id` (Classic / My Account), regex `^(AT|...|XI)[0-9A-Z]{2,12}$`, saved to `_rasa_vat_id` & exposed in Admin / emails / `wc/v3/orders` REST `vat_id` | `rasa-store-ops.php` | Collects EU B2B VAT IDs on WooCommerce Block Checkout without blocking B2C guests; `woocommerce_register_additional_checkout_field` cannot be set via WP-CLI alone. |
| 2026-11-18 | B2B net prices & EU reverse charge | Logged-in `b2b_customer` role sees net (`excl.`) prices; if logged-in B2B user has saved non-DE EU VAT ID matching shipping country, `WC()->customer->set_is_vat_exempt(true)` applies 0% VAT before checkout totals | `setup/settings.sh` (`b2b_customer` role) + `rasa-store-ops.php` | Avoids mid-checkout price jumps during payment capture. **Limitation / upgrade note:** Live VIES API validation for first-time guest B2B orders is not active without Germanized Pro or an EU VAT VIES plugin; until then, assign recurring B2B buyers the `b2b_customer` role after manual VAT ID check. |

---

## 2. Shipping (Zones, Flat Rate `4.90 €`, Free from `59 €`, Shipping Classes)

| Date | Setting / Feature | Value | Enforced In | Reason |
| --- | --- | --- | --- | --- |
| 2026-11-18 | Germany shipping zone | Zone `Germany` (`DE`, priority `0`) | `setup/settings.sh` | Primary launch market is Germany. International EU/CH zones (`AT` 89 € threshold mentioned in banner fallback) can be added later once cross-border DHL rates are finalized. |
| 2026-11-18 | Flat rate shipping | `Standardversand (DHL)`: `4.90 €` (`cost = '4.90'`, `tax_status = 'taxable'`), converted from gross `4.90 €` to inclusive net+VAT at runtime | `setup/settings.sh` + `rasa-store-ops.php` | Flat rate + free shipping on purpose: `4.90 €` flat rate / free from `59 €` is the standard in Germany and matches customer expectations. DHL parcel prices inside Germany are fixed per parcel size anyway, so live carrier rates add complexity without value (international live/tiered rates deferred to later). Core WooCommerce treats shipping `cost` as net (`excl.` tax), so `woocommerce_package_rates` converts `4.90` using `WC_Tax::calc_inclusive_tax()` ensuring customers pay exactly `4,90 €` incl. VAT whether buying 7% coffee or 19% merch. |
| 2026-11-18 | Free shipping threshold | `Kostenloser Versand`: `requires = min_amount`, `min_amount = 59`, `ignore_discounts = no`; paid `flat_rate` is hidden when `free_shipping` qualifies | `setup/settings.sh` + `rasa-store-ops.php` + `template-tags.php` | Prevents customers over `59,00 €` from accidentally selecting paid shipping. `rasa_free_shipping_bar` uses `WC()->cart->display_prices_including_tax()` so the progress bar matches WooCommerce free shipping evaluation 1:1. |
| 2026-11-18 | Shipping classes | `Keey` (`slug: keey`), `Partner roaster` (`slug: partner-roaster`) | `setup/settings.sh` | Classifies SKUs between Keey warehouse fulfillment and Partner roaster dropshipment while sharing the unified `4.90 €` customer shipping rate (`class_cost_*` left empty unless split surcharges are introduced later). |

---

## 3. Checkout & Payments

| Date | Setting / Feature | Value | Enforced In | Reason |
| --- | --- | --- | --- | --- |
| 2026-11-18 | Active payment gateways | `revolut_cc = yes` (Pay with card), `revolut_pay = yes` (Revolut Pay); `bacs`, `cheque`, `cod`, `revolut_pay_by_bank`, `revolut_payment_request` = `no` | `setup/settings.sh` | Tested end-to-end on Light Block Checkout (Order `#168`, `197,00 €`). |
| 2026-11-18 | Test mode vs Live mode | Controlled by `woocommerce_revolut_settings['mode']` (`sandbox` on `shop-dev`; `live` on production). `setup/settings.sh` defaults to `REVOLUT_MODE=keep` | Manual / WP-CLI (`REVOLUT_MODE=sandbox\|live bash setup/settings.sh`) | Switching between Sandbox (`r_test_...` + `pk_...` + `revolut-sandbox-compat.php`) and Live (OAuth merchant token or `rk_live_...` + `pk_live_...` in WP Admin -> WooCommerce -> Settings -> Payments -> Revolut) involves secret credentials that must never be stored in git. Note `revolut-sandbox-compat.php` strictly guards `sandbox-merchant.revolut.com` while `mode = sandbox`. |
| 2026-11-18 | Guest checkout & accounts | `enable_guest_checkout = yes`, `enable_checkout_login_reminder = yes`, `enable_signup_and_login_from_checkout = yes`, `enable_myaccount_registration = yes`, auto username/password = `yes` | `setup/settings.sh` | Minimizes B2C checkout friction while still permitting account creation and B2B login. |
| 2026-11-18 | Required checkout fields | Contact email, First name, Last name, Address line 1, Postcode, City, Country (`DE` default via `woo-compat.php`) required; Company, Address line 2, Phone (`woocommerce_checkout_*_field`), and VAT ID (`rasa-kopi/vat-id`) are `optional` | `setup/settings.sh` + `woo-compat.php` + Block Editor | **Note on Block Checkout:** WooCommerce Block Checkout persists billing/shipping field visibility attributes inside the Checkout page's block markup (`wp:woocommerce/checkout`). After first deploy, open WP Admin -> Pages -> Checkout -> Address block sidebar once to confirm Company / Phone / Apartment match `Optional`. |

---

## 4. Orders & E-mails

| Date | Setting / Feature | Value | Enforced In | Reason |
| --- | --- | --- | --- | --- |
| 2026-11-18 | Order number format | Native WooCommerce post ID (`#168`, numeric) | Core WooCommerce (`docs/settings.md` record) | Matches Shopify's numeric `#<id>` display in `thankyou.php` (`Confirmation #168`). Native WC IDs are not contiguous across posts/products; if German GoBD strictly requires gapless sequential invoice/order numbers on Go-Live without external ERP numbering, install a sequential order numbers plugin starting above the current max ID (`>= 1000`). Wait until Go-Live so Revolut `merchant_order_ext_ref` during QA stays consistent. |
| 2026-11-18 | Email sender | `From name`: `Rasa Kopi`, `From address`: `shop@rasakopi.coffee` | `setup/settings.sh` | Branded transactional delivery from verified domain address. Note: configure SPF/DKIM/DMARC or SMTP mailer plugin on host/DNS so `shop@rasakopi.coffee` does not land in spam. |
| 2026-11-18 | Enabled transactional emails | Admin (to `shop@rasakopi.coffee`): `new_order`, `cancelled_order`, `failed_order` ON. Customer: `customer_on_hold_order`, `customer_processing_order`, `customer_completed_order`, `customer_refunded_order`, `customer_invoice` (manual order details/pay email), `customer_note`, `customer_reset_password`, `customer_new_account` ON | `setup/settings.sh` | Complete operational alerting to `shop@rasakopi.coffee` and standard customer lifecycle emails. |

---

## 5. Germanized / German Market

| Date | Setting / Feature | Value | Enforced In | Reason |
| --- | --- | --- | --- | --- |
| 2026-11-18 | Plugin choice & status | **Germanized for WooCommerce** (Free) recommended over German Market; optional auto-install via `INSTALL_GERMANIZED=1 bash setup/settings.sh` | `setup/settings.sh` (conditional) + `rasa-store-ops.php` | Keeps stack lightweight and compatible with Shopify Dawn theme templates (`single-product.php`) and Light Block Checkout without unintended template overrides. |
| 2026-11-18 | Invoice feature | **OFF** (`woocommerce_gzdp_invoice_enabled = no`, `woocommerce_gzdp_invoice_auto_enabled = no`, plus `add_filter('storeabill_invoice_create_document', '__return_false', 99)`) | `setup/settings.sh` + `rasa-store-ops.php` | PDF accounting invoices are generated outside WooCommerce (prevents duplicate invoice numbers and unwanted PDF attachments). |
| 2026-11-18 | Unit prices (`Grundpreis`) | Enabled in shop, PDP, cart, checkout when Germanized active (`woocommerce_gzd_display_*_unit_price = yes`); standalone `_rasa_unit_price` fallback rendered below PDP price | `setup/settings.sh` + `rasa-store-ops.php` + `single-product.php` | PAngV mandatory for ground/whole-bean coffee sold by weight (e.g. per `1 kg`). |
| 2026-11-18 | Delivery time (`Lieferzeit`) | Default `3–7 Werktage` term (`product_delivery_time` slug `3-7-werktage`) + `woocommerce_gzd_display_*_delivery_time = yes`, runtime fallback `rasa_get_product_delivery_time()` | `setup/settings.sh` + `rasa-store-ops.php` + `single-product.php` | Required German legal delivery estimate on PDP, preserving Dawn layout. |
| 2026-11-18 | Checkout checkboxes | Block Checkout renders native Terms & Conditions + Privacy Policy consent statement; if Free Germanized is activated, review **WooCommerce -> Settings -> Germanized -> Legal Checkboxes** in WP Admin | Manual WP Admin check (serialized checkbox manager) | Germanized Legal Checkboxes store multi-field serialized objects (`woocommerce_gzd_legal_checkboxes_settings`) including page links (`AGB`, `Datenschutz`, `Widerruf`); cannot be safely initialized with placeholders via WP-CLI before those CMS pages are verified on the target host. |

---

## 6. Interfaces (`Real Cron`, `REST API`, `"Fulfilled by"` + `"Roaster"`)

| Date | Setting / Feature | Value | Enforced In | Reason |
| --- | --- | --- | --- | --- |
| 2026-11-18 | Disable WP pseudo-cron | `define( 'DISABLE_WP_CRON', true );` | `setup/settings.sh` (`wp config set DISABLE_WP_CRON true --raw`) | Prevents slow page loads on customer traffic and guarantees scheduled tasks / Action Scheduler queues run on schedule even on low traffic. |
| 2026-11-18 | Host system cron (Real Cron) | `/etc/cron.d/rasa-woo` on VPS host running every 5 min: `*/5 * * * * root docker exec shop-woo-web php -d open_basedir= -d disable_functions= /usr/local/bin/wp cron event run --due-now --allow-root >/dev/null 2>&1` | Host VPS (`/etc/cron.d/rasa-woo` — manual host install) | WP-CLI runs inside container/repo and cannot modify host `/etc/cron.d/`; must be added once on VPS with root privileges. Also schedules WooCommerce Action Scheduler cleanly via WP cron hook (`action_scheduler_run_queue`). |
| 2026-11-18 | REST API reachable | Permalinks `/%postname%/`; Nginx routes `^~ /wp-json/` to `/index.php?$args`; confirm TLS reverse proxy (`rasa-shared-proxy`) forwards `Authorization` header & Wordfence (if enabled) does not block Application Passwords / `/wp-json/wc/v3/*` | `setup/settings.sh` + `conf/nginx/shop-woo.conf` + Manual VPS / WP Admin | REST API keys (**WooCommerce -> Settings -> Advanced -> REST API -> Add key**, Read/Write) contain sensitive Consumer Secret credentials shown only once at creation time, so keys must be generated manually in WP Admin and stored in the integrator's secret manager. Test with `curl -sI https://shop-dev.rasakopi.coffee/wp-json/wc/v3`. |
| 2026-11-18 | Product fields: `"Fulfilled by"` & `"Roaster"` | Product Data -> General (`_rasa_fulfilled_by`: select `Keey` / `Partner roaster` / `Rasa Kopi`; `_rasa_roaster`: text input); REST v3 `/wp-json/wc/v3/products` top-level `fulfilled_by` & `roaster` (GET/POST/PUT) + WP REST meta; snapshotted onto order line items & `/wp-json/wc/v3/orders` (`line_items[].fulfilled_by`, `line_items[].roaster`) | `theme/rasa-kopi/inc/rasa-store-ops.php` | Enables Keey / Partner roaster fulfillment routing per SKU without needing separate plugins, and freezes fulfillment/roaster provenance on historical orders even if product metadata changes later. |
