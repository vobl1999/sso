-- ============================================================================
-- Vobl ID · 统一账号与单点登录系统  数据库结构
-- MySQL 5.7+ / 8.0 / MariaDB 10.3+
-- 字符集统一 utf8mb4，排序规则 utf8mb4_unicode_ci（邮箱比对大小写不敏感）
--
-- 约定：
--   · 所有金额字段一律用「分」为单位的整数，绝不用浮点
--   · 所有时间字段一律用 Unix 时间戳（INT），避免时区问题
--   · 所有邮箱入库前必须小写化 + trim（由应用层负责）
--   · 本文件可重复执行（IF NOT EXISTS）
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------- 用户
CREATE TABLE IF NOT EXISTS `users` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`           VARCHAR(190) NOT NULL COMMENT '登录凭证，入库前必须小写化',
  `email_verified`  TINYINT(1) NOT NULL DEFAULT 0,
  `display_name`    VARCHAR(60) NOT NULL DEFAULT '',
  `password_hash`   VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'password_hash() 产物，含算法与盐',
  `algo`            VARCHAR(20) NOT NULL DEFAULT 'bcrypt' COMMENT '便于将来平滑升级算法',
  `avatar`          VARCHAR(255) NOT NULL DEFAULT '',
  `status`          ENUM('active','locked','disabled') NOT NULL DEFAULT 'active',
  `totp_secret`     VARCHAR(64) NOT NULL DEFAULT '',
  `totp_enabled`    TINYINT(1) NOT NULL DEFAULT 0,
  `failed_count`    INT NOT NULL DEFAULT 0,
  `locked_until`    INT NOT NULL DEFAULT 0,
  `last_login_at`   INT NOT NULL DEFAULT 0,
  `last_login_ip`   VARCHAR(45) NOT NULL DEFAULT '',
  `created_at`      INT NOT NULL,
  `updated_at`      INT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_email` (`email`),
  KEY `idx_users_status` (`status`),
  KEY `idx_users_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='账号主体。邮箱是唯一身份标识';

-- ---------------------------------------------------------------- 第三方身份绑定
CREATE TABLE IF NOT EXISTS `identities` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `provider`       VARCHAR(32) NOT NULL COMMENT 'microsoft | github | wechat | qq',
  `provider_uid`   VARCHAR(191) NOT NULL COMMENT 'sub / id / unionid，绝不能用邮箱或昵称',
  `email`          VARCHAR(190) NOT NULL DEFAULT '' COMMENT 'provider 返回的邮箱，可能为空',
  `email_verified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '只有 1 才允许自动合并账号',
  `nickname`       VARCHAR(80) NOT NULL DEFAULT '' COMMENT '仅用于展示，不能作为标识',
  `raw`            TEXT COMMENT '原始返回，排障用',
  `linked_at`      INT NOT NULL,
  `last_login_at`  INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ident_provider_uid` (`provider`, `provider_uid`),
  UNIQUE KEY `uk_ident_user_provider` (`user_id`, `provider`),
  KEY `idx_ident_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='第三方登录绑定关系';

