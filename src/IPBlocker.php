<?php

class IPBlocker {
    private static $blockFile = null;
    private static $attemptsFile = null;
    
    /**
     * Inicializa los archivos de bloqueo
     */
    private static function initFiles() {
        if (self::$blockFile === null) {
            $dataDir = __DIR__ . '/../data';
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0755, true);
            }
            self::$blockFile = $dataDir . '/blocked_ips.json';
            self::$attemptsFile = $dataDir . '/failed_attempts.json';
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
     * Registra un intento fallido
     */
    public static function recordFailedAttempt($type = 'general') {
        self::initFiles();
        
        $ip = self::getClientIP();
        $now = time();
        
        // Cargar intentos existentes
        $attempts = [];
        if (file_exists(self::$attemptsFile)) {
            $attempts = json_decode(file_get_contents(self::$attemptsFile), true) ?: [];
        }
        
        // Inicializar si no existe
        if (!isset($attempts[$ip])) {
            $attempts[$ip] = [];
        }
        
        // Agregar nuevo intento
        $attempts[$ip][] = [
            'type' => $type,
            'timestamp' => $now,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ];
        
        // Limpiar intentos antiguos (mantiene últimos 30 minutos)
        $cutoffTime = $now - 1800; // 30 minutos
        $attempts[$ip] = array_filter($attempts[$ip], function($attempt) use ($cutoffTime) {
            return $attempt['timestamp'] > $cutoffTime;
        });
        
        // Guardar intentos actualizados
        file_put_contents(self::$attemptsFile, json_encode($attempts), LOCK_EX);
        
        // Verificar si debe bloquear
        self::checkAndBlock($ip, $attempts[$ip]);
    }
    
    /**
     * Verifica si una IP está bloqueada
     */
    public static function isBlocked() {
        self::initFiles();
        
        $ip = self::getClientIP();
        
        if (!file_exists(self::$blockFile)) {
            return false;
        }
        
        $blocked = json_decode(file_get_contents(self::$blockFile), true) ?: [];
        
        if (!isset($blocked[$ip])) {
            return false;
        }
        
        // Verificar si el bloqueo ha expirado
        $now = time();
        if ($blocked[$ip]['expires'] < $now) {
            // Eliminar bloqueo expirado
            unset($blocked[$ip]);
            file_put_contents(self::$blockFile, json_encode($blocked), LOCK_EX);
            return false;
        }
        
        return true;
    }
    
    /**
     * Bloquea manualmente una IP
     */
    public static function blockIP($ip, $duration = 3600, $reason = 'Manual block') {
        self::initFiles();
        
        $blocked = [];
        if (file_exists(self::$blockFile)) {
            $blocked = json_decode(file_get_contents(self::$blockFile), true) ?: [];
        }
        
        $blocked[$ip] = [
            'blocked_at' => time(),
            'expires' => time() + $duration,
            'reason' => $reason,
            'blocked_by' => 'system'
        ];
        
        file_put_contents(self::$blockFile, json_encode($blocked), LOCK_EX);
        
        SecurityLogger::logSecurityEvent('IP_BLOCKED', 'WARNING', [
            'ip' => $ip,
            'duration' => $duration,
            'reason' => $reason
        ]);
    }
    
    /**
     * Desbloquea una IP
     */
    public static function unblockIP($ip) {
        self::initFiles();
        
        if (!file_exists(self::$blockFile)) {
            return;
        }
        
        $blocked = json_decode(file_get_contents(self::$blockFile), true) ?: [];
        
        if (isset($blocked[$ip])) {
            unset($blocked[$ip]);
            file_put_contents(self::$blockFile, json_encode($blocked), LOCK_EX);
            
            SecurityLogger::logSecurityEvent('IP_UNBLOCKED', 'INFO', [
                'ip' => $ip,
                'unblocked_by' => 'system'
            ]);
        }
    }
    
    /**
     * Verifica y bloquea según los intentos fallidos
     */
    private static function checkAndBlock($ip, $attempts) {
        $now = time();
        $thresholds = [
            'csrf_fail' => ['count' => 5, 'window' => 300, 'block_duration' => 1800],  // 5 en 5 min = 30 min block
            'rate_limit' => ['count' => 10, 'window' => 300, 'block_duration' => 1800], // 10 en 5 min = 30 min block
            'invalid_file' => ['count' => 3, 'window' => 600, 'block_duration' => 3600], // 3 en 10 min = 1 hour block
            'general' => ['count' => 20, 'window' => 600, 'block_duration' => 1800]   // 20 en 10 min = 30 min block
        ];
        
        // Agrupar intentos por tipo
        $attemptsByType = [];
        foreach ($attempts as $attempt) {
            $type = $attempt['type'];
            if (!isset($attemptsByType[$type])) {
                $attemptsByType[$type] = [];
            }
            $attemptsByType[$type][] = $attempt;
        }
        
        // Verificar cada tipo
        foreach ($attemptsByType as $type => $typeAttempts) {
            if (isset($thresholds[$type])) {
                $threshold = $thresholds[$type];
                $cutoffTime = $now - $threshold['window'];
                
                // Contar intentos en la ventana de tiempo
                $recentAttempts = array_filter($typeAttempts, function($attempt) use ($cutoffTime) {
                    return $attempt['timestamp'] > $cutoffTime;
                });
                
                if (count($recentAttempts) >= $threshold['count']) {
                    self::blockIP($ip, $threshold['block_duration'], 
                        "Too many failed attempts: $type ({$threshold['count']} in {$threshold['window']}s)");
                    return;
                }
            }
        }
    }
    
