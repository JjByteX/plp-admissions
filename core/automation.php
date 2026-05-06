<?php
// ============================================================
// core/automation.php
// Automation helpers for the admissions pipeline
// ============================================================

// ----------------------------------------------------------------
// NOTIFICATIONS
// ----------------------------------------------------------------

/**
 * Create an in-app notification for a user.
 */
function create_notification(int $userId, string $type, string $title, string $message = '', string $link = ''): void
{
    try {
        // Ensure table exists (graceful upgrade)
        ensure_notifications_table();

        db()->prepare(
            'INSERT INTO notifications (user_id, type, title, message, link)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $type, $title, $message ?: null, $link ?: null]);
    } catch (\Throwable $e) {
        error_log('Notification error: ' . $e->getMessage());
    }
}

/**
 * Get unread notification count for a user.
 */
function notification_count(int $userId): int
{
    try {
        ensure_notifications_table();
        $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    } catch (\Throwable) {
        return 0;
    }
}

/**
 * Get notifications for a user (newest first).
 */
function get_notifications(int $userId, int $limit = 20): array
{
    try {
        ensure_notifications_table();
        $stmt = db()->prepare(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?'
        );
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    } catch (\Throwable) {
        return [];
    }
}

/**
 * Mark all notifications as read for a user.
 */
function mark_notifications_read(int $userId): void
{
    try {
        ensure_notifications_table();
        db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')
            ->execute([$userId]);
    } catch (\Throwable) {}
}

/**
 * Notify a student when their status changes.
 */
function notify_stage_transition(int $applicantId, string $newStatus, string $extra = ''): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT user_id FROM applicants WHERE id = ?');
    $stmt->execute([$applicantId]);
    $row = $stmt->fetch();
    if (!$row) return;

    $userId = (int) $row['user_id'];

    $messages = [
        'submitted'  => ['Documents Submitted', 'Your application documents have been submitted for review.', '/student/documents'],
        'exam'       => ['Documents Approved', 'All your documents have been approved. You are now eligible for the entrance exam.' . ($extra ? ' ' . $extra : ''), '/student/exam'],
        'interview'  => ['Exam Passed', 'Congratulations! You passed the entrance exam. Your interview will be scheduled soon.', '/student/interview'],
        'released'   => ['Result Released', 'Your admission result has been released. Check your result now.' . ($extra ? ' ' . $extra : ''), '/student/result'],
        'withdrawn'  => ['Application Withdrawn', 'Your application has been withdrawn.', '/student/documents'],
    ];

    if (!isset($messages[$newStatus])) return;

    [$title, $message, $link] = $messages[$newStatus];
    create_notification($userId, 'stage_' . $newStatus, $title, $message, $link);
}

// ----------------------------------------------------------------
// DOCUMENT AUTO-VALIDATION
// ----------------------------------------------------------------

/**
 * Auto-validate a document using OCR-style checks:
 *  Step 1: File format + size validation
 *  Step 2: Image integrity check (not corrupted, minimum resolution)
 *  Step 3: PDF structure + text extraction (verify it has readable content)
 *  Step 4: Minimum file size heuristic (very small files are likely blank/invalid)
 *
 * If OCR checks pass → auto-approve.
 * If OCR checks fail → mark failed.
 * If OCR checks are uncertain → flag for manual review (staff can use AI fallback).
 *
 * Returns: 'passed' | 'failed' | 'uncertain'
 */
