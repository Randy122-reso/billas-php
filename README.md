# Billas v4 — Satu Billing, Semua Akses

Billing terpadu **Pure PHP 8.3 + Slim 4 + PDO SQLite** untuk hotspot voucher,
PPPoE bulanan, QRIS unik per pelanggan, dan captive portal WiFi publik
**RTRWNET**. Satu ingress, satu database, tiga container: `app`, `worker`, dan
`cloudflared`.

> Tahap awal ini sudah runnable end-to-end untuk alur utama: voucher hotspot,
> invoice PPPoE, QRIS unik + garansi layanan, captive portal QRIS → password
> WiFi, webhook multi-gateway, retry MikroTik, serta cron dari RouterOS.

## Fitur

| Area | Ringkasan |
|---|---|
| Hotspot | Voucher sekali pakai, masa aktif **uptime** atau **kalender**, QRIS, halaman voucher publik. |
| PPPoE | Invoice bulanan, isolir otomatis, reaktivasi setelah bayar, push MikroTik lewat worker. |
| **Fitur D** | **QRIS unik per pelanggan PPPoE** + `service_guaranteed_until`; cron isolir menghormati garansi. |
| **Fitur E** | **Captive portal RTRWNET**: pengunjung pilih paket → bayar QRIS → dapat password WiFi unik. |
| **Fitur F** | **Webhook Simulator admin-only** (flag-gated, default OFF): uji alur bayar tanpa menunggu gateway nyata. |
| Pembayaran | Tripay, Midtrans, Xendit, Duitku, QRIS statis; webhook terpadu dan idempotent. |
| Operasional | Admin back-office, portal pelanggan, audit trail, retry queue, backup SQLite, health check. |
| Cron | `POST /internal/cron/run`, idempotent lewat `job_state`, dipicu RouterOS Scheduler. |
| Deployment | Docker Compose (nginx + php-fpm, worker CLI), Cloudflare Tunnel opsional via profile `tunnel`. |

## Arsitektur

```text
Internet / pelanggan
        │
        ▼
Cloudflare Tunnel (opsional)
        │
        ▼
billas-app :8080  (nginx + PHP-FPM 8.3 + Slim 4)
        │
        ├── SQLite /data/billas.db  ◄────────────┐
        │                                        │
        ▼                                        │
billas-worker (PHP CLI, retry MikroTik) ─────────┘

RouterOS Scheduler ── POST /internal/cron/run ──► billas-app
RouterOS API ◄── app/worker (push voucher, isolir, reaktivasi)
```

- **`app`** — web, webhook, admin/customer/captive portal, cron endpoint.
- **`worker`** — menguras `retry_queue` untuk push/perubahan MikroTik.
- **`cloudflared`** — ingress publik, hanya aktif dengan `--profile tunnel`.
- **SQLite** — satu file `/data/billas.db`, mode WAL, migrasi additive dan
  idempotent.

## Mulai Cepat — Docker

Prasyarat: Docker Desktop / Docker Engine + Compose v2.

```bash
cp .env.example .env
```

Isi minimum di `.env` sebelum boot production:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://billing.example.id
BASE_URL=https://billing.example.id
ADMIN_USERNAME=admin
ADMIN_PASSWORD=ganti-dengan-password-kuat
SESSION_SECRET=<minimal-16-karakter-acak>
ENCRYPTION_KEY=<minimal-16-karakter-acak>
CRON_TOKEN=<minimal-16-karakter-acak>
```

Generate secret di Linux/macOS (PowerShell: gunakan generator acak pilihan Anda):

```bash
openssl rand -hex 32
```

Lalu jalankan:

```bash
docker compose up -d --build
docker compose ps
curl http://127.0.0.1:8080/health
curl http://127.0.0.1:8080/ready
```

Buka `http://127.0.0.1:8080/admin` dan masuk dengan `ADMIN_USERNAME` /
`ADMIN_PASSWORD` yang diisi di atas.

Untuk mengulang dari database kosong (menghapus volume SQLite dan log):

```bash
docker compose down -v
docker compose up -d --build
```

Dengan Cloudflare Tunnel:

