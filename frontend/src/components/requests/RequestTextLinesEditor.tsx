import { Plus, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { createEmptyFreeTextLines } from '@/lib/parse-request-lines'
import { preventNegativeNumberKey, sanitizeQuantityInput } from '@/lib/quantity-input'
import type { RequestLine } from '@/types'

export function RequestTextLinesEditor({
  lines,
  disabled = false,
  onChange,
}: {
  lines: RequestLine[]
  disabled?: boolean
  onChange: (lines: RequestLine[]) => void
}) {
  const updateLine = (id: string, patch: Partial<RequestLine>) => {
    onChange(
      lines.map((line) => {
        if (line.id !== id) return line
        const next = { ...line, ...patch }
        if (patch.product !== undefined && !patch.description) {
          next.description = patch.product
        }
        return next
      }),
    )
  }

  const addLine = () => onChange([...lines, ...createEmptyFreeTextLines(1)])

  const removeLine = (id: string) => {
    if (lines.length <= 1) {
      onChange(createEmptyFreeTextLines(1))
      return
    }
    onChange(lines.filter((line) => line.id !== id))
  }

  return (
    <div className="space-y-2">
      <div className="overflow-x-auto rounded-lg border border-indigo-100 bg-indigo-50/40">
        <table className="w-full min-w-[560px] text-left text-sm">
          <thead className="border-b border-indigo-100 bg-white/70 text-slate-500">
            <tr>
              <th className="px-2 py-2 font-medium">Cant.</th>
              <th className="px-2 py-2 font-medium">Producto</th>
              <th className="px-2 py-2 font-medium">No. parte</th>
              <th className="px-2 py-2 font-medium">Marca</th>
              <th className="w-10 px-2 py-2" />
            </tr>
          </thead>
          <tbody>
            {lines.map((line) => (
              <tr key={line.id} className="border-b border-indigo-50/80">
                <td className="px-2 py-1.5">
                  <input
                    type="text"
                    inputMode="numeric"
                    className="w-16 rounded border border-slate-200 bg-white px-2 py-1.5 text-sm font-medium disabled:bg-slate-100"
                    placeholder="Cant."
                    value={line.quantity === 0 ? '' : String(line.quantity)}
                    disabled={disabled}
                    onKeyDown={preventNegativeNumberKey}
                    onChange={(e) => {
                      const digits = sanitizeQuantityInput(e.target.value)
                      updateLine(line.id, {
                        quantity: digits === '' ? 0 : Number(digits),
                      })
                    }}
                  />
                </td>
                <td className="px-2 py-1.5">
                  <input
                    type="text"
                    className="w-full min-w-[120px] rounded border border-slate-200 bg-white px-2 py-1.5 text-sm disabled:bg-slate-100"
                    value={line.product}
                    disabled={disabled}
                    placeholder="Descripción"
                    onChange={(e) => updateLine(line.id, { product: e.target.value })}
                  />
                </td>
                <td className="px-2 py-1.5">
                  <input
                    type="text"
                    className="w-full min-w-[100px] rounded border border-slate-200 bg-white px-2 py-1.5 font-mono text-xs disabled:bg-slate-100"
                    value={line.partNumber}
                    disabled={disabled}
                    placeholder="SKU"
                    onChange={(e) => updateLine(line.id, { partNumber: e.target.value })}
                  />
                </td>
                <td className="px-2 py-1.5">
                  <input
                    type="text"
                    className="w-full min-w-[80px] rounded border border-slate-200 bg-white px-2 py-1.5 text-sm disabled:bg-slate-100"
                    value={line.brand}
                    disabled={disabled}
                    placeholder="Marca"
                    onChange={(e) => updateLine(line.id, { brand: e.target.value })}
                  />
                </td>
                <td className="px-2 py-1.5">
                  <button
                    type="button"
                    className="rounded p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-40"
                    disabled={disabled}
                    aria-label="Quitar fila"
                    onClick={() => removeLine(line.id)}
                  >
                    <Trash2 className="h-4 w-4" />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        <p className="px-3 py-2 text-xs text-slate-500">
          Captura directa — se guarda con estas partidas.
        </p>
      </div>
      <Button
        type="button"
        variant="secondary"
        size="sm"
        disabled={disabled}
        onClick={addLine}
      >
        <Plus className="h-4 w-4" />
        Agregar fila
      </Button>
    </div>
  )
}
