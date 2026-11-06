Integrasi — Billas v4.2 → v4.3

> **Legenda penanda:**
> - **[TIDAK BERUBAH]** — isi sama dengan v4.2; hanya diringkas untuk efisiensi.
> - **[DIPERBARUI v4.3]** — ada penambahan/ perubahan karena Fitur G.
> - **[BARU v4.3]** — bagian yang sepenuhnya baru karena Fitur G.

---

## 1. Analisis Sintesis — v4.3 Final Terpadu

### 1.1 Yang dipertahankan dari v1/v2/v3/v4/v4.2 **[TIDAK BERUBAH]**

Sama persis dengan v4.2 §1.1. Ringkas: runtime PHP 8.3-FPM + Nginx + OpenRC; Slim 4 + PHP-DI + Monolog + Guzzle; PDO SQLite WAL; 3 container; Cloudflare Tunnel; cron RouterOS Scheduler; retry daemon; webhook unified; multi-gateway; Fitur A–F.

### 1.2 Perubahan v4.3 (aditif — Fitur G) **[DIPERBARUI v4.3]**

| # | Perubahan | Dampak skema | Dampak service | Dampak route | Dampak UI |
|---|---|---|---|---|---|
| **G** | Gateway API Tester (admin-only, flag-gated, default OFF) | +1 tabel `api_tester_runs`; +0 kolom lama | `DebugApiService` (BARU), `BaseProvider::getApiTesterBaseUrl/AllowedPaths/Headers/Secrets` (BARU via `method_exists`), 5 provider implement | `GET /admin/api-tester`, `POST /admin/api-tester/send`, `GET /admin/api-tester/runs` | `views/admin/api_tester.php` (BARU), menu "API Tester" di `partials/admin-open.php` (jika flag ON), toggle di `admin/settings.php` |

### 1.3 Strategi unifikasi v4.3 **[DIPERBARUI v4.3]**

1–7. **[TIDAK BERUBAH]** — sama dengan v4.2 §1.3 (tetap 3 container, 1 DB, 1 gateway abstraction, 1 webhook pipeline, 1 ingress, 1 worker, fail-safe by default).
8. **[BARU v4.3]** **Tetap satu gateway abstraction untuk outbound** — Fitur G tidak membuat jalur HTTP baru; ia memakai `GatewayFactory` + `Guzzle` yang sudah ada, hanya menambah method opsional untuk base URL/whitelist/headers.
9. **[BARU v4.3]** **Fail-safe by default (Fitur G)** — `FEATURE_API_TESTER=false` di `.env.example`; `Env::validate()` menolak boot jika production + flag true + `API_TESTER_ALLOW_PRODUCTION_REQUESTS=false`.
10. **[BARU v4.3]** **SSRF-safe by construction** — base URL hanya dari provider; path wajib match whitelist regex; URL absolut ditolak.

---

## 2. Identitas & Naming (resmi) **[DIPERBARUI v4.3]**

| Item | Nilai |
|---|---|
| Repo | `billas-php` |
| Image app / worker | `ghcr.io/<org>/billas-php-app:<tag>` / `...-worker:<tag>` |
| Container | `billas-app`, `billas-worker`, `billas-cloudflared` |
| DB path | `/data/billas.db` |
| Volume | `billas-data`, `billas-logs` |
| Network | `billas-net` |
| Healthcheck | `GET /health` |
| Public hostname | `billing.example.id` |
| Internal cron | `POST /internal/cron/run` |
| WiFi publik | SSID `RTRWNET` → `GET /wifi` |
| Captive API | `POST /api/wifi/pay/create`, `GET /api/wifi/status/{orderId}` |
| Webhook Simulator | `GET /admin/simulator`, `POST /admin/simulator/webhook`, `GET /admin/simulator/runs` |
| **Gateway API Tester** | **`GET /admin/api-tester`, `POST /admin/api-tester/send`, `GET /admin/api-tester/runs`** (admin-only, CSRF, rate-limit 20/menit/IP, flag-gated) |
| PHP / Composer | 8.3.x / 2.x |
| Base image app / worker | `felted67/mikrotik-alp_rc_nginx_php83:latest` / `php:8.3-cli-alpine` |
| Init | OpenRC |

---

## 3. Ruang Lingkup **[DIPERBARUI v4.3]**

### In scope — tambahan v4.3

**Gateway API Tester — Fitur G [BARU v4.3]**
- Halaman admin `GET /admin/api-tester` (form + riwayat 20 run terakhir).
- `POST /admin/api-tester/send` — kirim request ke provider terpilih via `DebugApiService` (Guzzle).
- Pilihan provider: `tripay | midtrans | xendit | duitku | qris_static` (QRIS statis → error jelas karena tidak punya API).
- Pilihan method: `GET | POST | DELETE` (dibatasi `API_TESTER_ALLOWED_METHODS`).
- Input `endpoint` = path relatif; **wajib** match whitelist regex provider; URL absolut ditolak.
- Textarea `body` JSON (validasi + batas ukuran).
- Response viewer: status HTTP, `duration_ms`, headers (sensitif di-mask), body (secret di-mask).
- `GET /admin/api-tester/runs` — riwayat 20 run (request/response body di-mask).
- Flag `FEATURE_API_TESTER` (default `false`), `API_TESTER_MODE` (`sandbox|production`), `API_TESTER_ALLOW_PRODUCTION_REQUESTS` (default `false`).
- Audit setiap dispatch ke `audit_trail` + `api_tester_runs`.
- CLI opsional `bin/console api:tester <provider> <method> <endpoint> [--body=...]` untuk CI/UAT.

### Out of scope — tambahan v4.3 **[BARU v4.3]**

- **Modul 2 Log Viewer**, **Modul 3 Simulator 2.0**, **Modul 4 Health Check** — **tidak** diambil (lihat §B.4).
- **URL absolut / host arbitrer** — ditolak oleh `DebugApiService`.
- **Method `PUT`/`PATCH`** — tidak di whitelist default (bisa diperluas via `API_TESTER_ALLOWED_METHODS` jika suatu saat gateway membutuhkan).
- **Production endpoint dari UI admin** — diblokir oleh `Env::validate()` kecuali `API_TESTER_ALLOW_PRODUCTION_REQUESTS=true`.
- **Penyimpanan response body lengkap tanpa mask** — `api_tester_runs` selalu menyimpan versi ter-mask.

Infra wajib menyediakan: sama dengan v4.2 §3, plus:
- **[BARU v4.3]** Minimal satu provider terkonfigurasi (agar API Tester bisa menembak; jika tidak ada, UI menampilkan error jelas).

---

## 4. Fitur Inti v4.3

### 4.1–4.6 Fitur A–F **[TIDAK BERUBAH]**

Sama persis dengan v4.2 §4.1–§4.6. Tidak ada perubahan perilaku.

### 4.7 Fitur G — Gateway API Tester (The Injector) **[BARU v4.3]**

**Tujuan:** memungkinkan admin menguji endpoint outbound gateway (Xendit `/qr_codes`, Midtrans `/v2/charge`, dll.) langsung dari browser — menggantikan `curl` dari terminal, tanpa mengekspos API key ke frontend.

**Perilaku:**

1. Admin membuka `GET /admin/api-tester` (flag ON, session admin, CSRF token).
2. Form:
   - **Provider** (`tripay|midtrans|xendit|duitku|qris_static`).
   - **Method** (`GET|POST|DELETE`, difilter dari `API_TESTER_ALLOWED_METHODS`).
   - **Endpoint** — path relatif, dengan hint whitelist per provider (di-render dari `getApiTesterAllowedPaths()` → contoh path).
   - **Body** — textarea JSON (opsional untuk GET).
   - Tombol **Send Request**.
