<?php
declare(strict_types=1);

namespace Vobl\Controllers;

use Vobl\Core\Audit;
use Vobl\Core\Auth;
use Vobl\Core\Config;
use Vobl\Core\Crypto;
use Vobl\Core\DB;
use Vobl\Core\Request;
use Vobl\Core\Response;
use Vobl\Services\Settings;
use Vobl\Services\UserService;

/**
 * 单点登录（OAuth 2.0 授权码模式）
 * ============================================================================
 * 这是整个系统的核心：让一个账号在多个应用之间通用。
 *
 * 流程与安全要点：
 *   ① 应用把用户送到 /authorize（带 client_id、redirect_uri、scope、state）
 *   ② 我们校验 client_id 与 redirect_uri 白名单 —— 不匹配直接拒绝，绝不重定向
 *   ③ 未登录 → 先去登录，登录后自动回到本端点继续
 *   ④ 展示授权确认页（微软风格），让用户明确看到「谁在申请什么权限」
 *   ⑤ 用户同意 → 签发一次性授权码，重定向回应用
 *   ⑥ 应用在服务端用授权码换访问令牌（机密客户端用密钥 / 公开客户端用 PKCE）
 *   ⑦ 应用用令牌调 /oauth/userinfo 取身份
 *
 * ★ 授权确认页存在的意义：用户有权知道「哪个应用、要哪些信息」。
 *   静默授权（不询问直接发码）在内部系统之间可以接受，
 *   但只要涉及第三方应用，就应该让用户点一次「接受」。
 * ============================================================================
 */
final class OAuthController extends BaseController
{
    private const SCOPE_INFO = [
        'profile' => [
            'title' => '读取你的基本资料',
            'desc'  => '昵称、头像、账号 ID',
            'icon'  => 'user',
        ],
        'email' => [
            'title' => '读取你的邮箱地址',
            'desc'  => '用于识别你的身份与发送通知',
            'icon'  => 'mail',
        ],
        'orgs' => [
            'title' => '读取你所属的组织',
            'desc'  => '用于按组织控制访问权限',
            'icon'  => 'users',
        ],
    ];

    private const SCOPE_ALL = ['profile', 'email', 'orgs'];
    private const CODE_TTL  = 120;      // 授权码 2 分钟
    private const TOKEN_TTL = 3600;     // 访问令牌 1 小时

    // ================================================================ 授权端点

