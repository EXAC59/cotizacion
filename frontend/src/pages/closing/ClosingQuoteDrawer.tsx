import { useEffect, useRef, useState } from 'react'
import { X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Input, Label, Select, Textarea } from '@/components/ui/Input'
import { CLOSING_LABELS, CLOSING_STYLES, ClosingError, closingDetail, type ClosingDetail, type ClosingStatus } from '@/lib/quote-closing'
import { formatCurrency } from '@/lib/format'
import type { QuoteClosingStatus } from '@/types'

export function ClosingQuoteDrawer({ quoteId, onClose, onSaved }: {
  quoteId: string; onClose: () => void; onSaved: (data: ClosingDetail) => void
}) {
  const dialogRef = useRef<HTMLDialogElement>(null)
  const [detail, setDetail] = useState<ClosingDetail | null>(null)
  const [status, setStatus] = useState<ClosingStatus>('enviada')
  const [invoice, setInvoice] = useState('')
  const [comments, setComments] = useState('')
  const [selected, setSelected] = useState<string[]>([])
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [fields, setFields] = useState<Record<string, string[]>>({})
  const requestId = useRef<string | null>(null)
  const savingRef = useRef(false)
  const requiresInvoice = status === 'ganada' || status === 'ganada_parcialmente'

  useEffect(() => {
    let cancelled = false
    dialogRef.current?.showModal()
    closingDetail(quoteId).then((data) => {
      if (cancelled) return
      setDetail(data); setStatus(data.status); setInvoice(data.invoice); setComments(data.comments)
      setSelected(data.selectedLines.filter((saved) => data.lines.some((line) => line.id === saved.id)).map((line) => line.id))
    }).catch((err) => { if (!cancelled) setError(err instanceof Error ? err.message : 'No se pudo cargar la cotización.') })
    return () => { cancelled = true }
  }, [quoteId])

  const changed = () => { requestId.current = null; setFields({}); setError('') }
  const close = () => { if (!savingRef.current) dialogRef.current?.close() }
  const save = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!detail || !detail.canEdit || savingRef.current) return
    const validation: Record<string, string[]> = {}
    if (status === 'enviada') validation.status = ['Elige el resultado de la cotización.']
    if (requiresInvoice && !invoice.trim()) validation.invoice = ['Captura la factura.']
    if (['negociacion', 'perdida'].includes(status) && !comments.trim()) validation.comments = [status === 'perdida' ? 'Escribe el motivo de la pérdida.' : 'Escribe la aclaración de la negociación.']
    if (status === 'ganada_parcialmente' && (selected.length === 0 || selected.length >= detail.lines.length)) validation.selectedLineIds = ['Selecciona las partidas compradas. Si son todas, elige Ganada.']
    setFields(validation)
    if (Object.keys(validation).length) {
      dialogRef.current?.querySelector<HTMLElement>(`[name="${Object.keys(validation)[0]}"]`)?.focus()
      return
    }
    savingRef.current = true; setSaving(true); setError('')
    requestId.current ??= crypto.randomUUID()
    try {
      const data = await closingDetail(quoteId, {
        status: status as QuoteClosingStatus, invoice, comments, selectedLineIds: selected,
        version: detail.version, quoteVersion: detail.quoteVersion, requestId: requestId.current,
      })
      onSaved(data)
      dialogRef.current?.close()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'No se pudo guardar. Vuelve a intentarlo.')
      if (err instanceof ClosingError) setFields(err.fields)
    } finally { savingRef.current = false; setSaving(false) }
  }
  const fieldError = (name: string) => fields[name]?.[0]

  return <dialog ref={dialogRef} onClose={onClose} onCancel={(event) => { if (savingRef.current) event.preventDefault() }} onClick={(event) => { if (event.target === event.currentTarget) close() }} aria-labelledby="closing-edit-title" className="fixed inset-y-0 right-0 left-auto m-0 h-svh max-h-none w-full max-w-xl border-0 bg-white p-0 text-slate-800 shadow-2xl backdrop:bg-slate-950/50">
    <form onSubmit={(event) => void save(event)} className="flex h-full flex-col" noValidate>
      <header className="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-4">
        <h2 id="closing-edit-title" className="text-lg font-semibold">Cierre de cotización</h2>
        <Button variant="ghost" className="h-11 w-11 shrink-0 p-0" aria-label="Cerrar panel" disabled={saving} onClick={close}><X className="h-5 w-5" aria-hidden /></Button>
      </header>
      <div className="min-h-0 flex-1 space-y-5 overflow-y-auto p-6">
        {error && <p role="alert" className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">{error}</p>}
        {!detail ? !error && <p role="status">Cargando cotización…</p> : <>
          <dl className="grid gap-4 rounded-xl bg-slate-50 p-4 text-sm">
            <div><dt className="text-slate-500">Folio</dt><dd className="mt-1 font-semibold text-slate-900">{detail.folio}</dd></div>
            <div><dt className="text-slate-500">Cliente</dt><dd className="mt-1 font-medium text-slate-900">{detail.clientName}</dd></div>
          </dl>
          {!detail.canEdit && <p role="status" className="text-sm text-slate-600">Solo Compras, Administración o el vendedor responsable puede registrar el cierre.</p>}
          <fieldset disabled={saving || !detail.canEdit} className="space-y-5 disabled:opacity-70">
            <div><Label htmlFor="closing-edit-status">Estatus</Label>
              <Select name="status" id="closing-edit-status" value={status} onChange={(event) => { setStatus(event.target.value as ClosingStatus); changed() }} aria-invalid={Boolean(fieldError('status'))} aria-describedby={fieldError('status') ? 'closing-status-error' : undefined} className="min-h-11">
                {Object.entries(CLOSING_LABELS).map(([value, label]) => <option key={value} value={value} disabled={value === 'enviada'} className={CLOSING_STYLES[value as ClosingStatus]}>{label}</option>)}
              </Select>{fieldError('status') && <p id="closing-status-error" role="alert" className="mt-1 text-sm text-red-700">{fieldError('status')}</p>}
            </div>
            {requiresInvoice && <div><Label htmlFor="closing-invoice">Factura</Label><Input name="invoice" id="closing-invoice" placeholder="Número de factura" value={invoice} onChange={(event) => { setInvoice(event.target.value); changed() }} maxLength={60} required aria-invalid={Boolean(fieldError('invoice'))} aria-describedby={fieldError('invoice') ? 'closing-invoice-error' : undefined} />{fieldError('invoice') && <p id="closing-invoice-error" role="alert" className="mt-1 text-sm text-red-700">{fieldError('invoice')}</p>}</div>}
            {(status === 'perdida' || status === 'negociacion') && <div><Label htmlFor="closing-comments">{status === 'perdida' ? '¿Motivo de la pérdida de la cotización?' : 'Aclaración de la negociación con el cliente'}</Label><Textarea id="closing-comments" name="comments" placeholder={status === 'perdida' ? 'Escribe el motivo de la pérdida' : 'Escribe la aclaración de la negociación'} value={comments} onChange={(event) => { setComments(event.target.value); changed() }} maxLength={2000} required aria-invalid={Boolean(fieldError('comments'))} aria-describedby={fieldError('comments') ? 'closing-comments-error' : undefined} />{fieldError('comments') && <p id="closing-comments-error" role="alert" className="mt-1 text-sm text-red-700">{fieldError('comments')}</p>}</div>}
            {status === 'ganada_parcialmente' && <div>
              <h3 className="font-semibold">Partidas compradas</h3><p className="mt-1 text-sm text-slate-600">Selecciona las partidas que compró el cliente. Si son todas, elige Ganada.</p>
              <div className="mt-3 space-y-3" role="group" aria-label="Partidas de la cotización">
                {detail.lines.map((line) => <div key={line.id} className={`rounded-xl border p-4 ${selected.includes(line.id) ? 'border-yellow-300 bg-yellow-50' : 'border-slate-200'}`}>
                  <p className="font-medium text-slate-900">{line.product}</p><p className="mt-1 text-sm text-slate-600">{line.partNumber || 'Sin número de parte'} · Cantidad: {line.quantity}</p>
                  <div className="mt-3 flex flex-wrap items-center justify-between gap-3"><span className="text-sm font-semibold">{formatCurrency(line.amount)}</span>
                    <Button name="selectedLineIds" variant={selected.includes(line.id) ? 'secondary' : 'primary'} className="min-h-11" aria-pressed={selected.includes(line.id)} aria-label={`${selected.includes(line.id) ? 'Cancelar selección de' : 'Seleccionar'} ${line.product}`} onClick={() => { setSelected((previous) => previous.includes(line.id) ? previous.filter((id) => id !== line.id) : [...previous, line.id]); changed() }}>{selected.includes(line.id) ? 'Cancelar selección' : 'Seleccionar'}</Button>
                  </div>
                </div>)}
              </div>{fieldError('selectedLineIds') && <p role="alert" className="mt-2 text-sm text-red-700">{fieldError('selectedLineIds')}</p>}
              <p className="mt-3 text-sm font-medium">{selected.length} de {detail.lines.length} partidas seleccionadas</p>
            </div>}
          </fieldset>
        </>}
      </div>
      <footer className="flex justify-end gap-3 border-t border-slate-200 bg-white px-6 py-4">
        <Button variant="secondary" className="min-h-11" disabled={saving} onClick={close}>Cancelar</Button>
        <Button type="submit" className="min-h-11" disabled={!detail?.canEdit || saving}>{saving ? 'Guardando…' : 'Guardar'}</Button>
      </footer>
    </form>
  </dialog>
}
