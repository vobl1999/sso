-- ============================================================================
-- 测试用表结构（SQLite）
-- ----------------------------------------------------------------------------
-- 仅用于本地自动化测试，与 sql/schema.mysql.sql 保持相同的表名与列名。
-- 差异只是 SQLite 不支持 ENUM / UNSIGNED / AUTO_INCREMENT 这些 MySQL 特有的写法。
-- 生产环境请用 schema.mysql.sql。
-- ============================================================================

CREATE TABLE IF NOT EXISTS users (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  email           TEXT NOT NULL UNIQUE,
  email_verified  INTEGER NOT NULL DEFAULT 0,
  display_name    TEXT NOT NULL DEFAULT '',
  password_hash   TEXT NOT NULL DEFAULT '',
  algo            TEXT NOT NULL DEFAULT 'bcrypt',
  avatar          TEXT NOT NULL DEFAULT '',
  status          TEXT NOT NULL DEFAULT 'active',
  totp_secret     TEXT NOT NULL DEFAULT '',
  totp_enabled    INTEGER NOT NULL DEFAULT 0,
  failed_count    INTEGER NOT NULL DEFAULT 0,
  locked_until    INTEGER NOT NULL DEFAULT 0,
  last_login_at   INTEGER NOT NULL DEFAULT 0,
  last_login_ip   TEXT NOT NULL DEFAULT '',
  created_at      INTEGER NOT NULL,
  updated_at      INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS identities (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id         INTEGER NOT NULL,
  provider        TEXT NOT NULL,
  provider_uid    TEXT NOT NULL,
  email           TEXT NOT NULL DEFAULT '',
  email_verified  INTEGER NOT NULL DEFAULT 0,
  nickname        TEXT NOT NULL DEFAULT '',
  raw             TEXT,
  linked_at       INTEGER NOT NULL,
  last_login_at   INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX IF NOT EXISTS uk_ident_provider_uid ON identities (provider, provider_uid);
CREATE UNIQUE INDEX IF NOT EXISTS uk_ident_user_provider ON identities (user_id, provider);

CREATE TABLE IF NOT EXISTS email_challenges (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  email        TEXT NOT NULL,
  user_id      INTEGER NOT NULL DEFAULT 0,
  code_hash    TEXT NOT NULL,
  kind         TEXT NOT NULL,
  payload      TEXT NOT NULL DEFAULT '',
  attempts     INTEGER NOT NULL DEFAULT 0,
  ip           TEXT NOT NULL DEFAULT '',
  expires_at   INTEGER NOT NULL,
  consumed_at  INTEGER NOT NULL DEFAULT 0,
  created_at   INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS email_quota (
  bucket   TEXT PRIMARY KEY,
  count    INTEGER NOT NULL DEFAULT 0,
  reset_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS sessions (
  id          TEXT PRIMARY KEY,
  user_id     INTEGER NOT NULL,
  token_hash  TEXT NOT NULL UNIQUE,
  ip          TEXT NOT NULL DEFAULT '',
  ua          TEXT NOT NULL DEFAULT '',
  device      TEXT NOT NULL DEFAULT '',
  created_at  INTEGER NOT NULL,
  last_seen   INTEGER NOT NULL,
  expires_at  INTEGER NOT NULL,
  revoked_at  INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS webauthn_credentials (
  id               TEXT PRIMARY KEY,
  user_id          INTEGER NOT NULL DEFAULT 0,
  credential_id    TEXT NOT NULL UNIQUE,
  public_key       TEXT NOT NULL,
  alg              INTEGER NOT NULL DEFAULT -7,
  counter          INTEGER NOT NULL DEFAULT 0,
  name             TEXT NOT NULL DEFAULT '',
  aaguid           TEXT NOT NULL DEFAULT '',
  transports       TEXT NOT NULL DEFAULT '',
  backup_eligible  INTEGER NOT NULL DEFAULT 0,
  created_at       INTEGER NOT NULL,
  last_used_at     INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS webauthn_challenges (
  challenge  TEXT PRIMARY KEY,
  kind       TEXT NOT NULL,
  user_id    INTEGER NOT NULL DEFAULT 0,
  expires_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS recovery_codes (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL,
  code_hash  TEXT NOT NULL,
  used_at    INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS roles (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  code         TEXT NOT NULL UNIQUE,
  name         TEXT NOT NULL,
  capabilities TEXT NOT NULL,
  is_system    INTEGER NOT NULL DEFAULT 0,
  created_at   INTEGER NOT NULL,
  updated_at   INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS user_roles (
  user_id    INTEGER NOT NULL,
  role_id    INTEGER NOT NULL,
  granted_by INTEGER NOT NULL DEFAULT 0,
  granted_at INTEGER NOT NULL,
  PRIMARY KEY (user_id, role_id)
);

CREATE TABLE IF NOT EXISTS oauth_clients (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id     TEXT NOT NULL UNIQUE,
  client_secret TEXT NOT NULL DEFAULT '',
  name          TEXT NOT NULL,
  description   TEXT NOT NULL DEFAULT '',
  redirect_uris TEXT NOT NULL,
  scopes        TEXT NOT NULL DEFAULT '["profile"]',
  homepage      TEXT NOT NULL DEFAULT '',
  icon          TEXT NOT NULL DEFAULT '',
  accent        TEXT NOT NULL DEFAULT 'indigo',
  type          TEXT NOT NULL DEFAULT 'confidential',
  visible       INTEGER NOT NULL DEFAULT 1,
  status        TEXT NOT NULL DEFAULT 'active',
  created_by    INTEGER NOT NULL DEFAULT 0,
  created_at    INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS oauth_codes (
  jti         TEXT PRIMARY KEY,
  client_id   TEXT NOT NULL,
  user_id     INTEGER NOT NULL,
  payload     TEXT NOT NULL,
  expires_at  INTEGER NOT NULL,
  consumed_at INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS oauth_grants (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id  TEXT NOT NULL,
  user_id    INTEGER NOT NULL,
  scopes     TEXT NOT NULL DEFAULT '',
  granted_at INTEGER NOT NULL,
  revoked_at INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS payment_gateways (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  code       TEXT NOT NULL UNIQUE,
  name       TEXT NOT NULL,
  driver     TEXT NOT NULL,
  config     TEXT NOT NULL,
  enabled    INTEGER NOT NULL DEFAULT 0,
  is_default INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  note       TEXT NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS wallets (
  user_id    INTEGER PRIMARY KEY,
  balance    INTEGER NOT NULL DEFAULT 0,
  frozen     INTEGER NOT NULL DEFAULT 0,
  currency   TEXT NOT NULL DEFAULT 'CNY',
  updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS payment_orders (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  out_trade_no   TEXT NOT NULL UNIQUE,
  trade_no       TEXT NOT NULL DEFAULT '',
  user_id        INTEGER NOT NULL,
  gateway_code   TEXT NOT NULL,
  amount         INTEGER NOT NULL,
  credited       INTEGER NOT NULL DEFAULT 0,
  subject        TEXT NOT NULL DEFAULT '',
  kind           TEXT NOT NULL DEFAULT 'recharge',
  status         TEXT NOT NULL DEFAULT 'pending',
  notify_payload TEXT,
  notify_count   INTEGER NOT NULL DEFAULT 0,
  client_ip      TEXT NOT NULL DEFAULT '',
  created_at     INTEGER NOT NULL,
  paid_at        INTEGER NOT NULL DEFAULT 0,
  expires_at     INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS wallet_transactions (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id       INTEGER NOT NULL,
  direction     TEXT NOT NULL,
  amount        INTEGER NOT NULL,
  balance_after INTEGER NOT NULL,
  kind          TEXT NOT NULL,
  ref_id        TEXT NOT NULL DEFAULT '',
  remark        TEXT NOT NULL DEFAULT '',
  operator      INTEGER NOT NULL DEFAULT 0,
  client_ip     TEXT NOT NULL DEFAULT '',
  created_at    INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
  key        TEXT PRIMARY KEY,
  value      TEXT NOT NULL,
  group_name TEXT NOT NULL DEFAULT 'general',
  is_secret  INTEGER NOT NULL DEFAULT 0,
  updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS audit_logs (
  id       INTEGER PRIMARY KEY AUTOINCREMENT,
  ts       INTEGER NOT NULL,
  category TEXT NOT NULL,
  action   TEXT NOT NULL,
  actor_id INTEGER NOT NULL DEFAULT 0,
  actor    TEXT NOT NULL DEFAULT '',
  target   TEXT NOT NULL DEFAULT '',
  result   TEXT NOT NULL DEFAULT 'ok',
  ip       TEXT NOT NULL DEFAULT '',
  ua       TEXT NOT NULL DEFAULT '',
  detail   TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS login_attempts (
  bucket   TEXT PRIMARY KEY,
  count    INTEGER NOT NULL DEFAULT 0,
  reset_at INTEGER NOT NULL
);

-- 初始角色
INSERT OR IGNORE INTO roles (code, name, capabilities, is_system, created_at, updated_at) VALUES
('super_admin', '超级管理员', '["*"]', 1, 0, 0),
('auditor', '审计员', '["dashboard.view","audit.view","user.view"]', 1, 0, 0);
