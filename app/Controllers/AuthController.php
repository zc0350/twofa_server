<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Cache;
use App\Core\Database;
use App\Core\Request;
use App\Core\Tokens;
use App\Services\Mailer;

/**
 * 注册（邮箱验证两步）与登录（仅身份认证）。
 *
 * 注册流程（未验证邮箱前不入库，仅写 Cache）：
 *  1. register  {email, password} → 发验证码，待注册数据入 Cache（10 分钟有效）
 *  2. verify    {email, code}     → 验证通过写入 users 表并签发令牌
 *
 * 安全模型：登录密码 bcrypt 存储，仅用于签发令牌；
 * 数据加密使用独立「同步密码」，永不上传，服务器零知识。
 */
final class AuthController extends Controller
{
private const LOGIN_FAIL_LIMIT = 5;
    private const LOGIN_LOCK_SECONDS = 900;
    private const REGISTER_DAILY_LIMIT = 20;   // 单 IP 每日发码上限
    private const EMAIL_DAILY_LIMIT = 10;      // 单邮箱每日发码上限
    private const SEND_COOLDOWN = 60;          // 同邮箱发码冷却
    private const PENDING_TTL = 600;           // 待验证注册有效期
    private const VERIFY_MAX_ATTEMPTS = 5;
    private const PWD_DAILY_LIMIT = 10;        // 单邮箱每日密码验证码上限
    private const PWD_IP_DAILY_LIMIT = 20;     // 单 IP 每日密码验证码上限
    private const PWD_TTL = 600;               // 密码验证码有效期
    private const PWD_MAX_ATTEMPTS = 5;

    public function index()
    {
        header('Content-Type: text/html; charset=utf-8');
        require $_SERVER['DOCUMENT_ROOT']."/h5/index.html";
        return;
    }

