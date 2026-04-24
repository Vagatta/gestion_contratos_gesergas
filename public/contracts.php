<?php
require_once __DIR__ . '/../config/db.php';
$pageTitle = 'Contratos';

$q       = trim((string)($_GET['q']       ?? ''));
$dateFrom = trim((string)($_GET['from']    ?? ''));
$dateTo   = trim((string)($_GET['to']      ?? ''));
$page     = max(1, (int)($_GET['page']     ?? 1));
$perPage  = 5;
$offset   = ($page - 1) * $perPage;

$where = [];
$args  = [];
if ($q !== '') {
    $where[] = '(cl.name LIKE :q OR cl.address LIKE :q OR cl.contratista LIKE :q)';
    $args[':q'] = '%' . $q . '%';
}
if ($dateFrom !== '') { $where[] = 'c.contract_date >= :df'; $args[':df'] = $dateFrom; }
if ($dateTo   !== '') { $where[] = 'c.contract_date <= :dt'; $args[':dt'] = $dateTo; }
$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Total registros para paginación
$countSt = db()->prepare("SELECT COUNT(*) FROM contracts c JOIN clients cl ON cl.id = c.client_id $sqlWhere");
$countSt->execute($args);
$totalRows = (int)$countSt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$rows = db()->prepare("
    SELECT c.*, cl.name AS client_name, cl.contratista,
           (SELECT COUNT(*) FROM notifications n WHERE n.contract_id=c.id AND n.status='pending') AS pending
    FROM contracts c
    JOIN clients cl ON cl.id = c.client_id
    $sqlWhere
    ORDER BY c.contract_date DESC
    LIMIT $perPage OFFSET $offset
");
$rows->execute($args);
$rows = $rows->fetchAll();

// Helper para construir URL manteniendo filtros
$buildPageUrl = function(int $p) use ($q, $dateFrom, $dateTo) {
    $params = array_filter([
        'q'    => $q,
        'from' => $dateFrom,
        'to'   => $dateTo,
        'page' => $p,
    ], fn($v) => $v !== '' && $v !== null);
    return 'contracts.php?' . http_build_query($params);
};

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-1">Contratos</h1>
    <p class="text-muted mb-0">Gestiona todos los contratos y sus documentos</p>
  </div>
  <a href="contract_form.php" class="btn btn-primary">
    <i class="bi bi-plus-lg me-2"></i>Nuevo contrato
  </a>
</div>

<div class="card mb-4">
  <div class="card-body">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label"><i class="bi bi-search me-1"></i>Buscar</label>
        <input name="q" value="<?= e($q) ?>" class="form-control" placeholder="Cliente, dirección o contratista...">
      </div>
      <div class="col-md-2">
        <label class="form-label"><i class="bi bi-calendar3 me-1"></i>Desde</label>
        <input type="date" name="from" value="<?= e($dateFrom) ?>" class="form-control">
      </div>
      <div class="col-md-2">
        <label class="form-label"><i class="bi bi-calendar3 me-1"></i>Hasta</label>
        <input type="date" name="to" value="<?= e($dateTo) ?>" class="form-control">
      </div>
      <div class="col-md-4 d-flex gap-2">
        <button class="btn btn-primary flex-fill">
          <i class="bi bi-funnel me-1"></i>Filtrar
        </button>
        <a href="contracts.php" class="btn btn-outline-secondary">
          <i class="bi bi-x-lg"></i>
        </a>
      </div>
    </form>
  </div>
</div>

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
          <th><i class="bi bi-calendar3 me-1"></i>Fecha</th>
          <th><i class="bi bi-person me-1"></i>Cliente</th>
          <th><i class="bi bi-building me-1"></i>Contratista</th>
          <th><i class="bi bi-file-earmark me-1"></i>Documento</th>
          <th><i class="bi bi-bell me-1"></i>Notif.</th>
          <th class="text-end"><i class="bi bi-gear me-1"></i>Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr>
          <td colspan="6" class="text-center text-muted py-5">
            <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
            <?php if ($q || $dateFrom || $dateTo): ?>
              No hay resultados para los filtros aplicados.
            <?php else: ?>
              No hay contratos registrados. <a href="contract_form.php">Crea el primero</a>.
            <?php endif; ?>
          </td>
        </tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><span class="fw-medium"><?= e($r['contract_date']) ?></span></td>
          <td><?= e($r['client_name']) ?></td>
          <td><span class="text-muted"><?= e($r['contratista']) ?></span></td>
          <td>
            <?php if ($r['document_path']): ?>
              <div class="d-inline-flex align-items-center gap-1">
                <a href="document.php?id=<?= (int)$r['id'] ?>" target="_blank"
                   class="d-inline-flex align-items-center gap-1"
                   style="padding: 0.25rem 0.625rem; background: #fee2e2; color: #991b1b; border-radius: var(--radius-sm); font-size: 0.75rem; font-weight: 500; text-decoration: none;"
                   title="<?= e($r['document_name']) ?>">
                  <i class="bi bi-file-earmark-pdf-fill"></i>
                  <span class="text-truncate" style="max-width: 120px;"><?= e($r['document_name'] ?: 'Ver PDF') ?></span>
                </a>
                <a href="document.php?id=<?= (int)$r['id'] ?>&download=1"
                   class="btn btn-sm btn-outline-secondary py-0 px-2"
                   title="Descargar">
                  <i class="bi bi-download" style="font-size: 0.75rem;"></i>
                </a>
              </div>
            <?php else: ?>
              <span class="badge" style="background: var(--surface-container); color: var(--on-surface-variant); font-weight: 500;">
                <i class="bi bi-dash-circle"></i> Sin PDF
              </span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ((int)$r['pending']): ?>
              <span class="badge bg-warning"><?= (int)$r['pending'] ?> pend.</span>
            <?php else: ?>
              <span class="badge bg-success">OK</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary" href="contract_form.php?id=<?= (int)$r['id'] ?>" title="Editar">
              <i class="bi bi-pencil"></i>
            </a>
            <form method="post" action="contract_delete.php" class="d-inline" onsubmit="return confirm('¿Eliminar este contrato permanentemente?');">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" title="Eliminar">
                <i class="bi bi-trash"></i>
              </button>
            </form>
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
        // Mostrar hasta 5 números de página centrados en el actual
        $from = max(1, $page - 2);
        $to   = min($totalPages, $from + 4);
        $from = max(1, $to - 4);
        for ($p = $from; $p <= $to; $p++):
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
