import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { ArrowLeft, ArrowRight, Bell, Check, CheckCheck, Clock3, FileText, Info, Maximize2, Minimize2, MessageSquareText, Search, Send, Users, X, GitBranch } from 'lucide-react'
import { MESSAGING_UNREAD_CHANGED } from '@/hooks/useMessagingUnreadCount'
import { Button } from '@/components/ui/Button'
import { QuoteStatusBadge } from '@/components/ui/QuoteStatusBadge'
import { MessagingFollowUpPanel } from '@/components/messaging/MessagingFollowUpPanel'
import { formatCurrency } from '@/lib/format'
import { useLocation, useNavigate } from 'react-router-dom'
import type { User } from '@/types'
import { ROLE_LABEL, type ChatRole, type ChatThread, type ChatMessage } from './data'
import { StageBadge, WorkflowFollowup } from './WorkflowControls'
import { dateLabel, localDate, type WorkflowRequest } from './workflow-data'
import { chatApi, isMessagingTest, quoteUrl, serverDate, type LiveQuote, type LiveDetail, type LiveIndex, type LiveSection, type LiveChatNotice } from './live-api'

type DialogView = 'claim' | 'workflow' | 'action' | 'quote' | 'reminder'
type WorkflowAction = 'question' | 'resume' | 'ready' | 'sent' | 'close' | 'reopen'
type Thread = Omit<ChatThread, 'messages'> & Pick<LiveQuote, 'canChat' | 'canView' | 'canTake' | 'hasNotifications' | 'notificationCount' | 'lastNotification' | 'lastNotificationAt' | 'section' | 'sections' | 'notices' | 'requestId' | 'daysIdle' | 'alertType' | 'alertDetail'> & { messages: (ChatMessage & { authorName?: string; notice?: boolean })[] }
const SECTION_ORDER: LiveSection[] = ['quote_notices', 'new_requests', 'own_requests', 'own_quotes', 'ready_to_send', 'pending', 'history']
const SECTION_LABELS: Record<LiveSection, string> = { new_requests: 'Solicitudes nuevas', own_requests: 'Tus solicitudes', own_quotes: 'Tus cotizaciones', ready_to_send: 'Cotizaciones listas para enviar', quote_notices: 'Avisos de cotizaciones', pending: 'Pendientes por atender', history: 'Historial de chats' }
const EMPTY_COUNTS: Record<LiveSection, number> = { new_requests: 0, own_requests: 0, own_quotes: 0, ready_to_send: 0, quote_notices: 0, pending: 0, history: 0 }
function initials(name: string) { return name.split(' ').slice(0, 2).map((part) => part[0]).join('') }
const EMPTY: Thread = { id: '', folio: 'Selecciona una conversación', client: '', sales: 'Ventas', purchasing: 'Compras', status: 'solicitud_cotizaciones', total: 0, unread: { ventas: 0, compras: 0 }, reminder: null, products: [], messages: [], canChat: false, canTake: false, hasNotifications: false, notificationCount: 0, lastNotification: null, lastNotificationAt: null, section: 'history', daysIdle: 0, alertType: null, alertDetail: null }

