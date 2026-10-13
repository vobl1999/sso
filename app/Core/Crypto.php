<?php
declare(strict_types=1);

namespace Vobl\Core;

/**
 * 加密与令牌工具
 * ============================================================================
 * 全站所有「随机值、签名、摘要、密码」都必须经过这里，不要散落在业务代码里。
 * ============================================================================
 */
final class Crypto
{
    // ---------------------------------------------------------------- 随机

    /** 密码学安全随机，返回指定字节数的十六进制串 */
    public static function randHex(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** base64url 随机串，适合放进 URL 或 Cookie */
    public static function randB64(int $bytes = 32): string
    {
        return self::b64urlEncode(random_bytes($bytes));
    }

    /** 生成人类可读的分组码，如 7249-1038（用于恢复码、邀请码） */
    public static function randGrouped(int $groups = 4, int $len = 4): string
    {
        $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // 去掉易混的 0O1I
        $out = [];
        for ($g = 0; $g < $groups; $g++) {
            $s = '';
            for ($i = 0; $i < $len; $i++) {
                $s .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $out[] = $s;
        }
        return implode('-', $out);
    }

    /** 数字验证码，固定长度，允许前导零 */
    public static function randDigits(int $len = 6): string
    {
        $s = '';
        for ($i = 0; $i < $len; $i++) {
            $s .= (string) random_int(0, 9);
        }
        return $s;
    }

    // ---------------------------------------------------------------- 编码

    public static function b64urlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $data): string
    {
        $s = strtr($data, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad > 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($s, true);
        if ($out === false) {
            throw new \InvalidArgumentException('base64url 解码失败');
        }
        return $out;
    }

    // ---------------------------------------------------------------- 摘要

    /** 令牌摘要：库里只存这个，明文只在 Cookie / 链接里出现一次 */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * 定长比较。用于比较密钥、验证码、签名 —— 普通 === 会因为
     * 「第一个字符不符就提前返回」而泄露信息（时序侧信道）。
     */
    public static function equals(string $a, string $b): bool
    {
        // hash_equals 自身要求长度不同时返回 false，这里先统一长度
        if (strlen($a) !== strlen($b)) {
            // 仍然做一次比较，避免长度差异直接形成可测量的分支
            hash_equals($b, $b);
            return false;
        }
        return hash_equals($a, $b);
    }

    // ---------------------------------------------------------------- 对称加密

    /** AES-256-GCM 加密，用于数据库里的敏感配置（SMTP 密码、支付密钥） */
    public static function encrypt(string $plain, string $keyHex): string
    {
        $key = self::deriveKey($keyHex);
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('加密失败');
        }
        return 'v1:' . self::b64urlEncode($iv . $tag . $ct);
    }

    public static function decrypt(string $cipher, string $keyHex): string
    {
        if (!str_starts_with($cipher, 'v1:')) {
            // 兼容早期明文数据，首次保存时会自动加密
            return $cipher;
        }
        $raw = self::b64urlDecode(substr($cipher, 3));
        if (strlen($raw) < 29) {
            throw new \RuntimeException('密文长度异常');
        }
        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ct  = substr($raw, 28);
        $key = self::deriveKey($keyHex);
        $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('解密失败：config_key 可能已被更换');
        }
        return $plain;
    }

    private static function deriveKey(string $keyHex): string
    {
        // 允许传入不等长的字符串，统一派生为 32 字节
        return hash('sha256', $keyHex, true);
    }

    // ---------------------------------------------------------------- HMAC

    public static function hmac(string $data, string $secret): string
    {
        return hash_hmac('sha256', $data, $secret);
    }

    /** 自包含签名载荷：base64url(json).hex(hmac) —— 用于 CSRF、一次性链接 */
    public static function seal(array $payload, string $secret): string
    {
        $body = self::b64urlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $body . '.' . self::hmac($body, $secret);
    }

    /** 校验并解出载荷；签名不符或已过期 → null */
    public static function open(string $token, string $secret): ?array
    {
        $pos = strrpos($token, '.');
        if ($pos === false || $pos === 0) {
            return null;
        }
        $body = substr($token, 0, $pos);
        $sig  = substr($token, $pos + 1);
        if (!self::equals(self::hmac($body, $secret), $sig)) {
            return null;
        }
        $json = self::b64urlDecode($body);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        if (isset($data['exp']) && (int) $data['exp'] < time()) {
            return null;
        }
        return $data;
    }

    // ---------------------------------------------------------------- 密码

    /** 当前推荐的密码哈希。PASSWORD_DEFAULT 会随 PHP 版本自动升级算法 */
    public static function hashPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
        if ($hash === false) {
            throw new \RuntimeException('密码哈希失败');
        }
        return $hash;
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        return password_verify($password, $hash);
    }

    /**
     * 是否需要重新哈希（算法升级 / cost 提高）。
     * 登录成功后顺手调用，实现平滑迁移 —— 这是「可升级」的关键，
     * 否则将来想换算法会发现老密码全都验不了。
     */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT, ['cost' => 12]);
    }
}
