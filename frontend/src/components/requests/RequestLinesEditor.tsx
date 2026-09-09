import { forwardRef, useImperativeHandle, useState } from 'react'

import { ChevronDown, ChevronUp, Copy, Plus, Save, Trash2, X } from 'lucide-react'

import { ProductLineComparator } from '@/components/quotes/ProductLineComparator'

import { Button } from '@/components/ui/Button'

import { Input } from '@/components/ui/Input'

import { InlineBusy, ModalBusyPanel } from '@/components/ui/LoadingState'

import { preventNegativeNumberKey, sanitizeQuantityInput } from '@/lib/quantity-input'

import type { RequestLine, WholesalerOffer } from '@/types'

export type RequestLinesEditorHandle = {
  openSaveModal: () => void
}

export const RequestLinesEditor = forwardRef<
  RequestLinesEditorHandle,
  {
    lines: RequestLine[]
    requestId?: string
    preferredWarehouse?: string
    autoApplyBest?: boolean
    comparatorEnabled?: boolean
    /** false para roles sin inventario (p. ej. ventas). */
    showComparator?: boolean
    dirty?: boolean
    saving?: boolean
    /** Permite Guardar aunque no haya cambios (p. ej. solo enviar a compras). */
    forceSaveAvailable?: boolean
    /** Menciona en el modal que se asignará al área elegida. */
    assignHint?: boolean
    onChange: (lines: RequestLine[]) => void
    onSave?: () => Promise<boolean>
    onCancel?: () => void
  }
