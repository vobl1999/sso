# Vobl ID · 统一账号系统

以**邮箱**为身份主体的账号系统：注册验证、登录、个人安全设置、单点登录（OAuth 2.0）、
充值支付，后台可管理全部功能。

**PHP + MySQL，零 Composer 依赖，上传即用。**

---

## 与之前版本的差异

| 项 | 变化 |
| --- | --- |
| 后端 | 从 Cloudflare Pages Functions（JS）改为 **PHP + MySQL** |
| 腾讯云登录 | **已移除** |
| 新功能 | 后台可配置 SMTP、开放平台充值支付、支付通道后台绑定切换、后台安全密钥登录 |
| 登录状态 | Cookie 保存（Cookie 名可配，库中只存令牌摘要）|
| 授权确认页 | 独立的微软风格决策页 |

---

## 它是什么

| 模块 | 说明 |
| --- | --- |
| **账号体系** | 邮箱归一化、注册验证、密码登录、验证码登录、找回密码、恢复码 |
| **二次验证** | 安全密钥（WebAuthn / 指纹 / 面容 / YubiKey）、TOTP 两步验证 |
| **单点登录** | OAuth 2.0 授权码模式 + PKCE、微软风格授权确认页、已授权应用管理 |
| **开放平台** | 用户自助创建应用、获取 client_id 与一次性密钥 |
| **充值支付** | 钱包余额、充值下单、异步通知入账、流水对账、退款 |
| **后台管理** | 用户、角色权限、邮件、支付通道、订单、余额、审计日志、系统设置 |
| **运维工具** | 命令行诊断、环境自检、紧急恢复 |

---

## 目录结构

```
vobl-id-php/
├── public/                          ★ Web 根目录必须指向这里
│   ├── index.php                    唯一入口
│   ├── .htaccess                    Apache 重写 + 安全规则
│   └── assets/{app.css, app.js}     设计系统与前端交互
├── app/
│   ├── bootstrap.php                引导：加载配置、密钥自检、异常兜底
│   ├── Core/                        框架层（无业务逻辑）
│   │   ├── Config.php               配置读取
│   │   ├── DB.php                   ★ 数据访问层，强制参数化查询
│   │   ├── Crypto.php               哈希 / 加密 / 签名 / 密码
│   │   ├── Request.php              输入解析与净化
│   │   ├── Response.php            输出与安全响应头
│   │   ├── Router.php               路由（支持 {param}）
│   │   ├── Auth.php                 会话与权限（能力位模型）
│   │   ├── Csrf.php                 CSRF 三层防护
│   │   ├── RateLimit.php            限流（含发信三重限流）
│   │   ├── Audit.php                审计日志（自动脱敏）
│   │   ├── View.php / helpers.php   模板渲染与视图辅助
│   ├── Services/                    业务服务
│   │   ├── UserService.php          账号、验证码、第三方绑定
│   │   ├── Mailer.php               ★ 纯 socket SMTP 客户端
│   │   ├── WebAuthn.php             ★ 手写 CBOR/COSE/DER
│   │   ├── Wallet.php               钱包与资金（幂等入账）
│   │   ├── Settings.php             运行时配置（加密存储）
│   │   └── Payment/                 支付通道（可插拔）
│   │       ├── GatewayInterface.php 通道契约
│   │       ├── GatewayManager.php   绑定与切换
│   │       ├── EpayGateway.php      易支付
│   │       ├── AlipayGateway.php    支付宝
│   │       ├── WechatGateway.php    微信支付
│   │       └── MockGateway.php      模拟（测试用）
│   ├── Controllers/                 控制器
│   └── Views/                       模板（23 个）
├── config/
│   ├── config.sample.php            部署配置模板
│   └── mail.local.php               本地邮件配置（gitignored）
├── sql/schema.mysql.sql             表结构 + 初始数据
├── tools/
│   ├── cli.php                      ★ 命令行工具集
│   ├── test.php                     ★ 核心逻辑自测（174 项断言）
│   ├── schema.sqlite.sql            测试用表结构
│   └── setup-sqlite.php             本地 SQLite 环境
├── dev-router.php                   本地开发路由（须在 docroot 之外）
├── nginx.conf.example               宝塔伪静态规则
└── DEPLOY.md                        ★ 部署指南（先看这个）
```

---

## 本地跑起来

```bash
# 1. 初始化 SQLite 开发库（含演示账号）
php tools/setup-sqlite.php --fresh

# 2. 启动
php -S 127.0.0.1:8899 -t public dev-router.php

# 3. 打开 http://127.0.0.1:8899
#    演示账号：demo@vobl.local / Demo-Passw0rd!23（超级管理员）
```

> ⚠️ `dev-router.php` **必须放在项目根目录**，不能放进 `public/`。
> 放进 docroot 里的话，PHP 内置服务器会把它当静态文件直接输出（浏览器里看到源码）。

### Windows 便携版 PHP 的一个坑

独立版 PHP 若报 `configuration file routines::no such file`，
说明缺 `openssl.cnf`。解决办法：

