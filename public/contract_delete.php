<?php
require_once __DIR__ . '/../config/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('contracts.php');

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
