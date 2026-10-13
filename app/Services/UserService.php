<?php
declare(strict_types=1);

namespace Vobl\Services;

use Vobl\Core\Audit;
use Vobl\Core\Auth;
use Vobl\Core\Config;
use Vobl\Core\Crypto;
use Vobl\Core\DB;
use Vobl\Core\RateLimit;

/**
 * 账号与邮箱验证
 * ============================================================================
 * 这里集中了「邮箱作为唯一身份」的全部规则。三条最容易做错的：
 *
 *   1. 邮箱必须归一化（小写 + trim）。否则 A@X.cn 和 a@x.cn 会变成两个账号，
 *      用户换个大小写就登不进来。归一化只允许发生在 normalizeEmail() 里。
 *
 *   2. 注册 / 找回密码的响应绝不能暴露「这个邮箱是否已注册」。
 *      统一返回同一句话，差异只体现在邮件内容上 —— 这是防账号枚举的关键。
 *
 *   3. 验证码只存 SHA-256 摘要，一次校验即作废，且最多尝试 5 次。
 * ============================================================================
 */
final class UserService
{
    // ================================================================ 基础

    /** ★ 全站唯一的邮箱归一化入口 */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public static function findByEmail(string $email): ?array
    {
        return DB::conn()->one(
            'SELECT * FROM users WHERE email = ? LIMIT 1',
            [self::normalizeEmail($email)]
        );
    }

