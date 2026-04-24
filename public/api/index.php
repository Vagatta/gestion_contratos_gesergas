<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../src/ApiResponse.php';

// CORS preflight
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    ApiResponse::json(['ok' => true]);
}

// Parsear ruta: /api/v1/contracts/123  => ['v1','contracts','123']
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$base = '/api/';
$pos  = strpos($uri, $base);
$path = $pos !== false ? substr($uri, $pos + strlen($base)) : $uri;
$path = trim($path, '/');
$segs = $path === '' ? [] : explode('/', $path);

if (($segs[0] ?? '') !== 'v1') {
    ApiResponse::error('Versión de API no soportada', 404);
}

$method   = $_SERVER['REQUEST_METHOD'];
$resource = $segs[1] ?? '';
$id       = isset($segs[2]) ? (ctype_digit($segs[2]) ? (int)$segs[2] : $segs[2]) : null;
$action   = $segs[3] ?? null;

try {
    switch ($resource) {
        case 'contracts':     handle_contracts($method, $id, $action); break;
        case 'clients':       handle_clients($method, $id);            break;
        case 'notifications': handle_notifications($method, $id, $action); break;
        case 'parse-pdf':     handle_parse_pdf($method);               break;
        case '':
            ApiResponse::json(['name'=>'Contratos API','version'=>'v1',
                'endpoints'=>['/contracts','/clients','/notifications','/parse-pdf']]);
            break;
        default: ApiResponse::error('Recurso no encontrado', 404);
    }
} catch (Throwable $e) {
    ApiResponse::error('Error interno: ' . $e->getMessage(), 500);
}

// ---------------------------------------------------------------------------
// CONTRACTS
// ---------------------------------------------------------------------------
function handle_contracts(string $method, $id, ?string $action): void
{
    if ($id === null) {
        if ($method === 'GET')  { contracts_list();   return; }
        if ($method === 'POST') { contracts_create(); return; }
        ApiResponse::error('Método no permitido', 405);
    }
    if ($method === 'GET')    { contracts_get((int)$id);    return; }
    if ($method === 'PUT')    { contracts_update((int)$id); return; }
    if ($method === 'DELETE') { contracts_delete((int)$id); return; }
    ApiResponse::error('Método no permitido', 405);
}

function contracts_list(): void
{
    $q     = trim((string)($_GET['q']    ?? ''));
    $from  = trim((string)($_GET['from'] ?? ''));
    $to    = trim((string)($_GET['to']   ?? ''));
    $limit = max(1, min(500, (int)($_GET['limit']  ?? 100)));
    $offset= max(0, (int)($_GET['offset'] ?? 0));

    $where = []; $args = [];
    if ($q !== '')    { $where[] = '(cl.name LIKE :q OR cl.address LIKE :q OR cl.contratista LIKE :q)'; $args[':q']="%$q%"; }
    if ($from !== '') { $where[] = 'c.contract_date >= :df'; $args[':df']=$from; }
    if ($to   !== '') { $where[] = 'c.contract_date <= :dt'; $args[':dt']=$to; }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $st = db()->prepare("
        SELECT c.id, c.contract_date, c.document_path, c.document_name, c.notes,
               c.client_id, cl.name AS client_name, cl.address, cl.contratista
        FROM contracts c
        JOIN clients cl ON cl.id = c.client_id
        $w
        ORDER BY c.contract_date DESC
        LIMIT $limit OFFSET $offset
    ");
    $st->execute($args);
    $items = $st->fetchAll();

    $total = (int)db()->query("SELECT COUNT(*) FROM contracts")->fetchColumn();

    ApiResponse::json(['data' => $items, 'meta' => compact('total','limit','offset')]);
}

function contracts_get(int $id): void
{
    $st = db()->prepare("
        SELECT c.*, cl.name AS client_name, cl.address, cl.contratista
        FROM contracts c JOIN clients cl ON cl.id=c.client_id WHERE c.id=?");
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) ApiResponse::error('No encontrado', 404);

    $st = db()->prepare('SELECT id,type,label,value,is_primary FROM contact_methods WHERE client_id=?');
    $st->execute([$c['client_id']]);
    $c['contacts'] = $st->fetchAll();

    $st = db()->prepare('SELECT id,notify_date,message,status,completed_at FROM notifications WHERE contract_id=? ORDER BY notify_date');
    $st->execute([$id]);
    $c['notifications'] = $st->fetchAll();

    ApiResponse::json(['data' => $c]);
}

function contracts_create(): void
{
    $in = ApiResponse::input();
    $err = contracts_validate($in);
    if ($err) ApiResponse::error($err, 422);

    $pdo = db(); $pdo->beginTransaction();
    try {
        $clientId = resolve_client($in);
        $pdo->prepare('INSERT INTO contracts (client_id, contract_date, notes) VALUES (?,?,?)')
            ->execute([$clientId, $in['contract_date'], $in['notes'] ?? null]);
        $id = (int)$pdo->lastInsertId();

        save_contacts($clientId, $in['contacts'] ?? [], /*replace*/ true);
        regenerate_notification($id, $in['contract_date'], $clientId);
        log_action('contract', $id, 'created', 'api');

        $pdo->commit();
        contracts_get($id);
    } catch (Throwable $e) {
        $pdo->rollBack(); throw $e;
    }
}

function contracts_update(int $id): void
{
    $in = ApiResponse::input();
    $err = contracts_validate($in);
    if ($err) ApiResponse::error($err, 422);

    $pdo = db();
    $st = $pdo->prepare('SELECT client_id FROM contracts WHERE id=?');
    $st->execute([$id]);
    $clientId = (int)$st->fetchColumn();
    if (!$clientId) ApiResponse::error('No encontrado', 404);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE clients SET name=?, address=?, contratista=? WHERE id=?')
            ->execute([$in['client_name'], $in['address'] ?? null, $in['contratista'] ?? null, $clientId]);

        $pdo->prepare('UPDATE contracts SET contract_date=?, notes=? WHERE id=?')
            ->execute([$in['contract_date'], $in['notes'] ?? null, $id]);

        if (array_key_exists('contacts', $in)) {
            save_contacts($clientId, $in['contacts'], true);
        }
        regenerate_notification($id, $in['contract_date'], $clientId);
        log_action('contract', $id, 'updated', 'api');

        $pdo->commit();
        contracts_get($id);
    } catch (Throwable $e) {
        $pdo->rollBack(); throw $e;
    }
}

