<?php
// ============================================================
// core/staff_scope.php
// One place for "which courses may this staff member see?".
//
// Only Dean is restricted (to the courses of their own college).
// Every other role is unrestricted here. A Dean with no department
// on file, or a department with no courses, sees NOTHING (1 = 0).
// ============================================================

final class StaffScope
{
    private static ?self $current = null;

    private function __construct(
        private bool   $restricted,
        private string $department,
        private array  $courses
    ) {}

    /** Scope of the logged-in user (built once per request). */
    public static function current(): self
    {
        if (self::$current === null) {
            $isDean = Auth::check() && Auth::role() === ROLE_DEAN;
            $dept   = $isDean ? (string) user_department((int) Auth::id()) : '';
            self::$current = new self(
                $isDean,
                $dept,
                $dept !== '' ? courses_in_department($dept) : []
            );
        }
        return self::$current;
    }

    /** True when the viewer is limited to their own college (Dean). */
    public function restricted(): bool { return $this->restricted; }

    /** Dean's department; '' when unrestricted or none on file. */
    public function department(): string { return $this->department; }

    /** Courses the viewer may see; [] when unrestricted OR when a Dean has none. */
    public function courses(): array { return $this->courses; }

    public function canAccessCourse(string $course): bool
    {
        return !$this->restricted || in_array($course, $this->courses, true);
    }

    /** Keep only the courses the viewer may see (list of course names). */
    public function filterCourses(array $courses): array
    {
        if (!$this->restricted) {
            return array_values($courses);
        }
        return array_values(array_intersect($courses, $this->courses));
    }

    /**
     * SQL fragment limiting $col to the viewer's courses.
     * Returns ['', []] when unrestricted, ['1 = 0', []] when a Dean has no courses.
     * With $namedPrefix (e.g. 'dc') placeholders are :dc0, :dc1, ...; otherwise '?'.
     *
     * @return array{0:string,1:array}
     */
    public function courseSql(string $col, string $namedPrefix = ''): array
    {
        if (!$this->restricted) {
            return ['', []];
        }
        if (!$this->courses) {
            return ['1 = 0', []];
        }
        if ($namedPrefix === '') {
            [$ph, $params] = sql_in($this->courses);
            return ["{$col} IN ({$ph})", $params];
        }
        $names  = [];
        $params = [];
        foreach ($this->courses as $i => $c) {
            $key          = ':' . $namedPrefix . $i;
            $names[]      = $key;
            $params[$key] = $c;
        }
        return ["{$col} IN (" . implode(',', $names) . ')', $params];
    }

    /** Append the course restriction to an existing $where / $params pair. */
    public function applyToWhere(array &$where, array &$params, string $col = 'a.course_applied', string $namedPrefix = ''): void
    {
        [$sql, $p] = $this->courseSql($col, $namedPrefix);
        if ($sql === '') {
            return;
        }
        $where[] = $sql;
        $params  = array_merge($params, $p);
    }
}
