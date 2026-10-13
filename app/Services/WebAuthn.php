<?php
declare(strict_types=1);

namespace Vobl\Services;

use Vobl\Core\Config;
use Vobl\Core\Crypto;
use Vobl\Core\DB;

/**
 * WebAuthn / 安全密钥 / 通行密钥（指纹、面容、YubiKey）
 * ============================================================================
 * 零依赖实现。需要自己完成三处格式转换，缺一不可：
 *
 *   ① CBOR 解码   —— attestationObject 与 COSE 公钥都是 CBOR 编码。
 *                    注意 map 的键是「整数且有负数」，用字符串键取会得到 null。
 *   ② COSE → PEM  —— COSE 用整数键（1=kty, 3=alg, -1=crv, -2=x, -3=y），
 *                    而 openssl_verify 要 PEM。必须手工拼 DER。
 *   ③ 签名格式    —— ★ 这里与浏览器端有一个关键差异，见下方说明。
 *
 * ─────────────────────────────────────────────────────────────────────────
 * ★ 关于 DER → raw 签名转换（这是最容易踩的坑，但结论在 PHP 里不一样）
 *
 *   WebAuthn 的 ECDSA 签名是 ASN.1 DER 格式：SEQUENCE(INTEGER r, INTEGER s)
 *
 *   · 在浏览器端用 WebCrypto 时，ECDSA.verify 要求「定长 raw」r‖s（P-256 各 32 字节），
 *     所以必须先做 DER → raw 转换，否则所有验证都会失败且报错极含糊（verify 只返回 false）。
 *
 *   · 在 PHP 里用 openssl_verify 验 EC 密钥时，OpenSSL 原生就接受 DER 签名，
 *     所以 WebAuthn 给的签名可以直接用，反而不需要转换。
 *
 *   本类同时兼容两种输入：先按 DER 试，失败再按 raw 转 DER 重试。
 *   这样无论客户端给哪种格式都能过 —— 也不至于将来换实现时踩坑。
 * ─────────────────────────────────────────────────────────────────────────
 * ============================================================================
 */
final class WebAuthn
{
    private string $rpId;
    private string $origin;