function contracts_delete(int $id): void
{
    $st = db()->prepare('SELECT document_path FROM contracts WHERE id=?');
    $st->execute([$id]);
    $path = $st->fetchColumn();
    if ($path === false) ApiResponse::error('No encontrado', 404);

    db()->prepare('DELETE FROM contracts WHERE id=?')->execute([$id]);
    if ($path) {
        $f = UPLOAD_DIR . DIRECTORY_SEPARATOR . $path;
        if (is_file($f)) @unlink($f);
    }
    log_action('contract', $id, 'deleted', 'api');
    ApiResponse::json(['ok' => true], 200);
}

function contracts_validate(array $in): ?string
{
    if (empty($in['client_name']))   return 'client_name es obligatorio';
    if (empty($in['contract_date'])) return 'contract_date es obligatorio';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['contract_date'])) {
        return 'contract_date debe ser YYYY-MM-DD';
    }
    return null;
}

function resolve_client(array $in): int
{
    db()->prepare('INSERT INTO clients (name,address,contratista) VALUES (?,?,?)')
        ->execute([$in['client_name'], $in['address'] ?? null, $in['contratista'] ?? null]);
    return (int)db()->lastInsertId();
}

function save_contacts(int $clientId, array $contacts, bool $replace): void
{
    $pdo = db();
    if ($replace) {
        $pdo->prepare('DELETE FROM contact_methods WHERE client_id=?')->execute([$clientId]);
    }
    $st = $pdo->prepare('INSERT INTO contact_methods (client_id,type,label,value,is_primary) VALUES (?,?,?,?,?)');
    $allowed = ['phone','email','whatsapp','other'];
    foreach ($contacts as $c) {
        $val = trim((string)($c['value'] ?? ''));
        if ($val === '') continue;
        $type = in_array($c['type'] ?? 'other', $allowed, true) ? $c['type'] : 'other';
        $st->execute([
            $clientId, $type,
            $c['label'] ?? null, $val,
            !empty($c['is_primary']) ? 1 : 0,
        ]);
    }
}

function regenerate_notification(int $contractId, string $contractDate, int $clientId): void
{
    $notifyDate = compute_notify_date($contractDate);

    $st = db()->prepare('SELECT type,value FROM contact_methods WHERE client_id=? ORDER BY is_primary DESC, id ASC LIMIT 1');
    $st->execute([$clientId]);
    $primary = $st->fetch();

    $msg = 'Hay que contactar con este cliente. Método de contacto: '
         . ($primary ? strtoupper($primary['type']) . ' -> ' . $primary['value'] : 'sin datos de contacto');

    db()->prepare("DELETE FROM notifications WHERE contract_id=? AND status='pending'")->execute([$contractId]);
    db()->prepare('INSERT INTO notifications (contract_id,notify_date,message) VALUES (?,?,?)')
        ->execute([$contractId, $notifyDate, $msg]);
}

// ---------------------------------------------------------------------------
// CLIENTS
// ---------------------------------------------------------------------------
function handle_clients(string $method, $id): void
{
    if ($id === null) {
        if ($method === 'GET')  { clients_list();   return; }
        if ($method === 'POST') { clients_create(); return; }
        ApiResponse::error('Método no permitido', 405);
    }
    if ($method === 'GET')    { clients_get((int)$id);    return; }
    if ($method === 'PUT')    { clients_update((int)$id); return; }
    if ($method === 'DELETE') { clients_delete((int)$id); return; }
    ApiResponse::error('Método no permitido', 405);
}

function clients_list(): void
{
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $st = db()->prepare('SELECT * FROM clients WHERE name LIKE ? OR address LIKE ? ORDER BY name LIMIT 200');
        $st->execute(["%$q%","%$q%"]);
    } else {
        $st = db()->query('SELECT * FROM clients ORDER BY name LIMIT 200');
    }
    ApiResponse::json(['data' => $st->fetchAll()]);
}