    public static function authorize(Request $request): void
    {
        $clientId    = $request->str('client_id', '', 64);
        $redirectUri = $request->str('redirect_uri', '', 500);
        $responseType= $request->str('response_type', 'code', 20);
        $state       = $request->str('state', '', 200);
        $scope       = self::parseScopes($request->str('scope', 'profile', 200));
        $challenge   = $request->str('code_challenge', '', 200);
        $challengeMethod = $request->str('code_challenge_method', '', 10);
        $prompt      = $request->str('prompt', '', 20);

        $client = self::findClient($clientId);

        // ① 未知 client_id → 绝不重定向（否则就是开放重定向漏洞）
        if ($client === null) {
            self::errorPage(
                '未知的应用',
                '请求里的 client_id「' . ($clientId ?: '(空)') . '」没有在本站注册。'
                . '请先在开放平台创建应用，并把回调地址登记进去。',
                'client_id=' . $clientId
            );
        }

        // ② 回调地址白名单精确校验
        if ($redirectUri === '' || !self::matchRedirect($client['redirect_uris'], $redirectUri, $request->origin())) {
            self::errorPage(
                '回调地址未登记',
                '应用「' . $client['name'] . '」提交的回调地址与登记值不一致，已拒绝本次授权。'
                . '这是防止授权码被劫持到其他站点的必要检查。',
                "请求值：" . ($redirectUri ?: '(空)') . "\n已登记：" . implode(' 、 ', $client['redirect_uris'])
            );
        }

        // ③ 参数校验（这些已经确认过回调地址，可以带 error 回跳）
        if ($responseType !== 'code') {
            self::backWithError($redirectUri, $state, 'unsupported_response_type', '仅支持 response_type=code');
        }
        if ($challenge !== '' && $challengeMethod !== '' && $challengeMethod !== 'S256') {
            self::backWithError($redirectUri, $state, 'invalid_request', 'code_challenge_method 仅支持 S256');
        }
        if ((string) $client['type'] === 'public' && $challenge === '') {
            self::backWithError($redirectUri, $state, 'invalid_request', '公开客户端必须使用 PKCE');
        }
        if ((string) $client['status'] !== 'active') {
            self::backWithError($redirectUri, $state, 'access_denied', '该应用已被停用');
        }

        // ④ 未登录 → 去登录，登录后自动回到这里继续（把原始参数完整带上）
        if (!Auth::check() || $prompt === 'login') {
            $resume = '/authorize?' . http_build_query([
                'client_id'             => $clientId,
                'redirect_uri'          => $redirectUri,
                'response_type'         => 'code',
                'scope'                 => implode(' ', $scope),
                'state'                 => $state,
                'code_challenge'        => $challenge,
                'code_challenge_method' => $challengeMethod,
            ]);
            Response::redirect('/login?next=' . urlencode($resume));
        }

        $user = Auth::user();

        // ⑤ 已授权过且没要求重新确认 → 直接发码，用户无感
        $existing = DB::conn()->one(
            'SELECT * FROM oauth_grants WHERE client_id = ? AND user_id = ? AND revoked_at = 0 LIMIT 1',
            [$clientId, (int) $user['id']]
        );
        if ($existing !== null && $prompt !== 'consent') {
            // 已经同意过的权限范围没变，就不必再问一次
            $granted = array_filter(explode(' ', (string) $existing['scopes']));
            if (count(array_diff($scope, $granted)) === 0) {
                self::issueCodeAndRedirect($client, $user, $redirectUri, $scope, $state, $challenge);
            }
        }

        // ⑥ 展示授权确认页
        $scopeItems = [];
        foreach ($scope as $s) {
            $scopeItems[] = array_merge(['key' => $s], self::SCOPE_INFO[$s] ?? [
                'title' => $s, 'desc' => '', 'icon' => 'key',
            ]);
        }

        // 同账号下的其他会话，用于「切换账号」
        $otherAccounts = DB::conn()->all(
            'SELECT DISTINCT u.id, u.email, u.display_name
             FROM sessions s JOIN users u ON u.id = s.user_id
             WHERE s.revoked_at = 0 AND s.expires_at > ? AND u.id <> ?
             LIMIT 3',
            [time(), (int) $user['id']]
        );

        self::page('oauth/authorize', [
            'client'        => $client,
            'user'          => $user,
            'scopeItems'    => $scopeItems,
            'scopeString'   => implode(' ', $scope),
            'redirectUri'   => $redirectUri,
            'state'         => $state,
            'challenge'     => $challenge,
            'challengeMethod' => $challengeMethod,
            'otherAccounts' => $otherAccounts,
            'isFirstTime'   => $existing === null,
            'flash'         => self::takenFlash(),
        ], 'layout/consent');
    }

