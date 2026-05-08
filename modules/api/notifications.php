<?php
// ============================================================
// modules/api/notifications.php
// API endpoint for notification operations (AJAX)
// ============================================================

require_once CORE_PATH . '/bootstrap.php';
Auth::requireRole(ROLE_STUDENT, ROLE_STAFF, ROLE_SSO, ROLE_DEAN, ROLE_ADMIN);

header('Content-Type: application/json');

$userId = Auth::id();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

switch ($action) {
    case 'list':
        $notifications = get_notifications($userId, 20);
        $unread = notification_count($userId);
        echo json_encode(['ok' => true, 'notifications' => $notifications, 'unread' => $unread]);
        break;

    case 'count':
        echo json_encode(['ok' => true, 'unread' => notification_count($userId)]);
        break;

    case 'read':
        csrf_check();
        mark_notifications_read($userId);
        echo json_encode(['ok' => true]);
        break;

    case 'read_one':
        csrf_check();
        $notifId = (int)($_POST['id'] ?? 0);
        if ($notifId) {
            try {
                db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
                    ->execute([$notifId, $userId]);
            } catch (\Throwable) {}
        }
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
}
