(function tableI18nBootstrap(window, document) {
  'use strict';

  const requested = new URLSearchParams(window.location.search).get('lang');
  const openerLanguage = (() => {
    try {
      return window.opener && !window.opener.closed ? window.opener.document.documentElement.lang : '';
    } catch (error) {
      return '';
    }
  })();
  const language = String(requested || openerLanguage || document.documentElement.lang || 'es')
    .toLowerCase().startsWith('en') ? 'en' : 'es';

  const exact = new Map([
    ['Extraer partidas (selector de SEC con memoria)', 'Extract line items (persistent SEC selector)'],
    ['Extraer partidas desde la tabla (AJAX + selector de SEC)', 'Extract line items from the table (AJAX + SEC selector)'],
    ['Archivo PDF', 'PDF file'], ['Procesar PDF', 'Process PDF'],
    ['SECs detectados', 'Detected SECs'], ['elige cuáles mostrar', 'choose which ones to show'],
    ['Buscar', 'Search'], ['Seleccionar todo', 'Select all'], ['Ninguno', 'None'], ['Invertir', 'Invert'],
    ['Seleccionados: 0', 'Selected: 0'],
    ['Aplicar filtro (cliente)', 'Apply filter (browser)'], ['Aplicar filtro (servidor)', 'Apply filter (server)'],
    ['Cabecera del pedimento', 'Customs entry header'], ['Número de pedimento', 'Customs entry number'],
    ['Cve. pedimento', 'Customs entry code'], ['Razón social', 'Legal business name'],
    ['Fecha de entrada', 'Entry date'], ['Fecha de pago', 'Payment date'],
    ['Completa los datos de cabecera que quieras enviar al formulario principal (opcional).', 'Complete the header data you want to send to the main form (optional).'],
    ['Partidas mostradas:', 'Displayed line items:'], ['Exportar JSON', 'Export JSON'],
    ['Descripción', 'Description'], ['Fracción', 'Tariff code'], ['UMC Inicial', 'Initial UMC'], ['UMC Cant.', 'UMC Qty.'],
    ['Diagnóstico', 'Diagnostics'], ['Limpiar selección', 'Clear selection'],
    ['Enviar al formulario principal', 'Send to main form'],
    ['Selecciona un PDF.', 'Select a PDF file.'],
    ['Error: Servidor no devolvió JSON.', 'Error: the server did not return JSON.'],
    ['Error: el servidor no devolvió JSON.', 'Error: the server did not return JSON.'],
    ['Fallo inesperado', 'Unexpected failure'],
    ['Procesando...', 'Processing...'],
    ['No hay datos para exportar.', 'There is no data to export.']
  ]);
  const attributes = new Map([
    ['Ej: SEC 1, 25, 120…', 'E.g.: SEC 1, 25, 120…'],
    ['Vuelve a pedir al servidor solo estas SEC (opcional)', 'Request only these SECs from the server again (optional)'],
    ['Ej. 18  48  3000  0001234', 'E.g. 18  48  3000  0001234'], ['Ej. A1', 'E.g. A1'],
    ['Razón social del importador', 'Importer legal business name']
  ]);
  const text = (spanish, english) => language === 'en' ? english : spanish;

  function translateValue(value) {
    if (language !== 'en') return value;
    const original = String(value || '');
    const trimmed = original.trim();
    if (exact.has(trimmed)) return original.replace(trimmed, exact.get(trimmed));
    const replacements = [
      [/^Seleccionados:\s*(\d+)\s*—\s*ej:\s*(.+)$/u, 'Selected: $1 — e.g.: $2'],
      [/^Seleccionados:\s*(\d+)$/u, 'Selected: $1'],
      [/^Mostrando:\s*(\d+)\s*de\s*(\d+)$/u, 'Showing: $1 of $2'],
      [/^Partidas mostradas:\s*(\d+)$/u, 'Displayed line items: $1'],
      [/^Error de red\/JS:\s*(.+)$/u, 'Network/JavaScript error: $1']
    ];
    for (const [pattern, replacement] of replacements) {
      if (pattern.test(trimmed)) return original.replace(trimmed, trimmed.replace(pattern, replacement));
    }
    return original;
  }

  function translateNode(root) {
    if (language !== 'en' || !root) return;
    if (root.nodeType === Node.TEXT_NODE) {
      const translated = translateValue(root.nodeValue || '');
      if (translated !== root.nodeValue) root.nodeValue = translated;
      return;
    }
    if (root.nodeType !== Node.ELEMENT_NODE && root.nodeType !== Node.DOCUMENT_NODE) return;
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((node) => {
      const translated = translateValue(node.nodeValue || '');
      if (translated !== node.nodeValue) node.nodeValue = translated;
    });
  }

  function translateStaticDom() {
    document.documentElement.lang = language;
    if (language !== 'en') return;
    if (exact.has(document.title)) document.title = exact.get(document.title);
    translateNode(document.body);
    document.querySelectorAll('[placeholder], [title], [aria-label]').forEach((element) => {
      ['placeholder', 'title', 'aria-label'].forEach((attribute) => {
        const value = element.getAttribute(attribute);
        if (value && attributes.has(value)) element.setAttribute(attribute, attributes.get(value));
      });
    });
  }

  window.TableI18n = Object.freeze({ language, text });
  translateStaticDom();
  if (language === 'en' && document.body) {
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        if (mutation.type === 'characterData') translateNode(mutation.target);
        mutation.addedNodes.forEach(translateNode);
      });
    });
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
  }
})(window, document);