3. Submit → `POST /admin/api-tester/send`:
   - `AuthMiddleware` + `CsrfMiddleware` + `RateLimitMiddleware(20/min/IP)` + `ApiTesterFlagMiddleware`.
   - `DebugApiService::send()`:
     - Validasi method (whitelist env + hard-coded `GET|POST|DELETE`).
     - Validasi ukuran body (`API_TESTER_MAX_BODY_BYTES`).
     - Validasi JSON body (jika ada).
     - Validasi endpoint: **tolak** `^https?://`; **wajib** match salah satu `provider->getApiTesterAllowedPaths()`.
     - Bentuk URL: `rtrim(provider->getApiTesterBaseUrl(), '/') . '/' . ltrim($endpoint, '/')`.
     - Ambil headers: `provider->getApiTesterHeaders()` (auth dibangun server-side; **tidak pernah** dikirim ke frontend).
     - Guzzle request dengan `timeout = API_TESTER_TIMEOUT_SECONDS`, `http_errors=false`, `allow_redirects=false`.
     - Ukur `duration_ms`.
     - Mask headers sensitif (`Authorization`, `Xendit-Api-Key`, `X-Callback-Token`, `X-Callback-Signature`, `X-Signature-Key`).
     - Mask body via `provider->getApiTesterSecrets()`.
     - Simpan `api_tester_runs` (request & response sudah di-mask).
     - `AuditService::record('admin', $adminId, 'api_tester.dispatch', 'api_tester_run', null, "$provider:$method", $endpoint)`.
     - Return `{status, duration_ms, headers, body}`.
4. UI menampilkan response viewer + tabel riwayat 20 run terakhir (provider/method/endpoint/status/duration, created_by, created_at). Body lengkap tidak ditampilkan di tabel riwayat — hanya di response viewer setelah send.

**SSRF-safe by construction:**

| Lapis | Aturan |
|---|---|
| 1 | Base URL **hanya** dari `provider->getApiTesterBaseUrl()` (config, bukan input) |
| 2 | Endpoint **wajib** match regex whitelist provider |
| 3 | Endpoint **tidak boleh** match `^https?://` (URL absolut ditolak) |
| 4 | `allow_redirects=false` (cegah redirect ke host lain) |
| 5 | `http_errors=false` (tidak throw; response 4xx/5xx tetap ditampilkan) |
| 6 | Timeout cap `API_TESTER_TIMEOUT_SECONDS` |
| 7 | Body cap `API_TESTER_MAX_BODY_BYTES` |

**Masking:**

- Header sensitif → `***MASKED***`.
- Body di-mask via `getApiTesterSecrets()` (API key, private key, webhook token, server key, client key) → `***MASKED***`.
- `api_tester_runs.request_body` & `response_body` **selalu** di-mask sebelum disimpan.

**Kasus tepi:**

- Provider `qris_static` → `getApiTesterBaseUrl()` throw `LogicException` → UI menampilkan error jelas.
- Provider yang belum implement method → `method_exists()` cek → error jelas.
- Endpoint tidak match whitelist → 400 + pesan "endpoint tidak di whitelist untuk provider X".
- Body invalid JSON → 400.
- Body melebihi cap → 400.
- Method tidak di `API_TESTER_ALLOWED_METHODS` → 400.
- Production guard: `APP_ENV=production` + flag true + `API_TESTER_ALLOW_PRODUCTION_REQUESTS=false` → `Env::validate()` throw saat boot.
- **Tidak ada** perubahan pada `WebhookService` / `PaymentProvider` interface / pipeline v4.2.

---

## 5. Prinsip Desain (v4.3) **[DIPERBARUI v4.3]**

Semua prinsip v4.2 §5 **[TIDAK BERUBAH]**, ditambah:

| Prinsip | Implementasi |
|---|---|
| **API Tester default OFF** **[BARU v4.3]** | `FEATURE_API_TESTER=false` di `.env.example`; tidak pernah `true` default; fail-fast di production |
| **API Tester admin-only + CSRF + rate limit + audit** **[BARU v4.3]** | Route di `/admin/*`, `CsrfMiddleware`, `RateLimitMiddleware(20/min/IP)`, `audit_trail('api_tester.dispatch')` |
| **SSRF-safe by construction** **[BARU v4.3]** | Base URL fixed per provider + path whitelist regex + tolak URL absolut + `allow_redirects=false` |
| **Secret never leaves backend** **[BARU v4.3]** | Auth headers dibangun server-side; tidak pernah dikirim ke frontend; di-mask di response viewer & DB |
| **Response masking** **[BARU v4.3]** | Header sensitif + body secret diganti `***MASKED***` sebelum disimpan/ditampilkan |

---

## 6. Arsitektur Terpadu (v4.3) **[DIPERBARUI v4.3]**

Diagram arsitektur v4.2 §6 **[TIDAK BERUBAH]** — Fitur G berjalan di `billas-app` yang sama, tidak menambah container/worker.

**Alur Webhook Simulator (Fitur F)** **[TIDAK BERUBAH]** — sama dengan v4.2 §6.

**Alur API Tester (Fitur G) [BARU v4.3]:**

```text
Admin UI (GET /admin/api-tester)
  → POST /admin/api-tester/send
    → ApiTesterController::send()
      → AuthMiddleware + CsrfMiddleware + RateLimitMiddleware(20/min/IP) + ApiTesterFlagMiddleware
      → DebugApiService::send(provider, method, endpoint, body)
        → provider->getApiTesterBaseUrl()          ← config, bukan input
        → provider->getApiTesterAllowedPaths()     ← whitelist regex
        → validasi: method whitelist, path whitelist, no absolute URL, body size/JSON
        → provider->getApiTesterHeaders()          ← auth server-side
        → Guzzle request (timeout cap, allow_redirects=false)
        → maskHeaders() + maskBody(provider->getApiTesterSecrets())
        → INSERT api_tester_runs (masked)
        → audit_trail('api_tester.dispatch')
      → UI tampilkan {status, duration_ms, headers, body}
  → GET /admin/api-tester/runs → riwayat 20 run (masked)
```

---

## 7. Kontrak Infra (v4.3) **[DIPERBARUI v4.3]**

Semua baris v4.2 §7 **[TIDAK BERUBAH]**, ditambah:

| Kebutuhan | Kontrak |
|---|---|
| **API Tester production policy** **[BARU v4.3]** | `FEATURE_API_TESTER=false` default; jika `true` di `production`, wajib `API_TESTER_ALLOW_PRODUCTION_REQUESTS=true` (fail-fast di `Env::validate()`) |
| **API Tester akses tambahan** **[BARU v4.3]** | Opsional: Cloudflare Access policy pada `/admin/api-tester*` untuk pertahanan berlapis |
| **API Tester provider prerequisite** **[BARU v4.3]** | Minimal satu provider terkonfigurasi; jika tidak ada, UI menampilkan error jelas |
| **API Tester outbound** **[BARU v4.3]** | Butuh outbound HTTPS ke `*.tripay.co.id`, `api.midtrans.com`, `api.xendit.co`, `api-prod.duitku.com` (sudah ada di v4.2 §7) |

---

## 8. Stack & Dependensi (v4.3) **[TIDAK BERUBAH]**

Sama persis dengan v4.2 §8. **Tidak ada dependensi baru** — Fitur G memakai Guzzle 7, `GatewayFactory`, `AuditService`, `RateLimitMiddleware` yang sudah ada.

---

## 🌳 Struktur Repo Final Billas v4.3 **[DIPERBARUI v4.3]**

Semua file v4.2 tetap. **Tambahan v4.3** ditandai `# NEW v4.3`.

