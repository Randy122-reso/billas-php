# Changelog

Semua perubahan penting pada Billas dicatat di file ini.

Format mengikuti [Keep a Changelog](https://keepachangelog.com/id-ID/1.1.0/)
dan versi mengikuti [Semantic Versioning](https://semver.org/lang/id/).

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
- Footer admin menampilkan `Billas v4.2`.
- Ditambahkan `package.json` berisi metadata versi dan shortcut script PHP.

### Security

- Form hapus router tetap mewajibkan CSRF; request tanpa token menghasilkan
  HTTP 419 dan tidak menghapus data.
- Guard referensi mencegah penghapusan router yang masih dipakai sehingga
  integritas data pelanggan, voucher, dan sesi captive terjaga.

### Verification

- PHPUnit: **84 test, 340 assertion** — seluruhnya lulus.
- PHP lint: **134 file** tanpa error.
- Smoke test HTTP: halaman publik, portal pelanggan, seluruh halaman admin,
  settings save dengan nama field bertitik asli, tambah/hapus router, dan
  penolakan hapus router yang direferensikan.
- Smoke test CLI: `migrate --seed`, `status`, `cron:run --force`,
  `worker --once`, dan `worker --health`.
- Migrasi `006_fix_settings` diuji pada database kosong dan database lama;
  nilai settings yang sudah ada tidak tertimpa.

## [4.0.0]

Baseline fitur yang sudah tersedia sebelum rilis 4.2.0:

- Hotspot voucher dengan masa aktif uptime dan kalender.
- Billing PPPoE bulanan, isolir otomatis, dan reaktivasi setelah pembayaran.
- QRIS unik per pelanggan PPPoE dengan `service_guaranteed_until`.
- Captive portal WiFi RTRWNET: QRIS → password unik.
- Webhook multi-gateway (Tripay, Midtrans, Xendit, Duitku, QRIS statis) yang
  idempotent.
- Admin back-office, portal pelanggan, audit trail, retry queue, backup SQLite,
  health check `/health` dan `/ready`.
- Cron HTTP `POST /internal/cron/run` yang dipicu RouterOS Scheduler.
