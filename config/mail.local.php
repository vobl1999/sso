<?php
/**
 * 本地邮件配置（不要提交到 Git —— 已在 .gitignore 里排除）
 * ============================================================================
 * 这个文件由 tools/cli.php 读取，用来把 SMTP 参数一次性写入数据库（加密存储）。
 *
 * 用法：
 *   php tools/cli.php mail:apply          # 把下面的配置写入数据库
 *   php tools/cli.php mail:test           # 测试连接与认证
 *   php tools/cli.php mail:send 你的邮箱   # 发一封真实测试邮件
 *   php tools/cli.php mail:diagnose       # 综合诊断（推荐先跑这个）
 *
 * ⚠️ 关于下面这份 Outlook 配置的现状（重要）
 * ----------------------------------------------------------------------------
 * 微软已于 2026 年 4 月彻底关闭 SMTP 基础认证，原先的时间线是：
 *   2025-09  原计划停用，推迟
 *   2026-03  开始按比例拒绝基础认证请求
 *   2026-04  基础认证完全停用
 *
 * 因此「smtp-mail.outlook.com:587 + 账号密码」现在会被服务器直接拒绝，
 * 报错为 535 5.7.139 basic authentication is disabled。
 * Outlook / Hotmail 的应用专用密码也已随基础认证一起下线，无法再用。
 *
 * 想继续用这个微软邮箱发信，只有两条路：
 *   ① 改用 OAuth2（本系统已支持）：把 auth_method 改成 xoauth2，
 *      并按 tools/cli.php mail:oauth-help 的说明完成微软账号授权；
 *   ② 换一个支持基础认证的发信服务（推荐，最省事）：
 *      腾讯企业邮 smtp.exmail.qq.com:465
 *      阿里云邮件推送 smtpdm.aliyun.com:465
 *      网易企业邮 smtp.qiye.163.com:465
 *      Resend smtp.resend.com:465
 *      Brevo smtp-relay.brevo.com:587
 * ============================================================================
 */

return [
    // ---------------------------------------------------------------- 服务器
    'host'       => 'smtp-mail.outlook.com',
    'port'       => 587,
    'encryption' => 'tls',        // 587 端口用 STARTTLS；465 用 ssl

    // ---------------------------------------------------------------- 认证
    // 可选值：auto（自动尝试）/ login / plain / xoauth2
    // ★ Outlook / Hotmail 现在只接受 xoauth2
    'auth_method' => 'xoauth2',

    // ---------------------------------------------------------------- 账号
    'username' => 'maimaidx@hotmail.com',
    'password' => 'fteviciveqsoawrw',   // 已失效（基础认证被关闭）。请改为 OAuth2 后清空此项

    // OAuth2 用到的参数（auth_method = xoauth2 时才需要）
    // 需要通过 tools/cli.php mail:oauth-help 获取 refresh_token
    'oauth_client_id'     => '',
    'oauth_client_secret' => '',
    'oauth_tenant'        => 'consumers',      // 个人账号用 consumers，企业账号填租户 ID
    'oauth_refresh_token' => '',

    // ---------------------------------------------------------------- 发件人
    // 必须与登录账号同域，否则会被服务商拒信或直接判为伪造
    'from_address' => 'maimaidx@hotmail.com',
    'from_name'    => 'Vobl ID',
    'reply_to'     => '',

    // ---------------------------------------------------------------- 其他
    'timeout'     => 15,     // 秒
    'daily_limit' => 900,    // 全站每日发信上限，保护额度（这是钱的边界）
    'code_ttl'    => 600,    // 验证码有效期（秒）
];
