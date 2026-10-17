import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { ArrowUpRight, CheckCheck, MessageSquareText, Send } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card } from '@/components/ui/Card'
import { ConversationActivity } from '@/components/messaging/ConversationActivity'
import { MESSAGING_UNREAD_CHANGED } from '@/hooks/useMessagingUnreadCount'
import { chatApi, serverDate, type LiveDetail } from '../../../pruebas/mensajeria/live-api'

export function QuoteConversationPanel({ quoteId, canEdit }: { quoteId: string; canEdit: boolean }) {
  const [detail, setDetail] = useState<LiveDetail | null>(null)
  const [draft, setDraft] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const panelRef = useRef<HTMLDivElement>(null)
  const logRef = useRef<HTMLDivElement>(null)
  const visibleRef = useRef(false)
  const readRef = useRef('')
  const retryRef = useRef<{ text: string; id: string } | null>(null)

  const refresh = useCallback(async (open: boolean, signal?: AbortSignal) => {
    const result = await chatApi<LiveDetail>(`/${quoteId}${open ? '/abrir' : ''}`, open ? {} : undefined, signal)
    if (signal?.aborted) return
    setDetail(result)
    const last = result.messages.at(-1)?.id
    const log = logRef.current
    const atBottom = !log || log.scrollHeight - log.scrollTop - log.clientHeight < 60
    if (last && readRef.current !== last && visibleRef.current && atBottom && !document.hidden) {
      await chatApi(`/${quoteId}/leer`, { lastId: Number(last) }, signal)
      readRef.current = last
      if (result.quote.unreadCount > 0) window.dispatchEvent(new Event(MESSAGING_UNREAD_CHANGED))
    }
  }, [quoteId])

  useEffect(() => {
    const controller = new AbortController()
    let running = false
    const update = async (open = false) => {
      if (running || document.hidden) return
      running = true
      try { await refresh(open, controller.signal) }
      catch (cause) { if (!controller.signal.aborted) setError((cause as Error).message) }
      finally { running = false }
    }
    const observer = new IntersectionObserver(([entry]) => {
      visibleRef.current = entry.isIntersecting
      if (entry.isIntersecting) void update()
    }, { threshold: 0.2 })
    if (panelRef.current) observer.observe(panelRef.current)
    void update(true)
    const timer = window.setInterval(() => void update(), 10000)
    const onFocus = () => void update()
    window.addEventListener('focus', onFocus)
    document.addEventListener('visibilitychange', onFocus)
    return () => {
      controller.abort(); observer.disconnect(); window.clearInterval(timer)
      window.removeEventListener('focus', onFocus); document.removeEventListener('visibilitychange', onFocus)
    }
  }, [refresh])

  useEffect(() => {
    const log = logRef.current
    if (log && (log.scrollHeight - log.scrollTop - log.clientHeight < 180 || readRef.current === '')) log.scrollTop = log.scrollHeight
  }, [detail?.messages.length])

  async function send(event: FormEvent) {
    event.preventDefault()
    const text = draft.trim()
    if (!text || busy || !canEdit || !detail?.quote.canChat || detail.quote.stage === 'cerrada') return
    if (retryRef.current?.text !== text) retryRef.current = { text, id: crypto.randomUUID() }
    setBusy(true); setError('')
    try {
      await chatApi(`/${quoteId}/mensajes`, { text, clientId: retryRef.current.id })
      setDraft((current) => current.trim() === text ? '' : current); retryRef.current = null
      await refresh(false)
      window.dispatchEvent(new Event(MESSAGING_UNREAD_CHANGED))
    } catch (cause) { setError((cause as Error).message) }
    finally { setBusy(false) }
  }

  const disabled = busy || !canEdit || !detail?.quote.canChat || detail.quote.stage === 'cerrada'
  return <div ref={panelRef}>
    <Card>
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
        <h2 className="flex items-center gap-2 text-base font-semibold text-slate-900"><MessageSquareText className="h-5 w-5 text-indigo-600" aria-hidden />Seguimiento de la cotización</h2>
        <Link to={`/mensajeria?quote=${encodeURIComponent(quoteId)}`} className="inline-flex min-h-11 items-center gap-1 rounded-lg px-2 text-sm font-medium text-indigo-700 hover:bg-indigo-50">Abrir Mensajería<ArrowUpRight className="h-4 w-4" aria-hidden /></Link>
      </div>
      {detail && <ConversationActivity key={quoteId} detail={detail} onRead={() => refresh(false)} />}
      <div ref={logRef} role="log" aria-label="Mensajes del seguimiento de la cotización" aria-live="polite" aria-relevant="additions text" className="max-h-80 space-y-3 overflow-y-auto bg-slate-50/70 p-4">
        {detail ? detail.messages.some((message) => !message.system) ? detail.messages.filter((message) => !message.system).slice(-30).map((message) =>
          <div key={message.id} className={`flex ${message.mine ? 'justify-end' : 'justify-start'}`}>
            <div className={`max-w-[90%] rounded-xl px-4 py-3 sm:max-w-[80%] ${message.mine ? 'bg-indigo-600 text-white' : 'border border-slate-200 bg-white text-slate-800'}`}>
              <p className={`mb-1 text-xs font-semibold ${message.mine ? 'text-indigo-100' : 'text-slate-500'}`}>{message.mine ? 'Tú' : message.authorName}</p>
              <p className="whitespace-pre-wrap break-words text-sm">{message.text}</p>
              <p className={`mt-2 flex items-center justify-end gap-1 text-[11px] ${message.mine ? 'text-indigo-100' : 'text-slate-400'}`}>{serverDate(message.createdAt).toLocaleString('es-MX', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}{message.mine && message.read && <CheckCheck className="h-3.5 w-3.5" aria-label="Leído" />}</p>
            </div>
          </div>) : <p className="py-4 text-center text-sm text-slate-500">Escribe el primer mensaje para dar seguimiento a esta cotización.</p> : <p className="py-4 text-center text-sm text-slate-500">Cargando seguimiento…</p>}
      </div>
      <form onSubmit={(event) => void send(event)} className="border-t border-slate-100 p-4">
        <label htmlFor={`quote-chat-${quoteId}`} className="sr-only">Mensaje de seguimiento</label>
        <div className="flex items-end gap-2"><textarea id={`quote-chat-${quoteId}`} maxLength={2000} rows={2} disabled={disabled} value={draft} onChange={(event) => setDraft(event.target.value)} placeholder={detail?.quote.stage === 'cerrada' ? 'Seguimiento cerrado' : canEdit ? 'Escribe sobre esta cotización…' : 'Consulta del seguimiento'} className="min-h-14 max-h-32 flex-1 resize-y rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm disabled:bg-slate-50" /><Button type="submit" disabled={disabled || !draft.trim()} aria-label="Enviar mensaje de seguimiento" className="min-h-11 min-w-11 px-3"><Send className="h-4 w-4" aria-hidden /></Button></div>
        <p className="mt-1 text-right text-xs text-slate-400">{draft.length}/2000</p>
        {error && <p role="alert" className="mt-2 text-sm text-rose-700">{error}</p>}
      </form>
    </Card>
  </div>
}