```bash
# Isi CLOUDFLARE_TUNNEL_TOKEN di .env terlebih dahulu.
docker compose --profile tunnel up -d --build
```

### Development lokal (tanpa Docker)

Prasyarat: PHP 8.3 dengan ekstensi `pdo_sqlite`, `sqlite3`, `mbstring`,
`openssl`, `curl`, `json`, serta Composer.

```bash
composer install
cp .env.example .env
```

Ubah `.env` untuk lokal:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8090
AUTO_MIGRATE=1
AUTO_SEED=1
SEED_DEMO=1
MIKROTIK_FAKE=true
DB_PATH=var/billas.db
PAYMENT_DEFAULT_GATEWAY=qris_static
PAYMENT_FALLBACK_CHAIN=qris_static
```

```bash
php bin/console migrate --seed
php -S 127.0.0.1:8090 -t public public/index.php
```

Kredensial demo lokal:

| Portal | URL | Username | Password |
|---|---|---|---|
| Admin | `/admin` | `admin` | `admin123` |
| Pelanggan | `/portal` | `DEMO001` | `pelanggan123` |
| Captive | `/wifi` | — | tanpa login |

> Kredensial demo hanya dibuat bila `SEED_DEMO=1` dan hanya untuk mode lokal.
> Jangan gunakan nilai default di production.

## Konfigurasi

Prioritas konfigurasi:

1. **Process environment** (Docker/CI) menang atas file `.env`.
2. **`.env`** mengisi nilai awal.
3. **Tabel `settings`** menyimpan pengaturan operasional. Setelah seed pertama,
   nilai tertentu dapat diubah dari **Admin → Pengaturan** dan menjadi sumber
   utama untuk fitur/payment/wifi. Nilai env tetap dipakai sebagai fallback.

Variabel penting:

| Variabel | Default | Keterangan |
|---|---|---|
| `APP_ENV` | `production` | `production` mewajibkan HTTPS dan secret kuat. |
| `APP_URL` | `http://localhost:8080` | URL publik; dipakai untuk callback gateway dan QR. |
| `DB_PATH` | `var/billas.db` | Di Docker: `/data/billas.db`. |
| `AUTO_MIGRATE` | `0` | `1` menjalankan migrasi saat boot. |
| `AUTO_SEED` | `0` | `1` mengisi admin/settings saat boot. |
| `SEED_DEMO` | `0` | `1` membuat paket + pelanggan demo. |
| `SESSION_SECRET`, `ENCRYPTION_KEY` | — | **Wajib ≥16 karakter** di production. |
| `CRON_TOKEN` | — | **Wajib ≥16 karakter**; dipakai header `X-Cron-Token`. |
| `CRON_HMAC_ENABLED` | `false` | `true` mewajibkan `X-Cron-Signature` (HMAC body). |
| `FEATURE_PPOE_QRIS_UNIQUE` | `true` | Toggle awal Fitur D (bisa diubah di admin). |
| `FEATURE_WIFI_CAPTIVE_PORTAL` | `true` | Toggle awal Fitur E (bisa diubah di admin). |
| `FEATURE_WEBHOOK_SIMULATOR` | `false` | Toggle Fitur F. **Jangan aktifkan di production** kecuali untuk audit/demo ketat. |
| `WEBHOOK_SIMULATOR_MODE` | `sign` | `sign` memverifikasi signature provider; `bypass` melewati signature (khusus dev). |
| `WEBHOOK_SIMULATOR_ALLOW_IN_PRODUCTION` | `false` | Guard tambahan; `true` hanya jika simulator benar-benar diperlukan di production. |
| `PPPOE_QRIS_UNIQUE_PROVIDERS` | `midtrans,xendit,duitku` | Urutan gateway QRIS unik. |
| `PPPOE_SERVICE_GUARANTEE_DAYS` | `0` | Buffer garansi setelah `period_end`. |
| `WIFI_SSID` / `WIFI_ALLOWED_SSIDS` | `RTRWNET` | SSID captive portal yang diizinkan. |
| `WIFI_SESSION_TOKEN_TTL` | `1800` | Umur token sesi captive (detik). |
| `MIKROTIK_FAKE` | `false` | `true` = simulasi router tanpa koneksi nyata. |
| `PAYMENT_DEFAULT_GATEWAY` | `qris_static` | Gateway default saat membuat order. |
| `RETRY_INTERVAL` | `60` | Interval worker (detik). |

