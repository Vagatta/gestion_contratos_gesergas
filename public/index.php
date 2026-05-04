<?php
require_once __DIR__ . '/../config/db.php';
$pageTitle = 'Dashboard';

$stats = db()->query("
    SELECT
      (SELECT COUNT(*) FROM contracts)                                           AS total_contracts,
      (SELECT COUNT(*) FROM clients)                                             AS total_clients,
      (SELECT COUNT(*) FROM notifications WHERE status='pending')                AS pending_notifs,
      (SELECT COUNT(*) FROM notifications WHERE status='pending'
         AND notify_date <= CURDATE())                                           AS due_today
")->fetch();

$upcoming = db()->query("
    SELECT n.*, c.contract_date, cl.name AS client_name
    FROM notifications n
    JOIN contracts c  ON c.id = n.contract_id
    JOIN clients  cl ON cl.id = c.client_id
    WHERE n.status='pending'
    ORDER BY n.notify_date ASC
    LIMIT 10
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<!-- Header Welcome -->
<header class="mb-5 pt-4">
  <h1 style="font-size: 1.5rem; font-weight: 600; letter-spacing: -0.02em; color: var(--on-surface); margin-bottom: 0.5rem;">
    Dashboard
  </h1>
  <p style="color: var(--on-surface-variant); font-size: 1rem; margin: 0;">
    Resumen de contratos y notificaciones pendientes
  </p>
</header>

<!-- Stats Bento Grid -->
<section class="row g-4 mb-5">
  <!-- Primary Stat Card -->
  <div class="col-md-6 col-lg-4">
    <div class="card card-stat primary h-100">
      <div class="d-flex flex-column h-100 justify-content-between">
        <div>
          <small style="color: var(--on-primary-container); opacity: 0.8; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em;">
            Contratos totales
          </small>
          <div class="h3" style="font-size: 3rem; font-weight: 700; color: var(--on-primary); margin: 0.5rem 0;">
            <?= (int)$stats['total_contracts'] ?>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2" style="font-size: 0.875rem; color: var(--on-primary);">
          <i class="bi bi-file-earmark-text"></i>
          <span>Gestiona tus contratos</span>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Secondary Stat Cards -->
  <div class="col-md-6 col-lg-4">
    <div class="row g-3">
      <div class="col-6">
        <div class="card card-stat h-100">
          <div class="icon-box success mb-2">
            <i class="bi bi-people"></i>
          </div>
          <small style="color: var(--on-surface-variant); font-size: 0.75rem;">Clientes</small>
          <div class="h3" style="font-size: 1.75rem; margin: 0;">
            <?= (int)$stats['total_clients'] ?>
          </div>
        </div>
      </div>
      <div class="col-6">
        <div class="card card-stat h-100" style="border-color: var(--warning);">
          <div class="icon-box warning mb-2">
            <i class="bi bi-bell"></i>
          </div>
          <small style="color: var(--on-surface-variant); font-size: 0.75rem;">Pendientes</small>
          <div class="h3" style="font-size: 1.75rem; margin: 0;">
            <?= (int)$stats['pending_notifs'] ?>
          </div>
        </div>
      </div>
      <div class="col-12">
        <div class="card card-stat h-100" style="border-color: var(--error);">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <small style="color: var(--on-surface-variant); font-size: 0.75rem;">Vencidas hoy o antes</small>
              <div class="h3" style="font-size: 1.5rem; margin: 0;">
                <?= (int)$stats['due_today'] ?>
              </div>
            </div>
            <div class="icon-box danger">
              <i class="bi bi-exclamation-triangle"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Quick Actions -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-lightning-charge" style="color: var(--warning);"></i>
        <span>Acceso rápido</span>
      </div>
      <div class="card-body">
        <div class="d-grid gap-2">
          <a href="contract_form.php" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i>
            <span>Nuevo contrato</span>
          </a>
          <a href="contracts.php" class="btn btn-outline-primary">
            <i class="bi bi-list-ul"></i>
            <span>Ver contratos</span>
          </a>
          <a href="calendar.php" class="btn btn-outline-secondary">
            <i class="bi bi-calendar3"></i>
            <span>Calendario</span>
          </a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Main Content Grid -->
<div class="row g-4">
  <!-- Recent Notifications -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-calendar-event" style="color: var(--primary);"></i>
          <span>Próximas notificaciones</span>
          <?php if (count($upcoming) > 3): ?>
            <span class="badge" style="background: var(--surface-container); color: var(--on-surface-variant); font-size: 0.7rem; margin-left: 0.25rem;">
              <?= count($upcoming) ?>
            </span>
          <?php endif; ?>
        </div>
        <a href="notifications.php" style="font-size: 0.875rem; font-weight: 500;">Ver todas</a>
      </div>
      <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
        <table class="table mb-0" style="--sticky-bg: var(--surface-container-low);">
          <thead>
            <tr>
              <th style="width: 120px;">Fecha</th>
              <th>Cliente</th>
              <th>Mensaje</th>
              <th class="text-end" style="width: 100px;">Acción</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$upcoming): ?>
            <tr>
              <td colspan="4">
                <div class="empty-state">
                  <i class="bi bi-inbox"></i>
                  <p>No hay notificaciones pendientes</p>
                </div>
              </td>
            </tr>
          <?php else: foreach ($upcoming as $n): ?>
            <tr>
              <td>
                <span class="fw-medium"><?= e($n['notify_date']) ?></span>
              </td>
              <td><?= e($n['client_name']) ?></td>
              <td style="color: var(--on-surface-variant);"><?= e($n['message']) ?></td>
              <td class="text-end">
                <a href="contract_form.php?id=<?= (int)$n['contract_id'] ?>" class="btn btn-sm btn-outline-primary">
                  Ver
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  
  <!-- Alerts Section -->
  <div class="col-lg-4">
    <h2 style="font-size: 1.125rem; font-weight: 600; margin-bottom: 1rem; color: var(--on-surface);">
      Alertas importantes
    </h2>
    <div class="d-flex flex-column gap-3">
      <?php if ($stats['due_today'] > 0): ?>
      <div class="card" style="background: var(--error-container); border-color: var(--error);">
        <div class="card-body d-flex gap-3">
          <div class="mt-1">
            <i class="bi bi-exclamation-triangle-fill" style="color: var(--error);"></i>
          </div>
          <div>
            <h4 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.25rem; color: var(--on-error-container);">
              Notificaciones vencidas
            </h4>
            <p style="font-size: 0.875rem; color: var(--on-error-container); margin: 0; opacity: 0.9;">
              Tienes <?= (int)$stats['due_today'] ?> notificaciones que requieren atención inmediata.
            </p>
          </div>
        </div>
      </div>
      <?php endif; ?>
      
      <?php if ($stats['pending_notifs'] > 0): ?>
      <div class="card" style="background: #fef3c7; border-color: var(--warning);">
        <div class="card-body d-flex gap-3">
          <div class="mt-1">
            <i class="bi bi-clock-fill" style="color: var(--warning);"></i>
          </div>
          <div>
            <h4 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.25rem;">
              Pendientes por gestionar
            </h4>
            <p style="font-size: 0.875rem; color: var(--on-surface-variant); margin: 0;">
              <?= (int)$stats['pending_notifs'] ?> notificaciones en cola.
            </p>
          </div>
        </div>
      </div>
      <?php endif; ?>
      
      <?php if ($stats['due_today'] == 0 && $stats['pending_notifs'] == 0): ?>
      <div class="card" style="background: var(--surface-container); border-color: var(--outline-variant);">
        <div class="card-body d-flex gap-3">
          <div class="mt-1">
            <i class="bi bi-check-circle-fill" style="color: var(--success);"></i>
          </div>
          <div>
            <h4 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 0.25rem;">
              Todo en orden
            </h4>
            <p style="font-size: 0.875rem; color: var(--on-surface-variant); margin: 0;">
              No hay alertas pendientes. El sistema está al día.
            </p>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
