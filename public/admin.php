<?php
require_once __DIR__ . '/../config/db.php';

// ===== Protección por contraseña (solo admin) =====
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Contraseña configurable vía .env (docker-compose la inyecta en el contenedor)
$adminPassword = getenv('ADMIN_PASSWORD') ?: ($_ENV['ADMIN_PASSWORD'] ?? 'admin123');

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['admin_ok']);
    header('Location: admin.php');
    exit;
}

// Procesar login
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    if (hash_equals($adminPassword, (string)$_POST['admin_password'])) {
        $_SESSION['admin_ok'] = true;
        header('Location: admin.php');
        exit;
    }
    $loginError = 'Contraseña incorrecta';
}

// Si no está autenticado, mostrar formulario y salir
if (empty($_SESSION['admin_ok'])):
    $pageTitle = 'Admin - Acceso';
    include __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-center align-items-center" style="min-height: 60vh;">
  <div class="card" style="max-width: 420px; width: 100%;">
    <div class="card-body" style="padding: 2.5rem;">
      <div style="width: 64px; height: 64px; background: var(--primary-fixed); border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; font-size: 2rem; color: var(--primary);">
        <i class="bi bi-shield-lock-fill"></i>
      </div>
      <h1 style="font-size: 1.5rem; font-weight: 600; text-align: center; margin-bottom: 0.5rem;">
        Panel de Administración
      </h1>
      <p style="text-align: center; color: var(--on-surface-variant); font-size: 0.875rem; margin-bottom: 2rem;">
        Introduce la contraseña para acceder
      </p>
      <?php if ($loginError): ?>
        <div class="alert alert-danger" style="font-size: 0.875rem;">
          <i class="bi bi-exclamation-circle"></i> <?= e($loginError) ?>
        </div>
      <?php endif; ?>
      <form method="post">
        <div class="mb-4">
          <label class="form-label">Contraseña</label>
          <input name="admin_password" type="password" class="form-control" required autofocus autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary w-100 btn-lg">
          <i class="bi bi-unlock-fill"></i> Acceder
        </button>
      </form>
    </div>
  </div>
</div>
<?php
    include __DIR__ . '/includes/footer.php';
    exit;
endif;
// ===== Fin protección =====

$pageTitle = 'Panel de Administración';
$pdo = db();

// Guardar configuración de intervalos
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $selected = $_POST['intervals'] ?? [];
    $catalog = notification_intervals_catalog();
    $valid = array_values(array_filter(
        is_array($selected) ? $selected : [],
        fn($k) => isset($catalog[$k])
    ));
    if (empty($valid)) $valid = ['1_month']; // nunca guardar vacío
    setting_set('notification_schedule', json_encode($valid));
    header('Location: admin.php?saved=1');
    exit;
}

$configuredIntervals = get_configured_intervals();
$intervalCatalog = notification_intervals_catalog();

