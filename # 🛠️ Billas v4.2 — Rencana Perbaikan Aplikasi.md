# 🛠️ Billas v4.2 — Rencana Perbaikan Aplikasi

> **Tagline:** *Satu billing, semua akses.*
> **Sub-tagline (README):** *Hotspot voucher (uptime + kalender), PPPoE subscription billing dengan akun pembayaran persisten + garansi layanan, WiFi publik SSID RTRWNET dengan captive portal QRIS, dan Webhook Simulator — satu ingress, satu DB, tiga container. Pure PHP 8.3 + Slim 4 + PDO SQLite.*
> **Naming resmi:**
> - Repo: `billas-php`
> - Image app: `ghcr.io/<org>/billas-php-app:<tag>`
> - Image worker: `ghcr.io/<org>/billas-php-worker:<tag>`
> - Container: `billas-app`, `billas-worker`, `billas-cloudflared`
> - Path data: `/data/billas.db`
> - Volume: `billas-data` + `billas-logs`

Dokumen ini adalah hasil sintesis analisis kondisi **Billas v4.2** saat ini menjadi satu rencana perbaikan terpadu. Fokus utamanya adalah menyelesaikan *silent failure* pada sinkronisasi UI Admin vs Database vs Env, menyelaraskan logika timezone untuk cron, menambal fitur UI yang hilang (Hapus Router), serta memastikan alur webhook simulator berjalan sesuai koridor yang dijanjikan di *blueprint*. Keberhasilan diukur melalui target 100% UI-DB parity, zero 401/500 pada cron, dan tercapainya *Zero Discrepancy* antara konfigurasi `.env` dan tabel `settings`.

> **Asumsi:** Struktur direktori yang dirujuk mengikuti arsitektur DDD/Slim 4 pada *blueprint* (`src/Controller`, `src/Domain`, `config/`, `views/`). Metrik baseline diambil dari analisis kode dan bug report yang ada.

---

## 1. Analisis Kondisi Saat Ini & Gap

### 1.1 As-Is vs To-Be
| Aspek | As-Is | To-Be | Gap | Dampak bila tidak diperbaiki |
|---|---|---|---|---|
| **Konfigurasi** | `.env` diutamakan (Env-first) | Tabel `settings` diutamakan (DB-first) | Admin UI tidak berfungsi | Fitur macet, *silent failure* saat ubah toggle |
| **Timezone** | Container default UTC | Paksa `Asia/Jakarta` di bootstrap | Logika waktu meleset 7 jam | Isolir & Expire voucher telat |
| **Router Mgmt** | Hanya fungsi *Create/Read/Update* | *Full CRUD* (termasuk Delete) | Data router salah menumpuk | Salah IP/User mengganggu routing |
| **Cron Trigger** | CLI `php /app/bin/console cron:run` | HTTP `POST /internal/cron/run` | *Endpoint* RouterOS gagal 404/401 | Backup, sinkronisasi, revoke mati |
| **Simulator** | Middleware memblokir akses | Flag `features.webhook_simulator` dominan | Admin tidak bisa tes webhook | Waktu uji bayar terbuang |

### 1.2 Root Cause & Prioritas
| Gap | Akar masalah | Dampak | Effort (S/M/L) | Prioritas |
|---|---|---|---|---|
| Toggle UI Gagal | `config/settings.php` urutan *Env-first* | Fitur mati senyap | S | **P0** |
| Cron 401/404 | Server tidak punya command CLI, router diarahkan salah | Bisnis logic mati total | M | **P0** |
| Timezone Melenceng | PHP `date_default_timezone_set` absen di bootstrap | Tagihan/Expired ngawur | S | **P0** |
| Hapus Router Hilang | `views/admin/routers.php` & Controller belum ada `destroy()` | DB penuh sampah | S | **P1** |
| UI Simulator Error | Middleware hanya baca Env, bukan DB | Developer kesulitan testing | M | **P1** |

