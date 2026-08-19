import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import type { RequestLine } from '@/types'

export function RequestLinesPreview({
  lines,
  title = 'Vista preliminar de lectura',
  subtitle,
}: {
  lines: RequestLine[]
  title?: string
  subtitle?: string
}) {
  if (!lines.length) return null

  return (
    <Card className="border-indigo-200 bg-indigo-50/30">
      <CardHeader
        title={title}
        subtitle={subtitle ?? 'Revisa que cantidades, SKU y descripciones sean correctas antes de procesar'}
      />
      <CardBody className="p-0">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-indigo-100 bg-white/60 text-slate-500">
            <tr>
              <th className="px-5 py-3 font-medium">Cant.</th>
              <th className="px-5 py-3 font-medium">Producto</th>
              <th className="px-5 py-3 font-medium">No. parte</th>
              <th className="px-5 py-3 font-medium">Marca</th>
            </tr>
          </thead>
          <tbody>
            {lines.map((l) => (
              <tr key={l.id} className="border-b border-indigo-50/80">
                <td className="px-5 py-3 font-medium">{l.quantity}</td>
                <td className="px-5 py-3">{l.product}</td>
                <td className="px-5 py-3 font-mono text-xs">{l.partNumber}</td>
                <td className="px-5 py-3">{l.brand}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </CardBody>
    </Card>
  )
}
