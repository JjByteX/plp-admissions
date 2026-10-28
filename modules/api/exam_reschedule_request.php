<?php
// ============================================================
// modules/api/exam_reschedule_request.php
// Student POST: request EXAM reschedule
//
// Mirrors modules/api/reschedule_request.php (interview side) through
// submit_reschedule_request(). Inserts a pending row in
// exam_reschedule_requests and notifies the staff who can approve it
// (Proctor / SSO / Dean / Admin, the roles allowed on
// /staff/exam/reschedule).
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT);
csrf_check();

submit_reschedule_request([
    'cols'     => 'id, overall_status',
    'redirect' => '/student/exam',
    'table'    => 'exam_reschedule_requests',
    'resolve'  => function (array $applicant, PDO $db): array {
        // Only students still at the exam stage can request an exam reschedule
        // - once they've moved on to interview/result/released, the exam side
        // is fixed.
        if (($applicant['overall_status'] ?? '') !== 'exam') {
            fail('Your exam stage is finished - reschedule unavailable.', '/student/exam');
        }

        // The student must currently be assigned to an exam slot.
        $stmt = $db->prepare('SELECT slot_id FROM applicant_exam_slots WHERE applicant_id = ? LIMIT 1');
        $stmt->execute([$applicant['id']]);
        $mySlot = $stmt->fetch();
        if (!$mySlot) {
            fail('No exam slot assigned yet - nothing to reschedule.', '/student/exam');
        }
        return ['slot_id' => (int) $mySlot['slot_id']];
    },
    'pendingMsg'    => 'You already have a pending exam reschedule request.',
    'auditAction'   => 'exam_reschedule_requested',
    'auditLabel'    => 'EXAM reschedule',
    'roles'         => [ROLE_STAFF, ROLE_PROCTOR, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN],
    'notifyType'    => 'exam_reschedule_request',
    'notifyTitle'   => 'Exam Reschedule Request',
    'notifyMessage' => 'A student has requested to reschedule their entrance exam.',
    'notifyLink'    => '/staff/exam/reschedule',
    'successMsg'    => 'Exam reschedule request submitted. Staff will review it shortly.',
]);
