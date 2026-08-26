(() => {
  'use strict';

  const config = window.AvisoHistoricalImportConfig || {};
  const language = config.language === 'en' ? 'en' : 'es';
  const text = (es, en) => window.AppI18n && typeof window.AppI18n.text === 'function'
    ? window.AppI18n.text(es, en)
    : (language === 'en' ? en : es);
  const MAX_FILES = Number(config.maxFiles || 20);
  const MAX_FILE_SIZE_MB = Number(config.maxFileSizeMb || 250);
  const MAX_FILE_SIZE = MAX_FILE_SIZE_MB * 1024 * 1024;

  const MAX_BATCH_SIZE_MB = Number(config.maxBatchSizeMb || 300);
  const MAX_BATCH_SIZE = MAX_BATCH_SIZE_MB * 1024 * 1024;
  const form = document.querySelector('#historical-import-form');
  if (!form) return;

  const fileInput = form.querySelector('#historical-files');
  const dropzone = form.querySelector('[data-import-dropzone]');
  const fileList = form.querySelector('[data-file-list]');
  const submit = form.querySelector('[type="submit"]');
  const feedback = document.querySelector('[data-import-feedback]');
  const resultsSection = document.querySelector('[data-import-results]');
  const tbody = document.querySelector('[data-import-rows]');
  const batchSummary = document.querySelector('[data-batch-summary]');
  const batchClient = document.querySelector('[data-batch-client]');
  const batchOperational = document.querySelector('[data-batch-operational]');
  const batchProgress = document.querySelector('[data-batch-progress]');
  const selectedSummary = document.querySelector('[data-selected-summary]');
  const importSelectedButton = document.querySelector('[data-import-selected]');
  const validateBatchButton = document.querySelector('[data-validate-batch]');
  const validationSection = document.querySelector('[data-validation-results]');
  const validationBadge = document.querySelector('[data-validation-badge]');
  const validationSummary = document.querySelector('[data-validation-summary]');
  const validationBatchChecks = document.querySelector('[data-validation-batch-checks]');
  const validationRows = document.querySelector('[data-validation-rows]');
  const selectAllReadyButton = document.querySelector('[data-select-all-ready]');
  const commitModalElement = document.querySelector('#historicalCommitModal');
  const commitSummary = document.querySelector('[data-commit-summary]');
  const confirmCommitButton = document.querySelector('[data-confirm-commit]');
  const commitModal = commitModalElement && window.bootstrap ? new bootstrap.Modal(commitModalElement) : null;

  const stats = {
    total: document.querySelector('[data-stat-total]'),
    ready: document.querySelector('[data-stat-ready]'),
    review: document.querySelector('[data-stat-review]'),
    duplicate: document.querySelector('[data-stat-duplicate]')
  };

  const state = {
    batch: null,
    rows: [],
    selected: new Set()
  };

  const avisoStatuses = config.avisoStatuses || {
    issued: 'Emitido', presented: 'Presentado', replaced: 'Reemplazado', cancelled: 'Cancelado'
  };

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#039;');

  const escapeAttr = escapeHtml;

  const formatBytes = (bytes) => {
    const value = Number(bytes || 0);
    if (!Number.isFinite(value) || value <= 0) return '—';
    const units = ['B', 'KB', 'MB', 'GB'];
    let size = value;
    let idx = 0;
    while (size >= 1024 && idx < units.length - 1) { size /= 1024; idx += 1; }
    return `${size >= 10 || idx === 0 ? size.toFixed(0) : size.toFixed(1)} ${units[idx]}`;
  };

  const formatQuantity = (value) => {
    const number = Number(value || 0);
    if (!Number.isFinite(number)) return '0';
    return number.toLocaleString(language === 'en' ? 'en-US' : 'es-MX', { maximumFractionDigits: 3 });
  };

  const merchandiseSummary = (row) => {
    const items = Number(row?.item_count || 0);
    const pieces = Number(row?.piece_count || 0);
    const itemLabel = language === 'en' ? (items === 1 ? 'merchandise line' : 'merchandise lines') : (items === 1 ? 'renglón' : 'renglones');
    const pieceLabel = language === 'en' ? (pieces === 1 ? 'piece' : 'pieces') : (pieces === 1 ? 'pieza' : 'piezas');
    return `${items} ${itemLabel} · ${formatQuantity(pieces)} ${pieceLabel}`;
  };

  const toDateTimeLocal = (value, fallbackDate = '') => {
    const raw = String(value || '').trim();
    if (raw) {
      const normalized = raw.replace(' ', 'T');
      return normalized.length >= 16 ? normalized.slice(0, 16) : normalized;
    }
    return fallbackDate ? `${fallbackDate}T00:00` : '';
  };

  const mysqlDateTime = (value) => {
    const raw = String(value || '').trim();
    if (!raw) return null;
    return raw.replace('T', ' ') + (raw.length === 16 ? ':00' : '');
  };

  const setFeedback = (message, type = 'info', html = false) => {
    if (!feedback) return;
    feedback.className = `alert alert-${type}`;
    if (html) feedback.innerHTML = message;
    else feedback.textContent = message;
    feedback.hidden = !message;
  };

  const sourceLabel = (source) => ({
    pdf_text: text('Texto del PDF', 'PDF text'),
    pdf_scan: text('PDF escaneado', 'Scanned PDF'),
    word_companion: text('Word compañero', 'Companion Word file'),
    word_only: text('Sólo Word', 'Word only'),
    docx_companion: text('Word compañero', 'Companion Word file'),
    docx_only: text('Sólo Word', 'Word only'),
    unknown: text('Sin fuente', 'No source')
  }[source] || source || '—');

  const rowStatusMeta = (row) => {
    if (row.commit_status === 'imported') return { label: text('Importado', 'Imported'), cls: 'aviso-import-badge-imported' };
    if (row.commit_status === 'skipped') return { label: text('Omitido', 'Skipped'), cls: 'aviso-import-badge-muted' };
    if (row.commit_status === 'error') return { label: text('Error', 'Error'), cls: 'aviso-import-badge-duplicate' };
    if (row.review_status === 'ready') {
      if (row.review_action === 'skip') return { label: text('Listo para omitir', 'Ready to skip'), cls: 'aviso-import-badge-muted' };
      if (row.review_action === 'link_version') return { label: text('Listo para vincular', 'Ready to link'), cls: 'aviso-import-badge-ready' };
      if (row.review_action === 'import_new_override_deleted') return { label: text('Listo · importar como nuevo', 'Ready · import as new'), cls: 'aviso-import-badge-ready' };
      return { label: text('Listo para importar', 'Ready to import'), cls: 'aviso-import-badge-ready' };
    }
    if (row.duplicate_is_deleted) return { label: text('Eliminado · restaurar', 'Deleted · restore'), cls: 'aviso-import-badge-duplicate' };
    if (row.duplicate_desembarque_id) return { label: text('Duplicado · revisar', 'Duplicate · review'), cls: 'aviso-import-badge-duplicate' };
    if (row.extraction_status === 'needs_companion') return { label: text('Necesita Word/OCR', 'Needs Word/OCR'), cls: 'aviso-import-badge-review' };
    if (row.extraction_status === 'needs_pdf') return { label: text('Falta PDF final', 'Final PDF missing'), cls: 'aviso-import-badge-review' };
    return { label: row.review_status === 'invalid' ? text('Datos incompletos', 'Incomplete data') : text('Requiere revisión', 'Review required'), cls: 'aviso-import-badge-review' };
  };

  const actionLabel = (action) => ({
    import_new: text('Crear expediente nuevo', 'Create new case file'),
    import_new_override_deleted: text('Importar como aviso nuevo (conservar eliminado)', 'Import as new notice (keep deleted record)'),
    link_version: text('Vincular PDF como versión del existente', 'Link PDF as a version of the existing case file'),
    skip: text('Omitir', 'Skip')
  }[action] || action);

  const statusOptions = (selected) => Object.entries(avisoStatuses)
    .map(([slug, label]) => `<option value="${escapeAttr(slug)}" ${slug === selected ? 'selected' : ''}>${escapeHtml(label)}</option>`)
    .join('');

  const updateFileList = () => {
    if (!fileList || !fileInput) return;

    const files = Array.from(fileInput.files || []);

    if (!files.length) {
      fileList.innerHTML =
        '<span class="text-body-secondary">' + escapeHtml(text('Aún no seleccionas archivos.', 'No files selected yet.')) + '</span>';
      return;
    }

    const totalSize = files.reduce(
      (total, file) => total + Number(file.size || 0),
      0
    );

    const fileRows = files.map(file => {
      const oversized = file.size > MAX_FILE_SIZE;

      return `
        <div class="d-flex justify-content-between gap-3 py-1 border-bottom">
          <span class="text-truncate ${oversized ? 'text-danger fw-semibold' : ''}">
            ${escapeHtml(file.name)}
          </span>

          <span class="${oversized ? 'text-danger fw-semibold' : 'text-body-secondary'} small flex-shrink-0">
            ${formatBytes(file.size)}
            ${oversized ? ` · ${escapeHtml(text('supera', 'exceeds'))} ${MAX_FILE_SIZE_MB} MB` : ''}
          </span>
        </div>
      `;
    }).join('');

    fileList.innerHTML = `
      ${fileRows}

      <div class="d-flex justify-content-between gap-3 pt-2 mt-1">
        <strong class="small">
          ${files.length} ${escapeHtml(language === 'en' ? (files.length === 1 ? 'file' : 'files') : (files.length === 1 ? 'archivo' : 'archivos'))}
        </strong>

        <span class="small ${totalSize > MAX_BATCH_SIZE ? 'text-danger fw-semibold' : 'text-body-secondary'}">
          ${formatBytes(totalSize)} de ${MAX_BATCH_SIZE_MB} MB
        </span>
      </div>
    `;
  };

  const renderActionOptions = (row) => {
    if (row.duplicate_desembarque_id) {
      if (row.duplicate_is_deleted) {
        const actions = ['skip'];
        if (config.canOverrideDeleted && row.duplicate_kind === 'notice') actions.push('import_new_override_deleted');
        return actions.map(action => `<option value="${action}" ${row.review_action === action ? 'selected' : ''}>${escapeHtml(actionLabel(action))}</option>`).join('');
      }
      const options = ['skip'];
      if (row.duplicate_kind !== 'pdf_sha') options.unshift('link_version');
      return options.map(action => `<option value="${action}" ${row.review_action === action ? 'selected' : ''}>${escapeHtml(actionLabel(action))}</option>`).join('');
    }
    return ['import_new', 'skip'].map(action => `<option value="${action}" ${row.review_action === action ? 'selected' : ''}>${escapeHtml(actionLabel(action))}</option>`).join('');
  };

  const editorField = (label, key, value, type = 'text', col = 'col-md-6 col-xl-4', attrs = '') => `
    <div class="${col}">
      <label class="form-label small fw-semibold">${escapeHtml(label)}</label>
      <input class="form-control form-control-sm" type="${type}" data-field="${escapeAttr(key)}" value="${escapeAttr(value || '')}" ${attrs}>
    </div>`;

  const editorTextarea = (label, key, value, col = 'col-12') => `
    <div class="${col}">
      <label class="form-label small fw-semibold">${escapeHtml(label)}</label>
      <textarea class="form-control form-control-sm" rows="2" data-field="${escapeAttr(key)}">${escapeHtml(value || '')}</textarea>
    </div>`;

  const renderPedimentosEditor = (row) => {
    const pedimentos = Array.isArray(row.data?.pedimentos) ? row.data.pedimentos : [];
    return `
      <div class="aviso-review-section">
        <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
          <div><h4 class="h6 mb-0">${escapeHtml(text('Pedimentos', 'Customs entries'))}</h4><div class="small text-body-secondary">${escapeHtml(text('Clave, número e importador se almacenarán estructurados.', 'Code, number, and importer will be stored as structured data.'))}</div></div>
          <button class="btn btn-sm btn-outline-primary" type="button" data-add-pedimento="${row.id}">+ ${escapeHtml(text('Pedimento', 'Customs entry'))}</button>
        </div>
        <div data-pedimentos-list>
          ${pedimentos.map((p, index) => `
            <div class="aviso-review-array-row" data-pedimento-row>
              <div class="row g-2 flex-grow-1">
                <div class="col-sm-2"><label class="form-label small">${escapeHtml(text('Clave', 'Code'))}</label><input class="form-control form-control-sm" data-ped-field="key" value="${escapeAttr(p.key || '')}"></div>
                <div class="col-sm-4"><label class="form-label small">${escapeHtml(text('Número', 'Number'))}</label><input class="form-control form-control-sm" data-ped-field="number" value="${escapeAttr(p.number || '')}"></div>
                <div class="col-sm-6"><label class="form-label small">${escapeHtml(text('Importador / razón social', 'Importer / legal business name'))}</label><input class="form-control form-control-sm" data-ped-field="importer_name" value="${escapeAttr(p.importer_name || '')}"></div>
              </div>
              <button class="btn btn-sm btn-outline-danger align-self-end" type="button" data-remove-array-row title="${escapeAttr(text('Quitar pedimento', 'Remove customs entry'))}">×</button>
            </div>`).join('') || '<div class="text-body-secondary small py-2" data-empty-array>' + escapeHtml(text('No hay pedimentos detectados.', 'No customs entries detected.')) + '</div>'}
        </div>
      </div>`;
  };

  const renderItemsEditor = (row) => {
    const items = Array.isArray(row.data?.items) ? row.data.items : [];
    return `
      <div class="aviso-review-section">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-2">
          <div>
            <h4 class="h6 mb-0">${escapeHtml(text('Mercancías', 'Merchandise'))}</h4>
            <div class="small text-body-secondary"><strong>${escapeHtml(merchandiseSummary(row))}</strong>. ${escapeHtml(text('Un renglón no es lo mismo que la cantidad de piezas.', 'A merchandise line is not the same as the number of pieces.'))}</div>
          </div>
          <button class="btn btn-sm btn-outline-primary" type="button" data-add-item="${row.id}">+ ${escapeHtml(text('Mercancía', 'Merchandise'))}</button>
        </div>
        <div data-items-list>
          ${items.map((item, index) => renderItemRow(item, index)).join('') || '<div class="text-body-secondary small py-2" data-empty-array>' + escapeHtml(text('No hay mercancías detectadas.', 'No merchandise detected.')) + '</div>'}
        </div>
      </div>`;
  };

  const itemPedimentos = (item = {}) => {
    const list = Array.isArray(item.pedimentos) ? item.pedimentos.filter(Boolean) : [];
    if (list.length) return list;
    if (item.pedimento) return [{ key: item.key || '', number: item.pedimento || '', importer_name: item.importer_name || '' }];
    return [];
  };

  const renderItemPedimentoRow = (pedimento = {}) => `
    <div class="aviso-review-array-row" data-item-pedimento-row>
      <div class="row g-2 flex-grow-1">
        <div class="col-sm-3"><label class="form-label small">${escapeHtml(text('Clave', 'Code'))}</label><input class="form-control form-control-sm" data-item-ped-field="key" value="${escapeAttr(pedimento.key || '')}"></div>
        <div class="col-sm-5"><label class="form-label small">${escapeHtml(text('Número', 'Number'))}</label><input class="form-control form-control-sm" data-item-ped-field="number" value="${escapeAttr(pedimento.number || '')}"></div>
        <div class="col-sm-4"><label class="form-label small">${escapeHtml(text('Importador', 'Importer'))}</label><input class="form-control form-control-sm" data-item-ped-field="importer_name" value="${escapeAttr(pedimento.importer_name || '')}"></div>
      </div>
      <button class="btn btn-sm btn-outline-danger align-self-end" type="button" data-remove-array-row title="${escapeAttr(text('Quitar pedimento', 'Remove customs entry'))}">×</button>
    </div>`;

  const renderItemRow = (item = {}, index = 0) => {
    const relations = itemPedimentos(item);
    return `
    <div class="aviso-review-item" data-item-row>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <strong class="small">${escapeHtml(text('Renglón', 'Merchandise line'))} <span data-item-number>${index + 1}</span></strong>
        <button class="btn btn-sm btn-outline-danger" type="button" data-remove-array-row>${escapeHtml(text('Quitar', 'Remove'))}</button>
      </div>
      <div class="row g-2">
        <div class="col-12"><label class="form-label small">${escapeHtml(text('Descripción', 'Description'))}</label><textarea class="form-control form-control-sm" rows="2" data-item-field="description">${escapeHtml(item.description || '')}</textarea></div>
        <div class="col-sm-3 col-lg-2"><label class="form-label small">${escapeHtml(text('Cantidad', 'Quantity'))}</label><input class="form-control form-control-sm" type="number" min="0.001" step="0.001" data-item-field="quantity" value="${escapeAttr(item.quantity ?? '')}"></div>
        <div class="col-sm-9 col-lg-4"><label class="form-label small">${escapeHtml(text('Serial / identificación', 'Serial / identification'))}</label><input class="form-control form-control-sm" data-item-field="serial_number" value="${escapeAttr(item.serial_number || '')}"></div>
        <div class="col-sm-6 col-lg-2"><label class="form-label small">${escapeHtml(text('Marca', 'Brand'))}</label><input class="form-control form-control-sm" data-item-field="marca" value="${escapeAttr(item.marca || '')}"></div>
        <div class="col-sm-5 col-lg-3"><label class="form-label small">${escapeHtml(text('Partida', 'Line item'))}</label><input class="form-control form-control-sm" data-item-field="partida" value="${escapeAttr(item.partida || '')}"></div>
      </div>
      <div class="mt-3 border-top pt-3">
        <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
          <div><div class="fw-semibold small">${escapeHtml(text('Pedimentos asociados', 'Associated customs entries'))}</div><div class="small text-body-secondary">${escapeHtml(text('Una mercancía puede estar amparada por uno o varios pedimentos.', 'A merchandise line can be covered by one or more customs entries.'))}</div></div>
          <button class="btn btn-sm btn-outline-primary" type="button" data-add-item-pedimento>+ ${escapeHtml(text('Pedimento', 'Customs entry'))}</button>
        </div>
        <div data-item-pedimentos-list>
          ${relations.map(renderItemPedimentoRow).join('') || '<div class="text-body-secondary small py-2" data-empty-item-pedimentos>' + escapeHtml(text('Sin pedimentos asociados.', 'No associated customs entries.')) + '</div>'}
        </div>
      </div>
    </div>`;
  };

  const renderCommittedDetail = (row) => {
    if (row.commit_status === 'skipped') {
      return '<div class="alert alert-secondary mb-0">' + escapeHtml(text('Este archivo fue marcado como omitido. No se creó ni modificó ningún expediente.', 'This file was marked as skipped. No case file was created or modified.')) + '</div>';
    }
    if (row.commit_status === 'imported') {
      return `
        <div class="alert alert-success mb-0 d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-center">
          <div><strong>${escapeHtml(text('Importado correctamente.', 'Imported successfully.'))}</strong><div class="small">${escapeHtml(text('El PDF original quedó archivado como versión histórica inmutable.', 'The original PDF was archived as an immutable historical version.'))}</div></div>
          ${row.imported_desembarque_id ? `<a class="btn btn-sm btn-success" href="aviso-expediente.php?id=${Number(row.imported_desembarque_id)}">${escapeHtml(text('Abrir expediente', 'Open case file'))}</a>` : ''}
        </div>`;
    }
    return `<div class="alert alert-danger mb-0">${escapeHtml(row.import_error || text('La fila terminó con error.', 'The row ended with an error.'))}</div>`;
  };

  const renderReviewEditor = (row) => {
    if (row.commit_status !== 'pending') return renderCommittedDetail(row);
    const d = row.data || {};
    const effective = toDateTimeLocal(row.review_status_effective_at, row.review_aviso_status === 'presented' ? (d.office_date || '') : '');
    const duplicateBlock = row.duplicate_desembarque_id ? (row.duplicate_is_deleted ? `
      <div class="alert alert-danger small">
        <strong>${escapeHtml(text('Existe un expediente eliminado:', 'A deleted case file exists:'))}</strong> ${escapeHtml(row.duplicate_reason || text('Un administrador puede restaurarlo o un usuario interno puede importarlo como un aviso nuevo.', 'An administrator can restore it, or an internal user can import it as a new notice.'))}
        ${row.duplicate_deleted_at ? `<div class="mt-1">${escapeHtml(text('Eliminado:', 'Deleted:'))} ${escapeHtml(row.duplicate_deleted_at)}</div>` : ''}
        ${row.duplicate_delete_reason ? `<div>${escapeHtml(text('Motivo:', 'Reason:'))} ${escapeHtml(row.duplicate_delete_reason)}</div>` : ''}
        ${config.canRestoreDeleted && row.restore_url ? `<a class="btn btn-sm btn-outline-danger mt-2" href="${escapeAttr(row.restore_url)}">${escapeHtml(text('Abrir Papelera y restaurar', 'Open Deleted Notices to restore'))}</a>` : '<div class="mt-2 fw-semibold">' + escapeHtml(text('Un administrador puede restaurarlo desde la Papelera.', 'An administrator can restore it from Deleted Notices.')) + '</div>'}
        ${config.canOverrideDeleted && row.duplicate_kind === 'notice' ? `<div class="mt-2 fw-semibold">${escapeHtml(text('Si el eliminado era sólo una prueba, selecciona “Importar como aviso nuevo” e indica el motivo administrativo.', 'If the deleted record was only a test, select “Import as new notice” and enter the administrative reason.'))}</div>` : ''}
      </div>` : `
      <div class="alert alert-warning small">
        <strong>${escapeHtml(text('Posible duplicado:', 'Possible duplicate:'))}</strong> ${escapeHtml(row.duplicate_reason || text('Ya existe un Aviso compatible con este registro.', 'A notice compatible with this record already exists.'))}
        ${row.compare_url ? `<a class="alert-link ms-1" href="${escapeAttr(row.compare_url)}" target="_blank" rel="noopener">${escapeHtml(text('Comparar expediente', 'Compare case file'))}</a>` : ''}
      </div>`) : '';

    return `
      <div class="aviso-review-editor" data-review-editor="${row.id}">
        ${duplicateBlock}
        <div class="aviso-review-section">
          <div class="row g-3">
            <div class="col-md-6 col-xl-3">
              <label class="form-label small fw-semibold">${escapeHtml(text('Acción al confirmar', 'Action on confirmation'))}</label>
              <select class="form-select form-select-sm" data-review-action>${renderActionOptions(row)}</select>
            </div>
            <div class="col-md-6 col-xl-3">
              <label class="form-label small fw-semibold">${escapeHtml(text('Estado documental', 'Document status'))}</label>
              <select class="form-select form-select-sm" data-review-status>${statusOptions(row.review_aviso_status || 'presented')}</select>
            </div>
            <div class="col-md-6 col-xl-3">
              <label class="form-label small fw-semibold">${escapeHtml(text('Fecha/hora efectiva', 'Effective date/time'))}</label>
              <input class="form-control form-control-sm" type="datetime-local" data-review-effective value="${escapeAttr(effective)}">
            </div>
            <div class="col-md-6 col-xl-3">
              <label class="form-label small fw-semibold">${escapeHtml(text('Motivo', 'Reason'))}</label>
              <input class="form-control form-control-sm" data-review-reason value="${escapeAttr(row.review_reason || '')}" placeholder="${escapeAttr(text('Obligatorio al reemplazar/cancelar', 'Required when replacing/cancelling'))}">
            </div>
          </div>
        </div>

        <div data-data-editor>
          <div class="aviso-review-section">
            <h4 class="h6">${escapeHtml(text('Identificación del Aviso', 'Notice identification'))}</h4>
            <div class="row g-3">
              ${editorField(text('Número / manifiesto', 'Number / manifest'), 'notice_number', d.notice_number)}
              ${editorField(text('Código MADE', 'MADE code'), 'document_code', d.document_code)}
              ${editorField(text('Fecha del oficio', 'Official letter date'), 'office_date', d.office_date, 'date')}
              ${editorField(text('Destinatario', 'Recipient'), 'recipient_name', d.recipient_name)}
              ${editorField(text('Cargo destinatario', 'Recipient title'), 'recipient_title', d.recipient_title, 'text', 'col-md-6')}
              ${editorField(text('Firmante', 'Signatory'), 'signer_name', d.signer_name)}
              ${editorField(text('Cargo / patente', 'Title / license'), 'signer_title', d.signer_title)}
            </div>
          </div>

          <div class="aviso-review-section">
            <h4 class="h6">Rig / proyecto</h4>
            <div class="row g-3">
              ${editorField('Rig', 'rig_name', d.rig_name)}
              ${editorField('IMO Rig', 'rig_imo', d.rig_imo)}
              ${editorField(text('Campo', 'Field'), 'rig_field', d.rig_field)}
              ${editorField(text('Área del Rig', 'Rig area'), 'rig_area', d.rig_area)}
              ${editorField(text('Comitente', 'Principal'), 'comitente', d.comitente, 'text', 'col-md-8')}
            </div>
          </div>

          <div class="aviso-review-section">
            <h4 class="h6">${escapeHtml(text('Operación de desembarque', 'Unloading operation'))}</h4>
            <div class="row g-3">
              ${editorField(text('Manifiesto', 'Manifest'), 'manifest', d.manifest)}
              ${editorField(text('Medio de transporte', 'Means of transport'), 'transport_name', d.transport_name, 'text', 'col-md-6')}
              ${editorField(text('IMO transporte', 'Transport IMO'), 'transport_imo', d.transport_imo)}
              ${editorField(text('Consignataria', 'Consignee'), 'consignataria', d.consignataria, 'text', 'col-md-6')}
              ${editorField(text('Fecha de embarque', 'Shipping date'), 'shipping_date', d.shipping_date, 'date')}
              ${editorField(text('Desembarque / ETA', 'Unloading / ETA'), 'landing_datetime', toDateTimeLocal(d.landing_datetime), 'datetime-local')}
              ${editorTextarea(text('Lugar de desembarque', 'Unloading location'), 'landing_place', d.landing_place)}
              ${editorTextarea(text('Domicilio de almacenamiento', 'Storage address'), 'storage_address', d.storage_address)}
              ${editorTextarea(text('Domicilio reparación / mantenimiento', 'Repair / maintenance address'), 'repair_address', d.repair_address)}
            </div>
          </div>
          ${renderPedimentosEditor(row)}
          ${renderItemsEditor(row)}
        </div>

        <div class="aviso-review-errors alert alert-warning small mt-3 mb-0" data-row-errors hidden></div>
        <div class="d-flex flex-column flex-sm-row justify-content-end gap-2 mt-3">
          <button class="btn btn-primary" type="button" data-save-review="${row.id}">${escapeHtml(text('Guardar revisión', 'Save review'))}</button>
        </div>
      </div>`;
  };

  const renderRows = (rows) => {
    if (!tbody) return;
    if (!Array.isArray(rows) || !rows.length) {
      tbody.innerHTML = '<tr><td colspan="9" class="text-center text-body-secondary py-4">' + escapeHtml(text('No hay resultados.', 'No results.')) + '</td></tr>';
      return;
    }

    tbody.innerHTML = rows.map(row => {
      const meta = rowStatusMeta(row);
      const pair = [row.pdf_name, row.word_name || row.docx_name].filter(Boolean).map(name => `<div>${escapeHtml(name)}</div>`).join('<div class="small text-body-secondary">+</div>');
      const selectable = row.can_import && row.commit_status === 'pending';
      const checked = selectable && state.selected.has(Number(row.id));
      const warning = row.import_error || row.error_message || '';
      return `
        <tr class="${row.commit_status === 'imported' ? 'table-success-subtle' : ''}">
          <td class="text-center"><input class="form-check-input" type="checkbox" data-row-select="${row.id}" ${checked ? 'checked' : ''} ${selectable ? '' : 'disabled'} aria-label="${escapeAttr(text('Seleccionar aviso ', 'Select notice '))}${escapeAttr(row.notice_number || row.id)}"></td>
          <td><strong>${escapeHtml(row.notice_number || '—')}</strong><div class="small text-body-secondary">${escapeHtml(row.document_code || '')}</div></td>
          <td class="small">${pair || '—'}</td>
          <td><span class="badge rounded-pill aviso-import-badge-source">${escapeHtml(sourceLabel(row.extraction_source))}</span>${row.is_scanned_pdf ? '<div class="small text-warning-emphasis mt-1">' + escapeHtml(text('Escaneo detectado', 'Scan detected')) + '</div>' : ''}</td>
          <td>${escapeHtml(row.data?.office_date || '—')}</td>
          <td>${escapeHtml(row.data?.rig_name || '—')}</td>
          <td><strong>${escapeHtml(String(row.item_count || 0))}</strong> ${escapeHtml(language === 'en' ? (Number(row.item_count) === 1 ? 'merchandise line' : 'merchandise lines') : (Number(row.item_count) === 1 ? 'renglón' : 'renglones'))}<div class="small text-body-secondary">${escapeHtml(formatQuantity(row.piece_count))} ${escapeHtml(language === 'en' ? (Number(row.piece_count) === 1 ? 'piece' : 'pieces') : (Number(row.piece_count) === 1 ? 'pieza' : 'piezas'))}</div></td>
          <td>${escapeHtml(String((row.data?.pedimentos || []).length || 0))}</td>
          <td><span class="badge rounded-pill ${meta.cls}">${escapeHtml(meta.label)}</span>${row.critical_score != null ? `<div class="small text-body-secondary mt-1">${escapeHtml(text('Extracción', 'Extraction'))} ${Math.round(Number(row.critical_score) * 100)}%</div>` : ''}${warning ? `<div class="small mt-1 text-danger-emphasis">${escapeHtml(warning)}</div>` : ''}</td>
        </tr>
        <tr>
          <td colspan="9" class="pt-0">
            <details class="aviso-import-row-detail py-2" ${row.review_status === 'invalid' ? 'open' : ''}>
              <summary class="small fw-semibold">${escapeHtml(row.commit_status === 'pending' ? text('Revisar / editar datos', 'Review / edit data') : text('Ver resultado', 'View result'))}</summary>
              <div class="pt-3">${renderReviewEditor(row)}</div>
            </details>
          </td>
        </tr>`;
    }).join('');

    updateSelectedSummary();
  };


  const validationStatusMeta = (status) => ({
    pass: { label: 'PASS', cls: 'text-bg-success', icon: '✓' },
    warn: { label: text('REVISAR', 'REVIEW'), cls: 'text-bg-warning', icon: '!' },
    fail: { label: 'FAIL', cls: 'text-bg-danger', icon: '×' },
    skipped: { label: text('OMITIDO', 'SKIPPED'), cls: 'text-bg-secondary', icon: '–' }
  }[status] || { label: String(status || text('N/D', 'N/A')).toUpperCase(), cls: 'text-bg-secondary', icon: '?' });

  const renderValidation = (validation) => {
    if (!validationSection || !validation) return;
    validationSection.hidden = false;
    const summary = validation.summary || {};
    const overall = validation.pass ? (Number(summary.warn || 0) > 0 ? 'warn' : 'pass') : 'fail';
    const meta = validationStatusMeta(overall);
    if (validationBadge) {
      validationBadge.className = `badge rounded-pill ${meta.cls}`;
      validationBadge.textContent = `${meta.icon} ${meta.label}`;
    }
    if (validationSummary) {
      const cards = [
        [text('Avisos validados', 'Notices validated'), summary.rows || 0, ''],
        ['PASS', summary.pass || 0, 'text-success'],
        [text('Revisar', 'Review'), summary.warn || 0, 'text-warning'],
        ['FAIL', summary.fail || 0, 'text-danger'],
        [text('Omitidos', 'Skipped'), summary.skipped || 0, 'text-secondary'],
        [text('Checks ejecutados', 'Checks run'), summary.checks || 0, '']
      ];
      validationSummary.innerHTML = cards.map(([label, value, cls]) => `
        <div class="col-6 col-lg-2">
          <div class="aviso-validation-stat">
            <strong class="${cls}">${escapeHtml(value)}</strong>
            <span>${escapeHtml(label)}</span>
          </div>
        </div>`).join('');
    }
    if (validationBatchChecks) {
      const checks = Array.isArray(validation.batch_checks) ? validation.batch_checks : [];
      validationBatchChecks.innerHTML = checks.length ? `
        <div class="aviso-validation-check-list mb-3">
          ${checks.map(check => {
            const m = validationStatusMeta(check.status);
            return `<div class="aviso-validation-check ${escapeAttr(check.status)}"><span class="badge ${m.cls}">${m.icon}</span><div><strong>${escapeHtml(check.label || check.code)}</strong><div class="small text-body-secondary">${escapeHtml(check.detail || '')}</div></div></div>`;
          }).join('')}
        </div>` : '';
    }
    if (validationRows) {
      const rows = Array.isArray(validation.rows) ? validation.rows : [];
      validationRows.innerHTML = rows.length ? rows.map((row, index) => {
        const rowMeta = validationStatusMeta(row.status);
        const checks = Array.isArray(row.checks) ? row.checks : [];
        return `
          <div class="accordion-item">
            <h2 class="accordion-header" id="validationHeading${index}">
              <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#validationCollapse${index}" aria-expanded="false" aria-controls="validationCollapse${index}">
                <span class="badge ${rowMeta.cls} me-2">${rowMeta.icon}</span>
                <strong class="me-2">${escapeHtml(row.notice_number || `${text('Fila', 'Row')} #${row.row_id}`)}</strong>
                <span class="text-body-secondary small">${escapeHtml(rowMeta.label)} · ${escapeHtml(String(row.summary?.total || 0))} ${escapeHtml(text('validaciones', 'checks'))}</span>
              </button>
            </h2>
            <div id="validationCollapse${index}" class="accordion-collapse collapse" aria-labelledby="validationHeading${index}" data-bs-parent="#historicalValidationAccordion">
              <div class="accordion-body">
                ${row.status === 'skipped' ? '<div class="text-body-secondary small">' + escapeHtml(text('Fila omitida durante el commit; no crea expediente ni versión.', 'Row skipped during commit; it creates neither a case file nor a version.')) + '</div>' : `
                  <div class="aviso-validation-check-list">
                    ${checks.map(check => {
                      const cmeta = validationStatusMeta(check.status);
                      return `<div class="aviso-validation-check ${escapeAttr(check.status)}"><span class="badge ${cmeta.cls}">${cmeta.icon}</span><div><strong>${escapeHtml(check.label || check.code)}</strong><div class="small text-body-secondary">${escapeHtml(check.detail || '')}</div></div></div>`;
                    }).join('')}
                  </div>
                  ${row.desembarque_id ? `<div class="mt-3"><a class="btn btn-sm btn-outline-secondary" href="aviso-expediente.php?id=${encodeURIComponent(row.desembarque_id)}">${escapeHtml(text('Abrir expediente', 'Open case file'))}</a></div>` : ''}
                `}
              </div>
            </div>
          </div>`;
      }).join('') : '<div class="text-body-secondary small">' + escapeHtml(text('El lote todavía no tiene filas importadas para validar.', 'The batch does not yet have imported rows to validate.')) + '</div>';
    }
    validationSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  const validateBatch = async (showFeedback = true) => {
    if (!state.batch?.public_id || !config.validateUrl) return null;
    if (validateBatchButton) {
      validateBatchButton.disabled = true;
      validateBatchButton.dataset.originalLabel = validateBatchButton.dataset.originalLabel || validateBatchButton.innerHTML;
      validateBatchButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + escapeHtml(text('Validando…', 'Validating…'));
    }
    if (showFeedback) setFeedback(text('Ejecutando validación 5E5: estructura, SHA-256, conteos, estados y vínculos de importación…', 'Running 5E5 validation: structure, SHA-256, counts, statuses, and import links…'), 'info');
    try {
      const response = await fetch(`${config.validateUrl}?batch=${encodeURIComponent(state.batch.public_id)}`, {
        credentials: 'include', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await response.json().catch(() => ({}));
      if (!data.success || !data.validation) throw new Error(data.message || text('No fue posible validar el lote.', 'The batch could not be validated.'));
      renderValidation(data.validation);
      if (showFeedback) setFeedback(data.message || text('Validación 5E5 completada.', '5E5 validation completed.'), data.validation.pass ? 'success' : 'warning');
      return data.validation;
    } catch (error) {
      setFeedback(error.message || text('No fue posible validar el lote.', 'The batch could not be validated.'), 'danger');
      return null;
    } finally {
      if (validateBatchButton) {
        validateBatchButton.innerHTML = validateBatchButton.dataset.originalLabel || text('Validar lote 5E5', 'Validate 5E5 batch');
        validateBatchButton.disabled = Number(state.batch?.imported_rows || 0) <= 0;
      }
    }
  };

  const renderBatch = (payload) => {
    const batch = payload.batch || {};
    state.batch = batch;
    state.rows = Array.isArray(payload.rows) ? payload.rows : [];
    const allowed = new Set(state.rows.filter(row => row.can_import && row.commit_status === 'pending').map(row => Number(row.id)));
    state.selected = new Set([...state.selected].filter(id => allowed.has(id)));

    if (stats.total) stats.total.textContent = batch.total_rows ?? 0;
    if (stats.ready) stats.ready.textContent = batch.ready_rows ?? 0;
    if (stats.review) stats.review.textContent = batch.review_rows ?? 0;
    if (stats.duplicate) stats.duplicate.textContent = batch.duplicate_rows ?? 0;
    if (batchClient) batchClient.textContent = batch.client_name || text('Cliente', 'Client');
    if (batchOperational) batchOperational.textContent = `${text('Estado operativo:', 'Operational status:')} ${batch.operational_status_name || '—'}`;
    if (batchProgress) batchProgress.textContent = `${batch.imported_rows || 0} ${text('importados', 'imported')} · ${batch.skipped_rows || 0} ${text('omitidos', 'skipped')}`;
    if (batchSummary) batchSummary.hidden = false;
    if (validateBatchButton) validateBatchButton.disabled = Number(batch.imported_rows || 0) <= 0;

    renderRows(state.rows);
    if (resultsSection) resultsSection.hidden = false;
    const url = new URL(window.location.href);
    if (batch.public_id) {
      url.searchParams.set('batch', batch.public_id);
      history.replaceState({}, '', url);
    }
  };

  const loadBatch = async (batchId, message = '') => {
    if (!batchId) return;
    if (!message) setFeedback(text('Cargando lote de revisión…', 'Loading review batch…'), 'info');
    try {
      const response = await fetch(`${config.listUrl}?batch=${encodeURIComponent(batchId)}`, {
        credentials: 'include', headers: { Accept: 'application/json' }
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success) throw new Error(data.message || text('No fue posible cargar el lote.', 'The batch could not be loaded.'));
      renderBatch(data);
      if (message) setFeedback(message, 'success');
      else setFeedback(text('Lote recuperado. Guarda la revisión de cada Aviso antes de importarlo.', 'Batch retrieved. Save each notice review before importing it.'), 'success');
      return data;
    } catch (error) {
      setFeedback(error.message || text('No fue posible cargar el lote.', 'The batch could not be loaded.'), 'danger');
      return null;
    }
  };

  const collectReviewData = (editor) => {
    const data = {};
    editor.querySelectorAll('[data-field]').forEach(control => {
      const key = control.dataset.field;
      let value = control.value.trim();
      if (control.type === 'datetime-local') value = mysqlDateTime(value) || '';
      data[key] = value || null;
    });

    data.pedimentos = Array.from(editor.querySelectorAll('[data-pedimento-row]')).map(row => {
      const item = {};
      row.querySelectorAll('[data-ped-field]').forEach(input => { item[input.dataset.pedField] = input.value.trim() || null; });
      return item;
    });

    data.items = Array.from(editor.querySelectorAll('[data-item-row]')).map((row, index) => {
      const item = { sort_order: index + 1 };
      row.querySelectorAll('[data-item-field]').forEach(input => {
        let value = input.value.trim();
        if (input.dataset.itemField === 'quantity') value = value === '' ? null : Number(value);
        item[input.dataset.itemField] = value === '' ? null : value;
      });
      item.pedimentos = Array.from(row.querySelectorAll('[data-item-pedimento-row]')).map(pedRow => {
        const relation = {};
        pedRow.querySelectorAll('[data-item-ped-field]').forEach(input => { relation[input.dataset.itemPedField] = input.value.trim() || null; });
        return relation;
      });
      if (item.pedimentos.length === 1) {
        item.key = item.pedimentos[0].key || null;
        item.pedimento = item.pedimentos[0].number || null;
      } else {
        item.key = null;
        item.pedimento = null;
      }
      return item;
    });
    return data;
  };

  const saveReview = async (rowId, button) => {
    const editor = document.querySelector(`[data-review-editor="${rowId}"]`);
    if (!editor || !state.batch?.public_id) return;
    const errorsBox = editor.querySelector('[data-row-errors]');
    const payload = {
      csrf_token: config.csrfToken,
      batch: state.batch.public_id,
      row_id: String(rowId),
      action: editor.querySelector('[data-review-action]')?.value || 'import_new',
      aviso_status: editor.querySelector('[data-review-status]')?.value || 'presented',
      effective_at: mysqlDateTime(editor.querySelector('[data-review-effective]')?.value || ''),
      reason: editor.querySelector('[data-review-reason]')?.value.trim() || null,
      data: collectReviewData(editor)
    };

    button.disabled = true;
    const original = button.innerHTML;
    button.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + escapeHtml(text('Guardando…', 'Saving…'));
    if (errorsBox) errorsBox.hidden = true;
    try {
      const response = await fetch(config.reviewUrl, {
        method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(payload)
      });
      const data = await response.json().catch(() => ({}));
      if (response.status === 422) {
        await loadBatch(state.batch.public_id);
        const refreshedEditor = document.querySelector(`[data-review-editor="${rowId}"]`);
        const refreshedErrors = refreshedEditor?.querySelector('[data-row-errors]');
        if (refreshedErrors) {
          refreshedErrors.innerHTML = `<strong>${escapeHtml(text('La revisión se guardó, pero falta corregir:', 'The review was saved, but the following must be corrected:'))}</strong><ul class="mb-0 mt-1">${(data.errors || []).map(error => `<li>${escapeHtml(error)}</li>`).join('')}</ul>`;
          refreshedErrors.hidden = false;
          refreshedEditor.closest('details')?.setAttribute('open', '');
        }
        setFeedback(data.message || text('La revisión tiene datos pendientes.', 'The review still has pending information.'), 'warning');
        return;
      }
      if (!response.ok || !data.success) throw new Error(data.message || text('No fue posible guardar la revisión.', 'The review could not be saved.'));
      state.selected.add(Number(rowId));
      await loadBatch(state.batch.public_id, `${text('Aviso', 'Notice')} ${data.row?.notice_number || rowId}: ${text('revisión guardada y lista para importar.', 'review saved and ready to import.')}`);
    } catch (error) {
      setFeedback(error.message || text('No fue posible guardar la revisión.', 'The review could not be saved.'), 'danger');
    } finally {
      button.disabled = false;
      button.innerHTML = original;
    }
  };

  const updateItemNumbers = (container) => {
    container?.querySelectorAll('[data-item-row]').forEach((row, index) => {
      const label = row.querySelector('[data-item-number]');
      if (label) label.textContent = String(index + 1);
    });
  };

  const updateSelectedSummary = () => {
    const rows = state.rows.filter(row => state.selected.has(Number(row.id)) && row.can_import && row.commit_status === 'pending');
    const items = rows.reduce((sum, row) => sum + Number(row.item_count || 0), 0);
    const pieces = rows.reduce((sum, row) => sum + Number(row.piece_count || 0), 0);
    if (selectedSummary) {
      const selectedLabel = language === 'en' ? (rows.length === 1 ? 'notice selected' : 'notices selected') : (rows.length === 1 ? 'seleccionado' : 'seleccionados');
      const rowLabel = language === 'en' ? (items === 1 ? 'merchandise line' : 'merchandise lines') : (items === 1 ? 'renglón' : 'renglones');
      const piecesLabel = language === 'en' ? 'pieces' : 'piezas';
      selectedSummary.textContent = rows.length
        ? `${rows.length} ${selectedLabel} · ${items} ${rowLabel} · ${formatQuantity(pieces)} ${piecesLabel}`
        : text('0 seleccionados', '0 selected');
    }
    if (importSelectedButton) importSelectedButton.disabled = rows.length === 0;
  };

  const openCommitModal = () => {
    const rows = state.rows.filter(row => state.selected.has(Number(row.id)) && row.can_import && row.commit_status === 'pending');
    if (!rows.length || !state.batch) return;
    const newRows = rows.filter(row => ['import_new', 'import_new_override_deleted'].includes(row.review_action));
    const linkRows = rows.filter(row => row.review_action === 'link_version');
    const skipRows = rows.filter(row => row.review_action === 'skip');
    const items = newRows.reduce((sum, row) => sum + Number(row.item_count || 0), 0);
    const pieces = newRows.reduce((sum, row) => sum + Number(row.piece_count || 0), 0);
    if (commitSummary) {
      commitSummary.innerHTML = `
        <div class="aviso-commit-summary-grid">
          <div><span>${escapeHtml(text('Cliente', 'Client'))}</span><strong>${escapeHtml(state.batch.client_name || '—')}</strong></div>
          <div><span>${escapeHtml(text('Seleccionados', 'Selected'))}</span><strong>${rows.length}</strong></div>
          <div><span>${escapeHtml(text('Nuevos expedientes', 'New case files'))}</span><strong>${newRows.length}</strong></div>
          <div><span>${escapeHtml(text('PDF a vincular', 'PDFs to link'))}</span><strong>${linkRows.length}</strong></div>
          <div><span>${escapeHtml(text('A omitir', 'To skip'))}</span><strong>${skipRows.length}</strong></div>
          <div><span>${escapeHtml(text('Mercancías nuevas', 'New merchandise'))}</span><strong>${items} ${escapeHtml(language === 'en' ? (items === 1 ? 'merchandise line' : 'merchandise lines') : (items === 1 ? 'renglón' : 'renglones'))}</strong></div>
          <div><span>${escapeHtml(text('Cantidad total', 'Total quantity'))}</span><strong>${formatQuantity(pieces)} ${escapeHtml(text('piezas', 'pieces'))}</strong></div>
          <div><span>${escapeHtml(text('Estado operativo', 'Operational status'))}</span><strong>${escapeHtml(state.batch.operational_status_name || '—')}</strong></div>
        </div>
        <div class="small text-body-secondary mt-3">${escapeHtml(text('Avisos:', 'Notices:'))} ${rows.map(row => escapeHtml(row.notice_number || `#${row.id}`)).join(', ')}</div>`;
    }
    commitModal?.show();
  };

  const commitSelected = async () => {
    const rowIds = state.rows
      .filter(row => state.selected.has(Number(row.id)) && row.can_import && row.commit_status === 'pending')
      .map(row => Number(row.id));
    if (!rowIds.length || !state.batch?.public_id) return;

    confirmCommitButton.disabled = true;
    const original = confirmCommitButton.innerHTML;
    confirmCommitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + escapeHtml(text('Importando…', 'Importing…'));
    try {
      const response = await fetch(config.commitUrl, {
        method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ csrf_token: config.csrfToken, batch: state.batch.public_id, row_ids: rowIds })
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success) throw new Error(data.detail || data.message || text('No fue posible completar la importación.', 'The import could not be completed.'));
      commitModal?.hide();
      state.selected.clear();
      const links = (data.results || [])
        .filter(result => result.expediente_url)
        .map(result => `<a class="alert-link" href="${escapeAttr(result.expediente_url)}">${escapeHtml(result.reference || `${text('Expediente', 'Case file')} #${result.desembarque_id}`)}</a>`);
      await loadBatch(state.batch.public_id);
      setFeedback(`${escapeHtml(data.message || text('Importación completada.', 'Import completed.'))}${links.length ? `<div class="mt-1">${links.join(' · ')}</div>` : ''}`, 'success', true);
      await validateBatch(false);
    } catch (error) {
      setFeedback(error.message || text('No fue posible completar la importación.', 'The import could not be completed.'), 'danger');
    } finally {
      confirmCommitButton.disabled = false;
      confirmCommitButton.innerHTML = original;
    }
  };

  fileInput?.addEventListener('change', updateFileList);
  ['dragenter', 'dragover'].forEach(eventName => dropzone?.addEventListener(eventName, event => {
    event.preventDefault(); dropzone.classList.add('is-dragging');
  }));
  ['dragleave', 'drop'].forEach(eventName => dropzone?.addEventListener(eventName, event => {
    event.preventDefault(); dropzone.classList.remove('is-dragging');
  }));
  dropzone?.addEventListener('drop', event => {
    const files = event.dataTransfer?.files;
    if (!files?.length || !fileInput) return;
    const transfer = new DataTransfer();
    Array.from(files).forEach(file => transfer.items.add(file));
    fileInput.files = transfer.files;
    updateFileList();
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const files = Array.from(fileInput?.files || []);

    if (!files.length) {
      setFeedback(
        text('Selecciona al menos un PDF o Word (.doc/.docx).', 'Select at least one PDF or Word file (.doc/.docx).'),
        'warning'
      );
      return;
    }

    if (files.length > MAX_FILES) {
      setFeedback(
        `${text('Máximo', 'Maximum')} ${MAX_FILES} ${text('archivos por lote.', 'files per batch.')}`,
        'warning'
      );
      return;
    }

    const oversizedFile = files.find(file => file.size > MAX_FILE_SIZE);

    if (oversizedFile) {
      setFeedback(
        `${oversizedFile.name} ${text('pesa', 'is')} ${formatBytes(oversizedFile.size)}. ` +
        `${text('El máximo permitido es', 'The maximum allowed is')} ${MAX_FILE_SIZE_MB} ${text('MB por archivo.', 'MB per file.')}`,
        'warning'
      );
      return;
    }

    const invalidFile = files.find(file => file.size <= 0);

    if (invalidFile) {
      setFeedback(
        `${invalidFile.name} ${text('está vacío o no puede leerse.', 'is empty or cannot be read.')}`,
        'warning'
      );
      return;
    }

    const batchSize = files.reduce(
      (total, file) => total + Number(file.size || 0),
      0
    );

    if (batchSize > MAX_BATCH_SIZE) {
      setFeedback(
        `${text('El lote pesa', 'The batch is')} ${formatBytes(batchSize)}. ` +
        `${text('El máximo permitido por lote es', 'The maximum allowed per batch is')} ${MAX_BATCH_SIZE_MB} MB.`,
        'warning'
      );
      return;
    }

    const body = new FormData(form);
    submit.disabled = true;
    const original = submit.innerHTML;
    submit.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' + escapeHtml(text('Analizando…', 'Analyzing…'));
    setFeedback(text('Analizando documentos. Después podrás revisar cada registro antes de importarlo.', 'Analyzing documents. You will be able to review each record before importing it.'), 'info');
    try {
      const response = await fetch(config.uploadUrl, {
        method: 'POST', credentials: 'include', body,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.success) throw new Error(data.message || data.detail || text('No fue posible analizar el lote.', 'The batch could not be analyzed.'));
      state.selected.clear();
      renderBatch(data);
      setFeedback(text('Lote analizado. Abre cada fila, confirma/corrige la extracción y pulsa “Guardar revisión”.', 'Batch analyzed. Open each row, confirm or correct the extraction, and select “Save review”.'), 'success');
      resultsSection?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (error) {
      setFeedback(error.message || text('No fue posible analizar el lote.', 'The batch could not be analyzed.'), 'danger');
    } finally {
      submit.disabled = false;
      submit.innerHTML = original;
    }
  });

  tbody?.addEventListener('change', event => {
    const checkbox = event.target.closest('[data-row-select]');
    if (checkbox) {
      const id = Number(checkbox.dataset.rowSelect);
      if (checkbox.checked) state.selected.add(id); else state.selected.delete(id);
      updateSelectedSummary();
      return;
    }

    const statusSelect = event.target.closest('[data-review-status]');
    if (statusSelect) {
      const editor = statusSelect.closest('[data-review-editor]');
      const reason = editor?.querySelector('[data-review-reason]');
      const effective = editor?.querySelector('[data-review-effective]');
      if (reason) reason.placeholder = ['replaced', 'cancelled'].includes(statusSelect.value)
        ? text('Motivo obligatorio', 'Reason required')
        : text('Observación opcional', 'Optional note');
      if (effective && statusSelect.value === 'presented' && !effective.value) {
        const office = editor?.querySelector('[data-field="office_date"]')?.value || '';
        if (office) effective.value = `${office}T00:00`;
      }
      return;
    }

    const actionSelect = event.target.closest('[data-review-action]');
    if (actionSelect) {
      const editor = actionSelect.closest('[data-review-editor]');
      const dataEditor = editor?.querySelector('[data-data-editor]');
      if (dataEditor) dataEditor.classList.toggle('opacity-50', actionSelect.value !== 'import_new');
    }
  });

  tbody?.addEventListener('click', event => {
    const save = event.target.closest('[data-save-review]');
    if (save) { saveReview(Number(save.dataset.saveReview), save); return; }

    const addPed = event.target.closest('[data-add-pedimento]');
    if (addPed) {
      const editor = addPed.closest('[data-review-editor]');
      const list = editor?.querySelector('[data-pedimentos-list]');
      list?.querySelector('[data-empty-array]')?.remove();
      list?.insertAdjacentHTML('beforeend', `
        <div class="aviso-review-array-row" data-pedimento-row>
          <div class="row g-2 flex-grow-1">
            <div class="col-sm-2"><label class="form-label small">${escapeHtml(text('Clave', 'Code'))}</label><input class="form-control form-control-sm" data-ped-field="key"></div>
            <div class="col-sm-4"><label class="form-label small">${escapeHtml(text('Número', 'Number'))}</label><input class="form-control form-control-sm" data-ped-field="number"></div>
            <div class="col-sm-6"><label class="form-label small">${escapeHtml(text('Importador / razón social', 'Importer / legal business name'))}</label><input class="form-control form-control-sm" data-ped-field="importer_name"></div>
          </div>
          <button class="btn btn-sm btn-outline-danger align-self-end" type="button" data-remove-array-row>×</button>
        </div>`);
      return;
    }

    const addItemPedimento = event.target.closest('[data-add-item-pedimento]');
    if (addItemPedimento) {
      const itemRow = addItemPedimento.closest('[data-item-row]');
      const list = itemRow?.querySelector('[data-item-pedimentos-list]');
      list?.querySelector('[data-empty-item-pedimentos]')?.remove();
      list?.insertAdjacentHTML('beforeend', renderItemPedimentoRow({}));
      return;
    }

    const addItem = event.target.closest('[data-add-item]');
    if (addItem) {
      const editor = addItem.closest('[data-review-editor]');
      const list = editor?.querySelector('[data-items-list]');
      list?.querySelector('[data-empty-array]')?.remove();
      const index = list?.querySelectorAll('[data-item-row]').length || 0;
      list?.insertAdjacentHTML('beforeend', renderItemRow({}, index));
      updateItemNumbers(list);
      return;
    }

    const remove = event.target.closest('[data-remove-array-row]');
    if (remove) {
      const row = remove.closest('[data-item-pedimento-row], [data-item-row], [data-pedimento-row]');
      const list = row?.parentElement;
      row?.remove();
      updateItemNumbers(list);
    }
  });

  selectAllReadyButton?.addEventListener('click', () => {
    const readyIds = state.rows.filter(row => row.can_import && row.commit_status === 'pending').map(row => Number(row.id));
    const allSelected = readyIds.length > 0 && readyIds.every(id => state.selected.has(id));
    readyIds.forEach(id => { if (allSelected) state.selected.delete(id); else state.selected.add(id); });
    renderRows(state.rows);
  });

  validateBatchButton?.addEventListener('click', () => validateBatch(true));
  importSelectedButton?.addEventListener('click', openCommitModal);
  confirmCommitButton?.addEventListener('click', commitSelected);

  updateFileList();
  if (config.initialBatch) loadBatch(config.initialBatch);
})();
