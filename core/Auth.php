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

    public static function isStudent():   bool { return self::role() === ROLE_STUDENT;  }
    public static function isStaff():     bool { return self::role() === ROLE_STAFF;    }
    public static function isProfessor(): bool { return self::role() === ROLE_STAFF;    } // alias
    public static function isProctor():   bool { return self::role() === ROLE_PROCTOR;  }
    public static function isSSO():       bool { return self::role() === ROLE_SSO;      }
    public static function isDean():      bool { return self::role() === ROLE_DEAN;     }
    public static function isAdmin():     bool { return self::role() === ROLE_ADMIN;    }

    /**
     * Any non-student authenticated user (Professor / Proctor / SSO / Dean / Admin).
     * Use for pages that everyone with admin-side access can view (e.g.
     * shared dashboards, the interview queue, user-profile settings).
     */
    public static function isStaffPlus(): bool
    {
        return in_array(self::role(), [ROLE_STAFF, ROLE_PROCTOR, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN], true);
    }

    /**
     * SSO / Dean / Admin — the "oversight" tier.
     * Use for pages that read across applicants but where Professors
     * and Proctors have no business (results review, courses & strands).
     */
    public static function isOversight(): bool
    {
        return in_array(self::role(), [ROLE_SSO, ROLE_DEAN, ROLE_ADMIN], true);
    }

    /** Legacy helper kept for back-compat (Staff / Proctor OR Admin only). */
    public static function isStaffOrAdmin(): bool
    {
        return in_array(self::role(), [ROLE_STAFF, ROLE_PROCTOR, ROLE_ADMIN], true);
    }

    // -- Role labels --------------------------------------------
    // Map a DB enum value to its UI label. The DB enum keeps 'staff'
    // for back-compat, but everywhere the UI shows it we want
    // "Professor" — call this helper instead of `ucfirst($u['role'])`.
    public static function roleLabel(?string $role): string
    {
        return match ($role) {
            ROLE_STUDENT  => 'Student',
            ROLE_STAFF    => 'Professor',
            ROLE_PROCTOR  => 'Proctor',
            ROLE_SSO      => 'SSO',
            ROLE_DEAN     => 'Dean',
            ROLE_ADMIN    => 'Admin',
            default       => ucfirst((string)$role),
        };
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

    /** Convenience: any non-student authenticated user. */
    public static function requireStaffPlus(): void
    {
        self::requireRole(ROLE_STAFF, ROLE_PROCTOR, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);
    }

    /** Convenience: SSO / Dean / Admin (oversight tier). */
    public static function requireOversight(): void
    {
        self::requireRole(ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);
    }

    // -- Default redirect after login by role --------------------
    public static function homeUrl(): string
    {
        return match (self::role()) {
            ROLE_ADMIN   => url('/admin/dashboard'),
            ROLE_SSO     => url('/admin/dashboard'),
            ROLE_DEAN    => url('/admin/dashboard'),
            ROLE_STAFF   => url('/staff/interviews/queue'),
            // Proctors land on the staff dashboard (their sidebar links to
            // it). The interview queue is excluded for proctors by guard.
            ROLE_PROCTOR => url('/staff/dashboard'),
            default      => url('/student/documents'),
        };
    }
}
