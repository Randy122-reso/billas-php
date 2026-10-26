PRAGMA journal_mode = WAL;
PRAGMA busy_timeout = 5000;
PRAGMA foreign_keys = ON;

-- ============================================================
-- Billas v4 schema (v1/v2/v3 + additive Fitur D & E)
-- ============================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  migration TEXT NOT NULL UNIQUE,
  applied_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT UNIQUE NOT NULL,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'admin' CHECK (role IN ('admin','superadmin')),
  active INTEGER NOT NULL DEFAULT 1,
  created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS routers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  host TEXT NOT NULL,
  port INTEGER NOT NULL DEFAULT 8728,
  username TEXT NOT NULL,
  password_enc TEXT NOT NULL,
  use_tls INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS packages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  type TEXT NOT NULL CHECK (type IN ('hotspot','pppoe')),
  price INTEGER NOT NULL CHECK (price >= 1000),
  duration_value INTEGER,
  duration_unit TEXT CHECK (duration_unit IN ('minutes','hours','days') OR duration_unit IS NULL),
  duration_mode TEXT NOT NULL DEFAULT 'uptime' CHECK (duration_mode IN ('uptime','calendar')),
  calendar_days INTEGER,
  mikrotik_profile TEXT NOT NULL,
  bandwidth_down INTEGER,
  bandwidth_up INTEGER,
  active INTEGER NOT NULL DEFAULT 1,
  available_on_captive INTEGER NOT NULL DEFAULT 1,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_packages_type_active ON packages(type, active);

CREATE TABLE IF NOT EXISTS customers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT UNIQUE NOT NULL,
  name TEXT NOT NULL,
  phone TEXT,
  email TEXT,
  pppoe_username TEXT UNIQUE,
  pppoe_password TEXT,
  package_id INTEGER REFERENCES packages(id),
  router_id INTEGER REFERENCES routers(id),
  portal_password_hash TEXT,
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','isolir','inactive')),
  paid_until DATE,
  paid_until_updated_at INTEGER,
  service_guaranteed_until DATE,
  service_guaranteed_updated_at INTEGER,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_customers_status ON customers(status);
CREATE INDEX IF NOT EXISTS idx_customers_paid_until ON customers(paid_until);
CREATE INDEX IF NOT EXISTS idx_customers_guaranteed ON customers(service_guaranteed_until);

CREATE TABLE IF NOT EXISTS orders (
  id TEXT PRIMARY KEY,
  package_id INTEGER NOT NULL REFERENCES packages(id),
  amount INTEGER NOT NULL,
  gateway TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','processing','paid','expired','failed')),
  merchant_ref TEXT UNIQUE NOT NULL,
  gateway_reference TEXT,
  gateway_qr_string TEXT,
  gateway_payload TEXT,
  expires_at INTEGER NOT NULL,
  paid_at INTEGER,
  source TEXT NOT NULL DEFAULT 'web_portal' CHECK (source IN ('web_portal','captive_portal')),
  ssid TEXT,
  client_ip TEXT,
  client_mac TEXT,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_orders_merchant_ref ON orders(merchant_ref);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);
CREATE INDEX IF NOT EXISTS idx_orders_created ON orders(created_at);
CREATE INDEX IF NOT EXISTS idx_orders_source_ssid ON orders(source, ssid);
CREATE INDEX IF NOT EXISTS idx_orders_client_mac ON orders(client_mac) WHERE client_mac IS NOT NULL;

CREATE TABLE IF NOT EXISTS customer_payment_accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER NOT NULL REFERENCES customers(id) ON DELETE CASCADE,
  gateway TEXT NOT NULL CHECK (gateway IN ('midtrans','xendit','duitku','qris_static')),
  account_type TEXT NOT NULL CHECK (account_type IN ('va','qris')),
  external_ref TEXT NOT NULL,
  qris_unique_ref TEXT,
  qr_string TEXT,
  payload TEXT,
  active INTEGER NOT NULL DEFAULT 1,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  UNIQUE (customer_id, gateway, account_type)
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_cpa_external
  ON customer_payment_accounts(gateway, external_ref)
  WHERE external_ref IS NOT NULL AND active = 1;
CREATE UNIQUE INDEX IF NOT EXISTS idx_cpa_qris_unique
  ON customer_payment_accounts(gateway, qris_unique_ref)
  WHERE qris_unique_ref IS NOT NULL AND active = 1;

CREATE TABLE IF NOT EXISTS invoices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_no TEXT UNIQUE NOT NULL,
  customer_id INTEGER NOT NULL REFERENCES customers(id),
  period TEXT NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  amount INTEGER NOT NULL CHECK (amount >= 0),
  due_date DATE NOT NULL,
  status TEXT NOT NULL DEFAULT 'unpaid' CHECK (status IN ('unpaid','paid','void','expired')),
  source TEXT NOT NULL DEFAULT 'monthly' CHECK (source IN ('monthly','prepaid','manual')),
  paid_at INTEGER,
  paid_via_account_id INTEGER REFERENCES customer_payment_accounts(id),
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_invoices_customer_period ON invoices(customer_id, period);
CREATE INDEX IF NOT EXISTS idx_invoices_status_due ON invoices(status, due_date);
CREATE UNIQUE INDEX IF NOT EXISTS idx_invoices_customer_monthly
  ON invoices(customer_id, period) WHERE source = 'monthly';

