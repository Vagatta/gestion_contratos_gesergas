<?php

require_once __DIR__ . '/SecurityLogger.php';
require_once __DIR__ . '/IPBlocker.php';

class Security {
    private static $csrfToken = null;
    
    /**
     * Genera o recupera el token CSRF
     */
    public static function getCsrfToken() {
        if (self::$csrfToken === null) {
            if (isset($_SESSION['csrf_token'])) {
                self::$csrfToken = $_SESSION['csrf_token'];
                error_log('CSRF DEBUG: Token retrieved from session: ' . substr(self::$csrfToken, 0, 8) . '...');
            } else {
                self::$csrfToken = bin2hex(random_bytes(32));
                $_SESSION['csrf_token'] = self::$csrfToken;
                error_log('CSRF DEBUG: New token generated: ' . substr(self::$csrfToken, 0, 8) . '...');
            }
        }
        return self::$csrfToken;
    }
    
    /**
     * Valida el token CSRF
     */
    public static function validateCsrfToken($token) {
        $sessionToken = $_SESSION['csrf_token'] ?? 'NOT_SET';
        error_log('CSRF DEBUG: Validating - Session: ' . substr($sessionToken, 0, 8) . '..., Post: ' . substr($token, 0, 8) . '...');
        
        if (!isset($_SESSION['csrf_token'])) {
            SecurityLogger::logCSRFAttempt();
            IPBlocker::recordFailedAttempt('csrf_fail');
            error_log('CSRF DEBUG: Validation FAILED - no session token');
            return false;
        }
        
        $isValid = hash_equals($_SESSION['csrf_token'], $token);
        if (!$isValid) {
            SecurityLogger::logCSRFAttempt();
            IPBlocker::recordFailedAttempt('csrf_fail');
            error_log('CSRF DEBUG: Validation FAILED - tokens mismatch');
        } else {
            error_log('CSRF DEBUG: Validation SUCCESS');
        }
        
        return $isValid;
    }
    
    /**
     * Genera el campo HTML para CSRF
     */
    public static function csrfField() {
        $token = self::getCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
    
    /**
     * Rate limiting simple por sesión
     */
    public static function checkRateLimit($action = 'default', $limit = 5, $window = 60) {
        $key = 'rate_limit_' . $action;
        $now = time();
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }
        
        // Limpiar entradas antiguas
        $_SESSION[$key] = array_filter($_SESSION[$key], function($timestamp) use ($now, $window) {
            return $now - $timestamp < $window;
        });
        
        // Verificar límite
        if (count($_SESSION[$key]) >= $limit) {
            SecurityLogger::logRateLimitExceeded($action, $limit, $window);
            IPBlocker::recordFailedAttempt('rate_limit');
            return false;
        }
        
        // Registrar esta solicitud
        $_SESSION[$key][] = $now;
        return true;
    }
    
    /**
     * Sanitiza input de texto
     */
    public static function sanitizeInput($input, $type = 'string') {
        if ($input === null) {
            return null;
        }
        
        // Analizar inputs en busca de patrones maliciosos
        if (is_string($input) && strlen($input) > 0) {
            SecurityLogger::analyzeInputs(['sanitized_input' => $input]);
        }
        
        switch ($type) {
            case 'email':
                return filter_var(trim($input), FILTER_SANITIZE_EMAIL);
            case 'int':
                return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
            case 'float':
                return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            case 'url':
                return filter_var(trim($input), FILTER_SANITIZE_URL);
            case 'string':
            default:
                return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
        }
    }
    
    /**
     * Valida formato de email
     */
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Valida teléfono español
     */
    public static function validatePhone($phone) {
        // Eliminar espacios y caracteres no numéricos excepto +
        $clean = preg_replace('/[^\d+]/', '', $phone);
        
        // Validar formato español: 9 dígitos o +34 seguido de 9 dígitos
        return (preg_match('/^(\+34)?[6-9]\d{8}$/', $clean) === 1);
    }
    
