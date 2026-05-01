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

        // DB-backed sessions are required on Vercel (serverless containers don't
        // share /tmp between requests).  On localhost XAMPP the native file
        // handler works perfectly and avoids the sessions table dependency.
        if (APP_ENV === 'production') {
            self::registerDbHandler();
        }
        session_start();
        self::enforceTimeout();
        self::regenerateIfNeeded();
    }

    // -- DB-backed session handler (required for Vercel serverless) -
    // Vercel containers do not share /tmp, so file-based sessions are
    // lost between requests. Storing sessions in MySQL fixes this.
    private static function registerDbHandler(): void
    {
        session_set_save_handler(
            // open
            fn($path, $name) => true,
            // close
            fn() => true,
            // read
            function (string $id): string {
                try {
                    $stmt = db()->prepare('SELECT payload FROM sessions WHERE id = ?');
                    $stmt->execute([$id]);
                    return (string)($stmt->fetchColumn() ?: '');
                } catch (\Throwable) {
                    return '';
                }
            },
            // write
            function (string $id, string $data): bool {
                try {
                    db()->prepare('
                        INSERT INTO sessions (id, payload, last_activity)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            payload       = VALUES(payload),
                            last_activity = VALUES(last_activity)
                    ')->execute([$id, $data, time()]);
                    return true;
                } catch (\Throwable) {
                    return false;
                }
            },
            // destroy
            function (string $id): bool {
                try {
                    db()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
                    return true;
                } catch (\Throwable) {
                    return false;
                }
            },
            // gc
            function (int $maxLifetime): int|false {
                try {
                    $stmt = db()->prepare('DELETE FROM sessions WHERE last_activity < ?');
                    $stmt->execute([time() - $maxLifetime]);
                    return $stmt->rowCount();
                } catch (\Throwable) {
                    return false;
                }
            }
        );

        ini_set('session.gc_maxlifetime', (string) max(SESSION_LIFETIME_STAFF, SESSION_LIFETIME_STUDENT));
    }

    // -- Inactivity timeout — role-aware ----------------------------
    private static function enforceTimeout(): void
    {
        if (isset($_SESSION['_last_activity'])) {
            $role    = $_SESSION['user_role'] ?? 'student';
            $limit   = in_array($role, ['staff', 'admin'], true)
                ? SESSION_LIFETIME_STAFF
                : SESSION_LIFETIME_STUDENT;
            $elapsed = time() - $_SESSION['_last_activity'];
            if ($elapsed > $limit) {
                self::flash('timeout', '1');
                self::destroy();
                return;
            }
        }
        $_SESSION['_last_activity'] = time();
    }

    // -- Remaining session seconds (for JS warning timer) -----------
    public static function secondsRemaining(): int
    {
        if (!isset($_SESSION['_last_activity'])) return 0;
        $role  = $_SESSION['user_role'] ?? 'student';
        $limit = in_array($role, ['staff', 'admin'], true)
            ? SESSION_LIFETIME_STAFF
            : SESSION_LIFETIME_STUDENT;
        return max(0, $limit - (time() - $_SESSION['_last_activity']));
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