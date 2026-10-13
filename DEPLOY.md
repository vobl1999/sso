# 部署指南 · PHP 版

从零到上线，大约 20 分钟。**站点根目录必须指向 `public/`** —— 这是全文最重要的一句话。

---

## ⚠️ 动手前先看这两条

### 1. 腾讯云相关功能已移除

按要求去掉了腾讯云登录。现在保留的登录方式是：

| 方式 | 状态 | 能否自动并入已有账号 |
| --- | --- | --- |
| 邮箱 + 密码 | ✅ 默认启用 | — （它就是身份主体）|
| 邮箱验证码 | ✅ 默认启用 | — |
| 安全密钥 / 指纹 | ✅ 默认启用 | — |
| 微软账号 | ⭕ 需在后台开启 | ✅ 能（返回已验证邮箱）|
| GitHub | ⭕ 需在后台开启 | ✅ 能（需 `user:email` 权限）|
| 微信 / QQ | ⭕ 需在后台开启 | ❌ 不能（不返回邮箱，只能手动绑定）|

### 2. 微软邮箱发信已不可用（如果打算用 Outlook）

微软已于 **2026 年 4 月**彻底关闭 SMTP 基础认证。`smtp-mail.outlook.com:587` + 账号密码
现在会被直接拒绝，报 `535 5.7.139 basic authentication is disabled`。
Outlook 的应用专用密码也已随之下线。

**两个选择**：

- **改用其它发信服务（推荐，最省事）**：腾讯企业邮、阿里云邮件推送、网易企业邮、
  Resend、Brevo、SendGrid 都支持基础认证，填账号 + 授权码即可
- **继续用微软邮箱**：在后台把认证方式改成 **OAuth2**，按下面「附：微软邮箱 OAuth2」操作

---

## 第 0 步 · 环境要求

| 项目 | 要求 |
| --- | --- |
| PHP | 7.4 或更高（推荐 8.1+） |
| 必需扩展 | `pdo` `pdo_mysql` `openssl` `mbstring` `json` |
| 建议扩展 | `curl`（微信支付用）、`sockets`（SMTP 走流式时的兜底） |
| 数据库 | MySQL 5.7+ / MariaDB 10.3+ |
| Web 服务器 | Nginx 或 Apache（宝塔面板均可） |

**零 Composer 依赖** —— 不需要执行 `composer install`，上传即用。

---

## 第 1 步 · 上传代码

1. 把 `vobl-id-php/` 整个目录上传到服务器，例如 `/www/wwwroot/sso.vobl.cn/`
2. **在宝塔里把站点根目录设为 `/www/wwwroot/sso.vobl.cn/public`**
3. 设置目录权限（SSH 执行）：

```bash
cd /www/wwwroot/sso.vobl.cn
chown -R www:www .
chmod -R 755 .
chmod -R 775 storage
```

