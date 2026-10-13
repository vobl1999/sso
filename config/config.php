<?php
// ⚠️ 本地开发用配置（已被 .gitignore 排除，不会进入 Git 仓库）
// 生产部署时请改回 MySQL，详见 DEPLOY.md
return [
    'app' => [
        'url' => 'http://127.0.0.1:8787',
        'debug' => true,
        'timezone' => 'Asia/Shanghai',
        'cookie_name' => 'vobl_sid',
        'cookie_domain' => '',
        'trust_proxy' => false,
    ],
    'db' => [
        'driver' => 'sqlite',
        'database' => dirname(__DIR__) . '/storage/dev.sqlite',
        'strict_prepare' => true,
    ],
    'keys' => [
        'app_secret' => 'localdev0000000000000000000000000000000000000000000000000000000000',
        'config_key' => 'localdev1111111111111111111111111111111111111111111111111111111111',
    ],
    'paths' => ['storage' => dirname(__DIR__) . '/storage'],
    'install' => ['allow_bootstrap_admin' => false],
];
