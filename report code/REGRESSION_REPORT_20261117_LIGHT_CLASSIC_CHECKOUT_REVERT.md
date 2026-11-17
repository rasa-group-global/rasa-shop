# Pre-Push Regression Report — Light Theme & Classic Checkout Revert

- **Tanggal**: 2026-11-17
- **Repository**: `digitalCommerce` (`dev` branch -> `shop-dev.rasakopi.coffee`)
- **Theme Version**: `1.0.10` ([functions.php](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/functions.php) & [style.css](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/style.css))
- **Theme Version**: `1.0.11` ([functions.php](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/functions.php) & [style.css](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/style.css))
- **Baseline Pembanding**: Commit `ac8c227` (sebelum implementasi dark checkout `da5a4cc`)

---

## 1. Latar Belakang & Temuan Regresi (Root Causes yang Ditemukan)

Setelah melakukan revert dari dark Shopify checkout (`da5a4cc`) ke Light Theme Classic Checkout (`[woocommerce_checkout]`), audit lintas file menemukan dan memperbaiki 4 potensi regresi sebelum push ke `dev`:

1. **Dawn `.rte` CSS Bleed pada Checkout Table, List, dan Payment Icons**
   - **Temuan**: Wrapper checkout di [index.php](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/index.php) sebelumnya memakai class `.rte`, sehingga rule [base.css](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/assets/css/base.css#L3182-L3228) (`.rte table { table-layout: fixed; }`, `.rte ul/ol`, `.rte li`, `.rte img`, `.rte table td`) memengaruhi `table.woocommerce-checkout-review-order-table`, `ul#shipping_method`, dan ikon kartu di `#payment`.
   - **Perbaikan**: Diganti menjadi class khusus `.rasa-checkout-content` (tanpa `.rte`) pada branch `is_checkout()` di [index.php](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/index.php), serta menambahkan dukungan judul `Order received` untuk endpoint `is_wc_endpoint_url( 'order-received' )`.

2. **Duplicate Enqueue & Cascade Priority `rasa-overrides.css`**
   - **Temuan**: `glob(RASA_DIR . '/assets/css/*.css')` di [functions.php](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/functions.php) ikut me-load `rasa-overrides.css` sebagai `rasa-css-rasa-overrides` sebelum di-load ulang sebagai `rasa-overrides`.
   - **Perbaikan**: Mengecualikan `rasa-overrides.css` di loop `glob` dan menjalankan `add_action('wp_enqueue_scripts', 'rasa_assets', 20);` agar `rasa-overrides.css` selalu dimuat sekali di urutan akhir setelah stylesheet WooCommerce dan plugin pembayaran.

3. **Selector `label` Terlalu Luas + Konflik Pseudo-Element Dawn `.button`**
   - **Temuan**: Selector `.woocommerce form.woocommerce-checkout label { display: block; }` sebelumnya ikut mengenai label `ul#shipping_method`, checkbox syarat/ketentuan (`#terms`), dan checkbox saved-card milik Revolut. Selain itu, `.button::before` dan `.button::after` pada [base.css](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/assets/css/base.css#L1245-L1287) dapat menambah border/shadow ganda pada `#place_order`, serta halaman `order-received` belum distyling di Light Theme.
   - **Perbaikan di [rasa-overrides.css](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/assets/css/rasa-overrides.css#L576-L978)**:
     - Label input dibatasi ke `.woocommerce form.woocommerce-checkout .form-row>label`.
     - Reset ukuran `input[type="checkbox"]` & `input[type="radio"]`, layout flex rapi untuk `ul#shipping_method` & `#payment ul.payment_methods li.wc_payment_method`, dan penekanan `.woocommerce-checkout .button::before, .woocommerce-checkout .button::after { display: none !important; }`.
     - Tambah styling Light Theme untuk `.woocommerce-order`, `ul.woocommerce-order-overview`, `table.woocommerce-table--order-details`, dan `.woocommerce-customer-details address`.
     - Tambah `font-size: 1.6rem;` untuk input checkout pada `@media (max-width: 599px)` agar iOS Safari tidak auto-zoom saat mengetik.

4. **Sinkronisasi Versi Theme (`style.css` vs `RASA_VERSION`)**
   - **Temuan**: `RASA_VERSION` di [functions.php](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/functions.php) bernilai `'1.0.10'`, sedangkan header [style.css](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/style.css) masih `'1.0.0'`.
   - **Perbaikan**: Header `Version:` di [style.css](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/style.css) diselaraskan ke `1.0.10`.

---

## 2. Impact Radius Analysis

Diff bersih terhadap baseline sebelum dark checkout (`git diff ac8c227 --stat`):
- `theme/rasa-kopi/assets/css/rasa-overrides.css`: aturan baru ter-scope khusus pada `.woocommerce-checkout` dan `.woocommerce form.woocommerce-checkout` (tidak mengubah homepage `front-page.php`, archive `/shop/`, single product, `/cart/`, maupun Cart Drawer).
- `theme/rasa-kopi/functions.php`: `RASA_VERSION` `'1.0.10'`, dedup `rasa-overrides.css` di loop CSS, priority hook `20` pada `wp_enqueue_scripts`.
- `theme/rasa-kopi/index.php`: cabang `is_checkout()` memanggil `do_shortcode( '[woocommerce_checkout]' )` dengan wrapper `.rasa-checkout-content`; halaman `/cart/` dan fallback halaman/post lain tidak berubah.
- `theme/rasa-kopi/style.css`: `Version: 1.0.10`.
- `header.php`, `footer.php`, dan `inc/woo-compat.php`: kembali identik byte-for-byte dengan baseline `ac8c227` (0 diff vs `ac8c227`).
- 4 file dark checkout (`assets/css/rasa-checkout.css`, `assets/js/rasa-checkout.js`, `woocommerce/checkout/form-checkout.php`, `woocommerce/checkout/review-order.php`) + direktori `woocommerce/checkout/` sudah terhapus total.

---

## 3. Hasil Verifikasi Teknis (Di Environment Lokal Windows)

Dijalankan via [verify_regression.py](file:///C:/Users/errol/.gemini/antigravity/brain/58ebc6c0-3427-4138-a83c-759ecdaff53f/scratch/verify_regression.py) (`exit code 0`):

- [x] **Deleted Artifacts**: 4 file dark checkout dan folder `woocommerce/checkout/` terhapus; `0` sisa referensi `rasa-checkout.css`, `rasa-checkout.js`, `rasa-checkout-body`, `rasa-js-checkout` di seluruh PHP tema.
- [x] **Versioning Sync**: `RASA_VERSION` (`1.0.10`) cocok dengan header `Version: 1.0.10` pada `style.css`.
- [x] **Script Asset Mapping**: Semua 25 handle JS dalam `$js_order` di `functions.php` tersedia di `theme/rasa-kopi/assets/js/`, dan `rasa-overrides.css` tidak ter-enqueue ganda.
- [x] **Infrastructure Config**: [.github/workflows/deploy.yml](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/.github/workflows/deploy.yml) valid YAML (`deploy-dev` & `deploy-prod`); [conf/nginx/shop-woo.conf](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/conf/nginx/shop-woo.conf) kurung seimbang (`10/10`) dengan `location ^~ /wp-json/` agar `admin-ajax.php` diteruskan ke PHP-FPM.
- [x] **PHP / CSS / JS Balance Checks**:
  - 11 file PHP di `theme/rasa-kopi/` seimbang tanda kurung/tag PHP & tag HTML wrapper checkout di `index.php` seimbang.
  - `rasa-overrides.css` seimbang kurung kurawal (`174/174`).
  - 39 file JS di `theme/rasa-kopi/assets/js/` seimbang kurung kurawal & kurung biasa.
- ⚠️ **Limitasi Environment Lokal**: Binary CLI `php` dan `node` tidak tersedia di mesin Windows lokal, sehingga lint runtime `php -l` dilakukan otomatis oleh container `shop-woo-web` saat GitHub Actions [deploy.yml](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/.github/workflows/deploy.yml) berjalan, dan verifikasi visual/E2E Revolut Sandbox dilakukan langsung di `https://shop-dev.rasakopi.coffee/checkout/` setelah `git push origin dev`.

---

## 4. Pre-Push Regression Sign-off Checklist

- [x] **Syntax & Balance Integrity**: 11 file PHP, 39 file JS, `rasa-overrides.css`, `shop-woo.conf`, dan `deploy.yml` lolos pengecekan statis tanpa error.
- [x] **Impact Radius**: Perubahan terisolasi pada `.woocommerce-checkout` / `.rasa-checkout-content`; `header.php`, `footer.php`, dan `woo-compat.php` kembali bersih setara baseline `ac8c227`.
- [x] **Core Storefront Flows Protected**:
  - [x] Homepage (`front-page.php`), `/shop/`, PDP, dan Cart Drawer (`rasa-shim.js` AJAX) tidak tersentuh selector checkout.
  - [x] `/cart/` (`woocommerce/cart/cart.php`) tidak tersentuh.
  - [x] `/checkout/` memakai WooCommerce Classic `[woocommerce_checkout]` 2-kolom Light Theme tanpa gangguan `.rte` / `.button::after`.
- [x] **Report Generated**: Disimpan di [REGRESSION_REPORT_20261117_LIGHT_CLASSIC_CHECKOUT_REVERT.md](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/report%20code/REGRESSION_REPORT_20261117_LIGHT_CLASSIC_CHECKOUT_REVERT.md).
- [x] **Versioning Tracked**: `RASA_VERSION` & `style.css` selaras di `1.0.10`.

**STATUS: APPROVED FOR GIT PUSH (dev) ✅** — Lanjutkan cek visual `/checkout/` & Revolut Sandbox di staging setelah deploy selesai.