    /** 注册第一步：发验证码（账号暂存 Cache，未入库） */
    public function register(Request $request): array
    {
        $ipKey = 'reg_' . md5($request->ip());
        if (Cache::increment($ipKey, 86400) > self::REGISTER_DAILY_LIMIT) {
            return $this->fail(429, '注册操作过于频繁，请明日再试');
        }

        $email    = mb_strtolower(trim((string) $request->param('email', '')));
        $password = (string) $request->param('password', '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            return $this->fail(422, '邮箱格式不正确');
        }
        // SMTP 头注入防护：邮箱不得包含换行/回车
        if (preg_match('/[\r\n]/', $email)) {
            return $this->fail(422, '邮箱格式不正确');
        }
        if (strlen($password) < 8 || strlen($password) > 128) {
            return $this->fail(422, '密码长度须为 8 ~ 128 位');
        }
        if (Database::fetchOne('SELECT id FROM users WHERE email = ?', [$email]) !== null) {
            return $this->fail(409, '该邮箱已注册');
        }

        // 同邮箱发码冷却
        $coolKey = 'reg_cool_' . md5($email);
        if (Cache::get($coolKey) !== null) {
            return $this->fail(429, '验证码已发送，请 ' . self::SEND_COOLDOWN . ' 秒后再试');
        }
        // 单邮箱每日上限
        $dailyKey = 'reg_daily_' . md5($email);
        if ((int) Cache::get($dailyKey, 0) >= self::EMAIL_DAILY_LIMIT) {
            return $this->fail(429, '该邮箱今日验证码发送次数过多，请明日再试');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::set('reg_pending_' . md5($email), [
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'code_hash'     => hash('sha256', $code . '|' . $email),
            'attempts'      => 0,
            'expires_at'    => time() + self::PENDING_TTL,
        ], self::PENDING_TTL);

        try {
            Mailer::sendVerificationCode($email, $code);
        } catch (\Throwable $e) {
            Cache::delete('reg_pending_' . md5($email));
            // 开发调试：APP_DEBUG=true 时向客户端返回真实失败原因（生产应保持关闭）
            $message = '验证码邮件发送失败，请稍后重试或联系管理员';
            if (\App\Core\Env::get('APP_DEBUG') === 'true') {
                $message .= '（' . $e->getMessage() . '）';
            }
            return $this->fail(500, $message);
        }

        Cache::set($coolKey, 1, self::SEND_COOLDOWN);
        Cache::increment($dailyKey, 86400);

        return ['pending' => true, 'expiresIn' => self::PENDING_TTL];
    }

    /** 注册第二步：邮箱验证码校验通过后账号写入数据库并签发令牌 */
    public function verify(Request $request): array
    {
        $email = mb_strtolower(trim((string) $request->param('email', '')));
        $code  = trim((string) $request->param('code', ''));

        if ($email === '' || !preg_match('/^\d{6}$/', $code)) {
            return $this->fail(422, '请输入邮箱中的 6 位验证码');
        }

        $key = 'reg_pending_' . md5($email);
        $pending = Cache::get($key);
        if (!is_array($pending)) {
            return $this->fail(410, '验证码已过期或不存在，请重新注册');
        }
        if (time() > (int) $pending['expires_at']) {
            Cache::delete($key);
            return $this->fail(410, '验证码已过期，请重新注册');
        }
        if ((int) $pending['attempts'] >= self::VERIFY_MAX_ATTEMPTS) {
            Cache::delete($key);
            return $this->fail(429, '验证尝试次数过多，请重新注册');
        }

        if (!hash_equals((string) $pending['code_hash'], hash('sha256', $code . '|' . $email))) {
            $pending['attempts'] = (int) $pending['attempts'] + 1;
            Cache::set($key, $pending, max(1, (int) $pending['expires_at'] - time()));
            $left = self::VERIFY_MAX_ATTEMPTS - (int) $pending['attempts'];
            return $this->fail(401, '验证码不正确' . ($left > 0 ? '，还可尝试 ' . $left . ' 次' : '，请重新注册'));
        }

        // 并发兜底：捕获 UNIQUE 约束冲突（两个请求同时通过上方检查时，第二个到此失败）
        try {
            Database::exec(
                'INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, ?)',
                [$email, (string) $pending['password_hash'], date('Y-m-d H:i:s')]
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000' || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                Cache::delete($key);
                return $this->fail(409, '该邮箱已注册');
            }
            throw $e;
        }
        $userId = (int) Database::pdo()->lastInsertId();
        Cache::delete($key);

        return ['userId' => $userId, 'token' => $this->issueToken($userId), 'version' => 0];
    }

    public function login(Request $request): array
    {
        $email    = mb_strtolower(trim((string) $request->param('email', '')));
        $password = (string) $request->param('password', '');

        // 登录限速：邮箱+IP 维度，连续 5 次失败锁定 15 分钟
        $failKey = 'login_fail_' . md5($email . '|' . $request->ip());
        $failData = Cache::get($failKey);
        $failData = is_array($failData) ? $failData : ['count' => 0, 'until' => 0];
        if ($failData['count'] >= self::LOGIN_FAIL_LIMIT && $failData['until'] > time()) {
            $minutes = (int) ceil(($failData['until'] - time()) / 60);
            return $this->fail(429, '尝试次数过多，请 ' . max($minutes, 1) . ' 分钟后再试');
        }

        $user = Database::fetchOne('SELECT * FROM users WHERE email = ?', [$email]);
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            $failData['count'] += 1;
            $failData['until'] = time() + self::LOGIN_LOCK_SECONDS;
            Cache::set($failKey, $failData, self::LOGIN_LOCK_SECONDS);
            $left = self::LOGIN_FAIL_LIMIT - $failData['count'];
            return $this->fail(401, '邮箱或密码不正确' . ($left > 0 ? '，还可尝试 ' . $left . ' 次' : ''));
        }

        Cache::delete($failKey);

        // 登录时顺带清理该用户的过期 token
        Tokens::deleteExpired((int) $user['id']);

        $vault = Database::fetchOne('SELECT version FROM vaults WHERE user_id = ?', [(int) $user['id']]);

        return [
            'userId'  => (int) $user['id'],
            'token'   => $this->issueToken((int) $user['id']),
            'version' => $vault ? (int) $vault['version'] : 0,
        ];
    }

