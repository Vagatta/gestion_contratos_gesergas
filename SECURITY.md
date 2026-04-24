# Documentación de Seguridad

## Overview

Este documento describe las medidas de seguridad implementadas en el sistema de gestión de contratos para proteger contra ataques comunes y garantizar la integridad de los datos.

## 🔐 Medidas de Seguridad Implementadas

### 1. Protección CSRF (Cross-Site Request Forgery)

**Implementación:**
- Token CSRF generado aleatoriamente por sesión
- Validación en todos los formularios POST
- Regeneración automática de tokens

**Archivos:**
- `src/Security.php` - `getCsrfToken()`, `validateCsrfToken()`, `csrfField()`
- Todos los formularios incluyen `<?= Security::csrfField() ?>`

**Uso:**
```php
// En formularios
<form method="post">
    <?= Security::csrfField() ?>
    <!-- campos del formulario -->
</form>

// Validación
if (!Security::validateCsrfToken($_POST['csrf_token'])) {
    // Token inválido
}
```

### 2. Rate Limiting

**Implementación:**
- Límites diferentes por tipo de acción
- Registro de intentos fallidos
- Bloqueo automático de IPs

**Límites Configurados:**
- Guardar contrato: 3 intentos/minuto
- Completar notificación: 10 intentos/minuto
- Eliminar contrato: 3 intentos/5 minutos

**Uso:**
```php
if (!Security::checkRateLimit('action_name', 5, 60)) {
    // Límite excedido
}
```

### 3. Sistema de Bloqueo de IPs

**Implementación:**
- Bloqueo automático por intentos fallidos
- Diferentes umbrales por tipo de ataque
- Página de bloqueo personalizada

**Umbrales:**
- CSRF fallido: 5 intentos en 5 min → 30 min bloqueo
- Rate limit: 10 intentos en 5 min → 30 min bloqueo
- Archivo inválido: 3 intentos en 10 min → 1 hora bloqueo
- General: 20 intentos en 10 min → 30 min bloqueo

**Archivos:**
- `src/IPBlocker.php`

### 4. Validación y Sanitización de Inputs

**Implementación:**
- Sanitización automática de todos los inputs
- Validación de formatos específicos
- Validación de longitud de campos

**Validaciones Disponibles:**
```php
// Validación con sanitización
$result = Security::validateAndSanitizeInput($input, 'email', 5, 100);
if (!$result['valid']) {
    // Error: $result['error']
}
$sanitized = $result['value'];

// Tipos soportados: string, email, phone, name, address, date, int, float, url
```

### 5. Validación de Archivos

**Implementación:**
- Verificación real de MIME type
- Límite de tamaño (5MB por defecto)
- Detección de contenido malicioso
- Análisis de primeros 1KB del archivo

**Uso:**
```php
$validation = Security::validateFile($_FILES['document'], ['application/pdf'], 5242880);
if (!$validation['valid']) {
    // Error: $validation['error']
}
```

### 6. Headers de Seguridad HTTP

**Headers Implementados:**
```http
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; connect-src 'self'
```

### 7. Logging de Seguridad

**Implementación:**
- Registro de todos los eventos de seguridad
- Detección automática de patrones maliciosos
- Alertas por email para eventos críticos

**Eventos Registrados:**
- Intentos CSRF fallidos
- Rate limiting excedido
- Archivos inválidos
- Patrones de inyección SQL
- Patrones XSS
- Comportamiento sospechoso

**Archivos:**
- `src/SecurityLogger.php`
- `logs/security.log`

### 8. Monitoreo de Errores

**Implementación:**
- Captura de todos los errores y excepciones
- Clasificación por severidad
- Alertas automáticas para errores críticos
- Estadísticas de errores

**Archivos:**
- `src/ErrorMonitor.php`
- `logs/errors.log`

### 9. Sistema de Backup

**Implementación:**
- Backups automáticos diarios
- Compresión de backups
- Verificación de integridad
- Limpieza automática de backups antiguos

**Uso:**
```php
// Crear backup
$result = BackupManager::createBackup('full');

// Listar backups
$backups = BackupManager::listBackups();

// Restaurar backup
BackupManager::restoreBackup('backup_full_2024-01-01_00-00-00.sql.gz');
```

### 10. Optimización de Base de Datos

**Índices Implementados:**
- Índices compuestos para consultas frecuentes
- Índices por columnas de búsqueda
- Índices para ordenación y paginación

**Archivo:**
- `sql/schema.sql` (índices adicionales)

## 🛡️ Configuración Recomendada

### Variables de Entorno

```bash
# .env
APP_DEBUG=false
ADMIN_EMAIL=admin@tuempresa.com
DB_HOST=localhost
DB_USER=tu_usuario
DB_PASS=tu_contraseña
DB_NAME=contratos_db
ADMIN_PASSWORD=tu_contraseña_admin
```