    /**
     * Valida fecha
     */
    public static function validateDate($date, $format = 'Y-m-d') {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
    
    /**
     * Valida nombre (solo letras, espacios, y caracteres comunes)
     */
    public static function validateName($name) {
        return preg_match('/^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s\'\-\.]{2,100}$/', $name) === 1;
    }
    
    /**
     * Valida dirección
     */
    public static function validateAddress($address) {
        return preg_match('/^[a-zA-Z0-9áéíóúÁÉÍÓÚñÑüÜ\s\'\-\.,#ºª\/]{5,200}$/', $address) === 1;
    }
    
    /**
     * Valida longitud de string
     */
    public static function validateLength($string, $min = 1, $max = 255) {
        $length = mb_strlen($string, 'UTF-8');
        $isValid = $length >= $min && $length <= $max;
        
        if (!$isValid) {
            SecurityLogger::logSecurityEvent('INVALID_INPUT_LENGTH', 'WARNING', [
                'length' => $length,
                'min' => $min,
                'max' => $max,
                'input_preview' => substr($string, 0, 50)
            ]);
        }
        
        return $isValid;
    }
    
    /**
     * Valida y sanitiza input con longitud específica
     */
    public static function validateAndSanitizeInput($input, $type = 'string', $minLength = 1, $maxLength = 255) {
        if ($input === null) {
            return ['valid' => false, 'value' => null, 'error' => 'Input es nulo'];
        }
        
        // Sanitizar primero
        $sanitized = self::sanitizeInput($input, $type);
        
        // Validar longitud
        if (!self::validateLength($sanitized, $minLength, $maxLength)) {
            return [
                'valid' => false, 
                'value' => $sanitized, 
                'error' => "Longitud inválida. Mínimo: $minLength, Máximo: $maxLength caracteres"
            ];
        }
        
        // Validaciones específicas por tipo
        switch ($type) {
            case 'email':
                if (!self::validateEmail($sanitized)) {
                    return ['valid' => false, 'value' => $sanitized, 'error' => 'Email no válido'];
                }
                break;
            case 'phone':
                if (!self::validatePhone($sanitized)) {
                    return ['valid' => false, 'value' => $sanitized, 'error' => 'Teléfono no válido'];
                }
                break;
            case 'name':
                if (!self::validateName($sanitized)) {
                    return ['valid' => false, 'value' => $sanitized, 'error' => 'Nombre no válido'];
                }
                break;
            case 'address':
                if (!self::validateAddress($sanitized)) {
                    return ['valid' => false, 'value' => $sanitized, 'error' => 'Dirección no válida'];
                }
                break;
            case 'date':
                if (!self::validateDate($sanitized)) {
                    return ['valid' => false, 'value' => $sanitized, 'error' => 'Fecha no válida'];
                }
                break;
        }
        
        return ['valid' => true, 'value' => $sanitized, 'error' => null];
    }
    
    /**
     * Genera nonce para prevenir replay attacks
     */
    public static function generateNonce($action = 'default') {
        $nonce = bin2hex(random_bytes(16));
        $_SESSION['nonce_' . $action] = [
            'value' => $nonce,
            'expires' => time() + 300 // 5 minutos
        ];
        return $nonce;
    }
    
    /**
     * Valida nonce
     */
    public static function validateNonce($nonce, $action = 'default') {
        $key = 'nonce_' . $action;
        if (!isset($_SESSION[$key])) {
            return false;
        }
        
        $data = $_SESSION[$key];
        unset($_SESSION[$key]); // Usar solo una vez
        
        return time() < $data['expires'] && hash_equals($data['value'], $nonce);
    }
    
    /**
     * Headers de seguridad HTTP
     */
    public static function setSecurityHeaders() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net https://code.jquery.com; style-src \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net https://fonts.googleapis.com; style-src-elem \'self\' \'unsafe-inline\' https://cdn.jsdelivr.net https://fonts.googleapis.com; img-src \'self\' data:; font-src \'self\' https://cdn.jsdelivr.net https://fonts.gstatic.com; connect-src \'self\' https://cdn.jsdelivr.net');
        
        // Verificar si la IP está bloqueada
        IPBlocker::handleBlockedAccess();
    }
    
    /**
     * Validar uploaded file
     */
    public static function validateFile($file, $allowedTypes = ['application/pdf'], $maxSize = 5242880) {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            SecurityLogger::logInvalidFile($file['name'] ?? 'Unknown', 0, 'Unknown', 'Invalid upload');
            IPBlocker::recordFailedAttempt('invalid_file');
            return ['valid' => false, 'error' => 'Archivo no válido'];
        }
        
        if ($file['size'] > $maxSize) {
            SecurityLogger::logInvalidFile($file['name'], $file['size'], 'Unknown', 'File too large');
            IPBlocker::recordFailedAttempt('invalid_file');
            return ['valid' => false, 'error' => 'Archivo demasiado grande'];
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            SecurityLogger::logInvalidFile($file['name'], $file['size'], $mimeType, 'MIME type not allowed');
            IPBlocker::recordFailedAttempt('invalid_file');
            return ['valid' => false, 'error' => 'Tipo de archivo no permitido'];
        }
        
        // Verificar contenido real del archivo para detectar archivos maliciosos
        $fileContent = file_get_contents($file['tmp_name'], false, null, 0, 1024);
        if ($fileContent === false) {
            SecurityLogger::logInvalidFile($file['name'], $file['size'], $mimeType, 'Cannot read file content');
            return ['valid' => false, 'error' => 'No se puede leer el archivo'];
        }
        
        // Detectar scripts o contenido sospechoso en archivos PDF
        $suspiciousPatterns = [
            '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/i',
            '/<\?php/i',
            '/<%\s*=/i',
            '/javascript:/i',
            '/eval\s*\(/i'
        ];
        
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $fileContent)) {
                SecurityLogger::logSecurityEvent('SUSPICIOUS_FILE_CONTENT', 'CRITICAL', [
                    'filename' => $file['name'],
                    'size' => $file['size'],
                    'mime_type' => $mimeType,
                    'pattern_detected' => $pattern
                ]);
                return ['valid' => false, 'error' => 'Contenido sospechoso detectado en el archivo'];
            }
        }
        
        return ['valid' => true];
    }
}