> **为什么必须指向 public/**：`app/` 里是业务代码、`config/` 里是数据库密码和密钥、
> `storage/` 里是日志。如果站点根指向项目根目录，这些文件会能被直接下载下来。

---

## 第 2 步 · 建数据库

宝塔面板 → 数据库 → 添加数据库：

| 字段 | 建议值 |
| --- | --- |
| 数据库名 | `vobl_id` |
| 用户名 | `vobl_id` |
| 密码 | 生成一个强密码，记下来 |
| 字符集 | `utf8mb4` |

然后导入表结构：

**方式一（宝塔界面）**：数据库 → 管理 → 导入 → 选择 `sql/schema.mysql.sql`

**方式二（命令行，推荐，能看到报错）**：

```bash
cd /www/wwwroot/sso.vobl.cn
mysql -u vobl_id -p vobl_id < sql/schema.mysql.sql
```

导入成功后会看到 `roles` 与 `settings` 表里已经有初始数据，
并且自动创建了 4 个内置角色。

### 唯一索引必须单独建（schema 里已处理）

`schema.mysql.sql` 里的唯一索引是**单独执行 + 允许失败**的。原因：如果历史数据里
已有重复邮箱，索引创建会失败；若这条 DDL 和建表放在同一个批次里，**整个批次失败 → 所有接口 500**。

---

## 第 3 步 · 写配置文件

```bash
cd /www/wwwroot/sso.vobl.cn
cp config/config.sample.php config/config.php
```

生成两个密钥（**这一步不能省，也不能用示例里的值**）：

```bash
php -r "echo 'app_secret  = ' . bin2hex(random_bytes(32)) . PHP_EOL;"
php -r "echo 'config_key  = ' . bin2hex(random_bytes(32)) . PHP_EOL;"
```

编辑 `config/config.php`：

```php
'app' => [
    'url'   => 'https://sso.vobl.cn',   // ★ 必须是用户实际访问的地址
    'debug' => false,                    // ★ 生产环境必须 false
    'trust_proxy' => true,               // ★ 套了 Cloudflare / Nginx 反代就要 true
],
'db' => [
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'database' => 'vobl_id',
    'username' => 'vobl_id',
    'password' => '第 2 步生成的密码',
    'strict_prepare' => true,            // ★ 别改，这是防 SQL 注入的关键开关
],
'keys' => [
    'app_secret' => '替换成上面生成的值',
    'config_key' => '替换成另一个生成的值',
],
'install' => [
    'allow_bootstrap_admin' => true,     // 建完管理员后要改回 false
],
```

### `app.url` 填错会怎样

它影响三处，都会静默出错，不会报错只是功能不正常：

1. 邮件里的链接（用户点开会跳到错误域名）
2. 支付回调地址（**支付平台的通知收不到，用户付了钱但余额不进账**）
3. 第三方登录的回调地址拼接

**换域名后一定要回来改这一项。**

### `trust_proxy` 什么时候开

| 情况 | 设置 |
| --- | --- |
| 套了 Cloudflare | `true` |
| 宝塔里配了 Nginx 反代到本地 | `true` |
| PHP 直接对外服务 | `false` |

开着它时系统会信任 `X-Forwarded-For` 等请求头。**如果没有反代却开了它，
攻击者可以伪造 IP 绕过限流** —— 这是个真实的安全隐患，别图省事。

### 伪静态规则

**Nginx（宝塔）**：站点 → 设置 → 伪静态，填入 `nginx.conf.example` 的内容。
核心只有两行：

```nginx
location / { try_files $uri $uri/ /index.php?$query_string; }
```

**Apache**：`public/.htaccess` 已经写好了，确认 `AllowOverride All` 即可。

---

## 第 4 步 · 绑定域名与 HTTPS

1. 宝塔 → 站点 → 设置 → 域名管理 → 添加 `sso.vobl.cn`
2. 宝塔 → SSL → 申请 Let's Encrypt 证书 → **开启强制 HTTPS**

> **必须开 HTTPS**：会话 Cookie 带 `Secure` 标记、安全密钥（WebAuthn）
> 都要求 HTTPS 环境，纯 HTTP 下这两个功能直接不可用。

---

## 第 5 步 · 创建第一个管理员

浏览器打开 `https://sso.vobl.cn/install`：

1. 先看**环境自检**表，所有项应该是绿色
2. 填管理员邮箱与密码，点「创建并进入后台」
3. 看到后台控制台就成了

**然后立刻**把 `config/config.php` 里的 `install.allow_bootstrap_admin` 改成 `false`。
不改的话等于给所有人留了一个创建管理员的入口。

### 命令行替代方案

```bash
php tools/cli.php user:promote 你的邮箱@example.com
```

用于「管理员账号被误删/锁死」的紧急恢复。

---

## 第 6 步 · 配置发信（**最容易卡住的一步**）

后台 → **邮件设置**。

### 先选服务商

页面上方有个「选择服务商自动填入」下拉，选一个会自动带出服务器地址、端口、加密方式。

### 各服务商对照表

| 服务商 | 服务器地址 | 端口 | 加密 | 密码填什么 |
| --- | --- | --- | --- | --- |
| **腾讯企业邮** | `smtp.exmail.qq.com` | 465 | SSL | 邮箱登录密码 |
| **QQ 邮箱** | `smtp.qq.com` | 465 | SSL | **授权码** |
| **阿里云邮件推送** | `smtpdm.aliyun.com` | 465 | SSL | 发信密码 |
| **网易企业邮** | `smtp.qiye.163.com` | 465 | SSL | **授权码** |
| **Gmail** | `smtp.gmail.com` | 587 | TLS | **应用专用密码** |
| **Resend** | `smtp.resend.com` | 465 | SSL | API Key（账号填 `resend`）|
| **Brevo** | `smtp-relay.brevo.com` | 587 | TLS | SMTP Key |
| ~~Outlook / Hotmail~~ | ~~`smtp-mail.outlook.com`~~ | ~~587~~ | — | **已停用，见下方附录** |

### 然后依次点两个按钮

1. **测试连接** —— 只连不发。通过说明服务器地址、端口、加密、账号密码都对
2. **发送测试邮件** —— 真的发一封。没收到就看垃圾邮件文件夹

如果失败，这两个按钮会把**完整的 SMTP 协议过程**打印出来，
哪一步被服务器拒绝一目了然。也可以命令行诊断：

```bash
php tools/cli.php mail:diagnose
```

### ⚠️ 关于「授权码」

QQ 邮箱、163 的「密码」填的是**授权码**，不是登录密码。授权码要在邮箱网页端
单独生成（QQ 邮箱：设置 → 账户 → POP3/IMAP/SMTP 服务 → 生成授权码）。

**这是配置失败最常见的原因**，占了一半以上。

### ⚠️ DNS 记录

用自有域名发信时，要在 DNS 里配三条记录，否则邮件大概进垃圾箱：

```
TXT   @               v=spf1 include:<服务商> ~all
TXT   _dmarc          v=DMARC1; p=none; rua=mailto:dmarc@vobl.cn
CNAME <服务商给的>      <服务商给的公钥值>          ← DKIM
```

`p=none` 是 DMARC 的观察模式，先跑两周看报告再收紧到 `quarantine`。
三条记录服务商后台都会给出具体值。

---

## 第 7 步 · 配置支付（可选）

后台 → **支付通道**。系统内置四种：

| 通道 | 适用 |
| --- | --- |
| **模拟支付** | 默认启用。用于先跑通充值流程，**上线前必须停用** |
| **易支付** | 国内最常见的聚合支付，一个账号收支付宝 + 微信 |
| **支付宝** | 官方电脑网站支付（RSA2 签名） |
| **微信支付** | 官方 Native 扫码（APIv2） |

### 配置流程

1. 点通道右侧的「配置」，填入参数（**密钥字段留空表示不修改**）
2. 保存后点「启用」
3. 点「设为默认」把它切成默认通道
4. **回「模拟支付」把它停用**
5. 去前台充值一笔真实的小额订单验证

### 回调地址要登记到支付平台

```
https://sso.vobl.cn/pay/notify/<通道代码>
例：https://sso.vobl.cn/pay/notify/epay
```

### 上线前的一个自检

用模拟支付跑一遍充值，确认：

- 订单能创建
- 支付后余额自动到账
- **重复通知不会重复入账**（把一个通知重放两次，余额只加一次）
- 流水里有记录

这四条都验过，再换成真实通道 —— 代码路径完全一致，不会有意外。

---

## 附：微软邮箱 OAuth2

如果一定要用 Outlook / Hotmail 发信（比如公司邮箱只能用这个）：

```bash
php tools/cli.php mail:oauth-help
```

这条命令会打印完整步骤。核心是：

1. Azure 门户注册应用
2. 添加 `SMTP.Send` 权限（**还要有 `offline_access`**，否则拿不到刷新令牌）
3. **开启「允许公共客户端流」**（不开的话设备码流程会报 `unauthorized_client`）
4. 用设备码流程拿到 `refresh_token`
5. 在 `config/mail.local.php` 填好，然后：

```bash
php tools/cli.php mail:apply
php tools/cli.php mail:diagnose
```

---

## 日常维护

### 加进宝塔计划任务（每天跑一次）

```bash
# 清理 180 天前的审计日志 + 过期限流计数
cd /www/wwwroot/sso.vobl.cn && php tools/cli.php audit:prune 180

# 关闭超时未支付的订单
cd /www/wwwroot/sso.vobl.cn && php tools/cli.php order:close-expired
```

### 换域名

改 `config/config.php` 的 `app.url` → 后台「系统设置」改站点地址 →
**去支付平台更新回调地址** → 重新申请证书。

### 让所有人强制重新登录

改 `config/config.php` 里的 `app_secret`。所有已签发的会话瞬间失效。

### 怀疑被入侵

1. 后台 → **审计日志**，筛「安全事件」分类
2. 看「已拒绝」和「失败」的记录 —— 越权尝试、CSRF 校验失败都在这里
3. 改 `app_secret` 把所有会话踢掉
4. 改数据库密码

---

## 常见问题

### 首页 500 / 打开是源码

站点根目录没指向 `public/`。

### 所有页面 404

伪静态没配。Nginx 加 `try_files $uri $uri/ /index.php?$query_string;`。

### 提示「密钥未配置」

`config/config.php` 里 `app_secret` 还是示例值或不足 32 位。

### 后台显示「未绑定数据库」之类的错

`config/config.php` 的 `db` 段填错了。用 `php tools/cli.php env:check` 检查。

### 注册时收不到邮件

按这个顺序查：① 后台「邮件设置」点「发送测试邮件」 ② 垃圾邮件文件夹
③ 发信域名是否配了 SPF/DKIM ④ 服务商的发信额度是否用尽。

### 充值了但余额没变

**支付入账只认异步通知，不认页面跳转**。检查：

1. 支付平台里的回调地址是不是 `https://你的域名/pay/notify/<通道>`
2. `config/config.php` 的 `app.url` 是否与真实域名一致（影响回调地址拼接）
3. 后台 →「订单与对账」看该订单的「通知次数」——如果是 0，说明通知根本没到

### 安全密钥按钮不显示

需要 HTTPS。另外「系统设置」里确认「启用安全密钥」是开着的。

### 忘了管理员密码，又收不到邮件

```bash
php tools/cli.php user:promote 你的邮箱   # 先确认是超管
# 或用恢复码：/forgot 页面切到「恢复码」标签
```

---

## 上线前检查清单

- [ ] 站点根目录指向 `public/`，`config/` 无法通过 URL 访问
- [ ] `app.debug` = `false`
- [ ] `app_secret` 与 `config_key` 都是新生成的 32 字节随机值
- [ ] `install.allow_bootstrap_admin` = `false`
- [ ] 已开启 HTTPS 并强制跳转
- [ ] `storage/` 可写，且目录里没有 `.php` 文件
- [ ] 已创建第一个超级管理员并能登录
- [ ] 邮件测试通过，验证邮件进收件箱（不是垃圾箱）
- [ ] **模拟支付通道已停用**（或未接入真实通道时明确知道它的存在）
- [ ] 支付回调地址已在支付平台登记，`app.url` 与真实域名一致
- [ ] 后台「系统设置」里确认「管理员强制两步验证」的取舍
- [ ] 计划任务已加（日志清理、订单关闭）
- [ ] 数据库已配置自动备份