```text
billas-php/
├── docker/                                # [TIDAK BERUBAH]
├── docker-compose.yml                     # [TIDAK BERUBAH]
├── docker-compose.dev.yml                 # [TIDAK BERUBAH]
├── .dockerignore                          # [TIDAK BERUBAH]
├── .env.example                           # + API_TESTER_* (# NEW v4.3)
├── README.md                              # + section "API Tester" (# NEW v4.3)
├── composer.json                          # [TIDAK BERUBAH]
├── composer.lock                          # [TIDAK BERUBAH]
├── phpunit.xml                            # [TIDAK BERUBAH]
├── phpstan.neon                           # [TIDAK BERUBAH]
├── .github/workflows/build-push.yml       # [TIDAK BERUBAH]
├── bin/
│   ├── console                            # + subcommand api:tester (# NEW v4.3)
│   ├── worker.php                         # [TIDAK BERUBAH]
│   ├── cron.php                           # [TIDAK BERUBAH]
│   └── preload.php                        # [TIDAK BERUBAH]
├── public/
│   ├── index.php                          # [TIDAK BERUBAH]
│   ├── css/style.css                      # [TIDAK BERUBAH]
│   ├── js/app.js                          # [TIDAK BERUBAH]
│   ├── js/wifi.js                         # [TIDAK BERUBAH]
│   ├── js/api-tester.js                   # BARU v4.3 (form + response viewer) (# NEW v4.3)
│   └── img/logo.png                       # [TIDAK BERUBAH]
├── db/
│   ├── schema.sql                         # + api_tester_runs (# NEW v4.3)
│   └── migrations/
│       ├── 001_v3_additive.php            # [TIDAK BERUBAH]
│       ├── 002_v3_backfill.php            # [TIDAK BERUBAH]
│       ├── 003_v4_qris_unique.php         # [TIDAK BERUBAH]
│       ├── 004_v4_captive_portal.php      # [TIDAK BERUBAH]
│       ├── 005_v4_2_webhook_simulator.php # [TIDAK BERUBAH]
│       └── 006_v4_3_api_tester.php        # BARU v4.3 (# NEW v4.3)
├── config/
│   ├── env.php                            # + API_TESTER_* + guard (# NEW v4.3)
│   ├── logger.php                         # [TIDAK BERUBAH]
│   ├── database.php                       # [TIDAK BERUBAH]
│   ├── settings.php                       # + api_tester.* (# NEW v4.3)
│   └── container.php                      # + DebugApiService (# NEW v4.3)
├── src/
│   ├── App/
│   │   ├── Application.php                # [TIDAK BERUBAH]
│   │   └── Routes.php                     # + 3 route API tester (# NEW v4.3)
│   ├── Controller/
│   │   ├── PagesController.php            # [TIDAK BERUBAH]
│   │   ├── PaymentApiController.php       # [TIDAK BERUBAH]
│   │   ├── WebhookController.php          # [TIDAK BERUBAH]
│   │   ├── AdminPortalController.php      # [TIDAK BERUBAH]
│   │   ├── CustomerPortalController.php   # [TIDAK BERUBAH]
│   │   ├── HealthController.php           # [TIDAK BERUBAH]
│   │   ├── InternalController.php         # [TIDAK BERUBAH]
│   │   ├── CaptivePortalController.php    # [TIDAK BERUBAH]
│   │   ├── WebhookSimulatorController.php # [TIDAK BERUBAH]
│   │   └── ApiTesterController.php        # BARU v4.3 (# NEW v4.3)
│   ├── Middleware/
│   │   ├── AuthMiddleware.php             # [TIDAK BERUBAH]
│   │   ├── CsrfMiddleware.php             # [TIDAK BERUBAH]
│   │   ├── ErrorHandlerMiddleware.php     # [TIDAK BERUBAH]
│   │   ├── RateLimitMiddleware.php        # + route api-tester (# NEW v4.3)
│   │   ├── SecurityHeadersMiddleware.php  # [TIDAK BERUBAH]
│   │   ├── CronAuthMiddleware.php         # [TIDAK BERUBAH]
│   │   ├── CaptiveSessionMiddleware.php   # [TIDAK BERUBAH]
│   │   ├── SimulatorFlagMiddleware.php    # [TIDAK BERUBAH]
│   │   └── ApiTesterFlagMiddleware.php    # BARU v4.3 (# NEW v4.3)
│   ├── Database/                          # [TIDAK BERUBAH]
│   ├── Domain/
│   │   ├── PaymentGateway/
│   │   │   ├── PaymentProvider.php        # [TIDAK BERUBAH]
│   │   │   ├── GatewayFactory.php         # [TIDAK BERUBAH]
│   │   │   ├── BaseProvider.php           # + 4 method API tester (# NEW v4.3)
│   │   │   ├── TripayService.php          # + 4 method API tester (# NEW v4.3)
│   │   │   ├── MidtransService.php        # + 4 method API tester (# NEW v4.3)
│   │   │   ├── XenditService.php          # + 4 method API tester (# NEW v4.3)
│   │   │   ├── DuitkuService.php          # + 4 method API tester (# NEW v4.3)
│   │   │   └── QrisStaticService.php      # + 4 method (throw) (# NEW v4.3)
│   │   ├── PaymentService.php             # [TIDAK BERUBAH]
│   │   ├── OrderService.php               # [TIDAK BERUBAH]
│   │   ├── InvoiceService.php             # [TIDAK BERUBAH]
│   │   ├── VoucherService.php             # [TIDAK BERUBAH]
│   │   ├── CustomerService.php            # [TIDAK BERUBAH]
│   │   ├── CustomerPaymentAccountService.php # [TIDAK BERUBAH]
│   │   ├── PackageService.php             # [TIDAK BERUBAH]
│   │   ├── RouterService.php              # [TIDAK BERUBAH]
│   │   ├── MikrotikClient.php             # [TIDAK BERUBAH]
│   │   ├── MikrotikService.php            # [TIDAK BERUBAH]
│   │   ├── QrisUtil.php                   # [TIDAK BERUBAH]
│   │   ├── UniqueCodeService.php          # [TIDAK BERUBAH]
│   │   ├── WebhookService.php             # [TIDAK BERUBAH]
│   │   ├── RetryService.php               # [TIDAK BERUBAH]
│   │   ├── CronService.php                # [TIDAK BERUBAH]
│   │   ├── CaptivePortalService.php       # [TIDAK BERUBAH]
│   │   ├── WebhookSimulatorService.php    # [TIDAK BERUBAH]
│   │   ├── DebugApiService.php            # BARU v4.3 (# NEW v4.3)
│   │   ├── ReconciliationService.php      # [TIDAK BERUBAH]
│   │   ├── AnalyticsService.php           # [TIDAK BERUBAH]
│   │   ├── BackupService.php              # [TIDAK BERUBAH]
│   │   └── AuditService.php               # + api_tester.dispatch (# NEW v4.3)
│   ├── Session/                           # [TIDAK BERUBAH]
│   ├── Support/                           # [TIDAK BERUBAH]
│   └── Kernel.php                         # [TIDAK BERUBAH]
├── views/
│   ├── layout.php                         # [TIDAK BERUBAH]
│   ├── portal.php                         # [TIDAK BERUBAH]
│   ├── qris.php                           # [TIDAK BERUBAH]
│   ├── voucher.php                        # [TIDAK BERUBAH]
│   ├── error.php                          # [TIDAK BERUBAH]
│   ├── partials/
│   │   ├── admin-open.php                 # + menu "API Tester" (# NEW v4.3)
│   │   ├── admin-close.php                # [TIDAK BERUBAH]
│   │   ├── persistent-qr.php              # [TIDAK BERUBAH]
│   │   ├── qris-unique.php                # [TIDAK BERUBAH]
│   │   └── csrf.php                       # [TIDAK BERUBAH]
│   ├── admin/
│   │   ├── login.php                      # [TIDAK BERUBAH]
│   │   ├── dashboard.php                  # [TIDAK BERUBAH]
│   │   ├── customers.php                  # [TIDAK BERUBAH]
│   │   ├── packages.php                   # [TIDAK BERUBAH]
│   │   ├── routers.php                    # [TIDAK BERUBAH]
│   │   ├── billing.php                    # [TIDAK BERUBAH]
│   │   ├── vouchers.php                   # [TIDAK BERUBAH]
│   │   ├── analytics.php                  # [TIDAK BERUBAH]
│   │   ├── reconciliation.php             # [TIDAK BERUBAH]
│   │   ├── settings.php                   # + toggle features.api_tester (# NEW v4.3)
│   │   ├── wifi_sessions.php              # [TIDAK BERUBAH]
│   │   ├── simulator.php                  # [TIDAK BERUBAH]
│   │   └── api_tester.php                 # BARU v4.3 (# NEW v4.3)
│   ├── customer/                          # [TIDAK BERUBAH]
│   └── wifi/                              # [TIDAK BERUBAH]
├── tests/
│   ├── bootstrap.php                      # [TIDAK BERUBAH]
│   ├── Unit/
│   │   ├── PaymentGatewayTest.php         # [TIDAK BERUBAH]
│   │   ├── OrderServiceTest.php           # [TIDAK BERUBAH]
│   │   ├── VoucherServiceTest.php         # [TIDAK BERUBAH]
│   │   ├── HotspotCalendarTest.php        # [TIDAK BERUBAH]
│   │   ├── CustomerPaymentAccountTest.php # [TIDAK BERUBAH]
│   │   ├── InvoiceServiceTest.php         # [TIDAK BERUBAH]
│   │   ├── CustomerServiceTest.php        # [TIDAK BERUBAH]
│   │   ├── MikrotikServiceTest.php        # [TIDAK BERUBAH]
│   │   ├── QrisUtilTest.php               # [TIDAK BERUBAH]
│   │   ├── UniqueCodeServiceTest.php      # [TIDAK BERUBAH]
│   │   ├── CronServiceTest.php            # [TIDAK BERUBAH]
│   │   ├── CaptivePortalServiceTest.php   # [TIDAK BERUBAH]
│   │   ├── WebhookSimulatorTest.php       # [TIDAK BERUBAH]
│   │   └── DebugApiServiceTest.php        # BARU v4.3 (# NEW v4.3)
│   └── Integration/
│       ├── WebhookTest.php                # [TIDAK BERUBAH]
│       ├── PaymentFlowTest.php            # [TIDAK BERUBAH]
│       ├── OperationsTest.php             # [TIDAK BERUBAH]
│       ├── HotspotCalendarFlowTest.php    # [TIDAK BERUBAH]
│       ├── PersistentAccountTest.php      # [TIDAK BERUBAH]
│       ├── InternalCronTest.php           # [TIDAK BERUBAH]
│       ├── QrisUniqueGuaranteeTest.php    # [TIDAK BERUBAH]
│       ├── CaptivePortalFlowTest.php      # [TIDAK BERUBAH]
│       ├── WebhookSimulatorTest.php       # [TIDAK BERUBAH]
│       └── ApiTesterTest.php              # BARU v4.3 (# NEW v4.3)
└── var/.gitkeep                           # [TIDAK BERUBAH]
```

