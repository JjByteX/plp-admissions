<?php
// ============================================================
// core/helpers/exam_access.php
// Exam access helpers: exam password expiry, slot window, late cutoff.
// Loaded by core/helpers.php; plain functions, no side effects
// beyond defining them.
// ============================================================

// ================================================================
// FEATURE: Exam Password Expiry helpers
// ================================================================

define('EXAM_PASSWORD_EXPIRY_SECONDS', 300); // 5 minutes

/**
 * Generate a random, readable 6-character exam access code.
 * Uses uppercase letters + digits, avoiding ambiguous characters (0/O, 1/I/L).
 */
function generate_exam_password(): string
{
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $pwd   = '';
    for ($i = 0; $i < 6; $i++) {
        $pwd .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pwd;
}

/**
 * Check whether the access password on the given row is currently valid
 * (i.e. was issued within the last EXAM_PASSWORD_EXPIRY_SECONDS).
 *
 * As of Chunk 7 the password lives on `exam_slot_schedule` (per room),
 * not on `exams`. This helper accepts any row that has both
 * `access_password` and `password_issued_at` columns — historically
 * called with the exam row, now called with the slot row. Function name
 * kept as `exam_password_*` to avoid churn in call sites that only need
 * the value semantics.
 *
 * @param  array  $row  Row containing `access_password` + `password_issued_at`
 * @return bool
 */
function exam_password_is_valid(array $row): bool
{
    if (empty($row['access_password'])) {
        return false;
    }
    if (empty($row['password_issued_at'])) {
        return false;
    }
    // Compute age in the database so PHP/DB timezone differences don't skew the result.
    $stmt = db()->prepare(
        'SELECT TRUNC(EXTRACT(EPOCH FROM (NOW() - CAST(? AS timestamp))))'
    );
    $stmt->execute([$row['password_issued_at']]);
    $age = (int) $stmt->fetchColumn();
    return $age >= 0 && $age <= EXAM_PASSWORD_EXPIRY_SECONDS;
}

/**
 * Return the number of seconds remaining before the access password expires.
 * Returns 0 if already expired or never issued.
 *
 * @param  array  $row  Row containing `access_password` + `password_issued_at`
 */
function exam_password_seconds_remaining(array $row): int
{
    if (empty($row['password_issued_at'])) {
        return 0;
    }
    // Compute in the database so PHP/DB timezone differences don't skew the result.
    $stmt = db()->prepare(
        'SELECT GREATEST(0, ' . (int) EXAM_PASSWORD_EXPIRY_SECONDS
        . ' - TRUNC(EXTRACT(EPOCH FROM (NOW() - CAST(? AS timestamp)))))'
    );
    $stmt->execute([$row['password_issued_at']]);
    return (int) $stmt->fetchColumn();
}

/**
 * Return the wall-clock open / close DateTimes for a slot.
 *
 *   opens  = exam_date + slot_time
 *   closes = exam_date + end_time   (per Chunk 7.1 — each slot owns its window)
 *
 * If the slot has no `end_time` (legacy data), falls back to a 90-minute
 * window so the timer never returns negative numbers.
 *
 * Returns ['opens' => DateTime, 'closes' => DateTime].
 *
 * @param  array  $slot  Row from `exam_slot_schedule` (slot_time + end_time)
 */
function slot_window(array $slot): array
{
    $opens = new DateTime($slot['exam_date'] . ' ' . $slot['slot_time']);
    if (!empty($slot['end_time'])) {
        $closes = new DateTime($slot['exam_date'] . ' ' . $slot['end_time']);
        // If end_time wraps past midnight (e.g. 11pm → 1am), bump a day.
        if ($closes <= $opens) {
            $closes->modify('+1 day');
        }
    } else {
        $closes = (clone $opens)->modify('+90 minutes');
    }
    return ['opens' => $opens, 'closes' => $closes];
}

/**
 * How many minutes after `opens` an applicant can still enter the password
 * before being locked out for the day. Read from school setting
 * `exam_late_cutoff_minutes`, default 15.
 */
function exam_late_cutoff_minutes(): int
{
    $v = (int) school_setting('exam_late_cutoff_minutes', '15');
    return max(0, $v);
}
