<?php
require_once __DIR__ . '/../config/db.php';
$pageTitle = 'Contrato';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$contract = ['contract_date'=>'', 'energy_type'=>'', 'tariff_type'=>'', 'notes'=>'', 'document_path'=>null, 'document_name'=>null];
$client   = ['name'=>'', 'address'=>'', 'contratista'=>'', 'client_type'=>'particular', 'tax_id'=>''];
$contacts = [];
$documents = [];

if ($id > 0) {
    $st = db()->prepare('SELECT * FROM contracts WHERE id=?');
    $st->execute([$id]);
    $contract = $st->fetch();
    if (!$contract) { flash_set('danger','Contrato no encontrado.'); redirect('contracts.php'); }

    $st = db()->prepare('SELECT * FROM clients WHERE id=?');
    $st->execute([$contract['client_id']]);
    $client = $st->fetch() ?: $client;

    $st = db()->prepare('SELECT * FROM contact_methods WHERE client_id=? ORDER BY id');
    $st->execute([$contract['client_id']]);
    $contacts = $st->fetchAll();

    try {
        $st = db()->prepare('SELECT * FROM contract_documents WHERE contract_id=? ORDER BY uploaded_at DESC, id DESC');
        $st->execute([$id]);
        $documents = $st->fetchAll();
    } catch (Throwable $e) {
        $documents = [];
    }
}
if (!$contacts) {
    $contacts = [['type'=>'phone','label'=>'','value'=>'']];
}

include __DIR__ . '/includes/header.php';
error_log('CSRF DEBUG contract_form: Session ID=' . session_id() . ', token=' . substr(Security::getCsrfToken(), 0, 8) . '...');
$documentLabels = [
    'lease_or_deed' => 'Contrato arrendamiento o escrituras',
    'invoice' => 'Factura',
    'company_deeds' => 'Escrituras de la sociedad',
    'power_of_attorney' => 'Apoderamiento',
    'representative_dni' => 'DNI del apoderado',
    'bank_certificate' => 'Certificado bancario',
    'other' => 'Otros documentos',
];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div class="d-flex align-items-center gap-3">
    <div class="section-icon-lg" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);">
      <i class="bi bi-<?= $id ? 'pencil-square' : 'plus-circle' ?>"></i>
    </div>
    <div>
      <h1 class="h4 mb-0 fw-bold"><?= $id ? 'Editar contrato' : 'Nuevo contrato' ?></h1>
      <p class="text-muted mb-0 small">Completa los datos del cliente y la información del contrato</p>
    </div>
  </div>
  <a href="contracts.php" class="btn btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Volver
  </a>
</div>