-- ---------------------------------------------------------------- 邮箱验证码 / 找回密码
CREATE TABLE IF NOT EXISTS `email_challenges` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`       VARCHAR(190) NOT NULL,
  `user_id`     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `code_hash`   CHAR(64) NOT NULL COMMENT 'SHA-256 十六进制摘要，绝不存明文',
  `kind`        ENUM('verify_email','reset_password','change_email','login_code','bind_confirm')
                NOT NULL,
  `payload`     VARCHAR(255) NOT NULL DEFAULT '' COMMENT '改邮箱时存新地址等附加数据',
  `attempts`    INT NOT NULL DEFAULT 0 COMMENT '最多 5 次',
  `ip`          VARCHAR(45) NOT NULL DEFAULT '',
  `expires_at`  INT NOT NULL,
  `consumed_at` INT NOT NULL DEFAULT 0 COMMENT '非 0 表示已使用，一次性',
  `created_at`  INT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_chal_lookup` (`email`, `kind`, `consumed_at`),
  KEY `idx_chal_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='邮箱验证码类挑战，一次校验即作废';

-- ---------------------------------------------------------------- 发信配额
CREATE TABLE IF NOT EXISTS `email_quota` (
  `bucket`   VARCHAR(120) NOT NULL COMMENT 'mail:<email> / ip:<ip> / global:<Ymd>',
  `count`    INT NOT NULL DEFAULT 0,
  `reset_at` INT NOT NULL,
  PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='防止 SMTP 额度被刷爆';

-- ---------------------------------------------------------------- 会话
CREATE TABLE IF NOT EXISTS `sessions` (
  `id`          CHAR(32) NOT NULL,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `token_hash`  CHAR(64) NOT NULL COMMENT '只存摘要，明文只在 Cookie 里',
  `ip`          VARCHAR(45) NOT NULL DEFAULT '',
  `ua`          VARCHAR(255) NOT NULL DEFAULT '',
  `device`      VARCHAR(80) NOT NULL DEFAULT '',
  `created_at`  INT NOT NULL,
  `last_seen`   INT NOT NULL,
  `expires_at`  INT NOT NULL,
  `revoked_at`  INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sessions_token` (`token_hash`),
  KEY `idx_sessions_user` (`user_id`, `revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='登录会话，支持按设备吊销';

-- ---------------------------------------------------------------- 安全密钥 WebAuthn
CREATE TABLE IF NOT EXISTS `webauthn_credentials` (
  `id`            CHAR(32) NOT NULL,
  `user_id`       BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 表示注册流程中尚未归属',
  `credential_id` VARCHAR(512) NOT NULL COMMENT 'base64url 凭据 ID',
  `public_key`    TEXT NOT NULL COMMENT 'PEM 格式公钥',
  `alg`           INT NOT NULL DEFAULT -7,
  `counter`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `name`          VARCHAR(80) NOT NULL DEFAULT '',
  `aaguid`        CHAR(36) NOT NULL DEFAULT '',
  `transports`    VARCHAR(120) NOT NULL DEFAULT '',
  `backup_eligible` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`    INT NOT NULL,
  `last_used_at`  INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wacred_credid` (`credential_id`(255)),
  KEY `idx_wacred_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='安全密钥 / 通行密钥';

CREATE TABLE IF NOT EXISTS `webauthn_challenges` (
  `challenge`  CHAR(64) NOT NULL,
  `kind`       ENUM('register','login') NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` INT NOT NULL,
  PRIMARY KEY (`challenge`),
  KEY `idx_wachal_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='WebAuthn 挑战，用后即删';

-- ---------------------------------------------------------------- 恢复码
CREATE TABLE IF NOT EXISTS `recovery_codes` (
  `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`   BIGINT UNSIGNED NOT NULL,
  `code_hash` CHAR(64) NOT NULL,
  `used_at`   INT NOT NULL DEFAULT 0,
  `created_at` INT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_recovery_user` (`user_id`, `used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='邮箱丢失时的兜底，用后作废';

-- ---------------------------------------------------------------- 角色与权限
CREATE TABLE IF NOT EXISTS `roles` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`         VARCHAR(40) NOT NULL,
  `name`         VARCHAR(60) NOT NULL,
  `capabilities` TEXT NOT NULL COMMENT 'JSON 数组，能力位清单',
  `is_system`    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '系统角色不可删除',
  `created_at`   INT NOT NULL,
  `updated_at`   INT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_roles_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='角色=一组能力位，不硬编码角色名';

CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `role_id`    BIGINT UNSIGNED NOT NULL,
  `granted_by` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `granted_at` INT NOT NULL,
  PRIMARY KEY (`user_id`, `role_id`),
  KEY `idx_uroles_role` (`role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='用户与角色的多对多关系';

-- ---------------------------------------------------------------- 单点登录客户端
CREATE TABLE IF NOT EXISTS `oauth_clients` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`     VARCHAR(64) NOT NULL,
  `client_secret` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'password_hash 产物，机密客户端才有',
  `name`          VARCHAR(60) NOT NULL,
  `description`   VARCHAR(160) NOT NULL DEFAULT '',
  `redirect_uris` TEXT NOT NULL COMMENT 'JSON 数组，精确匹配白名单',
  `scopes`        VARCHAR(200) NOT NULL DEFAULT '["profile"]',
  `homepage`      VARCHAR(255) NOT NULL DEFAULT '',
  `icon`          VARCHAR(4) NOT NULL DEFAULT '',
  `accent`        VARCHAR(16) NOT NULL DEFAULT 'indigo',
  `type`          ENUM('confidential','public') NOT NULL DEFAULT 'confidential',
  `visible`       TINYINT(1) NOT NULL DEFAULT 1,
  `status`        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `created_by`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建者，普通用户只能管自己的应用',
  `created_at`    INT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_clients_clientid` (`client_id`),
  KEY `idx_clients_owner` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='接入的应用';

CREATE TABLE IF NOT EXISTS `oauth_codes` (
  `jti`         CHAR(32) NOT NULL,
  `client_id`   VARCHAR(64) NOT NULL,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `payload`     TEXT NOT NULL COMMENT '含 scope、redirect_uri、PKCE challenge',
  `expires_at`  INT NOT NULL,
  `consumed_at` INT NOT NULL DEFAULT 0 COMMENT '非 0 即已使用，防重放',
  PRIMARY KEY (`jti`),
  KEY `idx_codes_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `oauth_grants` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`  VARCHAR(64) NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `scopes`     VARCHAR(200) NOT NULL DEFAULT '',
  `granted_at` INT NOT NULL,
  `revoked_at` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_grant_client_user` (`client_id`, `user_id`),
  KEY `idx_grant_user` (`user_id`, `revoked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='授权同意记录，对应"已授权应用"列表';

-- ---------------------------------------------------------------- 支付通道
CREATE TABLE IF NOT EXISTS `payment_gateways` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`         VARCHAR(32) NOT NULL COMMENT '模块标识：epay / alipay / wechat / mock',
  `name`         VARCHAR(60) NOT NULL,
  `driver`       VARCHAR(32) NOT NULL COMMENT '对应 PHP 类',
  `config`       TEXT NOT NULL COMMENT 'JSON，含密钥。整表禁止导出到前端',
  `enabled`      TINYINT(1) NOT NULL DEFAULT 0,
  `is_default`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '全站只有一个默认通道',
  `sort_order`   INT NOT NULL DEFAULT 0,
  `note`         VARCHAR(200) NOT NULL DEFAULT '',
  `created_at`   INT NOT NULL,
  `updated_at`   INT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_gateway_code` (`code`),
  KEY `idx_gateway_enabled` (`enabled`, `is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='后台可绑定、切换的支付通道';

-- ---------------------------------------------------------------- 钱包
CREATE TABLE IF NOT EXISTS `wallets` (
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `balance`    BIGINT NOT NULL DEFAULT 0 COMMENT '余额，单位：分。绝不用浮点',
  `frozen`     BIGINT NOT NULL DEFAULT 0,
  `currency`   CHAR(3) NOT NULL DEFAULT 'CNY',
  `updated_at` INT NOT NULL,
  PRIMARY KEY (`user_id`),
  KEY `idx_wallet_balance` (`balance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='用户钱包余额';

CREATE TABLE IF NOT EXISTS `payment_orders` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `out_trade_no`  VARCHAR(40) NOT NULL COMMENT '我方订单号，唯一',
  `trade_no`      VARCHAR(80) NOT NULL DEFAULT '' COMMENT '支付平台订单号',
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `gateway_code`  VARCHAR(32) NOT NULL,
  `amount`        BIGINT NOT NULL COMMENT '金额，单位：分',
  `credited`      BIGINT NOT NULL DEFAULT 0 COMMENT '实际入账金额（可含赠送）',
  `subject`       VARCHAR(120) NOT NULL DEFAULT '',
  `kind`          ENUM('recharge','purchase') NOT NULL DEFAULT 'recharge',
  `status`        ENUM('pending','paid','failed','closed','refunded') NOT NULL DEFAULT 'pending',
  `notify_payload` TEXT COMMENT '异步通知原文，排障必备',
  `notify_count`  INT NOT NULL DEFAULT 0,
  `client_ip`     VARCHAR(45) NOT NULL DEFAULT '',
  `created_at`    INT NOT NULL,
  `paid_at`       INT NOT NULL DEFAULT 0,
  `expires_at`    INT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_outtradeno` (`out_trade_no`),
  KEY `idx_order_user` (`user_id`, `created_at`),
  KEY `idx_order_status` (`status`, `created_at`),
  KEY `idx_order_gateway` (`gateway_code`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='支付订单。金额一律整数分';

CREATE TABLE IF NOT EXISTS `wallet_transactions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     BIGINT UNSIGNED NOT NULL,
  `direction`   ENUM('in','out') NOT NULL,
  `amount`      BIGINT NOT NULL COMMENT '正数，方向由 direction 决定',
  `balance_after` BIGINT NOT NULL COMMENT '记账后余额，便于对账',
  `kind`        VARCHAR(32) NOT NULL COMMENT 'recharge / consume / refund / admin_adjust',
  `ref_id`      VARCHAR(64) NOT NULL DEFAULT '' COMMENT '关联订单号等',
  `remark`      VARCHAR(200) NOT NULL DEFAULT '',
  `operator`    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '管理员调整时记录操作人',
  `client_ip`   VARCHAR(45) NOT NULL DEFAULT '',
  `created_at`  INT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_wtx_user` (`user_id`, `created_at`),
  KEY `idx_wtx_kind` (`kind`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='钱包流水，只增不改（对账依据）';

-- ---------------------------------------------------------------- 配置 / 审计
CREATE TABLE IF NOT EXISTS `settings` (
  `key`        VARCHAR(64) NOT NULL,
  `value`      TEXT NOT NULL,
  `group_name` VARCHAR(32) NOT NULL DEFAULT 'general',
  `is_secret`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 表示回显时打码',
  `updated_at` INT NOT NULL,
  PRIMARY KEY (`key`),
  KEY `idx_settings_group` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='键值配置：SMTP、策略、站点信息等';

CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ts`         INT NOT NULL,
  `category`   VARCHAR(24) NOT NULL COMMENT 'auth / admin / payment / security',
  `action`     VARCHAR(48) NOT NULL,
  `actor_id`   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `actor`      VARCHAR(190) NOT NULL DEFAULT '',
  `target`     VARCHAR(120) NOT NULL DEFAULT '',
  `result`     ENUM('ok','fail','denied') NOT NULL DEFAULT 'ok',
  `ip`         VARCHAR(45) NOT NULL DEFAULT '',
  `ua`         VARCHAR(255) NOT NULL DEFAULT '',
  `detail`     VARCHAR(400) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_audit_ts` (`ts`),
  KEY `idx_audit_cat` (`category`, `ts`),
  KEY `idx_audit_actor` (`actor_id`, `ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='审计日志，敏感操作必留痕';

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `bucket`     VARCHAR(150) NOT NULL COMMENT 'ip:<ip> / user:<email>',
  `count`      INT NOT NULL DEFAULT 0,
  `reset_at`   INT NOT NULL,
  PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='登录失败计数，成功即清零';

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- 初始化数据
-- ============================================================================

-- 内置角色：能力位模型，加角色是配置而非改代码
INSERT INTO `roles` (`code`, `name`, `capabilities`, `is_system`, `created_at`, `updated_at`) VALUES
('super_admin', '超级管理员',
 '["*"]', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('app_admin', '应用管理员',
 '["dashboard.view","client.view","client.manage","audit.view","user.view","payment.view","payment.order.view"]',
 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('auditor', '审计员',
 '["dashboard.view","audit.view","audit.export","user.view","payment.order.view"]',
 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()),
('operator', '运营',
 '["dashboard.view","user.view","user.edit","payment.view","payment.order.view","payment.refund"]',
 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 站点基础配置
INSERT INTO `settings` (`key`, `value`, `group_name`, `is_secret`, `updated_at`) VALUES
('site.name',           'Vobl ID',                  'general',  0, UNIX_TIMESTAMP()),
('site.url',            'https://sso.vobl.cn',      'general',  0, UNIX_TIMESTAMP()),
('site.icp',            '',                         'general',  0, UNIX_TIMESTAMP()),
('mail.driver',         'smtp',                     'smtp',     0, UNIX_TIMESTAMP()),
('mail.host',           '',                         'smtp',     0, UNIX_TIMESTAMP()),
('mail.port',           '465',                      'smtp',     0, UNIX_TIMESTAMP()),
('mail.encryption',     'ssl',                      'smtp',     0, UNIX_TIMESTAMP()),
-- 认证方式：auto（自动尝试 PLAIN / LOGIN）/ login / plain / xoauth2
-- ★ Outlook / Hotmail / Microsoft 365 必须用 xoauth2 —— 微软已于 2026 年 4 月
--   彻底关闭 SMTP 基础认证，用账号密码会被直接拒绝（535 5.7.139）
('mail.auth_method',    'auto',                     'smtp',     0, UNIX_TIMESTAMP()),
('mail.username',       '',                         'smtp',     0, UNIX_TIMESTAMP()),
('mail.password',       '',                         'smtp',     1, UNIX_TIMESTAMP()),
('mail.oauth_client_id',     '',                    'smtp',     0, UNIX_TIMESTAMP()),
('mail.oauth_client_secret', '',                    'smtp',     1, UNIX_TIMESTAMP()),
('mail.oauth_tenant',        'consumers',           'smtp',     0, UNIX_TIMESTAMP()),
('mail.oauth_refresh_token', '',                    'smtp',     1, UNIX_TIMESTAMP()),
('mail.from_address',   '',                         'smtp',     0, UNIX_TIMESTAMP()),
('mail.from_name',      'Vobl ID',                  'smtp',     0, UNIX_TIMESTAMP()),
('mail.reply_to',       '',                         'smtp',     0, UNIX_TIMESTAMP()),
('mail.timeout',        '15',                       'smtp',     0, UNIX_TIMESTAMP()),
('mail.daily_limit',    '900',                      'smtp',     0, UNIX_TIMESTAMP()),
('mail.code_ttl',       '600',                      'smtp',     0, UNIX_TIMESTAMP()),
('security.password_min',      '10',   'security', 0, UNIX_TIMESTAMP()),
('security.session_ttl',       '604800','security',0, UNIX_TIMESTAMP()),
('security.require_2fa_admin', '0',    'security', 0, UNIX_TIMESTAMP()),
('security.webauthn_enabled',  '1',    'security', 0, UNIX_TIMESTAMP()),
('security.allow_register',    '1',    'security', 0, UNIX_TIMESTAMP()),
('security.cookie_remember',   '0',    'security', 0, UNIX_TIMESTAMP()),
('providers.enabled',   'email',                    'auth',     0, UNIX_TIMESTAMP()),
('payment.currency',    'CNY',                      'payment',  0, UNIX_TIMESTAMP()),
('payment.recharge_min','100',                      'payment',  0, UNIX_TIMESTAMP()),
('payment.recharge_max','1000000',                  'payment',  0, UNIX_TIMESTAMP()),
('payment.bonus_rate',  '0',                        'payment',  0, UNIX_TIMESTAMP()),
('payment.allow_manual','0',                        'payment',  0, UNIX_TIMESTAMP())
ON DUPLICATE KEY UPDATE `value` = `value`;
