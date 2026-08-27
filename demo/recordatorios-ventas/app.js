(() => {
  const STORAGE_KEY = 'exacto-demo-recordatorios-v8';
  const IDLE_NOTIFY_DAYS = 3;
  const INVOICE_MIN = 2;
  const INVOICE_MAX = 60;
  const COMMENTS_MIN = 3;
  const COMMENTS_MAX = 500;

  /** Actores demo (quién hace el seguimiento). */
  const ACTORS = {
    ventas: { name: 'María López', roleLabel: 'Ventas' },
    ventasAlt: { name: 'Carlos Ruiz', roleLabel: 'Ventas' },
    compras: { name: 'Gerente Compras', roleLabel: 'Compras' },
  };

  const FOLLOW_LABELS = {
    negociacion: 'Negociación',
    ganada: 'Ganada',
    perdida: 'Perdida',
  };

  /** Contenido fijo del PDF por cotización (siempre distinto, aunque el localStorage esté viejo). */
  const PDF_BY_QUOTE = {
    q1: {
      title: 'Cotización de equipo de cómputo',
      accent: '#0f4c81',
      seller: 'María López',
      city: 'Monterrey, NL',
      payment: '50% anticipo · 50% contra entrega',
      validityDays: 10,
      issuedAt: '2026-08-10',
      notes: 'Incluye instalación básica en sitio y garantía de 12 meses en laptops.',
      contact: 'compras@norte.demo',
      lines: [
        { sku: 'NB-5510', desc: 'Laptop empresarial 15" i7 16GB', qty: 2, price: 18500 },
        { sku: 'MON-27F', desc: 'Monitor 27" Full HD', qty: 4, price: 2800 },
        { sku: 'MOU-WL', desc: 'Mouse inalámbrico ergonómico', qty: 6, price: 350 },
      ],
    },
    q2: {
      title: 'Cotización de red y cableado',
      accent: '#6b3fa0',
      seller: 'Carlos Méndez',
      city: 'Guadalajara, JAL',
      payment: 'Crédito 30 días',
      validityDays: 7,
      issuedAt: '2026-08-05',
      notes: 'Entrega en almacén del cliente. No incluye mano de obra de cableado estructurado.',
      contact: 'ti@valle.demo',
      lines: [
        { sku: 'SW-24P', desc: 'Switch gestionable 24 puertos PoE', qty: 5, price: 2100 },
        { sku: 'CAB-CAT6', desc: 'Cable UTP Cat6 (caja 305m)', qty: 2, price: 1225 },
        { sku: 'RJ45-100', desc: 'Conectores RJ45 Cat6 (bolsa 100)', qty: 3, price: 180 },
        { sku: 'PATCH-1M', desc: 'Patch cord Cat6 1m (paquete 10)', qty: 4, price: 420 },
      ],
    },
    q3: {
      title: 'Cotización de infraestructura de servidor',
      accent: '#1a7a4c',
      seller: 'Ana Ruiz',
      city: 'CDMX',
      payment: 'Contado / transferencia',
      validityDays: 15,
      issuedAt: '2026-08-18',
      notes: 'Precio con rack rails. Configuración inicial remota incluida (2 horas).',
      contact: 'infra@techmx.demo',
      lines: [
        { sku: 'SRV-R240', desc: 'Servidor rack 1U 32GB RAM', qty: 1, price: 28400 },
        { sku: 'HDD-2TB', desc: 'Disco SAS 2TB hot-swap', qty: 2, price: 1500 },
        { sku: 'RAID-CTRL', desc: 'Controladora RAID hardware', qty: 1, price: 3200 },
      ],
    },
  };

  const seedQuotes = () => {
    const now = Date.now();
    const daysAgo = (n) => new Date(now - n * 86400000).toISOString();
    return [
      {
        id: 'q1',
        folio: 'COT-DEMO-0001',
        client: 'Distribuidora Norte SA',
        rfc: 'DNO850101XX1',
        amount: '$48,200',
        daysIdle: 5,
        workflowStatus: 'pendiente_envio',
        followUp: null,
        followUpHistory: [],
        lines: PDF_BY_QUOTE.q1.lines,
      },
      {
        id: 'q2',
        folio: 'COT-DEMO-0002',
        client: 'Comercial del Valle',
        rfc: 'CVA920315AB2',
        amount: '$12,950',
        daysIdle: 8,
        workflowStatus: 'en_elaboracion',
        followUp: {
          status: 'negociacion',
          invoice: '',
          comments: '',
          at: daysAgo(4),
          remindAt: daysAgo(1),
          byUser: 'María López',
        },
        followUpHistory: [
          {
            id: 'h-seed-1',
            at: daysAgo(4),
            userName: 'María López',
            fromStatus: null,
            toStatus: 'negociacion',
            remindAt: daysAgo(1),
            invoice: '',
            comments: '',
          },
        ],
        lines: PDF_BY_QUOTE.q2.lines,
      },
      {
        id: 'q3',
        folio: 'COT-DEMO-0003',
        client: 'Tech Solutions MX',
        rfc: 'TSM180722CD3',
        amount: '$31,400',
        daysIdle: 2,
        workflowStatus: 'en_elaboracion',
        followUp: null,
        followUpHistory: [],
        lines: PDF_BY_QUOTE.q3.lines,
      },
    ];
  };

  const defaultState = () => ({
    role: 'compras',
    salesActor: 'ventas', // ventas | ventasAlt
    quotes: seedQuotes(),
    notifications: [
      {
        id: 'n-seed-1',
        quoteId: 'q2',
        reasonCode: 'sin_avance',
        message: 'Sin avance: lleva 8 días en elaboración.',
        createdAt: new Date(Date.now() - 4 * 86400000).toISOString(),
        read: false,
      },
    ],
    comprasAlerts: [
      {
        id: 'ca-seed-1',
        quoteId: 'q2',
        userName: 'María López',
        followUpStatus: 'negociacion',
        message: 'María López marcó Negociación en COT-DEMO-0002.',
        createdAt: new Date(Date.now() - 4 * 86400000).toISOString(),
        read: false,
      },
    ],
    selectedQuoteId: null,
    forceStatusForm: null,
  });

  let state = loadState();

  const els = {
    roleCompras: document.getElementById('role-compras'),
    roleVentas: document.getElementById('role-ventas'),
    userChip: document.getElementById('user-chip'),
    bellWrap: document.getElementById('bell-wrap'),
    bellBtn: document.getElementById('bell-btn'),
    bellBadge: document.getElementById('bell-badge'),
    bellDropdown: document.getElementById('bell-dropdown'),
    bellList: document.getElementById('bell-list'),
    viewCompras: document.getElementById('view-compras'),
    viewVentas: document.getElementById('view-ventas'),
    comprasList: document.getElementById('compras-list'),
    ventasList: document.getElementById('ventas-list'),
    ventasStats: document.getElementById('ventas-stats'),
    detailPanel: document.getElementById('detail-panel'),
    detailTitle: document.getElementById('detail-title'),
    detailBody: document.getElementById('detail-body'),
    detailClose: document.getElementById('detail-close'),
    layout: document.querySelector('.layout'),
    resetDemo: document.getElementById('reset-demo'),
    toast: document.getElementById('toast'),
    salesActorWrap: document.getElementById('sales-actor-wrap'),
    salesActor: document.getElementById('sales-actor'),
    startTour: document.getElementById('start-tour'),
    startTourFooter: document.getElementById('start-tour-footer'),
    tourOverlay: document.getElementById('tour-overlay'),
    tourSpotlight: document.getElementById('tour-spotlight'),
    tourCard: document.getElementById('tour-card'),
    tourProgress: document.getElementById('tour-progress'),
    tourTitle: document.getElementById('tour-title'),
    tourText: document.getElementById('tour-text'),
    tourSkip: document.getElementById('tour-skip'),
    tourPrev: document.getElementById('tour-prev'),
    tourNext: document.getElementById('tour-next'),
  };

  const TOUR_SEEN_KEY = 'exacto-demo-tour-seen-v1';
  let tourIndex = -1;
  let tourActive = false;

  const tourSteps = [
    {
      title: 'Bienvenida',
      text: 'Esta demo muestra avisos de Compras a Ventas y el seguimiento comercial (Negociación / Ganada / Perdida), sin tocar el estado real de la cotización.',
      target: '[data-tour="roles"]',
      prepare() {
        applyRole('compras');
        closeDetailSilent();
      },
    },
    {
      title: 'Cambia de rol',
      text: 'Usa estos botones para simular Compras o Ventas. No hay login: es solo para la presentación.',
      target: '[data-tour="roles"]',
      prepare() {
        applyRole('compras');
        closeDetailSilent();
      },
    },
    {
      title: 'Vista Compras',
      text: 'Solo se avisa a Ventas si la cotización está Lista/Terminada, o En elaboración con 3+ días sin avance. El resto queda atenuado.',
      target: '[data-tour="compras-list"]',
      prepare() {
        applyRole('compras');
        closeDetailSilent();
      },
    },
    {
      title: 'Avisar a ventas',
      text: 'Puedes avisar desde la tarjeta. Si ya hay un aviso sin leer del mismo motivo, el sistema no duplica (dedupe).',
      target: '[data-tour="compras-list"]',
      prepare() {
        applyRole('compras');
        closeDetailSilent();
      },
    },
    {
      title: 'Vista Ventas',
      text: 'Cambiamos a Ventas. El dashboard muestra cuántas cotizaciones están en Negociación, Ganada o Perdida.',
      target: '[data-tour="stats"]',
      prepare() {
        applyRole('ventas');
        closeDetailSilent();
        els.bellDropdown.hidden = true;
      },
    },
    {
      title: 'Colores de estatus',
      text: 'Negociación pide fecha ≥ hoy. Ganada pide factura (2–60). Perdida pide comentarios (3–500). Select, no botones.',
      target: '[data-tour="legend"]',
      prepare() {
        applyRole('ventas');
        closeDetailSilent();
      },
    },
    {
      title: 'Campana de notificaciones',
      text: 'Los avisos muestran el motivo: “Lista / Terminada” o “Sin avance en elaboración”. Ábrelos para ir al detalle.',
      target: '[data-tour="bell"]',
      prepare() {
        applyRole('ventas');
        closeDetailSilent();
        els.bellDropdown.hidden = false;
        els.bellBtn.setAttribute('aria-expanded', 'true');
      },
    },
    {
      title: 'Actualizar seguimiento',
      text: 'En el detalle, Ventas marca Negociación (elige fecha), Ganada o Perdida (comentarios). Eso no cambia el workflow: es una capa aparte.',
      target: '[data-tour="detail"]',
      prepare() {
        applyRole('ventas');
        els.bellDropdown.hidden = true;
        openDetailSilent('q2');
      },
    },
    {
      title: 'Reagendar fecha',
      text: 'Si la fecha de Negociación ya venció (como COT-DEMO-0002), aparece “Reagendar fecha” para abrir el detalle y elegir una nueva.',
      target: '[data-tour="ventas-list"]',
      prepare() {
        applyRole('ventas');
        closeDetailSilent();
      },
    },
    {
      title: 'Listo',
      text: 'Prueba el flujo completo: Compras avisa → Ventas abre la campana → marca un estatus. Puedes reiniciar la guía cuando quieras con “Guía paso a paso”.',
      target: '#start-tour',
      prepare() {
        applyRole('compras');
        closeDetailSilent();
      },
    },
  ];

  function applyRole(role) {
    state.role = role;
    saveState();
    render();
  }

  function closeDetailSilent() {
    state.selectedQuoteId = null;
    saveState();
    render();
  }

  function openDetailSilent(quoteId) {
    state.selectedQuoteId = quoteId;
    saveState();
    render();
  }

  function loadState() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return defaultState();
      const parsed = JSON.parse(raw);
      if (!parsed.quotes || !parsed.notifications) return defaultState();
      const merged = { ...defaultState(), ...parsed };
      // Asegurar historial aunque el storage venga incompleto
      merged.quotes = (merged.quotes || []).map((q) => {
        const history = Array.isArray(q.followUpHistory) ? q.followUpHistory : [];
        if (!history.length && q.followUp?.status) {
          history.push({
            id: uid('h-backfill'),
            at: q.followUp.at || new Date().toISOString(),
            userName: q.followUp.byUser || 'Ventas',
            fromStatus: null,
            toStatus: q.followUp.status,
            remindAt: q.followUp.remindAt || null,
            invoice: q.followUp.invoice || '',
            comments: q.followUp.comments || '',
          });
        }
        return { ...q, followUpHistory: history };
      });
      if (!Array.isArray(merged.comprasAlerts)) merged.comprasAlerts = [];
      return merged;
    } catch {
      return defaultState();
    }
  }

  function saveState() {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        role: state.role,
        salesActor: state.salesActor || 'ventas',
        quotes: state.quotes,
        notifications: state.notifications,
        comprasAlerts: state.comprasAlerts || [],
        selectedQuoteId: state.selectedQuoteId,
        forceStatusForm: state.forceStatusForm,
      }),
    );
  }

  function uid(prefix) {
    return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
  }

  function showToast(message) {
    els.toast.textContent = message;
    els.toast.hidden = false;
    clearTimeout(showToast._t);
    showToast._t = setTimeout(() => {
      els.toast.hidden = true;
    }, 2600);
  }

  function toDateInputValue(isoOrDate) {
    const d = isoOrDate ? new Date(isoOrDate) : new Date();
    if (Number.isNaN(d.getTime())) {
      const fallback = new Date();
      fallback.setDate(fallback.getDate() + 3);
      return fallback.toISOString().slice(0, 10);
    }
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  }

  function defaultRemindDateValue() {
    const d = new Date();
    d.setDate(d.getDate() + 3);
    return toDateInputValue(d);
  }

  /** Fecha local YYYY-MM-DD → ISO al final del día local. */
  function dateInputToRemindAt(dateStr) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec((dateStr || '').trim());
    if (!m) return null;
    const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]), 23, 59, 59, 999);
    return d.toISOString();
  }

  function followUpComments(followUp) {
    if (!followUp) return '';
    return (followUp.comments || '').trim();
  }

  function currentActor() {
    if (state.role === 'compras') return ACTORS.compras;
    return ACTORS[state.salesActor] || ACTORS.ventas;
  }

  function followStatusLabel(status) {
    return FOLLOW_LABELS[status] || status || '—';
  }

  function ensureHistory(quote) {
    if (!Array.isArray(quote.followUpHistory)) quote.followUpHistory = [];
    return quote.followUpHistory;
  }

  function unreadComprasAlerts() {
    return (state.comprasAlerts || []).filter((n) => !n.read).length;
  }

  function todayDateInputValue() {
    return toDateInputValue(new Date());
  }

  function isRemindDateNotPast(dateStr) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec((dateStr || '').trim());
    if (!m) return false;
    const chosen = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    chosen.setHours(0, 0, 0, 0);
    return chosen.getTime() >= today.getTime();
  }

  function hasUnreadNotify(quoteId, reasonCode) {
    return state.notifications.some(
      (n) => n.quoteId === quoteId && n.reasonCode === reasonCode && !n.read,
    );
  }

  function workflowLabel(status) {
    if (status === 'pendiente_envio') return 'Lista / Terminada';
    if (status === 'en_elaboracion') return 'En elaboración';
    return status || '—';
  }

  function workflowChip(status) {
    if (status === 'pendiente_envio') {
      return '<span class="chip chip-lista">Lista / Terminada</span>';
    }
    if (status === 'en_elaboracion') {
      return '<span class="chip chip-elaboracion">En elaboración</span>';
    }
    return `<span class="chip chip-idle">${escapeHtml(workflowLabel(status))}</span>`;
  }

  /** Motivo de aviso a ventas: lista_terminada | sin_avance | null */
  function notifyReasonCode(quote) {
    if (!quote) return null;
    if (quote.workflowStatus === 'pendiente_envio') return 'lista_terminada';
    if (quote.workflowStatus === 'en_elaboracion' && quote.daysIdle >= IDLE_NOTIFY_DAYS) {
      return 'sin_avance';
    }
    return null;
  }

  function canNotifySales(quote) {
    if (!quote) return false;
    if (quote.followUp && ['ganada', 'perdida'].includes(quote.followUp.status)) return false;
    return notifyReasonCode(quote) !== null;
  }

  function notifyReasonLabel(code) {
    if (code === 'lista_terminada') return 'Lista / Terminada';
    if (code === 'sin_avance') return 'Sin avance en elaboración';
    return 'Aviso';
  }

  function defaultNotifyMessage(quote) {
    const code = notifyReasonCode(quote);
    if (code === 'lista_terminada') {
      return 'Lista/Terminada: lista para que ventas continúe.';
    }
    if (code === 'sin_avance') {
      return `Sin avance: lleva ${quote.daysIdle} días en elaboración.`;
    }
    return `Cotización ${quote.folio}: revisar seguimiento.`;
  }

  function notifyBlockReason(quote) {
    if (quote.followUp && ['ganada', 'perdida'].includes(quote.followUp.status)) {
      return 'Ya cerrada (ganada/perdida).';
    }
    if (quote.workflowStatus === 'en_elaboracion' && quote.daysIdle < IDLE_NOTIFY_DAYS) {
      return `Recién en elaboración. Ventas recibirá un aviso si pasan ${IDLE_NOTIFY_DAYS} días sin avance.`;
    }
    return 'No candidata a avisar.';
  }

  function formatDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('es-MX', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
    });
  }

  function quoteById(id) {
    return state.quotes.find((q) => q.id === id);
  }

  function unreadCount() {
    return state.notifications.filter((n) => !n.read).length;
  }

  function needsRemindAgain(followUp) {
    if (!followUp || followUp.status !== 'negociacion' || !followUp.remindAt) return false;
    return new Date(followUp.remindAt).getTime() <= Date.now();
  }

  function statusChip(followUp) {
    if (!followUp || !followUp.status) {
      return '<span class="chip chip-idle">Sin seguimiento</span>';
    }
    const map = {
      negociacion: 'chip-negociacion',
      ganada: 'chip-ganada',
      perdida: 'chip-perdida',
    };
    const labels = {
      negociacion: 'Negociación',
      ganada: 'Ganada',
      perdida: 'Perdida',
    };
    let html = `<span class="chip ${map[followUp.status]}">${labels[followUp.status]}</span>`;
    if (needsRemindAgain(followUp)) {
      html += ' <span class="chip chip-remind">Recordar de nuevo</span>';
    }
    return html;
  }

  function setRole(role) {
    state.role = role;
    state.selectedQuoteId = null;
    state.forceStatusForm = null;
    els.bellDropdown.hidden = true;
    els.bellBtn.setAttribute('aria-expanded', 'false');
    saveState();
    render();
  }

  function openDetail(quoteId) {
    state.selectedQuoteId = quoteId;
    saveState();
    render();
  }

  function closeDetail() {
    state.selectedQuoteId = null;
    saveState();
    render();
  }

  function notifySales(quoteId, message) {
    const quote = quoteById(quoteId);
    if (!quote) return;
    const reasonCode = notifyReasonCode(quote);
    if (!canNotifySales(quote) || !reasonCode) {
      showToast(notifyBlockReason(quote));
      return;
    }
    if (hasUnreadNotify(quoteId, reasonCode)) {
      showToast('Ya hay un aviso pendiente para esta cotización.');
      return;
    }
    const text = (message || '').trim() || defaultNotifyMessage(quote);
    state.notifications.unshift({
      id: uid('n'),
      quoteId,
      reasonCode,
      message: text,
      createdAt: new Date().toISOString(),
      read: false,
    });
    saveState();
    showToast(`Aviso enviado · ${notifyReasonLabel(reasonCode)} · ${quote.folio}`);
    render();
  }

  function markNotificationRead(id) {
    const n = state.notifications.find((x) => x.id === id);
    if (!n) return;
    n.read = true;
    saveState();
    render();
  }

  function markAllRead() {
    state.notifications.forEach((n) => {
      n.read = true;
    });
    saveState();
    render();
  }

  function setFollowUp(quoteId, status, extra = {}) {
    const quote = quoteById(quoteId);
    if (!quote) return;

    if (!status || !['negociacion', 'ganada', 'perdida'].includes(status)) {
      showToast('Selecciona un estatus de seguimiento.');
      return;
    }

    if (status === 'ganada') {
      const invoice = (extra.invoice || '').trim();
      if (invoice.length < INVOICE_MIN || invoice.length > INVOICE_MAX) {
        showToast(`Factura/ticket: entre ${INVOICE_MIN} y ${INVOICE_MAX} caracteres.`);
        return;
      }
    }

    const comments = (extra.comments || '').trim();
    if (status === 'perdida') {
      if (comments.length < COMMENTS_MIN || comments.length > COMMENTS_MAX) {
        showToast(`Comentarios: entre ${COMMENTS_MIN} y ${COMMENTS_MAX} caracteres.`);
        return;
      }
    }

    let remindAt = null;
    if (status === 'negociacion') {
      if (!isRemindDateNotPast(extra.remindDate)) {
        showToast('La fecha de seguimiento no puede ser anterior a hoy.');
        return;
      }
      remindAt = dateInputToRemindAt(extra.remindDate);
      if (!remindAt) {
        showToast('Elige la fecha de seguimiento.');
        return;
      }
    }

    const now = new Date().toISOString();
    const fromStatus = quote.followUp?.status || null;
    const actor = currentActor();
    const invoice = status === 'ganada' ? (extra.invoice || '').trim() : '';
    const commentsSaved = status === 'perdida' ? comments : '';

    quote.followUp = {
      status,
      invoice,
      comments: commentsSaved,
      at: now,
      remindAt,
      byUser: actor.name,
    };

    ensureHistory(quote).unshift({
      id: uid('h'),
      at: now,
      userName: actor.name,
      fromStatus,
      toStatus: status,
      remindAt,
      invoice,
      comments: commentsSaved,
    });

    if (!state.comprasAlerts) state.comprasAlerts = [];
    state.comprasAlerts.unshift({
      id: uid('ca'),
      quoteId,
      userName: actor.name,
      followUpStatus: status,
      message: `${actor.name} marcó ${followStatusLabel(status)} en ${quote.folio}.`,
      createdAt: now,
      read: false,
    });

    state.notifications.forEach((n) => {
      if (n.quoteId === quoteId) n.read = true;
    });
    state.forceStatusForm = null;

    saveState();
    const suffix =
      status === 'negociacion' ? ` · recordar ${formatDate(remindAt)}` : '';
    showToast(`${quote.folio} → ${followStatusLabel(status)}${suffix} · ${actor.name}`);
    render();
  }

  function openReschedule(quoteId) {
    const quote = quoteById(quoteId);
    if (!quote?.followUp || quote.followUp.status !== 'negociacion') return;
    if (!needsRemindAgain(quote.followUp)) {
      showToast('Solo se reagenda cuando la fecha ya venció.');
      return;
    }
    state.selectedQuoteId = quoteId;
    state.forceStatusForm = 'negociacion';
    saveState();
    render();
  }

  function openFromNotification(notifId) {
    const n = state.notifications.find((x) => x.id === notifId);
    if (!n) return;
    n.read = true;
    els.bellDropdown.hidden = true;
    els.bellBtn.setAttribute('aria-expanded', 'false');
    const quote = quoteById(n.quoteId);
    state.selectedQuoteId = n.quoteId;
    const closed = quote?.followUp && ['ganada', 'perdida'].includes(quote.followUp.status);
    // Cerradas: solo detalle. Sin cierre: desplegar Negociación + fecha.
    state.forceStatusForm = closed ? null : 'negociacion';
    saveState();
    render();
  }

  function money(n) {
    return new Intl.NumberFormat('es-MX', {
      style: 'currency',
      currency: 'MXN',
      maximumFractionDigits: 0,
    }).format(n);
  }

  function resolvePdfData(quote) {
    const catalog = PDF_BY_QUOTE[quote.id] || {
      title: 'Cotización comercial',
      accent: '#0f4c81',
      seller: 'Ventas Exacto',
      city: 'México',
      payment: 'Por definir',
      validityDays: 15,
      issuedAt: new Date().toISOString().slice(0, 10),
      notes: 'Documento de demostración.',
      contact: 'ventas@exacto.demo',
      lines: quote.lines || [{ sku: 'DEMO-001', desc: 'Partida de demostración', qty: 1, price: 1000 }],
    };
    return {
      ...catalog,
      folio: quote.folio,
      client: quote.client,
      rfc: quote.rfc || 'XAXX010101000',
      lines: catalog.lines,
    };
  }

  function openQuotePdf(quoteId) {
    const quote = quoteById(quoteId);
    if (!quote) return;
    const pdf = resolvePdfData(quote);
    const lines = pdf.lines;
    const subtotal = lines.reduce((sum, line) => sum + line.qty * line.price, 0);
    const iva = Math.round(subtotal * 0.16);
    const total = subtotal + iva;
    const rows = lines
      .map(
        (line, idx) => `
        <tr>
          <td>${idx + 1}</td>
          <td>${escapeHtml(line.sku)}</td>
          <td>${escapeHtml(line.desc)}</td>
          <td class="num">${line.qty}</td>
          <td class="num">${money(line.price)}</td>
          <td class="num">${money(line.qty * line.price)}</td>
        </tr>`,
      )
      .join('');

    const html = `<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <title>PDF · ${escapeHtml(pdf.folio)}</title>
  <style>
    :root { --accent: ${pdf.accent}; }
    body { font-family: "Segoe UI", Arial, sans-serif; margin: 0; background: #e8ecf1; color: #1a2332; }
    .bar { display: flex; justify-content: space-between; align-items: center; gap: 1rem;
           padding: 0.75rem 1.25rem; background: #16324f; color: #fff; }
    .bar button { border: 0; border-radius: 8px; padding: 0.45rem 0.9rem; cursor: pointer; font: inherit; }
    .bar .print { background: #fff; color: #16324f; font-weight: 650; }
    .bar .close { background: transparent; color: #fff; border: 1px solid rgba(255,255,255,.4); }
    .sheet { max-width: 820px; margin: 1.25rem auto; background: #fff; padding: 2rem 2.25rem;
             box-shadow: 0 10px 30px rgba(0,0,0,.12); min-height: 90vh; border-top: 6px solid var(--accent); }
    .head { display: flex; justify-content: space-between; gap: 1rem; border-bottom: 2px solid var(--accent); padding-bottom: 1rem; }
    .brand { font-size: 1.5rem; font-weight: 700; color: var(--accent); }
    .doc-type { margin-top: 0.25rem; font-weight: 650; color: #1a2332; }
    .meta { text-align: right; font-size: 0.92rem; color: #5b6b7c; }
    .meta strong { color: #1a2332; font-size: 1.15rem; display: block; }
    .badge { display: inline-block; margin-top: 0.35rem; padding: 0.15rem 0.5rem; border-radius: 999px;
             background: color-mix(in srgb, var(--accent) 15%, white); color: var(--accent); font-size: 0.75rem; font-weight: 700; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin: 1.25rem 0; }
    .block h2 { margin: 0 0 0.35rem; font-size: 0.8rem; text-transform: uppercase; letter-spacing: .04em; color: #5b6b7c; }
    table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; font-size: 0.9rem; }
    th, td { border-bottom: 1px solid #d8dee6; padding: 0.55rem 0.4rem; text-align: left; }
    th { background: #f3f6fa; font-size: 0.78rem; color: #5b6b7c; }
    .num { text-align: right; white-space: nowrap; }
    .totals { margin-top: 1rem; margin-left: auto; width: 260px; }
    .totals div { display: flex; justify-content: space-between; padding: 0.25rem 0; }
    .totals .grand { font-weight: 700; font-size: 1.1rem; border-top: 2px solid var(--accent); margin-top: 0.35rem; padding-top: 0.45rem; color: var(--accent); }
    .notes { margin-top: 1.5rem; padding: 0.85rem 1rem; background: #f7f9fc; border-left: 4px solid var(--accent); font-size: 0.88rem; }
    .foot { margin-top: 2rem; font-size: 0.78rem; color: #5b6b7c; display: flex; justify-content: space-between; gap: 1rem; }
    @media print {
      body { background: #fff; }
      .bar { display: none; }
      .sheet { box-shadow: none; margin: 0; max-width: none; }
    }
  </style>
</head>
<body>
  <div class="bar">
    <span>PDF · ${escapeHtml(pdf.folio)} · ${escapeHtml(pdf.title)}</span>
    <span>
      <button type="button" class="print" onclick="window.print()">Imprimir / Guardar PDF</button>
      <button type="button" class="close" onclick="window.close()">Cerrar</button>
    </span>
  </div>
  <div class="sheet">
    <div class="head">
      <div>
        <div class="brand">Exacto</div>
        <div class="doc-type">${escapeHtml(pdf.title)}</div>
        <span class="badge">${escapeHtml(pdf.folio)}</span>
      </div>
      <div class="meta">
        <strong>${escapeHtml(pdf.folio)}</strong>
        <div>Emisión: ${formatDate(pdf.issuedAt)}</div>
        <div>Vigencia: ${pdf.validityDays} días</div>
        <div>Vendedor: ${escapeHtml(pdf.seller)}</div>
      </div>
    </div>
    <div class="grid">
      <div class="block">
        <h2>Cliente</h2>
        <div><strong>${escapeHtml(pdf.client)}</strong></div>
        <div>RFC: ${escapeHtml(pdf.rfc)}</div>
        <div>Contacto: ${escapeHtml(pdf.contact)}</div>
      </div>
      <div class="block">
        <h2>Entrega / pago</h2>
        <div>Ciudad: ${escapeHtml(pdf.city)}</div>
        <div>Condiciones: ${escapeHtml(pdf.payment)}</div>
        <div>Partidas: ${lines.length}</div>
      </div>
    </div>
    <div class="block">
      <h2>Partidas de ${escapeHtml(pdf.folio)}</h2>
      <table>
        <thead>
          <tr>
            <th>#</th><th>SKU</th><th>Descripción</th>
            <th class="num">Cant.</th><th class="num">P. unit.</th><th class="num">Importe</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
      <div class="totals">
        <div><span>Subtotal</span><span>${money(subtotal)}</span></div>
        <div><span>IVA 16%</span><span>${money(iva)}</span></div>
        <div class="grand"><span>Total</span><span>${money(total)}</span></div>
      </div>
    </div>
    <div class="notes"><strong>Notas:</strong> ${escapeHtml(pdf.notes)}</div>
    <div class="foot">
      <span>Demo local · documento distinto por cotización</span>
      <span>${escapeHtml(pdf.folio)} · ${escapeHtml(pdf.client)}</span>
    </div>
  </div>
</body>
</html>`;

    const win = window.open('', `_blank`, 'noopener,noreferrer,width=900,height=1000');
    if (!win) {
      showToast('Permite ventanas emergentes para ver el PDF.');
      return;
    }
    win.document.open();
    win.document.write(html);
    win.document.close();
    showToast(`PDF ${quote.folio} · ${pdf.title}`);
  }

  function openFromComprasAlert(alertId) {
    const n = (state.comprasAlerts || []).find((x) => x.id === alertId);
    if (!n) return;
    n.read = true;
    els.bellDropdown.hidden = true;
    els.bellBtn.setAttribute('aria-expanded', 'false');
    state.selectedQuoteId = n.quoteId;
    state.forceStatusForm = null;
    saveState();
    render();
  }

  function renderFollowUpHistory(quote, opts = {}) {
    const compact = !!opts.compact;
    const history = ensureHistory(quote);
    if (!history.length) {
      if (compact) return '';
      return `
        <div class="history-block" id="historial-seguimiento">
          <h3 class="history-title">Historial de seguimiento</h3>
          <p class="client">Aún no hay movimientos de ventas.</p>
        </div>
      `;
    }
    const items = (compact ? history.slice(0, 2) : history)
      .map((ev) => {
        const from = ev.fromStatus ? followStatusLabel(ev.fromStatus) : 'Sin seguimiento';
        const to = followStatusLabel(ev.toStatus);
        let detail = '';
        if (ev.toStatus === 'negociacion' && ev.remindAt) {
          detail = `Fecha: ${formatDate(ev.remindAt)}`;
        } else if (ev.toStatus === 'ganada' && ev.invoice) {
          detail = `Factura/ticket: ${escapeHtml(ev.invoice)}`;
        } else if (ev.toStatus === 'perdida' && ev.comments) {
          detail = `Comentarios: ${escapeHtml(ev.comments)}`;
        }
        return `
          <li class="history-item">
            <div class="history-main">
              <strong>${escapeHtml(ev.userName || 'Ventas')}</strong>
              <span>${escapeHtml(from)} → ${escapeHtml(to)}</span>
            </div>
            ${detail ? `<div class="history-detail">${detail}</div>` : ''}
            <div class="history-meta">${formatDate(ev.at)}</div>
          </li>
        `;
      })
      .join('');
    const more =
      compact && history.length > 2
        ? `<p class="client">+${history.length - 2} movimiento(s). Abre el detalle para ver todo.</p>`
        : '';
    return `
      <div class="history-block ${compact ? 'history-block-compact' : ''}" id="historial-seguimiento">
        <h3 class="history-title">Historial de seguimiento</h3>
        <ul class="history-list">${items}</ul>
        ${more}
      </div>
    `;
  }

  function renderBell() {
    els.bellWrap.hidden = false;
    const isVentas = state.role === 'ventas';
    const count = isVentas ? unreadCount() : unreadComprasAlerts();
    if (count > 0) {
      els.bellBadge.hidden = false;
      els.bellBadge.textContent = String(count);
    } else {
      els.bellBadge.hidden = true;
    }

    const head = document.querySelector('.bell-dropdown-head');
    if (head) {
      head.textContent = isVentas ? 'Avisos de compras' : 'Seguimiento de ventas';
    }

    if (isVentas) {
      if (!state.notifications.length) {
        els.bellList.innerHTML = '<div class="notif-empty">Sin notificaciones</div>';
        return;
      }
      els.bellList.innerHTML = state.notifications
        .map((n) => {
          const q = quoteById(n.quoteId);
          const reason = notifyReasonLabel(n.reasonCode);
          return `
            <button type="button" class="notif-item ${n.read ? '' : 'unread'}" data-open-notif="${n.id}">
              <span class="folio">${q ? q.folio : 'Cotización'}</span>
              <span class="notif-reason">${escapeHtml(reason)}</span>
              <span>${escapeHtml(n.message)}</span>
              <span class="meta">${formatDate(n.createdAt)}${n.read ? '' : ' · Nueva'}</span>
            </button>
          `;
        })
        .join('');
      return;
    }

    const alerts = state.comprasAlerts || [];
    if (!alerts.length) {
      els.bellList.innerHTML = '<div class="notif-empty">Sin avisos de seguimiento</div>';
      return;
    }
    els.bellList.innerHTML = alerts
      .map((n) => {
        const q = quoteById(n.quoteId);
        return `
          <button type="button" class="notif-item ${n.read ? '' : 'unread'}" data-open-compras-alert="${n.id}">
            <span class="folio">${q ? q.folio : 'Cotización'}</span>
            <span class="notif-reason">${escapeHtml(followStatusLabel(n.followUpStatus))}</span>
            <span>${escapeHtml(n.message)}</span>
            <span class="meta">${escapeHtml(n.userName)} · ${formatDate(n.createdAt)}${n.read ? '' : ' · Nueva'}</span>
          </button>
        `;
      })
      .join('');
  }

  function renderQuoteCardCompras(q, eligible) {
    const canNotify = canNotifySales(q);
    const reasonCode = notifyReasonCode(q);
    const pendingUnread = canNotify && reasonCode ? hasUnreadNotify(q.id, reasonCode) : false;
    const allowNotify = canNotify && !pendingUnread;
    const muted = !eligible;
    return `
      <article class="card ${muted ? 'card-muted' : ''}">
        <div class="card-top">
          <div>
            <h3>${q.folio}</h3>
            <p class="client">${escapeHtml(q.client)} · ${q.amount}</p>
            <p class="client">${q.daysIdle} días sin avance</p>
          </div>
          <div class="chip-stack">
            ${workflowChip(q.workflowStatus)}
            ${statusChip(q.followUp)}
          </div>
        </div>
        ${
          !canNotify
            ? `<p class="client card-note">${escapeHtml(notifyBlockReason(q))}</p>`
            : pendingUnread
              ? `<p class="client card-note">Ya hay un aviso pendiente (sin leer).</p>`
              : ''
        }
        ${renderFollowUpHistory(q, { compact: true })}
        <div class="card-actions">
          <button type="button" class="btn btn-ghost" data-open-quote="${q.id}">Ver detalle</button>
          <button type="button" class="btn btn-ghost" data-view-pdf="${q.id}">Ver PDF</button>
          <button type="button" class="btn" data-notify="${q.id}" ${allowNotify ? '' : 'disabled'}>
            Avisar a ventas
          </button>
        </div>
      </article>
    `;
  }

  function renderCompras() {
    const eligible = state.quotes.filter((q) => canNotifySales(q));
    const others = state.quotes.filter((q) => !canNotifySales(q));

    els.comprasList.innerHTML = `
      <div class="list-section">
        <h2 class="list-section-title">Candidatas a avisar</h2>
        <p class="list-section-hint">Lista / Terminada, o En elaboración con ${IDLE_NOTIFY_DAYS}+ días sin avance.</p>
        <div class="card-list">
          ${
            eligible.length
              ? eligible.map((q) => renderQuoteCardCompras(q, true)).join('')
              : '<p class="client">No hay candidatas ahora.</p>'
          }
        </div>
      </div>
      <div class="list-section">
        <h2 class="list-section-title">Cotizaciones en seguimiento</h2>
        <p class="list-section-hint">Aún no cumplen la regla (o ya cerradas). No se notifica a ventas.</p>
        <div class="card-list">
          ${
            others.length
              ? others.map((q) => renderQuoteCardCompras(q, false)).join('')
              : '<p class="client">Ninguna.</p>'
          }
        </div>
      </div>
    `;
  }

  function renderVentas() {
    const counts = { negociacion: 0, ganada: 0, perdida: 0 };
    state.quotes.forEach((q) => {
      const s = q.followUp?.status;
      if (s && counts[s] !== undefined) counts[s] += 1;
    });

    els.ventasStats.innerHTML = `
      <div class="stat"><div class="n" style="color:var(--negociacion)">${counts.negociacion}</div><div class="l">Negociación</div></div>
      <div class="stat"><div class="n" style="color:var(--ganada)">${counts.ganada}</div><div class="l">Ganada</div></div>
      <div class="stat"><div class="n" style="color:var(--perdida)">${counts.perdida}</div><div class="l">Perdida</div></div>
    `;

    els.ventasList.innerHTML = state.quotes
      .map((q) => {
        const fu = q.followUp;
        let extra = '';
        if (fu?.status === 'ganada' && fu.invoice) {
          extra = `<p class="client">Factura/ticket: ${escapeHtml(fu.invoice)}</p>`;
        }
        if (fu?.status === 'perdida') {
          const c = followUpComments(fu);
          if (c) extra = `<p class="client">Comentarios: ${escapeHtml(c)}</p>`;
        }
        if (fu?.status === 'negociacion') {
          extra = `<p class="client">Fecha agendada: ${formatDate(fu.remindAt)}</p>`;
        }
        if (fu?.byUser) {
          extra += `<p class="client">Seguimiento: ${escapeHtml(fu.byUser)}</p>`;
        }
        return `
          <article class="card">
            <div class="card-top">
              <div>
                <h3>${q.folio}</h3>
                <p class="client">${escapeHtml(q.client)} · ${q.amount}</p>
                ${extra}
              </div>
              <div>${statusChip(fu)}</div>
            </div>
            ${renderFollowUpHistory(q, { compact: true })}
            <div class="card-actions">
              <button type="button" class="btn btn-ghost" data-view-pdf="${q.id}">Ver PDF</button>
              <button type="button" class="btn" data-open-quote="${q.id}">Actualizar seguimiento</button>
              ${
                needsRemindAgain(fu)
                  ? `<button type="button" class="btn btn-ghost" data-reschedule="${q.id}">Reagendar fecha</button>`
                  : ''
              }
            </div>
          </article>
        `;
      })
      .join('');
  }

  function renderDetail() {
    const quote = quoteById(state.selectedQuoteId);
    if (!quote) {
      els.detailPanel.hidden = true;
      els.layout.classList.remove('with-detail');
      return;
    }

    els.detailPanel.hidden = false;
    els.layout.classList.add('with-detail');
    els.detailTitle.textContent = quote.folio;

    const fu = quote.followUp;
    const isVentas = state.role === 'ventas';
    const isCompras = state.role === 'compras';

    let body = `
      ${renderFollowUpHistory(quote)}
      <div class="detail-meta">
        <div><strong>Cliente:</strong> ${escapeHtml(quote.client)}</div>
        <div><strong>Monto:</strong> ${quote.amount}</div>
        <div><strong>Estado de cotización:</strong> ${workflowChip(quote.workflowStatus)}</div>
        <div><strong>Días sin avance:</strong> ${quote.daysIdle}</div>
        <div><strong>Seguimiento:</strong> ${statusChip(fu)}</div>
        ${fu?.invoice ? `<div><strong>Factura/ticket:</strong> ${escapeHtml(fu.invoice)}</div>` : ''}
        ${
          followUpComments(fu)
            ? `<div><strong>Comentarios:</strong> ${escapeHtml(followUpComments(fu))}</div>`
            : ''
        }
        ${fu?.remindAt && fu.status === 'negociacion' ? `<div><strong>Fecha agendada:</strong> ${formatDate(fu.remindAt)}</div>` : ''}
        ${fu?.byUser ? `<div><strong>Último seguimiento por:</strong> ${escapeHtml(fu.byUser)}</div>` : ''}
      </div>
      <div class="card-actions" style="margin-bottom:0.75rem">
        <button type="button" class="btn btn-ghost" data-view-pdf="${quote.id}">Ver PDF de la cotización</button>
      </div>
    `;

    if (isCompras) {
      const canNotify = canNotifySales(quote);
      const reasonCode = notifyReasonCode(quote);
      const pendingUnread = canNotify && reasonCode ? hasUnreadNotify(quote.id, reasonCode) : false;
      const allowNotify = canNotify && !pendingUnread;
      body += `
        <div class="form-block">
          ${
            canNotify
              ? pendingUnread
                ? `<p class="client" style="margin:0">Ya hay un aviso pendiente (sin leer) · ${escapeHtml(notifyReasonLabel(reasonCode))}</p>`
                : `<p class="client" style="margin:0">Motivo del aviso: <strong>${escapeHtml(notifyReasonLabel(reasonCode))}</strong></p>`
              : `<p class="client" style="margin:0">${escapeHtml(notifyBlockReason(quote))}</p>`
          }
          <label>
            Mensaje para ventas ${allowNotify ? '(opcional)' : ''}
            <textarea id="notify-message" ${allowNotify ? '' : 'disabled'} placeholder="${escapeAttr(
              allowNotify ? defaultNotifyMessage(quote) : 'No disponible',
            )}">${allowNotify ? escapeHtml(defaultNotifyMessage(quote)) : ''}</textarea>
          </label>
          <button type="button" class="btn" id="detail-notify" ${allowNotify ? '' : 'disabled'}>Avisar a ventas</button>
        </div>
      `;
    }

    if (isVentas) {
      const remindRaw =
        fu?.status === 'negociacion' && fu.remindAt ? toDateInputValue(fu.remindAt) : '';
      const remindDefault =
        remindRaw && isRemindDateNotPast(remindRaw) ? remindRaw : defaultRemindDateValue();
      const commentsVal = followUpComments(fu);
      const currentStatus = fu?.status || '';
      body += `
        <div class="form-block">
          <p style="margin:0;color:var(--muted);font-size:0.9rem">
            Marca el resultado comercial (no cambia el estado de cotización).
          </p>
          <label class="status-select-wrap">
            Estatus de seguimiento
            <select id="follow-status-select" class="status-select">
              <option value="">Seleccionar estatus…</option>
              <option value="negociacion" ${currentStatus === 'negociacion' ? 'selected' : ''}>Negociación</option>
              <option value="ganada" ${currentStatus === 'ganada' ? 'selected' : ''}>Ganada</option>
              <option value="perdida" ${currentStatus === 'perdida' ? 'selected' : ''}>Perdida</option>
            </select>
          </label>
          <div id="field-remind-wrap" class="follow-panel" hidden>
            <label>
              Fecha de seguimiento
              <input type="date" id="field-remind-date" min="${escapeAttr(todayDateInputValue())}" value="${escapeAttr(remindDefault)}" />
            </label>
            <p class="follow-panel-hint">Elige cuándo volver a contactar al cliente.</p>
          </div>
          <div id="field-invoice-wrap" class="follow-panel" hidden>
            <label>
              Número de factura o ticket
              <input type="text" id="field-invoice" placeholder="FAC-12345 / TKT-889" value="${escapeAttr(fu?.invoice || '')}" />
            </label>
          </div>
          <div id="field-comments-wrap" class="follow-panel" hidden>
            <label>
              Comentarios
              <textarea id="field-comments" placeholder="Ej. Eligió otro proveedor / precio fuera de presupuesto">${escapeHtml(commentsVal)}</textarea>
            </label>
          </div>
          <button type="button" class="btn" id="confirm-followup" hidden>Guardar seguimiento</button>
        </div>
      `;
    }

    els.detailBody.innerHTML = body;
    // Historial ya va al inicio del detalle; asegurar scroll visible
    requestAnimationFrame(() => {
      document.getElementById('historial-seguimiento')?.scrollIntoView({
        block: 'nearest',
        behavior: 'smooth',
      });
    });

    if (isCompras) {
      document.getElementById('detail-notify')?.addEventListener('click', () => {
        const msg = document.getElementById('notify-message')?.value || '';
        notifySales(quote.id, msg);
      });
    }

    if (isVentas) {
      let pendingStatus = null;
      const statusSelect = document.getElementById('follow-status-select');
      const remindWrap = document.getElementById('field-remind-wrap');
      const invoiceWrap = document.getElementById('field-invoice-wrap');
      const commentsWrap = document.getElementById('field-comments-wrap');
      const confirmBtn = document.getElementById('confirm-followup');

      const syncFields = (status) => {
        pendingStatus = status || null;
        if (statusSelect) {
          statusSelect.value = status || '';
          statusSelect.dataset.status = status || '';
        }
        remindWrap.hidden = status !== 'negociacion';
        invoiceWrap.hidden = status !== 'ganada';
        commentsWrap.hidden = status !== 'perdida';
        confirmBtn.hidden = !status;
        if (!status) return;
        confirmBtn.textContent =
          status === 'negociacion'
            ? 'Guardar Negociación'
            : status === 'ganada'
              ? 'Guardar Ganada'
              : 'Guardar Perdida';
        if (status === 'negociacion') {
          remindWrap.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
          document.getElementById('field-remind-date')?.focus();
        }
      };

      statusSelect?.addEventListener('change', () => {
        syncFields(statusSelect.value);
      });

      confirmBtn?.addEventListener('click', () => {
        if (!pendingStatus) return;
        setFollowUp(quote.id, pendingStatus, {
          invoice: document.getElementById('field-invoice')?.value || '',
          comments: document.getElementById('field-comments')?.value || '',
          remindDate: document.getElementById('field-remind-date')?.value || '',
        });
      });

      if (state.forceStatusForm === 'negociacion') {
        syncFields('negociacion');
        state.forceStatusForm = null;
        saveState();
      } else if (fu?.status === 'negociacion' || fu?.status === 'ganada' || fu?.status === 'perdida') {
        syncFields(fu.status);
      }
    }
  }

  function render() {
    const isVentas = state.role === 'ventas';
    els.roleCompras.classList.toggle('active', !isVentas);
    els.roleVentas.classList.toggle('active', isVentas);
    els.userChip.textContent = isVentas
      ? `Ventas · ${currentActor().name}`
      : 'Compras · Gerente';
    if (els.salesActorWrap) els.salesActorWrap.hidden = !isVentas;
    if (els.salesActor && state.salesActor) els.salesActor.value = state.salesActor;
    els.viewCompras.hidden = isVentas;
    els.viewVentas.hidden = !isVentas;

    renderBell();
    if (isVentas) renderVentas();
    else renderCompras();
    renderDetail();
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function escapeAttr(str) {
    return escapeHtml(str).replace(/'/g, '&#39;');
  }

  // Events
  els.roleCompras.addEventListener('click', () => setRole('compras'));
  els.roleVentas.addEventListener('click', () => setRole('ventas'));

  els.salesActor?.addEventListener('change', () => {
    state.salesActor = els.salesActor.value;
    saveState();
    render();
  });

  els.bellBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    const open = els.bellDropdown.hidden;
    els.bellDropdown.hidden = !open;
    els.bellBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  document.addEventListener('click', (e) => {
    if (tourActive) return;
    if (!els.bellWrap.contains(e.target)) {
      els.bellDropdown.hidden = true;
      els.bellBtn.setAttribute('aria-expanded', 'false');
    }
  });

  els.bellList.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-open-notif]');
    if (btn) {
      openFromNotification(btn.getAttribute('data-open-notif'));
      return;
    }
    const ca = e.target.closest('[data-open-compras-alert]');
    if (ca) {
      openFromComprasAlert(ca.getAttribute('data-open-compras-alert'));
    }
  });

  document.body.addEventListener('click', (e) => {
    const pdfBtn = e.target.closest('[data-view-pdf]');
    if (pdfBtn) {
      openQuotePdf(pdfBtn.getAttribute('data-view-pdf'));
      return;
    }
    const openBtn = e.target.closest('[data-open-quote]');
    if (openBtn) {
      openDetail(openBtn.getAttribute('data-open-quote'));
      return;
    }
    const notifyBtn = e.target.closest('[data-notify]');
    if (notifyBtn) {
      if (notifyBtn.disabled) return;
      notifySales(notifyBtn.getAttribute('data-notify'), '');
      return;
    }
    const rescheduleBtn = e.target.closest('[data-reschedule]');
    if (rescheduleBtn) {
      openReschedule(rescheduleBtn.getAttribute('data-reschedule'));
    }
  });

  els.detailClose.addEventListener('click', closeDetail);

  els.resetDemo.addEventListener('click', () => {
    localStorage.removeItem(STORAGE_KEY);
    state = defaultState();
    showToast('Demo restablecida');
    render();
  });

  function clearTourHighlight() {
    document.querySelectorAll('.tour-target').forEach((el) => el.classList.remove('tour-target'));
  }

  function positionTour(targetEl) {
    const pad = 10;
    const margin = 16;
    const cardW = Math.min(360, window.innerWidth - margin * 2);
    const cardH = 210;

    const rect = targetEl
      ? targetEl.getBoundingClientRect()
      : {
          top: margin,
          left: margin,
          right: margin,
          bottom: margin,
          width: 0,
          height: 0,
        };

    if (targetEl) {
      // Para listas grandes, resalta solo la cabecera (evita tarjeta encima del título)
      const head = targetEl.querySelector('.panel-head, .legend, .stats, .detail-head');
      const spotRect = head && targetEl.matches('[data-tour="compras-list"], [data-tour="ventas-dash"], [data-tour="ventas-list"]')
        ? head.getBoundingClientRect()
        : rect;

      els.tourSpotlight.style.top = `${Math.max(8, spotRect.top - pad)}px`;
      els.tourSpotlight.style.left = `${Math.max(8, spotRect.left - pad)}px`;
      els.tourSpotlight.style.width = `${Math.min(spotRect.width + pad * 2, window.innerWidth - 16)}px`;
      els.tourSpotlight.style.height = `${Math.min(spotRect.height + pad * 2, 160)}px`;
      els.tourSpotlight.hidden = false;
      targetEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    } else {
      els.tourSpotlight.hidden = true;
    }

    // Posicionar la tarjeta FUERA del área resaltada (prioridad: abajo → derecha → arriba → fija abajo)
    const spot = {
      top: parseFloat(els.tourSpotlight.style.top) || rect.top,
      left: parseFloat(els.tourSpotlight.style.left) || rect.left,
      width: parseFloat(els.tourSpotlight.style.width) || rect.width,
      height: parseFloat(els.tourSpotlight.style.height) || rect.height,
    };
    spot.bottom = spot.top + spot.height;
    spot.right = spot.left + spot.width;

    let top;
    let left;

    if (window.innerWidth < 860) {
      top = window.innerHeight - cardH - margin;
      left = margin;
    } else if (spot.bottom + 12 + cardH < window.innerHeight - margin) {
      top = spot.bottom + 12;
      left = Math.min(Math.max(margin, spot.left), window.innerWidth - cardW - margin);
    } else if (spot.right + 12 + cardW < window.innerWidth - margin) {
      top = Math.min(Math.max(margin, spot.top), window.innerHeight - cardH - margin);
      left = spot.right + 12;
    } else if (spot.top - 12 - cardH > margin) {
      top = spot.top - 12 - cardH;
      left = Math.min(Math.max(margin, spot.left), window.innerWidth - cardW - margin);
    } else {
      top = window.innerHeight - cardH - margin;
      left = margin;
    }

    els.tourCard.style.top = `${top}px`;
    els.tourCard.style.left = `${left}px`;
    els.tourCard.style.bottom = 'auto';
    els.tourCard.style.right = 'auto';
  }

  function showTourStep(index) {
    tourIndex = index;
    tourActive = true;
    const step = tourSteps[index];
    if (!step) {
      endTour(true);
      return;
    }

    step.prepare?.();
    requestAnimationFrame(() => {
      clearTourHighlight();
      const target = step.target ? document.querySelector(step.target) : null;
      els.tourOverlay.hidden = false;
      els.tourProgress.textContent = `Paso ${index + 1} de ${tourSteps.length}`;
      els.tourTitle.textContent = step.title;
      els.tourText.textContent = step.text;
      els.tourPrev.disabled = index === 0;
      els.tourNext.textContent = index === tourSteps.length - 1 ? 'Terminar' : 'Siguiente';
      positionTour(target);
      // Recalcular tras scroll/layout
      setTimeout(() => positionTour(target), 220);
    });
  }

  function startTour() {
    showTourStep(0);
  }

  function endTour(markSeen) {
    tourActive = false;
    tourIndex = -1;
    els.tourOverlay.hidden = true;
    els.tourSpotlight.hidden = true;
    clearTourHighlight();
    els.bellDropdown.hidden = true;
    els.bellBtn.setAttribute('aria-expanded', 'false');
    if (markSeen) localStorage.setItem(TOUR_SEEN_KEY, '1');
  }

  els.startTour?.addEventListener('click', startTour);
  els.startTourFooter?.addEventListener('click', startTour);
  els.tourSkip?.addEventListener('click', () => endTour(true));
  els.tourPrev?.addEventListener('click', () => {
    if (tourIndex > 0) showTourStep(tourIndex - 1);
  });
  els.tourNext?.addEventListener('click', () => {
    if (tourIndex >= tourSteps.length - 1) endTour(true);
    else showTourStep(tourIndex + 1);
  });

  window.addEventListener('resize', () => {
    if (!tourActive || tourIndex < 0) return;
    const step = tourSteps[tourIndex];
    const target = step?.target ? document.querySelector(step.target) : null;
    positionTour(target);
  });

  document.addEventListener('keydown', (e) => {
    if (!tourActive) return;
    if (e.key === 'Escape') endTour(true);
    if (e.key === 'ArrowRight') els.tourNext.click();
    if (e.key === 'ArrowLeft' && tourIndex > 0) els.tourPrev.click();
  });

  render();

  if (!localStorage.getItem(TOUR_SEEN_KEY)) {
    setTimeout(startTour, 400);
  }
})();
