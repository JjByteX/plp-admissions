<?php
// ============================================================
// core/Auth.php
// Login state, role resolution, route guards
// ============================================================

class Auth
{
    // -- Login / logout ------------------------------------------
    public static function login(array $user): void
    {
        session_regenerate_id(true);
        Session::set('user_id',   (int)  $user['id']);
        Session::set('user_name',        $user['name']);
        Session::set('user_email',       $user['email']);
        Session::set('user_role',        $user['role']);
    }

    public static function logout(): void
    {
        Session::destroy();
        header('Location: ' . url('/login'));
        exit;
    }

    // -- State checks --------------------------------------------
    public static function check(): bool
    {
        return Session::has('user_id');
    }

    public static function id(): ?int
    {
        return Session::get('user_id');
    }

    public static function role(): ?string
    {
        return Session::get('user_role');
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        return [
            'id'    => Session::get('user_id'),
            'name'  => Session::get('user_name'),
            'email' => Session::get('user_email'),
            'role'  => Session::get('user_role'),
        ];
    }

    public static function isStudent(): bool { return self::role() === ROLE_STUDENT; }
    public static function isStaff():   bool { return self::role() === ROLE_STAFF;   }
    public static function isAdmin():   bool { return self::role() === ROLE_ADMIN;   }
    public static function isStaffOrAdmin(): bool
    {
        return in_array(self::role(), [ROLE_STAFF, ROLE_ADMIN], true);
    }

    // -- Guards --------------------------------------------------
    // Call at the top of any page that requires authentication
    public static function requireLogin(): void
    {
        if (!self::check()) {
            Session::flash('error', 'Please log in to continue.');
            header('Location: ' . url('/login'));
            exit;
        }
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        if (!in_array(self::role(), $roles, true)) {
            http_response_code(403);
            include VIEWS_PATH . '/403.php';
            exit;
        }
    }

    // -- Default redirect after login by role --------------------
    public static function homeUrl(): string
    {
        return match (self::role()) {
            ROLE_ADMIN => url("/admin/dashboard"),
            ROLE_STAFF => url("/staff/dashboard"),
            default    => url('/student/documents'),
        };
    }
}