    public function __construct(string $rpId = null, string $origin = null)
    {
        // 从请求推导，这样自定义域名与本地调试都能自动适配
        if ($rpId === null || $origin === null) {
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $host = preg_replace('/:\d+$/', '', $host) ?: 'localhost';
            $proto = 'http';
            if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
                $proto = 'https';
            } elseif (Config::get('app.trust_proxy', false)
                && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
                $proto = 'https';
            }
            $rpId   = $rpId   ?? $host;
            $origin = $origin ?? ($proto . '://' . ($_SERVER['HTTP_HOST'] ?? $host));
        }
        $this->rpId   = $rpId;
        $this->origin = rtrim($origin, '/');
    }

    public function rpId(): string
    {
        return $this->rpId;
    }

    // ================================================================ 注册

    /**
     * 生成注册选项（服务端下发，浏览器调 navigator.credentials.create）
     */
    public function beginRegistration(int $userId, string $userEmail, string $displayName): array
    {
        $challenge = Crypto::randB64(32);

        DB::conn()->delete('webauthn_challenges', 'expires_at < ?', [time()]);
        DB::conn()->insert('webauthn_challenges', [
            'challenge'  => hash('sha256', $challenge),
            'kind'       => 'register',
            'user_id'    => $userId,
            'expires_at' => time() + 300,
        ]);

        // 排除已注册的凭据，避免同一把密钥重复注册
        $existing = DB::conn()->all(
            'SELECT credential_id, transports FROM webauthn_credentials WHERE user_id = ?',
            [$userId]
        );
        $exclude = [];
        foreach ($existing as $row) {
            $item = ['type' => 'public-key', 'id' => (string) $row['credential_id']];
            $t = array_filter(explode(',', (string) $row['transports']));
            if ($t !== []) {
                $item['transports'] = array_values($t);
            }
            $exclude[] = $item;
        }

        return [
            'challenge' => $challenge,
            'rp'        => [
                'id'   => $this->rpId,
                'name' => (string) Settings::get('site.name', 'Vobl ID'),
            ],
            'user' => [
                // user.id 必须是字节串的 base64url（不能直接用邮箱或自增 ID）
                'id'          => Crypto::b64urlEncode('vobl-user-' . $userId),
                'name'        => $userEmail,
                'displayName' => $displayName !== '' ? $displayName : $userEmail,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],    // ES256（最通用，优先）
                ['type' => 'public-key', 'alg' => -257],  // RS256
                ['type' => 'public-key', 'alg' => -8],    // Ed25519
            ],
            'timeout'     => 60000,
            'attestation' => 'none',   // 不要求厂商证明，兼容性最好
            'excludeCredentials' => $exclude,
            'authenticatorSelection' => [
                // preferred 比 required 兼容性好 —— 部分认证器不支持用户验证
                'userVerification' => 'preferred',
                'residentKey'      => 'preferred',
                'requireResidentKey' => false,
            ],
        ];
    }

    /**
     * 完成注册校验。
     * @return array{ok:bool, error:string, credential:array}
     */
    public function finishRegistration(int $userId, array $response, string $deviceName = ''): array
    {
        try {
            $clientData = $this->decodeClientData($response['clientDataJSON'] ?? '', 'webauthn.create');
            $this->assertChallenge($clientData['challenge'], 'register', $userId);

            $attestationRaw = $this->b64u($response['attestationObject'] ?? '');
            $attestation = $this->cborDecode($attestationRaw);
            if (!is_array($attestation) || !isset($attestation['authData'])) {
                throw new \RuntimeException('attestationObject 解析失败');
            }
            $authData = $attestation['authData'];
            $parsed = $this->parseAuthData($authData);

            // rpIdHash 必须匹配当前域名 —— 这是「凭据与域名绑定」的校验点
            $expectedHash = hash('sha256', $this->rpId, true);
            if (!Crypto::equals($parsed['rpIdHash'], $expectedHash)) {
                throw new \RuntimeException('rpId 校验失败：该密钥不是为本站注册的（换域名后必须重新注册）');
            }
            if (($parsed['flags'] & 0x01) === 0) {
                throw new \RuntimeException('用户在场标志（UP）未设置，注册无效');
            }
            if ($parsed['credentialId'] === '' || $parsed['coseKey'] === null) {
                throw new \RuntimeException('authData 中缺少凭据数据');
            }

            $pem = $this->coseToPem($parsed['coseKey']);
            if ($pem === '') {
                throw new \RuntimeException('不支持的公钥算法（仅支持 ES256 / RS256 / Ed25519）');
            }

            $credentialId = Crypto::b64urlEncode($parsed['credentialId']);
            $exists = DB::conn()->one(
                'SELECT id FROM webauthn_credentials WHERE credential_id = ? LIMIT 1',
                [$credentialId]
            );
            if ($exists !== null) {
                throw new \RuntimeException('这把安全密钥已经注册过了，请直接使用它登录');
            }

            $name = $deviceName !== '' ? $deviceName : $this->guessDeviceName();

            DB::conn()->insert('webauthn_credentials', [
                'id'              => Crypto::randHex(16),
                'user_id'         => $userId,
                'credential_id'   => $credentialId,
                'public_key'      => $pem,
                'alg'             => (int) ($parsed['coseKey'][3] ?? -7),
                'counter'         => $parsed['counter'],
                'name'            => mb_substr($name, 0, 80),
                'aaguid'          => $this->formatAaguid($parsed['aaguid']),
                'transports'      => implode(',', array_slice((array) ($response['transports'] ?? []), 0, 6)),
                'backup_eligible' => ($parsed['flags'] & 0x08) ? 1 : 0,
                'created_at'      => time(),
                'last_used_at'    => 0,
            ]);

            return [
                'ok'    => true,
                'error' => '',
                'credential' => [
                    'name'  => $name,
                    'aaguid' => $this->formatAaguid($parsed['aaguid']),
                ],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'credential' => []];
        }
    }

    // ================================================================ 登录

    /**
     * 生成登录选项。
     * ★ 这个入口**不能要求登录**（它就是登录入口），所以 $userId 允许为 0。
     */
    public function beginLogin(int $userId = 0): array
    {
        $challenge = Crypto::randB64(32);

        DB::conn()->delete('webauthn_challenges', 'expires_at < ?', [time()]);
        DB::conn()->insert('webauthn_challenges', [
            'challenge'  => hash('sha256', $challenge),
            'kind'       => 'login',
            'user_id'    => $userId,
            'expires_at' => time() + 300,
        ]);

        $allow = [];
        if ($userId > 0) {
            $rows = DB::conn()->all(
                'SELECT credential_id, transports FROM webauthn_credentials WHERE user_id = ?',
                [$userId]
            );
            foreach ($rows as $row) {
                $item = ['type' => 'public-key', 'id' => (string) $row['credential_id']];
                $t = array_filter(explode(',', (string) $row['transports']));
                if ($t !== []) {
                    $item['transports'] = array_values($t);
                }
                $allow[] = $item;
            }
        }
        // 不传 allowCredentials 时走「可发现凭据」（resident key）流程

        return [
            'challenge'        => $challenge,
            'rpId'             => $this->rpId,
            'timeout'          => 60000,
            'userVerification' => 'preferred',
            'allowCredentials' => $allow,
        ];
    }

    /**
     * 完成登录校验。
     * @return array{ok:bool, error:string, user_id:int, credential_id:string}
     */
    public function finishLogin(array $response): array
    {
        try {
            $clientData = $this->decodeClientData($response['clientDataJSON'] ?? '', 'webauthn.get');
            $this->assertChallenge($clientData['challenge'], 'login', 0);

            $credentialId = (string) ($response['id'] ?? '');
            if ($credentialId === '') {
                throw new \RuntimeException('缺少凭据 ID');
            }
            $row = DB::conn()->one(
                'SELECT * FROM webauthn_credentials WHERE credential_id = ? LIMIT 1',
                [$credentialId]
            );
            if ($row === null) {
                throw new \RuntimeException('这把安全密钥没有在本站注册过');
            }

            $authDataRaw    = $this->b64u($response['authenticatorData'] ?? '');
            $signatureRaw   = $this->b64u($response['signature'] ?? '');
            if ($authDataRaw === '' || $signatureRaw === '') {
                throw new \RuntimeException('缺少签名数据');
            }

            $parsed = $this->parseAuthData($authDataRaw);

            $expectedHash = hash('sha256', $this->rpId, true);
            if (!Crypto::equals($parsed['rpIdHash'], $expectedHash)) {
                throw new \RuntimeException('rpId 校验失败');
            }
            if (($parsed['flags'] & 0x01) === 0) {
                throw new \RuntimeException('用户在场标志（UP）未设置');
            }

            // ★ 签名基串顺序不能反：authenticatorData ‖ SHA-256(clientDataJSON)
            $signedData = $authDataRaw . hash('sha256', $this->rawClientData($response['clientDataJSON'] ?? ''), true);

            if (!$this->verifySignature($signedData, $signatureRaw, (string) $row['public_key'], (int) $row['alg'])) {
                throw new \RuntimeException('签名验证失败');
            }

            // 签名计数器回退 = 凭据可能被克隆
            $stored = (int) $row['counter'];
            $current = $parsed['counter'];
            if ($current > 0 && $stored > 0 && $current <= $stored) {
                DB::conn()->insert('audit_logs', [
                    'ts' => time(), 'category' => 'security', 'action' => 'webauthn.counter_regress',
                    'actor_id' => (int) $row['user_id'], 'actor' => 'user:' . $row['user_id'],
                    'target' => (string) $row['name'], 'result' => 'fail',
                    'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'ua' => '', 
                    'detail' => sprintf('签名计数器未递增（库内 %d，本次 %d），疑似凭据被复制', $stored, $current),
                ]);
                // 计数器回退在「多设备同步的通行密钥」场景下是正常的（BE=1），
                // 只有单设备硬件密钥（BE=0）才视为攻击
                if ((int) $row['backup_eligible'] === 0) {
                    throw new \RuntimeException('检测到安全风险：签名计数器异常，请联系管理员');
                }
            }

            DB::conn()->update('webauthn_credentials',
                ['counter' => max($stored, $current), 'last_used_at' => time()],
                'id = ?', [$row['id']]
            );

            return [
                'ok'            => true,
                'error'         => '',
                'user_id'       => (int) $row['user_id'],
                'credential_id' => $credentialId,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'user_id' => 0, 'credential_id' => ''];
        }
    }

    // ================================================================ 凭据管理

    public static function listFor(int $userId): array
    {
        return DB::conn()->all(
            'SELECT id, credential_id, name, alg, aaguid, transports, backup_eligible,
                    created_at, last_used_at
             FROM webauthn_credentials WHERE user_id = ? ORDER BY created_at DESC',
            [$userId]
        );
    }

    public static function countFor(int $userId): int
    {
        return DB::conn()->count('SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = ?', [$userId]);
    }

    /**
     * 删除凭据。
     *
     * ★ 判断顺序至关重要：先查「有没有这条」（404），再判「是不是最后一把」（409）。
     *   反过来写的话，只剩 1 把时删除任何不存在的 ID 都会误报「这是最后一把」。
     */
    public static function delete(int $userId, string $id): array
    {
        $row = DB::conn()->one(
            'SELECT id FROM webauthn_credentials WHERE id = ? AND user_id = ? LIMIT 1',
            [$id, $userId]
        );
        if ($row === null) {
            return ['ok' => false, 'error' => '这把安全密钥不存在', 'status' => 404];
        }
        if (self::countFor($userId) <= 1) {
            return [
                'ok'     => false,
                'error'  => '这是最后一把安全密钥，移除后你将无法用密钥登录。请先添加一把新的。',
                'status' => 409,
            ];
        }
        DB::conn()->delete('webauthn_credentials', 'id = ? AND user_id = ?', [$id, $userId]);
        return ['ok' => true, 'error' => '', 'status' => 200];
    }

    /** 该用户是否可用密钥登录（登录页据此决定要不要显示按钮） */
    public static function hasAny(): array
    {
        $rows = DB::conn()->all(
            'SELECT DISTINCT p.user_id, u.email, u.display_name
             FROM webauthn_credentials wc JOIN users u ON u.id = wc.user_id
             WHERE u.status = "active" LIMIT 5'
        );
        return $rows;
    }

    // ================================================================ 校验辅助

    private function rawClientData(string $b64): string
    {
        $raw = $this->b64u($b64);
        if ($raw === '') {
            throw new \RuntimeException('clientDataJSON 为空');
        }
        return $raw;
    }

    private function decodeClientData(string $b64, string $expectedType): array
    {
        $raw = $this->rawClientData($b64);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('clientDataJSON 不是合法 JSON');
        }
        if (($data['type'] ?? '') !== $expectedType) {
            throw new \RuntimeException('操作类型不匹配（期望 ' . $expectedType . '）');
        }
        // origin 必须完全一致 —— 防钓鱼的关键
        $origin = rtrim((string) ($data['origin'] ?? ''), '/');
        if ($origin !== $this->origin) {
            throw new \RuntimeException('来源校验失败：凭据来自 ' . $origin . '，本站是 ' . $this->origin);
        }
        return $data;
    }

    private function assertChallenge(string $challengeB64, string $kind, int $userId): void
    {
        $hash = hash('sha256', $challengeB64);
        $row = DB::conn()->one(
            'SELECT * FROM webauthn_challenges WHERE challenge = ? AND kind = ? LIMIT 1',
            [$hash, $kind]
        );
        if ($row === null) {
            throw new \RuntimeException('挑战值无效（可能已使用或已过期）');
        }
        // 用后即删：只查过期时间是可以重放的
        DB::conn()->delete('webauthn_challenges', 'challenge = ?', [$hash]);

        if ((int) $row['expires_at'] < time()) {
            throw new \RuntimeException('操作超时，请重试');
        }
        if ($kind === 'register' && $userId > 0 && (int) $row['user_id'] !== $userId) {
            throw new \RuntimeException('挑战值与当前账号不匹配');
        }
    }

    /**
     * 解析 authenticatorData
     * 结构：rpIdHash(32) | flags(1) | counter(4) | [aaguid(16) | credIdLen(2) | credId | COSE公钥]
     */
    private function parseAuthData(string $data): array
    {
        if (strlen($data) < 37) {
            throw new \RuntimeException('authData 长度不足');
        }
        $rpIdHash = substr($data, 0, 32);
        $flags    = ord($data[32]);
        $counter  = unpack('N', substr($data, 33, 4))[1];

        $result = [
            'rpIdHash'     => $rpIdHash,
            'flags'        => $flags,
            'counter'      => (int) $counter,
            'aaguid'       => '',
            'credentialId' => '',
            'coseKey'      => null,
        ];

        // 0x40 = AT 标志位，表示带了认证器数据
        if (($flags & 0x40) !== 0 && strlen($data) >= 55) {
            $result['aaguid'] = substr($data, 37, 16);
            $credIdLen = unpack('n', substr($data, 53, 2))[1];
            if (strlen($data) < 55 + $credIdLen) {
                throw new \RuntimeException('凭据 ID 长度异常');
            }
            $result['credentialId'] = substr($data, 55, $credIdLen);
            $coseStart = 55 + $credIdLen;
            if (strlen($data) > $coseStart) {
                $result['coseKey'] = $this->cborDecode(substr($data, $coseStart));
            }
        }
        return $result;
    }

    private function formatAaguid(string $raw): string
    {
        if (strlen($raw) !== 16) {
            return '';
        }
        $hex = bin2hex($raw);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12)
        );
    }

    // ================================================================ 签名验证

    /**
     * 验证签名。同时兼容 DER 与 raw 两种签名格式。
     */
    private function verifySignature(string $data, string $signature, string $pem, int $alg): bool
    {
        $algo = $this->opensslAlgo($alg, $pem);
        if ($algo === null) {
            return false;
        }

        // openssl_verify 对 EC 密钥原生接受 DER 签名（PHP 与 WebCrypto 的关键差异）
        $ok = @openssl_verify($data, $signature, $pem, $algo);
        if ($ok === 1) {
            return true;
        }

        // 兜底：若客户端给了定长 raw 签名（64 字节），转成 DER 再试一次
        if (strlen($signature) === 64 && $signature[0] !== "\x30") {
            $der = $this->rawToDer($signature);
            $ok2 = @openssl_verify($data, $der, $pem, $algo);
            return $ok2 === 1;
        }

        // 再兜底：若给了 DER 但密钥是 RSA，用默认 SHA256
        if ($algo !== OPENSSL_ALGO_SHA256) {
            return @openssl_verify($data, $signature, $pem, OPENSSL_ALGO_SHA256) === 1;
        }
        return false;
    }

    private function opensslAlgo(int $coseAlg, string $pem): ?int
    {
        return match ($coseAlg) {
            -7, -35, -36 => OPENSSL_ALGO_SHA256,   // ES256 / ES384 / ES512 都按 SHA256 处理 EC 曲线
            -257, -258, -259 => OPENSSL_ALGO_SHA256, // RS256/384/512
            -8 => OPENSSL_ALGO_SHA256,             // Ed25519（openssl 会忽略 algo 参数）
            default => OPENSSL_ALGO_SHA256,
        };
    }

    /**
     * raw 签名（r‖s 定长）→ ASN.1 DER
     * 仅作为兜底，正常情况下 WebAuthn 给的就是 DER。
     */
    private function rawToDer(string $raw): string
    {
        $len = intdiv(strlen($raw), 2);
        $r = ltrim(substr($raw, 0, $len), "\x00");
        $s = ltrim(substr($raw, $len), "\x00");
        if ($r === '') {
            $r = "\x00";
        }
        if ($s === '') {
            $s = "\x00";
        }
        // 最高位为 1 时必须补前导 0，否则会被解析成负数
        if (ord($r[0]) & 0x80) {
            $r = "\x00" . $r;
        }
        if (ord($s[0]) & 0x80) {
            $s = "\x00" . $s;
        }
        $seq = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;
        return "\x30" . chr(strlen($seq)) . $seq;
    }

    // ================================================================ COSE → PEM

    /**
     * COSE 公钥（整数键的 CBOR map）→ PEM
     *
     * COSE 键位约定：
     *   1 = kty   2=EC2  3=RSA  1=OKP
     *   3 = alg   -7=ES256  -257=RS256  -8=Ed25519
     *  -1 = crv   1=P-256（EC）  6=Ed25519（OKP）；RSA 时这里是模数 n
     *  -2 = x / n
     *  -3 = y / e
     */
    private function coseToPem(array $cose): string
    {
        $kty = (int) ($cose[1] ?? 0);

        if ($kty === 2) {
            // EC2：限定 P-256（-1 = 1）
            $crv = (int) ($cose[-1] ?? 0);
            if ($crv !== 1) {
                // 只支持 P-256。P-384/521 需要额外映射曲线 OID
                return '';
            }
            $x = (string) ($cose[-2] ?? '');
            $y = (string) ($cose[-3] ?? '');
            if (strlen($x) !== 32 || strlen($y) !== 32) {
                return '';
            }
            // SPKI = SEQUENCE { SEQUENCE { OID ecPublicKey, OID prime256v1 }, BIT STRING (0x04||x||y) }
            $algId = $this->derSequence(
                $this->derOid('1.2.840.10045.2.1') . $this->derOid('1.2.840.10045.3.1.7')
            );
            $point = "\x04" . $x . $y;
            $spki = $this->derSequence($algId . $this->derBitString($point));
            return $this->pem('PUBLIC KEY', $spki);
        }

        if ($kty === 3) {
            // RSA：-1 = n, -2 = e
            $n = (string) ($cose[-1] ?? '');
            $e = (string) ($cose[-2] ?? '');
            if ($n === '' || $e === '') {
                return '';
            }
            $rsaKey = $this->derSequence($this->derInteger($n) . $this->derInteger($e));
            $algId = $this->derSequence($this->derOid('1.2.840.113549.1.1.1') . "\x05\x00");
            $spki = $this->derSequence($algId . $this->derBitString($rsaKey));
            return $this->pem('PUBLIC KEY', $spki);
        }

        if ($kty === 1) {
            // OKP：只支持 Ed25519（crv = 6）
            $crv = (int) ($cose[-1] ?? 0);
            if ($crv !== 6) {
                return '';
            }
            $x = (string) ($cose[-2] ?? '');
            if (strlen($x) !== 32) {
                return '';
            }
            $algId = $this->derSequence($this->derOid('1.3.101.112'));
            $spki = $this->derSequence($algId . $this->derBitString($x));
            return $this->pem('PUBLIC KEY', $spki);
        }

        return '';
    }

    // ---------------------------------------------------------- 最小 DER 编码器

    private function derLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = '';
        while ($len > 0) {
            $bytes = chr($len & 0xFF) . $bytes;
            $len >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function derSequence(string $content): string
    {
        return "\x30" . $this->derLength(strlen($content)) . $content;
    }

    private function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . $this->derLength(strlen($bytes)) . $bytes;
    }

    private function derBitString(string $bytes): string
    {
        // 前导 0x00 表示「没有未使用位」
        $content = "\x00" . $bytes;
        return "\x03" . $this->derLength(strlen($content)) . $content;
    }

    private function derOid(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        $first = array_shift($parts);
        $second = array_shift($parts);
        $body = chr($first * 40 + $second);
        foreach ($parts as $p) {
            $chunk = chr($p & 0x7F);
            $p >>= 7;
            while ($p > 0) {
                $chunk = chr(($p & 0x7F) | 0x80) . $chunk;
                $p >>= 7;
            }
            $body .= $chunk;
        }
        return "\x06" . $this->derLength(strlen($body)) . $body;
    }

    private function pem(string $label, string $der): string
    {
        $b64 = chunk_split(base64_encode($der), 64, "\n");
        return "-----BEGIN {$label}-----\n{$b64}-----END {$label}-----\n";
    }

    // ================================================================ CBOR 解码

    /**
     * CBOR 解码（WebAuthn 用到的子集：major type 0-7）
     *
     * ★ 最容易踩的坑：map 的键是「整数」，COSE 里还大量使用负数（-1 / -2 / -3）。
     *   如果把 map 当成字符串键的关联数组处理，取 $map['-1'] 会得到 null。
     */
    private function cborDecode(string $data, int &$offset = 0)
    {
        $len = strlen($data);
        if ($offset >= $len) {
            throw new \RuntimeException('CBOR 数据不完整');
        }

        $byte = ord($data[$offset]);
        $offset++;

        $major = $byte >> 5;
        $additional = $byte & 0x1F;

        // 读取附加信息里的长度值
        //
        // ★ 这里必须用 ord()：readBytes() 返回的是「字符串」，
        //   $str[0] 在 PHP 里得到的是「单字符字符串」而不是整数。
        //   漏了 ord() 会让 $value 变成字符串，后续按位运算与长度比较全部出错，
        //   最终在 readBytes() 的 int 参数上抛 TypeError。
        $value = $additional;
        if ($additional === 24) {
            $value = ord($this->readBytes($data, $offset, 1));
        } elseif ($additional === 25) {
            $b = $this->readBytes($data, $offset, 2);
            $value = (ord($b[0]) << 8) | ord($b[1]);
        } elseif ($additional === 26) {
            $b = $this->readBytes($data, $offset, 4);
            $value = (ord($b[0]) << 24) | (ord($b[1]) << 16) | (ord($b[2]) << 8) | ord($b[3]);
        } elseif ($additional === 27) {
            $b = $this->readBytes($data, $offset, 8);
            $value = 0;
            for ($i = 0; $i < 8; $i++) {
                $value = ($value << 8) | ord($b[$i]);
            }
        } elseif ($additional === 31) {
            throw new \RuntimeException('不支持不定长 CBOR 编码');
        }

        switch ($major) {
            case 0: // 无符号整数
                return $value;

            case 1: // ★ 负整数：CBOR 用 -1-n 表示
                return -1 - $value;

            case 2: // 字节串
                return $this->readBytes($data, $offset, $value);

            case 3: // 文本串
                return $this->readBytes($data, $offset, $value);

            case 4: // 数组
                $arr = [];
                for ($i = 0; $i < $value; $i++) {
                    $arr[] = $this->cborDecode($data, $offset);
                }
                return $arr;

            case 5: // ★ map：键可能是整数或负数
                $map = [];
                for ($i = 0; $i < $value; $i++) {
                    $k = $this->cborDecode($data, $offset);
                    $v = $this->cborDecode($data, $offset);
                    // 整数键直接用作数组键，负数键 PHP 也支持
                    $map[$k] = $v;
                }
                return $map;

            case 6: // 标签：忽略标签本身，返回其内容
                return $this->cborDecode($data, $offset);

            case 7: // 简单值 / 浮点
                return match ($additional) {
                    20 => false,
                    21 => true,
                    22 => null,
                    23 => null,
                    default => $value,
                };
        }
        throw new \RuntimeException('无法解析的 CBOR 类型：major ' . $major);
    }

    private function readBytes(string $data, int &$offset, int $length): string
    {
        if ($length === 0) {
            return '';
        }
        if ($offset + $length > strlen($data)) {
            throw new \RuntimeException('CBOR 数据被截断');
        }
        $out = substr($data, $offset, $length);
        $offset += $length;
        return $out;
    }

    /**
     * CBOR 编码（仅测试与构造用，线上流程不需要）
     */
    public function cborEncode($value): string
    {
        if (is_int($value)) {
            return $value >= 0
                ? $this->cborHead(0, $value)
                : $this->cborHead(1, -1 - $value);
        }
        if (is_string($value)) {
            return $this->cborHead(2, strlen($value)) . $value;
        }
        if (is_array($value)) {
            // 判断是 list 还是 map
            $isList = array_keys($value) === range(0, count($value) - 1);
            if ($isList) {
                $out = $this->cborHead(4, count($value));
                foreach ($value as $v) {
                    $out .= $this->cborEncode($v);
                }
                return $out;
            }
            $out = $this->cborHead(5, count($value));
            foreach ($value as $k => $v) {
                $out .= $this->cborEncode($k) . $this->cborEncode($v);
            }
            return $out;
        }
        if ($value === null) {
            return "\xf6";
        }
        if (is_bool($value)) {
            return $value ? "\xf5" : "\xf4";
        }
        throw new \RuntimeException('不支持的 CBOR 值类型');
    }

    private function cborHead(int $major, int $value): string
    {
        $prefix = $major << 5;
        if ($value < 24) {
            return chr($prefix | $value);
        }
        if ($value < 0x100) {
            return chr($prefix | 24) . chr($value);
        }
        if ($value < 0x10000) {
            return chr($prefix | 25) . pack('n', $value);
        }
        return chr($prefix | 26) . pack('N', $value);
    }

    // ================================================================ 工具

    private function b64u(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        try {
            return Crypto::b64urlDecode($value);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** 从 UA 猜一个设备名，允许用户之后改名 */
    private function guessDeviceName(): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (stripos($ua, 'iPhone') !== false) return 'iPhone';
        if (stripos($ua, 'iPad') !== false)   return 'iPad';
        if (stripos($ua, 'Android') !== false) return 'Android 设备';
        if (stripos($ua, 'Macintosh') !== false) return 'Mac';
        if (stripos($ua, 'Windows') !== false) return 'Windows 电脑';
        if (stripos($ua, 'Linux') !== false)  return 'Linux 设备';
        return '安全密钥';
    }
}