### 1.3 Strategi Perbaikan
1. **Expand-Contract untuk Settings:** Ganti cara baca setting dari `getenv()` menjadi `SettingsRepository::get()`. Pertahankan fallback `.env` hanya saat *seeding* DB pertama kali.
2. **Strangler Fig untuk Cron:** Pertahankan endpoint HTTP `/internal/cron/run` sebagai *source of truth*, ganti pemicu CLI lama secara bertahap.
3. **Feature-Flagged Delivery:** Rilis fitur hapus router dan simulasi di balik flag atau *role* yang jelas.
4. **Timezone Bootstrapping:** Selipkan `Time::boot()` di `public/index.php` dan `bin/worker.php` untuk menstandarkan WIB secara absolut sebelum *request* diproses.

### 1.4 Matriks Prioritisasi (Impact × Effort)
| Kuadran | Improvement |
|---|---|
| **Quick Win** (High Impact, Low Effort) | Force Timezone WIB; Tambah tombol Hapus Router; Ganti parameter `env` ke `settings` |
| **Strategic** (High Impact, High Effort) | Refactor `config/settings.php` secara menyeluruh; Ganti trigger Cron dari CLI ke HTTP |
| **Fill-in** (Low Impact, Low Effort) | Tampilkan status `job_state` di UI Settings; Rename tombol "Buat QRIS Unik" |
| **Avoid** (Low Impact, High Effort) | Menulis ulang pipeline Webhook dari nol (padahal sudah bagus) |

---

## 2. Identitas Aplikasi & Baseline

| Item | Saat ini | Target |
|---|---|---|
| Repo | `billas-php` | `billas-php` |
| Image | `ghcr.io/org/billas-php-app:latest` | Tetap |
| Container | `billas-app`, `billas-worker` | Tetap |
| Path Data | `/data/billas.db` | Tetap |
| Volume | `billas-data`, `billas-logs` | Tetap |
| Healthcheck | `GET /health` | Tetap + `GET /ready` (gateway status) |
| SLO | 99.5% Uptime | 99.9% Uptime |
| p95 Latency | ~200ms (portal) | < 150ms |
| Error Rate | > 15% (karena misconfig webhook) | < 1% |
| RTO | Belum terdefinisi | 15 Menit |
| RPO | Backup harian (24 jam) | Backup harian (24 jam) |

---

## 3. Ruang Lingkup Perbaikan

**In scope**
- Perbaikan `config/settings.php` (DB-first).
- Penambahan route, controller, dan view untuk **Hapus Router**.
- Injeksi *Timezone WIB* di `public/index.php` & `bin/worker.php`.
- Perbaikan middleware `SimulatorFlagMiddleware` untuk membaca dari DB.
- Migrasi command `cron:run` dari CLI ke HTTP endpoint.
- Penambahan *Unit Test* dan *Integration Test* terkait perubahan.

**Out of scope / prasyarat eksternal**
- Setup MikroTik fisik (VLAN, OLT, PPP Profile).
- Pembuatan akun/token di Dashboard Xendit/Midtrans Production.
- Sinkronisasi NTP di level host/OS luar container.

**Infra wajib menyediakan**
- Volume `/data` persisten dengan permission `www-data:www-data` (UID 82).
- RouterOS Scheduler untuk hit `POST /internal/cron/run` dengan token yang valid.
- `TZ=Asia/Jakarta` di level *host* agar *log rotation* akurat.

**Constraints**
- **Dilarang Menghapus Data:** Tidak boleh ada query `DROP TABLE` atau `DELETE` tanpa `WHERE` yang mematikan migrasi.
- **Backward Compat:** Image versi lama (v4.0) harus tetap bisa membaca DB setelah perbaikan (additive schema).
- **Zero Downtime:** Proses deploy ulang menggunakan strategi *rolling restart*.

---

## 4. Prinsip Perbaikan