**Total file baru v4.3:** ~8 · **Total file diubah:** ~10 (mayoritas additive).

---

## 10. Environment Variables (v4.3 Final) **[DIPERBARUI v4.3]**

Semua env v4.2 **[TIDAK BERUBAH]**, ditambah blok berikut:

```text
# ── Fitur G (v4.3) ────────────────────────────────
FEATURE_API_TESTER=false                        # default OFF; jangan pernah true di production tanpa izin eksplisit
API_TESTER_MODE=sandbox                         # sandbox|production (menentukan base URL provider)
API_TESTER_ALLOW_PRODUCTION_REQUESTS=false      # jika true, mengizinkan flag ON di production (fail-fast guard)
API_TESTER_TIMEOUT_SECONDS=10                   # cap timeout request
API_TESTER_MAX_BODY_BYTES=65536                 # cap ukuran request body
API_TESTER_RATE_LIMIT_PER_MIN=20                # rate limit per IP
API_TESTER_ALLOWED_METHODS=GET,POST,DELETE      # whitelist method
```

**Aturan `Env::validate()` (v4.3 final) [DIPERBARUI v4.3]:**

Semua aturan v4.2 **[TIDAK BERUBAH]**, ditambah:

```php
// (v4.3) Fitur G: production guard
if ($env['APP_ENV'] === 'production'
    && filter_var($env['FEATURE_API_TESTER'], FILTER_VALIDATE_BOOL)
    && !filter_var($env['API_TESTER_ALLOW_PRODUCTION_REQUESTS'], FILTER_VALIDATE_BOOL)) {
    throw new \RuntimeException(
        'FEATURE_API_TESTER=true tidak diizinkan di production. ' .
        'Set API_TESTER_ALLOW_PRODUCTION_REQUESTS=true jika ini disengaja.'
    );
}

// (v4.3) Mode API tester harus valid
if (!in_array($env['API_TESTER_MODE'], ['sandbox','production'], true)) {
    throw new \RuntimeException('API_TESTER_MODE harus sandbox|production.');
}

// (v4.3) Method whitelist harus subset dari {GET,POST,DELETE}
$allowedMethods = array_map('trim', explode(',', strtoupper($env['API_TESTER_ALLOWED_METHODS'])));
$invalidMethods = array_diff($allowedMethods, ['GET','POST','DELETE']);
if (!empty($invalidMethods)) {
    throw new \RuntimeException(
        'API_TESTER_ALLOWED_METHODS hanya boleh berisi GET,POST,DELETE. Ditemukan: ' .
        implode(',', $invalidMethods)
    );
}

// (v4.3) Timeout & body cap harus positif
if ((int) $env['API_TESTER_TIMEOUT_SECONDS'] < 1 || (int) $env['API_TESTER_TIMEOUT_SECONDS'] > 60) {
    throw new \RuntimeException('API_TESTER_TIMEOUT_SECONDS harus 1..60.');
}
if ((int) $env['API_TESTER_MAX_BODY_BYTES'] < 1 || (int) $env['API_TESTER_MAX_BODY_BYTES'] > 1048576) {
    throw new \RuntimeException('API_TESTER_MAX_BODY_BYTES harus 1..1048576.');
}
```

---

## 11. Skema Database v4.3 Final **[DIPERBARUI v4.3]**

Semua tabel v4.2 **[TIDAK BERUBAH]**. Tambahan:

```sql
-- ============================================================
-- v4.3 — Fitur G: Gateway API Tester (#v4.3)
-- ============================================================
CREATE TABLE IF NOT EXISTS api_tester_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  provider TEXT NOT NULL CHECK (provider IN ('tripay','midtrans','xendit','duitku','qris_static')),
  method TEXT NOT NULL CHECK (method IN ('GET','POST','DELETE')),
  endpoint TEXT NOT NULL,
  request_body TEXT,          -- sudah di-mask sebelum disimpan
  response_status INTEGER,
  response_body TEXT,         -- sudah di-mask sebelum disimpan
  duration_ms INTEGER,
  error TEXT,                 -- pesan error (jika ada); di-mask
  created_by INTEGER REFERENCES users(id),
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_api_tester_runs_provider
  ON api_tester_runs(provider);
CREATE INDEX IF NOT EXISTS idx_api_tester_runs_created
  ON api_tester_runs(created_at);
```

**Catatan integritas v4.3:**
- `api_tester_runs.request_body` & `response_body` **wajib** di-mask sebelum disimpan (header sensitif + `getApiTesterSecrets()`).
- `api_tester_runs` **tidak** dipakai oleh pipeline webhook/gateway asli — hanya alat observability.
- `provider` CHECK dibatasi 5 provider; jika nanti ada provider baru, migrasi `ALTER` rebuild (sama seperti pola v4).
- Tidak ada kolom lama yang berubah.

---

## 12. Kontrak Service Layer (v4.3 Final) **[DIPERBARUI v4.3]**

### 12.1–12.10 **[TIDAK BERUBAH]** — sama dengan v4.2 §12.1–§12.10.

### 12.11 `DebugApiService` (Fitur G) **[BARU v4.3]**

