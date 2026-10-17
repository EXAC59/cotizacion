import { useEffect, useRef, useState } from 'react'
import { Move, X } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { closingDetail, CLOSING_LABELS, CLOSING_STYLES, type ClosingDetail } from '@/lib/quote-closing'
import { formatCurrency, formatPercent } from '@/lib/format'
import { usePermission } from '@/hooks/usePermission'

export function ClosingQuotePreview({ quoteId, onClose }: { quoteId: string; onClose: () => void }) {
  const { user } = usePermission()
  const dialogRef = useRef<HTMLDialogElement>(null)
  const [detail, setDetail] = useState<ClosingDetail | null>(null)
  const [error, setError] = useState('')
  const [position, setPosition] = useState(() => ({ x: Math.max(16, (window.innerWidth - Math.min(960, window.innerWidth - 32)) / 2), y: 24 }))
  const drag = useRef<{ x: number; y: number } | null>(null)
  const showFinancials = ['gerente_compras', 'administrador'].includes(user?.role ?? '') && Boolean(detail?.financials)
  useEffect(() => {
    let cancelled = false
    dialogRef.current?.showModal()
    closingDetail(quoteId).then((data) => { if (!cancelled) setDetail(data) })
      .catch((err) => { if (!cancelled) setError(err instanceof Error ? err.message : 'No se pudo cargar la cotización.') })
    return () => { cancelled = true }
  }, [quoteId])
  const move = (x: number, y: number) => setPosition({
    x: Math.max(0, Math.min(window.innerWidth - (dialogRef.current?.offsetWidth ?? 0), x)),
    y: Math.max(0, Math.min(window.innerHeight - (dialogRef.current?.offsetHeight ?? 0), y)),
  })

  return <dialog ref={dialogRef} onClose={onClose} aria-labelledby="closing-preview-title" style={{ left: position.x, top: position.y }} className="fixed m-0 max-h-[85svh] w-[calc(100%-2rem)] max-w-[960px] overflow-hidden rounded-2xl border border-slate-200 bg-white p-0 text-slate-800 shadow-2xl backdrop:bg-slate-950/50">
    <header className="flex items-center gap-3 border-b border-slate-200 px-5 py-3">
      <button type="button" aria-label="Mover ventana de cotización" title="Arrastra para mover. También puedes usar las flechas del teclado." className="flex min-h-11 min-w-0 flex-1 cursor-move touch-none items-center gap-3 rounded-lg text-left focus-visible:outline-2 focus-visible:outline-indigo-600" onPointerDown={(event) => { if (event.button !== 0) return; drag.current = { x: event.clientX - position.x, y: event.clientY - position.y }; event.currentTarget.setPointerCapture(event.pointerId) }} onPointerMove={(event) => { if (drag.current) move(event.clientX - drag.current.x, event.clientY - drag.current.y) }} onPointerUp={(event) => { drag.current = null; if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId) }} onPointerCancel={() => { drag.current = null }} onKeyDown={(event) => {
        const offsets: Record<string, [number, number]> = { ArrowLeft: [-20, 0], ArrowRight: [20, 0], ArrowUp: [0, -20], ArrowDown: [0, 20] }
        const offset = offsets[event.key]; if (offset) { event.preventDefault(); move(position.x + offset[0], position.y + offset[1]) }
      }}><Move className="h-5 w-5 shrink-0 text-slate-500" aria-hidden /><h2 id="closing-preview-title" className="font-semibold">Cotización {detail?.folio}</h2></button>
      <Button variant="ghost" className="h-11 w-11 shrink-0 p-0" aria-label="Cerrar cotización" onClick={() => dialogRef.current?.close()}><X className="h-5 w-5" aria-hidden /></Button>
    </header>
    <div className="max-h-[calc(85svh-84px)] space-y-5 overflow-y-auto p-5">
      {error ? <p role="alert" className="text-sm text-red-700">{error}</p> : !detail ? <p role="status">Cargando cotización…</p> : <>
        <div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-sm text-slate-500">Cliente</p><p className="mt-1 font-semibold">{detail.clientName}</p></div><span className={`rounded-full border px-3 py-1 text-xs font-semibold ${CLOSING_STYLES[detail.status]}`}>{CLOSING_LABELS[detail.status]}</span></div>
        <div className="overflow-x-auto rounded-xl border border-slate-200" role="region" aria-label="Partidas de la cotización" tabIndex={0}>
          <table className={`w-full text-left text-sm ${showFinancials ? 'min-w-[800px]' : 'min-w-[600px]'}`}><thead className="bg-slate-50 text-slate-600"><tr className="[&>th]:p-3"><th scope="col">Partida</th><th scope="col">Descripción</th><th scope="col" className="text-right">Cantidad</th><th scope="col" className="text-right">Precio unitario</th><th scope="col" className="text-right">Importe</th>{showFinancials && <><th scope="col" className="text-right">Margen</th><th scope="col" className="text-right">Utilidad</th></>}</tr></thead>
            <tbody className="divide-y divide-slate-100">{detail.lines.map((line, index) => <tr key={line.id} className="[&>td]:p-3 [&>td]:align-top"><td>{index + 1}</td><td><p className="font-medium">{line.product}</p>{line.partNumber && <p className="mt-1 text-xs text-slate-500">{line.partNumber}</p>}</td><td className="text-right">{line.quantity}</td><td className="whitespace-nowrap text-right">{formatCurrency(line.salePrice)}</td><td className="whitespace-nowrap text-right font-medium">{formatCurrency(line.amount)}</td>{showFinancials && <><td className="whitespace-nowrap text-right">{line.financials ? formatPercent(line.financials.marginPercent) : '—'}</td><td className="whitespace-nowrap text-right">{line.financials ? formatCurrency(line.financials.profit) : '—'}</td></>}</tr>)}
              {detail.lines.length === 0 && <tr><td colSpan={showFinancials ? 7 : 5} className="p-5 text-center text-slate-500">Esta cotización no tiene partidas.</td></tr>}
            </tbody>
          </table>
        </div>
        <dl className="ml-auto grid max-w-xs grid-cols-2 gap-x-8 gap-y-3 text-sm"><dt className="text-slate-600">Subtotal</dt><dd className="text-right">{formatCurrency(detail.subtotal)}</dd><dt className="text-slate-600">IVA ({detail.taxPercent}%)</dt><dd className="text-right">{formatCurrency(detail.taxAmount)}</dd><dt className="border-t border-slate-200 pt-3 text-base font-semibold">Total</dt><dd className="border-t border-slate-200 pt-3 text-right text-base font-semibold">{formatCurrency(detail.total)}</dd></dl>
        {showFinancials && detail.financials && <dl className="ml-auto grid max-w-xs grid-cols-2 gap-x-8 gap-y-3 rounded-xl bg-slate-50 p-4 text-sm"><dt className="text-slate-600">Margen</dt><dd className="text-right font-medium">{formatPercent(detail.financials.marginPercent)}</dd><dt className="text-slate-600">Utilidad</dt><dd className="text-right font-semibold">{formatCurrency(detail.financials.profit)}</dd></dl>}
      </>}
    </div>
  </dialog>
}