| Prinsip | Implementasi |
|---|---|
| **No Big Bang** | Perubahan dibagi menjadi 4 fase; setiap fase bisa di-*rollback* secara independen. |
| **Expand-Contract** | Untuk memperbaiki *settings*, baca dari DB dulu, jika kosong baca dari `.env` (shim). |
| **Database is the Source of Truth** | Admin UI menulis ke DB. Aplikasi membaca DB. `.env` hanya untuk *bootstrapping* kredensial. |
| **Backward Compatible by Default** | Rute lama tetap jalan, parameter baru (jika ada) diletakkan paling akhir dan opsional. |
| **Rollback < 5 Menit** | Gunakan *feature flag* (`features.*`) untuk mematikan fitur yang error tanpa *deploy* ulang. |
| **Observability First** | Setiap error di service harus di-*log* via Monolog dengan level `ERROR`. |
| **Fail-Fast Config** | `Env::validate()` akan *throw Exception* jika `CRON_HMAC_ENABLED=true` (karena RouterOS tidak support HMAC). |
| **Test Pyramid** | Unit test untuk Domain Service (WIB Timezone, Settings), Integration test untuk Controller. |
| **Strangler Fig** | Ganti pemanggilan `getenv('XENDIT_SECRET_KEY')` menjadi `$settings->get('payment.xendit_secret_key')`. |

---

## 5. Arsitektur Saat Ini & Target

**AS-IS (Kondisi Bermasalah):**
```text
[Admin UI] --> (Ubah Toggle) --> [Controller] --> [Baca .env]
                                                       |
                                               (Abaikan DB Settings!)
                                                       |
                                               [Fitur Tetap Mati]
```

**TO-BE (Target Perbaikan):**
```text
[Admin UI] --> (Ubah Toggle) --> [Controller] --> [UPDATE tabel settings (SQLite)]
                                                       |
                                               [App Request Berikutnya]
                                                       |
                                               [Baca DB: payment.default_gateway]
                                                       |
                                               [Fitur Aktif]
```

**Alur Migrasi:**
1. Terapkan WIB Timezone di *bootstrap* aplikasi.
2. Tambahkan *fallback logic* di `SettingsRepository` (DB dulu, baru Env).
3. Buat Controller & View untuk Delete Router.
4. Ubah trigger Cron dari CLI ke HTTP (RouterOS Scheduler).
5. Uji E2E via *Webhook Simulator*.

**Risk Register:**
| Risiko | Mitigasi | Trigger rollback |
|---|---|---|
| SQLite *database locked* saat migrasi settings | Gunakan `PRAGMA busy_timeout=5000` | Error > 1% di log |
| Perubahan timezone membuat log *overwrite* | Rotasi log berbasis *date('Y-m-d')* dengan aman | Log anomali di `/app/logs` |
| Cron macet usai ubah endpoint | Pastikan *server* *listen* di IP dan *port* yang benar | `GET /health` menolak koneksi |

**Yang TIDAK diubah oleh perbaikan ini:** Skema tabel utama (kecuali additive `settings`), pipeline webhook yang sudah berjalan, dan konfigurasi MikroTik Client API.

---

## 6. Kontrak Infra

| Kebutuhan | Kontrak untuk infra | Status |
|---|---|---|
| Runtime container | PHP 8.3-FPM, Nginx, OpenRC | Dipertahankan |
| Bind address | `0.0.0.0:8080` | Dipertahankan |
| Network | Bridge `billas-net` (app, worker, cloudflared) | Dipertahankan |
| Volume | `billas-data` (`/data`), `billas-logs` (`/app/logs`) | Dipertahankan |
| Outbound API | TCP 443 ke Gateway (Xendit, Tripay, dll) | Dipertahankan |
| Outbound MikroTik | TCP 8728/8729 ke IP RouterOS | Dipertahankan |
| DNS | Resolve `*.xendit.co`, `*.midtrans.com` | Dipertahankan |
| NTP | Host tersinkron (pool.ntp.org) | Dipertahankan |
| Backup | *Snapshot* `/data/billas.db` harian | Dipertahankan |
| CPU Arch | `linux/amd64` | Dipertahankan |
| Cron trigger | RouterOS Scheduler hit `POST /internal/cron/run` tiap 2 menit | Diperbaiki (dari CLI) |

---

## 7. Stack & Dependensi — Rencana Migrasi

