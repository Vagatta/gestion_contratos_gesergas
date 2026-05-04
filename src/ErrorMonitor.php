<?php

require_once __DIR__ . '/SecurityLogger.php';

class ErrorMonitor {
    private static $logFile = null;
    private static $errorTypes = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_PARSE => 'PARSE',
        E_NOTICE => 'NOTICE',
        E_CORE_ERROR => 'CORE_ERROR',
        E_CORE_WARNING => 'CORE_WARNING',
        E_COMPILE_ERROR => 'COMPILE_ERROR',
        E_COMPILE_WARNING => 'COMPILE_WARNING',
        E_USER_ERROR => 'USER_ERROR',
        E_USER_WARNING => 'USER_WARNING',
        E_USER_NOTICE => 'USER_NOTICE',
        E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
        E_DEPRECATED => 'DEPRECATED',
        E_USER_DEPRECATED => 'USER_DEPRECATED'
        // E_STRICT removido: deprecado desde PHP 8.4
    ];
    
    /**
     * Inicializa el archivo de log de errores
     */
    private static function getLogFile() {
        if (self::$logFile === null) {
            $logDir = __DIR__ . '/../logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            self::$logFile = $logDir . '/errors.log';
        }
        return self::$logFile;
    }
    
    /**
     * Configura el manejador de errores
     */
    public static function init() {
        // Manejador de errores
        set_error_handler([self::class, 'handleError']);
        
        // Manejador de excepciones
        set_exception_handler([self::class, 'handleException']);
        
        // Manejador de errores fatales
        register_shutdown_function([self::class, 'handleShutdown']);
    }
    
    /**
     * Maneja errores de PHP
     */
    public static function handleError($errno, $errstr, $errfile = '', $errline = 0) {
        // No reportar errores suprimidos con @
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        $errorType = self::$errorTypes[$errno] ?? 'UNKNOWN';
        $severity = self::getErrorSeverity($errno);
        
        self::logError([
            'type' => 'PHP_ERROR',
            'severity' => $severity,
            'error_type' => $errorType,
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
            'context' => self::getContext(),
            'stack_trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
        ]);
        
        // No ejecutar el manejador interno de PHP
        return true;
    }
    
    /**
     * Maneja excepciones no capturadas
     */
    public static function handleException($exception) {
        self::logError([
            'type' => 'UNCAUGHT_EXCEPTION',
            'severity' => 'CRITICAL',
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
            'context' => self::getContext(),
            'stack_trace' => $exception->getTraceAsString()
        ]);
        
        // Mostrar página de error amigable en producción
        if (!self::isDebugMode()) {
            self::showErrorPage();
        }
    }
    
    /**
     * Maneja errores fatales
     */
    public static function handleShutdown() {
        $error = error_get_last();
        
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
            self::logError([
                'type' => 'FATAL_ERROR',
                'severity' => 'CRITICAL',
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
                'context' => self::getContext()
            ]);
            
            // Mostrar página de error amigable en producción
            if (!self::isDebugMode()) {
                self::showErrorPage();
            }
        }
    }
    
    /**
     * Registra un error personalizado
     */
    public static function logCustomError($message, $severity = 'WARNING', $context = []) {
        self::logError([
            'type' => 'CUSTOM_ERROR',
            'severity' => $severity,
            'message' => $message,
            'file' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[0]['file'] ?? '',
            'line' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[0]['line'] ?? 0,
            'context' => array_merge(self::getContext(), $context),
            'stack_trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
        ]);
    }
    
    /**
     * Registra un error de base de datos
     */
    public static function logDatabaseError($query, $error, $params = []) {
        self::logError([
            'type' => 'DATABASE_ERROR',
            'severity' => 'CRITICAL',
            'message' => $error,
            'query' => $query,
            'params' => $params,
            'file' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[0]['file'] ?? '',
            'line' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[0]['line'] ?? 0,
            'context' => self::getContext()
        ]);
    }
    
    /**
     * Registra un error de seguridad
     */
    public static function logSecurityError($message, $details = []) {
        self::logError([
            'type' => 'SECURITY_ERROR',
            'severity' => 'CRITICAL',
            'message' => $message,
            'details' => $details,
            'file' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[0]['file'] ?? '',
            'line' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[0]['line'] ?? 0,
            'context' => self::getContext()
        ]);
    }
    
    /**
     * Escribe el error en el log
     */
    private static function logError($errorData) {
        $timestamp = date('Y-m-d H:i:s');
        $errorData['timestamp'] = $timestamp;
        
        $logEntry = json_encode($errorData) . PHP_EOL;
        file_put_contents(self::getLogFile(), $logEntry, FILE_APPEND | LOCK_EX);
        
        // Si es crítico, enviar alerta
        if ($errorData['severity'] === 'CRITICAL') {
            self::sendAlert($errorData);
        }
        
        // También registrar en SecurityLogger si es un error de seguridad
        if ($errorData['type'] === 'SECURITY_ERROR') {
            SecurityLogger::logSecurityEvent('APPLICATION_ERROR', 'CRITICAL', $errorData);
        }
    }
    
    /**
     * Obtiene el contexto de la solicitud
     */
    private static function getContext() {
        return [
            'ip' => self::getClientIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
            'post_data' => array_keys($_POST),
            'get_data' => array_keys($_GET),
            'session_id' => session_id() ?? 'None',
            'timestamp' => time()
        ];
    }
    
    /**
     * Obtiene la IP real del cliente
     */
    private static function getClientIP() {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    }
    
    /**
     * Determina la severidad del error
     */
    private static function getErrorSeverity($errno) {
        switch ($errno) {
            case E_ERROR:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
            case E_RECOVERABLE_ERROR:
                return 'CRITICAL';
            case E_WARNING:
            case E_CORE_WARNING:
            case E_COMPILE_WARNING:
            case E_USER_WARNING:
                return 'WARNING';
            case E_NOTICE:
            case E_USER_NOTICE:
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                return 'INFO';
            default:
                return 'WARNING';
        }
    }
    
    /**
     * Verifica si está en modo debug
     */
    private static function isDebugMode() {
        return (getenv('APP_DEBUG') === 'true') || (defined('APP_DEBUG') && APP_DEBUG);
    }
    
    /**
     * Muestra página de error amigable
     */
    private static function showErrorPage() {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        
        echo '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error del Servidor</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f8f9fa; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 50px auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
        h1 { color: #dc3545; margin-bottom: 20px; }
        .icon { font-size: 4rem; color: #dc3545; margin-bottom: 20px; }
        p { color: #6c757d; line-height: 1.6; }
        .btn { display: inline-block; background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; margin-top: 20px; }
        .btn:hover { background: #0056b3; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">⚠️</div>
        <h1>Error del Servidor</h1>
        <p>Ha ocurrido un error inesperado. Nuestro equipo técnico ha sido notificado y está trabajando para solucionarlo.</p>
        <p>Por favor, intente nuevamente en unos minutos.</p>
        <a href="/" class="btn">Volver al Inicio</a>
    </div>
</body>
</html>';
        exit;
    }
    
    /**
     * Envía alerta por email para errores críticos
     */
    private static function sendAlert($errorData) {
        $to = getenv('ADMIN_EMAIL') ?: 'admin@example.com';
        $subject = '🚨 Error Crítico - ' . $errorData['type'];
        
        $message = "Se ha detectado un error crítico:\n\n";
        $message .= "Tipo: " . $errorData['type'] . "\n";
        $message .= "Severidad: " . $errorData['severity'] . "\n";
        $message .= "Mensaje: " . $errorData['message'] . "\n";
        $message .= "IP: " . ($errorData['context']['ip'] ?? 'Unknown') . "\n";
        $message .= "URL: " . ($errorData['context']['request_uri'] ?? 'Unknown') . "\n";
        $message .= "Fecha: " . $errorData['timestamp'] . "\n";
        
        if (isset($errorData['file'])) {
            $message .= "Archivo: " . $errorData['file'] . "\n";
            $message .= "Línea: " . ($errorData['line'] ?? 'Unknown') . "\n";
        }
        
        if (isset($errorData['query'])) {
            $message .= "Query: " . $errorData['query'] . "\n";
        }
        
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $headers = [
            'From: noreply@' . $host,
            'Content-Type: text/plain; charset=UTF-8'
        ];
        
        @mail($to, $subject, $message, implode("\r\n", $headers));
    }
    
    /**
     * Obtiene estadísticas de errores
     */
    public static function getErrorStats($hours = 24) {
        $logFile = self::getLogFile();
        if (!file_exists($logFile)) {
            return [];
        }
        
        $cutoffTime = time() - ($hours * 60 * 60);
        $stats = [
            'total_errors' => 0,
            'critical_errors' => 0,
            'warnings' => 0,
            'errors_by_type' => [],
            'errors_by_file' => [],
            'top_error_messages' => []
        ];
        
        $handle = fopen($logFile, 'r');
        while (($line = fgets($handle)) !== false) {
            $errorEntry = json_decode($line, true);
            if ($errorEntry && strtotime($errorEntry['timestamp']) > $cutoffTime) {
                $stats['total_errors']++;
                
                if ($errorEntry['severity'] === 'CRITICAL') {
                    $stats['critical_errors']++;
                } elseif ($errorEntry['severity'] === 'WARNING') {
                    $stats['warnings']++;
                }
                
                $type = $errorEntry['type'];
                $stats['errors_by_type'][$type] = ($stats['errors_by_type'][$type] ?? 0) + 1;
                
                if (isset($errorEntry['file'])) {
                    $file = basename($errorEntry['file']);
                    $stats['errors_by_file'][$file] = ($stats['errors_by_file'][$file] ?? 0) + 1;
                }
                
                $message = substr($errorEntry['message'], 0, 50);
                $stats['top_error_messages'][$message] = ($stats['top_error_messages'][$message] ?? 0) + 1;
            }
        }
        fclose($handle);
        
        // Ordenar resultados
        arsort($stats['errors_by_type']);
        arsort($stats['errors_by_file']);
        arsort($stats['top_error_messages']);
        
        return $stats;
    }
    
    /**
     * Limpia logs antiguos (mantiene últimos 30 días)
     */
    public static function cleanOldLogs($days = 30) {
        $logFile = self::getLogFile();
        if (!file_exists($logFile)) {
            return;
        }
        
        $cutoffDate = time() - ($days * 24 * 60 * 60);
        $tempFile = $logFile . '.tmp';
        
        $handle = fopen($logFile, 'r');
        $tempHandle = fopen($tempFile, 'w');
        
        while (($line = fgets($handle)) !== false) {
            $errorEntry = json_decode($line, true);
            if ($errorEntry) {
                $errorTime = strtotime($errorEntry['timestamp']);
                if ($errorTime > $cutoffDate) {
                    fwrite($tempHandle, $line);
                }
            }
        }
        
        fclose($handle);
        fclose($tempHandle);
        
        rename($tempFile, $logFile);
    }
}
