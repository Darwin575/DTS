<?php

declare(strict_types=0);
// 2. Opposite of ini_set('display_errors', 1);
ini_set('display_errors', 0);

// 3. Opposite of ini_set('display_startup_errors', 1);
ini_set('display_startup_errors', 0);

// 4. Opposite of error_reporting(E_ALL);
//    This means report NO errors, warnings, notices, etc.
error_reporting(0);

// 5. CRUCIAL for production (logs errors instead of displaying)
ini_set('log_errors', 0);
class SessionManager
{
    private static $instance = null;
    private static $isProd;

    private function __construct()
    {
        $this->configureSession();
        $this->startSession();
    }

    public static function unset($key)
    {
        unset($_SESSION[$key]);
    }

    public static function init(): void
    {
        if (self::$instance === null) {
            self::$isProd = self::detectProduction();
            self::$instance = new self();
        }
    }

    private static function detectProduction(): bool
    {
        $host = $_SERVER['SERVER_NAME'] ?? '';
        // Treat LAN IPs as development
        if (preg_match('/^192\.168\.\d+\.\d+$/', $host)) {
            return false;
        }
        return !in_array($host, ['localhost', '127.0.0.1', '::1']) &&
            !str_ends_with($host, '.test') &&
            !str_ends_with($host, '.local');
    }

    private function configureSession(): void
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $useHostCookie = $isHttps && self::$isProd;

        $settings = [
            'name' => $useHostCookie ? '__Host-DTS_SESSION' : 'DTS_SESSION',
            'cookie_lifetime' => 86400,
            'cookie_secure' => $useHostCookie, // true only for HTTPS production
            'cookie_domain' => '',
            'cookie_httponly' => true,
            'cookie_samesite' => self::$isProd ? 'Strict' : 'Lax',
            'use_strict_mode' => true,
            'use_only_cookies' => true,
            'gc_maxlifetime' => 86400,
            'sid_length' => 128,
            'sid_bits_per_character' => 6,
            'hash_function' => 'sha256',
            'cookie_path' => '/',
        ];

        foreach ($settings as $key => $value) {
            ini_set('session.' . $key, $value);
        }
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (headers_sent()) {
                throw new RuntimeException('Headers already sent');
            }

            session_start();

            if (empty($_SESSION['_initialized'])) {
                session_regenerate_id(true);
                $_SESSION['_initialized'] = true;
                $_SESSION['_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
                $_SESSION['_ua'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $_SESSION['_created'] = time();
            }

            $this->validateSession();
        }
    }

    private function validateSession(): void
    {
        $currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $currentUa = $_SERVER['HTTP_USER_AGENT'] ?? '';
        // error_log('Validating session...');
        // error_log('Current IP: ' . $currentIp);
        // error_log('Session IP: ' . ($_SESSION['_ip'] ?? ''));
        // error_log('Current UA: ' . $currentUa);
        // error_log('Session UA: ' . ($_SESSION['_ua'] ?? ''));
        if (
            $_SESSION['_ip'] !== $currentIp ||
            $_SESSION['_ua'] !== $currentUa ||
            (self::$isProd && time() - $_SESSION['_created'] > 300)
        ) {
            $this->destroySession();
        }
    }

    private function destroySession(): void
    {
        // Unset all session variables
        $_SESSION = [];

        // Delete session cookie (important!)
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        // Destroy session on the server
        session_destroy();

        // Start a new session
        session_start();
        session_regenerate_id(true);

        // Initialize fresh session metadata
        $_SESSION = [
            '_initialized' => true,
            '_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            '_ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            '_created' => time()
        ];
    }


    public static function isProduction(): bool
    {
        return self::$isProd;
    }

    public static function get($key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set($key, $value): void
    {
        $_SESSION[$key] = $value;
    }
    public static function logout(): void
    {
        if (self::$instance !== null) {
            self::$instance->destroySession();
        }
    }
}

// Initialize session
try {
    SessionManager::init();

    if (!SessionManager::isProduction()) {
        // error_log('Session initialized: ' . print_r([
        //     'id' => session_id(),
        //     'data' => $_SESSION,
        //     'cookie' => session_get_cookie_params()
        // ], true));
    }
} catch (Throwable $e) {
    error_log('Session init failed: ' . $e->getMessage());
    if (!SessionManager::isProduction()) {
        throw $e;
    }
}
