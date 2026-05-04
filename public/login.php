<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/Security.php';
require_once __DIR__ . '/../src/ErrorMonitor.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
ErrorMonitor::init();

// Headers básicos de seguridad sin activar IPBlocker
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Si ya está autenticado, redirigir al dashboard
if (!empty($_SESSION['authenticated'])) {
    redirect('index.php');
}

// Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $validCsrf = !empty($_SESSION['csrf_token'])
        && !empty($submittedToken)
        && hash_equals($_SESSION['csrf_token'], $submittedToken);

    if (!$validCsrf) {
        $error = 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.';
    } elseif (!Security::checkRateLimit('login', 5, 60)) {
        $error = 'Demasiados intentos. Espera un minuto e inténtalo de nuevo.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $adminEmail    = $_ENV['ADMIN_EMAIL']    ?? '';
        $adminPassword = $_ENV['ADMIN_PASSWORD'] ?? '';

        if ($email === $adminEmail && $password === $adminPassword) {
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['user_email']    = $email;
            redirect('index.php');
        } else {
            $error = 'Credenciales incorrectas.';
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Acceso — Gestión de contratos</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="assets/app.css" rel="stylesheet">
  <style>
    body { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .login-card { width: 100%; max-width: 400px; }
  </style>
</head>
<body class="bg-surface">
  <div class="login-card p-4">
    <div class="text-center mb-4">
      <i class="bi bi-file-earmark-text-fill fs-1" style="color: var(--primary-container);"></i>
      <h1 class="mt-2" style="font-size: 1.25rem; font-weight: 700; color: var(--on-surface);">Gestión de contratos</h1>
      <p style="color: var(--on-surface-variant); font-size: 0.875rem;">Acceso restringido al equipo interno</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2 px-3" style="font-size: 0.875rem;">
        <i class="bi bi-exclamation-triangle-fill me-1"></i><?= e($error) ?>
      </div>
    <?php endif; ?>

    <form method="POST" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

      <div class="mb-3">
        <label for="email" class="form-label" style="font-size: 0.875rem; font-weight: 500;">Correo electrónico</label>
        <input
          type="email"
          id="email"
          name="email"
          class="form-control"
          autocomplete="email"
          required
          value="<?= e($_POST['email'] ?? '') ?>"
        >
      </div>

      <div class="mb-4">
        <label for="password" class="form-label" style="font-size: 0.875rem; font-weight: 500;">Contraseña</label>
        <input
          type="password"
          id="password"
          name="password"
          class="form-control"
          autocomplete="current-password"
          required
        >
      </div>

      <button type="submit" class="btn btn-primary w-100">
        <i class="bi bi-box-arrow-in-right me-1"></i> Entrar
      </button>
    </form>

    <p class="text-center mt-4" style="font-size: 0.75rem; color: var(--on-surface-variant);">
      <a href="https://gesergas-murex.vercel.app/" style="color: var(--on-surface-variant);">← Volver a gesergas.es</a>
    </p>
  </div>
</body>
</html>
