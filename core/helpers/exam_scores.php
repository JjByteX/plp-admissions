<?php
// ============================================================
// core/helpers/exam_scores.php
// Exam score to rank, tier and pass-mark helpers.
// Loaded by core/helpers.php; plain functions, no side effects
// beyond defining them.
// ============================================================

// ================================================================
// EXAM SCORE TIER SYSTEM
// ================================================================
// Raw score → 1-10 rank (percentage-based)
// Rank tiers per client interview:
//   High    7–10  → Passed
//   Average 4–6   → Passed
//   Low     1–3   → Rejected
// ================================================================

/**
 * Convert raw score to a 1–10 ranking using percentage.
 * e.g. 70% → rank 7, 40% → rank 4, 25% → rank 3
 */
function score_to_rank(int $score, int $total): int
{
    if ($total <= 0) {
        return 1;
    }
    $pct = ($score / $total) * 100;         // 0–100
    $rank = (int) ceil($pct / 10);          // 0–10
    return max(1, min(10, $rank ?: 1));     // clamp 1–10
}

/**
 * Return display info for a 1–10 rank.
 * Returns: ['tier' => 'high'|'average'|'low', 'label' => ..., 'color' => ..., 'verdict' => 'passed'|'rejected']
 */
function rank_tier_info(int $rank): array
{
    if ($rank >= 7) {
        return [
            'tier'    => 'high',
            'label'   => 'High',
            'color'   => '#22c55e',
            'bg'      => '#dcfce7',
            'verdict' => 'passed',
        ];
    }
    if ($rank >= 4) {
        return [
            'tier'    => 'average',
            'label'   => 'Average',
            'color'   => '#f59e0b',
            'bg'      => '#fef3c7',
            'verdict' => 'passed',
        ];
    }
    return [
        'tier'    => 'low',
        'label'   => 'Low',
        'color'   => '#ef4444',
        'bg'      => '#fee2e2',
        'verdict' => 'rejected',
    ];
}

/**
 * Get the minimum rank required to pass for a given course.
 * Checks course_passing_scores DB table first; falls back to config.
 */
function get_pass_threshold(string $course): int
{
    static $cache = [];
    if (isset($cache[$course])) {
        return $cache[$course];
    }
    try {
        $stmt = db()->prepare('SELECT pass_from FROM course_passing_scores WHERE course_name=? LIMIT 1');
        $stmt->execute([$course]);
        $row = $stmt->fetch();
        if ($row) {
            return $cache[$course] = (int) $row['pass_from'];
        }
    } catch (\Throwable $e) {}
    $config = COURSE_PASSING_SCORES[$course] ?? null;
    return $cache[$course] = $config ? (int)$config['pass_from'] : 4;
}

/**
 * Determine if a score passes for a course.
 */
function exam_passed(int $score, int $total, string $course): bool
{
    return score_to_rank($score, $total) >= get_pass_threshold($course);
}

/**
 * Return list of available courses the applicant's score qualifies for,
 * excluding their applied course and any that are full.
 *
 * @return array<string>  list of course names
 */
function suggest_alt_courses(int $score, int $total, string $appliedCourse): array
{
    if ($total <= 0) {
        return [];
    }
    $rank = score_to_rank($score, $total);

    // Get all per-course thresholds from DB
    $thresholds = [];
    try {
        $rows = db()->query('SELECT course_name, pass_from FROM course_passing_scores')->fetchAll();
        foreach ($rows as $r) {
            $thresholds[$r['course_name']] = (int)$r['pass_from'];
        }
    } catch (\Throwable $e) {}
    // Fall back to config for any missing
    foreach (COURSE_PASSING_SCORES as $cn => $cfg) {
        if (!isset($thresholds[$cn])) {
            $thresholds[$cn] = (int)$cfg['pass_from'];
        }
    }

    // Find full courses
    $fullCourses = [];
    try {
        $sy = school_setting('current_school_year', date('Y').'-'.(date('Y')+1));
        $stmt = db()->prepare(
            'SELECT cc.course_name
             FROM course_caps cc
             LEFT JOIN applicants a      ON a.course_applied = cc.course_name AND a.school_year = cc.school_year
             LEFT JOIN admission_results r ON r.applicant_id = a.id AND r.result = \'accepted\'
             WHERE cc.school_year = ? AND cc.max_slots IS NOT NULL
             GROUP BY cc.course_name, cc.max_slots
             HAVING COUNT(r.id) >= cc.max_slots'
        );
        $stmt->execute([$sy]);
        $fullCourses = $stmt->fetchAll(\PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {}

    $suggestions = [];
    foreach (PLP_COURSES as $course) {
        if ($course === $appliedCourse) {
            continue;
        }
        if (in_array($course, $fullCourses, true)) {
            continue;
        }
        $pf = $thresholds[$course] ?? 4;
        if ($rank >= $pf) {
            $suggestions[] = $course;
        }
    }
    return $suggestions;
}

/**
 * Seats still open for a course this school year.
 * Returns null when the course has no cap (nothing to enforce), otherwise
 * max_slots minus applicants already released as 'accepted' (never below 0).
 * Used by the Results release handlers to refuse accepting past the cap.
 */
function course_slots_remaining(PDO $db, string $course, string $schoolYear): ?int
{
    $stmt = $db->prepare(
        "SELECT cc.max_slots,
                (SELECT COUNT(*)
                   FROM admission_results r
                   JOIN applicants a ON a.id = r.applicant_id
                  WHERE a.course_applied = cc.course_name
                    AND a.school_year    = cc.school_year
                    AND r.result = 'accepted') AS accepted
           FROM course_caps cc
          WHERE cc.course_name = ? AND cc.school_year = ?
            AND cc.max_slots IS NOT NULL AND cc.max_slots > 0"
    );
    $stmt->execute([$course, $schoolYear]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return max(0, (int)$row['max_slots'] - (int)$row['accepted']);
}
