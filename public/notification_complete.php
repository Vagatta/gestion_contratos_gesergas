<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Security.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('notifications.php');

// Validar CSRF
if (!isset($_POST['csrf_token']) || !Security::validateCsrfToken($_POST['csrf_token'])) {
    flash_set('danger', 'Token de seguridad inválido.');
    redirect('notifications.php');
}

// Rate limiting
if (!Security::checkRateLimit('notification_complete', 10, 60)) {
    flash_set('danger', 'Demasiados intentos. Por favor, espere.');
    redirect('notifications.php');
}

$id = (int)($_POST['id'] ?? 0);
if ($id > 0) {
    db()->prepare("UPDATE notifications SET status='completed', completed_at=NOW() WHERE id=?")
        ->execute([$id]);
    log_action('notification', $id, 'completed');
    flash_set('success', 'Notificación marcada como completada.');
}
redirect($_SERVER['HTTP_REFERER'] ?? 'notifications.php');
