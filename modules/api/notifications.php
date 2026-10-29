<?php
// ============================================================
// modules/api/notifications.php
// API endpoint for notification operations (AJAX)
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT, ROLE_STAFF, ROLE_PROCTOR, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);

$userId = Auth::id();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

switch ($action) {
    case 'list':
        $notifications = get_notifications($userId, 20);
        $unread = notification_count($userId);
        json_response(['ok' => true, 'notifications' => $notifications, 'unread' => $unread]);

    case 'count':
        json_response(['ok' => true, 'unread' => notification_count($userId)]);

    case 'read':
        csrf_check();
        mark_notifications_read($userId);
        json_response(['ok' => true]);

    case 'read_one':
        csrf_check();
        $notifId = post_int('id');
        if ($notifId) {
            try {
                db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
                    ->execute([$notifId, $userId]);
            } catch (\Throwable) {}
        }
        json_response(['ok' => true]);

    default:
        json_response(['error' => 'Invalid action'], 400);
}