### Permisos de Archivos

```bash
# Directorios que deben ser escribibles por el servidor web
chmod 755 logs/
chmod 755 data/
chmod 755 backups/
chmod 755 uploads/contracts/

# Archivos sensibles (solo lectura)
chmod 644 .env
chmod 644 config/db.php
```

### Configuración de Servidor Web

**Apache (.htaccess):**
```apache
# Prevenir acceso a archivos sensibles
<Files ".env">
    Require all denied
</Files>

<Files "config/db.php">
    Require all denied
</Files>

# Prevenir ejecución en uploads
<Directory "uploads">
    php_flag engine off
    Options -ExecCGI
    RemoveHandler .php .phtml .php3 .php4 .php5
</Directory>
```

**Nginx:**
```nginx
# Prevenir acceso a archivos sensibles
location ~ /\.(env|log) {
    deny all;
}

location ~ ^/(config|src)/ {
    deny all;
}

# Prevenir ejecución en uploads
location ~ ^/uploads/.*\.php$ {
    deny all;
}
```

## 🔍 Monitoreo y Mantenimiento

### Logs Importantes

- `logs/security.log` - Eventos de seguridad
- `logs/errors.log` - Errores y excepciones
- `data/blocked_ips.json` - IPs bloqueadas
- `data/failed_attempts.json` - Intentos fallidos
- `backups/backup_log.json` - Historial de backups

### Comandos de Mantenimiento

```php
// Limpiar logs antiguos (ejecutar semanalmente)
SecurityLogger::cleanOldLogs(30);
ErrorMonitor::cleanOldLogs(30);
IPBlocker::cleanup();

// Crear backup automático
BackupManager::autoBackup();

// Verificar integridad de backups
$verification = BackupManager::verifyBackups();
```

### Estadísticas de Seguridad

```php
// Estadísticas de seguridad últimas 24h
$securityStats = SecurityLogger::getSecurityStats(24);
$errorStats = ErrorMonitor::getErrorStats(24);
$blockedIPs = IPBlocker::getBlockedIPs();
$failedAttempts = IPBlocker::getFailedAttemptsStats(24);
```

## 🚨 Respuesta a Incidentes

### Detección de Ataques

1. **Monitorear logs de seguridad** regularmente
2. **Configurar alertas por email** para eventos críticos
3. **Revisar estadísticas** de intentos fallidos
4. **Verificar IPs bloqueadas** periódicamente

### Procedimiento en Caso de Ataque

1. **Identificar el tipo de ataque** en los logs
2. **Bloquear IPs maliciosas** manualmente si es necesario
3. **Revisar integridad de datos**
4. **Restaurar backup** si es necesario
5. **Actualizar medidas de seguridad**
6. **Documentar el incidente**

### Comandos de Respuesta Rápida

```php
// Bloquear IP manualmente
IPBlocker::blockIP('192.168.1.100', 86400, 'Ataque detectado manualmente');

// Desbloquear IP
IPBlocker::unblockIP('192.168.1.100');

// Verificar logs recientes
$recentLogs = SecurityLogger::getSecurityStats(1);

// Crear backup de emergencia
BackupManager::createBackup('emergency');
```

## 📋 Checklist de Seguridad

### ✅ Implementación Básica

- [ ] Tokens CSRF en todos los formularios
- [ ] Rate limiting configurado
- [ ] Validación de inputs implementada
- [ ] Headers de seguridad configurados
- [ ] Sistema de logging activo

### ✅ Configuración Avanzada

- [ ] Bloqueo de IPs automático
- [ ] Monitoreo de errores activo
- [ ] Sistema de backup configurado
- [ ] Índices de BD optimizados
- [ ] Alertas por email configuradas

### ✅ Mantenimiento

- [ ] Logs rotados periódicamente
- [ ] Backups verificados regularmente
- [ ] Estadísticas revisadas semanalmente
- [ ] Actualizaciones de seguridad aplicadas
- [ ] Documentación mantenida actualizada

## 🔐 Buenas Prácticas Adicionales

1. **Actualizar dependencias** regularmente
2. **Usar HTTPS** en producción
3. **Implementar autenticación** robusta
4. **Limitar intentos de login**
5. **Monitorear tráfico anómalo**
6. **Segmentar la red** si es posible
7. **Realizar auditorías** periódicas
8. **Capacitar al personal** en seguridad

## 📞 Contacto de Seguridad

Para reportar incidentes de seguridad o vulnerabilidades:

- **Email:** security@tuempresa.com
- **Urgente:** +34 XXX XXX XXX

---

**Última actualización:** 2024-01-01  
**Versión:** 1.0  
**Responsable:** Equipo de Seguridad
