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
$clientType    = Security::sanitizeInput($_POST['client_type'] ?? 'particular', 'string');
$taxId         = strtoupper(Security::sanitizeInput($_POST['tax_id'] ?? '', 'string'));
$energyType    = Security::sanitizeInput($_POST['energy_type'] ?? '', 'string');
$tariffType    = Security::sanitizeInput($_POST['tariff_type'] ?? '', 'string');
$notes         = Security::sanitizeInput($_POST['notes'] ?? '', 'string');
$company           = Security::sanitizeInput($_POST['company'] ?? '', 'string');
$cups              = Security::sanitizeInput($_POST['cups'] ?? '', 'string');
$annualConsumption = Security::sanitizeInput($_POST['annual_consumption'] ?? '', 'string');
$powerP1           = Security::sanitizeInput($_POST['power_p1'] ?? '', 'string');
$powerP2           = Security::sanitizeInput($_POST['power_p2'] ?? '', 'string');
$product           = Security::sanitizeInput($_POST['product'] ?? '', 'string');
$saleDate          = Security::sanitizeInput($_POST['sale_date'] ?? '', 'string');
$activationDate    = Security::sanitizeInput($_POST['activation_date'] ?? '', 'string');
$startDate         = Security::sanitizeInput($_POST['start_date'] ?? '', 'string');
$endDate           = Security::sanitizeInput($_POST['end_date'] ?? '', 'string');
$invoicePaper      = in_array((int)($_POST['invoice_paper'] ?? 0), [0,1], true) ? (int)$_POST['invoice_paper'] : 0;
$contractPaper     = in_array((int)($_POST['contract_paper'] ?? 0), [0,1], true) ? (int)$_POST['contract_paper'] : 0;
$birthDate         = Security::sanitizeInput($_POST['birth_date'] ?? '', 'string');
$fiscalAddress     = Security::sanitizeInput($_POST['fiscal_address'] ?? '', 'string');
$commsAddress  = Security::sanitizeInput($_POST['comms_address'] ?? '', 'string');
$iban          = strtoupper(preg_replace('/\s+/', '', Security::sanitizeInput($_POST['iban'] ?? '', 'string')));
$agentName     = Security::sanitizeInput($_POST['agent_name'] ?? '', 'string');
$agentEmail    = Security::sanitizeInput($_POST['agent_email'] ?? '', 'string');
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

// ── Helpers de validación local ────────────────────────────────────────────
function validateDni(string $v): bool {
    $v = strtoupper(preg_replace('/[\s\-]/', '', $v));
    if (!preg_match('/^\d{8}[A-Z]$/', $v)) return false;
    return substr('TRWAGMYFPDXBNJZSQVHLCKE', (int)substr($v, 0, 8) % 23, 1) === $v[8];
}
function validateNie(string $v): bool {
    $v = strtoupper(preg_replace('/[\s\-]/', '', $v));
    if (!preg_match('/^[XYZ]\d{7}[A-Z]$/', $v)) return false;
    $map = ['X'=>'0','Y'=>'1','Z'=>'2'];
    $num = ($map[$v[0]] ?? '0') . substr($v, 1, 7);
    return substr('TRWAGMYFPDXBNJZSQVHLCKE', (int)$num % 23, 1) === $v[8];
}
function validateCif(string $v): bool {
    $v = strtoupper(preg_replace('/[\s\-]/', '', $v));
    return (bool)preg_match('/^[ABCDEFGHJKLMNPQRSVWX]\d{7}[0-9A-J]$/', $v);
}
function validateCups(string $v): bool {
    if ($v === '') return true; // opcional
    return (bool)preg_match('/^ES\d{16}[A-Z]{2}(\d[FPRCXYZ])?$/i', preg_replace('/\s/', '', $v));
}
function validateOptionalDate(string $v): bool {
    if ($v === '') return true;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return $d && $d->format('Y-m-d') === $v;
}
// ────────────────────────────────────────────────────────────────────────────

// Validación de campos obligatorios usando Security
$missing = [];
if ($clientName === '' || !Security::validateName($clientName)) {
    $missing[] = 'nombre del cliente (solo letras, espacios y caracteres comunes)';
}
if ($contratista !== '' && mb_strlen($contratista) > 200) {
    $contratista = mb_substr($contratista, 0, 200);
}
if ($address === '' || !Security::validateAddress($address)) {
    $missing[] = 'dirección (formato inválido)';
}
if ($contractDate === '' || !Security::validateDate($contractDate)) {
    $missing[] = 'fecha del contrato (formato YYYY-MM-DD)';
}
if (!in_array($clientType, ['particular','empresa'], true)) {
    $missing[] = 'tipo de cliente válido';
}