| Komponen | Saat ini | Target | Alasan | Breaking? | Catatan migrasi |
|---|---|---|---|---|---|
| PHP | 8.3-FPM | 8.3-FPM | Sesuai standar | Tidak | Tidak ada perubahan |
| Slim | 4.x | 4.x | Stabil | Tidak | Tidak ada perubahan |
| PDO SQLite | 3.x | 3.x | Standar | Tidak | Tidak ada perubahan |
| Monolog | 3.x | 3.x | Stabil | Tidak | Tidak ada perubahan |
| Guzzle | 7.x | 7.x | Stabil | Tidak | Tidak ada perubahan |
| Settings Config | Env-First | DB-First | Sinkronisasi UI | Ya (logika) | Ubah `config/settings.php` |
| Timezone | UTC Default | Asia/Jakarta | Akurasi bisnis | Tidak | Inject di `public/index.php` |
| Cron Runner | CLI `cron:run` | HTTP `POST /internal/cron/run` | Standar RouterOS | Ya (endpoint) | RouterOS Scheduler wajib diubah |
| Router CRUD | Tanpa Delete | Full CRUD | Kebersihan data | Tidak | Tambah route & view |

---

## 8. Struktur Repo Target

```text
billas-php/
├── config/
│   ├── settings.php         # MIGRATED: DB-first, Env-fallback logic
│   └── env.php              # DEPRECATED: Hanya validasi kredensial boot
├── public/
│   └── index.php            # MIGRATED: Tambah date_default_timezone_set()
├── bin/
│   └── worker.php           # MIGRATED: Tambah date_default_timezone_set()
├── src/
│   ├── Controller/
│   │   ├── AdminPortalController.php  # MIGRATED: Tambah destroyRouter()
│   │   └── WebhookSimulatorController.php # MIGRATED: Baca flag dari DB
│   ├── Domain/
│   │   ├── SettingsRepository.php     # NEW: Ambil data dari DB
│   │   └── CustomerService.php        # MIGRATED: Cek service_guaranteed_until
│   ├── Middleware/
│   │   └── SimulatorFlagMiddleware.php # MIGRATED: Baca DB, bukan env
│   └── Routes.php           # MIGRATED: Tambah route delete router
├── views/
│   └── admin/
│       ├── routers.php      # MIGRATED: Tambah tombol & form Hapus
│       └── settings.php     # MIGRATED: Tampilkan status job_state
└── db/
    └── migrations/
        └── 006_fix_settings.php # NEW: Backfill dan set default key
```

Estimasi: ± 15 file source berubah, ± 3 file baru, ± 1 file dihapus.

---

## 9. Konfigurasi — Migrasi Env Vars

```ini
# ── Core ──────────────────────────────────────────
APP_ENV=production                  # KEEP
APP_DEBUG=false                     # KEEP
APP_URL=https://billing.example.id  # KEEP
TZ=Asia/Jakarta                     # NEW: Wajib untuk logika container

# ── Fitur (MIGRATED ke Database) ──────────────────
FEATURE_HOTSPOT_CALENDAR_EXPIRY=true # DEPRECATED: Gunakan features.hotspot_calendar_expiry di DB
FEATURE_PERSISTENT_ACCOUNT=false     # DEPRECATED: Gunakan features.persistent_account di DB
FEATURE_PPOE_QRIS_UNIQUE=true        # DEPRECATED: Gunakan features.pppoe_qris_unique di DB
FEATURE_WEBHOOK_SIMULATOR=false      # DEPRECATED: Gunakan features.webhook_simulator di DB

# ── Cron ──────────────────────────────────────────
CRON_HMAC_ENABLED=false             # KEEP (Wajib false untuk RouterOS)
CRON_TOKEN=rahasia123               # KEEP
```
**Aturan:**
- **Fail-fast:** Aplikasi berhenti jika `CRON_HMAC_ENABLED=true`.
- **Backward Compat:** Jika DB `settings` kosong, aplikasi baca fallback ke `.env`.
- **Deprecation:** Memberikan *warning* di log jika `.env` menyala tapi di DB bernilai mati.

---

## 10. Skema Data — Migrasi & Integritas

