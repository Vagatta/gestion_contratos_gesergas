<?php
require_once __DIR__ . '/../config/db.php';
$pageTitle = 'Calendario';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h1 class="h3 mb-1">Calendario de notificaciones</h1>
    <p class="text-muted mb-0">Vista cronológica de todos los avisos programados</p>
  </div>
</div>

<!-- Filtros + Leyenda -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-body">
        <form class="row g-3" id="calFilters">
          <div class="col-md-5">
            <label class="form-label">Estado</label>
            <select name="status" class="form-select">
              <option value="">Todas las notificaciones</option>
              <option value="pending">Solo pendientes</option>
              <option value="completed">Solo completadas</option>
            </select>
          </div>
          <div class="col-md-7">
            <label class="form-label">Buscar cliente</label>
            <input name="q" class="form-control" placeholder="Filtrar por nombre de cliente...">
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <small style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; color: var(--on-surface-variant); font-weight: 600;">
          Leyenda
        </small>
        <div class="d-flex flex-column gap-2 mt-2">
          <div class="d-flex align-items-center gap-2">
            <span style="width: 14px; height: 14px; border-radius: 4px; background: #ef4444;"></span>
            <span style="font-size: 0.875rem;">Vencida (acción inmediata)</span>
          </div>
          <div class="d-flex align-items-center gap-2">
            <span style="width: 14px; height: 14px; border-radius: 4px; background: #f59e0b;"></span>
            <span style="font-size: 0.875rem;">Pendiente</span>
          </div>
          <div class="d-flex align-items-center gap-2">
            <span style="width: 14px; height: 14px; border-radius: 4px; background: #10b981;"></span>
            <span style="font-size: 0.875rem;">Completada</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Calendario -->
<div class="card">
  <div class="card-body">
    <div id="calendar"></div>
  </div>
</div>

<link  href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>

<style>
/* Overrides FullCalendar con estilo Kinetic Precision */
.fc {
  font-family: 'Inter', sans-serif;
  --fc-border-color: var(--outline-variant);
  --fc-today-bg-color: var(--primary-fixed);
  --fc-page-bg-color: var(--surface-container-lowest);
  --fc-neutral-bg-color: var(--surface-container-low);
}

.fc .fc-toolbar.fc-header-toolbar {
  margin-bottom: 1.5rem;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.fc .fc-toolbar-title {
  font-size: 1.25rem !important;
  font-weight: 600;
  letter-spacing: -0.02em;
  color: var(--on-surface);
}

.fc .fc-button {
  background: var(--surface-container-lowest) !important;
  border: 1px solid var(--outline-variant) !important;
  color: var(--on-surface) !important;
  font-weight: 500;
  font-size: 0.8125rem;
  padding: 0.5rem 0.875rem;
  border-radius: var(--radius-sm) !important;
  box-shadow: none !important;
  text-transform: none;
  transition: all 0.2s ease;
}

.fc .fc-button:hover:not(:disabled) {
  background: var(--surface-container-low) !important;
  border-color: var(--outline) !important;
}

.fc .fc-button-primary:not(:disabled).fc-button-active,
.fc .fc-button-primary:not(:disabled):active {
  background: var(--primary) !important;
  border-color: var(--primary) !important;
  color: white !important;
}

.fc .fc-today-button {
  background: var(--primary) !important;
  border-color: var(--primary) !important;
  color: white !important;
}

.fc .fc-today-button:disabled {
  opacity: 0.5;
}

.fc-daygrid-day {
  transition: background 0.15s ease;
}

.fc-daygrid-day:hover {
  background: var(--surface-bright);
}

.fc .fc-col-header-cell {
  background: var(--surface-container-low);
  padding: 0.75rem 0 !important;
}

.fc .fc-col-header-cell-cushion {
  font-size: 0.6875rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--on-surface-variant);
  padding: 0;
}

.fc .fc-daygrid-day-number {
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--on-surface);
  padding: 0.5rem 0.625rem;
}

.fc-day-today .fc-daygrid-day-number {
  color: var(--primary);
  font-weight: 700;
}

/* Eventos en vista Mes (daygrid): compactos */
.fc-daygrid-event {
  border: none !important;
  border-radius: 4px !important;
  padding: 1px 6px !important;
  font-size: 0.7rem !important;
  font-weight: 500 !important;
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
  margin-bottom: 2px !important;
  line-height: 1.4 !important;
  min-height: 20px;
  display: flex !important;
  align-items: center;
}

