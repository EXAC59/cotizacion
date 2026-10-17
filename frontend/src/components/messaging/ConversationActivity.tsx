import { useState } from 'react'
import { chatApi, serverDate, type LiveDetail } from '../../../pruebas/mensajeria/live-api'
import { MESSAGING_UNREAD_CHANGED } from '@/hooks/useMessagingUnreadCount'

export function ConversationActivity({ detail, onRead }: { detail: LiveDetail; onRead: () => Promise<void> }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const unread = detail.notices.filter((notice) => notice.persistent && !notice.read)
  const events = [
    ...detail.notices.map((notice) => ({ id: `notice-${notice.id}`, text: notice.detail, label: notice.label, date: notice.createdAt, unread: !notice.read })),
    ...detail.messages.filter((message) => message.system).map((message) => ({ id: `message-${message.id}`, text: message.text, label: 'Actividad de la cotización', date: message.createdAt, unread: false })),
  ].sort((a, b) => (b.date ? serverDate(b.date).getTime() : 0) - (a.date ? serverDate(a.date).getTime() : 0))

  async function markRead() {
    if (busy) return
    setBusy(true); setError('')
    try {
      for (let index = 0; index < unread.length; index += 100) {
        await chatApi(`/${detail.quote.id}/leer`, { noticeIds: unread.slice(index, index + 100).map((notice) => notice.id) })
      }
      window.dispatchEvent(new Event(MESSAGING_UNREAD_CHANGED))
      await onRead()
    } catch (cause) { setError(cause instanceof Error ? cause.message : 'No se pudieron marcar los avisos como leídos.') }
    finally { setBusy(false) }
  }

  if (!events.length && !detail.quote.reminder) return null
  return <details className="shrink-0 border-b border-slate-200 bg-slate-50 text-sm text-slate-700">
    <summary className="min-h-11 cursor-pointer px-4 py-3 font-semibold">Avisos y actividad{unread.length > 0 ? ` · ${unread.length} sin leer` : ''}</summary>
    <div className="max-h-64 space-y-3 overflow-y-auto px-4 pb-4">
      {detail.quote.reminder && <p>Próximo seguimiento: {serverDate(detail.quote.reminder).toLocaleDateString('es-MX')}.</p>}
      {unread.length > 0 && <button type="button" disabled={busy} onClick={() => void markRead()} className="min-h-11 rounded-lg border border-slate-300 bg-white px-3 font-medium disabled:opacity-50">{busy ? 'Guardando…' : 'Marcar avisos como leídos'}</button>}
      <p className="text-xs text-slate-500">Leer un aviso no completa el seguimiento de la cotización.</p>
      {error && <p role="alert" className="text-rose-700">{error}</p>}
      {events.map((event) => <article key={event.id} className={`rounded-lg border p-3 ${event.unread ? 'border-indigo-200 bg-indigo-50' : 'border-slate-200 bg-white'}`}>
        <p className="font-semibold">{event.label}{event.unread ? ' · Sin leer' : ''}</p>
        <p className="mt-1 whitespace-pre-wrap break-words">{event.text}</p>
        {event.date && <time className="mt-1 block text-xs text-slate-500">{serverDate(event.date).toLocaleString('es-MX')}</time>}
      </article>)}
    </div>
  </details>
}