// Validar DNI / NIE / CIF según tipo de cliente
if ($taxId === '') {
    $missing[] = $clientType === 'empresa' ? 'CIF' : 'DNI';
} elseif ($clientType === 'empresa') {
    if (!validateCif($taxId)) {
        $missing[] = 'CIF con formato válido (ej: B12345678)';
    }
} else {
    $taxIdClean = preg_replace('/[\s\-]/', '', $taxId);
    if (!validateDni($taxIdClean) && !validateNie($taxIdClean)) {
        $missing[] = 'DNI/NIE con formato y letra de control válidos';
    }
}

if (!in_array($energyType, ['gas','electricidad'], true)) {
    $missing[] = 'tipo de contrato';
}
$allowedTariffs = [
    'gas' => ['RL-1','RL-2','RL-3','RL-4'],
    'electricidad' => ['2.0 TD','3.0 TD','6.1 TD','6.2 TD'],
];
if ($energyType === '' || !in_array($tariffType, $allowedTariffs[$energyType] ?? [], true)) {
    $missing[] = 'tarifa válida';
}

// Validar CUPS si se rellena
if (!validateCups($cups)) {
    $missing[] = 'CUPS con formato válido (ej: ES0021000009117716LF)';
}

// Validar fecha de nacimiento (no puede ser futura, ni antes de 1900)
if ($birthDate !== '') {
    if (!validateOptionalDate($birthDate)) {
        $missing[] = 'fecha de nacimiento con formato válido (YYYY-MM-DD)';
    } else {
        $bd = new DateTime($birthDate);
        $today = new DateTime('today');
        if ($bd > $today) {
            $missing[] = 'fecha de nacimiento no puede ser futura';
        } elseif ($bd < new DateTime('1900-01-01')) {
            $missing[] = 'fecha de nacimiento no puede ser anterior a 1900';
        }
    }
}

// Validar fechas opcionales del contrato
foreach (['sale_date' => $saleDate, 'activation_date' => $activationDate, 'start_date' => $startDate, 'end_date' => $endDate] as $dKey => $dVal) {
    if ($dVal !== '' && !validateOptionalDate($dVal)) {
        $missing[] = str_replace('_date', '', $dKey) . ': fecha con formato inválido';
    }
}
// Coherencia lógica de fechas
if ($startDate !== '' && $endDate !== '' && validateOptionalDate($startDate) && validateOptionalDate($endDate)) {
    if (new DateTime($endDate) <= new DateTime($startDate)) {
        $missing[] = 'fecha de baja debe ser posterior a la fecha de inicio';
    }
}
if ($saleDate !== '' && $activationDate !== '' && validateOptionalDate($saleDate) && validateOptionalDate($activationDate)) {
    if (new DateTime($activationDate) < new DateTime($saleDate)) {
        $missing[] = 'fecha de activación no puede ser anterior a la fecha de venta';
    }
}

// Validar email del agente si se rellena
if ($agentEmail !== '' && !Security::validateEmail($agentEmail)) {
    $missing[] = 'correo del agente con formato válido';
}

// Limitar longitudes a las columnas de la BD
$clientName    = mb_substr($clientName,    0, 200);
$address       = mb_substr($address,       0, 300);
$fiscalAddress = mb_substr($fiscalAddress, 0, 300);
$commsAddress  = mb_substr($commsAddress,  0, 300);
$taxId         = mb_substr($taxId,         0, 20);
$iban          = mb_substr($iban,          0, 34);
$agentName     = mb_substr($agentName,     0, 200);
$agentEmail    = mb_substr($agentEmail,    0, 200);
$company       = mb_substr($company,       0, 200);
$cups          = mb_substr(preg_replace('/\s/', '', $cups), 0, 100);
$annualConsumption = mb_substr($annualConsumption, 0, 50);
$powerP1       = mb_substr($powerP1,       0, 50);
$powerP2       = mb_substr($powerP2,       0, 50);
$product       = mb_substr($product,       0, 200);

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