    /**
     * Obtiene lista de IPs bloqueadas
     */
    public static function getBlockedIPs() {
        self::initFiles();
        
        if (!file_exists(self::$blockFile)) {
            return [];
        }
        
        $blocked = json_decode(file_get_contents(self::$blockFile), true) ?: [];
        $now = time();
        
        // Filtrar bloqueos expirados y limpiar
        $activeBlocks = [];
        foreach ($blocked as $ip => $blockInfo) {
            if ($blockInfo['expires'] > $now) {
                $activeBlocks[$ip] = $blockInfo;
            }
        }
        
        // Actualizar archivo si se eliminaron bloqueos expirados
        if (count($activeBlocks) !== count($blocked)) {
            file_put_contents(self::$blockFile, json_encode($activeBlocks), LOCK_EX);
        }
        
        return $activeBlocks;
    }
    
    /**
     * Obtiene estadísticas de intentos fallidos
     */
    public static function getFailedAttemptsStats($hours = 24) {
        self::initFiles();
        
        if (!file_exists(self::$attemptsFile)) {
            return [];
        }
        
        $attempts = json_decode(file_get_contents(self::$attemptsFile), true) ?: [];
        $cutoffTime = time() - ($hours * 3600);
        
        $stats = [
            'total_attempts' => 0,
            'unique_ips' => 0,
            'attempts_by_type' => [],
            'top_offending_ips' => []
        ];
        
        $ipCounts = [];
        
        foreach ($attempts as $ip => $ipAttempts) {
            $recentAttempts = array_filter($ipAttempts, function($attempt) use ($cutoffTime) {
                return $attempt['timestamp'] > $cutoffTime;
            });
            
            if (!empty($recentAttempts)) {
                $stats['unique_ips']++;
                $stats['total_attempts'] += count($recentAttempts);
                $ipCounts[$ip] = count($recentAttempts);
                
                foreach ($recentAttempts as $attempt) {
                    $type = $attempt['type'];
                    $stats['attempts_by_type'][$type] = ($stats['attempts_by_type'][$type] ?? 0) + 1;
                }
            }
        }
        
        // Ordenar IPs por número de intentos
        arsort($ipCounts);
        $stats['top_offending_ips'] = array_slice($ipCounts, 0, 10, true);
        
        return $stats;
    }
    
    /**
     * Limpia datos antiguos
     */
    public static function cleanup() {
        self::initFiles();
        
        $now = time();
        
        // Limpiar intentos fallidos antiguos (más de 7 días)
        if (file_exists(self::$attemptsFile)) {
            $attempts = json_decode(file_get_contents(self::$attemptsFile), true) ?: [];
            $cutoffTime = $now - (7 * 24 * 3600); // 7 días
            
            foreach ($attempts as $ip => $ipAttempts) {
                $attempts[$ip] = array_filter($ipAttempts, function($attempt) use ($cutoffTime) {
                    return $attempt['timestamp'] > $cutoffTime;
                });
                
                if (empty($attempts[$ip])) {
                    unset($attempts[$ip]);
                }
            }
            
            file_put_contents(self::$attemptsFile, json_encode($attempts), LOCK_EX);
        }
        
        // Limpiar bloqueos expirados
        self::getBlockedIPs(); // Este método ya limpia los expirados
    }
    
    /**
     * Muestra página de bloqueo si la IP está bloqueada
     */
    public static function handleBlockedAccess() {
        if (self::isBlocked()) {
            $ip = self::getClientIP();
            $blocked = self::getBlockedIPs();
            $blockInfo = $blocked[$ip] ?? null;
            
            http_response_code(403);
            header('Content-Type: text/html; charset=UTF-8');
            
            echo '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Bloqueado</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f8f9fa; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 50px auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #dc3545; text-align: center; }
        .alert { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 4px; margin: 20px 0; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; border-radius: 4px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚫 Acceso Bloqueado</h1>
        <div class="alert">
            <strong>Su dirección IP ha sido bloqueada temporalmente.</strong><br>
            IP: ' . htmlspecialchars($ip) . '
        </div>';
            
            if ($blockInfo) {
                $remainingTime = $blockInfo['expires'] - time();
                $hours = floor($remainingTime / 3600);
                $minutes = floor(($remainingTime % 3600) / 60);
                
                echo '<div class="info">
                    <strong>Motivo:</strong> ' . htmlspecialchars($blockInfo['reason']) . '<br>
                    <strong>Tiempo restante:</strong> ';
                
                if ($hours > 0) {
                    echo $hours . ' hora' . ($hours > 1 ? 's' : '');
                }
                if ($minutes > 0) {
                    echo ($hours > 0 ? ' y ' : '') . $minutes . ' minuto' . ($minutes > 1 ? 's' : '');
                }
                
                echo '</div>';
            }
            
            echo '<p>Si cree que esto es un error, por favor contacte al administrador del sistema.</p>
        <p><a href="mailto:admin@example.com">Contactar Administrador</a></p>
    </div>
</body>
</html>';
            exit;
        }
    }
}
