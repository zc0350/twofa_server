-- 2FA 同步服务数据库初始化脚本（SQLite 版，DB_DRIVER=sqlite）
-- 由 app/Core/Database.php 首次连接自动执行（CREATE TABLE IF NOT EXISTS，幂等）；
-- 也可手动执行：sqlite3 runtime/twofa.sqlite < sql/init.sqlite.sql
--
-- 类型映射（相对 init.sql MySQL 版）：
--   BIGINT UNSIGNED AUTO_INCREMENT → INTEGER PRIMARY KEY AUTOINCREMENT
--   VARCHAR(190)/CHAR(32)/MEDIUMTEXT → TEXT
--   DATETIME → TEXT（存 'YYYY-MM-DD HH:MM:SS'，strtotime 兼容）
--   ENGINE=InnoDB/UNSIGNED 不支持，已去除

-- 用户表：仅身份认证（登录密码 bcrypt 哈希）
CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  email         TEXT    NOT NULL UNIQUE,
  password_hash TEXT    NOT NULL,
  created_at    TEXT    NOT NULL
);

-- 登录令牌（仅存 SHA-256 哈希，30 天有效）
CREATE TABLE IF NOT EXISTS user_tokens (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL,
  token_hash TEXT    NOT NULL UNIQUE,
  expires_at TEXT    NOT NULL,
  created_at TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_tokens_user ON user_tokens (user_id);

-- 设备登记（信息性记录）
CREATE TABLE IF NOT EXISTS devices (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id      INTEGER NOT NULL,
  device_id    TEXT    NOT NULL,
  last_seen_at TEXT    NOT NULL,
  UNIQUE (user_id, device_id)
);

-- 加密保险库：每用户一条记录；version 单调递增乐观并发版本号
CREATE TABLE IF NOT EXISTS vaults (
  user_id    INTEGER PRIMARY KEY,
  version    INTEGER NOT NULL DEFAULT 0,
  kdf_salt   TEXT    DEFAULT NULL,
  cipher     TEXT    NOT NULL,
  updated_at TEXT    NOT NULL
);

-- 历史版本归档（保留最近 10 个版本或 30 天）
CREATE TABLE IF NOT EXISTS vault_history (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL,
  version    INTEGER NOT NULL,
  kdf_salt   TEXT    DEFAULT NULL,
  cipher     TEXT    NOT NULL,
  created_at TEXT    NOT NULL,
  UNIQUE (user_id, version)
);