    public static function findById(int $id): ?array
    {
        return DB::conn()->one('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);
    }

    /** 对外暴露的用户信息，永远不含密码哈希等敏感字段 */
    public static function publicView(array $user): array
    {
        return [
            'id'             => (int) $user['id'],
            'email'          => (string) $user['email'],
            'email_verified' => (int) $user['email_verified'] === 1,
            'display_name'   => (string) $user['display_name'],
            'avatar'         => (string) $user['avatar'],
            'status'         => (string) $user['status'],
            'totp_enabled'   => (int) $user['totp_enabled'] === 1,
            'created_at'     => (int) $user['created_at'],
            'last_login_at'  => (int) ($user['last_login_at'] ?? 0),
        ];
    }

    // ================================================================ 创建

    /**
     * 创建账号。邮箱必须已验证过（调用方需先校验验证码）。
     * @return array{ok:bool, error:string, user:array}
     */
    public static function create(string $email, string $password, string $displayName = ''): array
    {
        $email = self::normalizeEmail($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => '邮箱格式不正确', 'user' => []];
        }
        if (self::findByEmail($email) !== null) {
            // 注意：这个分支只在「验证码已通过」时才可能到达，
            // 所以此时可以安全地告诉用户邮箱已存在（他刚刚证明了邮箱所有权）
            return ['ok' => false, 'error' => '该邮箱已经注册过了', 'user' => []];
        }

        $check = self::validatePassword($password);
        if (!$check['ok']) {
            return ['ok' => false, 'error' => $check['error'], 'user' => []];
        }

        $now = time();
        $id = DB::conn()->insert('users', [
            'email'          => $email,
            'email_verified' => 1,
            'display_name'   => mb_substr($displayName !== '' ? $displayName : self::defaultName($email), 0, 60),
            'password_hash'  => Crypto::hashPassword($password),
            'algo'           => 'bcrypt',
            'avatar'         => '',
            'status'         => 'active',
            'totp_secret'    => '',
            'totp_enabled'   => 0,
            'failed_count'   => 0,
            'locked_until'   => 0,
            'last_login_at'  => 0,
            'last_login_ip'  => '',
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        Wallet::of($id);   // 顺手建钱包，避免首次充值时的分支

        Audit::log('auth', 'user.register', [
            'actor_id' => 0,
            'actor'    => $email,
            'target'   => 'user:' . $id,
            'detail'   => '注册成功',
        ]);

        return ['ok' => true, 'error' => '', 'user' => self::findById($id) ?? []];
    }

    private static function defaultName(string $email): string
    {
        $local = explode('@', $email)[0] ?? 'user';
        return mb_substr($local, 0, 20) ?: '用户';
    }

    // ================================================================ 密码策略

    /**
     * 密码强度校验。规则写在后台配置里，改策略不用改代码。
     * @return array{ok:bool, error:string, score:int}
     */
    public static function validatePassword(string $password): array
    {
        $min = max(6, Settings::int('security.password_min', 10));

        if ($password === '') {
            return ['ok' => false, 'error' => '请输入密码', 'score' => 0];
        }
        if (mb_strlen($password) < $min) {
            return ['ok' => false, 'error' => "密码至少需要 {$min} 位", 'score' => 0];
        }
        if (mb_strlen($password) > 200) {
            // 限制长度是因为 bcrypt 只处理前 72 字节，且防止超长输入消耗 CPU
            return ['ok' => false, 'error' => '密码过长（最多 200 位）', 'score' => 0];
        }

        $score = 0;
        if (preg_match('/[a-z]/', $password) === 1) $score++;
        if (preg_match('/[A-Z]/', $password) === 1) $score++;
        if (preg_match('/\d/', $password) === 1)    $score++;
        if (preg_match('/[^A-Za-z0-9]/', $password) === 1) $score++;
        if (mb_strlen($password) >= 14) $score++;

        // 常见弱口令直接拒绝，不管长度
        $weak = ['password', '12345678', 'qwerty', 'admin123', '11111111',
                 'abcd1234', 'passw0rd', 'iloveyou', 'letmein', 'welcome'];
        $lower = mb_strtolower($password);
        foreach ($weak as $w) {
            if (str_contains($lower, $w)) {
                return ['ok' => false, 'error' => '这个密码太常见了，请换一个', 'score' => $score];
            }
        }

        // 单一字符集的密码要求更长：
        // 纯数字比纯字母更容易穷举，所以对纯字母放宽到 16 位。
        // 12 位的 'abcdefghijklm' 这种键盘走位，实际强度很低。
        if (preg_match('/^\d+$/', $password) === 1 && mb_strlen($password) < 12) {
            return ['ok' => false, 'error' => '密码不能是纯数字，请混合字母或符号', 'score' => $score];
        }
        if (preg_match('/^[a-zA-Z]+$/', $password) === 1 && mb_strlen($password) < 16) {
            return ['ok' => false, 'error' => '纯字母的密码需要至少 16 位，建议加入数字或符号', 'score' => $score];
        }

        return ['ok' => true, 'error' => '', 'score' => min(4, $score)];
    }

    public static function setPassword(int $userId, string $password): array
    {
        $check = self::validatePassword($password);
        if (!$check['ok']) {
            return ['ok' => false, 'error' => $check['error']];
        }
        DB::conn()->update('users', [
            'password_hash' => Crypto::hashPassword($password),
            'algo'          => 'bcrypt',
            'updated_at'    => time(),
        ], 'id = ?', [$userId]);

        // ★ 改密码后踢掉全部其他会话 —— 这是密码泄露时用户唯一的自救手段
        $revoked = Auth::revokeAll($userId, Auth::sessionId());

        Audit::log('security', 'password.change', [
            'actor_id' => $userId,
            'target'   => 'user:' . $userId,
            'detail'   => '修改密码，已登出 ' . $revoked . ' 个其他会话',
        ]);

        return ['ok' => true, 'error' => '', 'revoked' => $revoked];
    }

    // ================================================================ 密码登录

    /**
     * 密码校验。返回统一的失败信息，不区分「用户不存在」和「密码错误」。
     * @return array{ok:bool, error:string, user:array, needs_rehash:bool}
     */
    public static function authenticate(string $email, string $password): array
    {
        $email = self::normalizeEmail($email);
        $fail = ['ok' => false, 'error' => '邮箱或密码不正确', 'user' => [], 'needs_rehash' => false];

        if ($email === '' || $password === '') {
            return $fail;
        }

        $user = self::findByEmail($email);
        if ($user === null) {
            // ★ 对不存在的账号也做一次哈希运算，让响应时间与「密码错误」一致，
            //   否则可以通过响应耗时的差异枚举出哪些邮箱已注册
            Crypto::verifyPassword($password, '$2y$12$' . str_repeat('x', 53));
            return $fail;
        }

        if ((string) $user['status'] === 'locked') {
            $until = (int) $user['locked_until'];
            if ($until > time()) {
                return ['ok' => false,
                        'error' => '账号已被临时锁定，请 ' . ceil(($until - time()) / 60) . ' 分钟后再试',
                        'user' => [], 'needs_rehash' => false];
            }
            // 锁定期已过，自动解锁
            DB::conn()->update('users', ['status' => 'active', 'failed_count' => 0, 'locked_until' => 0],
                'id = ?', [$user['id']]);
            $user['status'] = 'active';
        }
        if ((string) $user['status'] === 'disabled') {
            return ['ok' => false, 'error' => '该账号已被管理员停用', 'user' => [], 'needs_rehash' => false];
        }

        if (!Crypto::verifyPassword($password, (string) $user['password_hash'])) {
            $failed = (int) $user['failed_count'] + 1;
            $update = ['failed_count' => $failed, 'updated_at' => time()];
            // 连续 10 次失败 → 锁定 30 分钟
            if ($failed >= 10) {
                $update['status'] = 'locked';
                $update['locked_until'] = time() + 1800;
                Audit::log('security', 'account.locked', [
                    'actor_id' => (int) $user['id'], 'actor' => $email, 'result' => 'fail',
                    'detail' => '连续登录失败 ' . $failed . ' 次，已锁定 30 分钟',
                ]);
            }
            DB::conn()->update('users', $update, 'id = ?', [$user['id']]);
            return $fail;
        }

        // ★ 登录成功必须清零失败计数，否则「错 7 次 → 输对 → 再错 1 次」会被
        //   当成累计 8 次直接锁号
        if ((int) $user['failed_count'] > 0) {
            DB::conn()->update('users', ['failed_count' => 0, 'locked_until' => 0], 'id = ?', [$user['id']]);
        }

        // 算法/cost 升级：顺手重算一次哈希
        $needsRehash = Crypto::needsRehash((string) $user['password_hash']);

        return ['ok' => true, 'error' => '', 'user' => $user, 'needs_rehash' => $needsRehash];
    }

    public static function markLoginSuccess(int $userId, string $ip): void
    {
        DB::conn()->update('users', [
            'last_login_at' => time(),
            'last_login_ip' => $ip,
        ], 'id = ?', [$userId]);

        // 现在 users 表里有 email_verified 字段，一并清理过期的挑战记录
        DB::conn()->delete('email_challenges', 'expires_at < ?', [time() - 3600]);
    }

    // ================================================================ 验证码

    /**
     * 下发邮箱验证码。
     *
     * ★ 返回给调用方的永远是「已发送」，不暴露邮箱是否存在。
     *   差异只体现在邮件内容里（已注册的邮箱会收到「你已有账号」的提示邮件）。
     */
    public static function issueCode(string $email, string $kind, string $ip, array $payload = []): array
    {
        $email = self::normalizeEmail($email);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => '邮箱格式不正确', 'sent' => false];
        }

        // 三重限流：同邮箱 60 秒 / 5 次每小时、同 IP 20 次每小时、全站每日总量
        $guard = RateLimit::guardMail($email, $ip);
        if (!$guard['allowed']) {
            return ['ok' => false, 'error' => $guard['reason'], 'sent' => false, 'retry_after' => $guard['retry_after']];
        }

        $user = self::findByEmail($email);
        $ttl  = max(60, Settings::int('mail.code_ttl', 600));
        $code = Crypto::randDigits(6);

        // 同一邮箱同类型的旧验证码作废，避免多个码同时有效
        DB::conn()->query(
            'UPDATE email_challenges SET consumed_at = ? WHERE email = ? AND kind = ? AND consumed_at = 0',
            [time(), $email, $kind]
        );

        DB::conn()->insert('email_challenges', [
            'email'       => $email,
            'user_id'     => $user !== null ? (int) $user['id'] : 0,
            'code_hash'   => hash('sha256', $code),
            'kind'        => $kind,
            'payload'     => mb_substr((string) ($payload['new_email'] ?? ''), 0, 255),
            'attempts'    => 0,
            'ip'          => $ip,
            'expires_at'  => time() + $ttl,
            'consumed_at' => 0,
            'created_at'  => time(),
        ]);

        // 发信
        $mailer = new Mailer();
        $minutes = (int) ceil($ttl / 60);

        if ($user !== null && $kind === 'verify_email') {
            // 已注册却来「注册」→ 不发验证码，改发提示邮件（不暴露给客户端）
            $html = $mailer->template('你已经有账号了', [
                '这个邮箱已经在 Vobl ID 注册过了，无需重复注册。',
                '如果你是想登录，直接回到登录页即可；如果忘记了密码，可以使用「忘记密码」重置。',
            ], ['账号邮箱' => $email], [
                'label' => '前往登录',
                'url'   => self::siteUrl('/login'),
            ]);
            $result = $mailer->send($email, '你已经有 Vobl ID 账号了', $html);
        } else {
            $html = $mailer->codeTemplate($code, $kind, $minutes);
            $titles = [
                'verify_email'   => '验证你的邮箱',
                'reset_password' => '重置登录密码',
                'login_code'     => '登录验证码',
                'change_email'   => '确认新邮箱',
                'bind_confirm'   => '绑定确认',
            ];
            $result = $mailer->send($email, ($titles[$kind] ?? '验证码') . ' · ' . Settings::get('site.name', 'Vobl ID'), $html);
        }

        if (!$result['ok']) {
            // 发信失败要如实告知（否则用户会一直等一封永远不会来的邮件）
            Audit::log('smtp', 'send.fail', [
                'actor' => $email, 'result' => 'fail',
                'detail' => '验证码邮件发送失败：' . $result['error'],
            ]);
            return [
                'ok'    => false,
                'error' => '邮件发送失败：' . $result['error'],
                'sent'  => false,
            ];
        }

        Audit::log('auth', 'code.issue', [
            'actor'  => $email,
            'target' => $kind,
            'detail' => '下发验证码（有效期 ' . $minutes . ' 分钟）',
        ]);

        return ['ok' => true, 'error' => '', 'sent' => true];
    }

