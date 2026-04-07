<?php
// ============================================================
// core/Session.php
// Handles session start, inactivity timeout, flash messages
// ============================================================

class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(SESSION_NAME);

        session_set_cookie_params([
            'lifetime' => 0,             // browser session only
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        self::enforceTimeout();
        self::regenerateIfNeeded();
    }

    // -- Inactivity timeout ------------------------------------------
    private static function enforceTimeout(): void
    {
        if (isset($_SESSION['_last_activity'])) {
            if (time() - $_SESSION['_last_activity'] > SESSION_LIFETIME) {
                self::destroy();
                return;
            }
        }
        $_SESSION['_last_activity'] = time();
    }

    // -- Regenerate ID periodically to limit fixation attacks --------
    private static function regenerateIfNeeded(): void
    {
        if (!isset($_SESSION['_regen_at'])) {
            $_SESSION['_regen_at'] = time();
            return;
        }
        if (time() - $_SESSION['_regen_at'] > 300) {  // every 5 min
            session_regenerate_id(true);
            $_SESSION['_regen_at'] = time();
        }
    }

    // -- CRUD --------------------------------------------------------
    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    // -- Flash messages ----------------------------------------------
    // Set once, read once — disappear after display
    public static function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public static function getFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    public static function hasFlash(string $key): bool
    {
        return isset($_SESSION['_flash'][$key]);
    }
}
