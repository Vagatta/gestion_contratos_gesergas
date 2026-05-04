<?php

class SecurityLogger {
    private static $logFile = null;
    
    /**
     * Inicializa el archivo de log
     */
    private static function getLogFile() {
        if (self::$logFile === null) {
            $logDir = __DIR__ . '/../logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            self::$logFile = $logDir . '/security.log';
        }
        return self::$logFile;
    }
    
    /**
     * Registra un evento de seguridad
     */
    public static function logSecurityEvent($event, $level = 'WARNING', $details = []) {
        $timestamp = date('Y-m-d H:i:s');
        $ip = self::getClientIP();
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $requestUri = $_SERVER['REQUEST_URI'] ?? 'Unknown';
        
        $logEntry = [
            'timestamp' => $timestamp,
            'level' => $level,
            'event' => $event,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'request_uri' => $requestUri,
            'details' => $details
        ];
        
        $logLine = json_encode($logEntry) . PHP_EOL;
        file_put_contents(self::getLogFile(), $logLine, FILE_APPEND | LOCK_EX);
        
        // Si es un evento crítico, también enviar email al admin
        if ($level === 'CRITICAL') {
            self::sendAlert($event, $details);
        }
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
     * Regresa intento de CSRF fallido
     */
    public static function logCSRFAttempt() {
        self::logSecurityEvent('CSRF_TOKEN_INVALID', 'CRITICAL', [
            'post_data' => array_keys($_POST),
            'referer' => $_SERVER['HTTP_REFERER'] ?? 'None'
        ]);
    }
    
    /**
     * Regresa rate limiting excedido
     */
    public static function logRateLimitExceeded($action, $limit, $window) {
        self::logSecurityEvent('RATE_LIMIT_EXCEEDED', 'WARNING', [
            'action' => $action,
            'limit' => $limit,
            'window' => $window,
            'session_id' => session_id()
        ]);
    }
    
    /**
     * Regresa validación de archivo fallida
     */
    public static function logInvalidFile($fileName, $fileSize, $mimeType, $error) {
        self::logSecurityEvent('INVALID_FILE_UPLOAD', 'WARNING', [
            'filename' => $fileName,
            'size' => $fileSize,
            'mime_type' => $mimeType,
            'error' => $error
        ]);
    }
    
    /**
     * Regresa intento de inyección SQL
     */
    public static function logSQLInjectionAttempt($input, $field) {
        self::logSecurityEvent('SQL_INJECTION_ATTEMPT', 'CRITICAL', [
            'input' => substr($input, 0, 100),
            'field' => $field,
            'pattern_matched' => 'SQL injection patterns'
        ]);
    }
    
    /**
     * Regresa intento de XSS
     */
    public static function logXSSAttempt($input, $field) {
        self::logSecurityEvent('XSS_ATTEMPT', 'CRITICAL', [
            'input' => substr($input, 0, 100),
            'field' => $field,
            'pattern_matched' => 'XSS patterns'
        ]);
    }
    
    /**
     * Regresa múltiples intentos de login fallidos
     */
    public static function logMultipleFailedAttempts($type, $count, $timeframe) {
        self::logSecurityEvent('MULTIPLE_FAILED_ATTEMPTS', 'WARNING', [
            'type' => $type,
            'count' => $count,
            'timeframe' => $timeframe,
            'ip' => self::getClientIP()
        ]);
    }
    
    /**
     * Regresa acceso a área restringida
     */
    public static function logUnauthorizedAccess($resource) {
        self::logSecurityEvent('UNAUTHORIZED_ACCESS', 'WARNING', [
            'resource' => $resource,
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown'
        ]);
    }
    
    /**
     * Regresa comportamiento sospechoso
     */
    public static function logSuspiciousActivity($description, $details = []) {
        self::logSecurityEvent('SUSPICIOUS_ACTIVITY', 'WARNING', array_merge([
            'description' => $description
        ], $details));
    }
    
    /**
     * Detecta patrones de inyección SQL
     */
    public static function detectSQLInjection($input) {
        $patterns = [
            '/(\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|UNION|SCRIPT)\b)/i',
            '/(\b(OR|AND)\s+\d+\s*=\s*\d+)/i',
            '/(\b(OR|AND)\s+["\']?\w+["\']?\s*=\s*["\']?\w+["\']?)/i',
            '/(;\s*(DROP|DELETE|UPDATE|INSERT)\b)/i',
            '/(\bUNION\s+SELECT\b)/i',
            '/(\/\*.*\*\/)/',
            '/(--.*$)/m'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detecta patrones de XSS
     */
    public static function detectXSS($input) {
        $patterns = [
            '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i',
            '/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>/i',
            '/javascript:/i',
            '/on\w+\s*=/i',
            '/<img[^>]*src[^>]*javascript:/i',
            '/<\?php/i',
            '/<%\s*=/i',
            '/eval\s*\(/i',
            '/expression\s*\(/i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Analiza inputs en busca de patrones maliciosos
     */
    public static function analyzeInputs($inputs) {
        foreach ($inputs as $field => $value) {
            if (is_string($value) && strlen($value) > 0) {
                if (self::detectSQLInjection($value)) {
                    self::logSQLInjectionAttempt($value, $field);
                    return true;
                }
                
                if (self::detectXSS($value)) {
                    self::logXSSAttempt($value, $field);
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Envía alerta por email para eventos críticos
     */
    private static function sendAlert($event, $details) {
        $to = getenv('ADMIN_EMAIL') ?: 'admin@example.com';
        $subject = '🚨 Alerta de Seguridad - ' . $event;
        
        $message = "Se ha detectado un evento de seguridad crítico:\n\n";
        $message .= "Evento: $event\n";
        $message .= "IP: " . self::getClientIP() . "\n";
        $message .= "Fecha: " . date('Y-m-d H:i:s') . "\n";
        $message .= "URL: " . ($_SERVER['REQUEST_URI'] ?? 'Unknown') . "\n\n";
        
        if (!empty($details)) {
            $message .= "Detalles:\n";
            foreach ($details as $key => $value) {
                $message .= "- $key: $value\n";
            }
        }
        
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $headers = [
            'From: noreply@' . $host,
            'Content-Type: text/plain; charset=UTF-8'
        ];
        
        @mail($to, $subject, $message, implode("\r\n", $headers));
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
            $logEntry = json_decode($line, true);
            if ($logEntry) {
                $logTime = strtotime($logEntry['timestamp']);
                if ($logTime > $cutoffDate) {
                    fwrite($tempHandle, $line);
                }
            }
        }
        
        fclose($handle);
        fclose($tempHandle);
        
        rename($tempFile, $logFile);
    }
    
    /**
     * Obtiene estadísticas de seguridad
     */
    public static function getSecurityStats($hours = 24) {
        $logFile = self::getLogFile();
        if (!file_exists($logFile)) {
            return [];
        }
        
        $cutoffTime = time() - ($hours * 60 * 60);
        $stats = [
            'total_events' => 0,
            'critical_events' => 0,
            'warnings' => 0,
            'events_by_type' => [],
            'top_ips' => []
        ];
        
        $handle = fopen($logFile, 'r');
        while (($line = fgets($handle)) !== false) {
            $logEntry = json_decode($line, true);
            if ($logEntry && strtotime($logEntry['timestamp']) > $cutoffTime) {
                $stats['total_events']++;
                
                if ($logEntry['level'] === 'CRITICAL') {
                    $stats['critical_events']++;
                } elseif ($logEntry['level'] === 'WARNING') {
                    $stats['warnings']++;
                }
                
                $event = $logEntry['event'];
                $stats['events_by_type'][$event] = ($stats['events_by_type'][$event] ?? 0) + 1;
                
                $ip = $logEntry['ip'];
                $stats['top_ips'][$ip] = ($stats['top_ips'][$ip] ?? 0) + 1;
            }
        }
        fclose($handle);
        
        // Ordenar resultados
        arsort($stats['events_by_type']);
        arsort($stats['top_ips']);
        
        return $stats;
    }
}
