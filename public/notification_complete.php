<?php
require_once __DIR__ . '/../config/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('notifications.php');

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
    db()->prepare("UPDATE notifications SET status='completed', completed_at=NOW() WHERE id=?")
        ->execute([$id]);
    log_action('notification', $id, 'completed');
    flash_set('success', 'Notificación marcada como completada.');
}
redirect($_SERVER['HTTP_REFERER'] ?? 'notifications.php');