function clients_get(int $id): void
{
    $st = db()->prepare('SELECT * FROM clients WHERE id=?');
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) ApiResponse::error('No encontrado', 404);

    $st = db()->prepare('SELECT id,type,label,value,is_primary FROM contact_methods WHERE client_id=?');
    $st->execute([$id]);
    $c['contacts'] = $st->fetchAll();
    ApiResponse::json(['data' => $c]);
}

function clients_create(): void
{
    $in = ApiResponse::input();
    if (empty($in['name'])) ApiResponse::error('name es obligatorio', 422);
    db()->prepare('INSERT INTO clients (name,address,contratista) VALUES (?,?,?)')
        ->execute([$in['name'], $in['address'] ?? null, $in['contratista'] ?? null]);
    $id = (int)db()->lastInsertId();
    save_contacts($id, $in['contacts'] ?? [], true);
    log_action('client', $id, 'created', 'api');
    clients_get($id);
}

function clients_update(int $id): void
{
    $in = ApiResponse::input();
    if (empty($in['name'])) ApiResponse::error('name es obligatorio', 422);
    $st = db()->prepare('UPDATE clients SET name=?,address=?,contratista=? WHERE id=?');
    $st->execute([$in['name'], $in['address'] ?? null, $in['contratista'] ?? null, $id]);
    if ($st->rowCount() === 0 && !clients_exists($id)) ApiResponse::error('No encontrado', 404);
    if (array_key_exists('contacts', $in)) save_contacts($id, $in['contacts'], true);
    log_action('client', $id, 'updated', 'api');
    clients_get($id);
}

function clients_delete(int $id): void
{
    $st = db()->prepare('DELETE FROM clients WHERE id=?');
    $st->execute([$id]);
    if ($st->rowCount() === 0) ApiResponse::error('No encontrado', 404);
    log_action('client', $id, 'deleted', 'api');
    ApiResponse::json(['ok' => true]);
}

function clients_exists(int $id): bool
{
    $st = db()->prepare('SELECT 1 FROM clients WHERE id=?');
    $st->execute([$id]);
    return (bool)$st->fetchColumn();
}

// ---------------------------------------------------------------------------
// NOTIFICATIONS
// ---------------------------------------------------------------------------
function handle_notifications(string $method, $id, ?string $action): void
{
    if ($id === null && $method === 'GET') { notifications_list(); return; }
    if ($id !== null && $action === 'complete' && $method === 'POST') { notifications_complete((int)$id); return; }
    ApiResponse::error('Ruta no encontrada', 404);
}

function notifications_list(): void
{
    $status = $_GET['status'] ?? '';
    $from   = $_GET['from']   ?? '';
    $to     = $_GET['to']     ?? '';
    $q      = trim((string)($_GET['q'] ?? ''));

    $where = []; $args = [];
    if (in_array($status, ['pending','completed'], true)) { $where[]='n.status=:st'; $args[':st']=$status; }
    if ($from !== '') { $where[]='n.notify_date >= :df'; $args[':df']=$from; }
    if ($to   !== '') { $where[]='n.notify_date <= :dt'; $args[':dt']=$to; }
    if ($q    !== '') { $where[]='cl.name LIKE :q';      $args[':q']="%$q%"; }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $st = db()->prepare("
        SELECT n.*, cl.name AS client_name, c.contract_date
        FROM notifications n
        JOIN contracts c ON c.id=n.contract_id
        JOIN clients cl  ON cl.id=c.client_id
        $w ORDER BY n.notify_date ASC LIMIT 500");
    $st->execute($args);
    ApiResponse::json(['data' => $st->fetchAll()]);
}

function notifications_complete(int $id): void
{
    $st = db()->prepare("UPDATE notifications SET status='completed', completed_at=NOW() WHERE id=?");
    $st->execute([$id]);
    if ($st->rowCount() === 0) ApiResponse::error('No encontrada', 404);
    log_action('notification', $id, 'completed', 'api');
    ApiResponse::json(['ok' => true]);
}

// ---------------------------------------------------------------------------
// PARSE PDF (OCR / extracción de campos)
// ---------------------------------------------------------------------------
function handle_parse_pdf(string $method): void
{
    if ($method !== 'POST') ApiResponse::error('Usa POST con multipart/form-data', 405);

    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        ApiResponse::error('Adjunta un PDF en el campo "file"', 400);
    }
    if (($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        ApiResponse::error('Error al subir el archivo', 400);
    }
    $orig = $_FILES['file']['name'];
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') ApiResponse::error('Sólo PDF', 415);

    require_once __DIR__ . '/../../src/PdfExtractor.php';
    $extractor = new PdfExtractor();
    $result    = $extractor->extract($_FILES['file']['tmp_name']);

    // No devolvemos el texto completo por defecto (puede ser grande); sólo preview.
    $preview = mb_substr($result['text'], 0, 1500);

    ApiResponse::json([
        'source'  => $result['source'],
        'fields'  => $result['fields'],
        'preview' => $preview,
    ]);
}
