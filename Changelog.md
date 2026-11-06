# Changelog

Semua perubahan penting pada Billas dicatat di file ini.

Format mengikuti [Keep a Changelog](https://keepachangelog.com/id-ID/1.1.0/)
dan versi mengikuti [Semantic Versioning](https://semver.org/lang/id/).

## [4.3.0] — 2026-11-07

Rilis ini mengimplementasikan integrasi terpadu v4.3 yang menambahkan **Fitur G: Gateway API Tester** (The Injector), melengkapi Fitur F (Webhook Simulator) sehingga Billas memiliki konsol debug dua arah: inbound (simulasi webhook) dan outbound (injeksi request ke gateway resmi).

### Added

- **Fitur G — Gateway API Tester (Admin-only, default OFF)**:
  - Antarmuka web `GET /admin/api-tester` dengan form pengujian outbound, hint regex whitelist per provider, response viewer interaktif (HTTP status badge, duration ms, masked headers, formatted JSON body), dan tabel riwayat 20 pengujian terakhir.
  - Endpoint dispatch `POST /admin/api-tester/send` yang dilindungi lapis keamanan penuh: `AuthMiddleware`, `CsrfMiddleware`, `RateLimitMiddleware` (20 req/menit/IP), dan `ApiTesterFlagMiddleware`.
  - Endpoint JSON `GET /admin/api-tester/runs` untuk memuat riwayat pengujian.
  - **Keamanan SSRF-Safe by Construction**: base URL fixed per provider sesuai mode (sandbox vs production), path relatif wajib cocok dengan whitelist regex provider, penolakan URL absolut (`http://`, `https://`, `//`), larangan redirect (`allow_redirects=false`), timeout cap (10s), dan body size cap (64 KB).
  - **Secret Masking Otomatis**: header sensitif (`Authorization`, `Xendit-Api-Key`, `X-Callback-Signature`, dll.) dan string secret gateway dari `getApiTesterSecrets()` otomatis di-mask menjadi `***MASKED***` di UI dan tabel `api_tester_runs`.
  - **Audit Trail**: setiap pengiriman request outbound diaudit ke `audit_trail` dengan aksi `api_tester.dispatch`.
  - **CLI Support**: subcommand `bin/console api:tester <provider> <method> <endpoint> [--body=...]` untuk kemudahan pengujian dari terminal atau CI/CD.
  - **Provider Implementation**: implementasi 4 method API tester (`getApiTesterBaseUrl`, `getApiTesterAllowedPaths`, `getApiTesterHeaders`, `getApiTesterSecrets`) pada `BaseProvider`, `TripayService`, `MidtransService`, `XenditService`, `DuitkuService`, dan penanganan `LogicException` jelas pada `QrisStaticService`.
  - **Migrasi Idempotent**: file migrasi `007_v4_3_api_tester.php` untuk membuat tabel `api_tester_runs` beserta index provider & created_at, serta seeding default settings `features.api_tester`, `api_tester.mode`, `api_tester.timeout_seconds`, dan `api_tester.max_body_bytes`.
  - **UI Admin Integration**: menu navigasi "API Tester" di sidebar admin (hanya muncul saat flag aktif), dan kartu konfigurasi Gateway API Tester di halaman Admin → Pengaturan.
  - Test suite lengkap Unit (`tests/Unit/DebugApiServiceTest.php`) dan Integration (`tests/Integration/ApiTesterTest.php`).

### Security

- **Production Fail-Fast Guard**: `Env::validate()` langsung menolak booting aplikasi di environment production jika `FEATURE_API_TESTER=true` tanpa persetujuan eksplisit `API_TESTER_ALLOW_PRODUCTION_REQUESTS=true`.
- Whitelist method ketat (hanya `GET,POST,DELETE`).

---

## [4.2.0] — 2026-11-06

Rilis ini menutup celah *silent failure* antara form Admin, tabel `settings`,
dan environment, melengkapi CRUD router, serta menyelaraskan versi aplikasi.

### Added

- **Hapus router end-to-end**: `POST /admin/routers/delete/{id}`, tombol Hapus
  dengan konfirmasi di **Admin → Router**, audit `router.deleted`, dan guard
  yang menolak penghapusan selama router masih direferensikan pelanggan,
  voucher, atau sesi captive.
- **Kontrol Webhook Simulator di Admin → Pengaturan**: toggle
  `features.webhook_simulator`, pilihan mode `sign`/`bypass`, dan izin
  production dapat disimpan ke tabel `settings` tanpa rebuild container.
- Seed default `features.webhook_simulator` agar database baru langsung
  konsisten dengan environment.
- Migrasi additive `006_fix_settings` untuk mem-backfill key settings yang
  belum ada pada database lama tanpa menimpa nilai yang sudah disimpan.
- Test regresi untuk penyimpanan settings dengan nama field bertitik, hapus
  router (sukses, diblokir, CSRF), render halaman router/settings, dan timezone
  `Asia/Jakarta`.

### Fixed

- **Settings admin tidak tersimpan**: PHP mengubah titik pada nama field form
  menjadi garis bawah (`features.wifi_captive_portal` →
  `features_wifi_captive_portal`). Controller sekarang membaca kedua bentuk,
  sehingga semua toggle, nilai WiFi, payment, QRIS, dan cron benar-benar masuk
  ke database, bukan tersimpan sebagai `0`/kosong.
- Normalisasi nilai checkbox non-fitur (`pppoe_qris.auto_create`,
  `webhook_simulator.allow_in_production`) menjadi `0`/`1`.
- **Cron `--force` tidak memperbarui `job_state.last_run_at`**: forced run
  sekarang tetap mengklaim slot sehingga status terakhir terlihat di Admin →
  Pengaturan dan `job_state` tidak lagi tertinggal di `0`.
- Penghapusan router kini mengembalikan pesan jelas dan tidak pernah
  meninggalkan data yatim.
- Versi default aplikasi diselaraskan ke `4.2.0` pada `Env`, container,
  health check, `.env.example`, dan tag image Docker Compose.

### Changed

- README diperbarui: prioritas DB-first, cara kerja simpan settings, tombol
  hapus router, troubleshooting baru, dan ringkasan test.