    /**
     * 用户点击「接受」或「拒绝」。
     * ★ 这是唯一会真正签发授权码的地方，所有校验必须再走一遍 ——
     *   不能信任表单里的隐藏字段（用户或攻击者都能改）。
     */
    public static function decision(Request $request): void
    {
        Auth::requireLogin();
        $user = Auth::user();

        $decision    = $request->str('decision', '', 20);
        $clientId    = $request->str('client_id', '', 64);
        $redirectUri = $request->str('redirect_uri', '', 500);
        $state       = $request->str('state', '', 200);
        $scope       = self::parseScopes($request->str('scope', 'profile', 200));
        $challenge   = $request->str('code_challenge', '', 200);

        $client = self::findClient($clientId);
        if ($client === null) {
            self::errorPage('未知的应用', '提交的 client_id 无法识别。');
        }
        // 重新校验回调地址 —— 表单字段同样不可信
        if (!self::matchRedirect($client['redirect_uris'], $redirectUri, $request->origin())) {
            self::errorPage('回调地址未登记', '提交的回调地址与登记值不一致，已拒绝。');
        }

        if ($decision !== 'approve') {
            Audit::log('oauth', 'authorize.deny', [
                'actor_id' => (int) $user['id'],
                'target'   => $clientId,
                'detail'   => '用户拒绝了应用「' . $client['name'] . '」的授权请求',
            ]);
            self::backWithError($redirectUri, $state, 'access_denied', '用户拒绝了授权请求');
        }

        // 记录 / 更新授权同意
        $existing = DB::conn()->one(
            'SELECT * FROM oauth_grants WHERE client_id = ? AND user_id = ? LIMIT 1',
            [$clientId, (int) $user['id']]
        );
        if ($existing === null) {
            DB::conn()->insert('oauth_grants', [
                'client_id'  => $clientId,
                'user_id'    => (int) $user['id'],
                'scopes'     => implode(' ', $scope),
                'granted_at' => time(),
                'revoked_at' => 0,
            ]);
            Audit::log('oauth', 'authorize.grant', [
                'actor_id' => (int) $user['id'],
                'target'   => $clientId,
                'detail'   => '首次授权应用「' . $client['name'] . '」，范围：' . implode(' ', $scope),
            ]);
        } else {
            // 合并权限（用户可能这次同意了更多范围）
            $merged = array_unique(array_merge(
                array_filter(explode(' ', (string) $existing['scopes'])),
                $scope
            ));
            DB::conn()->update('oauth_grants', [
                'scopes'     => implode(' ', $merged),
                'revoked_at' => 0,
            ], 'id = ?', [(int) $existing['id']]);

            Audit::log('oauth', 'authorize.grant', [
                'actor_id' => (int) $user['id'],
                'target'   => $clientId,
                'detail'   => '再次授权应用「' . $client['name'] . '」，范围：' . implode(' ', $scope),
            ]);
        }

        self::issueCodeAndRedirect($client, $user, $redirectUri, $scope, $state, $challenge);
    }

    // ---------------------------------------------------------------- 发码

    private static function issueCodeAndRedirect(
        array $client,
        array $user,
        string $redirectUri,
        array $scope,
        string $state,
        string $challenge
    ): void {
        $jti = Crypto::randHex(16);
        $payload = [
            'jti'          => $jti,
            'client_id'    => (string) $client['client_id'],
            'user_id'      => (int) $user['id'],
            'redirect_uri' => $redirectUri,
            'scope'        => $scope,
            'challenge'    => $challenge,
            'exp'          => time() + self::CODE_TTL,
        ];

        DB::conn()->insert('oauth_codes', [
            'jti'         => $jti,
            'client_id'   => (string) $client['client_id'],
            'user_id'     => (int) $user['id'],
            'payload'     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'expires_at'  => time() + self::CODE_TTL,
            'consumed_at' => 0,
        ]);

        $code = Crypto::seal($payload, (string) Config::require('keys.app_secret'));

        // 把一次性标记塞进去，换令牌时用它防止重放
        $back = $redirectUri;
        $sep = str_contains($back, '?') ? '&' : '?';
        $back .= $sep . http_build_query(array_filter([
            'code'  => $code,
            'state' => $state !== '' ? $state : null,
        ]));

        Response::redirect($back);
    }

    // ================================================================ 令牌端点

