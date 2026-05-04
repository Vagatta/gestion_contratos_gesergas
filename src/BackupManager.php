<?php

class BackupManager {
    private static $backupDir = null;
    private static $maxBackups = 30; // Mantener 30 días de backups
    
    /**
     * Inicializa el directorio de backups
     */
    private static function initBackupDir() {
        if (self::$backupDir === null) {
            self::$backupDir = __DIR__ . '/../backups';
            if (!is_dir(self::$backupDir)) {
                mkdir(self::$backupDir, 0755, true);
            }
        }
        return self::$backupDir;
    }
    
    /**
     * Crea un backup completo de la base de datos
     */
    public static function createBackup($type = 'full') {
        self::initBackupDir();
        
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "backup_{$type}_{$timestamp}.sql";
        $backupPath = self::$backupDir . '/' . $filename;
        
        try {
            // Obtener configuración de la base de datos
            $dbConfig = self::getDBConfig();
            
            // Comando mysqldump
            $command = sprintf(
                'mysqldump --single-transaction --routines --triggers --events --opt --default-character-set=utf8mb4 -h%s -u%s -p%s %s > %s',
                escapeshellarg($dbConfig['host']),
                escapeshellarg($dbConfig['username']),
                escapeshellarg($dbConfig['password']),
                escapeshellarg($dbConfig['database']),
                escapeshellarg($backupPath)
            );
            
            // Ejecutar backup
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new Exception("Error ejecutando mysqldump (código: $returnCode)");
            }
            
            // Verificar que el archivo se creó y tiene contenido
            if (!file_exists($backupPath) || filesize($backupPath) === 0) {
                throw new Exception("El archivo de backup no se creó correctamente");
            }
            
            // Comprimir el backup
            $compressedPath = self::compressBackup($backupPath);
            
            // Eliminar archivo sin comprimir
            unlink($backupPath);
            
            // Registrar backup
            self::logBackup($filename . '.gz', filesize($compressedPath), $type);
            
            // Limpiar backups antiguos
            self::cleanOldBackups();
            
            return [
                'success' => true,
                'filename' => basename($compressedPath),
                'size' => self::formatBytes(filesize($compressedPath)),
                'path' => $compressedPath
            ];
            
        } catch (Exception $e) {
            SecurityLogger::logSecurityEvent('BACKUP_FAILED', 'CRITICAL', [
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Restaura un backup desde un archivo
     */
    public static function restoreBackup($filename) {
        self::initBackupDir();
        
        $backupPath = self::$backupDir . '/' . $filename;
        
        if (!file_exists($backupPath)) {
            throw new Exception("El archivo de backup no existe: $filename");
        }
        
        try {
            // Descomprimir si es necesario
            if (substr($filename, -3) === '.gz') {
                $backupPath = self::decompressBackup($backupPath);
            }
            
            // Obtener configuración de la base de datos
            $dbConfig = self::getDBConfig();
            
            // Comando mysql para restaurar
            $command = sprintf(
                'mysql -h%s -u%s -p%s %s < %s',
                escapeshellarg($dbConfig['host']),
                escapeshellarg($dbConfig['username']),
                escapeshellarg($dbConfig['password']),
                escapeshellarg($dbConfig['database']),
                escapeshellarg($backupPath)
            );
            
            // Ejecutar restauración
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new Exception("Error restaurando backup (código: $returnCode)");
            }
            
            // Limpiar archivo temporal si se descomprimió
            if (substr($filename, -3) === '.gz') {
                unlink($backupPath);
            }
            
            // Log de restauración
            SecurityLogger::logSecurityEvent('BACKUP_RESTORED', 'INFO', [
                'filename' => $filename,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            return [
                'success' => true,
                'message' => 'Backup restaurado exitosamente'
            ];
            
        } catch (Exception $e) {
            SecurityLogger::logSecurityEvent('BACKUP_RESTORE_FAILED', 'CRITICAL', [
                'filename' => $filename,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Lista todos los backups disponibles
     */
    public static function listBackups() {
        self::initBackupDir();
        
        $backups = [];
        $files = glob(self::$backupDir . '/*.gz');
        
        foreach ($files as $file) {
            $filename = basename($file);
            $stat = stat($file);
            
            // Extraer información del nombre del archivo
            if (preg_match('/backup_(\w+)_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.gz/', $filename, $matches)) {
                $type = $matches[1];
                $dateStr = str_replace('_', ' ', $matches[2]);
                $date = DateTime::createFromFormat('Y-m-d H i s', $dateStr);
                
                $backups[] = [
                    'filename' => $filename,
                    'type' => $type,
                    'size' => self::formatBytes($stat['size']),
                    'created_at' => $date->format('Y-m-d H:i:s'),
                    'timestamp' => $stat['mtime']
                ];
            }
        }
        
        // Ordenar por fecha (más reciente primero)
        usort($backups, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        return $backups;
    }
    
    /**
     * Elimina un backup
     */
    public static function deleteBackup($filename) {
        self::initBackupDir();
        
        $backupPath = self::$backupDir . '/' . $filename;
        
        if (!file_exists($backupPath)) {
            throw new Exception("El archivo de backup no existe: $filename");
        }
        
        if (!unlink($backupPath)) {
            throw new Exception("No se pudo eliminar el backup: $filename");
        }
        
        SecurityLogger::logSecurityEvent('BACKUP_DELETED', 'INFO', [
            'filename' => $filename
        ]);
        
        return true;
    }
    
    /**
     * Limpia backups antiguos
     */
    private static function cleanOldBackups() {
        $backups = self::listBackups();
        $maxBackups = self::$maxBackups;
        
        if (count($backups) > $maxBackups) {
            $toDelete = array_slice($backups, $maxBackups);
            
            foreach ($toDelete as $backup) {
                try {
                    self::deleteBackup($backup['filename']);
                } catch (Exception $e) {
                    // Log error but continue cleaning
                    SecurityLogger::logSecurityEvent('BACKUP_CLEANUP_ERROR', 'WARNING', [
                        'filename' => $backup['filename'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }
    
    /**
     * Comprime un archivo de backup
     */
    private static function compressBackup($filePath) {
        $compressedPath = $filePath . '.gz';
        
        $source = fopen($filePath, 'rb');
        $dest = gzopen($compressedPath, 'wb9');
        
        if (!$source || !$dest) {
            throw new Exception("No se pudo abrir archivos para compresión");
        }
        
        while (!feof($source)) {
            gzwrite($dest, fread($source, 1024 * 512));
        }
        
        fclose($source);
        gzclose($dest);
        
        return $compressedPath;
    }
    
    /**
     * Descomprime un archivo de backup
     */
    private static function decompressBackup($compressedPath) {
        $tempPath = tempnam(sys_get_temp_dir(), 'backup_');
        
        $source = gzopen($compressedPath, 'rb');
        $dest = fopen($tempPath, 'wb');
        
        if (!$source || !$dest) {
            throw new Exception("No se pudo abrir archivos para descompresión");
        }
        
        while (!gzeof($source)) {
            fwrite($dest, gzread($source, 1024 * 512));
        }
        
        gzclose($source);
        fclose($dest);
        
        return $tempPath;
    }
    
    /**
     * Obtiene la configuración de la base de datos
     */
    private static function getDBConfig() {
        // Usar las mismas constantes que config/db.php
        return [
            'host' => DB_HOST ?? 'localhost',
            'username' => DB_USER ?? 'root',
            'password' => DB_PASS ?? '',
            'database' => DB_NAME ?? 'contratos_db'
        ];
    }
    
    /**
     * Registra un backup en el log
     */
    private static function logBackup($filename, $size, $type) {
        $logFile = self::$backupDir . '/backup_log.json';
        
        $logs = [];
        if (file_exists($logFile)) {
            $logs = json_decode(file_get_contents($logFile), true) ?: [];
        }
        
        $logs[] = [
            'filename' => $filename,
            'size' => $size,
            'type' => $type,
            'created_at' => date('Y-m-d H:i:s'),
            'timestamp' => time()
        ];
        
        // Mantener solo últimos 100 registros
        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }
        
        file_put_contents($logFile, json_encode($logs, JSON_PRETTY_PRINT), LOCK_EX);
    }
    
    /**
     * Formatea bytes a formato legible
     */
    private static function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Crea backup automático si es necesario
     */
    public static function autoBackup() {
        $backupFile = self::$backupDir . '/last_auto_backup.txt';
        
        if (!file_exists($backupFile)) {
            touch($backupFile);
        }
        
        $lastBackup = filemtime($backupFile);
        $now = time();
        $hoursSinceLastBackup = ($now - $lastBackup) / 3600;
        
        // Crear backup automático cada 24 horas
        if ($hoursSinceLastBackup >= 24) {
            $result = self::createBackup('auto');
            
            if ($result['success']) {
                touch($backupFile);
                SecurityLogger::logSecurityEvent('AUTO_BACKUP_CREATED', 'INFO', [
                    'filename' => $result['filename'],
                    'size' => $result['size']
                ]);
            }
            
            return $result;
        }
        
        return ['success' => false, 'message' => 'No es necesario crear backup automático'];
    }
    
    /**
     * Verifica integridad de backups
     */
    public static function verifyBackups() {
        $backups = self::listBackups();
        $results = [];
        
        foreach ($backups as $backup) {
            $filePath = self::$backupDir . '/' . $backup['filename'];
            
            try {
                // Intentar descomprimir para verificar integridad
                $tempPath = self::decompressBackup($filePath);
                
                // Verificar que el archivo SQL tenga contenido válido
                $content = file_get_contents($tempPath, false, null, 0, 1000);
                $isValid = strpos($content, '-- MySQL dump') !== false || 
                          strpos($content, 'CREATE DATABASE') !== false;
                
                unlink($tempPath);
                
                $results[] = [
                    'filename' => $backup['filename'],
                    'valid' => $isValid,
                    'error' => $isValid ? null : 'Formato de backup inválido'
                ];
                
            } catch (Exception $e) {
                $results[] = [
                    'filename' => $backup['filename'],
                    'valid' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
}
