import { useEffect, useRef, useState } from 'react'
import { Button } from '@/components/ui/Button'
import { InlineBusy } from '@/components/ui/LoadingState'
import { getWhatsAppTemplates, sendQuoteByWhatsApp, type WhatsAppCatalog } from '@/lib/whatsapp-api'

type Props = {
  quoteId: string
  recipient: string
  contact: string
  folio: string
  modified: boolean
  onClose: () => void
  onSent: (message: string) => void
  onManual: () => void
}

export function WhatsAppQuoteModal({ quoteId, recipient, contact, folio, modified, onClose, onSent, onManual }: Props) {
  const [catalog, setCatalog] = useState<WhatsAppCatalog | null>(null)
  const [selected, setSelected] = useState(modified ? 'cotizacion_actualizada' : 'cotizacion_lista')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const [sending, setSending] = useState(false)
  const sendingRef = useRef(false)

  useEffect(() => {
    let active = true
    getWhatsAppTemplates().then((result) => {
      if (active) setCatalog(result)
    }).catch((err: unknown) => {
      if (active) setError(err instanceof Error ? err.message : 'No se pudieron consultar las plantillas.')
    }).finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [])

  const template = catalog?.data.find((item) => item.name === selected)
  const send = async () => {
    if (sendingRef.current || !template?.canSend) return
    sendingRef.current = true
    setSending(true)
    setError('')
    try {
      const result = await sendQuoteByWhatsApp(quoteId, selected)
      onSent(result.message)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'No se pudo confirmar el envío.')
    } finally {
      sendingRef.current = false
      setSending(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" role="dialog" aria-modal="true" aria-labelledby="whatsapp-title">
      <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
        <h3 id="whatsapp-title" className="text-lg font-bold">Enviar cotización por WhatsApp</h3>
        <p className="mt-2 text-sm text-slate-600">Cliente: {contact} · {recipient}</p>
        <p className="text-sm text-slate-600">Se generará y adjuntará el PDF de {folio} desde la cuenta emisora de WhatsApp Business.</p>
        {loading ? <p className="mt-4 flex gap-2"><InlineBusy size="sm" /> Consultando plantillas…</p> : (
          <>
            {!catalog?.configured && <p className="mt-4 text-sm text-amber-700">La cuenta emisora aún no está conectada.</p>}
            {catalog?.connectionError && <p className="mt-2 text-sm text-amber-700">{catalog.connectionError}</p>}
            <label className="mt-4 block text-sm font-medium" htmlFor="whatsapp-template">Plantilla</label>
            <select id="whatsapp-template" className="mt-1 w-full rounded-lg border border-slate-300 p-2" value={selected} disabled={sending} onChange={(event) => setSelected(event.target.value)}>
              {catalog?.data.map((item) => <option key={item.name} value={item.name}>{item.label} · {item.status}</option>)}
            </select>
            {template && <p className="mt-3 whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-sm">{template.body.replace('{{1}}', contact).replace('{{2}}', folio).replace('{{3}}', 'nuestra empresa')}</p>}
            {catalog?.configured && !template?.canSend && <p className="mt-3 text-sm text-amber-700">Esta plantilla todavía no está aprobada o no es compatible. Consulta Configuración → Integraciones → WhatsApp Business.</p>}
          </>
        )}
        {error && <p className="mt-3 text-sm text-red-600" role="alert">{error}</p>}
        <div className="mt-5 flex flex-wrap justify-end gap-2">
          <Button variant="secondary" size="sm" disabled={sending} onClick={onClose}>Cancelar</Button>
          <Button variant="secondary" size="sm" disabled={sending} onClick={onManual}>Envío manual</Button>
          <Button size="sm" disabled={loading || sending || !template?.canSend} onClick={() => void send()}>{sending ? 'Enviando PDF…' : 'Enviar plantilla y PDF'}</Button>
        </div>
      </div>
    </div>
  )
}