```bash
export OPENSSL_CONF="C:\path\to\php\openssl.cnf"
```

Linux 下通常无此问题。生产环境（宝塔）也不会遇到。

---

## 测试

```bash
# 核心逻辑自测：174 项断言，覆盖 SQL 注入、加密、限流、幂等入账等
php tools/test.php

# 环境自检
php tools/cli.php env:check

# 邮件诊断（配置、连通性、认证、限额一次查完）
php tools/cli.php mail:diagnose
```

测试重点是**验证安全机制真的能挡住攻击**，而不只是「语法没写错」。例如：

- 7 种 SQL 注入 payload 被原样当作数据存入，表结构完好无损
- 篡改的签名 / 过期载荷 / 用错密钥全部被拒绝
- 授权码与验证码重放被拦截
- **支付回调重复通知不重复入账**（幂等）
- 回调金额与订单不符时拒绝入账
- 余额不足时扣款与退款都被拒绝（不会出现负数余额）
- 未验证邮箱的第三方登录不自动合并账号
- 审计日志自动脱敏密码 / 密钥 / 令牌 / 验证码

---

## 命令行工具

```bash
php tools/cli.php                     # 查看全部命令

# 邮件
php tools/cli.php mail:apply          # 应用 config/mail.local.php
php tools/cli.php mail:show           # 查看当前配置（密钥隐藏）
php tools/cli.php mail:test           # 测试连接与认证
php tools/cli.php mail:send 邮箱      # 发测试邮件
php tools/cli.php mail:diagnose       # 综合诊断
php tools/cli.php mail:oauth-help     # 微软邮箱 OAuth2 步骤

# 运维
php tools/cli.php user:list
php tools/cli.php user:promote 邮箱   # 紧急恢复管理员
php tools/cli.php order:close-expired
php tools/cli.php audit:prune 180
php tools/cli.php env:check
```

---

## 安全设计

### SQL 注入防护（三层）

1. **连接层**：`ATTR_EMULATE_PREPARES = false`。SQL 语句与参数分两次发给 MySQL，
   参数值永远不会被当作 SQL 语法解析 —— 这是根本
2. **访问层**：`DB` 类只提供占位符入口，**不提供任何「把变量拼进 SQL」的方法**。
   表名/列名/ORDER BY 字段这类无法用占位符的位置，必须经过 `identifier()` 白名单正则校验
3. **验证层**：`tools/test.php` 用 7 种注入 payload 实测，断言表结构完好、数据原样存入

### 其他安全机制

| 机制 | 实现 |
| --- | --- |
| 密码 | `password_hash()` + cost 12，支持算法平滑升级 |
| 会话 | Cookie 存明文令牌，库里只存 SHA-256 摘要；改密码即踢掉全部会话 |
| CSRF | 已登录用 HMAC 派生令牌；未登录用双重提交 Cookie；写操作强制校验 |
| 会话固定 | 登录成功前先作废旧会话 |
| 二次验证 | 改密码/换邮箱/绑删密钥/提现等敏感操作需重新验证身份 |
| 权限 | 能力位模型；**管理员不能给自己授权**；**不能取消最后一个超管**（服务端强制）|
| 限流 | 登录失败计数（成功即清零）、发信三重限流、全站日上限 |
| 审计 | 只增不改，敏感字段自动脱敏，不提供删除接口 |
| 敏感配置 | SMTP 密码与支付密钥 AES-256-GCM 加密存储，界面只回显占位符 |
| 支付 | 金额以服务端订单为准、回调验签、入账幂等、金额比对、不用浮点 |
| 输出 | 所有用户数据经 `e()` 转义；CSP 限制脚本仅同源 |
| 开放重定向 | `safeNext()` 只接受站内相对路径 |
| CSV 注入 | 导出时对 `=+-@` 开头的单元格加前缀（防 Excel 公式执行）|

### 已知边界

- 不签发 `id_token`（纯 OAuth 2.0，取身份用 `/oauth/userinfo`）
- 微信 / QQ 不返回邮箱，只能手动绑定，不能自动合并账号
- 微软邮箱发信需 OAuth2（微软已于 2026 年 4 月关闭 SMTP 基础认证）

---

## 支付通道扩展

加一个新通道只需两步：

1. 实现 `GatewayInterface`（`createPayment` / `verifyNotify` / `parseNotify` / `notifyAck` / `configSchema`）
2. 在 `GatewayManager::MAP` 里注册一行

后台的配置表单由 `configSchema()` 自动生成，**不需要改控制器或模板**。

三条不可妥协的底线（接口注释里也写了）：

- **金额以服务端订单为准**，回调金额只用来比对
- **回调必须验签**，验签失败一律丢弃
- **入账必须幂等**，同一订单只允许入账一次

---

## 部署

看 [`DEPLOY.md`](./DEPLOY.md)。

三件事必须记住：

1. **站点根目录指向 `public/`**
2. **`app_secret` 与 `config_key` 必须新生成**（不能用示例值）
3. **`install.allow_bootstrap_admin` 建完管理员后改回 `false`**
