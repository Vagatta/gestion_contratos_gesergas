<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Security.php';
$pageTitle = 'Notificaciones';

$status = $_GET['status'] ?? 'pending';
$q      = trim((string)($_GET['q'] ?? ''));
$from   = trim((string)($_GET['from'] ?? ''));
$to     = trim((string)($_GET['to']   ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 5;
$offset = ($page - 1) * $perPage;

$where = [];
$args  = [];
if (in_array($status, ['pending','completed'], true)) {
    $where[] = 'n.status = :st'; $args[':st'] = $status;
}
if ($q !== '') { $where[] = 'cl.name LIKE :q';           $args[':q']  = "%$q%"; }
if ($from !== '') { $where[] = 'n.notify_date >= :df';   $args[':df'] = $from; }
if ($to   !== '') { $where[] = 'n.notify_date <= :dt';   $args[':dt'] = $to; }
$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Total para paginación
$countSt = db()->prepare("
    SELECT COUNT(*) FROM notifications n
    JOIN contracts c ON c.id = n.contract_id
    JOIN clients cl  ON cl.id = c.client_id
    $sqlWhere
");
$countSt->execute($args);
$totalRows = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$st = db()->prepare("
    SELECT n.*, cl.name AS client_name, c.contract_date
    FROM notifications n
    JOIN contracts c ON c.id = n.contract_id
    JOIN clients cl  ON cl.id = c.client_id
    $sqlWhere
    ORDER BY n.notify_date ASC
    LIMIT $perPage OFFSET $offset
");
$st->execute($args);
$rows = $st->fetchAll();

$buildPageUrl = function(int $p) use ($status, $q, $from, $to) {
    $params = array_filter([
        'status' => $status,
        'q'      => $q,
        'from'   => $from,
        'to'     => $to,
        'page'   => $p,
    ], fn($v) => $v !== '' && $v !== null);
    return 'notifications.php?' . http_build_query($params);
};

include __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-1">Notificaciones</h1>
    <p class="text-muted mb-0">Gestiona avisos de renovación de contratos</p>
  </div>
</div>

<!-- Filtros -->
<div class="card mb-4">
  <div class="card-body">
    <form class="row g-3" method="get">
      <div class="col-md-3">
        <label class="form-label">Estado</label>
        <select name="status" class="form-select">
          <option value="">Todas</option>
          <option value="pending"   <?= $status==='pending'?'selected':'' ?>>Pendientes</option>
          <option value="completed" <?= $status==='completed'?'selected':'' ?>>Completadas</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Cliente</label>
        <input name="q" value="<?= e($q) ?>" class="form-control" placeholder="Buscar cliente...">
      </div>
      <div class="col-md-2">
        <label class="form-label">Desde</label>
        <input type="date" name="from" value="<?= e($from) ?>" class="form-control">
      </div>
      <div class="col-md-2">
        <label class="form-label">Hasta</label>
        <input type="date" name="to" value="<?= e($to) ?>" class="form-control">
      </div>
      <div class="col-md-2 d-flex align-items-end">
        <button class="btn btn-primary w-100">
          <i class="bi bi-funnel"></i> Filtrar
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Tabla -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-list-ul me-2"></i>Resultados (<?= $totalRows ?>)</span>
    <?php if ($totalPages > 1): ?>
      <small style="color: var(--on-surface-variant);">
        Página <?= $page ?> de <?= $totalPages ?>
      </small>
    <?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead>
        <tr>
          <th style="width: 120px;"><i class="bi bi-calendar3 me-1"></i>Fecha</th>
          <th style="width: 180px;"><i class="bi bi-person me-1"></i>Cliente</th>
          <th><i class="bi bi-chat-left-text me-1"></i>Mensaje</th>
          <th style="width: 120px;"><i class="bi bi-flag me-1"></i>Estado</th>
          <th class="text-end" style="width: 200px;"><i class="bi bi-gear me-1"></i>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr>
          <td colspan="5">
            <div class="empty-state">
              <i class="bi bi-inbox"></i>
              <p>Sin notificaciones que coincidan con los filtros</p>
            </div>
          </td>
        </tr>
      <?php else: foreach ($rows as $n):
        $due = $n['status']==='pending' && $n['notify_date'] <= date('Y-m-d'); ?>
        <tr>
          <td>
            <span class="fw-medium" style="font-size: 0.875rem;"><?= e($n['notify_date']) ?></span>
          </td>
          <td><?= e($n['client_name']) ?></td>
          <td style="color: var(--on-surface-variant); font-size: 0.875rem;">
            <?= e($n['message']) ?>
          </td>
          <td>
            <?php if ($n['status']==='completed'): ?>
              <span class="badge bg-success">
                <i class="bi bi-check-circle-fill"></i> Completada
              </span>
            <?php elseif ($due): ?>
              <span class="badge bg-danger">
                <i class="bi bi-exclamation-circle-fill"></i> Vencida
              </span>
            <?php else: ?>
              <span class="badge bg-warning">
                <i class="bi bi-clock-fill"></i> Pendiente
              </span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <div class="d-inline-flex gap-2">
              <?php if ($n['status']==='pending'): ?>
                <form method="post" action="notification_complete.php" style="display: inline;">
                  <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                  <?= Security::csrfField() ?>
                  <button class="btn btn-sm" style="background: var(--success); color: white;">
                    <i class="bi bi-check2"></i> Completar
                  </button>
                </form>
              <?php endif; ?>
              <a class="btn btn-sm btn-outline-secondary" href="contract_form.php?id=<?= (int)$n['contract_id'] ?>">
                <i class="bi bi-file-earmark-text"></i> Contrato
              </a>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 p-3" style="border-top: 1px solid var(--outline-variant);">
    <small style="color: var(--on-surface-variant); font-size: 0.8125rem;">
      Mostrando <strong><?= $offset + 1 ?></strong>–<strong><?= min($offset + $perPage, $totalRows) ?></strong> de <strong><?= $totalRows ?></strong>
    </small>
    <div class="d-flex gap-1 align-items-center">
      <a href="<?= $page > 1 ? e($buildPageUrl($page - 1)) : '#' ?>"
         class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>">
        <i class="bi bi-chevron-left"></i> Anterior
      </a>
      <?php
        $pFrom = max(1, $page - 2);
        $pTo   = min($totalPages, $pFrom + 4);
        $pFrom = max(1, $pTo - 4);
        for ($p = $pFrom; $p <= $pTo; $p++):
      ?>
        <a href="<?= e($buildPageUrl($p)) ?>"
           class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-outline-secondary' ?>"
           style="min-width: 36px;">
          <?= $p ?>
        </a>
      <?php endfor; ?>
      <a href="<?= $page < $totalPages ? e($buildPageUrl($page + 1)) : '#' ?>"
         class="btn btn-sm btn-outline-secondary <?= $page >= $totalPages ? 'disabled' : '' ?>">
        Siguiente <i class="bi bi-chevron-right"></i>
      </a>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