    /**
     * 校验验证码。一次通过即作废；最多尝试 5 次。
     * @return array{ok:bool, error:string, challenge:array}
     */
    public static function verifyCode(string $email, string $kind, string $code): array
    {
        $email = self::normalizeEmail($email);
        $fail = ['ok' => false, 'error' => '验证码不正确或已过期', 'challenge' => []];

        if ($code === '' || !preg_match('/^\d{4,8}$/', $code)) {
            return $fail;
        }

        $row = DB::conn()->one(
            'SELECT * FROM email_challenges
             WHERE email = ? AND kind = ? AND consumed_at = 0
             ORDER BY id DESC LIMIT 1',
            [$email, $kind]
        );
        if ($row === null) {
            return $fail;
        }
        if ((int) $row['expires_at'] < time()) {
            DB::conn()->update('email_challenges', ['consumed_at' => time()], 'id = ?', [$row['id']]);
            return ['ok' => false, 'error' => '验证码已过期，请重新获取', 'challenge' => []];
        }
        if ((int) $row['attempts'] >= 5) {
            DB::conn()->update('email_challenges', ['consumed_at' => time()], 'id = ?', [$row['id']]);
            return ['ok' => false, 'error' => '尝试次数过多，请重新获取验证码', 'challenge' => []];
        }

        $expected = hash('sha256', $code);
        if (!Crypto::equals($expected, (string) $row['code_hash'])) {
            DB::conn()->increment('email_challenges', 'attempts', 1, 'id = ?', [$row['id']]);
            $left = 5 - ((int) $row['attempts'] + 1);
            return ['ok' => false, 'error' => '验证码不正确' . ($left > 0 ? "，还可尝试 {$left} 次" : ''), 'challenge' => []];
        }

        // ★ 一次通过即作废 —— 只靠过期时间是可以重放的
        DB::conn()->update('email_challenges', ['consumed_at' => time()], 'id = ?', [$row['id']]);

        return ['ok' => true, 'error' => '', 'challenge' => $row];
    }

