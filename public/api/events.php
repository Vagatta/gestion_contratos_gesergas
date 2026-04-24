<?php
require_once __DIR__ . '/../../config/db.php';

header('Content-Type: application/json; charset=utf-8');

$from   = $_GET['from']   ?? date('Y-m-01');
$to     = $_GET['to']     ?? date('Y-m-t');
$status = $_GET['status'] ?? '';
$q      = trim((string)($_GET['q'] ?? ''));

$where = ['n.notify_date >= :df', 'n.notify_date < :dt'];
$args  = [':df'=>$from, ':dt'=>$to];
if (in_array($status, ['pending','completed'], true)) { $where[]='n.status=:st'; $args[':st']=$status; }
if ($q !== '') { $where[] = 'cl.name LIKE :q'; $args[':q'] = "%$q%"; }

$sql = "SELECT n.id, n.contract_id, n.notify_date, n.status, n.message, cl.name AS client_name
        FROM notifications n
        JOIN contracts c ON c.id=n.contract_id
        JOIN clients cl  ON cl.id=c.client_id
        WHERE " . implode(' AND ', $where);

$st = db()->prepare($sql);
$st->execute($args);

$out = [];
foreach ($st->fetchAll() as $r) {
    $color = $r['status']==='completed' ? '#10b981'
           : ($r['notify_date'] <= date('Y-m-d') ? '#ef4444' : '#f59e0b');
    $out[] = [
        'id'       => (int)$r['id'],
        'title'    => $r['client_name'],
        'start'    => $r['notify_date'],
        'allDay'   => true,
        'color'    => $color,
        'textColor'=> '#ffffff',
        'extendedProps' => [
            'contract_id' => (int)$r['contract_id'],
            'message'     => $r['message'],
        ],
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