// Métricas principales
$metrics = $pdo->query("
    SELECT
      (SELECT COUNT(*) FROM contracts)                                                          AS total_contracts,
      (SELECT COUNT(*) FROM clients)                                                            AS total_clients,
      (SELECT COUNT(*) FROM contact_methods)                                                    AS total_contacts,
      (SELECT COUNT(*) FROM notifications)                                                      AS total_notifs,
      (SELECT COUNT(*) FROM notifications WHERE status='pending')                               AS pending_notifs,
      (SELECT COUNT(*) FROM notifications WHERE status='completed')                             AS completed_notifs,
      (SELECT COUNT(*) FROM notifications WHERE status='pending' AND notify_date <= CURDATE())  AS overdue_notifs,
      (SELECT COUNT(*) FROM contracts WHERE document_path IS NOT NULL)                          AS with_pdf,
      (SELECT COUNT(*) FROM contracts WHERE contract_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS recent_30d,
      (SELECT COUNT(*) FROM contracts WHERE contract_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY))  AS recent_7d
")->fetch();

// Notificaciones por tipo (buscando en el mensaje el label)
$byType = $pdo->query("
    SELECT
      SUM(CASE WHEN message LIKE '%3 meses antes%' THEN 1 ELSE 0 END) AS three_months,
      SUM(CASE WHEN message LIKE '%1 mes antes%' THEN 1 ELSE 0 END) AS one_month,
      SUM(CASE WHEN message LIKE '%15 días antes%' THEN 1 ELSE 0 END) AS fifteen_days,
      SUM(CASE WHEN message LIKE '%Día del vencimiento%' THEN 1 ELSE 0 END) AS due_day
    FROM notifications WHERE status='pending'
")->fetch();

// Contratos por mes (últimos 12 meses)
$byMonth = $pdo->query("
    SELECT DATE_FORMAT(contract_date, '%Y-%m') AS ym, COUNT(*) AS total
    FROM contracts
    WHERE contract_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY ym
    ORDER BY ym ASC
")->fetchAll();

// Top 5 clientes con más contratos
$topClients = $pdo->query("
    SELECT cl.name, COUNT(c.id) AS contracts_count
    FROM clients cl
    LEFT JOIN contracts c ON c.client_id = cl.id
    GROUP BY cl.id
    ORDER BY contracts_count DESC
    LIMIT 5
")->fetchAll();

// Notificaciones críticas próximas (siguientes 15 días)
$critical = $pdo->query("
    SELECT n.*, cl.name AS client_name, c.contract_date
    FROM notifications n
    JOIN contracts c ON c.id = n.contract_id
    JOIN clients cl ON cl.id = c.client_id
    WHERE n.status='pending'
      AND n.notify_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 15 DAY)
    ORDER BY n.notify_date ASC
    LIMIT 10
")->fetchAll();

// Actividad reciente (action_log) con nombre de cliente cuando sea posible.
// - Para entity='contract': entity_id es el id del contrato → unir con contracts + clients
// - Para entity='notification': entity_id es id de notificación → unir con notifications + contracts + clients
$recentActivity = $pdo->query("
    SELECT
        al.*,
        CASE
            WHEN al.entity = 'contract'     THEN cl_c.name
            WHEN al.entity = 'notification' THEN cl_n.name
            ELSE NULL
        END AS client_name
    FROM action_log al
    LEFT JOIN contracts c_c      ON al.entity = 'contract'     AND c_c.id = al.entity_id
    LEFT JOIN clients   cl_c     ON cl_c.id   = c_c.client_id
    LEFT JOIN notifications n_n  ON al.entity = 'notification' AND n_n.id = al.entity_id
    LEFT JOIN contracts c_n      ON c_n.id    = n_n.contract_id
    LEFT JOIN clients   cl_n     ON cl_n.id   = c_n.client_id
    ORDER BY al.created_at DESC
    LIMIT 15
")->fetchAll();

include __DIR__ . '/includes/header.php';

// Tasa de completado
$completionRate = $metrics['total_notifs'] > 0
    ? round(($metrics['completed_notifs'] / $metrics['total_notifs']) * 100, 1)
    : 0;

// Tasa con PDF
$pdfRate = $metrics['total_contracts'] > 0
    ? round(($metrics['with_pdf'] / $metrics['total_contracts']) * 100, 1)
    : 0;
?>

<!-- Header -->
<header class="mb-5 pt-4">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
    <div>
      <div class="d-flex align-items-center gap-2 mb-2">
        <span class="badge" style="background: var(--primary-fixed); color: var(--primary);">
          <i class="bi bi-shield-lock-fill"></i> ADMIN
        </span>
      </div>
      <h1 style="font-size: 1.75rem; font-weight: 600; letter-spacing: -0.02em; color: var(--on-surface); margin-bottom: 0.5rem;">
        Panel de Administración
      </h1>
      <p style="color: var(--on-surface-variant); font-size: 1rem; margin: 0;">
        Métricas, analíticas y actividad del sistema
      </p>
    </div>
    <div class="d-flex gap-2">
      <a href="admin.php?logout=1" class="btn btn-outline-secondary">
        <i class="bi bi-box-arrow-right"></i> Cerrar sesión
      </a>
    </div>
  </div>
</header>

<?php if (isset($_GET['saved'])): ?>
<div class="alert alert-success mb-4">
  <i class="bi bi-check-circle-fill"></i> Configuración guardada correctamente.
</div>
<?php endif; ?>

<!-- Configuración de notificaciones -->
<section class="card mb-4">
  <div class="card-header">
    <i class="bi bi-gear-fill" style="color: var(--primary);"></i>
    <span>Configuración de notificaciones automáticas</span>
  </div>
  <div class="card-body">
    <p style="color: var(--on-surface-variant); font-size: 0.875rem; margin-bottom: 1rem;">
      Selecciona cuándo quieres que el sistema genere avisos automáticamente al crear o editar un contrato. Los cambios solo afectan a contratos creados/editados <strong>después</strong> de guardar.
    </p>
    <form method="post">
      <div class="row g-3">
        <?php foreach ($intervalCatalog as $key => $meta):
          $checked = in_array($key, $configuredIntervals, true);
        ?>
        <div class="col-md-6 col-lg-3">
          <label class="d-flex align-items-start gap-2 p-3" style="background: <?= $checked ? 'var(--primary-fixed)' : 'var(--surface-container-low)' ?>; border: 1px solid <?= $checked ? 'var(--primary)' : 'var(--outline-variant)' ?>; border-radius: var(--radius-md); cursor: pointer; transition: all 0.2s;">
            <input type="checkbox" name="intervals[]" value="<?= e($key) ?>" <?= $checked ? 'checked' : '' ?>
                   class="form-check-input mt-1" style="flex-shrink: 0;">
            <div>
              <div style="font-size: 0.875rem; font-weight: 600; color: var(--on-surface);">
                <?= e($meta['label']) ?>
              </div>
              <small style="color: var(--on-surface-variant); font-size: 0.75rem;">
                <?= e($meta['offset']) ?>
              </small>
            </div>
          </label>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="d-flex align-items-center gap-3 mt-3 pt-3" style="border-top: 1px solid var(--outline-variant);">
        <button type="submit" name="save_settings" value="1" class="btn btn-primary">
          <i class="bi bi-save"></i> Guardar configuración
        </button>
        <small style="color: var(--on-surface-variant); font-size: 0.8125rem;">
          <i class="bi bi-info-circle"></i>
          Actualmente activo: <strong><?= count($configuredIntervals) ?></strong> aviso<?= count($configuredIntervals) === 1 ? '' : 's' ?> por contrato
        </small>
      </div>
    </form>
  </div>
</section>

<!-- KPI Cards -->
<section class="row g-3 mb-5">
  <div class="col-md-6 col-lg-3">
    <div class="card card-stat h-100">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="icon-box primary"><i class="bi bi-file-earmark-text"></i></div>
        <span class="badge" style="background: #d1fae5; color: #065f46; font-size: 0.7rem;">
          +<?= (int)$metrics['recent_7d'] ?> (7d)
        </span>
      </div>
      <small>Contratos totales</small>
      <div class="h3"><?= (int)$metrics['total_contracts'] ?></div>
    </div>
  </div>
  
  <div class="col-md-6 col-lg-3">
    <div class="card card-stat h-100">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="icon-box success"><i class="bi bi-people"></i></div>
        <span class="badge" style="background: var(--surface-container); color: var(--on-surface-variant); font-size: 0.7rem;">
          <?= (int)$metrics['total_contacts'] ?> contactos
        </span>
      </div>
      <small>Clientes</small>
      <div class="h3"><?= (int)$metrics['total_clients'] ?></div>
    </div>
  </div>
  
  <div class="col-md-6 col-lg-3">
    <div class="card card-stat h-100">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="icon-box warning"><i class="bi bi-bell"></i></div>
        <span class="badge" style="background: #fee2e2; color: #991b1b; font-size: 0.7rem;">
          <?= (int)$metrics['overdue_notifs'] ?> vencidas
        </span>
      </div>
      <small>Notif. pendientes</small>
      <div class="h3"><?= (int)$metrics['pending_notifs'] ?></div>
    </div>
  </div>
  
  <div class="col-md-6 col-lg-3">
    <div class="card card-stat h-100">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <div class="icon-box" style="background: #ede9fe; color: #6d28d9;"><i class="bi bi-file-pdf"></i></div>
        <span class="badge" style="background: var(--primary-fixed); color: var(--primary); font-size: 0.7rem;">
          <?= $pdfRate ?>%
        </span>
      </div>
      <small>Contratos con PDF</small>
      <div class="h3"><?= (int)$metrics['with_pdf'] ?></div>
    </div>
  </div>
</section>

<!-- Row 2: Breakdown + Chart -->
<div class="row g-4 mb-5">
  <!-- Notificaciones por tipo -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-bell-fill" style="color: var(--primary);"></i>
        <span>Desglose de notificaciones pendientes</span>
      </div>
      <div class="card-body">
        <div class="d-flex flex-column gap-3">
          <?php
          $types = [
            ['label' => '3 meses antes', 'count' => (int)($byType['three_months'] ?? 0), 'color' => '#60a5fa', 'icon' => 'bi-calendar3'],
            ['label' => '1 mes antes',   'count' => (int)($byType['one_month'] ?? 0),    'color' => '#fbbf24', 'icon' => 'bi-calendar2-week'],
            ['label' => '15 días antes', 'count' => (int)($byType['fifteen_days'] ?? 0), 'color' => '#fb923c', 'icon' => 'bi-calendar-event'],
            ['label' => 'Día del vencimiento', 'count' => (int)($byType['due_day'] ?? 0), 'color' => '#ef4444', 'icon' => 'bi-calendar-x'],
          ];
          $maxCount = max(1, max(array_column($types, 'count')));
          foreach ($types as $t):
            $pct = $maxCount > 0 ? round(($t['count'] / $maxCount) * 100) : 0;
          ?>
          <div>
            <div class="d-flex justify-content-between align-items-center mb-1">
              <span style="font-size: 0.875rem; font-weight: 500; display: flex; align-items: center; gap: 0.5rem;">
                <i class="<?= $t['icon'] ?>" style="color: <?= $t['color'] ?>;"></i>
                <?= e($t['label']) ?>
              </span>
              <strong style="font-size: 0.875rem;"><?= $t['count'] ?></strong>
            </div>
            <div style="height: 8px; background: var(--surface-container); border-radius: 9999px; overflow: hidden;">
              <div style="height: 100%; width: <?= $pct ?>%; background: <?= $t['color'] ?>; border-radius: 9999px; transition: width 0.3s;"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Contratos por mes (chart simple) -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-bar-chart-fill" style="color: var(--primary);"></i>
        <span>Contratos por mes (últimos 12 meses)</span>
      </div>
      <div class="card-body">
        <?php if (!$byMonth): ?>
          <div class="empty-state">
            <i class="bi bi-graph-up"></i>
            <p>Sin datos suficientes</p>
          </div>
        <?php else:
          $maxM = max(array_column($byMonth, 'total'));
        ?>
        <div class="d-flex align-items-end gap-2" style="height: 200px;">
          <?php foreach ($byMonth as $m):
            $h = $maxM > 0 ? ($m['total'] / $maxM) * 100 : 0;
            $ym = DateTime::createFromFormat('Y-m', $m['ym']);
            $label = $ym ? $ym->format('M y') : $m['ym'];
          ?>
          <div class="d-flex flex-column align-items-center flex-fill" style="height: 100%;">
            <div style="flex: 1; display: flex; align-items: flex-end; width: 100%;">
              <div style="width: 100%; background: linear-gradient(180deg, var(--primary-container) 0%, var(--primary) 100%); height: <?= $h ?>%; border-radius: 4px 4px 0 0; position: relative;" title="<?= e($m['ym']) ?>: <?= (int)$m['total'] ?>">
                <span style="position: absolute; top: -20px; left: 50%; transform: translateX(-50%); font-size: 0.7rem; font-weight: 600; color: var(--on-surface);">
                  <?= (int)$m['total'] ?>
                </span>
              </div>
            </div>
            <small style="font-size: 0.65rem; color: var(--on-surface-variant); margin-top: 0.5rem; transform: rotate(-45deg); white-space: nowrap;">
              <?= e($label) ?>
            </small>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Row 3: Top clients + Critical notifications -->
<div class="row g-4 mb-5">
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-trophy-fill" style="color: var(--warning);"></i>
        <span>Top 5 clientes</span>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead>
            <tr>
              <th>Cliente</th>
              <th class="text-end" style="width: 120px;">Contratos</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$topClients): ?>
            <tr><td colspan="2"><div class="empty-state"><i class="bi bi-person-x"></i><p>Sin clientes</p></div></td></tr>
          <?php else: foreach ($topClients as $i => $cl): ?>
            <tr>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <span style="width: 24px; height: 24px; border-radius: 9999px; background: var(--primary-fixed); color: var(--primary); display: inline-flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700;"><?= $i+1 ?></span>
                  <span class="fw-medium"><?= e($cl['name']) ?></span>
                </div>
              </td>
              <td class="text-end">
                <span class="badge" style="background: var(--surface-container); color: var(--on-surface);">
                  <?= (int)$cl['contracts_count'] ?>
                </span>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div>
          <i class="bi bi-fire" style="color: var(--error);"></i>
          <span>Notificaciones críticas (próximos 15 días)</span>
        </div>
        <a href="notifications.php" style="font-size: 0.875rem;">Ver todas</a>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead>
            <tr>
              <th style="width: 110px;">Fecha</th>
              <th>Cliente</th>
              <th style="width: 90px;" class="text-end">Días</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$critical): ?>
            <tr><td colspan="3"><div class="empty-state"><i class="bi bi-check-circle"></i><p>No hay notificaciones críticas</p></div></td></tr>
          <?php else: foreach ($critical as $n):
            $daysLeft = (new DateTime($n['notify_date']))->diff(new DateTime())->days;
            $isToday = $n['notify_date'] <= date('Y-m-d');
          ?>
            <tr>
              <td>
                <span class="fw-medium" style="font-size: 0.875rem;"><?= e($n['notify_date']) ?></span>
              </td>
              <td><?= e($n['client_name']) ?></td>
              <td class="text-end">
                <?php if ($isToday): ?>
                  <span class="badge bg-danger">HOY</span>
                <?php else: ?>
                  <span class="badge" style="background: #fef3c7; color: #92400e;"><?= $daysLeft ?>d</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Row 4: Stats + Activity log -->
<div class="row g-4 mb-5">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-graph-up-arrow" style="color: var(--success);"></i>
        <span>Métricas clave</span>
      </div>
      <div class="card-body">
        <div class="d-flex flex-column gap-4">
          <div>
            <div class="d-flex justify-content-between mb-1">
              <small style="color: var(--on-surface-variant); font-size: 0.8rem;">Tasa de completado</small>
              <strong style="font-size: 0.875rem;"><?= $completionRate ?>%</strong>
            </div>
            <div class="progress"><div class="progress-bar" style="width: <?= $completionRate ?>%; background: var(--success);"></div></div>
          </div>
          
          <div>
            <div class="d-flex justify-content-between mb-1">
              <small style="color: var(--on-surface-variant); font-size: 0.8rem;">Contratos con PDF</small>
              <strong style="font-size: 0.875rem;"><?= $pdfRate ?>%</strong>
            </div>
            <div class="progress"><div class="progress-bar" style="width: <?= $pdfRate ?>%; background: var(--primary);"></div></div>
          </div>
          
          <hr style="margin: 0.5rem 0; border-color: var(--outline-variant);">
          
          <div class="d-flex justify-content-between">
            <small style="color: var(--on-surface-variant);">Notificaciones completadas</small>
            <strong><?= (int)$metrics['completed_notifs'] ?></strong>
          </div>
          <div class="d-flex justify-content-between">
            <small style="color: var(--on-surface-variant);">Contratos (últimos 30d)</small>
            <strong><?= (int)$metrics['recent_30d'] ?></strong>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header">
        <i class="bi bi-clock-history" style="color: var(--primary);"></i>
        <span>Actividad reciente</span>
      </div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead>
            <tr>
              <th style="width: 150px;">Fecha</th>
              <th style="width: 100px;">Entidad</th>
              <th style="width: 100px;">Acción</th>
              <th>Cliente</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$recentActivity): ?>
            <tr><td colspan="4"><div class="empty-state"><i class="bi bi-journal"></i><p>Sin actividad registrada</p></div></td></tr>
          <?php else:
            $entityLabels = ['contract' => 'Contrato', 'notification' => 'Notificación', 'client' => 'Cliente'];
            foreach ($recentActivity as $log):
            $actionColors = [
              'created'   => ['bg' => '#d1fae5', 'color' => '#065f46'],
              'updated'   => ['bg' => '#dbeafe', 'color' => '#1e40af'],
              'deleted'   => ['bg' => '#fee2e2', 'color' => '#991b1b'],
              'completed' => ['bg' => '#e0e7ff', 'color' => '#3730a3'],
            ];
            $c = $actionColors[$log['action']] ?? ['bg' => '#f3f4f6', 'color' => '#374151'];
          ?>
            <tr>
              <td style="font-size: 0.8125rem; color: var(--on-surface-variant);">
                <?= e($log['created_at']) ?>
              </td>
              <td>
                <span style="font-size: 0.8rem;"><?= e($entityLabels[$log['entity']] ?? ucfirst($log['entity'])) ?></span>
              </td>
              <td>
                <span class="badge" style="background: <?= $c['bg'] ?>; color: <?= $c['color'] ?>; font-size: 0.7rem;">
                  <?= e($log['action']) ?>
                </span>
              </td>
              <td style="font-size: 0.875rem;">
                <?php if (!empty($log['client_name'])): ?>
                  <span class="fw-medium"><?= e($log['client_name']) ?></span>
                  <small style="color: var(--on-surface-variant); font-size: 0.75rem;"> · #<?= (int)$log['entity_id'] ?></small>
                <?php else: ?>
                  <span style="color: var(--on-surface-variant);">
                    <i class="bi bi-dash"></i> #<?= (int)$log['entity_id'] ?>
                    <?php if ($log['action'] === 'deleted'): ?>
                      <small style="font-size: 0.7rem;">(eliminado)</small>
                    <?php endif; ?>
                  </span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
