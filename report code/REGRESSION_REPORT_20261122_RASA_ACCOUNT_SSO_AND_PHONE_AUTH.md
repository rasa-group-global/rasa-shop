# 🛡️ Regression Test Report: `digitalCommerce` (WooCommerce) Rasa Account SSO, Phone Login/Register & B2B Role Sync

**Date**: 2026-11-22  
**Author**: Antigravity AI Assistant & Engineering Team  
**Scope**: `digitalCommerce` (`theme/rasa-kopi/inc/rasa-keycloak-sso.php`, `theme/rasa-kopi/functions.php`, `setup/sync-keycloak-users.sh`, `.env.example`)  
**Version**: `v1.0.23`  
**Status**: **APPROVED FOR PRODUCTION / GIT PUSH ✅**

---

## 1. Ringkasan Fitur & Integrasi Single Source of Truth

1. **Tombol `"Sign in with Rasa Account"` (`Mit Rasa Account anmelden`)**:
   - Ditampilkan di halaman `/my-account/` WooCommerce (`woocommerce_login_form_start`).
   - Menjalankan OIDC Authorization Code Flow + PKCE (`?rasa_sso=login` $\rightarrow$ Keycloak `sso-dev.rasagroup.global` $\rightarrow$ `?rasa_sso=callback`).
   - Menukar kode melalui `POST /api/v1/auth/keycloak/exchange` di `RasaApi` (dengan fallback langsung ke endpoint `/token` Keycloak jika API sedang maintenance).
2. **Registrasi & Login dengan Nomor Telepon (`+49`, `+62`, `+43`, `+41`, `+31`) atau Email**:
   - Menambahkan input kode negara + nomor telepon (`billing_phone`) di form Register WooCommerce (`woocommerce_register_form_start` & `woocommerce_registration_errors`).
   - Mengubah label login WooCommerce menjadi **`Telefonnummer, Benutzername oder E-Mail-Adresse` / `Phone number, username or email address`**.
   - Hook `authenticate` (priority `35`) mendukung:
     - Pencarian user lokal WordPress berdasarkan meta `billing_phone` dan `_rasa_phone` (mendukung format E.164 `+49...`, `+62...`, maupun lokal `08...`).
     - Fallback autentikasi ke `POST /api/v1/auth/login` di `RasaApi` menggunakan `email` maupun `phone`, lalu otomatis membuat/menyinkronkan user WooCommerce jika akun berasal dari Mobile App (`digitalApk`).
3. **Sinkronisasi Otomatis Role `b2b_customer` & Loyalty Points**:
   - Jika user memiliki role `b2b_customer` di Rasa Account SSO / `RasaApi`, role WordPress otomatis diset ke `b2b_customer` (tanpa menurunkan role `administrator`), sehingga fitur harga Net (`excl. MwSt.`) dan EU Reverse Charge VAT di `rasa-store-ops.php` langsung aktif.
   - Menyimpan `keycloak_id` & `keyloack_id`, `_rasa_user_uuid`, `_rasa_wallet_balance`, dan `_rasa_loyalty_points` di `wp_usermeta`, serta menampilkan kartu **Rasa Account Connected** di dashboard *My Account*.
4. **WP-CLI Bulk User Sync (`rasa_sso_bulk_sync_all_woo_users`)**:
   - Script `setup/sync-keycloak-users.sh` untuk menyinkronkan seluruh user eksisting di database WooCommerce ke Keycloak Realm `rasa`.

---

## 2. 🛡️ Pre-Push Regression Sign-off Report

- [x] **Build & Syntax**: `theme/rasa-kopi/inc/rasa-keycloak-sso.php`, `theme/rasa-kopi/functions.php`, dan `theme/rasa-kopi/inc/rasa-store-ops.php` lulus `php -l` (`No syntax errors detected`).
- [x] **Impact Radius**: Tidak mengubah flow checkout, kalkulasi pajak `19%`/`7%`, ongkir DHL `4.90 €`, maupun gateway pembayaran Revolut yang sudah ada.
- [x] **Core Flows Verified**:
  - [x] Login via tombol "Sign in with Rasa Account" (OIDC + PKCE)
  - [x] Login via form WooCommerce menggunakan Nomor Telepon (`+49` / `+62` / `08...`), Username, atau Email
  - [x] Register akun baru di WooCommerce dengan Nomor Telepon + auto-sync ke `RasaApi` & Keycloak
  - [x] B2B Customer (`b2b_customer`) role & VAT ID (`_rasa_vat_id`) preservation
  - [x] WP-CLI bulk user sync (`rasa_sso_bulk_sync_all_woo_users()`)

STATUS: **APPROVED FOR GIT PUSH ✅**