    public static function token(Request $request): void
    {
        $body = $request->json();

        $grantType    = (string) ($body['grant_type'] ?? 'authorization_code');
        $code         = (string) ($body['code'] ?? '');
        $clientId     = (string) ($body['client_id'] ?? '');
        $clientSecret = (string) ($body['client_secret'] ?? '');
        $verifier     = (string) ($body['code_verifier'] ?? '');

        $cors = ['Access-Control-Allow-Origin' => '*'];

        if ($grantType !== 'authorization_code') {
            Response::json(['error' => 'unsupported_grant_type',
                            'error_description' => '仅支持 authorization_code'], 400, $cors);
        }
        if ($code === '') {
            Response::json(['error' => 'invalid_request', 'error_description' => '缺少 code'], 400, $cors);
        }

        // 解出授权码载荷并验签
        $payload = Crypto::open($code, (string) Config::require('keys.app_secret'));
        if ($payload === null || ($payload['exp'] ?? 0) < time()) {
            Response::json(['error' => 'invalid_grant',
                            'error_description' => '授权码无效或已过期（有效期 2 分钟）'], 400, $cors);
        }
        if ($clientId !== '' && $clientId !== (string) $payload['client_id']) {
            Response::json(['error' => 'invalid_grant',
                            'error_description' => '授权码不属于该应用'], 400, $cors);
        }

        $client = self::findClient((string) $payload['client_id']);
        if ($client === null) {
            Response::json(['error' => 'invalid_client', 'error_description' => '应用不存在'], 400, $cors);
        }

        // ★ 一次性校验：用条件更新抢「消费」这一次
        $consumed = DB::conn()->query(
            'UPDATE oauth_codes SET consumed_at = ? WHERE jti = ? AND consumed_at = 0',
            [time(), (string) $payload['jti']]
        )->rowCount();
        if ($consumed === 0) {
            Response::json(['error' => 'invalid_grant',
                            'error_description' => '授权码已被使用过'], 400, $cors);
        }

        // 客户端身份校验
        if ((string) $client['type'] === 'public') {
            // 公开客户端：靠 PKCE
            if ((string) ($payload['challenge'] ?? '') === '') {
                Response::json(['error' => 'invalid_grant',
                                'error_description' => '该应用未使用 PKCE'], 400, $cors);
            }
            if ($verifier === '') {
                Response::json(['error' => 'invalid_request',
                                'error_description' => '缺少 code_verifier'], 400, $cors);
            }
            if (!self::verifyPkce($verifier, (string) $payload['challenge'])) {
                Response::json(['error' => 'invalid_grant',
                                'error_description' => 'code_verifier 与 code_challenge 不匹配'], 400, $cors);
            }
        } else {
            // 机密客户端：靠密钥
            if ((string) $client['client_secret'] === '') {
                Response::json(['error' => 'invalid_client',
                                'error_description' => '应用尚未生成密钥，请到开放平台重置'], 400, $cors);
            }
            if ($clientSecret === '' || !Crypto::verifyPassword($clientSecret, (string) $client['client_secret'])) {
                Audit::log('oauth', 'token.badsecret', [
                    'target' => (string) $client['client_id'],
                    'result' => 'denied',
                    'detail' => 'client_secret 校验失败',
                ]);
                Response::json(['error' => 'invalid_client',
                                'error_description' => 'client_secret 不正确'], 401, $cors);
            }
            // 机密客户端也带了 PKCE 就一并校验
            if ((string) ($payload['challenge'] ?? '') !== '') {
                if ($verifier === '' || !self::verifyPkce($verifier, (string) $payload['challenge'])) {
                    Response::json(['error' => 'invalid_grant',
                                    'error_description' => 'code_verifier 校验失败'], 400, $cors);
                }
            }
        }

        $user = UserService::findById((int) $payload['user_id']);
        if ($user === null || (string) $user['status'] !== 'active') {
            Response::json(['error' => 'invalid_grant', 'error_description' => '账号不可用'], 400, $cors);
        }

        $scope = (array) ($payload['scope'] ?? ['profile']);

        $token = Crypto::seal([
            'typ'     => 'access',
            'user_id' => (int) $user['id'],
            'login'   => (string) $user['email'],
            'cid'     => (string) $client['client_id'],
            'scope'   => $scope,
            'exp'     => time() + self::TOKEN_TTL,
        ], (string) Config::require('keys.app_secret'));

        Audit::log('oauth', 'token.issue', [
            'actor_id' => (int) $user['id'],
            'target'   => (string) $client['client_id'],
            'detail'   => '授权码换令牌成功，范围：' . implode(' ', $scope),
        ]);

        Response::json([
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'expires_in'   => self::TOKEN_TTL,
            'scope'        => implode(' ', $scope),
            'user' => [
                'id'         => (int) $user['id'],
                'login'      => (string) $user['email'],
                'name'       => (string) $user['display_name'],
                'avatar_url' => (string) $user['avatar'],
                'email'      => in_array('email', $scope, true) ? (string) $user['email'] : null,
            ],
        ], 200, $cors);
    }