```sql
-- CURRENT (Asumsi, kemungkinan hanya key-value biasa)
CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);

-- TARGET (Menambah timestamp untuk audit)
ALTER TABLE settings ADD COLUMN updated_at INTEGER DEFAULT (strftime('%s','now'));

-- MIGRATION SCRIPT (006_fix_settings.php)
BEGIN TRANSACTION;
-- Backfill defaults untuk fitur D
INSERT OR IGNORE INTO settings (key, value) VALUES 
  ('features.pppoe_qris_unique', '1'),
  ('features.wifi_captive_portal', '1'),
  ('payment.default_gateway', 'xendit'),
  ('payment.fallback_chain', 'xendit,midtrans,duitku,qris_static');

-- Backfill defaults untuk fitur F
INSERT OR IGNORE INTO settings (key, value) VALUES 
  ('features.webhook_simulator', '0');
COMMIT;

-- ROLLBACK SCRIPT
-- Hapus key yang baru ditambahkan (berdasarkan timestamp migrasi)
DELETE FROM settings WHERE updated_at > [TIMESTAMP_BEFORE_MIGRATION];
```

**Kunci Integritas:**
- `PRIMARY KEY (key)` pada tabel `settings` mencegah duplikasi.
- `UNIQUE (customer_id, gateway, account_type)` pada `customer_payment_accounts`.

---

## 11. Service Layer — Refactor Boundaries

### 11.1 Interface Utama (Settings)
```php
interface SettingsRepositoryInterface {
    public function get(string $key, ?string $default = null): ?string;
    public function set(string $key, string $value): void;
    public function allFeatures(): array;
}
```
### 11.3 Tabel Domain Service
| File | Tanggung jawab saat ini | Target refactor |
|---|---|---|
| `AdminPortalController` | Menampilkan View admin | Tambah fungsi `deleteRouter()` |
| `SettingsRepository` | (Baru) | Implementasi DB-First read/write |
| `SimulatorFlagMiddleware` | Cek `getenv('FEATURE_WEBHOOK_SIMULATOR')` | Cek `$settings->get('features.webhook_simulator')` |
| `WebhookSimulatorService` | Membuat payload simulasi | Perbaiki mode `bypass` (tambahkan header) |
| `CustomerService` | Isolir berdasarkan `paid_until` | Tambahkan pengecekan `service_guaranteed_until` |

### 11.4 Pipeline Inti (Simulator)
`Request → AuthMiddleware → CsrfMiddleware → SimulatorFlagMiddleware (Cek DB) → Controller → Service → Response`

### 11.5 Retry & Scheduler (Cron)
| Task | Jadwal/Interval | Aksi |
|---|---|---|
| `vouchers` | 300 detik | Expire voucher & push ke MikroTik |
| `captive_sessions` | 900 detik | Expire session captive portal |
| `isolir` | 86400 detik | Cek `service_guaranteed_until` & `paid_until` |

### 11.6 Deprecation Table
| Interface/Function | Deprecated at | Replacement | Removal at |
|---|---|---|---|
| CLI `bin/console cron:run` | Fase 1 | `POST /internal/cron/run` | Fase 4 |
| Env `FEATURE_*` | Fase 2 | Tabel `settings` (`features.*`) | Fase 4 |

---

## 12. Routes & API — Versioning & Deprecation

| Route/Entry | Status | Breaking? | Fungsi |
|---|---|---|---|
| `GET /admin/routers` | Keep | No | List router |
| `POST /admin/routers` | Keep | No | Tambah router |
| `POST /admin/routers/delete/{id}` | **Modify** | Yes (Baru) | Hapus router |
| `GET /admin/settings` | Keep | No | Lihat setting |
| `POST /admin/settings` | Modify | No | Simpan setting (ke DB) |
| `POST /internal/cron/run` | Modify | No | Trigger cron via HTTP |
| `POST /admin/simulator/webhook` | Keep | No | Kirim simulasi webhook |
| `POST /api/webhook/{provider}` | Keep | No | Endpoint webhook asli |

- **Rate Limit:** `/admin/simulator/webhook` dibatasi 20 req/menit/IP.
- **Deprecation Timeline:** Menghapus fungsi `cron:run` pada iterasi v4.3 (Fase 4).