<form method="post" action="contract_save.php" enctype="multipart/form-data" id="contractForm">
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <?= Security::csrfField() ?>

  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header card-header-colored" style="--hdr-color:#6366f1;">
          <div class="d-flex align-items-center gap-3">
            <div class="card-hdr-icon"><i class="bi bi-person-circle"></i></div>
            <strong>Datos del cliente</strong>
          </div>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Tipo de cliente *</label>
              <select name="client_type" id="clientType" class="form-select" required>
                <option value="particular" <?= ($client['client_type'] ?? 'particular') === 'particular' ? 'selected' : '' ?>>Cliente particular</option>
                <option value="empresa" <?= ($client['client_type'] ?? '') === 'empresa' ? 'selected' : '' ?>>Empresa</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label" id="taxIdLabel">DNI *</label>
              <input name="tax_id" value="<?= e($client['tax_id'] ?? '') ?>" class="form-control" placeholder="DNI / CIF" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" id="clientNameLabel">Nombre completo *</label>
              <input name="client_name" value="<?= e($client['name']) ?>" class="form-control" placeholder="Ej: María Elena González" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Contratista *</label>
              <input name="contratista" value="<?= e($client['contratista']) ?>" class="form-control" placeholder="Ej: Constructora Madrid SL" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Fecha de nacimiento</label>
              <input type="date" name="birth_date" value="<?= e($client['birth_date'] ?? '') ?>" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Dirección *</label>
              <input name="address" value="<?= e($client['address']) ?>" class="form-control" placeholder="Ej: Calle Alcalá 45, 2ºB, 28014 Madrid" required>
            </div>
            <div class="col-12">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="toggleExtraAddresses">
                <i class="bi bi-plus-circle me-1"></i>Añadir dirección fiscal / comunicaciones
              </button>
            </div>
            <div id="extraAddresses" style="display:none;" class="col-12">
              <div class="row g-3">
                <div class="col-12">
                  <label class="form-label">Dirección fiscal</label>
                  <input name="fiscal_address" value="<?= e($client['fiscal_address'] ?? '') ?>" class="form-control" placeholder="Ej: Calle Alcalá 45, 2ºB, 28014 Madrid">
                </div>
                <div class="col-12">
                  <label class="form-label">Dirección comunicaciones</label>
                  <input name="comms_address" value="<?= e($client['comms_address'] ?? '') ?>" class="form-control" placeholder="Ej: Calle Alcalá 45, 2ºB, 28014 Madrid">
                </div>
              </div>
            </div>
          </div>

          <div class="section-divider"><span><i class="bi bi-bank me-2"></i>Datos bancarios</span></div>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">IBAN</label>
              <input name="iban" value="<?= e($client['iban'] ?? '') ?>" class="form-control" placeholder="Ej: ES00 0000 0000 0000 0000 0000" maxlength="34">
            </div>
          </div>

          <div class="section-divider"><span><i class="bi bi-person-badge me-2"></i>Agente comercial</span></div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nombre del agente</label>
              <input name="agent_name" value="<?= e($client['agent_name'] ?? '') ?>" class="form-control" placeholder="Ej: Susana Parla García">
            </div>
            <div class="col-md-6">
              <label class="form-label">Correo del agente</label>
              <input type="email" name="agent_email" value="<?= e($client['agent_email'] ?? '') ?>" class="form-control" placeholder="agente@empresa.com">
            </div>
          </div>
        </div>
      </div>

      <div class="card mt-4">
        <div class="card-header card-header-colored" style="--hdr-color:#10b981;">
          <div class="d-flex align-items-center gap-3">
            <div class="card-hdr-icon"><i class="bi bi-file-earmark-text"></i></div>
            <strong>Información del contrato</strong>
          </div>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Fecha del contrato *</label>
              <input type="date" name="contract_date" value="<?= e($contract['contract_date']) ?>" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label">Tipo de contrato *</label>
              <select name="energy_type" id="energyType" class="form-select" required>
                <option value="">Seleccionar</option>
                <option value="gas" <?= ($contract['energy_type'] ?? '') === 'gas' ? 'selected' : '' ?>>Gas</option>
                <option value="electricidad" <?= ($contract['energy_type'] ?? '') === 'electricidad' ? 'selected' : '' ?>>Electricidad</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Tarifa *</label>
              <select name="tariff_type" id="tariffType" class="form-select" data-current="<?= e($contract['tariff_type'] ?? '') ?>" required>
                <option value="">Selecciona primero el tipo</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Compañía</label>
              <input name="company" value="<?= e($contract['company'] ?? '') ?>" class="form-control" placeholder="Ej: Repsol">
            </div>
            <div class="col-md-6">
              <label class="form-label">CUPS</label>
              <input name="cups" value="<?= e($contract['cups'] ?? '') ?>" class="form-control" placeholder="Ej: ES0021000009117716LF">
            </div>
            <div class="col-md-4">
              <label class="form-label">Consumo anual</label>
              <input name="annual_consumption" value="<?= e($contract['annual_consumption'] ?? '') ?>" class="form-control" placeholder="Ej: 3.068 kWh">
            </div>
            <div class="col-md-4">
              <label class="form-label">Potencia P1</label>
              <input name="power_p1" value="<?= e($contract['power_p1'] ?? '') ?>" class="form-control" placeholder="Ej: 3,3 kW">
            </div>
            <div class="col-md-4">
              <label class="form-label">Potencia P2</label>
              <input name="power_p2" value="<?= e($contract['power_p2'] ?? '') ?>" class="form-control" placeholder="Ej: 3,3 kW">
            </div>
            <div class="col-12">
              <label class="form-label">Producto</label>
              <input name="product" value="<?= e($contract['product'] ?? '') ?>" class="form-control" placeholder="Ej: PRECIO FIJO SBC NUEVO 12M V32">
            </div>
            <div class="col-md-4">
              <label class="form-label">Fecha de venta</label>
              <input type="date" name="sale_date" value="<?= e($contract['sale_date'] ?? '') ?>" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Fecha de activación</label>
              <input type="date" name="activation_date" value="<?= e($contract['activation_date'] ?? '') ?>" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Fecha de inicio</label>
              <input type="date" name="start_date" value="<?= e($contract['start_date'] ?? '') ?>" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Fecha de baja</label>
              <input type="date" name="end_date" value="<?= e($contract['end_date'] ?? '') ?>" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label">Factura a papel</label>
              <select name="invoice_paper" class="form-select">
                <option value="0" <?= empty($contract['invoice_paper']) ? 'selected' : '' ?>>No</option>
                <option value="1" <?= !empty($contract['invoice_paper']) ? 'selected' : '' ?>>Sí</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Contrato a papel</label>
              <select name="contract_paper" class="form-select">
                <option value="0" <?= empty($contract['contract_paper']) ? 'selected' : '' ?>>No</option>
                <option value="1" <?= !empty($contract['contract_paper']) ? 'selected' : '' ?>>Sí</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Notas adicionales</label>
              <textarea name="notes" class="form-control" rows="3" placeholder="Observaciones, condiciones especiales, etc."><?= e($contract['notes']) ?></textarea>
            </div>
          </div>
          <?php if ($id && !empty($contract['created_at'])): ?>
          <hr class="my-3">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label text-muted">Fecha de creación</label>
              <input class="form-control" value="<?= e($contract['created_at']) ?>" readonly disabled>
            </div>
            <div class="col-md-6">
              <label class="form-label text-muted">Fecha de actualización</label>
              <input class="form-control" value="<?= e($contract['updated_at']) ?>" readonly disabled>
            </div>
          </div>
          <?php endif; ?>

          <div class="section-divider" style="--sd-color:#f59e0b;"><span><i class="bi bi-magic me-2"></i>Subir contrato PDF</span></div>
          <p class="text-muted small mb-3">El sistema extraerá automáticamente nombre, dirección, fecha y contactos.</p>
          <div class="input-group">
            <input type="file" name="document" id="pdfFile" class="form-control" accept="application/pdf"
                   <?= empty($contract['document_path']) ? 'required' : '' ?>>
            <span class="input-group-text d-none" id="pdfSpinner">
              <span class="spinner-border spinner-border-sm"></span>
            </span>
          </div>
          <?php if (empty($contract['document_path'])): ?>
            <small style="color: var(--on-surface-variant); font-size: 0.75rem;">
              <i class="bi bi-asterisk" style="color: #dc2626; font-size: 0.5rem;"></i>
              Obligatorio. Solo se aceptan archivos PDF.
            </small>
          <?php endif; ?>
          <div id="pdfResult" class="form-text mt-2"></div>
          <?php if (!empty($contract['document_path'])): ?>
            <div class="d-flex align-items-center justify-content-between gap-2 mt-3 p-3" style="background: #fef2f2; border: 1px solid #fecaca; border-radius: var(--radius-md);">
              <div class="d-flex align-items-center gap-2 text-truncate">
                <i class="bi bi-file-earmark-pdf-fill" style="color: #dc2626; font-size: 1.5rem;"></i>
                <div class="text-truncate">
                  <div style="font-size: 0.8125rem; font-weight: 600; color: var(--on-surface);" class="text-truncate">
                    <?= e($contract['document_name']) ?>
                  </div>
                  <small style="color: var(--on-surface-variant); font-size: 0.75rem;">PDF actualmente asociado</small>
                </div>
              </div>
              <div class="d-flex gap-1 flex-shrink-0">
                <a href="document.php?id=<?= (int)$id ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Ver">
                  <i class="bi bi-eye"></i>
                </a>
                <a href="document.php?id=<?= (int)$id ?>&download=1" class="btn btn-sm btn-outline-secondary" title="Descargar">
                  <i class="bi bi-download"></i>
                </a>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card mt-4">
        <div class="card-header card-header-colored" style="--hdr-color:#f59e0b;">
          <div class="d-flex align-items-center gap-3">
            <div class="card-hdr-icon"><i class="bi bi-folder2-open"></i></div>
            <strong>Documentación requerida</strong>
          </div>
        </div>
        <div class="card-body">
          <div class="row g-3" id="documentFields">
            <?php foreach ($documentLabels as $docKey => $docLabel): ?>
              <div class="col-md-6 doc-field" data-doc-client="<?= in_array($docKey, ['company_deeds','power_of_attorney','representative_dni','bank_certificate'], true) ? 'empresa' : 'both' ?>">
                <label class="form-label"><?= e($docLabel) ?> <?= $docKey !== 'other' ? '*' : '' ?></label>
                <input type="file" name="documents[<?= e($docKey) ?>]<?= $docKey === 'other' ? '[]' : '' ?>" class="form-control document-input" accept="application/pdf,image/*" <?= $docKey !== 'other' ? 'data-required-doc="1"' : '' ?> <?= $docKey === 'other' ? 'multiple' : '' ?>>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if ($documents): ?>
            <hr>
            <div class="small text-muted mb-2">Documentos ya subidos</div>
            <div class="list-group">
              <?php foreach ($documents as $doc): ?>
                <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="<?= e(UPLOAD_WEB_PATH . $doc['document_path']) ?>" target="_blank">
                  <span><?= e($documentLabels[$doc['document_type']] ?? $doc['document_type']) ?> - <?= e($doc['document_name']) ?></span>
                  <i class="bi bi-box-arrow-up-right"></i>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-header card-header-colored" style="--hdr-color:#0ea5e9;">
          <div class="d-flex align-items-center gap-3">
            <div class="card-hdr-icon"><i class="bi bi-envelope"></i></div>
            <strong>Emails de contacto</strong>
          </div>
        </div>
        <div class="card-body">
          <p class="text-muted small mb-3">
            Al menos <strong>1 email obligatorio</strong>. Marca con <i class="bi bi-star-fill text-warning"></i> el favorito (se usará en las notificaciones).
          </p>
          <div id="contactsWrap">
            <?php
            $favIndex = 0;
            foreach ($contacts as $i => $c) {
                if (!empty($c['is_primary'])) { $favIndex = $i; break; }
            }
            foreach ($contacts as $i => $c): ?>
              <div class="contact-row" data-row>
                <label class="fav-toggle" title="Marcar como favorito">
                  <input type="radio" name="primary_contact" value="<?= $i ?>" <?= $i === $favIndex ? 'checked' : '' ?>>
                  <i class="bi bi-star-fill"></i>
                </label>
                <input type="hidden" name="contact_type[]" value="email">
                <input name="contact_label[]" value="<?= e($c['label']??'') ?>" class="form-control form-control-sm" placeholder="Etiqueta">
                <input type="email" name="contact_value[]" value="<?= e($c['value']??'') ?>" class="form-control form-control-sm" placeholder="email@ejemplo.com"
                       <?= $i === 0 ? 'required' : '' ?>>
                <button type="button" class="btn btn-sm btn-outline-danger" data-remove><i class="bi bi-x"></i></button>
              </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-outline-primary w-100 mt-3" id="addContact">
            <i class="bi bi-plus-lg me-1"></i>Añadir contacto
          </button>
        </div>
      </div>

      <div class="card mt-4" style="border:2px solid #6366f1;">
        <div class="card-body">
          <div class="d-grid gap-2">
            <button class="btn btn-lg fw-semibold" style="background:var(--primary); color:var(--on-primary);">
              <i class="bi bi-check-lg me-2"></i>Guardar contrato
            </button>
            <a href="contracts.php" class="btn btn-outline-secondary">
              <i class="bi bi-x-lg me-2"></i>Cancelar
            </a>
          </div>
          <?php if ($id): ?>
            <hr>
            <div class="text-center">
              <small class="text-muted">ID del contrato: #<?= $id ?></small>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</form>