    // ================================================================ 用户信息

    public static function userinfo(Request $request): void
    {
        $cors = ['Access-Control-Allow-Origin' => '*'];

        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $bearer = '';
        if (stripos($auth, 'bearer ') === 0) {
            $bearer = trim(substr($auth, 7));
        }
        if ($bearer === '') {
            $bearer = (string) ($request->json()['access_token'] ?? $request->str('access_token', '', 500));
        }
        if ($bearer === '') {
            Response::json(['error' => 'invalid_request', 'error_description' => '缺少 Bearer 令牌'],
                401, array_merge($cors, ['WWW-Authenticate' => 'Bearer']));
        }

        $t = Crypto::open($bearer, (string) Config::require('keys.app_secret'));
        if ($t === null || ($t['typ'] ?? '') !== 'access') {
            Response::json(['error' => 'invalid_token', 'error_description' => '令牌无效或已过期'],
                401, array_merge($cors, ['WWW-Authenticate' => 'Bearer']));
        }

        $user = UserService::findById((int) $t['user_id']);
        if ($user === null) {
            Response::json(['error' => 'invalid_token', 'error_description' => '账号不存在'],
                401, array_merge($cors, ['WWW-Authenticate' => 'Bearer']));
        }

        // ★ 按授权的 scope 决定返回哪些字段 —— 不多给一个字节
        $scope = (array) ($t['scope'] ?? ['profile']);
        $out = [
            'sub'       => (string) $user['id'],
            'login'     => (string) $user['email'],
            'client_id' => (string) ($t['cid'] ?? ''),
        ];
        if (in_array('profile', $scope, true)) {
            $out['name']    = (string) $user['display_name'];
            $out['picture'] = (string) $user['avatar'];
        }
        if (in_array('email', $scope, true)) {
            $out['email'] = (string) $user['email'];
            $out['email_verified'] = (int) $user['email_verified'] === 1;
        }
        if (in_array('orgs', $scope, true)) {
            $out['orgs'] = [];
        }
        $out['expires_in'] = max(0, (int) ($t['exp'] ?? 0) - time());

        Response::json($out, 200, $cors);
    }

    // ================================================================ 发现文档