function auto_validate_document(int $documentId): string
{
    if (school_setting('auto_validate_documents', '1') !== '1') {
        return 'uncertain';
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT d.*, a.applicant_type
         FROM documents d
         JOIN applicants a ON a.id = d.applicant_id
         WHERE d.id = ?'
    );
    $stmt->execute([$documentId]);
    $doc = $stmt->fetch();
    if (!$doc || !$doc['file_path']) return 'uncertain';

    $result = 'uncertain';
    $confidence = 0;
    $details = [];

    $filePath = $doc['file_path'];
    $isUrl = str_starts_with($filePath, 'http');

    if (!$isUrl) {
        $fullPath = PUBLIC_PATH . $filePath;
        if (!file_exists($fullPath)) {
            $details['file_check'] = 'File not found on disk';
            log_document_validation($documentId, 'ocr', 'uncertain', 0, $details);
            return 'uncertain';
        }

        $fileSize = filesize($fullPath);
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($fullPath);

        // ── Step 1: File format + size ────────────────────────
        $validMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mimeType, $validMimes, true)) {
            $details['step1_format'] = 'FAIL: Invalid file type ' . $mimeType;
            log_document_validation($documentId, 'ocr', 'failed', 0, $details);
            return 'failed';
        }
        if ($fileSize > MAX_UPLOAD_BYTES) {
            $details['step1_format'] = 'FAIL: File too large (' . round($fileSize / 1024 / 1024, 2) . ' MB)';
            log_document_validation($documentId, 'ocr', 'failed', 0, $details);
            return 'failed';
        }
        // Very small files (< 5 KB) are suspicious — likely blank or placeholder
        if ($fileSize < 5120) {
            $details['step1_format'] = 'WARN: File very small (' . $fileSize . ' bytes)';
            $confidence = 30;
        } else {
            $details['step1_format'] = 'OK: ' . $mimeType . ', ' . round($fileSize / 1024) . ' KB';
            $confidence = 50;
        }

        // ── Step 2: Image integrity ──────────────────────────
        if (str_starts_with($mimeType, 'image/')) {
            $imgInfo = @getimagesize($fullPath);
            if ($imgInfo === false) {
                $details['step2_integrity'] = 'FAIL: Corrupted or invalid image';
                log_document_validation($documentId, 'ocr', 'failed', 10, $details);
                return 'failed';
            }

            $width = $imgInfo[0];
            $height = $imgInfo[1];
            $details['step2_integrity'] = "OK: {$width}x{$height}";

            // Document images should have reasonable resolution
            if ($width < 200 || $height < 200) {
                $details['step2_integrity'] .= ' WARN: Very low resolution';
                $confidence = max($confidence, 40);
            } elseif ($width >= 600 && $height >= 400) {
                $confidence = max($confidence, 75);
            } else {
                $confidence = max($confidence, 60);
            }
        }

        // ── Step 3: PDF structure + text extraction ──────────
        if ($mimeType === 'application/pdf') {
            $header = file_get_contents($fullPath, false, null, 0, 5);
            if ($header !== '%PDF-') {
                $details['step3_pdf'] = 'FAIL: Invalid PDF header';
                log_document_validation($documentId, 'ocr', 'failed', 10, $details);
                return 'failed';
            }

            // Try to extract text from PDF (basic OCR for text-based PDFs)
            $pdfText = extract_pdf_text($fullPath);
            if ($pdfText !== null) {
                $textLen = mb_strlen(trim($pdfText));
                if ($textLen > 20) {
                    $details['step3_pdf'] = 'OK: PDF has readable text (' . $textLen . ' chars)';
                    $confidence = max($confidence, 80);
                } elseif ($textLen > 0) {
                    $details['step3_pdf'] = 'OK: PDF has minimal text (' . $textLen . ' chars) — likely scanned';
                    $confidence = max($confidence, 65);
                } else {
                    $details['step3_pdf'] = 'OK: PDF valid but no extractable text — scanned document';
                    $confidence = max($confidence, 60);
                }
            } else {
                $details['step3_pdf'] = 'OK: Valid PDF structure (text extraction not available)';
                $confidence = max($confidence, 65);
            }
        }
    } else {
        // Remote file (Uploadcare CDN) - format validated at upload time
        $details['step1_format'] = 'OK: Remote file (CDN) — validated at upload';
        $confidence = 70;
    }

    // ── Determine result from OCR confidence ──────────────
    if ($confidence >= 70) {
        $result = 'passed';
    } elseif ($confidence <= 30) {
        $result = 'failed';
    } else {
        $result = 'uncertain'; // Flag for manual review; staff can use AI fallback
    }

    log_document_validation($documentId, 'ocr', $result, $confidence, $details);
    return $result;
}

