# Regression Report — Block Checkout Field Notice & Quirks-Mode Black Viewport Fix (`1.0.18`)

**Date**: 2026-11-19  
**Theme Version**: `1.0.18`  
**Regression Script**: [`tests/regression_check.py`](../tests/regression_check.py)

---

## Root Cause Analysis of Both Reported Issues

1. **Issue 1 (`/checkout/` — WP `_doing_it_wrong` Notice + blank checkout below notice)**
   - [`theme/rasa-kopi/inc/rasa-store-ops.php`](../theme/rasa-kopi/inc/rasa-store-ops.php) registered `rasa-kopi/vat-id` via `woocommerce_register_additional_checkout_field()` with `'placeholder' => 'DE123456789'` inside `'attributes'`.
   - WooCommerce's Additional Checkout Fields API rejects `'placeholder'` inside `'attributes'` (whitelist: `autocomplete`, `autocapitalize`, `maxLength`, `readOnly`, `pattern`, `title`, `data-*`, `aria-*`), emitting a WordPress `Notice` on `woocommerce_init` before HTTP headers were sent.
   - Early output triggered `headers already sent` during `/checkout/` WooCommerce redirects/cookie headers, leaving only the notice text on a blank page.
   - **Secondary fix found during audit**: `validate_callback` previously accepted `($value, $errors)` and called `$errors->add(...)`, whereas WooCommerce `CheckoutFields::validate_field()` passes `($value)` (and optional `$field`) and expects `return new WP_Error(...)` on failure or `true` on success.

2. **Issue 2 (`/` homepage — PHP Notice + solid black viewport below marquee)**
   - Emitting the same `woocommerce_init` Notice BEFORE `<!doctype html>` forced Chrome into **Quirks Mode** (`BackCompat`).
   - In [`theme/rasa-kopi/assets/css/base.css`](../theme/rasa-kopi/assets/css/base.css), `.utility-bar { height: 100%; }` relies on Standards Mode (`body { min-height: 100%; }` resolving child `100%` to `auto`). In Quirks Mode, `.utility-bar { height: 100%; }` walks up to `html { height: 100%; }` (`100vh`) while [`theme/rasa-kopi/header.php`](../theme/rasa-kopi/header.php) sets `background: #000000 !important`, stretching `.utility-bar` over the initial viewport and pushing the header + hero off-screen.

---

## Fixes Applied

- [`theme/rasa-kopi/inc/rasa-store-ops.php`](../theme/rasa-kopi/inc/rasa-store-ops.php):
  - Removed `'placeholder'` from `woocommerce_register_additional_checkout_field['attributes']`; kept whitelisted `'autocomplete' => 'off'` and `'autocapitalize' => 'characters'`.
  - Fixed `'validate_callback'` to accept `($value)` and `return new WP_Error(...)` / `return true`.
  - Guarded `rasa_is_b2b_customer()` (`function_exists('is_user_logged_in')`, `wp_get_current_user`) and `WC_Tax::get_shipping_tax_rates()` (`WC()->cart` check).
- [`theme/rasa-kopi/header.php`](../theme/rasa-kopi/header.php):
  - Added `height: auto !important;` on `.utility-bar` so `base.css`'s `height: 100%` can never expand `.utility-bar` to `100vh`.
- [`scripts/first-run.sh`](../scripts/first-run.sh):
  - Aligned `woocommerce_currency_pos` to `'right_space'` matching `EUR` DE price formatting.
- [`theme/rasa-kopi/functions.php`](../theme/rasa-kopi/functions.php) & [`theme/rasa-kopi/style.css`](../theme/rasa-kopi/style.css):
  - Bumped theme version to `1.0.18`.

---

## Permanent Regression Checks Added (`tests/regression_check.py`)

Future pre-push checks now permanently enforce:
1. **Additional Checkout Fields Contract**: Fails if `'attributes'` contains any non-whitelisted key (`placeholder`, `maxlength`, `class`, `style`, `inputmode`, etc.), if `'placeholder'` appears anywhere in registration, or if `'validate_callback'` calls `->add(` instead of returning `new WP_Error(...)` and `true`.
2. **Early-Output & Quirks-Mode Traps**: Fails on UTF-8 BOM, leading whitespace before `<?php`, trailing `?>` in library files (`functions.php`, `inc/*.php`), direct output in `init`/`woocommerce_init`/`plugins_loaded`/`after_setup_theme`, HTML before `<!doctype html>`, or missing `height: auto !important` on `.utility-bar` in `header.php`.
3. **Full Theme, Shell & Config Parity**: Single theme version check, Light Block Checkout + Shopify `thankyou.php` checks, strict CSS brace & section-reset check, PHP balance check across all 14 files, heredoc/inline PHP balance + LF checks across `setup/settings.sh` and `scripts/first-run.sh`, YAML + Nginx `/wp-json/`, `docs/settings.md`, and secret hygiene.