```php
<?php
declare(strict_types=1);

namespace Billas\Domain;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class DebugApiService
{
    public function __construct(
        private GatewayFactory $gateways,
        private AuditService $audit,
        private array $config,
        private ?int $adminId = null,
    ) {}

    /**
     * @return array{status:int, duration_ms:int, headers:array, body:string}
     */
    public function send(string $provider, string $method, string $endpoint, ?string $body): array
    {
        $method = strtoupper($method);

        // 1) Validasi method
        if (!in_array($method, ['GET','POST','DELETE'], true)) {
            throw new \InvalidArgumentException("Method tidak valid: {$method}");
        }
        $allowed = array_map('trim', explode(',', strtoupper($this->config['api_tester_allowed_methods'])));
        if (!in_array($method, $allowed, true)) {
            throw new \InvalidArgumentException("Method {$method} tidak diizinkan oleh konfigurasi.");
        }

        // 2) Validasi body
        if ($body !== null && strlen($body) > $this->config['api_tester_max_body_bytes']) {
            throw new \InvalidArgumentException('Body melebihi batas ukuran.');
        }
        if ($body !== null && $body !== '' && $method !== 'GET') {
            json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        }

        // 3) Validasi endpoint
        if (preg_match('#^https?://#i', $endpoint)) {
            throw new \InvalidArgumentException('Endpoint harus path relatif, bukan URL absolut.');
        }
        $gw = $this->gateways->make($provider);
        if (!method_exists($gw, 'getApiTesterBaseUrl')) {
            throw new \LogicException("Provider {$provider} tidak mendukung API tester.");
        }
        $allowedPaths = $gw->getApiTesterAllowedPaths();
        $matched = false;
        foreach ($allowedPaths as $pattern) {
            if (preg_match($pattern, $endpoint)) { $matched = true; break; }
        }
        if (!$matched) {
            throw new \InvalidArgumentException("Endpoint tidak di whitelist untuk provider {$provider}.");
        }

        // 4) Bangun URL & headers (server-side)
        $url = rtrim($gw->getApiTesterBaseUrl(), '/') . '/' . ltrim($endpoint, '/');
        $headers = $gw->getApiTesterHeaders();
        $secrets = $gw->getApiTesterSecrets();

        // 5) Kirim request
        $client = new Client([
            'timeout'         => (int) $this->config['api_tester_timeout_seconds'],
            'http_errors'     => false,
            'allow_redirects' => false,
        ]);

        $start = microtime(true);
        try {
            $response = $client->request($method, $url, [
                'headers' => $headers,
                'body'    => $body,
            ]);
        } catch (GuzzleException $e) {
            $duration = (int) ((microtime(true) - $start) * 1000);
            $this->recordRun($provider, $method, $endpoint, $body, null, null, $e->getMessage(), $duration, $secrets);
            throw new \RuntimeException('Request gagal: ' . $e->getMessage(), 0, $e);
        }
        $duration = (int) ((microtime(true) - $start) * 1000);

        // 6) Mask & simpan
        $statusCode   = $response->getStatusCode();
        $maskedHeaders = $this->maskHeaders($response->getHeaders());
        $respBody     = (string) $response->getBody();
        $maskedBody   = $this->maskBody($respBody, $secrets);
        $maskedReqBody = $this->maskBody((string) $body, $secrets);

        $this->recordRun($provider, $method, $endpoint, $maskedReqBody, $statusCode, $maskedBody, null, $duration, []);
        $this->audit->record(
            'admin', (string) $this->adminId, 'api_tester.dispatch',
            'api_tester_run', null, "{$provider}:{$method}", $endpoint
        );

        return [
            'status'      => $statusCode,
            'duration_ms' => $duration,
            'headers'     => $maskedHeaders,
            'body'        => $maskedBody,
        ];
    }

    private function maskHeaders(array $headers): array
    {
        $sensitive = ['authorization','xendit-api-key','x-callback-token','x-callback-signature','x-signature-key'];
        $out = [];
        foreach ($headers as $name => $values) {
            $out[$name] = in_array(strtolower($name), $sensitive, true)
                ? ['***MASKED***']
                : $values;
        }
        return $out;
    }

    private function maskBody(string $body, array $secrets): string
    {
        foreach ($secrets as $s) {
            if ($s !== null && $s !== '') {
                $body = str_replace($s, '***MASKED***', $body);
            }
        }
        return $body;
    }

    private function recordRun(
        string $provider, string $method, string $endpoint,
        ?string $requestBody, ?int $status, ?string $responseBody,
        ?string $error, int $durationMs, array $secrets
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO api_tester_runs
             (provider, method, endpoint, request_body, response_status, response_body,
              duration_ms, error, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $provider, $method, $endpoint,
            $requestBody !== null ? $this->maskBody($requestBody, $secrets) : null,
            $status,
            $responseBody !== null ? $this->maskBody($responseBody, $secrets) : null,
            $error !== null ? $this->maskBody($error, $secrets) : null,
            $durationMs, $this->adminId, time(),
        ]);
    }
}
```

### 12.12 `BaseProvider` + 5 provider — method API Tester (Fitur G) **[BARU v4.3]**

**`BaseProvider` (default throw / empty):**

```php
public function getApiTesterBaseUrl(): string
{
    throw new \LogicException(static::class . ' tidak mendukung API tester.');
}

/** Regex patterns; path relatif saja. Empty = tidak ada yang diizinkan. */
public function getApiTesterAllowedPaths(): array
{
    return [];
}

/** Auth headers (dibangun server-side; di-mask di log). */
public function getApiTesterHeaders(): array
{
    return [];
}

/** Secret strings yang harus di-mask di body request/response. */
public function getApiTesterSecrets(): array
{
    return [];
}
```

**Ringkasan implementasi per provider:**

| Provider | Base URL (mode-aware) | Whitelist path (contoh) | Auth header | Secrets |
|---|---|---|---|---|
| `TripayService` | `https://tripay.co.id/api-sandbox/` / `https://tripay.co.id/api/` | `^transaction/create$`, `^transaction/detail$`, `^merchant/payment-channel$`, `^merchant/fee-calculator$` | `Authorization: Bearer <TRIPAY_API_KEY>` | `TRIPAY_API_KEY`, `TRIPAY_PRIVATE_KEY` |
| `MidtransService` | `https://api.sandbox.midtrans.com/` / `https://api.midtrans.com/` | `^v2/[^/]+/charge$`, `^v2/[^/]+/status$`, `^v2/[^/]+/cancel$`, `^v2/[^/]+/expire$` | `Authorization: Basic base64(<MIDTRANS_SERVER_KEY> + ':')` | `MIDTRANS_SERVER_KEY`, `MIDTRANS_CLIENT_KEY` |
| `XenditService` | `https://api.xendit.co/` | `^qr_codes$`, `^qr_codes/[^/]+$`, `^v2/invoices$`, `^v2/invoices/[^/]+$`, `^v2/balance$`, `^callback_tokens$`, `^callback_tokens/[^/]+$` | `Authorization: Basic base64(<XENDIT_SECRET_KEY> + ':')` | `XENDIT_SECRET_KEY`, `XENDIT_WEBHOOK_TOKEN` |
| `DuitkuService` | `https://sandbox.duitku.com/webapi/api/` / `https://passport.duitku.com/webapi/api/` | `^merchant/createInvoice$`, `^merchant/transactionStatus$`, `^merchant/paymentmethod/getpaymentmethod$` | `Content-Type: application/json` | `DUITKU_API_KEY`, `DUITKU_MERCHANT_CODE` |
| `QrisStaticService` | **throw** `LogicException` — tidak punya API | — | — | — |

> Provider baru yang ingin mendukung API Tester cukup implement 4 method di atas — tanpa mengubah `DebugApiService` atau `GatewayFactory`.

### 12.13 Ringkasan service domain (v4.3) **[DIPERBARUI v4.3]**

Semua baris v4.2 §12.2 **[TIDAK BERUBAH]**, ditambah:

| File | Tanggung jawab |
|---|---|
| `DebugApiService.php` **[BARU v4.3]** | Validasi method/endpoint/body, form URL, Guzzle request, mask, `api_tester_runs`, audit |

---

## 13. Routes & API (v4.3 Final) **[DIPERBARUI v4.3]**

Semua route v4.2 §13 **[TIDAK BERUBAH]**, ditambah:

| Route | Fungsi |
|---|---|
| **`GET /admin/api-tester`** | **Fitur G — form + riwayat 20 run** |
| **`POST /admin/api-tester/send`** | **Fitur G — kirim request ke provider** |
| **`GET /admin/api-tester/runs`** | **Fitur G — daftar run (masked)** |