---

## 13. Alur Inti — Before/After

### 13.1 Alur Simpan Pengaturan
**As-Is:** Admin klik "Simpan" → Controller update `.env` → Restart Container → Aplikasi baca `.env` → (GAGAL karena DB menimpa).
**To-Be:** Admin klik "Simpan" → Controller `UPDATE settings SET value = ?` → Aplikasi `SELECT` dari DB → (BERHASIL).
**Error Path:** Rollback transaksi DB jika query gagal, tampilkan *Toast Notification*.
**Rollback Path:** Gunakan fitur "Reset to Default" yang menghapus baris setting, memaksa aplikasi baca `.env`.

### 13.2 Alur Hapus Router
**As-Is:** Tidak ada tombol, database kotor.
**To-Be:** Admin klik "Hapus" → Konfirmasi → `POST /admin/routers/delete/1` → Cek `SELECT count(*) FROM customers WHERE router_id = 1` → Jika 0, `DELETE FROM routers WHERE id = 1` → Redirect ke index.
**Error Path:** Tolak hapus jika masih ada `customer` yang terikat.
**Rollback Path:** Soft-delete (`active=0`) bisa diaktifkan jika `DELETE` gagal secara *constraint*.

---

## 14. UI / Views — Perbaikan

| View/Asset | Sumber | Perbaikan | Alasan |
|---|---|---|---|
| `admin/routers.php` | Controller | Tambah tombol `<form>` Hapus | Fitur Delete hilang |
| `admin/settings.php` | Controller | Tampilkan indikator "Tersimpan!" saat update | Feedback UX |
| `admin/settings.php` | Controller | Tampilkan nilai `job_state` | Monitoring cron |
| `admin/customers.php` | Controller | Pastikan data QRIS terpanggil | UI tidak sinkron |
| `partials/admin-open.php` | Static | Cek `$settings->get('features.webhook_simulator')` | Menu simulasi harus dinamis |
| `admin/simulator.php` | Controller | Pastikan `CSRF` token ada di form | Keamanan |
| `admin/vouchers.php` | Controller | Tombol "Simulasi" pre-fill target_id | Mempermudah testing |
| `admin/billing.php` | Controller | Tombol "Simulasi" pre-fill target_id | Mempermudah testing |
| `wifi/portal.php` | Controller | Tampilkan error jika `session_token` expired | UX |
| `wifi/voucher.php` | Controller | Peringatan "Simpan password sekali" | UX |

---

## 15. Security & Reliability — Hardening

| Area | Tindakan | Prioritas | Verifikasi |
|---|---|---|---|
| Webhook Replay | Cek idempotensi di `webhook_events` | P0 | Kirim payload duplikat |
| SQLi | Gunakan PDO *prepared statements* | P0 | Code review |
| CSRF | Validasi token di form Hapus Router | P0 | Penetrasi manual |
| Brute Force | Rate limit `/admin/login` | P1 | Uji 100 request/menit |
| Cron Auth | `X-Cron-Token` harus `hash_equals` | P0 | Uji token salah |
| Path Traversal | Batasi *View* whitelist | P1 | Uji `../../etc/passwd` |
| Container | Non-root (UID 1000) | P1 | `docker exec whoami` |
| Time Drift | Pasang NTP di host | P0 | `date` container = WIB |
| Data Corrupt | Backup DB harian | P0 | Restore backup |
| Dependency CVE | `composer audit` | P1 | Jalankan di CI |
| Simulator Bypass | Wajib Header `X-Billas-Simulated: 1` | P0 | Uji bypass tanpa header |
| Secret Handling | Masking di `simulator_runs` | P0 | Cek DB setelah run |

**Threat Model:** Penyerang mencoba mengirim webhook palsu lewat `/api/webhook/xendit`. Mitigasi: `hash_equals` untuk signature.
**SLO:** Uptime 99.9%, p95 latency < 150ms, Error Budget 0.1%.

---

## 16. Build, Deploy, CI/CD — Perbaikan Pipeline