export function LiveMessaging({ user, canEditFollowUp = false }: { user: User; canEditFollowUp?: boolean; onLogout?: () => Promise<void> }) {
  const navigate = useNavigate()
  const location = useLocation()
  const role: ChatRole = user.role === 'ventas' ? 'ventas' : 'compras'
  const otherRole: ChatRole = role === 'ventas' ? 'compras' : 'ventas'
  const [quotes, setQuotes] = useState<LiveQuote[]>([])
  const [section, setSection] = useState<LiveSection | 'all'>('all')
  const [sectionCounts, setSectionCounts] = useState(EMPTY_COUNTS)
  const [unreadMessageCount, setUnreadMessageCount] = useState(0)
  const [unreadQuoteCount, setUnreadQuoteCount] = useState(0)
  const [remindCount, setRemindCount] = useState(0)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [activeId, setActiveId] = useState(() => new URLSearchParams(window.location.search).get('quote') ?? '')
  const [detail, setDetail] = useState<LiveDetail | null>(null)
  const [view, setView] = useState<'messages' | 'notifications'>('messages')
  const [pendingAction, setPendingAction] = useState<WorkflowAction>('question')
  const [filter, setFilter] = useState<'all' | 'unread' | 'reminders'>('all')
  const [search, setSearch] = useState('')
  const [drafts, setDrafts] = useState<Record<string, string>>({})
  const [mobileView, setMobileView] = useState<'list' | 'chat'>(() => window.location.search.includes('quote=') ? 'chat' : 'list')
  const [dialogView, setDialogView] = useState<DialogView>('claim')
  const [claimQuoteId, setClaimQuoteId] = useState('')
  const [reminderQuoteId, setReminderQuoteId] = useState('')
  const [feedback, setFeedback] = useState('')
  const [busy, setBusy] = useState(false)
  const [loading, setLoading] = useState(true)
  const [maximized, setMaximized] = useState(false)
  const workspaceRef = useRef<HTMLDivElement>(null)
  const dialogRef = useRef<HTMLDialogElement>(null)
  const logRef = useRef<HTMLDivElement>(null)
  const quoteContextRef = useRef<HTMLElement>(null)
  const composerRef = useRef<HTMLTextAreaElement>(null)
  const activeRef = useRef(activeId)
  const listRequestRef = useRef(0)
  const retryRef = useRef<{ id: string; text: string; clientId: string } | null>(null)
  const olderScrollRef = useRef<{ height: number; top: number } | null>(null)
  const scrollThreadRef = useRef('')
  const readingRef = useRef(false)
  const offeredClaimRef = useRef('')
  const showDialog = useCallback((kind: DialogView) => { setDialogView(kind); dialogRef.current?.showModal() }, [])
  const openReminder = useCallback((id: string) => { setReminderQuoteId(id); showDialog('reminder') }, [showDialog])
  useEffect(() => {
    const update = () => setMaximized(document.fullscreenElement === workspaceRef.current)
    document.addEventListener('fullscreenchange', update)
    return () => document.removeEventListener('fullscreenchange', update)
  }, [])
  const toggleMaximized = async () => {
    try {
      if (document.fullscreenElement === workspaceRef.current) await document.exitFullscreen()
      else await workspaceRef.current?.requestFullscreen()
    } catch {
      setFeedback('No se pudo maximizar mensajería en este navegador.')
    }
  }
  useEffect(() => { activeRef.current = activeId }, [activeId])
  useEffect(() => { if (quoteContextRef.current) quoteContextRef.current.scrollTop = 0 }, [activeId])
  useEffect(() => { readingRef.current = view === 'messages' && (window.innerWidth >= 768 || mobileView === 'chat') }, [view, mobileView])
  // Router search parameters are the source for shared links and browser navigation.
  /* eslint-disable react-hooks/set-state-in-effect */
  useEffect(() => {
    const params = new URLSearchParams(location.search)
    const id = params.get('quote')
    if (id) { activeRef.current = id; setActiveId(id); setMobileView('chat') }
    if (params.get('filter') === 'reminders') { setFilter('reminders'); setSection('all'); setPage(1) }
    if (id && params.get('reminder') === '1') openReminder(id)
  }, [location.search, openReminder])
  /* eslint-enable react-hooks/set-state-in-effect */

  function mapQuote(sourceQuote: LiveQuote, messages?: LiveDetail['messages']): Thread {
    const listed = quotes.find((item) => item.id === sourceQuote.id)
    const quote = listed ? { ...sourceQuote, section: listed.section, sections: listed.sections, notices: listed.notices, alertType: listed.alertType, alertDetail: listed.alertDetail } : sourceQuote
    const preview = quote.hasNotifications ? quote.lastNotification || quote.lastMessage : quote.lastMessage
    return { ...quote, reminder: quote.reminder ? `Seguimiento programado: ${serverDate(quote.reminder).toLocaleString('es-MX')}` : quote.alertType === 'reminder' ? quote.alertDetail : null,
      unread: { ventas: role === 'ventas' ? quote.unreadCount : 0, compras: role === 'compras' ? quote.unreadCount : 0 },
      messages: messages ? conversationMessages(messages, detail?.quote.id === quote.id ? detail.notices : [])
        : [{ id: `preview-${quote.id}`, author: 'sistema', text: preview, day: '', time: quote.lastNotificationAt ? serverDate(quote.lastNotificationAt).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: false }) : quote.lastMessageAt ? serverDate(quote.lastMessageAt).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: false }) : '' }],
    }
  }
  function conversationMessages(messages: LiveDetail['messages'], notices: LiveChatNotice[]): Thread['messages'] {
    const entries: (Thread['messages'][number] & { timestamp: number })[] = messages.map((message) => ({
      id: message.id, author: message.system ? 'sistema' : message.mine ? role : otherRole, text: message.text,
      authorName: message.authorName, day: serverDate(message.createdAt).toLocaleDateString('es-MX'),
      time: serverDate(message.createdAt).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: false }),
      readBy: message.read ? [otherRole] : [], timestamp: serverDate(message.createdAt).getTime(),
    }))
    for (const notice of notices) entries.push({ id: `notice-${notice.id}`, author: 'sistema', notice: true,
      text: `${notice.label}: ${notice.detail}`, day: notice.createdAt ? serverDate(notice.createdAt).toLocaleDateString('es-MX') : 'Avisos vigentes',
      time: notice.createdAt ? serverDate(notice.createdAt).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: false }) : '',
      timestamp: notice.createdAt ? serverDate(notice.createdAt).getTime() : Number.MAX_SAFE_INTEGER,
    })
    return entries.sort((a, b) => a.timestamp - b.timestamp || a.id.localeCompare(b.id))
  }
  const threads = quotes.map((quote) => mapQuote(quote))
  const active = detail && detail.quote.id === activeId ? mapQuote(detail.quote, detail.messages) : EMPTY
  const activeRequest = detail?.quote.id === activeId ? toRequest(detail.quote) : undefined
  const selectedCanView = quotes.some((quote) => quote.id === activeId && (quote.canView ?? quote.canChat))
    || Boolean(activeId && !loading && !quotes.some((quote) => quote.id === activeId))
  const claimQuote = quotes.find((quote) => quote.id === claimQuoteId)
  const draftKey = `${user.id}:${activeId}`
  const draft = drafts[draftKey] ?? ''
  const requests = quotes.map(toRequest)
  function toRequest(quote: LiveQuote): WorkflowRequest {
    return { id: quote.id, folio: quote.folio, client: quote.client, sales: quote.sales, owner: quote.canChat ? quote.purchasing : null,
      stage: quote.stage, priority: 'Normal', due: quote.createdAt?.slice(0, 10) || localDate(), created: quote.createdAt?.slice(0, 10) || localDate(), brief: quote.lastMessage,
      products: quote.products, checks: quote.checks, notices: { ventas: role === 'ventas' && quote.unreadCount > 0 ? quote.lastMessage : null,
        compras: role === 'compras' && (quote.canTake || quote.unreadCount > 0) ? quote.lastMessage : null },
    }
  }
  const noticeCount = requests.filter((request) => request.notices[role]).length
  const allQuoteCount = SECTION_ORDER.filter((key) => key !== 'history').reduce((sum, key) => sum + sectionCounts[key], 0)
  const availableSections = SECTION_ORDER.filter((key) => key !== 'history' && (role === 'ventas'
    ? ['own_quotes', 'ready_to_send'].includes(key)
    : ['new_requests', 'quote_notices', 'pending'].includes(key)))
  const counterpart = role === 'ventas' ? active.purchasing : active.sales
  const ownName = user.name
  const errorText = (error: unknown) => error instanceof Error ? error.message : 'No se pudo completar la operación.'

  const refreshList = useCallback(async () => {
    const requestId = ++listRequestRef.current
    const result = await chatApi<LiveIndex>(`?page=${page}&section=${section}&filter=${filter}&search=${encodeURIComponent(search.trim())}`)
    if (requestId !== listRequestRef.current) return
    setQuotes(result.data); setLastPage(result.lastPage)
    setSectionCounts(result.sections)
    setUnreadMessageCount(result.unreadCount); setUnreadQuoteCount(result.unreadQuoteCount); setRemindCount(result.reminderCount)
    setLoading(false)
  }, [page, search, section, filter])
  const refreshDetail = useCallback(async (id: string, open = false) => {
    if (!id) return
    const result = await chatApi<LiveDetail>(open ? `/${id}/abrir` : `/${id}`, open ? {} : undefined)
    if (activeRef.current !== id) return
    setDetail((current) => {
      if (current?.quote.id !== id) return result
      const merged = [...new Map([...current.messages, ...result.messages].map((message) => [message.id, message])).values()].sort((a, b) => Number(a.id) - Number(b.id))
      return { ...result, messages: merged, hasMore: current.messages.length > 100 ? current.hasMore : result.hasMore }
    })
    const noticeIds = result.notices.filter((notice) => notice.persistent && !notice.read).slice(0, 100).map((notice) => notice.id)
    if (readingRef.current && (result.messages.length || noticeIds.length) && !document.hidden) {
      const reading = await chatApi<{ unreadCount: number }>(`/${id}/leer`, { ...(result.messages.length ? { lastId: Number(result.messages.at(-1)!.id) } : {}),
        ...(noticeIds.length ? { noticeIds } : {}) })
      setDetail((current) => current?.quote.id === id ? { ...current, notices: current.notices.map((notice) => noticeIds.includes(notice.id) ? { ...notice, read: true } : notice) } : current)
      setUnreadMessageCount(reading.unreadCount)
      setQuotes((current) => current.map((quote) => quote.id === id ? { ...quote, ...result.quote,
        sections: quote.sections, notices: quote.notices?.map((notice) => noticeIds.includes(notice.id) ? { ...notice, read: true } : notice),
        notificationCount: Math.max(0, quote.notificationCount - (quote.notices ?? []).filter((notice) => !notice.read && noticeIds.includes(notice.id)).length),
        section: quote.section, alertType: quote.alertType, alertDetail: quote.alertDetail, unreadCount: 0 } : quote))
      if (result.quote.unreadCount > 0 || noticeIds.length > 0) window.dispatchEvent(new Event(MESSAGING_UNREAD_CHANGED))
    }
  }, [])
  useEffect(() => {
    let cancelled = false
    // Clear the previous query results as soon as the active filters change.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setLoading(true); setQuotes([])
    const update = () => { if (!document.hidden) void refreshList().catch((error) => { if (!cancelled) { setFeedback(errorText(error)); setLoading(false) } }) }
    const delay = window.setTimeout(update, 250)
    const timer = window.setInterval(update, 15000)
    return () => { cancelled = true; listRequestRef.current += 1; window.clearTimeout(delay); window.clearInterval(timer) }
  }, [refreshList])
  useEffect(() => {
    if (!activeId || !selectedCanView) return
    let cancelled = false; let running = false
    const update = async (open = false) => {
      if (running || document.hidden) return
      running = true
      try { await refreshDetail(activeId, open) } catch (error) { if (!cancelled) setFeedback(errorText(error)) } finally { running = false }
    }
    void update(true)
    const timer = window.setInterval(() => void update(), 5000)
    return () => { cancelled = true; window.clearInterval(timer) }
  }, [activeId, selectedCanView, refreshDetail])
  useEffect(() => {
    const requested = quotes.find((quote) => quote.id === activeId)
    if (loading || !requested?.canTake || (requested.canView ?? requested.canChat) || offeredClaimRef.current === activeId) return
    offeredClaimRef.current = activeId
    setClaimQuoteId(activeId); setDialogView('claim')
    if (!dialogRef.current?.open) dialogRef.current?.showModal()
  }, [activeId, quotes, loading])
  useEffect(() => {
    const log = logRef.current; if (!log) return
    if (olderScrollRef.current) { log.scrollTop += log.scrollHeight - olderScrollRef.current.height; olderScrollRef.current = null }
    else if (scrollThreadRef.current !== active.id || log.scrollHeight - log.scrollTop - log.clientHeight < 240) log.scrollTop = log.scrollHeight
    scrollThreadRef.current = active.id
  }, [active.id, active.messages.length, mobileView])

  function selectThread(id: string) { activeRef.current = id; setActiveId(id); setMobileView('chat'); setView('messages'); setFeedback(''); const params = new URLSearchParams(location.search); params.set('quote', id); params.delete('reminder'); navigate({ pathname: location.pathname, search: params.toString() }, { replace: true }) }
  function openQuoteItem(thread: Thread) {
    if (thread.requestId) {
      navigate(`/solicitudes/${encodeURIComponent(thread.requestId)}`)
    } else if (thread.canView ?? thread.canChat) {
      selectThread(thread.id)
    } else if (thread.canTake) {
      setClaimQuoteId(thread.id)
      showDialog('claim')
    } else {
      openReminder(thread.id)
    }
  }
  async function mutate(task: () => Promise<unknown>) {
    if (busy) return
    setBusy(true); setFeedback('')
    try { await task(); await refreshList() } catch (error) { setFeedback(errorText(error)) } finally { setBusy(false) }
  }
  async function send(event?: FormEvent) {
    event?.preventDefault(); if (!draft.trim() || !active.id || !active.canChat || activeRequest?.stage === 'cerrada' || busy) return
    const id = active.id; const text = draft.trim(); const key = draftKey
    if (retryRef.current?.id !== id || retryRef.current.text !== text) retryRef.current = { id, text, clientId: crypto.randomUUID() }
    const payload = retryRef.current
    await mutate(async () => {
      await chatApi(`/${id}/mensajes`, { text, clientId: payload.clientId }); retryRef.current = null
      setDrafts((current) => ({ ...current, [key]: current[key]?.trim() === text ? '' : current[key] }))
      await refreshDetail(id); composerRef.current?.focus({ preventScroll: true }); if (logRef.current) logRef.current.scrollTop = logRef.current.scrollHeight
    })
  }
  async function takeRequest(id: string) {
    await mutate(async () => {
      await chatApi<LiveDetail>(`/${id}/tomar`, {})
      // Refresh before selecting so the detail effect sees the assigned participants.
      await refreshList()
      selectThread(id); dialogRef.current?.close()
      setFeedback('Tomaste la solicitud. El chat se abrió automáticamente para ambos responsables.')
      window.dispatchEvent(new Event(MESSAGING_UNREAD_CHANGED))
    })
  }
  async function checkPoint(key: keyof WorkflowRequest['checks']) {
    if (!activeRequest) return
    await mutate(async () => { await chatApi(`/${active.id}/seguimiento`, { action: 'check', key, checked: !activeRequest.checks[key] }); await refreshDetail(active.id) })
  }
  async function completeAction(action: WorkflowAction, text = '') {
    await mutate(async () => { await chatApi(`/${active.id}/seguimiento`, { action, text }); dialogRef.current?.close(); await refreshDetail(active.id) })
  }
  function workflowAction(action: WorkflowAction) { if (action === 'ready') { void completeAction(action); return } setPendingAction(action); showDialog('action') }
  async function loadOlder() {
    if (!detail?.hasMore || busy) return
    await mutate(async () => {
      const id = active.id; const result = await chatApi<LiveDetail>(`/${id}?before=${detail.messages[0].id}`)
      if (activeRef.current !== id) return
      if (logRef.current) olderScrollRef.current = { height: logRef.current.scrollHeight, top: logRef.current.scrollTop }
      setDetail((current) => current && current.quote.id === id ? { ...current, messages: [...result.messages, ...current.messages], hasMore: result.hasMore } : current)
    })
  }
  const actionTitles = { question: 'Pedir información a Ventas', resume: 'Responder a Compras', ready: 'Cotización lista', sent: 'Confirmar envío al cliente', close: 'Cerrar seguimiento', reopen: 'Solicitar un ajuste a Compras' }
  const dialogTitle = { claim: 'Tomar solicitud de cotización', workflow: 'Seguimiento de la solicitud', action: actionTitles[pendingAction], quote: active.folio, reminder: 'Recordatorios' }[dialogView]

  return <div ref={workspaceRef} className={`messaging-preview messaging-embedded${maximized ? ' messaging-maximized' : ''}`}>
      <main id="messaging-main" className="preview-main">
        <div className="page-heading"><div><div className="heading-eyebrow"><span className="tiny-line" />COMPRAS + VENTAS</div><h1>{view === 'messages' ? 'Mensajería' : 'Notificaciones y recordatorios'}</h1>{view !== 'messages' && <p>Del pendiente a la conversación, sin perder el contexto de la cotización.</p>}</div>
          <Button type="button" variant="secondary" size="sm" aria-pressed={maximized} aria-label={maximized ? 'Minimizar mensajería' : 'Maximizar mensajería'} onClick={() => void toggleMaximized()}>
            {maximized ? <Minimize2 size={18} aria-hidden /> : <Maximize2 size={18} aria-hidden />}
            {maximized ? 'Minimizar' : 'Maximizar'}
          </Button>
        </div>

        {view === 'messages' ? <section className={`chat-workspace mobile-${mobileView}`} aria-label="Mensajería por cotización">
          <aside className="conversation-inbox" aria-label="Conversaciones">
            <div className="inbox-heading"><h2>{role === 'ventas' ? 'Tus cotizaciones' : 'Avisos de la campana'} <span>{allQuoteCount}</span></h2><span className="unread-summary" title="Avisos sin leer, solicitudes, recordatorios y mensajes pendientes; el mismo total de la campana">{unreadMessageCount} pendientes</span></div>
            <div className="chat-search"><label htmlFor="chat-search">Buscar conversación</label><div><Search size={18} aria-hidden /><input id="chat-search" type="search" maxLength={120} placeholder="Folio o cliente" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1) }} /></div></div>
            <div className="inbox-section-picker">
              <label className="sr-only" htmlFor="chat-section">Apartado de cotizaciones</label>
              <select id="chat-section" value={section} onChange={(event) => {
                const nextSection = event.target.value as LiveSection | 'all'
                setSection(nextSection); setPage(1)
                if (nextSection === 'history') setFilter('all')
              }}>
                <option value="all">Todos los apartados ({allQuoteCount})</option>
                {availableSections.map((key) => <option key={key} value={key}>{SECTION_LABELS[key]} ({sectionCounts[key]})</option>)}
                {role !== 'ventas' && <option value="history">Historial de chats ({sectionCounts.history})</option>}
              </select>
            </div>
            <div className="inbox-filters" aria-label="Filtrar conversaciones">
              <button className={filter === 'all' ? 'active' : ''} aria-pressed={filter === 'all'} onClick={() => { setFilter('all'); setPage(1) }}>Todas</button>
              <button className={filter === 'unread' ? 'active' : ''} aria-pressed={filter === 'unread'} title="Cotizaciones con mensajes o avisos sin leer en el apartado seleccionado" onClick={() => { setFilter('unread'); setPage(1) }}>Sin leer<span>{unreadQuoteCount}</span></button>
              <button className={filter === 'reminders' ? 'active' : ''} aria-pressed={filter === 'reminders'} title="Cotizaciones con recordatorios en el apartado seleccionado" onClick={() => { setFilter('reminders'); setPage(1) }}>Recordatorios<span>{remindCount}</span></button>
            </div>
            <div className="thread-list">
              {threads.length ? SECTION_ORDER.map((key) => {
                if (section !== 'all' && section !== key) return null
                const group = threads.filter((thread) => (thread.sections ?? [thread.section]).includes(key))
                return group.length > 0 && <section className="inbox-section-group" key={key} aria-label={SECTION_LABELS[key]}><h3>{SECTION_LABELS[key]}</h3>{group.map((thread) => {
                const lastMessage = thread.messages.at(-1)!
                const contact = role === 'ventas' ? thread.purchasing : thread.sales
                const notices = (thread.notices ?? []).filter((notice) => notice.group === key)
                return <button key={thread.id} className={`thread-item ${active.id === thread.id ? 'active' : ''}`} aria-current={active.id === thread.id ? 'true' : undefined} onClick={() => openQuoteItem(thread)} aria-label={`${thread.folio}, ${thread.client}, ${thread.unread[role] + thread.notificationCount} avisos sin leer`}>
                  <span className={`thread-avatar ${thread.reminder ? 'with-reminder' : ''}`}><FileText size={21} aria-hidden /></span>
                  <span className="thread-info"><span className="thread-top"><strong>{thread.folio}</strong><time>{lastMessage.time}</time></span><span className="thread-client">{thread.client}</span>{notices.map((notice) => <span key={notice.id} className={`thread-notice ${notice.read ? 'notice-read' : ''}`}><strong>{notice.label}</strong><span>{notice.detail}</span></span>)}{notices.length === 0 && <span className="thread-excerpt">{lastMessage.author === role ? 'Tú: ' : lastMessage.author === 'sistema' ? '' : `${contact.split(' ')[0]}: `}{lastMessage.text}</span>}<span className="thread-bottom"><span>{key === 'pending' ? <><Clock3 size={12} aria-hidden />Recordatorio</> : key !== 'history' ? <><Bell size={12} aria-hidden />{notices.length > 1 ? `${notices.length} avisos` : 'Aviso'}</> : <><Users size={12} aria-hidden />{ROLE_LABEL[otherRole]}</>}</span>{thread.unread[role] + thread.notificationCount > 0 && <span className="thread-unread" title="Mensajes y avisos sin leer">{thread.unread[role] + thread.notificationCount}</span>}</span></span>
                </button>
              })}</section>
              }) : <div className="inbox-empty"><Search size={28} aria-hidden /><h3>{loading ? 'Cargando conversaciones…' : 'No hay cotizaciones en este apartado y filtro'}</h3><p>Consulta otro apartado o busca otro folio.</p><Button variant="secondary" onClick={() => { setSearch(''); setFilter('all'); setSection('all'); setPage(1) }}>Ver todas</Button></div>}
            </div>
            <div className="live-inbox-navigation"><div className="live-inbox-pages"><button disabled={page <= 1 || busy} onClick={() => setPage(page - 1)}>Anterior</button><span>{page} / {lastPage}</span><button disabled={page >= lastPage || busy} onClick={() => setPage(page + 1)}>Siguiente</button></div></div>
          </aside>

          <section className={`chat-panel ${!active.id ? 'live-no-selection' : ''}`} aria-label={`Conversación de ${active.folio}`}>
            {!active.id && <div className="live-chat-empty"><MessageSquareText size={36} aria-hidden /><h2>Tu seguimiento, en un chat</h2><p>Selecciona una cotización de la lista. Cuando Compras toma la solicitud, su chat con Ventas se abre automáticamente.</p></div>}
            <header className="chat-header"><button className="icon-button mobile-back" aria-label="Volver a conversaciones" onClick={() => setMobileView('list')}><ArrowLeft size={20} aria-hidden /></button><span className="avatar avatar-indigo">{initials(counterpart)}</span><div className="chat-header-copy"><h2>{active.folio}</h2><p>{counterpart} <span>· {ROLE_LABEL[otherRole]}</span></p></div><button className="icon-button" aria-label="Recordatorios" title="Recordatorios" onClick={() => openReminder(active.id)}><Clock3 size={19} aria-hidden /></button><button className="icon-button" aria-label="Ver seguimiento de la solicitud" onClick={() => showDialog('workflow')}><GitBranch size={19} aria-hidden /></button><button className="icon-button context-button" aria-label="Ver contexto de la cotización" onClick={() => showDialog('quote')}><Info size={20} aria-hidden /></button></header>
            <div className="chat-quote-strip"><span><FileText size={15} aria-hidden />{active.client}</span><QuoteStatusBadge status={active.status} /></div>
            <div className="message-log" ref={logRef} role="log" aria-label="Historial de mensajes" aria-live="polite" aria-relevant="additions text">
              {detail?.hasMore && <button className="live-load-older" disabled={busy} onClick={() => void loadOlder()}>Cargar mensajes anteriores</button>}
              {active.messages.map((message, index) => <div key={message.id}>
                {(index === 0 || active.messages[index - 1].day !== message.day) && <div className="day-divider"><span>{message.day}</span></div>}
                {message.author === 'sistema' ? <div className={`system-event ${message.notice ? 'notification-event' : ''}`}><span>{message.notice ? <Bell size={14} aria-hidden /> : message.text.startsWith('Recordatorio') ? <Clock3 size={14} aria-hidden /> : <Check size={14} aria-hidden />}{message.text}{message.notice && message.time && <time>{message.time}</time>}</span></div> : <div className={`message-row ${message.author === role ? 'outgoing' : 'incoming'}`}>
                  {message.author !== role && <span className="avatar message-avatar">{initials(counterpart)}</span>}
                  <div className="message-group"><span className="message-author">{message.author === role ? 'Tú' : message.authorName ?? counterpart}</span><div className="message-bubble"><p>{message.text}</p><div className="message-meta"><time>{message.time}</time>{message.author === role && ((message.readBy ?? []).includes(otherRole) ? <span aria-label="Leído"><CheckCheck size={15} aria-hidden />Leído</span> : <span aria-label="Entregado al servidor"><Check size={14} aria-hidden />Enviado</span>)}</div></div></div>
                </div>}
              </div>)}
            </div>
            <form className="chat-composer" onSubmit={send}>
              <div className="composer-heading"><label htmlFor="chat-message">Mensaje para {counterpart.split(' ')[0]}</label><span>{ROLE_LABEL[otherRole]}</span></div>
              <div className="composer-input"><textarea id="chat-message" ref={composerRef} rows={2} maxLength={2000} disabled={busy || !active.id || !active.canChat || activeRequest?.stage === 'cerrada'} placeholder={!active.canChat ? active.status === 'solicitud_cotizaciones' ? 'Cuando Compras tome la solicitud, podrás enviar mensajes.' : 'Falta vincular al responsable de Compras para enviar mensajes.' : activeRequest?.stage === 'cerrada' ? 'Seguimiento cerrado. Consulta el historial.' : 'Escribe sobre esta cotización…'} value={draft} onChange={(event) => setDrafts({ ...drafts, [draftKey]: event.target.value })} onKeyDown={(event) => {
                if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) { event.preventDefault(); send() }
              }} /><button className="send-message" type="submit" disabled={busy || !active.id || !active.canChat || !draft.trim() || activeRequest?.stage === 'cerrada'} aria-label="Enviar mensaje"><Send size={20} aria-hidden /></button></div>
              <div className="composer-footer"><span>{draft.length}/2000</span></div>
            </form>
          </section>

          <aside ref={quoteContextRef} className={`quote-context ${!active.id ? 'live-hidden' : ''}`} aria-label="Contexto de la cotización">
            <h2>En esta cotización</h2><span className="context-eyebrow">FOLIO</span><strong className="context-folio">{active.folio}</strong><QuoteStatusBadge status={active.status} />
            <div className="context-section"><span className="context-eyebrow">CLIENTE</span><strong>{active.client}</strong><div className="context-total"><span>Total estimado</span><strong>{active.total > 0 ? formatCurrency(active.total) : 'Por cotizar'}</strong></div><Button variant="secondary" className="view-quote" onClick={() => window.open(quoteUrl(active.id), '_blank', 'noopener')}>Ver cotización<ArrowRight size={16} aria-hidden /></Button></div>
            <div className="context-section"><h3>Participantes</h3><div className="participant"><span className="avatar avatar-indigo">{initials(active.sales)}</span><div><strong>{active.sales}</strong><span>Ventas</span></div>{role === 'ventas' && <span className="you-pill">Tú</span>}</div><div className="participant"><span className="avatar avatar-teal">{initials(active.purchasing)}</span><div><strong>{active.purchasing}</strong><span>Compras</span></div>{role === 'compras' && <span className="you-pill">Tú</span>}</div></div>
            {activeRequest && <div className="context-section flow-context"><h3>Seguimiento</h3><StageBadge stage={activeRequest.stage} /><p>Creada: {dateLabel(activeRequest.created)}</p><button onClick={() => showDialog('workflow')}>Ver seguimiento<ArrowRight size={14} aria-hidden /></button></div>}
            <div className="context-section"><h3>Recordatorios</h3>{active.reminder && <p className="messaging-reminder-summary">{active.reminder}</p>}<Button variant="secondary" className="view-quote" onClick={() => openReminder(active.id)}>Gestionar seguimiento<ArrowRight size={16} aria-hidden /></Button></div>
          </aside>
        </section> : <section className="notifications-demo" aria-label="Notificaciones de ejemplo">
          <div className="notifications-intro"><Bell size={23} aria-hidden /><div><h2>Lo que necesita atención</h2><p>Toma una solicitud nueva o abre el seguimiento de su folio.</p></div></div>
          {requests.filter((request) => request.notices[role]).map((request) => <article className="notification-demo-row" key={`workflow-${request.id}`}><span className="notice-icon"><GitBranch size={20} aria-hidden /></span><div><span className="context-eyebrow">{request.stage === 'por_tomar' ? 'NUEVA SOLICITUD' : 'SIGUIENTE PASO'}</span><h3>{request.folio}</h3><p>{request.notices[role]}</p><span>{request.client}</span></div>{request.stage === 'por_tomar' ? role === 'compras' ? <Button onClick={() => takeRequest(request.id)}>Tomar solicitud<ArrowRight size={16} aria-hidden /></Button> : <StageBadge stage={request.stage} /> : <Button variant="secondary" onClick={() => selectThread(request.id)}>Abrir seguimiento<ArrowRight size={16} aria-hidden /></Button>}</article>)}
          {threads.filter((thread) => thread.reminder || thread.unread[role] + thread.notificationCount > 0).map((thread) => <article className="notification-demo-row" key={thread.id}><span className={`notice-icon ${thread.reminder ? 'reminder' : ''}`}>{thread.reminder ? <Clock3 size={20} aria-hidden /> : <MessageSquareText size={20} aria-hidden />}</span><div><span className="context-eyebrow">{thread.reminder ? 'RECORDATORIO' : 'MENSAJE SIN LEER'}</span><h3>{thread.folio}</h3><p>{thread.reminder ?? thread.messages.at(-1)?.text}</p><span>{thread.client}</span></div><Button variant="secondary" onClick={() => selectThread(thread.id)}>Abrir chat<ArrowRight size={16} aria-hidden /></Button></article>)}
          {!noticeCount && !threads.some((thread) => thread.reminder || thread.unread[role] + thread.notificationCount > 0) && <p className="no-notices">Todo al día. Puedes consultar el historial en Mensajería.</p>}
          <Button variant="ghost" onClick={() => selectThread(active.id)}>Ver todas las conversaciones<ArrowRight size={16} aria-hidden /></Button>
        </section>}

        <footer className="prototype-footer"><div><button onClick={() => void refreshList().catch((error) => setFeedback(errorText(error)))}>Actualizar</button></div></footer>
        <p className="preview-feedback" role="status" aria-live="polite">{feedback}</p>
      </main>

    <dialog ref={dialogRef} className="messaging-dialog" aria-labelledby="messaging-dialog-title" onClose={() => setReminderQuoteId('')} onClick={(event) => { if (event.target === event.currentTarget) dialogRef.current?.close() }}>
      <div className="dialog-header"><div><span className="heading-eyebrow">MENSAJERÍA</span><h2 id="messaging-dialog-title">{dialogTitle}</h2></div><button className="icon-button" aria-label="Cerrar" onClick={() => dialogRef.current?.close()}><X size={20} aria-hidden /></button></div>
      <div className="dialog-content">
        {dialogView === 'claim' && claimQuote && <><p><strong>{claimQuote.folio}</strong> · {claimQuote.client}</p><p>Al tomar esta solicitud quedarás como responsable de Compras. El chat con {claimQuote.sales} se abrirá automáticamente para dar seguimiento.</p><div className="reset-actions"><Button variant="secondary" onClick={() => dialogRef.current?.close()}>Cancelar</Button><Button disabled={busy || !claimQuote.canTake} onClick={() => void takeRequest(claimQuote.id)}>Tomar solicitud<ArrowRight size={16} aria-hidden /></Button></div></>}
        {dialogView === 'workflow' && activeRequest && <><p className="workflow-dialog-folio">{active.folio} · {active.client}</p><fieldset disabled={busy}><WorkflowFollowup request={activeRequest} role={role} onCheck={(key) => void checkPoint(key)} onAction={workflowAction} /></fieldset><p className="dialog-note">Los precios y el envío se guardan en la cotización.</p><a className="live-request-link" target="_blank" rel="noopener" href={quoteUrl(active.id)}>Abrir cotización<ArrowRight size={16} aria-hidden /></a></>}
        {dialogView === 'action' && <form key={`${pendingAction}:${active.id}`} className="flow-request-form" onSubmit={(event) => {
          event.preventDefault(); void completeAction(pendingAction, String(new FormData(event.currentTarget).get('text') ?? ''))
        }}><p><strong>{active.folio}</strong> · {active.client}</p>{pendingAction === 'sent' ? <p>Confirma que ya enviaste la cotización al cliente por tu canal habitual. El envío debe estar registrado en la cotización para poder confirmarlo aquí.</p> : <label>{pendingAction === 'question' ? '¿Qué información falta?' : pendingAction === 'close' ? 'Resultado del seguimiento' : pendingAction === 'reopen' ? '¿Qué necesita ajustarse?' : 'Respuesta para Compras'}<textarea name="text" required maxLength={2000} rows={4} autoFocus placeholder={pendingAction === 'close' ? 'Ej. Cliente confirmó la cotización.' : 'Escribe el detalle para dejarlo en el chat…'} /></label>}<div className="reset-actions"><Button type="button" variant="secondary" onClick={() => dialogRef.current?.close()}>Cancelar</Button><Button type="submit" disabled={busy}>{pendingAction === 'sent' ? 'Confirmar envío' : pendingAction === 'close' ? 'Cerrar seguimiento' : 'Enviar y actualizar etapa'}<ArrowRight size={16} aria-hidden /></Button></div></form>}
        {dialogView === 'quote' && <><div className="quote-dialog-summary"><p>{active.client}</p><QuoteStatusBadge status={active.status} /></div><h3>Productos solicitados</h3><ul className="quote-product-list">{active.products.map((product) => <li key={product.description}><span>{product.description}</span><strong>{product.quantity} unidades</strong></li>)}</ul><div className="quote-dialog-total"><span>Total estimado</span><strong>{active.total > 0 ? formatCurrency(active.total) : 'Por cotizar'}</strong></div><div className="dialog-team"><Users size={18} aria-hidden /><span>{active.sales} (Ventas)<br />{active.purchasing} (Compras)</span></div><p className="dialog-note">Esta información corresponde a la cotización guardada en {isMessagingTest ? 'el sistema de pruebas' : 'Exacto'}.</p></>}
        {dialogView === 'reminder' && reminderQuoteId && <MessagingFollowUpPanel key={reminderQuoteId} quoteId={reminderQuoteId} user={user} canEdit={canEditFollowUp} onSaved={async (claimed) => {
          if (claimed) { selectThread(reminderQuoteId); dialogRef.current?.close() }
          await refreshList()
          if (!claimed && activeRef.current === reminderQuoteId && active.canView) await refreshDetail(reminderQuoteId)
          window.dispatchEvent(new Event(MESSAGING_UNREAD_CHANGED))
        }} />}
        {feedback && <p className="live-error" role="alert">{feedback}</p>}
      </div>
    </dialog>
    <span className="sr-only">Escribiendo como {ownName}, {ROLE_LABEL[role]}.</span>
  </div>
}