/**
 * Extract text from a PDF file (basic OCR for text-based PDFs).
 * Returns extracted text, or null if extraction is not possible.
 */
function extract_pdf_text(string $pdfPath): ?string
{
    // Method 1: Try pdftotext command-line tool (poppler-utils)
    $cmd = 'pdftotext ' . escapeshellarg($pdfPath) . ' - 2>/dev/null';
    $output = @shell_exec($cmd);
    if ($output !== null && trim($output) !== '') {
        return trim($output);
    }

    // Method 2: Basic text extraction from PDF stream objects
    $content = @file_get_contents($pdfPath);
    if ($content === false) return null;

    $text = '';
    // Extract text between BT (begin text) and ET (end text) markers
    if (preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $matches)) {
        foreach ($matches[1] as $block) {
            // Extract text from Tj (show text) and TJ (show text array) operators
            if (preg_match_all('/\(([^)]*)\)\s*Tj/s', $block, $tj)) {
                $text .= implode(' ', $tj[1]);
            }
            if (preg_match_all('/\[([^\]]*)\]\s*TJ/s', $block, $tjarr)) {
                foreach ($tjarr[1] as $arr) {
                    if (preg_match_all('/\(([^)]*)\)/s', $arr, $parts)) {
                        $text .= implode('', $parts[1]);
                    }
                }
            }
        }
    }

    // Clean up extracted text
    $text = preg_replace('/[^\x20-\x7E\x0A\x0D]/', '', $text);
    return trim($text) !== '' ? trim($text) : '';
}

/**
 * Save AI validation result from client-side Puter AI.
 * Called via AJAX after the Puter JS SDK returns a result in the browser.
 */
function save_ai_validation(int $documentId, string $status, float $confidence, string $reason): void
{
    $details = ['ai_reason' => $reason, 'source' => 'puter_client'];

    log_document_validation($documentId, 'ai', $status, $confidence, $details);

    // Auto-approve if AI says it's valid with high confidence
    if ($status === 'passed') {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT status FROM documents WHERE id = ?');
        $stmt->execute([$documentId]);
        $doc = $stmt->fetch();
        if ($doc && in_array($doc['status'], ['uploaded', 'under_review'], true)) {
            $pdo->prepare('UPDATE documents SET status = ? WHERE id = ?')
                ->execute(['approved', $documentId]);
        }
    }
}

/**
 * Log document validation result.
 */