    /* ============================================================
     * 登录密码找回 / 修改
     * ------------------------------------------------------------
     * 统一密码验证码缓存键：pwd_reset_<md5(email)>，10 分钟有效，
     * 验证码 SHA-256 加盐存储、最多尝试 5 次（与注册验证码同策略）。
     * 修改密码后撤销该用户全部令牌（revokeAll），各端强制重新登录。
     * ============================================================ */

    /** 发送密码验证码：已登录取当前用户邮箱；未登录需显式传 email（须为已注册邮箱） */
    public function passwordCode(Request $request): array
    {
        $email = mb_strtolower(trim((string) $request->param('email', '')));

        // 已登录状态：以当前账号邮箱为准，忽略传入 email（防止改别人账号）
        $userId = Tokens::authenticate($request);
        if ($userId !== null) {
            $row = Database::fetchOne('SELECT email FROM users WHERE id = ?', [$userId]);
            if ($row === null) {
                return $this->fail(404, '账号不存在');
            }
            $email = mb_strtolower(trim((string) $row['email']));
        } else {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                return $this->fail(422, '邮箱格式不正确');
            }
            if (Database::fetchOne('SELECT id FROM users WHERE email = ?', [$email]) === null) {
                return $this->fail(404, '该邮箱未注册，请先注册账号');
            }
        }

        // 单 IP 每日上限
        $ipKey = 'pwd_ip_' . md5($request->ip());
        if (Cache::increment($ipKey, 86400) > self::PWD_IP_DAILY_LIMIT) {
            return $this->fail(429, '操作过于频繁，请明日再试');
        }
        // 同邮箱冷却 + 每日上限
        $coolKey = 'pwd_cool_' . md5($email);
        if (Cache::get($coolKey) !== null) {
            return $this->fail(429, '验证码已发送，请 ' . self::SEND_COOLDOWN . ' 秒后再试');
        }
        $dailyKey = 'pwd_daily_' . md5($email);
        if ((int) Cache::get($dailyKey, 0) >= self::PWD_DAILY_LIMIT) {
            return $this->fail(429, '该邮箱今日验证码发送次数过多，请明日再试');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::set('pwd_reset_' . md5($email), [
            'email'      => $email,
            'code_hash'  => hash('sha256', $code . '|' . $email),
            'attempts'   => 0,
            'expires_at' => time() + self::PWD_TTL,
        ], self::PWD_TTL);

        try {
            Mailer::sendVerificationCode($email, $code, '找回登录密码 / 安全验证，请勿将验证码告知他人', '【风信密码器】密码验证码');
        } catch (\Throwable $e) {
            Cache::delete('pwd_reset_' . md5($email));
            $message = '验证码邮件发送失败，请稍后重试或联系管理员';
            if (\App\Core\Env::get('APP_DEBUG') === 'true') {
                $message .= '（' . $e->getMessage() . '）';
            }
            return $this->fail(500, $message);
        }

        Cache::set($coolKey, 1, self::SEND_COOLDOWN);
        Cache::increment($dailyKey, 86400);

        return ['pending' => true, 'expiresIn' => self::PWD_TTL];
    }

    /** 密码验证码校验（共用：忘记密码重置 / 已登录改密）, 失败返回错误提示字符串，成功返回 null */
    private function verifyPasswordCode(string $email, string $code): ?array
    {
        $key = 'pwd_reset_' . md5($email);
        $pending = Cache::get($key);
        if (!is_array($pending)) {
            return [$this->fail(410, '验证码已过期或不存在，请重新获取')];
        }
        if (time() > (int) $pending['expires_at']) {
            Cache::delete($key);
            return [$this->fail(410, '验证码已过期，请重新获取')];
        }
        if ((int) $pending['attempts'] >= self::PWD_MAX_ATTEMPTS) {
            Cache::delete($key);
            return [$this->fail(429, '验证尝试次数过多，请重新获取')];
        }
        if (!hash_equals((string) $pending['code_hash'], hash('sha256', $code . '|' . $email))) {
            $pending['attempts'] = (int) $pending['attempts'] + 1;
            Cache::set($key, $pending, max(1, (int) $pending['expires_at'] - time()));
            $left = self::PWD_MAX_ATTEMPTS - (int) $pending['attempts'];
            return [$this->fail(401, '验证码不正确' . ($left > 0 ? '，还可尝试 ' . $left . ' 次' : '，请重新获取'))];
        }
        Cache::delete($key);
        return null;
    }

