<?php
// ============================================================
// modules/api/reschedule_request.php
// Student POST: request interview reschedule
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);
csrf_check();

submit_reschedule_request([
    'cols'     => 'id',
    'redirect' => '/student/interview',
    'table'    => 'reschedule_requests',
    'resolve'  => function (array $applicant, PDO $db): array {
        $stmt = $db->prepare('SELECT id, status FROM interview_queue WHERE applicant_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$applicant['id']]);
        $queue = $stmt->fetch();
        if (!$queue || !in_array($queue['status'], ['scheduled', 'waiting', 'checked_in'], true)) {
            fail('No active interview slot to reschedule.', '/student/interview');
        }
        return ['queue_id' => $queue['id']];
    },
    'pendingMsg'    => 'You already have a pending reschedule request.',
    'auditAction'   => 'reschedule_requested',
    'auditLabel'    => 'interview reschedule',
    // Notify all roles that can actually act on a reschedule request:
    // staff (read-only view), SSO, Dean, and Admin (can approve/deny).
    'roles'         => [ROLE_STAFF, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN],
    'notifyType'    => 'reschedule_request',
    'notifyTitle'   => 'Reschedule Request',
    'notifyMessage' => 'A student has requested to reschedule their interview.',
    'notifyLink'    => '/staff/interviews/absent?tab=requests',
    'successMsg'    => 'Reschedule request submitted. Staff will review it shortly.',
]);
