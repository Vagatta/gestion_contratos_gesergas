<?php
require_once __DIR__ . '/../config/db.php';

$id = (int)($_GET['id'] ?? 0);
$download = isset($_GET['download']);

if ($id <= 0) {
    http_response_code(400);
    exit('ID inválido');
}

$st = db()->prepare('SELECT document_path, document_name FROM contracts WHERE id=?');
$st->execute([$id]);
$row = $st->fetch();

if (!$row || !$row['document_path']) {
    http_response_code(404);
    exit('Documento no encontrado');
}

$path = UPLOAD_DIR . DIRECTORY_SEPARATOR . $row['document_path'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Archivo no disponible en disco');
}

$name = $row['document_name'] ?: ('contrato_' . $id . '.pdf');
$disposition = $download ? 'attachment' : 'inline';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($name) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;
