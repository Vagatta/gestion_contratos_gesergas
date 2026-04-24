<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../src/PdfExtractor.php';
require_once __DIR__ . '/../src/Security.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('contracts.php');
}

// Validar CSRF
if (!isset($_POST['csrf_token']) || !Security::validateCsrfToken($_POST['csrf_token'])) {
    flash_set('danger', 'Token de seguridad inválido. Por favor, recargue la página e intente nuevamente.');
    redirect('contracts.php');
}

// Rate limiting
if (!Security::checkRateLimit('contract_save', 3, 60)) {
    flash_set('danger', 'Demasiados intentos. Por favor, espere un minuto antes de intentar nuevamente.');
    redirect('contracts.php');
}

// Sanitización y validación de inputs
$id            = (int)($_POST['id'] ?? 0);
$contractDate  = Security::sanitizeInput($_POST['contract_date'] ?? '', 'string');
$clientName    = Security::sanitizeInput($_POST['client_name'] ?? '', 'string');
$address       = Security::sanitizeInput($_POST['address'] ?? '', 'string');
$contratista   = Security::sanitizeInput($_POST['contratista'] ?? '', 'string');
$notes         = Security::sanitizeInput($_POST['notes'] ?? '', 'string');
$types         = array_map(fn($t) => Security::sanitizeInput($t, 'string'), $_POST['contact_type'] ?? []);
$labels        = array_map(fn($l) => Security::sanitizeInput($l, 'string'), $_POST['contact_label'] ?? []);
$values        = array_map(fn($v) => Security::sanitizeInput($v, 'string'), $_POST['contact_value'] ?? []);
$primaryIndex  = isset($_POST['primary_contact']) ? (int)$_POST['primary_contact'] : 0;

// Variables para almacenar datos extraídos del PDF
$pdfExtractedFields = [];
$pdfUploaded = false;

// Procesar PDF subido y extraer información
if (!empty($_FILES['document']['name']) && is_uploaded_file($_FILES['document']['tmp_name'])) {
    if ($_FILES['document']['error'] === UPLOAD_ERR_OK) {
        // Validación de archivo PDF mejorada
        $fileValidation = Security::validateFile($_FILES['document'], ['application/pdf'], 5242880); // 5MB max
        if (!$fileValidation['valid']) {
            flash_set('danger', 'Error en el archivo PDF: ' . $fileValidation['error']);
            redirect('contract_form.php' . ($id > 0 ? '?id=' . $id : ''));
        }
        
        $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            $tmpPdfPath = $_FILES['document']['tmp_name'];
            $extractor = new PdfExtractor();
            $result = $extractor->extract($tmpPdfPath);
            
            if (!empty($result['fields'])) {
                $pdfExtractedFields = $result['fields'];
                $pdfUploaded = true;
                
                // Sanitizar datos extraídos del PDF
                if ($clientName === '' && !empty($result['fields']['name'])) {
                    $clientName = Security::sanitizeInput($result['fields']['name'], 'string');
                }
                if ($address === '' && !empty($result['fields']['address'])) {
                    $address = Security::sanitizeInput($result['fields']['address'], 'string');
                }
                if ($contractDate === '' && !empty($result['fields']['date'])) {
                    $contractDate = Security::sanitizeInput($result['fields']['date'], 'string');
                }
            }
        }
    }
}

// Validación de campos obligatorios usando Security
$missing = [];
if ($clientName === '' || !Security::validateName($clientName)) {
    $missing[] = 'nombre del cliente (solo letras, espacios y caracteres comunes)';
}
if ($contratista === '' || !Security::validateName($contratista)) {
    $missing[] = 'contratista (solo letras, espacios y caracteres comunes)';
}
if ($address === '' || !Security::validateAddress($address)) {
    $missing[] = 'dirección (formato inválido)';
}
if ($contractDate === '' || !Security::validateDate($contractDate)) {
    $missing[] = 'fecha del contrato (formato YYYY-MM-DD)';
}