Daftar lengkap ada di [`.env.example`](.env.example). Pengaturan fitur, WiFi,
dan payment juga dapat diubah di **Admin → Pengaturan** setelah aplikasi jalan.

## Gateway Pembayaran & Webhook

Daftarkan callback URL berikut di dashboard masing-masing gateway:

| Gateway | URL callback | Verifikasi |
|---|---|---|
| Tripay | `https://domain-anda/api/webhook/tripay` | `X-Callback-Signature` = HMAC-SHA256(raw body, private key) |
| Midtrans | `https://domain-anda/api/webhook/midtrans` | `signature_key` = SHA512(order_id + status_code + gross_amount + server key) |
| Xendit | `https://domain-anda/api/webhook/xendit` | header `x-callback-token` = `XENDIT_WEBHOOK_TOKEN` |
| Duitku | `https://domain-anda/api/webhook/duitku` | `signature` = MD5(merchantCode + merchantRef + amount + apiKey) |
| QRIS statis | Tidak ada webhook | Konfirmasi manual di **Admin → Rekonsiliasi** |

Catatan:

- Set `PAYMENT_DEFAULT_GATEWAY` dan `PAYMENT_FALLBACK_CHAIN` sesuai gateway yang
  aktif. Gateway yang belum dikonfigurasi otomatis dilewati.
- QRIS statis memakai nominal unik. Cocokkan mutasi bank dengan nominal tersebut,
  lalu konfirmasi di **Admin → Rekonsiliasi**.
- **QRIS unik / akun persisten** tidak memiliki nominal tetap. Di halaman
  rekonsiliasi, masukkan nominal sesuai mutasi bank dan (disarankan) referensi
  mutasi agar pembayaran bulan berikutnya tidak dianggap duplikat. Untuk
  Midtrans/Duitku, QRIS unik memakai payload QRIS statis yang di-flag; Xendit
  juga mendukung VA persisten.
- `WEBHOOK_SECRET` opsional; verifikasi signature tetap dilakukan per provider.

## Simulasi Pembayaran (admin-only, default OFF)

Fitur F menambahkan **Webhook Simulator** untuk menguji alur pembayaran tanpa
menunggu callback gateway nyata. Simulator hanya dapat diakses admin yang sudah
login, dilindungi CSRF, dibatasi **20 request/menit/IP**, dan **mati secara
default**.

> **Peringatan production:** biarkan `FEATURE_WEBHOOK_SIMULATOR=false` di
> production. Simulator dapat menerbitkan voucher, melunasi invoice, dan
> memperpanjang `paid_until` tanpa pembayaran nyata. Jika terpaksa dipakai untuk
> audit/demo, set `WEBHOOK_SIMULATOR_ALLOW_IN_PRODUCTION=true`, batasi akses
> `/admin/simulator*` dengan Cloudflare Access atau lapisan auth tambahan, dan
> matikan kembali segera setelah selesai.

### Mengaktifkan (lokal/staging)

```dotenv
FEATURE_WEBHOOK_SIMULATOR=true
WEBHOOK_SIMULATOR_MODE=sign
WEBHOOK_SIMULATOR_ALLOW_IN_PRODUCTION=false
```

- Flag juga dapat diubah dari **Admin → Pengaturan** setelah seed pertama.
- `Env::validate()` menolak boot bila simulator aktif di production tanpa
  `WEBHOOK_SIMULATOR_ALLOW_IN_PRODUCTION=true`, bila mode tidak valid, atau bila
  mode `sign` dipilih sementara secret gateway yang didukung belum lengkap.

### Mode

| Mode | Perilaku |
|---|---|
| `sign` (default) | Payload dibangun sesuai format provider dan **signature tetap diverifikasi** oleh pipeline webhook asli. Gunakan untuk menguji kesesuaian signature. |
| `bypass` | Melewati verifikasi signature. Hanya untuk development. QRIS statis wajib memakai mode ini karena tidak memiliki signature webhook. |

