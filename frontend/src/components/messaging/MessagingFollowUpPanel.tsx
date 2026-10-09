import { useEffect, useRef, useState } from 'react'
import { FollowUpHistoryBlock } from '@/components/recordatorios/FollowUpHistoryBlock'
import { QuoteFollowUpPanel } from '@/components/quotes/QuoteFollowUpPanel'
import { Button } from '@/components/ui/Button'
import { FollowUpBadge } from '@/components/ui/FollowUpBadge'
import { Textarea } from '@/components/ui/Input'
import { InlineBusy } from '@/components/ui/LoadingState'
import { QuoteStatusBadge } from '@/components/ui/QuoteStatusBadge'
import { notifySalesAboutQuote } from '@/lib/notifications-api'
import { isUnsentForClientQuote } from '@/lib/quote-status'
import { claimQuoteFollowUp, getQuoteById, openQuotePdf } from '@/lib/quotes-api'
import { formatDate } from '@/lib/format'
import type { Quote, User } from '@/types'

export function MessagingFollowUpPanel({ quoteId, user, canEdit, onSaved }: {
  quoteId: string
  user: User
  canEdit: boolean
  onSaved: (claimed?: boolean) => Promise<void>
}) {
  const [quote, setQuote] = useState<Quote | null>(null)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [message, setMessage] = useState('')
  const [declaration, setDeclaration] = useState('')
  const [busy, setBusy] = useState(false)
  const mounted = useRef(false)

  useEffect(() => {
    mounted.current = true
    void getQuoteById(quoteId).then((value) => {
      if (mounted.current) setQuote(value)
    }).catch((reason) => {
      if (mounted.current) setError(reason instanceof Error ? reason.message : 'No se pudo cargar el seguimiento.')
    })
    return () => { mounted.current = false }
  }, [quoteId])

  async function refresh(claimed = false) {
    try {
      const value = await getQuoteById(quoteId)
      if (mounted.current) setQuote(value)
      await onSaved(claimed)
    } catch (reason) {
      if (mounted.current) setError(reason instanceof Error ? reason.message : 'Recarga para consultar el seguimiento actualizado.')
    }
  }

  async function act(task: () => Promise<unknown>, done: string, claimed = false) {
    if (busy) return
    setBusy(true); setError(''); setSuccess('')
    try {
      await task()
      if (mounted.current) { setSuccess(done); setMessage(''); setDeclaration('') }
      await refresh(claimed)
    } catch (reason) {
      if (mounted.current) setError(reason instanceof Error ? reason.message : 'No se pudo guardar el cambio.')
    } finally {
      if (mounted.current) setBusy(false)
    }
  }

  if (!quote) return error ? <p role="alert" className="text-sm text-amber-800">{error}</p> : <InlineBusy label="Cargando seguimiento…" />

  const sales = user.role === 'ventas'
  const admin = user.role === 'administrador'
  const assignedElsewhere = Boolean(quote.followUpAssigneeId && !quote.followUpAssignedToViewer)
  const canUpdate = canEdit && isUnsentForClientQuote(quote) && (admin || (sales && !assignedElsewhere && quote.ownedByViewer === true))
  const canClaim = canEdit && sales && !quote.followUpAssigneeId && quote.assignedToSales === true && quote.eligibility?.eligible === true
  const salesRecipientName = quote.eligibility?.notifyRecipientName
  const canNotify = canEdit && (user.role === 'gerente_compras' || admin) && Boolean(salesRecipientName)

  if (sales && (assignedElsewhere || (!quote.ownedByViewer && !canClaim))) {
    return <p role="alert" className="text-sm text-slate-600">El seguimiento pertenece a otro vendedor.</p>
  }

  return <div className="messaging-followup space-y-4">
    <div className="flex flex-wrap items-center justify-between gap-2">
      <div><strong>{quote.folio}</strong><p className="text-sm text-slate-600">{quote.clientName}</p></div>
      <QuoteStatusBadge status={quote.status} />
    </div>
    <div className="flex flex-wrap items-center gap-2 text-sm">
      <span>Ventas: {salesRecipientName || quote.followUpAssigneeName || quote.createdByName || 'Sin asignar'}</span>
      {quote.followUp?.status && <FollowUpBadge status={quote.followUp.status} />}
    </div>
    {quote.followUp?.remindAt && <p className="text-sm text-slate-700">Próximo seguimiento: <strong>{formatDate(quote.followUp.remindAt)}</strong></p>}

    {canClaim && <form className="space-y-2 rounded-xl border border-slate-200 p-3" onSubmit={(event) => {
      event.preventDefault()
      void act(() => claimQuoteFollowUp(quoteId, declaration.trim()), 'Tomaste el seguimiento de esta cotización.', true)
    }}>
      <label htmlFor="messaging-followup-declaration" className="text-sm font-semibold">Tomar seguimiento de Ventas</label>
      <Textarea id="messaging-followup-declaration" required minLength={10} maxLength={1000} rows={2} value={declaration} disabled={busy} onChange={(event) => setDeclaration(event.target.value)} placeholder="Describe cómo darás seguimiento al cliente." />
      <Button type="submit" disabled={busy || declaration.trim().length < 10}>Guardar declaración y tomar</Button>
    </form>}

    <fieldset disabled={busy}>
      <QuoteFollowUpPanel quoteId={quoteId} initialFollowUp={quote.followUp ?? null} initialHistory={quote.followUpHistory ?? []} initialEligibility={quote.eligibility} canEdit={canUpdate} canNotifySales={false} embedded detailMode showHistory={false} onSaved={() => void refresh()} />
    </fieldset>
    {!canUpdate && !quote.followUp && sales && <p className="text-sm text-slate-600">{assignedElsewhere ? 'El seguimiento está asignado a otro vendedor.' : !isUnsentForClientQuote(quote) ? 'La programación de fechas y el resultado comercial se guardan cuando la cotización está Lista / Terminada y pendiente de enviar.' : 'Toma el seguimiento para actualizarlo.'}</p>}

    {canNotify && <form className="space-y-2" onSubmit={(event) => {
      event.preventDefault()
      void act(() => notifySalesAboutQuote(quoteId, message.trim()), `Mensaje enviado a ${salesRecipientName}.`)
    }}>
      <label htmlFor="messaging-followup-notice" className="text-sm font-semibold">Mensaje para {salesRecipientName}</label>
      <Textarea id="messaging-followup-notice" required minLength={3} maxLength={1000} rows={3} disabled={busy} value={message} onChange={(event) => setMessage(event.target.value)} placeholder="Escribe qué necesita atención en esta cotización." />
      <Button type="submit" disabled={busy || message.trim().length < 3}>Enviar mensaje</Button>
    </form>}

    <FollowUpHistoryBlock history={quote.followUpHistory ?? []} />
    <Button type="button" variant="secondary" onClick={() => void openQuotePdf(quoteId)}>Ver PDF de la cotización</Button>
    {success && <p role="status" className="text-sm text-emerald-700">{success}</p>}
    {error && <p role="alert" className="text-sm text-amber-800">{error}</p>}
  </div>
}
