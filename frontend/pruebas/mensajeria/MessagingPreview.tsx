import { useEffect, useRef, useState, type FormEvent } from 'react'
import {
  ArrowLeft, ArrowRight, Bell, Check, CheckCheck, ChevronRight, Clock3,
  FileText, Info, LayoutDashboard, MessageCircle, MessageSquareText,
  Plus, Search, Send, ShieldCheck, Users, X, GitBranch,
} from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { QuoteStatusBadge } from '@/components/ui/QuoteStatusBadge'
import { formatCurrency } from '@/lib/format'
import { ROLE_LABEL, STORAGE_KEY, loadThreads, seedThreads, type ChatRole, type ChatThread } from './data'

import { RequestForm, StageBadge, WorkflowFollowup } from './WorkflowControls'
import { CHECK_LABELS, WORKFLOW_KEY, dateLabel, loadRequests, localDate, seedRequests, type RequestInput, type WorkflowRequest } from './workflow-data'

type View = 'messages' | 'notifications'
type ChatFilter = 'all' | 'unread' | 'reminders'
type DialogView = 'new' | 'workflow' | 'request' | 'action' | 'quote' | 'reminder' | 'proposal' | 'reset'
type WorkflowAction = 'question' | 'resume' | 'ready' | 'sent' | 'close' | 'reopen'

function initials(name: string) {
  return name.split(' ').slice(0, 2).map((part) => part.charAt(0)).join('')
}

function markRead(thread: ChatThread, role: ChatRole): ChatThread {
  return {
    ...thread, unread: { ...thread.unread, [role]: 0 },
    messages: thread.messages.map((message) => message.author !== 'sistema' && message.author !== role
      ? { ...message, readBy: Array.from(new Set([...(message.readBy ?? []), role])) } : message),
  }
}

