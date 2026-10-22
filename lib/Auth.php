<?php
declare(strict_types=1);

/**
 * Session-based admin auth + CSRF for the description-review admin panel.
 *
 * Single-user — credentials live in config.php under the 'admin' key:
 *   'admin' => [
 *       'username'      => '<username>',
 *       'password_hash' => '<bcrypt hash>',
 *   ]
 * Generate the hash once via:
 *   php -r "echo password_hash('your-password', PASSWORD_BCRYPT, ['cost' => 12]);"
 */
final class Auth
{
    private const SESSION_KEY  = 'codex_admin_user';
    private const CSRF_KEY     = 'codex_csrf';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('codex_admin');
        session_start();
    }

    public static function isLoggedIn(): bool
    {
        self::start();
        return !empty($_SESSION[self::SESSION_KEY]);
    }

    public static function user(): ?string
    {
        self::start();
        return $_SESSION[self::SESSION_KEY] ?? null;
    }

    /**
     * Verify credentials and start an authenticated session. nginx allows
     * 5 login attempts a minute per visitor (limit_req).
     */
    public static function attemptLogin(string $username, string $password): bool
    {
        $config = require __DIR__ . '/../config.php';
        $admin = $config['admin'] ?? null;
        if (!is_array($admin)
            || empty($admin['username'])
            || empty($admin['password_hash'])
            || $admin['username'] === 'CHANGE_ME'
        ) {
            return false;
        }

        if (!hash_equals((string) $admin['username'], $username)) {
            // Run password_verify on a dummy hash to keep timing similar.
            @password_verify($password, '$2y$12$' . str_repeat('a', 53));
            Logger::warn('admin.login.bad_user', ['user' => $username]);
            return false;
        }

        if (!password_verify($password, (string) $admin['password_hash'])) {
            Logger::warn('admin.login.bad_password', ['user' => $username]);
            return false;
        }

        self::start();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $username;
        $_SESSION[self::CSRF_KEY]    = bin2hex(random_bytes(16));
        Logger::info('admin.login.ok', ['user' => $username]);
        return true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie((string) session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function csrfToken(): string
    {
        self::start();
        if (empty($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(16));
        }
        return $_SESSION[self::CSRF_KEY];
    }

    public static function checkCsrf(?string $token): bool
    {
        self::start();
        $expected = $_SESSION[self::CSRF_KEY] ?? '';
        return $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    /**
     * Redirect to login if not authenticated. Stores intended destination
     * so we can return after successful login.
     */
    public static function requireLogin(string $loginUrl, string $intendedPath = ''): void
    {
        if (self::isLoggedIn()) return;
        self::start();
        if ($intendedPath !== '') $_SESSION['codex_admin_after_login'] = $intendedPath;
        header('Location: ' . $loginUrl);
        exit;
    }

    public static function takeIntendedPath(): ?string
    {
        self::start();
        $p = $_SESSION['codex_admin_after_login'] ?? null;
        unset($_SESSION['codex_admin_after_login']);
        return $p;
    }
}