<template id="tplContact">
  <div class="contact-row" data-row>
    <label class="fav-toggle" title="Marcar como favorito">
      <input type="radio" name="primary_contact" value="">
      <i class="bi bi-star-fill"></i>
    </label>
    <input type="hidden" name="contact_type[]" value="email">
    <input name="contact_label[]" class="form-control form-control-sm" placeholder="Etiqueta">
    <input type="email" name="contact_value[]" class="form-control form-control-sm" placeholder="email@ejemplo.com">
    <button type="button" class="btn btn-sm btn-outline-danger" data-remove><i class="bi bi-x"></i></button>
  </div>
</template>

<style>
/* === Page header icon === */
.section-icon-lg {
  width: 48px; height: 48px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: 1.4rem; flex-shrink: 0;
}

/* === Card header with color accent === */
.card-header-colored {
  background: color-mix(in srgb, var(--hdr-color) 12%, white);
  border-bottom: 2px solid color-mix(in srgb, var(--hdr-color) 30%, white);
  padding: .75rem 1.25rem;
}
.card-header-colored strong {
  color: color-mix(in srgb, var(--hdr-color) 80%, black);
  font-size: .95rem;
}
.card-hdr-icon {
  width: 32px; height: 32px; border-radius: 8px;
  background: var(--hdr-color);
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-size: 1rem; flex-shrink: 0;
}