Kedua mode mengirim header internal `X-Billas-Simulated: 1`; header ini tidak
diterima dari route webhook publik dan hanya dipakai simulator.

### Target dan event

| Target | ID | Efek |
|---|---|---|
| `order` | UUID order | `PAID` menerbitkan voucher; `EXPIRED`/`FAILED` mengubah order pending menjadi expired/failed tanpa voucher. |
| `invoice` | ID invoice | `PAID` melunasi invoice dan memperpanjang `paid_until` + `service_guaranteed_until`; transaksi pending dipakai ulang atau dibuat sintetis. |
| `persistent_account` | ID akun QRIS pelanggan | `PAID` melunasi invoice tertua atau membuat invoice prabayar. |

Event yang didukung: `PAID`, `EXPIRED`, `FAILED`. Override nominal dapat diisi
untuk menguji kasus amount mismatch. Provider yang belum mengimplementasikan
`buildSimulatedWebhook()` akan menampilkan error jelas, dan kegagalan build tetap
dicatat.

### Halaman, route, dan CLI

| Akses | Keterangan |
|---|---|
| `GET /admin/simulator` | Form simulator, tautan konteks, dan 20 run terakhir. |
| `POST /admin/simulator/webhook` | Bangun + dispatch webhook sintetis (CSRF wajib). |
| `GET /admin/simulator/runs` | Riwayat run dalam JSON (signature di-mask). |
| `php bin/console simulate:webhook order <uuid> --provider=tripay --event=PAID --mode=sign` | Jalur CLI untuk staging/otomasi. |
| `php bin/console simulate:webhook invoice <id> --provider=midtrans --event=PAID` | Contoh target invoice. |
| `php bin/console simulate:webhook persistent_account <id> --provider=xendit --event=PAID` | Contoh akun QRIS persisten. |

Tombol **Simulasi** juga tersedia di halaman admin voucher, billing, dan
pelanggan untuk mengisi target secara otomatis.

### Audit dan masking

- Setiap dispatch dicatat di `simulator_runs` (target, provider, mode, status,
  ringkasan respons) dan `audit_trail` dengan aksi `simulator.dispatch`.
- Header `X-Callback-Signature`, `X-Signature`, `X-Signature-Key`,
  `Authorization`, dan `X-Callback-Token`, serta field body `signature_key`,
  `signature`, `token`, dan `callback_token`, disimpan sebagai `***MASKED***`.
- `simulator_runs` hanya alat observability; pipeline webhook asli tidak
  membacanya.
- Event `EXPIRED`/`FAILED` hanya mengubah status order/transaksi pending pada
  dispatch simulasi. Webhook nyata dengan event tersebut tetap diabaikan agar
  status pembayaran asli tidak berubah.

### Verifikasi cepat

1. Aktifkan flag, login admin, buka `/admin/simulator`.
2. Pilih target `order`, masukkan UUID order pending, provider `tripay`, event
   `PAID`, mode `sign`; submit.
3. Pastikan respons sukses, voucher muncul di `/voucher/{orderId}`, dan baris
   baru tercatat di riwayat simulator.
4. Ulangi dengan event `EXPIRED` pada order pending lain; pastikan order menjadi
   expired dan tidak ada voucher.
5. Buka `/admin/simulator/runs`; pastikan signature/token tidak tampil
   plaintext dan audit `simulator.dispatch` tercatat.
6. Matikan kembali flag setelah pengujian.

## Alur Utama

### A. Voucher hotspot

1. Admin membuat paket hotspot (`uptime` atau `calendar`) dan mengaktifkan
   `available_on_captive` bila ingin dijual di WiFi publik.
2. Voucher/order dibuat lewat alur hotspot.
3. Pembayaran QRIS masuk lewat webhook (atau konfirmasi manual QRIS statis).
4. Voucher diterbitkan, halaman `/voucher/{orderId}` menampilkan kode/password.
5. Voucher kalender kedaluwarsa → cron `vouchers` → `hotspot_disable` masuk
   `retry_queue` → worker menonaktifkan user di MikroTik.

