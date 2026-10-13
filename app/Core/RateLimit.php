<?php
declare(strict_types=1);

namespace Vobl\Core;

/**
 * 限流器（数据库计数版）
 * ============================================================================
 * 为什么用数据库而不是文件/APCu：
 *   宝塔环境常见多 PHP-FPM 进程，文件锁容易出竞态；数据库是唯一可靠的共享状态。
 *   计数表只有几十行，性能完全不是问题。
 *
 * 三层限流的典型用法：
 *   同邮箱发信  → 60 秒 / 5 次每小时
 *   同 IP 发信  → 20 次每小时
 *   全站日发信  → 每日总量上限（保护 SMTP 额度，这是钱的边界）
 * ============================================================================
 */
final class RateLimit
{
    /**
     * 消耗一次配额。
     * @return array{allowed:bool, remaining:int, retry_after:int}
     */
    public static function hit(string $bucket, int $limit, int $windowSeconds): array
    {
        $now = time();
        $db  = DB::conn();

        try {
            $row = $db->one('SELECT count, reset_at FROM email_quota WHERE bucket = ? LIMIT 1', [$bucket]);
        } catch (\Throwable $e) {
            // 限流表异常时放行（宁可放行也不能把正常用户挡住），但要记日志
            error_log('[ratelimit] 读取失败，已放行：' . $e->getMessage());
            return ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
        }

        if ($row === null || (int) $row['reset_at'] <= $now) {
            // 窗口已过期或首次：重置计数
            if ($row === null) {
                $db->insert('email_quota', [
                    'bucket'   => $bucket,
                    'count'    => 1,
                    'reset_at' => $now + $windowSeconds,
                ]);
            } else {
                $db->update('email_quota',
                    ['count' => 1, 'reset_at' => $now + $windowSeconds],
                    'bucket = ?', [$bucket]);
            }
            return ['allowed' => true, 'remaining' => $limit - 1, 'retry_after' => 0];
        }

        $count = (int) $row['count'];
        if ($count >= $limit) {
            return [
                'allowed'     => false,
                'remaining'   => 0,
                'retry_after' => max(1, (int) $row['reset_at'] - $now),
            ];
        }

        // 原子递增，避免并发下漏计
        $db->increment('email_quota', 'count', 1, 'bucket = ?', [$bucket]);
        return ['allowed' => true, 'remaining' => $limit - $count - 1, 'retry_after' => 0];
    }

    /** 只检查不消耗（用于「还剩几次」的展示） */
    public static function peek(string $bucket, int $limit): int
    {
        $row = DB::conn()->one('SELECT count, reset_at FROM email_quota WHERE bucket = ? LIMIT 1', [$bucket]);
        if ($row === null || (int) $row['reset_at'] <= time()) {
            return $limit;
        }
        return max(0, $limit - (int) $row['count']);
    }

    /** 清空某个桶（如登录成功后清掉失败计数） */
    public static function clear(string $bucket): void
    {
        DB::conn()->delete('email_quota', 'bucket = ?', [$bucket]);
    }

    /**
     * 发信三重限流。任一不通过都返回具体原因，便于给用户准确提示。
     * @return array{allowed:bool, reason:string, retry_after:int}
     */
    public static function guardMail(string $email, string $ip): array
    {
        $fromSettings = \Vobl\Services\Settings::class;
        $hourlyEmail = 5;
        $hourlyIp    = 20;
        $dailyGlobal = (int) $fromSettings::get('mail.daily_limit', 900);

        $checks = [
            ['mail:min:' . $email,   1,            60,     '发送太频繁，请一分钟后再试'],
            ['mail:hr:' . $email,    $hourlyEmail, 3600,   '该邮箱本小时发送次数已达上限，请稍后再试'],
            ['mail:ip:' . $ip,       $hourlyIp,    3600,   '当前网络发送次数过多，请稍后再试'],
            ['mail:day:' . date('Ymd'), $dailyGlobal, 86400, '系统今日邮件额度已用完，请联系管理员'],
        ];

        foreach ($checks as [$bucket, $limit, $window, $reason]) {
            $r = self::hit($bucket, $limit, $window);
            if (!$r['allowed']) {
                return ['allowed' => false, 'reason' => $reason, 'retry_after' => $r['retry_after']];
            }
        }
        return ['allowed' => true, 'reason' => '', 'retry_after' => 0];
    }

    /**
     * 登录失败限流。登录成功时必须 clear()，否则
     * 「错 7 次 → 输对 → 之后错 1 次」会被当成累计 8 次直接锁号。
     */
    public static function guardLogin(string $email, string $ip): array
    {
        $byEmail = ['bucket' => 'login:mail:' . $email, 'limit' => 8,  'window' => 900];
        $byIp    = ['bucket' => 'login:ip:' . $ip,     'limit' => 40, 'window' => 900];

        foreach ([$byEmail, $byIp] as $rule) {
            $row = DB::conn()->one(
                'SELECT count, reset_at FROM email_quota WHERE bucket = ? LIMIT 1',
                [$rule['bucket']]
            );
            if ($row !== null && (int) $row['reset_at'] > time() && (int) $row['count'] >= $rule['limit']) {
                return [
                    'allowed'     => false,
                    'retry_after' => (int) $row['reset_at'] - time(),
                ];
            }
        }
        return ['allowed' => true, 'retry_after' => 0];
    }

    public static function recordLoginFailure(string $email, string $ip): void
    {
        self::hit('login:mail:' . $email, 8, 900);
        self::hit('login:ip:' . $ip, 40, 900);
    }

    public static function clearLoginFailure(string $email, string $ip): void
    {
        self::clear('login:mail:' . $email);
        self::clear('login:ip:' . $ip);
    }

    /** 清理过期计数行 */
    public static function prune(): int
    {
        return DB::conn()->delete('email_quota', 'reset_at < ?', [time() - 86400]);
    }
}