export function MessagingPreview() {
  const [threads, setThreads] = useState(() => loadThreads())
  const [activeId, setActiveId] = useState(() => loadThreads()[0].id)
  const [role, setRole] = useState<ChatRole>('ventas')
  const [view, setView] = useState<View>('messages')
  const [requests, setRequests] = useState(() => loadRequests(loadThreads()))
  const [pendingAction, setPendingAction] = useState<WorkflowAction>('question')
  const [filter, setFilter] = useState<ChatFilter>('all')
  const [search, setSearch] = useState('')
  const [drafts, setDrafts] = useState<Record<string, string>>({})
  const [mobileView, setMobileView] = useState<'list' | 'chat'>('list')
  const [dialogView, setDialogView] = useState<DialogView>('new')
  const [feedback, setFeedback] = useState('')
  const dialogRef = useRef<HTMLDialogElement>(null)
  const logRef = useRef<HTMLDivElement>(null)
  const composerRef = useRef<HTMLTextAreaElement>(null)
  const active = threads.find((thread) => thread.id === activeId) ?? threads[0]
  const draftKey = `${role}:${active.id}`
  const draft = drafts[draftKey] ?? ''
  const activeRequest = requests.find((request) => request.id === active.id)
  const noticeCount = requests.filter((request) => request.notices[role]).length
  const unreadTotal = threads.reduce((total, thread) => total + thread.unread[role], 0)
  const remindCount = threads.filter((thread) => thread.reminder).length
  const normalizedSearch = search.trim().toLocaleLowerCase('es-MX')
  const visibleThreads = threads.filter((thread) =>
    `${thread.folio} ${thread.client} ${thread.sales} ${thread.purchasing}`.toLocaleLowerCase('es-MX').includes(normalizedSearch) &&
    (filter !== 'unread' || thread.unread[role] > 0) && (filter !== 'reminders' || !!thread.reminder),
  )
  const otherRole: ChatRole = role === 'ventas' ? 'compras' : 'ventas'
  const counterpart = role === 'ventas' ? active.purchasing : active.sales
  const ownName = role === 'ventas' ? active.sales : active.purchasing

  // Scroll only the conversation, never the document or the dashboard below it.
  useEffect(() => {
    const log = logRef.current
    if (log) log.scrollTop = log.scrollHeight
  }, [active.id, active.messages.length, mobileView, view])

  function commit(next: ChatThread[]) {
    setThreads(next)
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(next)) }
    catch { setFeedback('El navegador no permite guardar. Los mensajes se conservarán mientras esta pantalla esté abierta.') }
  }

  function selectThread(id: string) {
    commit(threads.map((thread) => thread.id === id ? markRead(thread, role) : thread))
    commitRequests(requests.map((request) => request.id === id ? { ...request, notices: { ...request.notices, [role]: null } } : request))
    setActiveId(id)
    setMobileView('chat')
    setView('messages')
    setFeedback('')
  }

  function changeRole(next: ChatRole) {
    setRole(next)
    if (view === 'messages' && (window.innerWidth >= 768 || mobileView === 'chat')) {
      commit(threads.map((thread) => thread.id === active.id ? markRead(thread, next) : thread))
      commitRequests(requests.map((request) => request.id === active.id ? { ...request, notices: { ...request.notices, [next]: null } } : request))
    }
    setFilter('all')
    setFeedback('')
  }

  function showDialog(kind: DialogView) {
    setDialogView(kind)
    dialogRef.current?.showModal()
  }

  function send(event?: FormEvent) {
    event?.preventDefault()
    if (!draft.trim() || activeRequest?.stage === 'cerrada') return
    const now = new Date()
    commit(threads.map((thread) => thread.id === active.id ? {
      ...thread,
      unread: { ...thread.unread, [otherRole]: thread.unread[otherRole] + 1 },
      messages: [...thread.messages, {
        id: crypto.randomUUID(), author: role, text: draft.trim(), day: 'Hoy',
        time: now.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: false }), readBy: [],
      }],
    } : thread))
    setDrafts({ ...drafts, [draftKey]: '' })
    setFeedback('Mensaje guardado en la conversación de prueba.')
    composerRef.current?.focus({ preventScroll: true })
  }

  function commitRequests(next: WorkflowRequest[]) {
    setRequests(next)
    try { localStorage.setItem(WORKFLOW_KEY, JSON.stringify(next)) }
    catch { setFeedback('El navegador no permite guardar el seguimiento. Se conservará mientras esta pantalla esté abierta.') }
  }

  function createRequest(input: RequestInput) {
    if (role !== 'ventas') return
    const number = Math.max(16, ...requests.map((item) => Number(item.folio.split('-').at(-1)) || 0)) + 1
    const request: WorkflowRequest = {
      ...input, id: crypto.randomUUID(), folio: `COT-PRUEBA-${String(number).padStart(4, '0')}`,
      sales: 'Ericka Martínez', owner: null, stage: 'por_tomar', created: localDate(),
      checks: { stock: false, price: false, delivery: false },
      notices: { ventas: null, compras: 'Ventas envió una nueva solicitud. Tómala para iniciar el seguimiento.' },
    }
    commitRequests([request, ...requests])
    setView('messages'); dialogRef.current?.close()
    setFeedback(`${request.folio} enviada a la bandeja de Compras. Cambia a Compras para recibirla y tomarla.`)
  }

  function takeRequest(id: string) {
    const request = requests.find((item) => item.id === id)
    if (role !== 'compras' || !request || request.owner || request.stage !== 'por_tomar') return
    const now = new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: false })
    const next = { ...request, owner: 'Ana López', stage: 'en_proceso' as const,
      notices: { ventas: 'Ana López tomó tu solicitud. El chat está abierto para dar seguimiento.', compras: null } }
    commitRequests(requests.map((item) => item.id === id ? next : item))
    if (!threads.some((thread) => thread.id === id)) commit([{
      id, folio: request.folio, client: request.client, sales: request.sales, purchasing: 'Ana López',
      status: 'en_elaboracion', total: 0, unread: { ventas: 1, compras: 0 }, reminder: null, products: request.products,
      messages: [
        { id: crypto.randomUUID(), author: 'sistema', text: 'Ventas envió esta solicitud a la bandeja de Compras.', time: now, day: 'Hoy' },
        { id: crypto.randomUUID(), author: 'ventas', text: request.brief, time: now, day: 'Hoy', readBy: ['compras'] },
        { id: crypto.randomUUID(), author: 'sistema', text: 'Ana López tomó la solicitud. Se abrió el chat de seguimiento con Ventas.', time: now, day: 'Hoy' },
      ],
    }, ...threads])
    setActiveId(id); setView('messages'); setMobileView('chat'); setFilter('all'); setSearch('')
    setFeedback(`Tomaste ${request.folio}. Ahora eres responsable de su seguimiento.`)
  }

  function checkPoint(key: keyof WorkflowRequest['checks']) {
    if (!activeRequest || role !== 'compras' || activeRequest.stage !== 'en_proceso') return
    const checked = !activeRequest.checks[key]
    commitRequests(requests.map((item) => item.id === active.id ? { ...item, checks: { ...item.checks, [key]: checked } } : item))
    commit(threads.map((thread) => thread.id === active.id ? { ...thread, messages: [...thread.messages, {
      id: crypto.randomUUID(), author: 'sistema', text: `Compras ${checked ? 'confirmó' : 'reabrió'}: ${CHECK_LABELS[key].toLocaleLowerCase('es-MX')}.`, day: 'Hoy',
      time: new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: false }),
    }] } : thread))
  }

  function completeAction(action: WorkflowAction, text = '') {
    if (!activeRequest) return
    const allowed = role === 'compras'
      ? activeRequest.stage === 'en_proceso' && (action === 'question' || (action === 'ready' && Object.values(activeRequest.checks).every(Boolean)))
      : (activeRequest.stage === 'espera_ventas' && action === 'resume') || (activeRequest.stage === 'lista' && (action === 'sent' || action === 'reopen')) || (activeRequest.stage === 'enviada' && action === 'close')
    if (!allowed || (['question', 'resume', 'reopen', 'close'].includes(action) && !text.trim())) return
    const stage = { question: 'espera_ventas', resume: 'en_proceso', ready: 'lista', sent: 'enviada', close: 'cerrada', reopen: 'en_proceso' }[action] as WorkflowRequest['stage']
    const event = { question: 'Compras pidió información. El siguiente paso es de Ventas.', resume: 'Ventas respondió. Compras puede continuar la cotización.', ready: 'Compras terminó la revisión. Cotización lista para enviar por Ventas.', sent: 'Ventas confirmó que envió la cotización al cliente.', close: 'Ventas cerró el seguimiento. El historial se conserva.', reopen: 'Ventas solicitó un ajuste. Compras retomará la cotización.' }[action]
    commitRequests(requests.map((item) => item.id === active.id ? { ...item, stage,
      checks: action === 'reopen' ? { ...item.checks, price: false, delivery: false } : item.checks,
      notices: { ...item.notices, [role]: null, [otherRole]: event },
    } : item))
    const time = new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: false })
    commit(threads.map((thread) => thread.id === active.id ? { ...thread,
      status: action === 'ready' ? 'pendiente_envio' : action === 'sent' ? 'enviada' : action === 'reopen' ? 'en_elaboracion' : thread.status,
      unread: { ...thread.unread, [otherRole]: thread.unread[otherRole] + 1 },
      messages: [...thread.messages, ...(text.trim() ? [{ id: crypto.randomUUID(), author: role, text: text.trim(), time, day: 'Hoy', readBy: [] }] : []),
        { id: crypto.randomUUID(), author: 'sistema', text: event, time, day: 'Hoy' }],
    } : thread))
    dialogRef.current?.close(); setFeedback(event)
  }

  function workflowAction(action: WorkflowAction) {
    if (action === 'ready') { completeAction(action); return }
    setPendingAction(action); showDialog('action')
  }

  function resetDemo() {
    const next = seedThreads()
    commit(next)
    commitRequests(seedRequests(next))
    setActiveId(next[0].id)
    setDrafts({})
    setSearch('')
    setFilter('all')
    setRole('ventas')
    setView('messages')
    setMobileView('list')
    setFeedback('Se restablecieron las conversaciones de ejemplo.')
    dialogRef.current?.close()
  }

  const actionTitles = { question: 'Pedir información a Ventas', resume: 'Responder a Compras', ready: 'Cotización lista', sent: 'Confirmar envío al cliente', close: 'Cerrar seguimiento', reopen: 'Solicitar un ajuste a Compras' }
  const dialogTitle = { new: 'Iniciar conversación', workflow: 'Seguimiento de la solicitud', request: 'Enviar solicitud de cotización', action: actionTitles[pendingAction], quote: active.folio, reminder: 'Recordatorio de la cotización', proposal: 'La propuesta de Mensajería', reset: 'Restablecer conversaciones de ejemplo' }[dialogView]

  return <div className="messaging-preview">
    <a className="preview-skip" href="#messaging-main">Ir al contenido</a>
    <aside className="preview-sidebar" aria-label="Navegación del prototipo">
      <div className="preview-brand"><span className="brand-symbol">e<span>.</span></span><div><strong>exacto</strong><span>Cotizaciones</span></div></div>
      <p className="nav-caption">ESPACIO DE TRABAJO</p>
      <nav>
        <button className="nav-item" onClick={() => setView('notifications')}><LayoutDashboard aria-hidden />Dashboard</button>
        <button className="nav-item" onClick={() => showDialog('new')}><FileText aria-hidden />Cotizaciones</button>
        <button className={`nav-item ${view === 'notifications' ? 'selected' : ''}`} onClick={() => setView('notifications')}><Bell aria-hidden />Recordatorios{noticeCount > 0 && <span className="nav-badge">{noticeCount}</span>}</button>
        <button className={`nav-item ${view === 'messages' ? 'selected' : ''}`} onClick={() => selectThread(active.id)}><MessageSquareText aria-hidden />Mensajería<span className="nav-badge">{unreadTotal}</span></button>
      </nav>
      <div className="sidebar-note"><ShieldCheck aria-hidden /><strong>Una conversación.<br />Todo el contexto.</strong><p>Compras y Ventas, conectados por el folio.</p></div>
      <div className="sidebar-profile"><span className="avatar avatar-dark">{role === 'ventas' ? 'EM' : 'AL'}</span><div><strong>{role === 'ventas' ? 'Equipo de Ventas' : 'Equipo de Compras'}</strong><span>Vista de demostración</span></div></div>
    </aside>

    <div className="preview-content">
      <header className="preview-topbar">
        <div className="breadcrumb"><span>Espacio de trabajo</span><ChevronRight aria-hidden /><strong>{view === 'messages' ? 'Mensajería' : 'Recordatorios'}</strong></div>
        <div className="topbar-controls"><span className="test-pill"><span />Entorno de pruebas</span><div className="role-switch" aria-label="Cambiar vista de demostración">
          {(['ventas', 'compras'] as const).map((value) => <button key={value} aria-pressed={role === value} className={role === value ? 'active' : ''} onClick={() => changeRole(value)}>{ROLE_LABEL[value]}</button>)}
        </div></div>
      </header>

      <main id="messaging-main" className="preview-main">
        <div className="page-heading"><div><div className="heading-eyebrow"><span className="tiny-line" />COMPRAS + VENTAS</div><h1>{view === 'messages' ? 'Mensajería' : 'Notificaciones y recordatorios'}</h1><p>{view === 'messages' ? 'Cada cotización, una conversación. Todo el seguimiento en un solo lugar.' : 'Del pendiente a la conversación, sin perder el contexto de la cotización.'}</p></div>
          <Button className="new-chat-button" onClick={() => showDialog('new')}><Plus size={18} aria-hidden />Nuevo chat</Button>
        </div>

        {view === 'messages' ? <section className={`chat-workspace mobile-${mobileView}`} aria-label="Mensajería por cotización">
          <aside className="conversation-inbox" aria-label="Conversaciones">
            <div className="inbox-heading"><h2>Conversaciones <span>{threads.length}</span></h2><span className="unread-summary">{unreadTotal} sin leer</span></div>
            <div className="chat-search"><label htmlFor="chat-search">Buscar conversación</label><div><Search size={18} aria-hidden /><input id="chat-search" type="search" placeholder="Folio, cliente o participante" value={search} onChange={(event) => setSearch(event.target.value)} /></div></div>
            <div className="inbox-filters" aria-label="Filtrar conversaciones">
              <button className={filter === 'all' ? 'active' : ''} aria-pressed={filter === 'all'} onClick={() => setFilter('all')}>Todas</button>
              <button className={filter === 'unread' ? 'active' : ''} aria-pressed={filter === 'unread'} onClick={() => setFilter('unread')}>Sin leer<span>{unreadTotal}</span></button>
              <button className={filter === 'reminders' ? 'active' : ''} aria-pressed={filter === 'reminders'} onClick={() => setFilter('reminders')}>Recordatorios<span>{remindCount}</span></button>
            </div>
            <div className="thread-list">
              {visibleThreads.length ? visibleThreads.map((thread) => {
                const lastMessage = thread.messages.at(-1)!
                const contact = role === 'ventas' ? thread.purchasing : thread.sales
                return <button key={thread.id} className={`thread-item ${active.id === thread.id ? 'active' : ''}`} aria-current={active.id === thread.id ? 'true' : undefined} onClick={() => selectThread(thread.id)} aria-label={`${thread.folio}, ${thread.client}, ${thread.unread[role]} mensajes sin leer`}>
                  <span className={`thread-avatar ${thread.reminder ? 'with-reminder' : ''}`}><FileText size={21} aria-hidden /></span>
                  <span className="thread-info"><span className="thread-top"><strong>{thread.folio}</strong><time>{lastMessage.time}</time></span><span className="thread-client">{thread.client}</span><span className="thread-excerpt">{lastMessage.author === role ? 'Tú: ' : lastMessage.author === 'sistema' ? '' : `${contact.split(' ')[0]}: `}{lastMessage.text}</span><span className="thread-bottom"><span>{thread.reminder ? <><Clock3 size={12} aria-hidden />Recordatorio</> : <><Users size={12} aria-hidden />{ROLE_LABEL[otherRole]}</>}</span>{thread.unread[role] > 0 && <span className="thread-unread">{thread.unread[role]}</span>}</span></span>
                </button>
              }) : <div className="inbox-empty"><Search size={28} aria-hidden /><h3>No encontramos conversaciones</h3><p>Prueba otro folio o cambia el filtro.</p><Button variant="secondary" onClick={() => { setSearch(''); setFilter('all') }}>Ver todas</Button></div>}
            </div>
            <div className="inbox-footer"><MessageCircle size={15} aria-hidden /><span>Un chat por folio. Sin conversaciones duplicadas.</span></div>
          </aside>

          <section className="chat-panel" aria-label={`Conversación de ${active.folio}`}>
            <header className="chat-header"><button className="icon-button mobile-back" aria-label="Volver a conversaciones" onClick={() => setMobileView('list')}><ArrowLeft size={20} aria-hidden /></button><span className="avatar avatar-indigo">{initials(counterpart)}</span><div className="chat-header-copy"><h2>{active.folio}</h2><p>{counterpart} <span>· {ROLE_LABEL[otherRole]}</span></p></div><button className="icon-button" aria-label="Ver seguimiento de la solicitud" onClick={() => showDialog('workflow')}><GitBranch size={19} aria-hidden /></button><button className="icon-button context-button" aria-label="Ver contexto de la cotización" onClick={() => showDialog('quote')}><Info size={20} aria-hidden /></button></header>
            <div className="chat-quote-strip"><span><FileText size={15} aria-hidden />{active.client}</span><QuoteStatusBadge status={active.status} /></div>
            <div className="message-log" ref={logRef} role="log" aria-label="Historial de mensajes" aria-live="polite" aria-relevant="additions text">
              {active.messages.map((message, index) => <div key={message.id}>
                {(index === 0 || active.messages[index - 1].day !== message.day) && <div className="day-divider"><span>{message.day}</span></div>}
                {message.author === 'sistema' ? <div className="system-event"><span>{message.text.startsWith('Recordatorio') ? <Clock3 size={14} aria-hidden /> : <Check size={14} aria-hidden />}{message.text}</span></div> : <div className={`message-row ${message.author === role ? 'outgoing' : 'incoming'}`}>
                  {message.author !== role && <span className="avatar message-avatar">{initials(counterpart)}</span>}
                  <div className="message-group"><span className="message-author">{message.author === role ? 'Tú' : counterpart}</span><div className="message-bubble"><p>{message.text}</p><div className="message-meta"><time>{message.time}</time>{message.author === role && ((message.readBy ?? []).includes(otherRole) ? <span aria-label="Leído"><CheckCheck size={15} aria-hidden />Leído</span> : <span aria-label="Guardado en la demo"><Check size={14} aria-hidden />Guardado</span>)}</div></div></div>
                </div>}
              </div>)}
            </div>
            <form className="chat-composer" onSubmit={send}>
              <div className="composer-heading"><label htmlFor="chat-message">Mensaje para {counterpart.split(' ')[0]}</label><span>{ROLE_LABEL[otherRole]}</span></div>
              <div className="composer-input"><textarea id="chat-message" ref={composerRef} rows={2} maxLength={2000} disabled={activeRequest?.stage === 'cerrada'} placeholder={activeRequest?.stage === 'cerrada' ? 'Seguimiento cerrado. Consulta el historial.' : 'Escribe sobre esta cotización…'} value={draft} onChange={(event) => setDrafts({ ...drafts, [draftKey]: event.target.value })} onKeyDown={(event) => {
                if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) { event.preventDefault(); send() }
              }} /><button className="send-message" type="submit" disabled={!draft.trim() || activeRequest?.stage === 'cerrada'} aria-label="Enviar mensaje"><Send size={20} aria-hidden /></button></div>
              <div className="composer-footer"><span>Enter para enviar <span>·</span> Shift + Enter para nueva línea</span><span>{draft.length}/2000</span></div>
            </form>
          </section>

          <aside className="quote-context" aria-label="Contexto de la cotización">
            <h2>En esta cotización</h2><span className="context-eyebrow">FOLIO</span><strong className="context-folio">{active.folio}</strong><QuoteStatusBadge status={active.status} />
            <div className="context-section"><span className="context-eyebrow">CLIENTE</span><strong>{active.client}</strong><div className="context-total"><span>Total estimado</span><strong>{active.total > 0 ? formatCurrency(active.total) : 'Por cotizar'}</strong></div><Button variant="secondary" className="view-quote" onClick={() => showDialog('quote')}>Ver cotización<ArrowRight size={16} aria-hidden /></Button></div>
            <div className="context-section"><h3>Participantes</h3><div className="participant"><span className="avatar avatar-indigo">{initials(active.sales)}</span><div><strong>{active.sales}</strong><span>Ventas</span></div>{role === 'ventas' && <span className="you-pill">Tú</span>}</div><div className="participant"><span className="avatar avatar-teal">{initials(active.purchasing)}</span><div><strong>{active.purchasing}</strong><span>Compras</span></div>{role === 'compras' && <span className="you-pill">Tú</span>}</div></div>
            {activeRequest && <div className="context-section flow-context"><h3>Seguimiento</h3><StageBadge stage={activeRequest.stage} /><p>Compromiso: {dateLabel(activeRequest.due)}</p><button onClick={() => showDialog('workflow')}>Ver seguimiento<ArrowRight size={14} aria-hidden /></button></div>}
            {active.reminder ? <div className="context-reminder"><div><Clock3 size={17} aria-hidden /><h3>Recordatorio vinculado</h3></div><p>{active.reminder}</p><button onClick={() => showDialog('reminder')}>Ver recordatorio<ArrowRight size={14} aria-hidden /></button></div> : <div className="context-no-reminder"><CheckCheck size={18} aria-hidden /><p>Sin recordatorios pendientes<br /><span>Todo el contexto sigue en el chat.</span></p></div>}
            <div className="context-guidance"><ShieldCheck size={18} aria-hidden /><p>Solo el equipo de esta cotización.<br /><span>Cada cambio de etapa queda registrado en el historial.</span></p></div>
          </aside>
        </section> : <section className="notifications-demo" aria-label="Notificaciones de ejemplo">
          <div className="notifications-intro"><Bell size={23} aria-hidden /><div><h2>Lo que necesita atención</h2><p>Toma una solicitud nueva o abre el seguimiento de su folio.</p></div></div>
          {requests.filter((request) => request.notices[role]).map((request) => <article className="notification-demo-row" key={`workflow-${request.id}`}><span className="notice-icon"><GitBranch size={20} aria-hidden /></span><div><span className="context-eyebrow">{request.stage === 'por_tomar' ? 'NUEVA SOLICITUD' : 'SIGUIENTE PASO'}</span><h3>{request.folio}</h3><p>{request.notices[role]}</p><span>{request.client}</span></div>{request.stage === 'por_tomar' ? role === 'compras' ? <Button onClick={() => takeRequest(request.id)}>Tomar solicitud<ArrowRight size={16} aria-hidden /></Button> : <StageBadge stage={request.stage} /> : <Button variant="secondary" onClick={() => selectThread(request.id)}>Abrir seguimiento<ArrowRight size={16} aria-hidden /></Button>}</article>)}
          {threads.filter((thread) => thread.reminder || thread.unread[role] > 0).map((thread) => <article className="notification-demo-row" key={thread.id}><span className={`notice-icon ${thread.reminder ? 'reminder' : ''}`}>{thread.reminder ? <Clock3 size={20} aria-hidden /> : <MessageSquareText size={20} aria-hidden />}</span><div><span className="context-eyebrow">{thread.reminder ? 'RECORDATORIO' : 'MENSAJE SIN LEER'}</span><h3>{thread.folio}</h3><p>{thread.reminder ?? thread.messages.at(-1)?.text}</p><span>{thread.client}</span></div><Button variant="secondary" onClick={() => selectThread(thread.id)}>Abrir chat<ArrowRight size={16} aria-hidden /></Button></article>)}
          {!noticeCount && !threads.some((thread) => thread.reminder || thread.unread[role] > 0) && <p className="no-notices">Todo al día. Puedes consultar el historial en Mensajería.</p>}
          <Button variant="ghost" onClick={() => selectThread(active.id)}>Ver todas las conversaciones<ArrowRight size={16} aria-hidden /></Button>
        </section>}

        <footer className="prototype-footer"><span><span className="prototype-dot" />Prototipo interactivo · mensajes de ejemplo · guardado en este navegador</span><div><button onClick={() => showDialog('proposal')}>Ver propuesta<ArrowRight size={13} aria-hidden /></button><button onClick={() => showDialog('reset')}>Restablecer demo</button>{view === 'messages' ? <button className="mobile-notification-link" onClick={() => setView('notifications')}>Ver recordatorios</button> : <button className="mobile-notification-link" onClick={() => selectThread(active.id)}>Ver Mensajería</button>}</div></footer>
        <p className="preview-feedback" role="status" aria-live="polite">{feedback}</p>
      </main>
    </div>

    <dialog ref={dialogRef} className="messaging-dialog" aria-labelledby="messaging-dialog-title" onClick={(event) => { if (event.target === event.currentTarget) dialogRef.current?.close() }}>
      <div className="dialog-header"><div><span className="heading-eyebrow">MENSAJERÍA</span><h2 id="messaging-dialog-title">{dialogTitle}</h2></div><button className="icon-button" aria-label="Cerrar" onClick={() => dialogRef.current?.close()}><X size={20} aria-hidden /></button></div>
      <div className="dialog-content">
        {dialogView === 'new' && <><p>Abre una conversación existente o toma una solicitud para iniciar su chat.</p>{role === 'ventas' && <Button className="request-from-picker" onClick={() => showDialog('request')}><Plus size={16} aria-hidden />Enviar solicitud a Compras</Button>}<div className="quote-picker">{requests.filter((request) => request.stage === 'por_tomar').map((request) => <button key={request.id} disabled={role !== 'compras'} onClick={() => { takeRequest(request.id); dialogRef.current?.close() }}><span><strong>{request.folio}</strong><span>{request.client}</span><small>{request.sales} · Compras por asignar</small></span><span className="picker-action">{role === 'compras' ? 'Tomar solicitud' : 'Esperando a Compras'}<ArrowRight size={16} aria-hidden /></span></button>)}{threads.map((thread) => <button key={thread.id} onClick={() => { selectThread(thread.id); dialogRef.current?.close() }}><span><strong>{thread.folio}</strong><span>{thread.client}</span><small>{thread.sales} · {thread.purchasing}</small></span><span className="picker-action">Abrir chat<ArrowRight size={16} aria-hidden /></span></button>)}</div></>}
        {dialogView === 'workflow' && activeRequest && <><p className="workflow-dialog-folio">{active.folio} · {active.client}</p><WorkflowFollowup request={activeRequest} role={role} onCheck={checkPoint} onAction={workflowAction} /></>}
        {dialogView === 'request'  && <RequestForm onCreate={createRequest} />}
        {dialogView === 'action' && <form key={`${pendingAction}:${active.id}`} className="flow-request-form" onSubmit={(event) => {
          event.preventDefault(); completeAction(pendingAction, String(new FormData(event.currentTarget).get('text') ?? ''))
        }}><p><strong>{active.folio}</strong> · {active.client}</p>{pendingAction === 'sent' ? <p>Confirma que ya enviaste la cotización al cliente por tu canal habitual. En esta prueba se registra la etapa; no se envía un correo.</p> : <label>{pendingAction === 'question' ? '¿Qué información falta?' : pendingAction === 'close' ? 'Resultado del seguimiento' : pendingAction === 'reopen' ? '¿Qué necesita ajustarse?' : 'Respuesta para Compras'}<textarea name="text" required maxLength={2000} rows={4} autoFocus placeholder={pendingAction === 'close' ? 'Ej. Cliente confirmó la cotización.' : 'Escribe el detalle para dejarlo en el chat…'} /></label>}<div className="reset-actions"><Button type="button" variant="secondary" onClick={() => dialogRef.current?.close()}>Cancelar</Button><Button type="submit">{pendingAction === 'sent' ? 'Confirmar envío' : pendingAction === 'close' ? 'Cerrar seguimiento' : 'Enviar y actualizar etapa'}<ArrowRight size={16} aria-hidden /></Button></div></form>}
        {dialogView === 'quote' && <><div className="quote-dialog-summary"><p>{active.client}</p><QuoteStatusBadge status={active.status} /></div><h3>Productos solicitados</h3><ul className="quote-product-list">{active.products.map((product) => <li key={product.description}><span>{product.description}</span><strong>{product.quantity} unidades</strong></li>)}</ul><div className="quote-dialog-total"><span>Total estimado</span><strong>{active.total > 0 ? formatCurrency(active.total) : 'Por cotizar'}</strong></div><div className="dialog-team"><Users size={18} aria-hidden /><span>{active.sales} (Ventas)<br />{active.purchasing} (Compras)</span></div><p className="dialog-note">Datos de ejemplo para revisar el diseño. En la versión final, este acceso abrirá la cotización existente.</p></>}
        {dialogView === 'reminder' && <><div className="reminder-dialog-card"><Clock3 size={24} aria-hidden /><div><h3>{active.folio}</h3><p>{active.reminder ?? 'Esta cotización no tiene recordatorios pendientes.'}</p></div></div><p>Responsable de atención: {counterpart} · {ROLE_LABEL[otherRole]}.</p><p>El recordatorio y el chat comparten el mismo folio. Escribir en el chat no resuelve el recordatorio automáticamente.</p><Button onClick={() => { dialogRef.current?.close(); composerRef.current?.focus({ preventScroll: true }) }}>Escribir en esta conversación<MessageCircle size={16} aria-hidden /></Button></>}
        {dialogView === 'proposal' && <><p className="proposal-lead">Un chat por folio para colaborar entre Ventas y Compras, conservando este diseño de mensajería.</p><ol className="proposal-list"><li><strong>Un chat por folio</strong><p>Ventas envía una solicitud. Compras recibe el aviso, la toma y se abre una única conversación entre ambos.</p></li><li><strong>El contexto, siempre a la mano</strong><p>Cliente, estatus, responsables y recordatorio vinculados. Los botones del seguimiento cambian la etapa y dejan un evento en el chat. Un mensaje libre no cambia la etapa.</p></li><li><strong>Seguimiento claro</strong><p>Mensajes sin leer, hora, confirmación de lectura y borrador por conversación.</p></li><li><strong>Acceso por cotización</strong><p>Participan el vendedor y Compras asignado. Los permisos se validan en el servidor antes de consultar o enviar.</p></li><li><strong>Primera versión: solo texto</strong><p>Sin archivos, audios, llamadas, grupos ni integración con WhatsApp.</p></li></ol><div className="proposal-next"><strong>Siguiente etapa propuesta</strong><p>Conectar a Laravel las solicitudes, la toma exclusiva por un comprador, las etapas, el chat y los avisos del folio. Este prototipo usa ejemplos locales y permite simular ambos roles.</p></div></>}
        {dialogView === 'reset' && <><p>Se borrarán las solicitudes, etapas, mensajes y chats creados en esta demostración. Se restaurarán los ejemplos iniciales.</p><div className="reset-actions"><Button variant="secondary" onClick={() => dialogRef.current?.close()}>Cancelar</Button><Button onClick={resetDemo}>Restablecer demo</Button></div></>}
      </div>
    </dialog>
    <span className="sr-only">Escribiendo como {ownName}, {ROLE_LABEL[role]}.</span>
  </div>
}