.fc-daygrid-event:hover {
  opacity: 0.9;
  box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}

.fc-daygrid-event .fc-event-main {
  overflow: hidden;
  width: 100%;
}

.fc-daygrid-event .fc-event-title {
  overflow: hidden !important;
  text-overflow: ellipsis !important;
  white-space: nowrap !important;
  font-weight: 500 !important;
}

.fc-daygrid-event-dot {
  display: none !important;
}

/* Botón "+N más" */
.fc-daygrid-more-link {
  font-size: 0.7rem !important;
  font-weight: 600 !important;
  color: var(--primary) !important;
  padding: 1px 6px !important;
  border-radius: 4px !important;
  background: var(--primary-fixed) !important;
  margin-top: 2px !important;
  display: inline-block !important;
}

.fc-daygrid-more-link:hover {
  background: var(--primary-fixed-dim) !important;
  text-decoration: none !important;
}

/* Popover de "+N más" */
.fc-popover {
  border: 1px solid var(--outline-variant) !important;
  border-radius: var(--radius-md) !important;
  box-shadow: var(--shadow-md) !important;
  overflow: hidden;
}

.fc-popover-header {
  background: var(--surface-container-low) !important;
  padding: 0.5rem 0.75rem !important;
  font-size: 0.75rem !important;
  font-weight: 600 !important;
}

/* Vista Lista */
.fc-list {
  border-color: var(--outline-variant) !important;
  border-radius: var(--radius-md) !important;
  overflow: hidden;
}

.fc-list-day-cushion {
  background: var(--surface-container-low) !important;
  padding: 0.75rem 1rem !important;
  font-weight: 600;
  font-size: 0.875rem;
}

.fc-list-event {
  cursor: pointer;
}

.fc-list-event:hover td {
  background: var(--surface-bright) !important;
}

.fc-list-event td {
  padding: 0.75rem 1rem !important;
  border-color: var(--outline-variant) !important;
  font-size: 0.875rem !important;
  vertical-align: middle !important;
}

.fc-list-event-time {
  width: 120px;
  color: var(--on-surface-variant) !important;
  font-size: 0.8125rem !important;
}

.fc-list-event-graphic {
  width: 24px !important;
  padding-right: 0 !important;
}

.fc-list-event-dot {
  border-width: 5px !important;
  margin: 0 !important;
}

.fc-list-event-title {
  font-weight: 500 !important;
  color: var(--on-surface) !important;
}

/* Ocultar etiqueta 'all-day' que satura la fila */
.fc-list-event-time:empty,
.fc-list-event .fc-list-event-time {
  white-space: nowrap;
}

.fc-list-empty {
  background: transparent !important;
  color: var(--on-surface-variant) !important;
  padding: 3rem !important;
}

@media (max-width: 640px) {
  .fc .fc-toolbar.fc-header-toolbar {
    flex-direction: column;
    align-items: stretch;
  }
  .fc .fc-toolbar-chunk {
    display: flex;
    justify-content: center;
  }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const el = document.getElementById('calendar');
  const filters = document.getElementById('calFilters');

  const calendar = new FullCalendar.Calendar(el, {
    initialView: 'dayGridMonth',
    locale: 'es',
    firstDay: 1,
    height: 'auto',
    headerToolbar: { left:'prev,next today', center:'title', right:'dayGridMonth,listMonth' },
    buttonText: { today:'Hoy', month:'Mes', list:'Lista' },
    allDayText: '',
    displayEventTime: false,
    noEventsText: 'No hay notificaciones en este rango',
    dayMaxEvents: 2,
    moreLinkText: n => `+${n} más`,
    eventDidMount: function(info) {
      info.el.setAttribute('title', info.event.title);
    },
    events: function(info, success, failure) {
      const params = new URLSearchParams(new FormData(filters));
      params.set('from', info.startStr.substring(0,10));
      params.set('to',   info.endStr.substring(0,10));
      fetch('api/events.php?' + params.toString())
        .then(r => r.json()).then(success).catch(failure);
    },
    eventClick: function(e) {
      if (e.event.extendedProps.contract_id) {
        window.location = 'contract_form.php?id=' + e.event.extendedProps.contract_id;
      }
    }
  });
  calendar.render();

  filters.addEventListener('change', () => calendar.refetchEvents());
  filters.addEventListener('input',  () => calendar.refetchEvents());
});
</script>