### D. PPPoE QRIS unik + garansi

1. Buat pelanggan PPPoE. Jika `pppoe_qris.auto_create=1`, akun QRIS unik dibuat
   otomatis; bisa juga lewat tombol admin atau CLI:
   `php bin/console qris:create <customerId> [gateway]`.
2. Pelanggan memindai QRIS unik yang sama setiap bulan.
3. Webhook gateway atau konfirmasi manual menerapkan pembayaran ke invoice
   tertua. Bila tidak ada invoice terbuka, sistem membuat invoice prabayar.
4. `service_guaranteed_until` diisi dari `period_end` (+ buffer bila diatur),
   dan **tidak pernah mundur**.
5. Cron `isolir` melewati pelanggan yang masih berada dalam masa garansi.

### E. Captive portal RTRWNET

1. Hubungkan klien ke SSID `RTRWNET`.
2. MikroTik mengarahkan browser ke
   `/wifi?router_id=1&ssid=RTRWNET&client_ip=$(ip)&client_mac=$(mac)`.
3. Pengunjung memilih paket hotspot → order QRIS dibuat.
4. Setelah pembayaran terverifikasi, halaman `/wifi/voucher/{orderId}`
   menampilkan password WiFi unik.
5. Password hanya berlaku untuk transaksi itu; untuk login ulang, pengunjung
   membeli QRIS baru. Sesi pending dibersihkan cron `captive_sessions`.

## Halaman Penting

| Halaman | URL |
|---|---|
| Landing | `/` |
| Voucher hotspot publik | `/voucher/{orderId}` |
| Captive portal WiFi | `/wifi` |
| Voucher/password WiFi | `/wifi/voucher/{orderId}` |
| Admin | `/admin` |
| Portal pelanggan | `/portal` |
| QRIS unik pelanggan | `/portal/qris-unique` (setelah login) |
| Health | `/health` |
| Readiness (DB, schema, gateway) | `/ready` |
| Cron internal | `POST /internal/cron/run` |
| Webhook | `POST /api/webhook/{provider}` |
| Webhook Simulator (admin) | `/admin/simulator` |

## RouterOS

### Cron scheduler

Buat scheduler yang memanggil endpoint cron. Token mode (default) paling mudah
dipakai dari RouterOS:

```routeros
/system scheduler
add name="billas-cron" interval=2m \
    on-event="/tool fetch url=\"https://billing.example.id/internal/cron/run\" \
http-method=post \
http-header-field=\"X-Cron-Token: YOUR_CRON_TOKEN,Content-Type: application/json\" \
http-data=\"{\\\"tasks\\\":[\\\"vouchers\\\",\\\"order_expire\\\",\\\"invoice_expire\\\",\\\"captive_sessions\\\",\\\"invoices\\\",\\\"isolir\\\",\\\"backup\\\",\\\"logs\\\"]}\" \
keep-result=no" \
    policy=read,write,policy,test \
    comment="Billas v4 cron driver"
```

- `job_state` membuat setiap task idempotent; memanggil lebih sering tidak
  menggandakan pekerjaan.
- Cron juga bisa dijalankan manual: `php bin/cron.php --force`.
- `CRON_HMAC_ENABLED=true` mewajibkan header `X-Cron-Signature` berbentuk
  `sha256=<HMAC-SHA256(raw body, CRON_TOKEN)>`. Gunakan mode ini hanya jika
  pemanggil cron Anda dapat menghitung HMAC.

### Hotspot RTRWNET

Contoh ilustratif; sesuaikan nama interface/profile dengan router Anda:

```routeros
/interface wireless
set [find default-name=wlan1] ssid=RTRWNET

/ip hotspot walled-garden
add dst-host=billing.example.id comment="Billas captive portal"

/ip hotspot profile
set [find name=hsprof1] login-by=http-chap,http-pap html-directory=hotspot

# Arahkan login.html MikroTik ke portal Billas:
# https://billing.example.id/wifi?router_id=1&ssid=RTRWNET&client_ip=$(ip)&client_mac=$(mac)&link_orig=$(link-orig)
```

Di `login.html` MikroTik, redirect dapat memakai:

```html
<meta http-equiv="refresh"
      content="0; url=https://billing.example.id/wifi?router_id=1&amp;ssid=RTRWNET&amp;client_ip=$(ip)&amp;client_mac=$(mac)">
```

### API MikroTik

- Buat user API khusus, misalnya `billing-api`, dengan izin minimum yang
  dibutuhkan untuk hotspot/PPPoE.
- Isi `MIKROTIK_HOST`, `MIKROTIK_PORT` (8728/8729), `MIKROTIK_USER`,
  `MIKROTIK_PASS`, dan `MIKROTIK_TLS` di `.env`, atau tambahkan router lewat
  **Admin → Router**.
- Password router dienkripsi AES-256-GCM dengan `ENCRYPTION_KEY`; jangan ganti
  key ini setelah ada data tanpa rencana re-enkripsi.
- Untuk uji lokal, `MIKROTIK_FAKE=true` membuat push disimulasikan.

## CLI

```bash
php bin/console migrate [--seed]      # migrasi additive/idempotent
php bin/console seed                  # admin, settings, job state, demo
php bin/console status                # DB, migrasi, queue, job state
php bin/console backup                # backup SQLite ke <DB_DIR>/backups
php bin/console cron:list             # daftar task + status terakhir
php bin/console cron:run --force     # jalankan semua task sekali
php bin/console cron:run --tasks=vouchers,order_expire
php bin/console qris:create <id> [gateway]
php bin/console qris:create --all [gateway]
php bin/console simulate:webhook <target> <id> --provider=tripay --event=PAID --mode=sign
php bin/worker.php --once            # kuras retry queue sekali
php bin/worker.php --health          # health probe worker
php bin/cron.php --force             # trigger cron via CLI
```

## Backup & Restore

Backup bawaan:

```bash
# Host / container
php bin/console backup

# Docker (file berada di volume /data)
docker compose exec billas-app php bin/console backup
docker compose cp billas-app:/data/backups/billas-YYYYmmdd-HHMMSS.db ./backup.db
```

- Lokasi lokal: `var/backups/`; di Docker: `/data/backups/`.
- Retensi otomatis: 14 hari.
- Backup melakukan `PRAGMA wal_checkpoint(TRUNCATE)` lalu menyalin file DB.
  Untuk jaminan konsistensi mutlak saat trafik tinggi, hentikan aplikasi
  sesaat atau gunakan snapshot volume.
- Cron `backup` menjalankan backup harian sesuai interval.

Restore:

```bash
docker compose stop billas-app billas-worker
# Ganti /data/billas.db dengan file backup, lalu:
docker compose start billas-app billas-worker
```

Pastikan izin file dimiliki UID `1000` (container menjalankan `chown` saat boot).

## Runbook Operasional

1. **Cek kesehatan**
   ```bash
   curl -fsS https://domain-anda/health
   curl -fsS https://domain-anda/ready
   docker compose ps
   ```
2. **Lihat log**
   ```bash
   docker compose logs -f --tail=200 billas-app
   docker compose logs -f --tail=200 billas-worker
   ```
3. **Cek antrean retry dan cron** — **Admin → Pengaturan** atau:
   ```bash
   php bin/console status
   php bin/console cron:list
   ```
4. **Rekonsiliasi QRIS statis / QRIS unik** — **Admin → Rekonsiliasi**:
   - Cocokkan nominal unik untuk order/transaksi QRIS statis.
   - Untuk akun QRIS unik aktif, isi nominal mutasi bank dan referensi mutasi.
   - Aksi ini memakai pipeline webhook yang sama (invoice, audit, retry
     MikroTik).
5. **Restart aman**
   ```bash
   docker compose restart billas-app billas-worker
   ```
6. **Upgrade**
   ```bash
   docker compose build --pull
   docker compose up -d
   docker compose logs -f --tail=100 billas-app
   ```
   Migrasi berjalan otomatis bila `AUTO_MIGRATE=1`; backup dulu untuk produksi.
7. **Ganti kredensial admin** — buat user baru/ubah password melalui mekanisme
   admin yang tersedia, lalu keluarkan sesi lama. Jangan mengandalkan nilai
   `ADMIN_PASSWORD` setelah database terisi; seed hanya membuat admin saat tabel
   `users` masih kosong.