    /** 修改登录密码（登录态）：旧密码 或 邮箱验证码 二选一 → 校验新密码 → 撤旧令牌发新令牌 */
    public function changePassword(Request $request): array
    {
        $userId = $this->authUserId($request);
        if ($userId === null) {
            return $this->fail(401, '未登录或登录已过期');
        }
        $user = Database::fetchOne('SELECT id, email, password_hash FROM users WHERE id = ?', [$userId]);
        if ($user === null) {
            return $this->fail(401, '账号不存在');
        }

        $newPassword = (string) $request->param('newPassword', '');
        if (strlen($newPassword) < 8 || strlen($newPassword) > 128) {
            return $this->fail(422, '新密码长度须为 8 ~ 128 位');
        }

        // 身份复核：旧密码 或 邮箱验证码（与旧密码均正确时不重复计错）
        $oldPassword = (string) $request->param('oldPassword', '');
        $code        = trim((string) $request->param('code', ''));
        $verified = false;
        if ($oldPassword !== '' || $code !== '') {
            if ($oldPassword !== '' && password_verify($oldPassword, (string) $user['password_hash'])) {
                $verified = true;
            } elseif ($code !== '' && preg_match('/^\d{6}$/', $code)) {
                $check = $this->verifyPasswordCode((string) $user['email'], $code);
                if ($check === null) {
                    $verified = true;
                } else {
                    return $check[0];
                }
            }
        }
        if (!$verified) {
            // 旧密码错误：限速（与登录同策略，避免爆破）
            $failKey = 'pwd_fail_' . $userId . '_' . md5($request->ip());
            $failData = Cache::get($failKey);
            $failData = is_array($failData) ? $failData : ['count' => 0, 'until' => 0];
            if ($failData['count'] >= self::LOGIN_FAIL_LIMIT && $failData['until'] > time()) {
                $minutes = (int) ceil(($failData['until'] - time()) / 60);
                return $this->fail(429, '尝试次数过多，请 ' . max($minutes, 1) . ' 分钟后再试');
            }
            $failData['count'] += 1;
            $failData['until'] = time() + self::LOGIN_LOCK_SECONDS;
            Cache::set($failKey, $failData, self::LOGIN_LOCK_SECONDS);
            $left = self::LOGIN_FAIL_LIMIT - $failData['count'];
            return $this->fail(401, '旧密码或验证码不正确' . ($left > 0 ? '，还可尝试 ' . $left . ' 次' : ''));
        }

        Database::exec(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($newPassword, PASSWORD_BCRYPT), $userId]
        );

        // 安全：改密后让其他已登录端下线（本端重签新令牌维持会话）
        Tokens::revokeAll($userId);
        return [
            'userId' => $userId,
            'token'  => $this->issueToken($userId),
        ];
    }

    /** 忘记密码（登录前）：邮箱验证码 → 直接重置登录密码并撤销全部旧令牌 */
    public function resetPassword(Request $request): array
    {
        $email       = mb_strtolower(trim((string) $request->param('email', '')));
        $code        = trim((string) $request->param('code', ''));
        $newPassword = (string) $request->param('newPassword', '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            return $this->fail(422, '邮箱格式不正确');
        }
        if (!preg_match('/^\d{6}$/', $code)) {
            return $this->fail(422, '请输入邮箱中的 6 位验证码');
        }
        if (strlen($newPassword) < 8 || strlen($newPassword) > 128) {
            return $this->fail(422, '新密码长度须为 8 ~ 128 位');
        }

        $check = $this->verifyPasswordCode($email, $code);
        if ($check !== null) {
            return $check[0];
        }
        $user = Database::fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
        if ($user === null) {
            return $this->fail(404, '该邮箱未注册');
        }

        Database::exec(
            'UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash($newPassword, PASSWORD_BCRYPT), (int) $user['id']]
        );
        Tokens::revokeAll((int) $user['id']);

        return ['reset' => true];
    }
}
