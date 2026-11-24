# Pre-Push Regression Report — Shopify-Style Order Received (`1.0.16`)

- **Tanggal**: 2026-11-18
- **Repository**: `digitalCommerce` (branch `dev` → `shop-dev.rasakopi.coffee`)
- **Theme Version**: `1.0.16` ([functions.php](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/functions.php#L14) & [style.css](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/style.css#L6))
- **HEAD Baseline**: Commit `9904cc6` (`1.0.14`, termasuk Light Block Checkout & Revolut Sandbox `r_test_` compatibility layer)

---

## 1. Latar Belakang & Ringkasan Perubahan

1. **Verifikasi E2E Staging pada `1.0.14` (`9904cc6`)**:
   - Adapter [revolut-sandbox-compat.php](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/inc/revolut-sandbox-compat.php) berhasil menyelesaikan alur Revolut Sandbox hingga status **Order received** (Order `#168`, `197,00 €`, `Pay with card`).
2. **Desain Ulang Halaman Order Received (`1.0.15` → `1.0.16`)**:
   - Membuat template override [thankyou.php](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/woocommerce/checkout/thankyou.php) dengan layout 2-kolom ala Shopify Thank You (*Confirmation hero* dengan ikon centang, kartu status pesanan + email tujuan, kartu grid 2-kolom *Order details*, tombol *Continue shopping* & tautan *Contact us*, serta kartu sticky *Order summary* di kolom kanan dengan thumbnail `5.6rem` + badge kuantitas bulat `#3f3f3f`).
   - Memperbaiki rendering nama variasi item pada `thankyou.php` menggunakan `wp_kses_post( apply_filters( 'woocommerce_order_item_name', ... ) )` agar tag `<span> - </span>` dari WooCommerce tidak tampil mentah sebagai teks HTML.
   - Memperbarui cabang `is_checkout()` di [index.php](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/index.php#L16-L39) agar `<h1 class="h1 article__title">` di luar grid disembunyikan khusus saat `is_wc_endpoint_url('order-received')`, mencegah judul kembar di atas header konfirmasi.
   - Menambahkan stylesheet terisolasi `.rasa-ty-*` (beserta media query `@media screen and (max-width: 899px)` dan selector `.woocommerce .rasa-ty a.button.rasa-ty-btn-primary` agar `.woocommerce a.button` tidak merusak vertical centering teks tombol *Continue shopping*) di [rasa-overrides.css](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/assets/css/rasa-overrides.css#L540-L989) dan men-sync `RASA_VERSION` ke `'1.0.16'`.

---

## 2. Impact Radius Analysis

Diff bersih terhadap `HEAD` (`9904cc6`):

| File | Perubahan | Impact Radius & Isolasi |
|------|-----------|-------------------------|
| [thankyou.php](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/woocommerce/checkout/thankyou.php) | 300 baris (`wp_kses_post`) | Hanya aktif pada endpoint WooCommerce `order-received`; memakai `wp_kses_post( apply_filters( 'woocommerce_order_item_name', ... ) )` agar variasi tidak menampilkan tag `<span>`. |
| [index.php](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/index.php#L16-L39) | `+4/-4` baris | Kondisional `$is_order_received` hanya menyembunyikan heading luar pada `order-received`; `/checkout/`, `order-pay`, `/cart/`, dan halaman WP lain tidak berubah. |
| [rasa-overrides.css](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/assets/css/rasa-overrides.css#L540-L989) | `.rasa-ty-*` + button flex | Seluruh selector baru ter-scope di bawah `.rasa-ty` / `.rasa-ty-*`, dan `.woocommerce .rasa-ty a.button.rasa-ty-btn-primary` memaksa `display: inline-flex` agar teks tombol rata tengah. |
| [functions.php](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/functions.php#L14) & [style.css](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/theme/rasa-kopi/style.css#L6) | `1.0.15` → `1.0.16` | Cache-busting query string `?ver=1.0.16` untuk CSS/JS tema. |

### Audit Kompatibilitas Hook & Escaping
- **Hook WooCommerce & Revolut JS**: `woocommerce_before_thankyou`, `woocommerce_thankyou_<gateway>`, `woocommerce_thankyou`, dan selector `.woocommerce-thankyou-order-received` (yang dicek `revolut.js`) tetap dipertahankan. Hook `woocommerce_order_details_table` dilepas sementara di sekitar `do_action('woocommerce_thankyou')` lalu dipasang kembali agar tabel klasik berduplikasi tidak dirender di bawah layout Shopify.
- **Escaping & Fallback**: Seluruh output memakai `esc_html`, `esc_attr`, `esc_url`, atau `wp_kses_post`; fallback tersedia untuk `$order` kosong/invalid, item produk yang dihapus (`wc_placeholder_img_src()`), serta pesanan gagal (`failed` → tombol *Pay* & *My account*).

---

## 3. Hasil Verifikasi Teknis

Dijalankan pada workspace lokal Windows:

- **`verify_regression.py`** (`exit code 0`):
  - [x] Single `RASA_VERSION === style.css Version === '1.0.16'`: `OK`
  - [x] Light Block Checkout & Shopify Thank You (`index.php` memakai `the_content()`, bebas `form-checkout.php` & `review-order.php`, `thankyou.php` terverifikasi): `OK`
  - [x] Strict CSS parser pada seluruh stylesheet di `assets/css/`, `inc/tokens.css`, dan `inc/fonts.css` (nol unclosed block / ilegal nesting): `OK`
  - [x] Keseimbangan kurung/tag PHP pada seluruh 13 file PHP tema, validasi YAML [.github/workflows/deploy.yml](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/.github/workflows/deploy.yml), dan keseimbangan [conf/nginx/shop-woo.conf](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/conf/nginx/shop-woo.conf): `OK`
- **Validasi File JS (`theme/rasa-kopi/assets/js/*.js`)**:
  - [x] 39 file JS bersih tanpa conflict markers: `OK`
- ⚠️ **Limitasi Environment Lokal**: Binary CLI `php` tidak tersedia di mesin Windows lokal dan kontainer `shop-woo-web` berada di VPS; lint `php -l` dijalankan otomatis oleh `deploy-dev` di GitHub Actions dan verifikasi visual halaman `/checkout/order-received/...` dilakukan di `https://shop-dev.rasakopi.coffee/` setelah push.

---

## 4. Pre-Push Regression Sign-off Checklist

- [x] **Static PHP, CSS, JS & Infra Integrity**: 13 file PHP, semua CSS, 39 file JS, `deploy.yml`, dan `shop-woo.conf` lolos pengecekan statis tanpa error.
- [x] **Impact Radius Isolated**: Perubahan terbatas pada endpoint `order-received` dan selector `.rasa-ty*`.
- [x] **Core Flows Protected**:
  - [x] Storefront (`front-page.php`, `/shop/`, Single Product, `/cart/`, Cart Drawer) tidak terdampak.
  - [x] `/checkout/` tetap menggunakan WooCommerce Block Checkout Light Theme dengan adapter `r_test_` Sandbox aktif di `inc/revolut-sandbox-compat.php`.
  - [x] `/checkout/order-received/` merender layout Shopify Thank You tanpa duplikasi judul atau tabel ganda.
- [x] **Report Generated**: [REGRESSION_REPORT_20261117_SHOPIFY_ORDER_RECEIVED.md](file:///d:/WORKS/RASAKOPI/DIGITAL/COMMERCE/WOO/digitalCommerce/report%20code/REGRESSION_REPORT_20261117_SHOPIFY_ORDER_RECEIVED.md)
- [x] **Versioning Tracked**: `RASA_VERSION` & `style.css` diselaraskan di **`1.0.16`**.

**STATUS: APPROVED FOR GIT PUSH (`dev`) ✅** — Lanjutkan verifikasi visual `/checkout/order-received/` (desktop & mobile) setelah deploy CI/CD selesai.
