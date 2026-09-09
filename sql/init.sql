-- 2FA 同步服务数据库初始化脚本（MySQL 5.7+ / 8.0）
CREATE DATABASE IF NOT EXISTS twofa DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE twofa;

-- 用户表：仅身份认证（登录密码 bcrypt 哈希）。
-- 数据加密不依赖用户表：KDF 盐由客户端生成，随密文保存于 vaults / vault_history
-- （盐与密文一一配对，见 vault_history 注释）。
CREATE TABLE IF NOT EXISTS users (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME     NOT NULL
) ENGINE = InnoDB;

-- 登录令牌（仅存 SHA-256 哈希，30 天有效）
CREATE TABLE IF NOT EXISTS user_tokens (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_tokens_user (user_id)
) ENGINE = InnoDB;

-- 设备登记（信息性记录，便于后续设备管理）
CREATE TABLE IF NOT EXISTS devices (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  device_id    VARCHAR(64) NOT NULL,
  last_seen_at DATETIME NOT NULL,
  UNIQUE KEY uk_user_device (user_id, device_id)
) ENGINE = InnoDB;

-- 加密保险库：每用户一条记录；服务器无法解读 cipher 内容
-- version 为单调递增的乐观并发版本号，push 携带 baseVersion 做冲突检测
-- kdf_salt 由客户端生成（非机密），用于从「同步密码」派生端到端加密密钥；
-- 同步密码永不出设备，服务器零知识——新设备验证方式为"解密成功即密码正确"
CREATE TABLE IF NOT EXISTS vaults (
  user_id    BIGINT UNSIGNED PRIMARY KEY,
  version    INT UNSIGNED NOT NULL DEFAULT 0,
  kdf_salt   CHAR(32)     DEFAULT NULL,
  cipher     MEDIUMTEXT   NOT NULL,
  updated_at DATETIME     NOT NULL
) ENGINE = InnoDB;

-- 历史版本归档（服务端安全网）：push 覆盖前归档旧密文，保留最近 10 个版本或 30 天。
-- 用途：客户端合并缺陷/数据损坏导致坏快照覆盖时可整体回滚（管理员经 SQL 恢复）。
-- 内容仍为端到端密文，零知识属性不变。
CREATE TABLE IF NOT EXISTS vault_history (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT UNSIGNED NOT NULL,
  version    INT UNSIGNED NOT NULL,
  kdf_salt   CHAR(32)     DEFAULT NULL,
  cipher     MEDIUMTEXT   NOT NULL,
  created_at DATETIME     NOT NULL,
  UNIQUE KEY uk_user_version (user_id, version)
) ENGINE = InnoDB;
