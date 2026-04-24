<?php
require_once __DIR__ . '/../config/db.php';
$pageTitle = 'Contrato';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$contract = ['contract_date'=>'', 'notes'=>'', 'document_path'=>null, 'document_name'=>null];
$client   = ['name'=>'', 'address'=>'', 'contratista'=>''];
$contacts = [];

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
}
if (!$contacts) {
    $contacts = [['type'=>'phone','label'=>'','value'=>'']];
}

include __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-1"><?= $id ? 'Editar contrato' : 'Nuevo contrato' ?></h1>
    <p class="text-muted mb-0">Completa los datos del cliente y la información del contrato</p>
  </div>
  <a href="contracts.php" class="btn btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Volver
  </a>
</div>

<form method="post" action="contract_save.php" enctype="multipart/form-data" id="contractForm">
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <?= Security::csrfField() ?>

  <!-- PDF upload PRIMERO - autocompleta el resto del formulario -->
  <div class="card mb-4" style="border: 2px dashed var(--primary); background: var(--primary-fixed);">
    <div class="card-body">
      <div class="d-flex align-items-center gap-3 mb-3">
        <div class="icon-box primary" style="width: 48px; height: 48px; font-size: 1.5rem;">
          <i class="bi bi-magic"></i>
        </div>
        <div>
          <h2 style="font-size: 1rem; font-weight: 600; margin: 0;">Subir contrato PDF</h2>
          <small style="color: var(--on-surface-variant); font-size: 0.8125rem;">
            El sistema extraerá automáticamente nombre, dirección, fecha y contactos
          </small>
        </div>
      </div>
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

  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-person-circle text-primary"></i>
            <strong>Datos del cliente</strong>
          </div>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nombre completo *</label>
              <input name="client_name" value="<?= e($client['name']) ?>" class="form-control" placeholder="Ej: María Elena González" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Contratista *</label>
              <input name="contratista" value="<?= e($client['contratista']) ?>" class="form-control" placeholder="Ej: Constructora Madrid SL" required>
            </div>
            <div class="col-12">
              <label class="form-label">Dirección completa *</label>
              <input name="address" value="<?= e($client['address']) ?>" class="form-control" placeholder="Ej: Calle Alcalá 45, 2ºB, 28014 Madrid" required>
            </div>
          </div>
        </div>
      </div>

      <div class="card mt-4">
        <div class="card-header">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-file-earmark-text text-success"></i>
            <strong>Información del contrato</strong>
          </div>
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Fecha del contrato *</label>
              <input type="date" name="contract_date" value="<?= e($contract['contract_date']) ?>" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label">Notas adicionales</label>
              <textarea name="notes" class="form-control" rows="3" placeholder="Observaciones, condiciones especiales, etc."><?= e($contract['notes']) ?></textarea>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-header">
          <div class="d-flex align-items-center gap-2">
            <i class="bi bi-telephone text-info"></i>
            <strong>Métodos de contacto</strong>
          </div>
        </div>
        <div class="card-body">
          <p class="text-muted small mb-3">
            Al menos <strong>1 obligatorio</strong>. Marca con <i class="bi bi-star-fill text-warning"></i> el favorito (se usará en las notificaciones).
          </p>
          <div id="contactsWrap">
            <?php
            // Determinar el índice favorito: el primero marcado como is_primary, o 0 por defecto
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
                <select name="contact_type[]" class="form-select form-select-sm" style="max-width:110px">
                  <?php foreach (['phone'=>'Teléfono','email'=>'Email','whatsapp'=>'WhatsApp','other'=>'Otros'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= ($c['type']??'')===$v?'selected':'' ?>><?= $l ?></option>
                  <?php endforeach; ?>
                </select>
                <input name="contact_label[]" value="<?= e($c['label']??'') ?>" class="form-control form-control-sm" placeholder="Etiqueta" style="max-width:100px">
                <input name="contact_value[]" value="<?= e($c['value']??'') ?>" class="form-control form-control-sm" placeholder="Valor *"
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

      <div class="card mt-4 border-primary">
        <div class="card-body">
          <div class="d-grid gap-2">
            <button class="btn btn-primary btn-lg">
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
    <select name="contact_type[]" class="form-select form-select-sm" style="max-width:110px">
      <option value="phone">Teléfono</option>
      <option value="email">Email</option>
      <option value="whatsapp">WhatsApp</option>
      <option value="other">Otros</option>
    </select>
    <input name="contact_label[]" class="form-control form-control-sm" placeholder="Etiqueta" style="max-width:100px">
    <input name="contact_value[]" class="form-control form-control-sm" placeholder="Valor">
    <button type="button" class="btn btn-sm btn-outline-danger" data-remove><i class="bi bi-x"></i></button>
  </div>
</template>

<style>
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

// Prevenir múltiples submits y validación mejorada
$('#contractForm').on('submit', function(e) {
  const $form = $(this);
  const $submitBtn = $form.find('button[type="submit"]');
  
  // Validación básica
  const clientName = $('[name=client_name]').val().trim();
  const contractor = $('[name=contratista]').val().trim();
  const address = $('[name=address]').val().trim();
  const contractDate = $('[name=contract_date]').val().trim();
  
  const errors = [];
  
  // Validación de nombre (solo letras, espacios, y caracteres comunes)
  const nameRegex = /^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s\'\-\.]{2,100}$/;
  const addressRegex = /^[a-zA-Z0-9áéíóúÁÉÍÓÚñÑüÜ\s\'\-\.,#ºª\/]{5,200}$/;
  const dateRegex = /^\d{4}-\d{2}-\d{2}$/;
  
  if (!clientName || !nameRegex.test(clientName)) {
    errors.push('El nombre del cliente es obligatorio y debe tener un formato válido');
  }
  
  if (!contractor || !nameRegex.test(contractor)) {
    errors.push('El nombre del contratista es obligatorio y debe tener un formato válido');
  }
  
  if (!address || !addressRegex.test(address)) {
    errors.push('La dirección es obligatoria y debe tener un formato válido');
  }
  
  if (!contractDate || !dateRegex.test(contractDate)) {
    errors.push('La fecha del contrato es obligatoria y debe tener un formato válido');
  }
  
  // Validar contacto principal
  const primaryContact = $('[name="contact_value[]"]').first().val().trim();
  if (!primaryContact) {
    errors.push('Debe añadir al menos un método de contacto');
  }
  
  if (errors.length > 0) {
    e.preventDefault();
    alert('Por favor, corrija los siguientes errores:\n\n' + errors.join('\n'));
    return false;
  }
  
  // Deshabilitar botón para prevenir múltiples submits
  $submitBtn.prop('disabled', true)
    .html('<span class="spinner-border spinner-border-sm me-2"></span>Guardando...')
    .addClass('disabled');
  
  // Rate limiting del lado del cliente
  if (window.formSubmitTime && (Date.now() - window.formSubmitTime) < 2000) {
    e.preventDefault();
    $submitBtn.prop('disabled', false)
      .html('Guardar contrato')
      .removeClass('disabled');
    alert('Por favor, espere unos segundos antes de enviar el formulario nuevamente.');
    return false;
  }
  
  window.formSubmitTime = Date.now();
  
  // Rehabilitar botón después de 10 segundos (por si falla el submit)
  setTimeout(() => {
    $submitBtn.prop('disabled', false)
      .html('Guardar contrato')
      .removeClass('disabled');
  }, 10000);
});

// Validación en tiempo real
const nameRegex = /^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ\s\'\-\.]{2,100}$/;
const addressRegex = /^[a-zA-Z0-9áéíóúÁÉÍÓÚñÑüÜ\s\'\-\.,#ºª\/]{5,200}$/;
const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const phoneRegex = /^(\+34)?[6-9]\d{8}$/;

$('[name=client_name]').on('input', function() {
  const val = $(this).val().trim();
  if (val && !nameRegex.test(val)) {
    $(this).addClass('is-invalid');
    if (!$(this).siblings('.invalid-feedback').length) {
      $(this).after('<div class="invalid-feedback">El nombre solo puede contener letras, espacios y caracteres comunes</div>');
    }
  } else {
    $(this).removeClass('is-invalid');
    $(this).siblings('.invalid-feedback').remove();
  }
});

$('[name=contratista]').on('input', function() {
  const val = $(this).val().trim();
  if (val && !nameRegex.test(val)) {
    $(this).addClass('is-invalid');
    if (!$(this).siblings('.invalid-feedback').length) {
      $(this).after('<div class="invalid-feedback">El nombre solo puede contener letras, espacios y caracteres comunes</div>');
    }
  } else {
    $(this).removeClass('is-invalid');
    $(this).siblings('.invalid-feedback').remove();
  }
});

$('[name=address]').on('input', function() {
  const val = $(this).val().trim();
  if (val && !addressRegex.test(val)) {
    $(this).addClass('is-invalid');
    if (!$(this).siblings('.invalid-feedback').length) {
      $(this).after('<div class="invalid-feedback">La dirección contiene caracteres no válidos</div>');
    }
  } else {
    $(this).removeClass('is-invalid');
    $(this).siblings('.invalid-feedback').remove();
  }
});

$('[name="contact_value[]"]').on('input', function() {
  const $row = $(this).closest('[data-row]');
  const type = $row.find('[name="contact_type[]"]').val();
  const val = $(this).val().trim();
  
  if (val) {
    if (type === 'email' && !emailRegex.test(val)) {
      $(this).addClass('is-invalid');
      if (!$(this).siblings('.invalid-feedback').length) {
        $(this).after('<div class="invalid-feedback">El email no tiene un formato válido</div>');
      }
    } else if (type === 'phone' && !phoneRegex.test(val.replace(/[^\d+]/g, ''))) {
      $(this).addClass('is-invalid');
      if (!$(this).siblings('.invalid-feedback').length) {
        $(this).after('<div class="invalid-feedback">El teléfono no tiene un formato válido español</div>');
      }
    } else {
      $(this).removeClass('is-invalid');
      $(this).siblings('.invalid-feedback').remove();
    }
  }
});
</script>