### 16.1 Dockerfile (Ringkas)
```dockerfile
FROM php:8.3-fpm-alpine AS builder
# Install dependensi & composer
RUN apk add --no-cache $PHPIZE_DEPS sqlite-dev \
    && docker-php-ext-install pdo_sqlite
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

FROM php:8.3-fpm-alpine
# Copy app dari builder
COPY --from=builder /app /app
RUN adduser -u 1000 -D appuser && chown -R appuser:appuser /app
USER appuser
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
```

### 16.2 docker-compose.yml (Ringkas)
```yaml
services:
  billas-app:
    build: .
    env_file: .env
    volumes:
      - billas-data:/data
      - billas-logs:/app/logs
    depends_on:
      - billas-worker
  billas-worker:
    build: .
    command: ["php", "/app/bin/worker.php"]
    env_file: .env
    volumes:
      - billas-data:/data
      - billas-logs:/app/logs
```

### 16.3 CI/CD Pipeline
- **Trigger:** Push ke `main`.
- **Aksi:** `composer install`, `phpunit`, `phpstan`, `docker build`.
- **Verifikasi:** *Smoke test* `GET /health` setelah deploy.
- **Canary:** Rilis ke 20% traffic via Cloudflare Load Balancer (opsional) atau deploy manual dulu ke *staging*.
- **Rollback Trigger:** Jika error rate > 1% dalam 5 menit.

### 16.4 Rollback Procedure
1. Hentikan container `billas-app` & `billas-worker`.
2. Tarik image tag sebelumnya (`:v4.2.0`).
3. Jalankan container dengan image lama.
4. Verifikasi `GET /health` dan cek log 401/500.
**SLA:** < 5 Menit.

---

## 17. Testing Checklist — Regression + New

### Unit/Service
- [ ] `SettingsRepository::get()` mengembalikan nilai DB jika ada.
- [ ] `SettingsRepository::get()` mengembalikan *default* env jika DB kosong.
- [ ] `AdminPortalController::deleteRouter()` gagal jika router memiliki customer.
- [ ] `AdminPortalController::deleteRouter()` sukses jika tidak ada customer.
- [ ] `Time::boot()` menghasilkan `date_default_timezone_get()` = `Asia/Jakarta`.
- [ ] `SimulatorFlagMiddleware` mengizinkan akses jika DB mengembalikan `1`.

### Integration
- [ ] `POST /admin/settings` mengubah nilai di DB, bukan `.env`.
- [ ] `POST /admin/routers/delete/{id}` menghapus baris dari DB.
- [ ] `POST /internal/cron/run` memproses task `vouchers` dengan token valid.
- [ ] `POST /internal/cron/run` mengembalikan 401 jika token salah.
- [ ] `POST /admin/simulator/webhook` (mode bypass) menambahkan header `X-Billas-Simulated`.

### Smoke/Staging
- [ ] Login ke Admin Console.
- [ ] Mengubah Toggle QRIS Unik di UI, dan melihat DB berubah.
- [ ] Menghapus router yang salah tambah via UI.
- [ ] Menjalankan cron manual lewat RouterOS Scheduler.

### Security
- [ ] Menguji CSRF pada form Delete Router.
- [ ] Menguji SQL Injection pada input ID Router.
- [ ] Cek `simulator_runs` tidak menyimpan signature mentah.

### Migration
- [ ] Jalankan `006_fix_settings.php` di DB kosong.
- [ ] Jalankan di DB yang sudah berisi data.
- [ ] Rollback migrasi, data lama tetap aman.

### Rollback
- [ ] Deploy image `:v4.2.0` ke staging, pastikan aplikasi berjalan.
- [ ] Kembalikan `.env` ke versi sebelumnya.

---

## 18. Timeline — Fase Perbaikan