    // ================================================================ 改邮箱

    /**
     * 更换邮箱：先验证新地址，再通知旧地址。
     * 新地址验证通过前不修改任何数据 —— 否则打错一个字就把自己锁在门外了。
     */
    public static function changeEmail(int $userId, string $newEmail): array
    {
        $newEmail = self::normalizeEmail($newEmail);
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => '新邮箱格式不正确'];
        }
        $user = self::findById($userId);
        if ($user === null) {
            return ['ok' => false, 'error' => '用户不存在'];
        }
        if ($newEmail === (string) $user['email']) {
            return ['ok' => false, 'error' => '新邮箱与当前邮箱相同'];
        }

        $occupied = self::findByEmail($newEmail);
        if ($occupied !== null && (int) $occupied['id'] !== $userId) {
            return ['ok' => false, 'error' => '该邮箱已被其他账号使用'];
        }

        return self::issueCode($newEmail, 'change_email', $_SERVER['REMOTE_ADDR'] ?? '', ['new_email' => $newEmail]);
    }

    public static function confirmEmailChange(int $userId, string $newEmail, string $code): array
    {
        $newEmail = self::normalizeEmail($newEmail);
        $check = self::verifyCode($newEmail, 'change_email', $code);
        if (!$check['ok']) {
            return $check;
        }

        $user = self::findById($userId);
        if ($user === null) {
            return ['ok' => false, 'error' => '用户不存在'];
        }

        $oldEmail = (string) $user['email'];
        if (self::findByEmail($newEmail) !== null) {
            return ['ok' => false, 'error' => '该邮箱已被其他账号使用'];
        }

        DB::conn()->update('users', [
            'email'          => $newEmail,
            'email_verified' => 1,
            'updated_at'     => time(),
        ], 'id = ?', [$userId]);

        // ★ 向旧邮箱发通知 —— 账号被劫持时这是用户唯一能察觉的信号
        $mailer = new Mailer();
        $html = $mailer->template('你的登录邮箱已被更换', [
            '你的 Vobl ID 账号登录邮箱已从下面的旧地址更换为新地址。',
            '如果这不是你本人的操作，说明你的账号可能已被他人控制，请立即通过「忘记密码」重置密码，并联系管理员。',
        ], [
            '旧邮箱' => $oldEmail,
            '新邮箱' => $newEmail,
            '操作时间' => date('Y-m-d H:i:s'),
            '操作 IP' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]);
        $mailer->send($oldEmail, '安全提醒：登录邮箱已变更', $html);

        Audit::log('security', 'email.change', [
            'actor_id' => $userId, 'target' => $newEmail,
            'detail' => '登录邮箱由 ' . $oldEmail . ' 变更为 ' . $newEmail,
        ]);

        return ['ok' => true, 'error' => ''];
    }

    // ================================================================ 恢复码

    /**
     * 生成恢复码（邮箱丢失时的唯一兜底）。
     * 只在生成时显示一次，库里只存摘要。
     */
    public static function generateRecoveryCodes(int $userId, int $count = 10): array
    {
        $codes = [];
        DB::conn()->tx(function (DB $db) use ($userId, $count, &$codes) {
            $db->delete('recovery_codes', 'user_id = ? AND used_at = 0', [$userId]);
            for ($i = 0; $i < $count; $i++) {
                $plain = Crypto::randGrouped(3, 5);
                $codes[] = $plain;
                $db->insert('recovery_codes', [
                    'user_id'    => $userId,
                    'code_hash'  => hash('sha256', str_replace('-', '', $plain)),
                    'used_at'    => 0,
                    'created_at' => time(),
                ]);
            }
        });

        Audit::log('security', 'recovery.generate', [
            'actor_id' => $userId, 'target' => 'user:' . $userId,
            'detail' => '生成 ' . $count . ' 个恢复码',
        ]);

        return $codes;
    }

    public static function recoveryCodeStats(int $userId): array
    {
        return [
            'total' => DB::conn()->count('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ?', [$userId]),
            'left'  => DB::conn()->count('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at = 0', [$userId]),
        ];
    }

    /** 用恢复码重置密码（忘记密码且收不到邮件时的最后手段） */
    public static function useRecoveryCode(string $email, string $code, string $newPassword): array
    {
        $email = self::normalizeEmail($email);
        $user = self::findByEmail($email);
        if ($user === null) {
            return ['ok' => false, 'error' => '恢复码不正确'];
        }

        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
        $hash = hash('sha256', $normalized);

        $row = null;
        foreach (DB::conn()->all('SELECT * FROM recovery_codes WHERE user_id = ? AND used_at = 0', [(int) $user['id']]) as $r) {
            if (Crypto::equals($hash, (string) $r['code_hash'])) {
                $row = $r;
                break;
            }
        }
        if ($row === null) {
            Audit::log('security', 'recovery.fail', [
                'actor' => $email, 'result' => 'fail', 'detail' => '恢复码校验失败',
            ]);
            return ['ok' => false, 'error' => '恢复码不正确或已使用'];
        }

        $set = self::setPassword((int) $user['id'], $newPassword);
        if (!$set['ok']) {
            return $set;
        }

        DB::conn()->update('recovery_codes', ['used_at' => time()], 'id = ?', [$row['id']]);
        Auth::revokeAll((int) $user['id']);

        Audit::log('security', 'recovery.use', [
            'actor_id' => (int) $user['id'], 'actor' => $email,
            'detail' => '使用恢复码重置密码，已登出全部会话',
        ]);

        return ['ok' => true, 'error' => ''];
    }

    // ================================================================ 第三方绑定

    /**
     * 通过第三方身份登录或绑定。
     *
     * ★ 判定顺序（这是整套聚合登录的核心）：
     *     ① 已经绑过 → 直接登录
     *     ② 没绑过，但 provider 给了「已验证邮箱」且命中已有账号 → 自动绑定
     *     ③ 其它情况 → 返回 need_bind，要求用户先登录再手动绑定
     *
     *   「未验证邮箱不能自动合并」这条绝不能破：
     *   provider 说邮箱是 a@b.com 但没标 verified 时，攻击者可以在那个
     *   provider 上随便填任意邮箱 —— 一旦自动合并就等于接管了别人的账号。
     */
    public static function resolveIdentity(
        string $provider,
        string $providerUid,
        string $email,
        bool $emailVerified,
        array $profile = []
    ): array {
        if ($providerUid === '') {
            return ['ok' => false, 'error' => '第三方返回的用户标识为空', 'action' => 'error', 'user' => []];
        }

        // ① 已绑定
        $identity = DB::conn()->one(
            'SELECT * FROM identities WHERE provider = ? AND provider_uid = ? LIMIT 1',
            [$provider, $providerUid]
        );
        if ($identity !== null) {
            $user = self::findById((int) $identity['user_id']);
            if ($user === null || (string) $user['status'] !== 'active') {
                return ['ok' => false, 'error' => '绑定的账号不可用或已停用', 'action' => 'error', 'user' => []];
            }
            DB::conn()->update('identities', ['last_login_at' => time()], 'id = ?', [$identity['id']]);
            return ['ok' => true, 'error' => '', 'action' => 'login', 'user' => $user];
        }

        // ② 邮箱已验证且命中已有账号
        $email = self::normalizeEmail($email);
        if ($emailVerified && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $user = self::findByEmail($email);
            if ($user !== null && (int) $user['email_verified'] === 1) {
                DB::conn()->insert('identities', [
                    'user_id'        => (int) $user['id'],
                    'provider'       => $provider,
                    'provider_uid'   => $providerUid,
                    'email'          => $email,
                    'email_verified' => 1,
                    'nickname'       => mb_substr((string) ($profile['nickname'] ?? ''), 0, 80),
                    'raw'            => mb_substr(json_encode($profile, JSON_UNESCAPED_UNICODE), 0, 2000),
                    'linked_at'      => time(),
                    'last_login_at'  => time(),
                ]);

                Audit::log('auth', 'identity.autolink', [
                    'actor_id' => (int) $user['id'], 'actor' => $email,
                    'target'   => $provider,
                    'detail'   => '第三方登录按已验证邮箱自动绑定',
                ]);

                return ['ok' => true, 'error' => '', 'action' => 'autolink', 'user' => $user];
            }
        }

        // ③ 需要手动绑定
        return [
            'ok'     => false,
            'error'  => '',
            'action' => 'need_bind',
            'user'   => [],
            'reason' => $emailVerified
                ? '这个 ' . self::providerLabel($provider) . ' 账号绑定的邮箱没有对应到任何 Vobl ID 账号'
                : self::providerLabel($provider) . ' 没有提供可验证的邮箱，无法自动识别你的账号',
            'identity' => [
                'provider'     => $provider,
                'provider_uid' => $providerUid,
                'email'        => $email,
                'nickname'     => (string) ($profile['nickname'] ?? ''),
            ],
        ];
    }

    /** 把第三方身份绑到当前登录用户上 */
    public static function bindIdentity(int $userId, string $provider, string $providerUid, array $profile = []): array
    {
        if ($providerUid === '') {
            return ['ok' => false, 'error' => '第三方用户标识为空'];
        }
        $taken = DB::conn()->one(
            'SELECT user_id FROM identities WHERE provider = ? AND provider_uid = ? LIMIT 1',
            [$provider, $providerUid]
        );
        if ($taken !== null) {
            return ['ok' => false, 'error' => (int) $taken['user_id'] === $userId
                ? '你已经绑定过这个账号了'
                : '这个第三方账号已经绑定了其他 Vobl ID 账号'];
        }

        $mine = DB::conn()->one(
            'SELECT id FROM identities WHERE user_id = ? AND provider = ? LIMIT 1',
            [$userId, $provider]
        );
        if ($mine !== null) {
            return ['ok' => false, 'error' => '你已经绑定了 ' . self::providerLabel($provider) . '，请先解绑'];
        }

        $emailRaw = self::normalizeEmail((string) ($profile['email'] ?? ''));
        DB::conn()->insert('identities', [
            'user_id'        => $userId,
            'provider'       => $provider,
            'provider_uid'   => $providerUid,
            'email'          => $emailRaw,
            'email_verified' => !empty($profile['email_verified']) ? 1 : 0,
            'nickname'       => mb_substr((string) ($profile['nickname'] ?? ''), 0, 80),
            'raw'            => mb_substr(json_encode($profile, JSON_UNESCAPED_UNICODE), 0, 2000),
            'linked_at'      => time(),
            'last_login_at'  => time(),
        ]);

        $user = self::findById($userId);
        $label = self::providerLabel($provider);

        // 绑定成功 → 通知所有已验证邮箱
        if ($user !== null) {
            $mailer = new Mailer();
            $html = $mailer->template('你的账号新增了一种登录方式', [
                '你的 Vobl ID 账号刚刚绑定了' . $label . '，之后可以用它直接登录。',
                '如果这不是你本人的操作，请立即修改密码并解绑该登录方式。',
            ], [
                '账号邮箱' => (string) $user['email'],
                '新增方式' => $label,
                '操作时间' => date('Y-m-d H:i:s'),
                '操作 IP' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
            $mailer->send((string) $user['email'], '安全提醒：新增登录方式', $html);
        }

        Audit::log('security', 'identity.bind', [
            'actor_id' => $userId, 'target' => $provider,
            'detail' => '绑定 ' . $label . ' 账号',
        ]);

        return ['ok' => true, 'error' => ''];
    }

    /**
     * 解绑。
     * ★ 判断顺序至关重要：先查「有没有这个绑定」（404），再判「是不是最后一个」（409）。
     *   反过来写的话，只剩 1 种方式时解绑任何不存在的方式都会误报「这是最后一个」。
     */
    public static function unbindIdentity(int $userId, string $provider): array
    {
        $row = DB::conn()->one(
            'SELECT * FROM identities WHERE user_id = ? AND provider = ? LIMIT 1',
            [$userId, $provider]
        );
        if ($row === null) {
            return ['ok' => false, 'error' => '没有找到这个绑定', 'status' => 404];
        }

        $user = self::findById($userId);
        $hasPassword = $user !== null && (string) $user['password_hash'] !== '';
        $otherIdentities = DB::conn()->count(
            'SELECT COUNT(*) FROM identities WHERE user_id = ? AND provider <> ?',
            [$userId, $provider]
        );
        $passkeys = WebAuthn::countFor($userId);

        // 密码 + 其它第三方 + 安全密钥，全都算作「可用的登录方式」
        $remaining = ($hasPassword ? 1 : 0) + $otherIdentities + $passkeys;
        if ($remaining === 0) {
            return [
                'ok'     => false,
                'error'  => '这是你唯一可用的登录方式，解绑后你将无法登录。请先设置密码或绑定其他方式。',
                'status' => 409,
            ];
        }

        DB::conn()->delete('identities', 'id = ?', [(int) $row['id']]);

        $user2 = self::findById($userId);
        if ($user2 !== null) {
            $mailer = new Mailer();
            $html = $mailer->template('你的账号解绑了一种登录方式', [
                '你的 Vobl ID 账号刚刚解绑了' . self::providerLabel($provider) . '，之后无法再用它登录。',
                '如果这不是你本人的操作，请立即修改密码。',
            ], [
                '账号邮箱' => (string) $user2['email'],
                '解绑方式' => self::providerLabel($provider),
                '操作时间' => date('Y-m-d H:i:s'),
            ]);
            $mailer->send((string) $user2['email'], '安全提醒：解绑登录方式', $html);
        }

        Audit::log('security', 'identity.unbind', [
            'actor_id' => $userId, 'target' => $provider,
            'detail' => '解绑 ' . self::providerLabel($provider) . ' 账号',
        ]);

        return ['ok' => true, 'error' => '', 'status' => 200];
    }

    public static function identitiesOf(int $userId): array
    {
        return DB::conn()->all(
            'SELECT provider, nickname, email, email_verified, linked_at, last_login_at
             FROM identities WHERE user_id = ? ORDER BY linked_at',
            [$userId]
        );
    }

    public static function providerLabel(string $provider): string
    {
        return match ($provider) {
            'microsoft' => '微软账号',
            'github'    => 'GitHub',
            'wechat'    => '微信',
            'qq'        => 'QQ',
            'google'    => 'Google',
            'email'     => '邮箱',
            default     => $provider,
        };
    }

    /** 当前启用的登录方式（后台 providers.enabled 配置） */
    public static function enabledProviders(): array
    {
        $raw = (string) Settings::get('providers.enabled', 'email');
        $list = array_filter(array_map('trim', explode(',', $raw)));
        return array_values($list);
    }

    public static function siteUrl(string $path = '/'): string
    {
        $base = (string) Settings::get('site.url', '');
        if ($base === '') {
            $base = (string) Config::get('app.url', '');
        }
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}
