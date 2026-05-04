<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Security.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('contracts.php');

// Validar CSRF
if (!isset($_POST['csrf_token']) || !Security::validateCsrfToken($_POST['csrf_token'])) {
    flash_set('danger', 'Token de seguridad inválido.');
    redirect('contracts.php');
}

// Rate limiting para eliminaciones (más estricto)
if (!Security::checkRateLimit('contract_delete', 3, 300)) {
    flash_set('danger', 'Demasiados intentos de eliminación. Por favor, espere 5 minutos.');
    redirect('contracts.php');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) redirect('contracts.php');

// Recuperar archivo para borrarlo del disco.
$st = db()->prepare('SELECT document_path FROM contracts WHERE id=?');
$st->execute([$id]);
$path = $st->fetchColumn();

db()->prepare('DELETE FROM contracts WHERE id=?')->execute([$id]);

if ($path) {
    $file = UPLOAD_DIR . DIRECTORY_SEPARATOR . $path;
    if (is_file($file)) @unlink($file);
}
log_action('contract', $id, 'deleted');

flash_set('success', 'Contrato eliminado.');
redirect('contracts.php');