CREATE TABLE IF NOT EXISTS vouchers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id TEXT NOT NULL UNIQUE REFERENCES orders(id),
  code TEXT NOT NULL UNIQUE,
  password TEXT NOT NULL,
  mikrotik_profile TEXT NOT NULL,
  duration_value INTEGER NOT NULL,
  duration_unit TEXT NOT NULL,
  duration_mode TEXT NOT NULL DEFAULT 'uptime' CHECK (duration_mode IN ('uptime','calendar')),
  expires_at INTEGER,
  revoked INTEGER NOT NULL DEFAULT 0,
  revoked_at INTEGER,
  revoke_reason TEXT,
  router_id INTEGER REFERENCES routers(id),
  pushed_to_router INTEGER NOT NULL DEFAULT 0,
  push_error TEXT,
  source TEXT NOT NULL DEFAULT 'web_portal' CHECK (source IN ('web_portal','captive_portal')),
  ssid TEXT,
  client_ip TEXT,
  client_mac TEXT,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_vouchers_pushed ON vouchers(pushed_to_router);
CREATE INDEX IF NOT EXISTS idx_vouchers_expires ON vouchers(duration_mode, revoked, expires_at);
CREATE INDEX IF NOT EXISTS idx_vouchers_source_ssid ON vouchers(source, ssid);
CREATE INDEX IF NOT EXISTS idx_vouchers_created ON vouchers(created_at);

CREATE TABLE IF NOT EXISTS payment_transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_id INTEGER REFERENCES invoices(id),
  gateway TEXT NOT NULL,
  merchant_ref TEXT UNIQUE NOT NULL,
  gateway_reference TEXT,
  amount INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','paid','expired','failed')),
  qr_string TEXT,
  payload TEXT,
  paid_at INTEGER,
  expires_at INTEGER NOT NULL,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_payment_tx_invoice ON payment_transactions(invoice_id);
CREATE INDEX IF NOT EXISTS idx_payment_tx_status ON payment_transactions(status);

CREATE TABLE IF NOT EXISTS captive_portal_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_token TEXT UNIQUE NOT NULL,
  router_id INTEGER REFERENCES routers(id),
  ssid TEXT NOT NULL,
  client_ip TEXT,
  client_mac TEXT,
  package_id INTEGER REFERENCES packages(id),
  order_id TEXT REFERENCES orders(id),
  voucher_id INTEGER REFERENCES vouchers(id),
  status TEXT NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending','paid','expired','cancelled','revoked')),
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL,
  expires_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_cps_status ON captive_portal_sessions(status);
CREATE INDEX IF NOT EXISTS idx_cps_mac ON captive_portal_sessions(client_mac);
CREATE INDEX IF NOT EXISTS idx_cps_created ON captive_portal_sessions(created_at);
CREATE INDEX IF NOT EXISTS idx_cps_order ON captive_portal_sessions(order_id);

CREATE TABLE IF NOT EXISTS webhook_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  provider TEXT NOT NULL,
  event_key TEXT UNIQUE NOT NULL,
  merchant_ref TEXT,
  status TEXT NOT NULL DEFAULT 'received'
    CHECK (status IN ('received','processed','duplicate','unmatched','ignored','failed')),
  payload TEXT NOT NULL,
  note TEXT,
  received_at INTEGER NOT NULL,
  processed_at INTEGER
);
CREATE INDEX IF NOT EXISTS idx_webhook_merchant ON webhook_events(merchant_ref);
CREATE INDEX IF NOT EXISTS idx_webhook_received ON webhook_events(received_at);

CREATE TABLE IF NOT EXISTS retry_queue (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  task_type TEXT NOT NULL,
  payload TEXT NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 0,
  max_attempts INTEGER NOT NULL DEFAULT 10,
  next_attempt_at INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending'
    CHECK (status IN ('pending','processing','done','failed')),
  locked_at INTEGER,
  last_error TEXT,
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_retry_due ON retry_queue(status, next_attempt_at);
CREATE INDEX IF NOT EXISTS idx_retry_type ON retry_queue(task_type);

CREATE TABLE IF NOT EXISTS audit_trail (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  actor_type TEXT NOT NULL,
  actor_id TEXT NOT NULL,
  action TEXT NOT NULL,
  entity_type TEXT,
  entity_id TEXT,
  meta TEXT,
  ip TEXT,
  created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_trail(action);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_trail(created_at);

CREATE TABLE IF NOT EXISTS sessions (
  id TEXT PRIMARY KEY,
  data TEXT NOT NULL,
  last_activity INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_activity ON sessions(last_activity);

CREATE TABLE IF NOT EXISTS rate_limits (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  bucket TEXT NOT NULL,
  key TEXT NOT NULL,
  window_start INTEGER NOT NULL,
  count INTEGER NOT NULL DEFAULT 0,
  UNIQUE(bucket, key, window_start)
);
CREATE INDEX IF NOT EXISTS idx_rate_window ON rate_limits(window_start);

CREATE TABLE IF NOT EXISTS job_state (
  task TEXT PRIMARY KEY,
  last_run_at INTEGER NOT NULL DEFAULT 0,
  last_result TEXT,
  updated_at INTEGER NOT NULL DEFAULT 0
);