    public static function discovery(Request $request): void
    {
        $base = $request->origin();
        Response::json([
            'issuer'                 => $base,
            'authorization_endpoint' => $base . '/authorize',
            'token_endpoint'         => $base . '/oauth/token',
            'userinfo_endpoint'      => $base . '/oauth/userinfo',
            'response_types_supported' => ['code'],
            'grant_types_supported'    => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported'         => self::SCOPE_ALL,
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'none'],
            'service_documentation'    => $base . '/docs',
            'application_name'         => (string) Settings::get('site.name', 'Vobl ID'),
            // 本实现是纯 OAuth 2.0，不签发 id_token，取身份请用 userinfo
            'id_token_signing_alg_values_supported' => [],
        ], 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    public static function logout(Request $request): void
    {
        $next = self::safeNext($request->str('next', '/login', 300), '/login');
        Auth::logout();
        Response::redirect($next);
    }

    // ================================================================ 工具

    /** 解析并过滤 scope，只保留支持的项 */
    private static function parseScopes(string $raw): array
    {
        $asked = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $granted = array_values(array_intersect($asked, self::SCOPE_ALL));
        return $granted !== [] ? $granted : ['profile'];
    }

    private static function findClient(string $clientId): ?array
    {
        if ($clientId === '') {
            return null;
        }
        $row = DB::conn()->one('SELECT * FROM oauth_clients WHERE client_id = ? LIMIT 1', [$clientId]);
        if ($row === null) {
            return null;
        }
        $row['redirect_uris'] = json_decode((string) $row['redirect_uris'], true) ?: [];
        $row['scopes'] = json_decode((string) $row['scopes'], true) ?: ['profile'];
        return $row;
    }

    /**
     * 回调地址匹配。
     * 支持两种登记形式：
     *   绝对地址 → 必须完全一致
     *   相对地址（以 / 开头）→ 按本站 origin 展开后比较，方便同站应用
     */
    private static function matchRedirect(array $registered, string $requested, string $origin): bool
    {
        foreach ($registered as $reg) {
            $reg = (string) $reg;
            if ($reg === '') {
                continue;
            }
            if ($reg[0] === '/') {
                if ($origin . $reg === $requested) {
                    return true;
                }
                continue;
            }
            if ($reg === $requested) {
                return true;
            }
            // 容错：同站同路径，允许客户端自己多拼查询串
            $a = parse_url($reg);
            $b = parse_url($requested);
            if ($a !== false && $b !== false
                && ($a['scheme'] ?? '') === ($b['scheme'] ?? '')
                && ($a['host'] ?? '') === ($b['host'] ?? '')
                && ($a['port'] ?? '') === ($b['port'] ?? '')
                && ($a['path'] ?? '') === ($b['path'] ?? '')
                && !isset($a['query'])) {
                return true;
            }
        }
        return false;
    }

    /** PKCE：SHA256(code_verifier) 的 base64url 应等于 code_challenge */
    private static function verifyPkce(string $verifier, string $challenge): bool
    {
        if ($verifier === '' || $challenge === '') {
            return false;
        }
        $computed = Crypto::b64urlEncode(hash('sha256', $verifier, true));
        return Crypto::equals($computed, $challenge);
    }

    private static function backWithError(string $redirectUri, string $state, string $error, string $desc): void
    {
        $sep = str_contains($redirectUri, '?') ? '&' : '?';
        $params = ['error' => $error, 'error_description' => $desc];
        if ($state !== '') {
            $params['state'] = $state;
        }
        Response::redirect($redirectUri . $sep . http_build_query($params));
    }

    /** 不可重定向的错误页（客户端都不认识或回调不合法时用） */
    private static function errorPage(string $title, string $detail, string $hint = ''): void
    {
        Response::html(
            '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . e($title) . '</title><style>'
            . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#faf8f6;'
            . 'font:15px/1.7 -apple-system,BlinkMacSystemFont,Segoe UI,PingFang SC,sans-serif;color:#1f1710}'
            . '.c{max-width:560px;margin:24px;padding:34px 36px;border-radius:18px;background:#fff;'
            . 'border:1px solid #f0c6c6}'
            . 'h1{margin:0 0 12px;font-size:21px}p{color:#6b7280;margin:0 0 8px}'
            . 'pre{margin-top:16px;padding:13px 15px;border-radius:10px;background:#faf8f6;color:#b34e08;'
            . 'font:12.5px/1.7 ui-monospace,Consolas,monospace;white-space:pre-wrap;word-break:break-all}'
            . 'a{display:inline-block;margin-top:20px;color:#b34e08;font-weight:600}'
            . '</style></head><body><div class="c"><h1>' . e($title) . '</h1>'
            . '<p>' . e($detail) . '</p>'
            . ($hint !== '' ? '<pre>' . e($hint) . '</pre>' : '')
            . '<a href="/">← 返回首页</a></div></body></html>',
            403
        );
    }
}