**Rate limit** (tambahan v4.3):
- **`/admin/api-tester/send` → 20 req/menit/IP** (sama dengan simulator).

**Middleware untuk route API tester (v4.3):**
- `AuthMiddleware` — wajib session admin.
- `CsrfMiddleware` — wajib token CSRF (kecuali `GET /admin/api-tester/runs`).
- `RateLimitMiddleware` — 20 req/menit/IP.
- `ApiTesterFlagMiddleware` — 403 jika `FEATURE_API_TESTER=false`.

---

## 14. Alur Inti (v4.3 Final) **[DIPERBARUI v4.3]**

### 14.1–14.7 **[TIDAK BERUBAH]** — sama dengan v4.2 §14.1–§14.7.

### 14.8 Fitur G — Gateway API Tester **[BARU v4.3]**

1. Admin login → `GET /admin/api-tester`.
2. Form: pilih provider, method, isi endpoint (path relatif), isi body JSON, klik Send.
3. Submit → `POST /admin/api-tester/send`:
   - `AuthMiddleware` + `CsrfMiddleware` + `RateLimitMiddleware(20/min/IP)` + `ApiTesterFlagMiddleware`.
   - `DebugApiService::send()`:
     - Validasi method, body size/JSON, endpoint (no absolute URL, whitelist).
     - `provider->getApiTesterBaseUrl()` + `getApiTesterAllowedPaths()` + `getApiTesterHeaders()`.
     - Guzzle request (timeout cap, `allow_redirects=false`, `http_errors=false`).
     - `maskHeaders()` + `maskBody(provider->getApiTesterSecrets())`.
     - Simpan `api_tester_runs` (masked).
     - `audit_trail('api_tester.dispatch')`.
4. UI menampilkan response viewer (`status`, `duration_ms`, `headers`, `body`).
5. `GET /admin/api-tester/runs` → riwayat 20 run terakhir (masked).

**CLI (CI/UAT):**

```bash
php bin/console api:tester xendit GET  v2/balance
php bin/console api:tester xendit POST qr_codes --body='{"external_id":"test-1","type":"DYNAMIC","callback_url":"https://example.id/cb"}'
php bin/console api:tester midtrans POST v2/charge --body='{"payment_type":"qris","transaction_details":{"order_id":"T-1","gross_amount":10000}}'
```

**Kasus tepi:**

- Provider `qris_static` → `LogicException` → UI error jelas.
- Endpoint tidak whitelist → 400.
- URL absolut → 400.
- Body oversize → 400.
- Body invalid JSON → 400.
- Method tidak di env whitelist → 400.
- **Production guard:** `APP_ENV=production` + flag true + `API_TESTER_ALLOW_PRODUCTION_REQUESTS=false` → `Env::validate()` throw saat boot (bukan saat request).

---

## 15. UI / Views (v4.3 Final) **[DIPERBARUI v4.3]**

Semua view v4.2 §15 **[TIDAK BERUBAH]**, ditambah:

| View | Catatan |
|---|---|
| `admin/api_tester.php` **[BARU v4.3]** | Form: provider (select), method (select dari env), endpoint (input + hint whitelist), body (textarea JSON), Send. Response viewer: status badge, duration, headers table, body `<pre>`. Riwayat 20 run (provider/method/endpoint/status/duration/created_by/created_at). |
| `partials/admin-open.php` | + menu "API Tester" (hanya dirender jika `FEATURE_API_TESTER=true`) |
| `admin/settings.php` | + toggle `features.api_tester`; status read-only (`FEATURE_API_TESTER`, `API_TESTER_MODE`, "production allowed") |
| `public/js/api-tester.js` **[BARU v4.3]** | Fetch POST `/admin/api-tester/send` + render response viewer + refresh riwayat |
| `README.md` | + section "API Tester" dengan peringatan production |

Helper `View::render()` dan `e()` **[TIDAK BERUBAH]**.

---

## 16. Security & Reliability (v4.3 Final) **[DIPERBARUI v4.3]**

Semua baris v4.2 §16 **[TIDAK BERUBAH]**, ditambah:

| Area | Tindakan |
|---|---|
| **API Tester default OFF** **[BARU v4.3]** | `FEATURE_API_TESTER=false` di `.env.example`; tidak pernah `true` default |
| **API Tester production guard** **[BARU v4.3]** | `Env::validate()` throw jika production + flag true + `API_TESTER_ALLOW_PRODUCTION_REQUESTS=false` |
| **API Tester admin-only + CSRF + rate limit** **[BARU v4.3]** | `/admin/*`, `AuthMiddleware` + `CsrfMiddleware` + `RateLimitMiddleware(20/min/IP)` + `ApiTesterFlagMiddleware` |
| **SSRF prevention** **[BARU v4.3]** | Base URL fixed per provider + path whitelist regex + tolak URL absolut + `allow_redirects=false` |
| **Secret never leaves backend** **[BARU v4.3]** | Auth header dibangun server-side; tidak pernah dikirim ke frontend |
| **Response masking** **[BARU v4.3]** | Header sensitif (`Authorization`, `Xendit-Api-Key`, `X-Callback-Token`, `X-Callback-Signature`, `X-Signature-Key`) di-mask; body di-mask via `getApiTesterSecrets()` |
| **DB masking** **[BARU v4.3]** | `api_tester_runs.request_body` & `response_body` disimpan ter-mask |
| **Audit** **[BARU v4.3]** | Setiap dispatch ke `audit_trail('api_tester.dispatch')` |
| **Timeout & body cap** **[BARU v4.3]** | `API_TESTER_TIMEOUT_SECONDS` (default 10) + `API_TESTER_MAX_BODY_BYTES` (default 64 KB) |
| **Method whitelist** **[BARU v4.3]** | Hanya `GET|POST|DELETE` (dapat diperketat via env) |

---

## 17. Docker, Compose, OpenRC, Cloudflared **[TIDAK BERUBAH]**

Sama persis dengan v4.2 §17. Fitur G berjalan di `billas-app` yang sama; tidak ada container/worker/cron task baru.

---

## 18. Testing Checklist (v4.3 Final) **[DIPERBARUI v4.3]**

### 18.1–18.7 **[TIDAK BERUBAH]** — sama dengan v4.2 §18.1–§18.7.

### 18.8 Fitur G — Gateway API Tester **[BARU v4.3]**

**Unit (`tests/Unit/DebugApiServiceTest.php`):**
- [ ] `send` throw untuk method tidak valid (`PUT`, `PATCH`).
- [ ] `send` throw untuk method tidak di `API_TESTER_ALLOWED_METHODS`.
- [ ] `send` throw untuk body oversize (`> API_TESTER_MAX_BODY_BYTES`).
- [ ] `send` throw untuk body invalid JSON.
- [ ] `send` throw untuk endpoint URL absolut (`http://evil.com`).
- [ ] `send` throw untuk endpoint tidak whitelist (mis. `xendit` + `/admin/secret`).
- [ ] `send` throw untuk provider `qris_static` (`LogicException`).
- [ ] `send` throw untuk provider tanpa `getApiTesterBaseUrl` (`method_exists` false → `LogicException`).
- [ ] `maskHeaders` mengganti `Authorization`, `Xendit-Api-Key`, `X-Callback-Token`, `X-Callback-Signature`, `X-Signature-Key` dengan `***MASKED***`.
- [ ] `maskBody` mengganti secret dari `getApiTesterSecrets()` dengan `***MASKED***`.
- [ ] `recordRun` menyimpan body ter-mask (tidak plaintext).
- [ ] `getApiTesterBaseUrl` per provider: sandbox vs production sesuai mode.
- [ ] `getApiTesterAllowedPaths` per provider: path valid match, path invalid tidak match.