$requiredDocuments = ['lease_or_deed', 'invoice'];
if ($clientType === 'empresa') {
    $requiredDocuments = array_merge($requiredDocuments, ['company_deeds', 'power_of_attorney', 'representative_dni', 'bank_certificate']);
}
$hasExistingDocuments = [];
if ($id > 0) {
    try {
        $stDocs = db()->prepare('SELECT document_type FROM contract_documents WHERE contract_id=?');
        $stDocs->execute([$id]);
        $hasExistingDocuments = array_fill_keys($stDocs->fetchAll(PDO::FETCH_COLUMN), true);
    } catch (Throwable $e) {
        $hasExistingDocuments = [];
    }
}
foreach ($requiredDocuments as $docType) {
    $docProvided = !empty($_FILES['documents']['name'][$docType]);
    if (!$docProvided && empty($hasExistingDocuments[$docType])) {
        $missing[] = 'documento: ' . str_replace('_', ' ', $docType);
    }
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

        $pdo->prepare('UPDATE clients SET name=?, address=?, contratista=?, client_type=?, tax_id=?, birth_date=?, fiscal_address=?, comms_address=?, iban=?, agent_name=?, agent_email=? WHERE id=?')
            ->execute([$clientName, $address, $contratista, $clientType, $taxId, $birthDate ?: null, $fiscalAddress ?: null, $commsAddress ?: null, $iban ?: null, $agentName ?: null, $agentEmail ?: null, $clientId]);

        $pdo->prepare('UPDATE contracts SET contract_date=?, energy_type=?, tariff_type=?, company=?, cups=?, annual_consumption=?, power_p1=?, power_p2=?, product=?, sale_date=?, activation_date=?, start_date=?, end_date=?, invoice_paper=?, contract_paper=?, notes=? WHERE id=?')
            ->execute([$contractDate, $energyType, $tariffType, $company ?: null, $cups ?: null, $annualConsumption ?: null, $powerP1 ?: null, $powerP2 ?: null, $product ?: null, $saleDate ?: null, $activationDate ?: null, $startDate ?: null, $endDate ?: null, $invoicePaper, $contractPaper, $notes, $id]);

        // Reemplazar contactos (simple y predecible).
        $pdo->prepare('DELETE FROM contact_methods WHERE client_id=?')->execute([$clientId]);

        log_action('contract', $id, 'updated');
    } else {
        // INSERT cliente + contrato
        $pdo->prepare('INSERT INTO clients (name, address, contratista, client_type, tax_id, birth_date, fiscal_address, comms_address, iban, agent_name, agent_email) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$clientName, $address, $contratista, $clientType, $taxId, $birthDate ?: null, $fiscalAddress ?: null, $commsAddress ?: null, $iban ?: null, $agentName ?: null, $agentEmail ?: null]);
        $clientId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO contracts (client_id, contract_date, energy_type, tariff_type, company, cups, annual_consumption, power_p1, power_p2, product, sale_date, activation_date, start_date, end_date, invoice_paper, contract_paper, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$clientId, $contractDate, $energyType, $tariffType, $company ?: null, $cups ?: null, $annualConsumption ?: null, $powerP1 ?: null, $powerP2 ?: null, $product ?: null, $saleDate ?: null, $activationDate ?: null, $startDate ?: null, $endDate ?: null, $invoicePaper, $contractPaper, $notes]);
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

    $allowedDocumentTypes = ['lease_or_deed', 'invoice', 'other'];
    if ($clientType === 'empresa') {
        $allowedDocumentTypes = array_merge($allowedDocumentTypes, ['company_deeds', 'power_of_attorney', 'representative_dni', 'bank_certificate']);
    }
    $allowedDocumentMimes = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $insertDoc = $pdo->prepare('INSERT INTO contract_documents (contract_id, document_type, document_path, document_name) VALUES (?,?,?,?)');

    foreach ($allowedDocumentTypes as $docType) {
        if (empty($_FILES['documents']['name'][$docType])) {
            continue;
        }

        $names = is_array($_FILES['documents']['name'][$docType])
            ? $_FILES['documents']['name'][$docType]
            : [$_FILES['documents']['name'][$docType]];
        $tmpNames = is_array($_FILES['documents']['tmp_name'][$docType])
            ? $_FILES['documents']['tmp_name'][$docType]
            : [$_FILES['documents']['tmp_name'][$docType]];
        $errors = is_array($_FILES['documents']['error'][$docType])
            ? $_FILES['documents']['error'][$docType]
            : [$_FILES['documents']['error'][$docType]];
        $sizes = is_array($_FILES['documents']['size'][$docType])
            ? $_FILES['documents']['size'][$docType]
            : [$_FILES['documents']['size'][$docType]];
        $typesUpload = is_array($_FILES['documents']['type'][$docType])
            ? $_FILES['documents']['type'][$docType]
            : [$_FILES['documents']['type'][$docType]];

        foreach ($names as $i => $origDoc) {
            if ($origDoc === '' || !isset($tmpNames[$i]) || !is_uploaded_file($tmpNames[$i])) {
                continue;
            }
            if (($errors[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Error al subir documento: ' . $origDoc);
            }

            $file = [
                'name' => $origDoc,
                'tmp_name' => $tmpNames[$i],
                'error' => $errors[$i],
                'size' => $sizes[$i] ?? 0,
                'type' => $typesUpload[$i] ?? '',
            ];
            $fileValidation = Security::validateFile($file, $allowedDocumentMimes, 10485760);
            if (!$fileValidation['valid']) {
                throw new RuntimeException('Error en documento ' . $origDoc . ': ' . $fileValidation['error']);
            }

            if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
            $ext = strtolower(pathinfo($origDoc, PATHINFO_EXTENSION));
            $safeDoc = sprintf('contract_%d_%s_%s.%s', $id, $docType, bin2hex(random_bytes(6)), $ext);
            $destDoc = UPLOAD_DIR . DIRECTORY_SEPARATOR . $safeDoc;
            if (!move_uploaded_file($tmpNames[$i], $destDoc)) {
                throw new RuntimeException('No se pudo guardar documento: ' . $origDoc);
            }
            $insertDoc->execute([$id, $docType, $safeDoc, $origDoc]);
        }
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
