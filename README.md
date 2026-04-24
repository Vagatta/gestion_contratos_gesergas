# Gestión de contratos (MVP)

SaaS simple para registrar contratos, métodos de contacto por cliente, y generar
notificaciones automáticas de seguimiento (por defecto: `fecha_contrato + 11 meses`).

## Stack
- **Backend:** PHP 8+ (PDO), MariaDB/MySQL 5.7+
- **Frontend:** Bootstrap 5, jQuery 3, Bootstrap Icons
- **Calendario:** FullCalendar 6

## Estructura
```
sistemas/
├── composer.json              # dependencias (pdfparser)
├── config/db.php              # conexión PDO + helpers
├── sql/schema.sql             # esquema BD
├── src/
│   ├── ApiResponse.php        # helper JSON + CORS
│   └── PdfExtractor.php       # OCR/parsing PDF con detección de campos
├── public/                    # raíz web (apuntar DocumentRoot aquí)
│   ├── index.php              # dashboard
│   ├── contracts.php          # listado + filtros
│   ├── contract_form.php      # alta/edición + contactos dinámicos + subida + auto-parse PDF
│   ├── contract_save.php
│   ├── contract_delete.php
│   ├── notifications.php
│   ├── notification_complete.php
│   ├── calendar.php           # FullCalendar
│   ├── assets/app.css
│   ├── includes/              # header/footer comunes
│   └── api/
│       ├── .htaccess          # rewrite a index.php
│       ├── index.php          # router REST (v1)
│       └── events.php         # feed JSON para el calendario
└── uploads/contracts/         # PDFs subidos
```

## Instalación

1. Crear BD:
   ```bash
   mysql -u root -p < sql/schema.sql
   ```
2. Editar credenciales en `config/db.php` (`DB_USER`, `DB_PASS`).
3. Configurar el servidor web (Apache/Nginx) con `DocumentRoot` en `public/`.
   - La carpeta `uploads/` queda al nivel superior; los enlaces se generan con
     `UPLOAD_WEB_PATH = '../uploads/contracts/'`.
   - Si tu hosting no permite acceso a `../`, mueve `uploads/` dentro de `public/`
     y ajusta las constantes `UPLOAD_DIR` y `UPLOAD_WEB_PATH` en `config/db.php`.
4. Dar permisos de escritura a `uploads/contracts/`.
5. Abrir `http://tu-host/` -> dashboard.

## Lógica de notificaciones
Al guardar un contrato se crea una notificación pendiente con:
- **Fecha:** `contract_date + NOTIFY_OFFSET_MONTHS` (11 meses por defecto, configurable en `config/db.php`).
- **Mensaje:** `"Hay que contactar con este cliente. Método de contacto: <TIPO> -> <VALOR>"`
  usando el primer método de contacto registrado.

Al editar un contrato, las notificaciones pendientes previas se regeneran con la nueva fecha/mensaje.
Las completadas se conservan como histórico.

## Funcionalidades
- CRUD contratos + cliente (normalizado).
- Múltiples métodos de contacto por cliente (teléfono, email, whatsapp, otros — extensible).
- Subida de PDF con almacenamiento en disco y ruta en BD.
- Panel de notificaciones con filtros (estado, cliente, rango de fechas).
- Calendario interactivo con filtros y click -> contrato.
- Historial de acciones (`action_log`).
- Búsqueda y filtros en listado de contratos.

## API REST

Base: `http://tu-host/api/v1`. JSON in/out. CORS abierto (ajústalo en `src/ApiResponse.php`).

### Contratos
| Método | Ruta | Descripción |
|---|---|---|
| GET  | `/contracts?q=&from=&to=&limit=&offset=` | Listado con filtros |
| POST | `/contracts` | Crea cliente + contrato + contactos + notificación |
| GET  | `/contracts/{id}` | Detalle con `contacts` y `notifications` |
| PUT  | `/contracts/{id}` | Actualiza; regenera notificación pendiente |
| DELETE | `/contracts/{id}` | Elimina contrato y PDF asociado |

Body POST/PUT:
```json
{
  "client_name": "ACME SL",
  "address": "Calle Mayor 1, Madrid",
  "contratista": "Diego",
  "contract_date": "2026-03-01",
  "notes": "Renovación anual",
  "contacts": [
    { "type": "phone", "label": "móvil", "value": "600111222", "is_primary": true },
    { "type": "email", "value": "info@acme.es" }
  ]
}
```

### Clientes
`GET/POST /clients`, `GET/PUT/DELETE /clients/{id}` — mismo patrón.

### Notificaciones
| Método | Ruta | Descripción |
|---|---|---|
| GET  | `/notifications?status=pending&from=&to=&q=` | Listado |
| POST | `/notifications/{id}/complete` | Marca completada |

### Parse PDF (OCR / autocompletado)
`POST /parse-pdf` — `multipart/form-data` con campo `file` (PDF).

Respuesta:
```json
{
  "source": "pdftotext",
  "fields": {
    "name": "Juan Pérez",
    "address": "Calle Mayor 12, Madrid",
    "date": "2026-03-01",
    "email": "juan@example.com",
    "phone": "600 111 222"
  },
  "preview": "primeros 1500 caracteres del texto extraído..."
}
```

Ejemplo curl:
```bash
curl -X POST http://localhost/api/v1/parse-pdf -F "file=@contrato.pdf"
curl http://localhost/api/v1/contracts?q=acme
curl -X POST http://localhost/api/v1/contracts \
     -H 'Content-Type: application/json' \
     -d '{"client_name":"ACME","contract_date":"2026-03-01","contacts":[{"type":"email","value":"a@b.c"}]}'
```

## OCR / Parsing PDF

El servicio `PdfExtractor` (`src/PdfExtractor.php`) intenta, en orden:

1. **`pdftotext`** (Poppler) — rápido y fiable para PDFs con texto.
   - Instalar: `choco install poppler` (Windows), `apt install poppler-utils` (Debian/Ubuntu).
2. **`smalot/pdfparser`** (PHP puro) — fallback si no hay `pdftotext`.
   - Instalar: `composer install`.
3. **`tesseract` + `pdftoppm`** — OCR para PDFs escaneados (sin capa de texto).
   - Instalar: `choco install tesseract` / `apt install tesseract-ocr tesseract-ocr-spa poppler-utils`.

Detección de campos por regex heurísticas (nombre, dirección, fecha ES/ISO, email,
teléfono ES). Los valores se ofrecen como **sugerencia** al formulario, que
autocompleta sólo los campos vacíos — el usuario revisa antes de guardar.

Integración en `contract_form.php`: al seleccionar un PDF se llama vía AJAX a
`/api/v1/parse-pdf`, el spinner muestra el progreso y se rellenan los campos.

## Pendiente / extras futuros
- **Autenticación** (sesiones PHP + bcrypt, tabla `users`, API keys para la REST).
- **Cron job** para enviar emails/recordatorios de notificaciones vencidas:
  ```cron
  0 8 * * * /usr/bin/php /ruta/cron/send_reminders.php
  ```
- **Roles** (admin / operador).
- **Rate limiting** en la API.

## Seguridad
- `uploads/.htaccess` bloquea ejecución de scripts dentro de `uploads/`.
- Consultas preparadas (PDO) en todos los accesos a BD.
- Escape de salida con `e()` (`htmlspecialchars`).
- Sanitización de extensión de archivo (solo `.pdf`) y nombre aleatorio en disco.
