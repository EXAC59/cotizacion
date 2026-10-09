import { useEffect, useState } from 'react'
import { Button } from '@/components/ui/Button'
import { getWhatsAppTemplates, createWhatsAppTemplate, type WhatsAppCatalog } from '@/lib/whatsapp-api'

export function WhatsAppTemplatesPanel({ canManage }: { canManage: boolean }) {
  const [catalog, setCatalog] = useState<WhatsAppCatalog | null>(null)
  const [sample, setSample] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const [feedback, setFeedback] = useState('')

  const refresh = async () => {
    setBusy(true)
    try {
      setCatalog(await getWhatsAppTemplates())
    } catch (err) {
      setFeedback(err instanceof Error ? err.message : 'No se pudo consultar Meta.')
    } finally { setBusy(false) }
  }

  useEffect(() => {
    let active = true
    getWhatsAppTemplates().then((result) => { if (active) setCatalog(result) })
      .catch((err: unknown) => { if (active) setFeedback(err instanceof Error ? err.message : 'No se pudo consultar Meta.') })
    return () => { active = false }
  }, [])

  const create = async (name: string) => {
    if (!sample || busy) return
    setBusy(true)
    setFeedback('')
    try {
      await createWhatsAppTemplate(name, sample)
      setFeedback('Plantilla enviada a revisión de Meta. Podrás usarla cuando aparezca aprobada.')
      setCatalog(await getWhatsAppTemplates())
    } catch (err) {
      setFeedback(err instanceof Error ? err.message : 'No se pudo registrar la plantilla.')
    } finally { setBusy(false) }
  }

  return (
    <div className="rounded-lg border border-slate-200 p-3 sm:col-span-3">
      <div className="flex items-center justify-between gap-3">
        <p className="font-medium">WhatsApp Business — plantillas con PDF</p>
        <Button size="sm" variant="secondary" disabled={busy} onClick={() => void refresh()}>Actualizar estado</Button>
      </div>
      <p className="mt-2 text-slate-500">{catalog?.configured ? 'Cuenta emisora conectada. El destinatario se toma del WhatsApp registrado en el cliente.' : 'Pendiente de conectar la cuenta emisora de Meta.'}</p>
      {catalog?.connectionError && <p className="mt-2 text-sm text-amber-700">{catalog.connectionError}</p>}
      {canManage && <div className="mt-3">
        <label className="block font-medium" htmlFor="whatsapp-sample">PDF de ejemplo para solicitar aprobación</label>
        <p className="mb-2 text-xs text-slate-500">Elige un PDF de muestra, sin datos de clientes. Meta lo usará para revisar las plantillas.</p>
        <input id="whatsapp-sample" type="file" accept="application/pdf" disabled={busy} onChange={(event) => setSample(event.target.files?.[0] ?? null)} />
        {catalog?.configured && !catalog.canCreate && <p className="mt-2 text-amber-700">Falta completar la conexión de la aplicación de Meta para registrar plantillas.</p>}
      </div>}
      <div className="mt-3 grid gap-3 sm:grid-cols-3">
        {catalog?.data.map((item) => <div key={item.name} className="rounded-lg bg-slate-50 p-3">
          <p className="font-medium">{item.label}</p>
          <p className="mt-1 text-xs text-slate-500">{item.name} · {item.language} · {item.status === 'NOT_CREATED' ? 'Sin registrar' : item.status === 'UNKNOWN' ? 'Pendiente de consultar Meta' : item.status}</p>
          <p className="my-2 text-xs text-slate-600">{item.body}</p>
          {item.status === 'APPROVED' && !item.compatible && <p className="text-xs text-amber-700">El contenido de Meta difiere de esta plantilla; no se puede enviar.</p>}
          {canManage && item.status === 'NOT_CREATED' && <Button size="sm" disabled={busy || !sample || !catalog.canCreate} onClick={() => void create(item.name)}>Solicitar aprobación</Button>}
        </div>)}
      </div>
      {feedback && <p className="mt-3 text-sm" role="status">{feedback}</p>}
    </div>
  )
}