/* === Section dividers inside card === */
.section-divider {
  --sd-color: #6366f1;
  display: flex; align-items: center; gap: .5rem;
  margin: 1.25rem 0 .75rem;
}
.section-divider::before,
.section-divider::after {
  content: ''; flex: 1; height: 1px;
  background: color-mix(in srgb, var(--sd-color) 25%, #e5e7eb);
}
.section-divider span {
  white-space: nowrap; font-size: .8rem; font-weight: 600;
  color: color-mix(in srgb, var(--sd-color) 70%, #374151);
  background: color-mix(in srgb, var(--sd-color) 8%, white);
  border: 1px solid color-mix(in srgb, var(--sd-color) 20%, #e5e7eb);
  border-radius: 999px; padding: .15rem .75rem;
}

/* === Fav toggle === */
.fav-toggle {
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  transition: all 0.15s ease;
  flex-shrink: 0;
}
.fav-toggle input { position: absolute; opacity: 0; pointer-events: none; }
.fav-toggle i { color: #d1d5db; font-size: 1rem; transition: color 0.15s, transform 0.15s; }
.fav-toggle:hover i { color: #fbbf24; transform: scale(1.15); }
.fav-toggle input:checked ~ i {
  color: #f59e0b;
  filter: drop-shadow(0 0 3px rgba(245, 158, 11, 0.4));
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script>
$(function(){
  const tariffOptions = {
    gas: ['RL-1', 'RL-2', 'RL-3', 'RL-4'],
    electricidad: ['2.0 TD', '3.0 TD', '6.1 TD', '6.2 TD']
  };

  function syncClientType() {
    const type = $('#clientType').val();
    $('#taxIdLabel').text(type === 'empresa' ? 'CIF *' : 'DNI *');
    $('[name=tax_id]').attr('placeholder', type === 'empresa' ? 'CIF' : 'DNI');
    $('#clientNameLabel').text(type === 'empresa' ? 'Razón social *' : 'Nombre completo *');
    $('[name=client_name]').attr('placeholder', type === 'empresa' ? 'Ej: Empresa Madrid SL' : 'Ej: María Elena González');
    $('.doc-field').each(function(){
      const target = $(this).data('doc-client');
      const show = target === 'both' || target === type;
      $(this).toggle(show);
      $(this).find('input[type=file]').prop('disabled', !show);
    });
  }

  function syncTariffs() {
    const energy = $('#energyType').val();
    const current = $('#tariffType').data('current');
    const options = tariffOptions[energy] || [];
    $('#tariffType').empty().append('<option value="">Seleccionar</option>');
    options.forEach(function(option){
      $('#tariffType').append($('<option>', {
        value: option,
        text: option,
        selected: option === current
      }));
    });
  }

  // Toggle dirección fiscal / comunicaciones
  $('#toggleExtraAddresses').on('click', function(){
    const $panel = $('#extraAddresses');
    $panel.toggle();
    $(this).find('i').toggleClass('bi-plus-circle bi-dash-circle');
  });
  // Si ya hay valores al editar, mostrar el panel abierto
  if ($('[name=fiscal_address]').val() || $('[name=comms_address]').val()) {
    $('#extraAddresses').show();
    $('#toggleExtraAddresses i').removeClass('bi-plus-circle').addClass('bi-dash-circle');
  }

  $('#clientType').on('change', syncClientType);
  $('#energyType').on('change', function(){
    $('#tariffType').data('current', '');
    syncTariffs();
  });
  syncClientType();
  syncTariffs();

  // Reasignar valores de los radios según su posición en el DOM
  function reindexFavorites() {
    $('#contactsWrap [data-row]').each(function(i){
      $(this).find('input[type=radio][name=primary_contact]').val(i);
    });
    // Si no hay ninguno marcado, marca el primero
    if (!$('input[type=radio][name=primary_contact]:checked').length) {
      $('#contactsWrap input[type=radio][name=primary_contact]').first().prop('checked', true);
    }
  }

  $('#addContact').on('click', function(){
    const tpl = document.getElementById('tplContact').content.cloneNode(true);
    $('#contactsWrap').append(tpl);
    reindexFavorites();
  });
  $('#contactsWrap').on('click', '[data-remove]', function(){
    const row = $(this).closest('[data-row]');
    const wasChecked = row.find('input[type=radio]:checked').length > 0;
    if ($('#contactsWrap [data-row]').length > 1) {
      row.remove();
    } else {
      row.find('input[type=text], input:not([type=radio]), select').val('');
    }
    reindexFavorites();
  });

  // Auto-parseo al seleccionar PDF
  $('#pdfFile').on('change', function(){
    const f = this.files && this.files[0];
    $('#pdfResult').empty();
    if (!f) return;

    const fd = new FormData();
    fd.append('file', f);
    $('#pdfSpinner').removeClass('d-none');

    fetch('api/v1/parse-pdf', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        $('#pdfSpinner').addClass('d-none');
        if (data.error) {
          $('#pdfResult').html('<span class="text-danger">'+data.error+'</span>');
          return;
        }
        const f = data.fields || {};
        const detected = Object.keys(f).filter(k => f[k]);
        if (!detected.length) {
          $('#pdfResult').html('<span class="text-muted">No se detectaron campos (source='+data.source+').</span>');
          return;
        }
        // Rellenar sólo campos vacíos para no pisar lo ya escrito.
        if (f.name    && !$('[name=client_name]').val())   $('[name=client_name]').val(f.name);
        if (f.address && !$('[name=address]').val())       $('[name=address]').val(f.address);
        if (f.date    && !$('[name=contract_date]').val()) $('[name=contract_date]').val(f.date);

        // Contactos: añadir si no existen ya.
        function ensureContact(type, value) {
          if (!value) return;
          const exists = $('[name="contact_value[]"]').toArray().some(i => i.value.trim() === value.trim());
          if (exists) return;
          const empty = $('[name="contact_value[]"]').filter((i,el) => !el.value).first();
          if (empty.length) {
            empty.val(value);
            empty.closest('[data-row]').find('[name="contact_type[]"]').val(type);
          } else {
            $('#addContact').trigger('click');
            const row = $('#contactsWrap [data-row]').last();
            row.find('[name="contact_type[]"]').val(type);
            row.find('[name="contact_value[]"]').val(value);
          }
        }
        ensureContact('email', f.email);
        ensureContact('phone', f.phone);

        $('#pdfResult').html(
          '<span class="text-success">Autocompletado desde PDF (source='+data.source+'): '
          + detected.join(', ') + '. Revisa los valores antes de guardar.</span>'
        );
      })
      .catch(err => {
        $('#pdfSpinner').addClass('d-none');
        $('#pdfResult').html('<span class="text-danger">Error analizando PDF.</span>');
      });
  });
});

// ── Helpers de validación cliente ─────────────────────────────────────────
const nameRegex    = /^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s'\-.]{2,200}$/;
const addressRegex = /^[a-zA-Z0-9áéíóúÁÉÍÓÚñÑüÜ\s'\-.,#ºª\/]{5,300}$/;
const emailRegex   = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const dateRegex    = /^\d{4}-\d{2}-\d{2}$/;

function validateDniJs(v) {
  v = v.toUpperCase().replace(/[\s-]/g,'');
  if (!/^\d{8}[A-Z]$/.test(v)) return false;
  return 'TRWAGMYFPDXBNJZSQVHLCKE'[parseInt(v.slice(0,8)) % 23] === v[8];
}
function validateNieJs(v) {
  v = v.toUpperCase().replace(/[\s-]/g,'');
  if (!/^[XYZ]\d{7}[A-Z]$/.test(v)) return false;
  const n = ({X:'0',Y:'1',Z:'2'}[v[0]]||'0') + v.slice(1,8);
  return 'TRWAGMYFPDXBNJZSQVHLCKE'[parseInt(n) % 23] === v[8];
}
function validateCifJs(v) {
  v = v.toUpperCase().replace(/[\s-]/g,'');
  return /^[ABCDEFGHJKLMNPQRSVWX]\d{7}[0-9A-J]$/.test(v);
}
function validateCupsJs(v) {
  v = v.replace(/\s/g,'');
  return v === '' || /^ES\d{16}[A-Z]{2}(\d[FPRCXYZ])?$/i.test(v);
}
function setFieldState($el, isValid, msg) {
  $el.removeClass('is-invalid is-valid');
  $el.siblings('.invalid-feedback,.valid-feedback').remove();
  if (isValid === true)  { $el.addClass('is-valid'); }
  if (isValid === false) { $el.addClass('is-invalid'); $el.after('<div class="invalid-feedback">' + msg + '</div>'); }
}
// ───────────────────────────────────────────────────────────────────────────

// Validación en tiempo real
$('[name=client_name]').on('input', function() {
  const v = $(this).val().trim();
  setFieldState($(this), v ? nameRegex.test(v) : null, 'Solo letras, espacios y caracteres comunes (2-200 chars)');
});
$('[name=contratista]').on('input', function() {
  const v = $(this).val().trim();
  setFieldState($(this), v ? nameRegex.test(v) : null, 'Solo letras, espacios y caracteres comunes');
});
$('[name=address], [name=fiscal_address], [name=comms_address]').on('input', function() {
  const v = $(this).val().trim();
  setFieldState($(this), v ? addressRegex.test(v) : null, 'Dirección contiene caracteres no válidos (5-300 chars)');
});
$('[name=tax_id]').on('input', function() {
  const v = $(this).val().trim();
  if (!v) return setFieldState($(this), null);
  const type = $('#clientType').val();
  const ok = type === 'empresa' ? validateCifJs(v) : (validateDniJs(v) || validateNieJs(v));
  const msg = type === 'empresa' ? 'CIF inválido (ej: B12345678)' : 'DNI/NIE inválido o letra de control incorrecta';
  setFieldState($(this), ok, msg);
});
$('#clientType').on('change', function() {
  $('[name=tax_id]').trigger('input');
});
$('[name=cups]').on('input', function() {
  const v = $(this).val().trim();
  setFieldState($(this), v ? validateCupsJs(v) : null, 'CUPS inválido (ej: ES0021000009117716LF)');
});
$('[name=agent_email]').on('input', function() {
  const v = $(this).val().trim();
  setFieldState($(this), v ? emailRegex.test(v) : null, 'Email del agente no válido');
});
$('[name=iban]').on('input', function() {
  const v = $(this).val().replace(/\s/g,'').toUpperCase();
  setFieldState($(this), v ? /^[A-Z]{2}\d{2}[A-Z0-9]{1,30}$/.test(v) : null, 'IBAN inválido (ej: ES00 0000 0000 0000 0000 0000)');
});

// Coherencia de fechas en tiempo real
function checkDateCoherence() {
  const sale   = $('[name=sale_date]').val();
  const activ  = $('[name=activation_date]').val();
  const start  = $('[name=start_date]').val();
  const end    = $('[name=end_date]').val();
  const birth  = $('[name=birth_date]').val();
  const today  = new Date(); today.setHours(0,0,0,0);

  if (sale && activ && new Date(activ) < new Date(sale)) {
    setFieldState($('[name=activation_date]'), false, 'No puede ser anterior a la fecha de venta');
  } else { setFieldState($('[name=activation_date]'), null); }

  if (start && end && new Date(end) <= new Date(start)) {
    setFieldState($('[name=end_date]'), false, 'Debe ser posterior a la fecha de inicio');
  } else { setFieldState($('[name=end_date]'), null); }

  if (birth) {
    const bd = new Date(birth);
    if (bd > today) setFieldState($('[name=birth_date]'), false, 'No puede ser una fecha futura');
    else if (bd < new Date('1900-01-01')) setFieldState($('[name=birth_date]'), false, 'No puede ser anterior a 1900');
    else setFieldState($('[name=birth_date]'), null);
  }
}
$('[name=sale_date],[name=activation_date],[name=start_date],[name=end_date],[name=birth_date]').on('change', checkDateCoherence);

// Emails de contacto en tiempo real (delegado para filas añadidas dinámicamente)
$('#contactsWrap').on('input', '[name="contact_value[]"]', function() {
  const v = $(this).val().trim();
  setFieldState($(this), v ? emailRegex.test(v) : null, 'Email no válido');
});

// Prevenir múltiples submits + validación al enviar
$('#contractForm').on('submit', function(e) {
  const $form = $(this);
  const $submitBtn = $form.find('button[type="submit"]');
  const errors = [];

  const clientName  = $('[name=client_name]').val().trim();
  const address     = $('[name=address]').val().trim();
  const contractDate = $('[name=contract_date]').val().trim();
  const taxId       = $('[name=tax_id]').val().trim();
  const clientType  = $('#clientType').val();
  const cups        = $('[name=cups]').val().trim();
  const agentEmail  = $('[name=agent_email]').val().trim();
  const saleDate    = $('[name=sale_date]').val();
  const activDate   = $('[name=activation_date]').val();
  const startDate   = $('[name=start_date]').val();
  const endDate     = $('[name=end_date]').val();
  const birthDate   = $('[name=birth_date]').val();
  const today       = new Date(); today.setHours(0,0,0,0);

  if (!clientName || !nameRegex.test(clientName))
    errors.push('Nombre del cliente obligatorio y con formato válido');
  if (!address || !addressRegex.test(address))
    errors.push('Dirección obligatoria y con formato válido');
  if (!contractDate || !dateRegex.test(contractDate))
    errors.push('Fecha del contrato obligatoria (YYYY-MM-DD)');

  if (!taxId) {
    errors.push(clientType === 'empresa' ? 'CIF obligatorio' : 'DNI obligatorio');
  } else if (clientType === 'empresa' && !validateCifJs(taxId)) {
    errors.push('CIF inválido');
  } else if (clientType !== 'empresa' && !validateDniJs(taxId) && !validateNieJs(taxId)) {
    errors.push('DNI/NIE inválido o letra de control incorrecta');
  }

  if (!validateCupsJs(cups)) errors.push('CUPS inválido');
  if (agentEmail && !emailRegex.test(agentEmail)) errors.push('Correo del agente inválido');

  if (birthDate) {
    const bd = new Date(birthDate);
    if (bd > today) errors.push('Fecha de nacimiento no puede ser futura');
    if (bd < new Date('1900-01-01')) errors.push('Fecha de nacimiento anterior a 1900');
  }
  if (saleDate && activDate && new Date(activDate) < new Date(saleDate))
    errors.push('Fecha de activación no puede ser anterior a la de venta');
  if (startDate && endDate && new Date(endDate) <= new Date(startDate))
    errors.push('Fecha de baja debe ser posterior a la de inicio');

  const primaryEmail = $('[name="contact_value[]"]').first().val().trim();
  if (!primaryEmail) errors.push('Debe añadir al menos un email de contacto');
  else if (!emailRegex.test(primaryEmail)) errors.push('El primer email de contacto no es válido');

  $('.doc-field:visible input[data-required-doc="1"]').each(function(){
    if (!this.files || this.files.length === 0)
      errors.push('Debe subir: ' + $(this).closest('.doc-field').find('label').text().replace('*','').trim());
  });

  if (errors.length > 0) {
    e.preventDefault();
    alert('Por favor, corrija los siguientes errores:\n\n• ' + errors.join('\n• '));
    return false;
  }

  // Rate-limit cliente: no dos envíos en < 2s
  if (window.formSubmitTime && (Date.now() - window.formSubmitTime) < 2000) {
    e.preventDefault();
    alert('Por favor, espere unos segundos antes de enviar de nuevo.');
    return false;
  }
  window.formSubmitTime = Date.now();

  $submitBtn.prop('disabled', true)
    .html('<span class="spinner-border spinner-border-sm me-2"></span>Guardando...')
    .addClass('disabled');
  setTimeout(() => {
    $submitBtn.prop('disabled', false)
      .html('<i class="bi bi-check-lg me-2"></i>Guardar contrato')
      .removeClass('disabled');
  }, 10000);
});
</script>
