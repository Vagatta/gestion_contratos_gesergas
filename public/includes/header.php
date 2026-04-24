<?php
require_once __DIR__ . '/../../config/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$current = basename($_SERVER['PHP_SELF']);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle ?? 'Gestión de contratos') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/app.css" rel="stylesheet">
</head>
<body class="bg-surface">

<!-- Navigation -->
<nav class="navbar navbar-expand-lg sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand" href="index.php">
      <i class="bi bi-file-earmark-text-fill"></i>
      <span>Gestión de Contratos</span>
    </a>
    
    <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-label="Toggle navigation">
      <i class="bi bi-list fs-4"></i>
    </button>
    
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav mx-auto">
        <li class="nav-item">
          <a class="nav-link <?= $current==='index.php'?'active':'' ?>" href="index.php">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $current==='contracts.php'?'active':'' ?>" href="contracts.php">
            <i class="bi bi-folder-fill"></i>
            <span>Contratos</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $current==='notifications.php'?'active':'' ?>" href="notifications.php">
            <i class="bi bi-bell-fill"></i>
            <span>Notificaciones</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $current==='calendar.php'?'active':'' ?>" href="calendar.php">
            <i class="bi bi-calendar3"></i>
            <span>Calendario</span>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $current==='admin.php'?'active':'' ?>" href="admin.php">
            <i class="bi bi-shield-lock-fill"></i>
            <span>Admin</span>
          </a>
        </li>
      </ul>
      
      <div class="d-flex align-items-center gap-2">
        <a href="contract_form.php" class="btn btn-primary">
          <i class="bi bi-plus-lg"></i>
          <span class="d-none d-sm-inline">Nuevo contrato</span>
        </a>
      </div>
    </div>
  </div>
</nav>

<main class="container-fluid">
<?php foreach (flash_pop() as $f): ?>
  <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show">
    <?= e($f['msg']) ?>
    <button class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endforeach; ?>