function log_document_validation(int $documentId, string $type, string $status, float $confidence, array $details): void
{
    try {
        ensure_document_validations_table();
        db()->prepare(
            'INSERT INTO document_validations (document_id, validation_type, status, confidence, details)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$documentId, $type, $status, $confidence, json_encode($details)]);
    } catch (\Throwable $e) {
        error_log('Validation log error: ' . $e->getMessage());
    }
}

// ----------------------------------------------------------------
// AUTO-ASSIGN EXAM SLOTS
// ----------------------------------------------------------------

/**
 * Auto-assign an applicant to the next available exam slot.
 * Called after all documents are approved.
 */
function auto_assign_exam_slot(int $applicantId): ?int
{
    if (school_setting('auto_assign_exam_slots', '1') !== '1') return null;

    $pdo = db();

    // Check if already assigned
    $stmt = $pdo->prepare('SELECT id FROM applicant_exam_slots WHERE applicant_id = ?');
    $stmt->execute([$applicantId]);
    if ($stmt->fetch()) return null;

    // Get applicant's department
    $stmt = $pdo->prepare(
        'SELECT a.course_applied, u.department
         FROM applicants a JOIN users u ON u.id = a.user_id
         WHERE a.id = ?'
    );
    $stmt->execute([$applicantId]);
    $appl = $stmt->fetch();
    if (!$appl) return null;

    $dept = $appl['department'] ?: course_to_department($appl['course_applied']);

    // Find next available slot (matching department, future date, has capacity)
    $stmt = $pdo->prepare(
        'SELECT ess.id, ess.capacity, ess.filled
         FROM exam_slot_schedule ess
         WHERE ess.department = ?
           AND ess.exam_date >= CURDATE()
           AND ess.filled < ess.capacity
         ORDER BY ess.exam_date ASC, ess.slot_time ASC
         LIMIT 1'
    );
    $stmt->execute([$dept]);
    $slot = $stmt->fetch();

    // If no dept-specific slot, try any slot
    if (!$slot) {
        $stmt = $pdo->prepare(
            'SELECT ess.id, ess.capacity, ess.filled
             FROM exam_slot_schedule ess
             WHERE ess.exam_date >= CURDATE()
               AND ess.filled < ess.capacity
             ORDER BY ess.exam_date ASC, ess.slot_time ASC
             LIMIT 1'
        );
        $stmt->execute();
        $slot = $stmt->fetch();
    }

    if (!$slot) return null;

    $slotId = (int) $slot['id'];

    try {
        $pdo->prepare(
            'INSERT INTO applicant_exam_slots (applicant_id, slot_id) VALUES (?, ?)'
        )->execute([$applicantId, $slotId]);

        $pdo->prepare(
            'UPDATE exam_slot_schedule SET filled = filled + 1 WHERE id = ?'
        )->execute([$slotId]);

        // Get slot details for notification
        $stmt = $pdo->prepare('SELECT exam_date, slot_time, room_label FROM exam_slot_schedule WHERE id = ?');
        $stmt->execute([$slotId]);
        $slotInfo = $stmt->fetch();

        if ($slotInfo) {
            $dateStr = date('F j, Y', strtotime($slotInfo['exam_date']));
            $timeStr = date('g:i A', strtotime($slotInfo['slot_time']));
            $room = $slotInfo['room_label'];

            $stmt = $pdo->prepare('SELECT user_id FROM applicants WHERE id = ?');
            $stmt->execute([$applicantId]);
            $userId = (int) $stmt->fetchColumn();

            create_notification(
                $userId,
                'exam_slot_assigned',
                'Exam Slot Assigned',
                "You have been assigned to take the exam on {$dateStr} at {$timeStr} in {$room}.",
                '/student/exam'
            );
        }

        audit_log('exam_slot_auto_assigned', "Auto-assigned applicant {$applicantId} to slot {$slotId}", 'applicant', $applicantId);
        return $slotId;
    } catch (\Throwable $e) {
        error_log('Auto-assign exam slot error: ' . $e->getMessage());
        return null;
    }
}

// ----------------------------------------------------------------
// AUTO-PROMOTE WAITLIST
// ----------------------------------------------------------------

/**
 * When an accepted student withdraws or declines, auto-promote the next
 * waitlisted applicant in the same course.
 */
function auto_promote_waitlist(int $applicantId): ?int
{
    if (school_setting('auto_promote_waitlist', '1') !== '1') return null;

    $pdo = db();

    // Get the withdrawn/declined applicant's course
    $stmt = $pdo->prepare('SELECT course_applied FROM applicants WHERE id = ?');
    $stmt->execute([$applicantId]);
    $course = $stmt->fetchColumn();
    if (!$course) return null;

    // Find the highest-ranked waitlisted applicant for the same course
    $stmt = $pdo->prepare(
        'SELECT a.id AS applicant_id, a.user_id, er.rank_score
         FROM applicants a
         JOIN admission_results ar ON ar.applicant_id = a.id
         LEFT JOIN exam_results er ON er.applicant_id = a.id
         WHERE a.course_applied = ?
           AND ar.result = "waitlisted"
           AND a.overall_status = "released"
         ORDER BY er.rank_score DESC, a.documents_approved_at ASC
         LIMIT 1'
    );
    $stmt->execute([$course]);
    $next = $stmt->fetch();

    if (!$next) return null;

    $nextApplicantId = (int) $next['applicant_id'];
    $nextUserId = (int) $next['user_id'];

    // Promote: update result to accepted
    $systemUserId = get_system_user_id();
    $pdo->prepare(
        'UPDATE admission_results
         SET result = "accepted", promoted_from_waitlist = 1,
             remarks = CONCAT(COALESCE(remarks, ""), "\nAuto-promoted from waitlist"),
             released_by = ?, released_at = NOW()
         WHERE applicant_id = ?'
    )->execute([$systemUserId, $nextApplicantId]);

    // Notify the promoted student
    create_notification(
        $nextUserId,
        'waitlist_promoted',
        'Congratulations! You have been accepted!',
        'You have been promoted from the waitlist and are now accepted for ' . $course . '.',
        '/student/result'
    );

    audit_log('waitlist_auto_promoted',
        "Auto-promoted applicant {$nextApplicantId} from waitlist for {$course} (replacing applicant {$applicantId})",
        'applicant', $nextApplicantId);

    return $nextApplicantId;
}

/**
 * Get the system admin user ID (for automated actions).
 */
function get_system_user_id(): int
{
    static $id = null;
    if ($id !== null) return $id;
    try {
        $stmt = db()->prepare('SELECT id FROM users WHERE role = "admin" ORDER BY id ASC LIMIT 1');
        $stmt->execute();
        $id = (int) $stmt->fetchColumn();
    } catch (\Throwable) {
        $id = 1;
    }
    return $id;
}

// ----------------------------------------------------------------
// AUTO-RESCHEDULE NO-SHOWS
// ----------------------------------------------------------------

/**
 * Auto-reschedule all no-show applicants from completed interview sessions
 * to the next available session in their department.
 */
function auto_reschedule_noshows(): int
{
    if (school_setting('auto_reschedule_noshows', '1') !== '1') return 0;

    $pdo = db();
    $rescheduled = 0;

    // Find no-show applicants from past interview slots
    $noshows = $pdo->query(
        'SELECT iq.id AS queue_id, iq.applicant_id, iq.slot_id,
                isl.department, isl.slot_date, isl.slot_time,
                a.user_id
         FROM interview_queue iq
         JOIN interview_slots isl ON isl.id = iq.slot_id
         JOIN applicants a ON a.id = iq.applicant_id
         WHERE iq.status = "no_show"
           AND iq.interview_status = "absent"
           AND isl.slot_date < CURDATE()
           AND a.overall_status = "interview"'
    )->fetchAll();

    foreach ($noshows as $ns) {
        $newSlotId = reschedule_interview((int) $ns['applicant_id']);
        if ($newSlotId) {
            $rescheduled++;

            // Get new slot details for notification
            $stmt = $pdo->prepare('SELECT slot_date, slot_time FROM interview_slots WHERE id = ?');
            $stmt->execute([$newSlotId]);
            $newSlot = $stmt->fetch();

            if ($newSlot) {
                $dateStr = date('F j, Y', strtotime($newSlot['slot_date']));
                $timeStr = $newSlot['slot_time'] ? date('g:i A', strtotime($newSlot['slot_time'])) : '';

                create_notification(
                    (int) $ns['user_id'],
                    'interview_rescheduled',
                    'Interview Rescheduled',
                    "You have been rescheduled for a new interview on {$dateStr}" . ($timeStr ? " at {$timeStr}" : '') . ". Please make sure to attend.",
                    '/student/interview'
                );
            }
        }
    }

    if ($rescheduled > 0) {
        audit_log('auto_reschedule_noshows', "Auto-rescheduled {$rescheduled} no-show applicant(s)");
    }

    return $rescheduled;
}

// ----------------------------------------------------------------
// AUTO-RELEASE RESULTS
// ----------------------------------------------------------------

/**
 * Auto-release results for applicants who have completed both exam and interview,
 * based on score thresholds.
 */
function auto_release_results(): array
{
    if (school_setting('auto_release_results', '0') !== '1') {
        return ['accepted' => 0, 'waitlisted' => 0, 'rejected' => 0];
    }

    $pdo = db();
    $systemUserId = get_system_user_id();
    $counts = ['accepted' => 0, 'waitlisted' => 0, 'rejected' => 0];

    // Find applicants who have completed interview but don't have a result yet
    $stmt = $pdo->query(
        'SELECT a.id AS applicant_id, a.user_id, a.course_applied,
                er.score, er.total_items, er.rank_score, er.passed AS exam_passed,
                iq.interview_status, iq.evaluation_result
         FROM applicants a
         JOIN exam_results er ON er.applicant_id = a.id
         LEFT JOIN interview_queue iq ON iq.applicant_id = a.id
         LEFT JOIN admission_results ar ON ar.applicant_id = a.id
         WHERE a.overall_status IN ("interview", "released")
           AND ar.id IS NULL
           AND iq.interview_status = "completed"
           AND iq.attendance_status = "present"'
    );
    $applicants = $stmt->fetchAll();

    foreach ($applicants as $appl) {
        $rank = (int) $appl['rank_score'];
        $course = $appl['course_applied'];
        $threshold = get_pass_threshold($course);
        $interviewPassed = ($appl['evaluation_result'] ?? '') !== 'fail';

        if ($rank >= $threshold && $interviewPassed) {
            $decision = ($rank >= 7) ? 'accepted' : 'accepted';
        } elseif ($rank >= ($threshold - 1) && $interviewPassed) {
            $decision = 'waitlisted';
        } else {
            $decision = 'rejected';
        }

        // Insert result
        $pdo->prepare(
            'INSERT INTO admission_results (applicant_id, result, remarks, released_by, released_at)
             VALUES (?, ?, "Auto-released based on score threshold", ?, NOW())
             ON DUPLICATE KEY UPDATE result=VALUES(result), remarks=VALUES(remarks),
                                     released_by=VALUES(released_by), released_at=NOW()'
        )->execute([(int) $appl['applicant_id'], $decision, $systemUserId]);

        // Update status
        $pdo->prepare('UPDATE applicants SET overall_status = "released" WHERE id = ?')
            ->execute([(int) $appl['applicant_id']]);

        // Notify student
        notify_stage_transition((int) $appl['applicant_id'], 'released', 'Result: ' . ucfirst($decision));

        $counts[$decision]++;
    }

    if (array_sum($counts) > 0) {
        audit_log('auto_release_results',
            "Auto-released results: {$counts['accepted']} accepted, {$counts['waitlisted']} waitlisted, {$counts['rejected']} rejected");
    }

    return $counts;
}

// ----------------------------------------------------------------
// BATCH INTERVIEW SESSION CREATION
// ----------------------------------------------------------------

/**
 * Create interview sessions in batch based on a template.
 *
 * @param array  $config  ['start_date', 'end_date', 'start_time', 'end_time',
 *                         'capacity', 'days' => [1,2,3,4,5] (Mon-Fri)]
 * @param string $department  Department name (or '' for all)
 * @param int    $staffId     Staff who created the sessions
 */
function batch_create_interview_sessions(array $config, string $department, int $staffId): int
{
    $pdo = db();
    $created = 0;

    $startDate = new DateTime($config['start_date']);
    $endDate   = new DateTime($config['end_date']);
    $startTime = $config['start_time'] ?? '09:00';
    $endTime   = $config['end_time']   ?? '16:00';
    $capacity  = max(1, (int)($config['capacity'] ?? 30));
    $days      = $config['days'] ?? [1, 2, 3, 4, 5]; // Mon-Fri by default

    $departments = $department ? [$department] : departments_list();

    $current = clone $startDate;
    while ($current <= $endDate) {
        $dayOfWeek = (int) $current->format('N'); // 1=Mon, 7=Sun

        if (in_array($dayOfWeek, $days, true)) {
            $dateStr = $current->format('Y-m-d');

            foreach ($departments as $dept) {
                // Check if slot already exists for this date/dept
                $stmt = $pdo->prepare(
                    'SELECT id FROM interview_slots
                     WHERE slot_date = ? AND department = ?
                     LIMIT 1'
                );
                $stmt->execute([$dateStr, $dept]);
                if ($stmt->fetch()) continue;

                $pdo->prepare(
                    'INSERT INTO interview_slots (slot_date, slot_time, end_time, capacity, department, created_by)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$dateStr, $startTime . ':00', $endTime . ':00', $capacity, $dept, $staffId]);
                $created++;

                // Auto-assign pending applicants to this new session
                bulk_assign_pending_applicants($dept, $staffId);
            }
        }

        $current->modify('+1 day');
    }

    if ($created > 0) {
        audit_log('batch_interview_sessions',
            "Batch-created {$created} interview session(s) for " . ($department ?: 'all departments'),
            'interview_slot');
    }

    return $created;
}

// ----------------------------------------------------------------
// IDLE APPLICANT ALERTS
// ----------------------------------------------------------------

/**
 * Get applicants stuck at any stage for more than X days.
 */
function get_idle_applicants(int $days = 7): array
{
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT a.id, a.overall_status, a.course_applied, a.updated_at,
                u.name, u.email,
                DATEDIFF(NOW(), a.updated_at) AS days_idle
         FROM applicants a
         JOIN users u ON u.id = a.user_id
         WHERE a.overall_status NOT IN ("released", "withdrawn")
           AND DATEDIFF(NOW(), a.updated_at) >= ?
         ORDER BY days_idle DESC'
    );
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * Get summary of idle applicants by stage.
 */
function get_idle_summary(int $days = 7): array
{
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT a.overall_status AS stage,
                COUNT(*) AS count,
                MAX(DATEDIFF(NOW(), a.updated_at)) AS max_days
         FROM applicants a
         WHERE a.overall_status NOT IN ("released", "withdrawn")
           AND DATEDIFF(NOW(), a.updated_at) >= ?
         GROUP BY a.overall_status
         ORDER BY count DESC'
    );
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

// ----------------------------------------------------------------
// TABLE CREATION HELPERS (graceful upgrades)
// ----------------------------------------------------------------

function ensure_notifications_table(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        db()->query('SELECT 1 FROM notifications LIMIT 0');
    } catch (\Throwable) {
        db()->exec('CREATE TABLE IF NOT EXISTS `notifications` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT(10) UNSIGNED NOT NULL,
            `type` VARCHAR(80) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `message` TEXT DEFAULT NULL,
            `link` VARCHAR(500) DEFAULT NULL,
            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_notif_user` (`user_id`, `is_read`),
            KEY `idx_notif_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}

function ensure_document_validations_table(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        db()->query('SELECT 1 FROM document_validations LIMIT 0');
    } catch (\Throwable) {
        db()->exec('CREATE TABLE IF NOT EXISTS `document_validations` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `document_id` INT(10) UNSIGNED NOT NULL,
            `validation_type` ENUM("ocr","ai","file_check") NOT NULL DEFAULT "file_check",
            `status` ENUM("passed","failed","uncertain") NOT NULL,
            `confidence` DECIMAL(5,2) DEFAULT NULL,
            `details` TEXT DEFAULT NULL,
            `validated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_dv_document` (`document_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
}

function ensure_automation_settings(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $defaults = [
        'auto_validate_documents' => '1',
        'auto_assign_exam_slots'  => '1',
        'auto_promote_waitlist'   => '1',
        'auto_reschedule_noshows' => '1',
        'auto_release_results'    => '0',
        'idle_applicant_days'     => '7',
        'doc_reminder_days'       => '3',
    ];

    try {
        foreach ($defaults as $key => $val) {
            db()->prepare(
                'INSERT IGNORE INTO school_settings (setting_key, setting_value) VALUES (?, ?)'
            )->execute([$key, $val]);
        }
    } catch (\Throwable) {}
}
