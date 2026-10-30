<?php
// ============================================================
// core/automation/notifications.php
// Notifications: in-app and email, student and staff, reschedule decisions.
// Loaded by core/automation.php; plain functions, unchanged.
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
        db()->prepare(
            'INSERT INTO notifications (user_id, type, title, message, link)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$userId, $type, $title, $message ?: null, $link ?: null]);
    } catch (\Throwable $e) {
        error_log('Notification error: ' . $e->getMessage());
    }
}

/**
 * In-app notification + branded email for one user (student).
 * Looks up the email/name itself. A mail failure is logged, never thrown.
 * $link is an app path ('/student/exam'); the email button gets the full URL.
 */
function notify_student(
    int $userId,
    string $type,
    string $title,
    string $message,
    string $link,
    string $buttonLabel = 'View Details'
): void {
    create_notification($userId, $type, $title, $message, $link);

    try {
        $stmt = db()->prepare('SELECT email, name FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user && !empty($user['email'])) {
            $emailBody = '<p>' . e($message) . '</p>'
                . email_button(rtrim(BASE_URL, '/') . $link, $buttonLabel);
            send_email(
                (string) $user['email'],
                $title . ' - ' . school_setting('school_name', 'PLP Admissions'),
                email_template($title, $emailBody),
                (string) $user['name']
            );
        }
    } catch (\Throwable $e) {
        error_log('notify_student email failed: ' . $e->getMessage());
    }
}

/**
 * Get unread notification count for a user.
 */
function notification_count(int $userId): int
{
    try {
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
    if (!$row) {
        return;
    }

    $userId = (int) $row['user_id'];

    $messages = [
        'submitted' => [
            'Documents Submitted',
            'Your application documents have been submitted for review.',
            '/student/documents',
        ],
        'exam' => [
            'Documents Approved',
            'All your documents have been approved. You are now eligible for the entrance exam.'
                . ($extra ? ' ' . $extra : ''),
            '/student/exam',
        ],
        'interview' => [
            'Exam Passed',
            'Congratulations! You passed the entrance exam. Your interview will be scheduled soon.',
            '/student/interview',
        ],
        'released' => [
            'Result Released',
            'Your admission result has been released. Check your result now.' . ($extra ? ' ' . $extra : ''),
            '/student/result',
        ],
        'withdrawn' => [
            'Application Withdrawn',
            'Your application has been withdrawn.',
            '/student/documents',
        ],
        'enrollment_confirmed' => [
            'Continue to Enrollment',
            'Congratulations on your admission to PLP! Your details have been sent to the Registrar.' . ($extra ? ' ' . $extra : ''),
            '/student/result',
        ],
    ];

    if (!isset($messages[$newStatus])) {
        return;
    }

    [$title, $message, $link] = $messages[$newStatus];
    notify_student($userId, 'stage_' . $newStatus, $title, $message, $link);
}

/**
 * Send a welcome email after registration.
 */
function send_registration_email(string $email, string $name): void
{
    $body = '<p>Welcome, <strong>' . e($name) . '</strong>!</p>'
        . '<p>Your account has been created successfully. Please log in and upload your required documents '
        . 'to continue with your application.</p>'
        . email_button(rtrim(BASE_URL, '/') . '/student/documents', 'Upload Documents');
    send_email(
        $email,
        'Welcome to ' . school_setting('school_name', 'PLP Admissions'),
        email_template('Welcome!', $body),
        $name
    );
}

// ----------------------------------------------------------------
// RESCHEDULE: shared decision notification (in-app + email)
// ----------------------------------------------------------------

/**
 * In-app notification + branded email letting a student know their
 * reschedule request was approved or denied. Used by both the
 * interview and exam approve/deny handlers.
 *
 * $kind     = 'interview' | 'exam'
 * $decision = 'approved'  | 'denied'
 * $extra    = optional details ("new slot: Mon Jan 5, 9 AM, Rm 201"
 *             on approve; "reason: scheduling conflict" on deny)
 */
function notify_reschedule_decision(
    int $applicantId,
    string $kind,
    string $decision,
    string $extra = ''
): void {
    $kind     = $kind === 'exam' ? 'exam' : 'interview';
    $decision = $decision === 'approved' ? 'approved' : 'denied';

    try {
        $stmt = db()->prepare(
            'SELECT u.id AS user_id, u.email, u.name
               FROM applicants a
               JOIN users u ON u.id = a.user_id
              WHERE a.id = ?
              LIMIT 1'
        );
        $stmt->execute([$applicantId]);
        $user = $stmt->fetch();
        if (!$user) {
            return;
        }

        $link = $kind === 'exam' ? '/student/exam' : '/student/interview';

        if ($decision === 'approved') {
            $title = 'Reschedule approved';
            $msg   = ucfirst($kind) . " reschedule approved."
                   . ($extra ? ' ' . $extra : '');
        } else {
            $title = 'Reschedule request denied';
            $msg   = "Your {$kind} reschedule request was not approved."
                   . ($extra ? ' ' . $extra : '');
        }

        notify_student((int)$user['user_id'], 'reschedule_' . $decision, $title, $msg, $link);
    } catch (\Throwable $e) {
        error_log('reschedule decision notify failed: ' . $e->getMessage());
    }
}

// ----------------------------------------------------------------
// A3: STAFF NOTIFICATIONS
// ----------------------------------------------------------------

/**
 * Notify every active user holding one of the given roles. Pass ROLE_* constants.
 * Returns how many users were notified (0 on failure or no match).
 */
function notify_roles(array $roles, string $type, string $title, string $message = '', string $link = ''): int
{
    $roles = array_values(array_unique(array_map('strval', $roles)));
    if (!$roles) {
        return 0;
    }
    try {
        [$ph, $params] = sql_in($roles);
        $stmt = db()->prepare("SELECT id FROM users WHERE role IN ($ph) AND is_active = 1");
        $stmt->execute($params);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $userId) {
            create_notification((int)$userId, $type, $title, $message, $link);
        }
        return count($ids);
    } catch (\Throwable $e) {
        error_log('notify_roles error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Notify all staff/admin users about an event.
 */
function notify_staff(string $type, string $title, string $message = '', string $link = ''): void
{
    notify_roles([ROLE_STAFF, ROLE_ADMIN], $type, $title, $message, $link);
}

/**
 * Notify staff when a no-show is detected.
 */
function notify_staff_no_show(int $applicantId, string $applicantName): void
{
    notify_staff(
        'staff_no_show',
        'No-Show Detected',
        "{$applicantName} was marked as a no-show for their interview.",
        '/staff/interviews/absent'
    );
}