| Hari/Milestone | Aktivitas | Deliverable | Rollback point | Metrik verifikasi |
|---|---|---|---|---|
| H+1 | **Fase 1:** Force Timezone WIB | Update `public/index.php` | Git revert | `date_default_timezone_get()` = Asia/Jakarta |
| H+2 | **Fase 2:** Fix Settings Repo | Update `config/settings.php` | Flag `features.*` | Toggle UI berfungsi |
| H+3 | **Fase 3:** Fitur Hapus Router | Tambah route & view | Nonaktifkan route | Router salah hilang dari UI |
| H+4 | **Fase 4:** Fix Cron & Simulator | Update Middleware & Scheduler | Rollback RouterOS ke CLI | Cron `job_state` bergerak |
| H+5 | **Fase 5:** Integration Test & Hardening | Test Suite | Revert commit | Error rate < 1% |
| H+6 | **Fase 6:** Canary & Cleanup | Hapus fungsi `cron:run` lama | — | Semua log bersih |

---

## 19. Definition of Done (Billas v4.2)

- [ ] Semua kode lolos `phpstan level 6` dan `phpunit`.
- [ ] `config/settings.php` menggunakan `DB-first` logic.
- [ ] Timezone `Asia/Jakarta` di-set secara eksplisit di `index.php` & `worker.php`.
- [ ] Fitur "Hapus Router" berfungsi dan aman dari *orphan data*.
- [ ] UI Settings mencerminkan nilai dari DB dan tersimpan instan.
- [ ] Endpoint `/internal/cron/run` berjalan dan diaudit di RouterOS.
- [ ] Webhook Simulator bisa diakses jika `features.webhook_simulator` di DB = 1.
- [ ] Tidak ada error `SQLITE_BUSY` di log selama 24 jam.
- [ ] Migrasi DB berjalan *idempotent*.
- [ ] Rollback script diuji dan berjalan < 5 menit.
- [ ] Dokumentasi `README.md` diperbarui dengan instruksi Cron HTTP.
- [ ] Security scan (CVE) bersih.
- [ ] Simulator menutupi signature di log `simulator_runs`.
- [ ] Rate limit 20 req/menit/IP diaktifkan di simulator.
- [ ] `service_guaranteed_until` dipertimbangkan dalam cron isolir.
- [ ] Semua test (unit, integration, smoke) lulus.
- [ ] Tidak ada data yang hilang pasca migrasi.
- [ ] Semua file debug (`cron-*.json`) dibersihkan.
- [ ] Kredensial Xendit production terpasang dengan benar via DB.
- [ ] Backward compatibility dengan image v4.0 terverifikasi.
- [ ] Log sistem tidak mengandung warning "It is not safe to rely on system's timezone".
- [ ] Environment `.env` siap untuk *production*.

---

## 20. Roadmap Lanjutan + Ringkasan

| Fase | Deliverable |
|---|---|
| **F0** | Dokumen Rencana Perbaikan (dokumen ini) |
| **F1** | Perbaikan Timezone & Settings Repo (P0) |
| **F2** | Fitur Hapus Router & UI Polish (P1) |
| **F3** | Migrasi Cron CLI ke HTTP Endpoint (P0) |
| **F4** | Hardening Simulator & Security Patch (P1) |
| **F5** | Penghapusan fungsi *deprecated* & optimasi query (P2) |

**Cara menambah improvement berikutnya (plug-and-play):**
1. Analisis gap: temukan bug baru dan cocokkan dengan *blueprint*.
2. Prioritisasi: masukkan ke matriks Impact vs Effort.
3. Desain rollback: pastikan ada *feature flag* atau rute alternatif.
4. Implementasi: lakukan perubahan di *branch* terpisah.
5. Verifikasi: jalankan *test suite* dan pantau *health check*.

**Ringkasan:** Rencana perbaikan Billas v4.2 ini berfokus pada penyelesaian *silent failure* dengan menggeser *source of truth* konfigurasi dari `.env` ke tabel `settings` di SQLite, memastikan konsistensi timezone WIB di seluruh *bootstrap* aplikasi, dan menambal fitur UI yang hilang (Hapus Router). Melalui pendekatan *expand-contract* dan *feature flags*, setiap perubahan dirancang incremental dengan jalur *rollback* cepat. Migrasi *cron* dari CLI ke HTTP *endpoint* menjamin kompatibilitas dengan RouterOS Scheduler. Target akhirnya adalah Admin Console yang 100% sinkron dengan *database*, *zero silent failure*, dan *error rate* di bawah 1%.