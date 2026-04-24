<?php
// Configuración BD y helpers básicos.
// Cambia credenciales según tu entorno (ideal: variables de entorno).

declare(strict_types=1);

define('DB_HOST',    $_ENV['DB_HOST']    ?? '127.0.0.1');
define('DB_NAME',    $_ENV['DB_NAME']    ?? 'contratos_db');
define('DB_USER',    $_ENV['DB_USER']    ?? 'root');
define('DB_PASS',    $_ENV['DB_PASS']    ?? '');
define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4');

// Duración por defecto del contrato en meses (renovación anual por defecto).
const CONTRACT_DURATION_MONTHS = 12;

// Offset legacy (1 mes antes del vencimiento anual).
const NOTIFY_OFFSET_MONTHS = 11;

// Ruta física para almacenar PDFs subidos (relativa a la raíz del repo).
define('UPLOAD_DIR', ($_ENV['UPLOAD_DIR'] ?? null) ?: (dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'contracts'));
// Ruta web relativa para enlazar archivos desde el navegador.
define('UPLOAD_WEB_PATH', $_ENV['UPLOAD_WEB_PATH'] ?? '../uploads/contracts/');

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function flash_set(string $type, string $msg): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flash_pop(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $out = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $out;
}

function log_action(string $entity, int $entityId, string $action, ?string $details = null): void {
    db()->prepare(
        'INSERT INTO action_log (entity, entity_id, action, details) VALUES (?, ?, ?, ?)'
    )->execute([$entity, $entityId, $action, $details]);
}

/**
 * Calcula la fecha de notificación a partir de la fecha de contrato.
 * Ej: 2026-03-01 + 11 meses = 2027-02-01.
 */
function compute_notify_date(string $contractDate): string {
    $d = new DateTimeImmutable($contractDate);
    return $d->modify('+' . NOTIFY_OFFSET_MONTHS . ' months')->format('Y-m-d');
}

/**
 * Catálogo de intervalos disponibles para las notificaciones automáticas.
 * @return array<string,array{offset:string,label:string}>
 */
function notification_intervals_catalog(): array {
    return [
        '3_months'  => ['offset' => '-3 months', 'label' => '3 meses antes del vencimiento'],
        '1_month'   => ['offset' => '-1 month',  'label' => '1 mes antes del vencimiento'],
        '15_days'   => ['offset' => '-15 days',  'label' => '15 días antes del vencimiento'],
        'due_day'   => ['offset' => '+0 days',   'label' => 'Día del vencimiento'],
    ];
}

/**
 * Lee un setting de la BD. Devuelve el default si no existe.
 */
function setting_get(string $key, $default = null) {
    try {
        $st = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');
        $st->execute([$key]);
        $val = $st->fetchColumn();
        return $val !== false ? $val : $default;
    } catch (\Throwable $e) {
        return $default;
    }
}

function setting_set(string $key, string $value): void {
    db()->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, $value]);
}

/**
 * Devuelve las claves de intervalos configurados por el admin.
 * Default: solo 1 mes antes.
 * @return string[]
 */
function get_configured_intervals(): array {
    $raw = setting_get('notification_schedule', '["1_month"]');
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) return ['1_month'];
    $catalog = notification_intervals_catalog();
    return array_values(array_filter($decoded, fn($k) => isset($catalog[$k])));
}

/**
 * Devuelve las fechas programadas de notificación para un contrato.
 * Asume vencimiento = contract_date + 12 meses. Los intervalos concretos
 * vienen de la configuración (app_settings.notification_schedule).
 *
 * @return array<array{offset:string,date:string,label:string}>
 */
function compute_notification_schedule(string $contractDate): array {
    $start = new DateTimeImmutable($contractDate);
    $end   = $start->modify('+' . CONTRACT_DURATION_MONTHS . ' months');
    $catalog = notification_intervals_catalog();
    $keys = get_configured_intervals();
    if (!$keys) $keys = ['1_month']; // fallback
    
    $result = [];
    foreach ($keys as $key) {
        $item = $catalog[$key];
        $result[] = [
            'offset' => $item['offset'],
            'date'   => $end->modify($item['offset'])->format('Y-m-d'),
            'label'  => $item['label'],
        ];
    }
    return $result;
}