**Integration (`tests/Integration/ApiTesterTest.php`):**
- [ ] `Env::validate()` throw untuk production + flag true + `API_TESTER_ALLOW_PRODUCTION_REQUESTS=false`.
- [ ] `Env::validate()` lulus untuk dev + flag true.
- [ ] `Env::validate()` throw untuk `API_TESTER_MODE` invalid.
- [ ] `Env::validate()` throw untuk `API_TESTER_ALLOWED_METHODS` berisi `PUT`.
- [ ] `POST /admin/api-tester/send` tanpa session admin → 302/401.
- [ ] `POST /admin/api-tester/send` tanpa CSRF → 403.
- [ ] `POST /admin/api-tester/send` dengan flag OFF → 403 (`ApiTesterFlagMiddleware`).
- [ ] `POST /admin/api-tester/send` melebihi 20/menit → 429.
- [ ] `POST /admin/api-tester/send` Xendit `GET v2/balance` (fake Guzzle) → 200 + response viewer.
- [ ] `POST /admin/api-tester/send` Xendit `POST qr_codes` (fake) → 200 + body di-mask.
- [ ] `POST /admin/api-tester/send` endpoint tidak whitelist → 400.
- [ ] `POST /admin/api-tester/send` URL absolut → 400.
- [ ] `POST /admin/api-tester/send` body oversize → 400.
- [ ] `POST /admin/api-tester/send` body invalid JSON → 400.
- [ ] `POST /admin/api-tester/send` provider `qris_static` → 400/500 dengan pesan jelas.
- [ ] `api_tester_runs` tercatat dengan `request_body` & `response_body` ter-mask.
- [ ] `audit_trail` mencatat `api_tester.dispatch`.
- [ ] `GET /admin/api-tester/runs` menampilkan 20 run terakhir.

**CLI:**
- [ ] `bin/console api:tester` untuk provider valid.
- [ ] `bin/console api:tester` untuk endpoint tidak whitelist → exit code non-zero.

**UI:**
- [ ] `admin/api_tester.php` menampilkan form + response viewer + riwayat.
- [ ] Menu "API Tester" hanya muncul saat flag ON.
- [ ] `admin/settings.php` menampilkan toggle `features.api_tester` + status read-only.

**Security:**
- [ ] `api_tester_runs.request_body` & `response_body` **tidak** pernah plaintext (secret di-mask).
- [ ] Header `Authorization` **tidak** pernah dikirim ke frontend.
- [ ] `POST /admin/api-tester/send` dengan endpoint `http://169.254.169.254/latest/meta-data/` → 400.
- [ ] `POST /admin/api-tester/send` dengan endpoint `//evil.com/path` → 400 (tidak match whitelist).
- [ ] Log tidak memuat secret.

---

## 19. Timeline (v4.3 Final) **[DIPERBARUI v4.3]**

Semua timeline v4.2 §19 (H1–H23) **[TIDAK BERUBAH]**, ditambah:

| Hari | Aktivitas |
|---|---|
| **H24** | **Fitur G: migrasi `006_v4_3_api_tester.php`; env `API_TESTER_*` + `Env::validate()` guard; `DebugApiService`; `BaseProvider` + 5 provider (4 method); `ApiTesterController`; `ApiTesterFlagMiddleware`** |
| **H25** | **Fitur G UI: `views/admin/api_tester.php`; `public/js/api-tester.js`; menu di `admin-open.php`; toggle di `admin/settings.php`; `bin/console api:tester`; test Unit + Integration; README section "API Tester"** |

**Total ± 25 hari.** Fitur G dapat dikerjakan **paralel** dengan H20–H21 (UAT staging) karena hanya menyentuh: `BaseProvider` (+4 method), 5 provider (+4 method kecil), `Routes.php` (+3 route), `Env.php`/`settings.php` (+6 env), 2 file baru (`DebugApiService`, `ApiTesterController`, `ApiTesterFlagMiddleware`), 1 view. Tanpa mengganggu Fitur D/E/F.

---

## 20. Definition of Done (Billas v4.3 Final) **[DIPERBARUI v4.3]**

### Dasar v3 + Fitur D + Fitur E + Fitur F **[TIDAK BERUBAH]**

Seluruh DoD v4.2 §20 **wajib lulus**.

### Fitur G — Gateway API Tester (wajib v4.3) **[BARU v4.3]**

- [ ] `FEATURE_API_TESTER=false` default di `.env.example`.
- [ ] `Env::validate()` fail-fast di production jika flag true tanpa `API_TESTER_ALLOW_PRODUCTION_REQUESTS=true`.
- [ ] `Env::validate()` memvalidasi `API_TESTER_MODE`, `API_TESTER_ALLOWED_METHODS`, `API_TESTER_TIMEOUT_SECONDS`, `API_TESTER_MAX_BODY_BYTES`.
- [ ] `api_tester_runs` tabel terbuat via migrasi idempotent `006_v4_3_api_tester.php`.
- [ ] `DebugApiService` berfungsi untuk 5 provider × 3 method (GET/POST/DELETE).
- [ ] Route `GET /admin/api-tester`, `POST /admin/api-tester/send`, `GET /admin/api-tester/runs` aktif dan dilindungi `AuthMiddleware` + `CsrfMiddleware` + `RateLimitMiddleware(20/min/IP)` + `ApiTesterFlagMiddleware`.
- [ ] `views/admin/api_tester.php` menampilkan form + response viewer + riwayat 20 run.
- [ ] Menu "API Tester" di `partials/admin-open.php` (hanya saat flag ON).
- [ ] `bin/console api:tester` berfungsi.
- [ ] **SSRF prevention:** URL absolut ditolak; path wajib whitelist; base URL fixed per provider.
- [ ] **Secret masking:** header sensitif + body secret di-mask di UI & DB.
- [ ] Audit `api_tester.dispatch` tercatat di `audit_trail`.
- [ ] README section "API Tester" dengan peringatan production.

### Regression & migrasi (wajib v4.3) **[DIPERBARUI v4.3]**

- [ ] Seluruh test v3 + v4 + v4.2 lulus.
- [ ] Migrasi `001`–`006` idempotent; rollback aman (image lama tetap jalan).
- [ ] `Env::validate()` fail-fast untuk semua fitur (production guard Fitur F + G, mode valid, secret requirement).
- [ ] **0 kolom lama diubah; 0 perilaku berubah saat flag mati.**

---

## 21. Roadmap Multi-Gateway Plug-and-Play (v4.3) **[DIPERBARUI v4.3]**

**Cara menambah provider baru:**

1. Buat `src/Domain/PaymentGateway/<Name>Service.php` yang `extends BaseProvider`.
2. Daftarkan di `GatewayFactory::PROVIDERS`.
3. Tambah env `NAME_*`.
4. Route `/api/webhook/{name}` otomatis berfungsi.
5. Tambah unit test.
6. (Opsional) Implement `createPersistentAccount` untuk Fitur B/D.
7. (Opsional) Implement `buildSimulatedWebhook` untuk Fitur F.
8. **[BARU v4.3]** (Opsional) Implement `getApiTesterBaseUrl`, `getApiTesterAllowedPaths`, `getApiTesterHeaders`, `getApiTesterSecrets` untuk Fitur G.

Provider baru **tidak wajib** implementasi method Fitur G — jika tidak ada, UI API Tester menampilkan error jelas untuk provider tersebut (via `method_exists`). Provider baru yang ingin mendukung API Tester cukup menambahkan 4 method di kelasnya, tanpa mengubah `DebugApiService` atau interface.

---

## 22. Status Implementasi & Catatan Port (v4.3 Final) **[DIPERBARUI v4.3]**

### 22.1 Perbedaan implementasi vs v4.2 **[DIPERBARUI v4.3]**

| Aspek | v4.2 | v4.3 |
|---|---|---|
| Fitur F | Webhook Simulator | **[TIDAK BERUBAH]** |
| Fitur G | — | **Gateway API Tester (admin-only, flag-gated, default OFF)** |
| Skema | v4.2 | + `api_tester_runs` |
| Service | v4.2 | + `DebugApiService`; `BaseProvider` + 4 method; 5 provider + 4 method |
| Route | v4.2 | + `/admin/api-tester`, `/admin/api-tester/send`, `/admin/api-tester/runs` |
| View | v4.2 | + `admin/api_tester.php`, `partials/admin-open.php` (+menu) |
| Env | v4.2 | + `FEATURE_API_TESTER`, `API_TESTER_MODE`, `API_TESTER_ALLOW_PRODUCTION_REQUESTS`, `API_TESTER_TIMEOUT_SECONDS`, `API_TESTER_MAX_BODY_BYTES`, `API_TESTER_ALLOWED_METHODS`, `API_TESTER_RATE_LIMIT_PER_MIN` |
| Middleware | v4.2 | + `ApiTesterFlagMiddleware` |
| CLI | v4.2 | + `bin/console api:tester` |