>(function RequestLinesEditor(
  {
    lines,
    requestId,
    preferredWarehouse = 'CDMX',
    comparatorEnabled = true,
    showComparator = true,
    dirty = false,
    saving = false,
    forceSaveAvailable = false,
    assignHint = false,
    onChange,
    onSave,
    onCancel,
  },
  ref,
) {
  const [activeLineId, setActiveLineId] = useState<string | null>(null)
  const [saveModalOpen, setSaveModalOpen] = useState(false)

  useImperativeHandle(ref, () => ({
    openSaveModal: () => {
      if (!onSave) return
      if (!(dirty || forceSaveAvailable)) return
      setSaveModalOpen(true)
    },
  }))

  const handleSave = async () => {
    if (!onSave) return
    const saved = await onSave()
    if (saved) setSaveModalOpen(false)
  }



  const updateLine = (id: string, patch: Partial<RequestLine>) => {

    onChange(lines.map((l) => (l.id === id ? { ...l, ...patch } : l)))

  }



  const applyOffer = (

    lineId: string,

    offer: WholesalerOffer,

    allOffers?: WholesalerOffer[],

  ) => {

    onChange(

      lines.map((l) => {

        if (l.id !== lineId) return l

        const offers =

          allOffers?.map((o) => ({

            ...o,

            isSelected: o.wholesalerId === offer.wholesalerId,

          })) ?? l.offers

        return {

          ...l,

          product: (offer.description ?? '').trim() !== '' ? (offer.description ?? '').trim() : l.product,

          description: (offer.description ?? '').trim() !== '' ? (offer.description ?? '').trim() : l.description,

          referenceCost: offer.cost,

          warehouse: offer.warehouse,

          selectedWholesalerId: offer.wholesalerId,

          offers,

        }

      }),

    )

  }



  const removeLine = (id: string) => {

    onChange(lines.filter((l) => l.id !== id))

    if (activeLineId === id) setActiveLineId(null)

  }



  const duplicateLine = (id: string) => {

    const index = lines.findIndex((l) => l.id === id)

    if (index < 0) return

    const copy: RequestLine = {

      ...lines[index],

      id: `edit-${Date.now()}`,

    }

    const next = [...lines.slice(0, index + 1), copy, ...lines.slice(index + 1)]

    onChange(next)

    setActiveLineId(copy.id)

  }



  const moveLine = (id: string, direction: -1 | 1) => {

    const index = lines.findIndex((l) => l.id === id)

    const target = index + direction

    if (index < 0 || target < 0 || target >= lines.length) return

    const next = [...lines]

    ;[next[index], next[target]] = [next[target], next[index]]

    onChange(next)

  }



  const addLine = () => {

    const id = `edit-${Date.now()}`

    onChange([

      ...lines,

      {

        id,

        quantity: 1,

        product: '',

        partNumber: '',

        brand: '',

        description: '',

        unit: 'pza',

      },

    ])

    setActiveLineId(id)

  }



  const activeLine = lines.find((l) => l.id === activeLineId)



  return (

    <div>

      <div className="table-scroll-mobile">

        <table className="w-full min-w-[960px] text-left text-sm">

          <thead className="border-b bg-slate-50 text-slate-500">

            <tr>

              <th className="px-3 py-2 font-medium">Cant.</th>

              <th className="px-3 py-2 font-medium">Producto</th>

              <th className="px-3 py-2 font-medium">No. parte</th>

              <th className="px-3 py-2 font-medium">Marca</th>

              <th className="px-3 py-2" />

            </tr>

          </thead>

          <tbody>

            {lines.map((line, index) => (

              <tr

                key={line.id}

                className={`border-b border-slate-50 ${activeLineId === line.id ? 'bg-indigo-50/40' : ''}`}

              >

                <td className="px-3 py-2">

                  <Input

                    type="number"

                    min={1}

                    className="w-20"

                    placeholder="Cant."

                    value={line.quantity}

                    onFocus={() => setActiveLineId(line.id)}

                    onKeyDown={preventNegativeNumberKey}

                    onChange={(e) => {

                      const raw = sanitizeQuantityInput(e.target.value)

                      updateLine(line.id, {

                        quantity: Math.max(1, Number(raw) || 1),

                      })

                    }}

                  />

                </td>

                <td className="px-3 py-2">

                  <Input

                    value={line.product}

                    placeholder="Producto"

                    onFocus={() => setActiveLineId(line.id)}

                    onChange={(e) => updateLine(line.id, { product: e.target.value })}

                  />

                </td>

                <td className="px-3 py-2">

                  <Input

                    value={line.partNumber}

                    placeholder="No. parte"

                    onFocus={() => setActiveLineId(line.id)}

                    onChange={(e) => updateLine(line.id, { partNumber: e.target.value })}

                  />

                </td>

                <td className="px-3 py-2">

                  <Input

                    value={line.brand}

                    placeholder="Marca"

                    onFocus={() => setActiveLineId(line.id)}

                    onChange={(e) => updateLine(line.id, { brand: e.target.value })}

                  />

                </td>

                <td className="px-3 py-2">

                  <div className="flex items-center gap-0.5">

                    <Button

                      variant="ghost"

                      size="sm"

                      title="Subir línea"

                      disabled={index === 0}

                      onClick={() => moveLine(line.id, -1)}

                    >

                      <ChevronUp className="h-4 w-4" />

                    </Button>

                    <Button

                      variant="ghost"

                      size="sm"

                      title="Bajar línea"

                      disabled={index === lines.length - 1}

                      onClick={() => moveLine(line.id, 1)}

                    >

                      <ChevronDown className="h-4 w-4" />

                    </Button>

                    <Button

                      variant="ghost"

                      size="sm"

                      title="Duplicar línea"

                      onClick={() => duplicateLine(line.id)}

                    >

                      <Copy className="h-4 w-4" />

                    </Button>

                    <Button variant="ghost" size="sm" onClick={() => removeLine(line.id)}>

                      <Trash2 className="h-4 w-4 text-red-500" />

                    </Button>

                  </div>

                </td>

              </tr>

            ))}

          </tbody>

        </table>

        <div className="mt-3 flex flex-wrap items-center gap-2">

          <Button variant="secondary" size="sm" onClick={addLine} disabled={saving}>

            <Plus className="h-4 w-4" />

            Agregar línea

          </Button>

          {(dirty || forceSaveAvailable) && onSave ? (

            <>

              <Button size="sm" onClick={() => setSaveModalOpen(true)} disabled={saving}>

                {saving ? <InlineBusy size="sm" /> : <Save className="h-4 w-4" />}

                Guardar cambios

              </Button>

              {dirty && onCancel ? (

                <Button variant="secondary" size="sm" onClick={onCancel} disabled={saving}>

                  <X className="h-4 w-4" />

                  Cancelar

                </Button>

              ) : null}

            </>

          ) : null}

        </div>

      </div>



      {showComparator && activeLine && activeLine.partNumber.trim() !== '' && (

        <div className="mt-4 border-t border-slate-100 pt-4">

          <ProductLineComparator

            partNumber={activeLine.partNumber}

            quantity={activeLine.quantity}

            preferredWarehouse={preferredWarehouse}

            productLabel={activeLine.product || activeLine.partNumber}

            enabled={comparatorEnabled}

            autoApplyBest={false}

            context={{

              screen: 'requests',

              lineId: activeLine.id,

              requestId,

            }}

            onSelectOffer={(offer) => applyOffer(activeLine.id, offer, activeLine.offers)}

            onBestApplied={undefined}

          />

        </div>

      )}

      {saveModalOpen && onSave ? (

        <div

          className="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/50 px-4 pb-4 pt-[12vh]"

          role="dialog"

          aria-modal="true"

          aria-labelledby="save-request-lines-title"

          onClick={() => !saving && setSaveModalOpen(false)}

        >

          <div

            className="relative w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-xl"

            onClick={(event) => event.stopPropagation()}

          >

            {saving ? (

              <ModalBusyPanel label="Guardando cambios…" />

            ) : (

              <>

                <h3 id="save-request-lines-title" className="text-lg font-bold text-slate-900">

                  ¿Guardar cambios?

                </h3>

                <p className="mt-2 text-sm text-slate-600">

                  Se guardarán las partidas de la solicitud.

                  {assignHint
                    ? ' Al confirmar, también se enviará a la persona seleccionada.'
                    : ''}

                </p>

                <div className="mt-5 flex flex-col gap-3">

                  <Button

                    size="sm"

                    className="w-full justify-center"

                    onClick={() => void handleSave()}

                  >

                    Guardar

                  </Button>

                </div>

                <button

                  type="button"

                  className="mt-5 text-sm text-slate-500 hover:text-slate-700"

                  onClick={() => setSaveModalOpen(false)}

                >

                  Cancelar

                </button>

              </>

            )}

          </div>

        </div>

      ) : null}

    </div>

  )

})

RequestLinesEditor.displayName = 'RequestLinesEditor'