// Validación de métodos de contacto
$hasContact = false;
$contactErrors = [];
foreach ($values as $i => $v) {
    $value = trim((string)$v);
    $type = $types[$i] ?? '';
    
    if ($value !== '') {
        $hasContact = true;
        
        // Validar formato según tipo
        switch ($type) {
            case 'email':
                if (!Security::validateEmail($value)) {
                    $contactErrors[] = "email '$value' no es válido";
                }
                break;
            case 'phone':
                if (!Security::validatePhone($value)) {
                    $contactErrors[] = "teléfono '$value' no tiene formato válido español";
                }
                break;
        }
    }
}

if (!$hasContact) {
    $missing[] = 'al menos un método de contacto';
}

if ($contactErrors) {
    $missing = array_merge($missing, $contactErrors);
}

// Documento PDF: obligatorio al crear, y al editar solo si no hay ya uno asociado
$pdfProvided = !empty($_FILES['document']['name']) && is_uploaded_file($_FILES['document']['tmp_name']);
$hasExistingDoc = false;
if ($id > 0) {
    $chk = db()->prepare('SELECT document_path FROM contracts WHERE id=?');
    $chk->execute([$id]);
    $hasExistingDoc = !empty($chk->fetchColumn());
}
if (!$pdfProvided && !$hasExistingDoc) {
    $missing[] = 'documento PDF';
}

if ($missing) {
    flash_set('danger', 'Faltan campos obligatorios: ' . implode(', ', $missing) . '.');
    redirect('contract_form.php' . ($id ? "?id=$id" : ''));
}

// Procesar contactos: filtrar vacíos. Marcar el favorito con is_primary=1.
$contacts = [];
$existingValues = []; // Para evitar duplicados

for ($i = 0, $n = count($values); $i < $n; $i++) {
    $v = trim((string)($values[$i] ?? ''));
    if ($v === '') continue;
    $existingValues[] = strtolower($v);
    $contacts[] = [
        'type'       => in_array($types[$i] ?? 'other', ['phone','email','whatsapp','other'], true) ? $types[$i] : 'other',
        'label'      => trim((string)($labels[$i] ?? '')),
        'value'      => $v,
        'is_primary' => ($i === $primaryIndex) ? 1 : 0,
    ];
}

// Si nadie quedó marcado como primary tras filtrar, marcar el primero
if ($contacts && !array_filter($contacts, fn($c) => $c['is_primary'])) {
    $contacts[0]['is_primary'] = 1;
}

// Agregar contactos extraídos del PDF si no existen ya
if ($pdfUploaded && !empty($pdfExtractedFields)) {
    // Email del PDF
    if (!empty($pdfExtractedFields['email'])) {
        $email = $pdfExtractedFields['email'];
        if (!in_array(strtolower($email), $existingValues)) {
            $contacts[] = [
                'type'  => 'email',
                'label' => 'Email (extraído PDF)',
                'value' => $email,
            ];
            $existingValues[] = strtolower($email);
        }
    }
    
    // Teléfono del PDF
    if (!empty($pdfExtractedFields['phone'])) {
        $phone = $pdfExtractedFields['phone'];
        if (!in_array(strtolower($phone), $existingValues)) {
            $contacts[] = [
                'type'  => 'phone',
                'label' => 'Teléfono (extraído PDF)',
                'value' => $phone,
            ];
            $existingValues[] = strtolower($phone);
        }
    }
}

$pdo = db();
$pdo->beginTransaction();