## Testing & Pemeriksaan

```bash
composer test
# atau
vendor/bin/phpunit

# lint seluruh file PHP
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l

# PowerShell (Windows)
Get-ChildItem -Recurse -Filter *.php | Where-Object { $_.FullName -notmatch '\\vendor\\' } |
    ForEach-Object { php -l $_.FullName }
```

Test mencakup skema/migrasi, Fitur D (QRIS unik + garansi), Fitur E (captive
portal + voucher), Fitur F (webhook simulator: build, signature, masking,
middleware, rate limit, HTTP/CLI), kalkulasi kalender, QR util, crypto, dan
rekonsiliasi akun QRIS persisten.

## Struktur Proyek

```text
bin/                 console, cron.php, worker.php, preload.php
config/              env, database, logger, container
db/                  schema.sql + migrations additive
docker/              app + worker Dockerfiles, nginx, entrypoint
public/              front controller + css/js/img
src/
  App/               Application, Routes
  Controller/        admin, customer, captive, webhook, internal, health
  Database/          Migrator, Seeder
  Domain/            billing, voucher, captive, payment gateway, reconciliation
  Middleware/        auth, CSRF, cron auth, captive token, rate limit
  Support/           env, settings, view, crypto, session, helpers
tests/               unit + integration (PHPUnit 11)
views/               admin, customer, wifi, public, partials
var/                 DB lokal, backup, PID server dev
```

## Keamanan

- Semua form mutasi memakai POST + CSRF; session HttpOnly/SameSite.
- Webhook memverifikasi signature/token per provider dan idempotent lewat
  `webhook_events`.
- Simulator admin-only memakai `AuthMiddleware` + CSRF + rate limit, default
  OFF, dan diblokir di production kecuali diizinkan eksplisit.
- Cron memakai `X-Cron-Token` atau HMAC `X-Cron-Signature`; bandingkan dengan
  `hash_equals`.
- Password router dienkripsi AES-256-GCM; password pelanggan di-hash bcrypt.
- Query memakai prepared statement; output di-escape dengan `e()`.
- Production wajib `APP_DEBUG=false`, `APP_URL=https://...`, dan secret ≥16
  karakter. Jangan pernah commit `.env` atau file database.

## Troubleshooting

| Gejala | Periksa |
|---|---|
| `/ready` 503 | DB/schema belum dimigrasi, atau belum ada gateway terkonfigurasi. |
| Admin tidak bisa login | `ADMIN_USERNAME`/`ADMIN_PASSWORD` hanya dipakai saat tabel `users` kosong. |
| QR tidak muncul | Gateway belum dikonfigurasi atau `QRIS_STATIC_PAYLOAD` kosong di production. |
| Webhook 401 | Signature/token gateway tidak cocok; cek secret dan raw body. |
| Cron 401/503 | `CRON_TOKEN` kosong/salah, `CRON_HMAC_ENABLED` tidak sesuai, atau `CRON_ENABLED=false`. |
| Voucher tidak masuk MikroTik | Cek `retry_queue`, log worker, kredensial router, dan `MIKROTIK_FAKE`. |
| Captive portal 403 | `FEATURE_WIFI_CAPTIVE_PORTAL` mati atau SSID tidak ada di `WIFI_ALLOWED_SSIDS`. |
| Simulator 403 | `FEATURE_WEBHOOK_SIMULATOR=false`, atau production tanpa `WEBHOOK_SIMULATOR_ALLOW_IN_PRODUCTION=true`. |
| Simulator 422/401 | Signature mode `sign` tidak cocok, target/ID salah, atau gateway belum dikonfigurasi; cek riwayat run. |
| Container app gagal boot | `AUTO_MIGRATE=1` dan migrasi gagal; lihat `docker compose logs billas-app`. |

---

Billas v4 adalah revisi aditif dari Billas v3: perilaku lama dipertahankan,
fitur baru di-gate flag, dan migrasi dirancang idempotent agar database produksi
dapat ditingkatkan tanpa langkah destruktif.