### 22.2 Status roadmap **[DIPERBARUI v4.3]**

| Fase | Status | Realisasi |
|---|---|---|
| **F0–F5** | ✅ Rencana port | Sama v3 |
| **Fitur A** | ✅ Rilis | calendar expiry + cron `tasks=vouchers` |
| **Fitur B** | ✅ Rilis | akun persisten |
| **Fitur C** | ✅ Rilis | `paid_until` |
| **Fitur D** | ✅ Rilis | QRIS unik + `service_guaranteed_until` |
| **Fitur E** | ✅ Rilis | captive portal RTRWNET + password unik |
| **Fitur F** | ✅ Rilis | Webhook Simulator |
| **Fitur G** | 🚧 Rencana port v4.3 | Gateway API Tester (admin-only, default OFF) |

### 22.3 Berkas utama hasil port v4.3 **[DIPERBARUI v4.3]**

Semua berkas v4.2 §22.3 **[TIDAK BERUBAH]**, ditambah:

| Berkas | Peran |
|---|---|
| `db/migrations/006_v4_3_api_tester.php` | Migrasi Fitur G |
| `src/Domain/DebugApiService.php` | **[BARU v4.3]** — validasi, Guzzle request, mask, `api_tester_runs` |
| `src/Controller/ApiTesterController.php` | **[BARU v4.3]** — 3 route admin |
| `src/Middleware/ApiTesterFlagMiddleware.php` | **[BARU v4.3]** — 403 saat flag OFF |
| `src/Domain/PaymentGateway/BaseProvider.php` + 5 provider | **[DIPERBARUI v4.3]** — +4 method API Tester |
| `src/Domain/AuditService.php` | **[DIPERBARUI v4.3]** — + `api_tester.dispatch` |
| `views/admin/api_tester.php` | **[BARU v4.3]** — form + response viewer + riwayat |
| `public/js/api-tester.js` | **[BARU v4.3]** — fetch + render |
| `tests/Unit/DebugApiServiceTest.php` + `tests/Integration/ApiTesterTest.php` | **[BARU v4.3]** — test Fitur G |
| `bin/console` | **[DIPERBARUI v4.3]** — + subcommand `api:tester` |

---

## 23. Migrasi & Rilis v4.3 (additive, idempotent) **[DIPERBARUI v4.3]**

### 23.1–23.3 **[TIDAK BERUBAH]** — sama dengan v4.2 §23.1–§23.3.

### 23.4 `db/migrations/006_v4_3_api_tester.php` (Fitur G) **[BARU v4.3]**

| # | Langkah | Idempotensi |
|---|---|---|
| 1 | `CREATE TABLE IF NOT EXISTS api_tester_runs (...)` | `IF NOT EXISTS` |
| 2 | `CREATE INDEX IF NOT EXISTS idx_api_tester_runs_provider ON api_tester_runs(provider)` | `IF NOT EXISTS` |
| 3 | `CREATE INDEX IF NOT EXISTS idx_api_tester_runs_created ON api_tester_runs(created_at)` | `IF NOT EXISTS` |
| 4 | Seed setting default `features.api_tester=false` | `INSERT OR IGNORE` |
| 5 | Seed setting default `api_tester.mode=sign` (untuk konsistensi UI; runtime tetap dari env) | `INSERT OR IGNORE` |

**Seed v4.3:** tambah setting default `features.api_tester` (`false`), `api_tester.mode` (`sandbox`), `api_tester.timeout_seconds` (`10`), `api_tester.max_body_bytes` (`65536`).

**Cara menjalankan migrasi:** sama dengan v4.2 §23 — `docker compose run --rm billas-app php /app/bin/console migrate` atau `AUTO_MIGRATE=1`.

**Rollback aman:** image v4.2 tetap bisa membaca DB v4.3 karena `api_tester_runs` murni additive dan tidak dipakai oleh pipeline webhook/gateway asli; Fitur G di-gate flag.

---

## 24. Hal yang TIDAK Diubah (dari rancangan v3/v4/v4.2) **[DIPERBARUI v4.3]**

Semua baris v4.2 §24 **[TIDAK BERUBAH]**, ditambah:

- **Tidak ada `DROP`/`MODIFY` kolom lama**; hanya `ADD COLUMN` + `CREATE TABLE IF NOT EXISTS`.
- **Fitur A–F tidak berubah perilaku** — `WebhookService::handle`, `PaymentProvider` interface, `VoucherService`, `InvoiceService`, `CustomerService`, `CaptivePortalService`, `WebhookSimulatorService` **tidak disentuh**.
- **Retry task type tidak ada yang baru** — Fitur G tidak memakai `retry_queue` (sinkron request-response).
- **Cron task tidak ada yang baru** — Fitur G request-driven; tidak ada `tasks=api_tester`.
- **Cloudflare Tunnel / ingress tidak berubah** — `/admin/api-tester*` dilayani app yang sama.
- **Container tidak berubah** — Fitur G di `billas-app`; tidak perlu `billas-worker` baru.
- **Kontrak publik tidak berubah** — endpoint HTTP & payload webhook identik (plus tambahan `/admin/api-tester*`).
- **`PaymentProvider` interface tidak diubah** — method Fitur G disediakan via `BaseProvider` + `method_exists` (Opsi A — additive-only murni).

**Perubahan arsitektural v4.3 (bukan perilaku):**

- Tidak ada container baru, worker baru, cron task baru.
- Tidak ada perubahan pada `WebhookService::handle()` (berbeda dari v4.2 yang menambah `bool $simulated = false`).
- **Satu-satunya perubahan pada kode lama:** `BaseProvider` + 5 provider mendapat 4 method opsional; `AdminPortalController` tidak berubah (route baru punya controller sendiri).
- `audit_trail` mendapat aksi baru `api_tester.dispatch` (pola sudah ada).

---

### Ringkasan satu paragraf

**Billas v4.3 (Pure PHP, FPM + Nginx + OpenRC)** adalah **pembaruan aditif v4.2** yang menambahkan **satu fitur operasional (G) Gateway API Tester**: alat **admin-only**, **flag-gated** (`FEATURE_API_TESTER=false` default), yang memungkinkan admin menembak endpoint outbound gateway (Xendit `/qr_codes`, Midtrans `/v2/charge`, Tripay `/transaction/create`, Duitku `/merchant/createInvoice`) langsung dari browser — **tanpa `curl`**, **tanpa mengekspos API key** (masking header + body), **tanpa membuka celah SSRF** (base URL fixed per provider + path whitelist regex + tolak URL absolut), dan **tanpa menyentuh pipeline webhook/gateway asli** (`WebhookService`, `PaymentProvider` interface, dan semua service v4.2 **tidak berubah**). Fitur G melengkapi Fitur F: **F = inbound** (webhook sintetis masuk), **G = outbound** (request API keluar). Semua tetap di atas **satu DB SQLite** (+1 tabel `api_tester_runs`), **satu ingress Cloudflare Tunnel**, **tiga container**, **satu abstraksi multi-gateway**, **satu worker**, dan **satu endpoint cron** — dengan **0 kolom lama diubah, 0 perilaku berubah saat flag mati, dan `Env::validate()` fail-fast** di production untuk Fitur G sebagaimana Fitur F. Stack tetap: **PHP 8.3-FPM + Slim 4 + PDO SQLite + Monolog + Guzzle + endroid/qr-code + evilfreelancer/routeros-api-php + PHPUnit 11 + OpenRC + Nginx**. *Satu billing, semua akses — dan sekarang, satu konsol debug untuk semua gateway.*