try {
    if ($id > 0) {
        // UPDATE
        $st = $pdo->prepare('SELECT client_id FROM contracts WHERE id=?');
        $st->execute([$id]);
        $clientId = (int)$st->fetchColumn();
        if (!$clientId) throw new RuntimeException('Contrato no encontrado.');

        $pdo->prepare('UPDATE clients SET name=?, address=?, contratista=? WHERE id=?')
            ->execute([$clientName, $address, $contratista, $clientId]);

        $pdo->prepare('UPDATE contracts SET contract_date=?, notes=? WHERE id=?')
            ->execute([$contractDate, $notes, $id]);

        // Reemplazar contactos (simple y predecible).
        $pdo->prepare('DELETE FROM contact_methods WHERE client_id=?')->execute([$clientId]);

        log_action('contract', $id, 'updated');
    } else {
        // INSERT cliente + contrato
        $pdo->prepare('INSERT INTO clients (name, address, contratista) VALUES (?,?,?)')
            ->execute([$clientName, $address, $contratista]);
        $clientId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO contracts (client_id, contract_date, notes) VALUES (?,?,?)')
            ->execute([$clientId, $contractDate, $notes]);
        $id = (int)$pdo->lastInsertId();

        log_action('contract', $id, 'created');
    }

    // Insertar contactos (con flag is_primary)
    $stC = $pdo->prepare('INSERT INTO contact_methods (client_id, type, label, value, is_primary) VALUES (?,?,?,?,?)');
    foreach ($contacts as $c) {
        $stC->execute([$clientId, $c['type'], $c['label'], $c['value'], $c['is_primary'] ?? 0]);
    }

    // Subida de PDF (opcional)
    if (!empty($_FILES['document']['name']) && is_uploaded_file($_FILES['document']['tmp_name'])) {
        if ($_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Error al subir el archivo.');
        }
        if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);

        $orig = $_FILES['document']['name'];
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') throw new RuntimeException('Solo se permiten PDF.');

        $safe = sprintf('contract_%d_%s.pdf', $id, bin2hex(random_bytes(6)));
        $dest = UPLOAD_DIR . DIRECTORY_SEPARATOR . $safe;
        if (!move_uploaded_file($_FILES['document']['tmp_name'], $dest)) {
            throw new RuntimeException('No se pudo guardar el archivo.');
        }
        $pdo->prepare('UPDATE contracts SET document_path=?, document_name=? WHERE id=?')
            ->execute([$safe, $orig, $id]);
    }

    // Generar notificaciones según configuración. Usar el contacto FAVORITO.
    $primary = null;
    foreach ($contacts as $c) {
        if (!empty($c['is_primary'])) { $primary = $c; break; }
    }
    if (!$primary) $primary = $contacts[0] ?? null;
    $typeLabels = [
        'phone'    => 'TELÉFONO',
        'email'    => 'EMAIL',
        'whatsapp' => 'WHATSAPP',
        'other'    => 'OTRO',
    ];
    $contactInfo = $primary
        ? ($typeLabels[$primary['type']] ?? strtoupper($primary['type'])) . ' → ' . $primary['value']
        : 'sin datos de contacto';

    // Si es edición, limpiar notificaciones pendientes previas y recrear.
    $pdo->prepare("DELETE FROM notifications WHERE contract_id=? AND status='pending'")
        ->execute([$id]);

    $schedule = compute_notification_schedule($contractDate);
    $stNotif = $pdo->prepare('INSERT INTO notifications (contract_id, notify_date, message) VALUES (?,?,?)');
    foreach ($schedule as $item) {
        $msg = 'Contactar cliente. ' . $contactInfo;
        $stNotif->execute([$id, $item['date'], $msg]);
    }

    $pdo->commit();
    $n = count($schedule);
    if ($n === 1) {
        $successMsg = 'Contrato guardado. Notificación programada para ' . $schedule[0]['date'] . ' (' . $schedule[0]['label'] . ')';
    } else {
        $successMsg = 'Contrato guardado. Se programaron ' . $n . ' notificaciones según la configuración actual';
    }
    if ($pdfUploaded) {
        $extractedInfo = [];
        if (!empty($pdfExtractedFields['name'])) $extractedInfo[] = 'nombre';
        if (!empty($pdfExtractedFields['address'])) $extractedInfo[] = 'dirección';
        if (!empty($pdfExtractedFields['email'])) $extractedInfo[] = 'email';
        if (!empty($pdfExtractedFields['phone'])) $extractedInfo[] = 'teléfono';
    }
    $successMsg .= '.';
    flash_set('success', $successMsg);
    redirect('contracts.php');

} catch (Throwable $ex) {
    $pdo->rollBack();
    flash_set('danger', 'Error: ' . $ex->getMessage());
    redirect('contract_form.php' . ($id ? "?id=$id" : ''));
}
