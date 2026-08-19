import { useEffect, useState, useTransition } from 'react'
import { TrendingDown, TrendingUp, Trophy, Users } from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { LoadingState, StatGridSkeleton } from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { StatCard } from '@/components/ui/StatCard'
import {
  fetchReportes,
  periodRangeForPreset,
  type AnalyticsPeriodPreset,
} from '@/lib/dashboard-api'
import { formatCurrency } from '@/lib/format'
import type { ReportesAnalytics, SalespersonProfit } from '@/types'

const PERIOD_OPTIONS: { id: AnalyticsPeriodPreset; label: string }[] = [
  { id: 'current_month', label: 'Mes actual' },
  { id: 'previous_month', label: 'Mes anterior' },
  { id: 'last_6_months', label: 'Últimos 6 meses' },
  { id: 'all', label: 'Todo' },
]

function SalespersonProfitList({ items }: { items: SalespersonProfit[] }) {
  if (items.length === 0) {
    return <p className="text-sm text-slate-500">Sin utilidad por vendedor en el periodo.</p>
  }

  const max = Math.max(...items.map((p) => p.profit), 1)

  return (
    <div className="space-y-3">
      {items.map((person) => (
        <div key={`${person.userId ?? 'none'}-${person.name}`}>
          <div className="flex items-center justify-between gap-2 text-sm">
            <span className="truncate font-medium text-slate-700">{person.name}</span>
            <span className="shrink-0 text-slate-600">
              {formatCurrency(person.profit)} · {person.quoteCount} cot.
            </span>
          </div>
          <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
            <div
              className="h-full rounded-full bg-emerald-500"
              style={{ width: `${(person.profit / max) * 100}%` }}
            />
          </div>
        </div>
      ))}
    </div>
  )
}

export function ReportsPage() {
  const [period, setPeriod] = useState<AnalyticsPeriodPreset>('current_month')
  const [analytics, setAnalytics] = useState<ReportesAnalytics | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [initialLoad, setInitialLoad] = useState(true)
  const [isPending, startTransition] = useTransition()

  useEffect(() => {
    let cancelled = false
    const range = periodRangeForPreset(period)

    fetchReportes(range)
      .then((data) => {
        if (!cancelled) {
          setAnalytics(data)
          setError(null)
        }
      })
      .catch(() => {
        if (!cancelled) setError('No se pudieron cargar los reportes.')
      })
      .finally(() => {
        if (!cancelled) setInitialLoad(false)
      })

    return () => {
      cancelled = true
    }
  }, [period])

  const loading = initialLoad || isPending

  const selectPeriod = (id: AnalyticsPeriodPreset) => {
    startTransition(() => setPeriod(id))
  }

  const topQuoted = analytics?.topQuotedProducts ?? []
  const maxQuoted = Math.max(...topQuoted.map((p) => p.count), 1)
  const trendMax = Math.max(
    ...(analytics?.profitTrend.map((p) => Math.max(p.realizedProfit, p.potentialProfit)) ?? [1]),
    1,
  )
  const closedTotal = (analytics?.wonQuotes ?? 0) + (analytics?.lostQuotes ?? 0)

  return (
    <div>
      <PageHeader
        title="Reportes y analítica"
        description="Ventas, utilidad y desempeño comercial"
        actions={
          <div className="flex flex-wrap gap-2">
            {PERIOD_OPTIONS.map((opt) => (
              <button
                key={opt.id}
                type="button"
                onClick={() => selectPeriod(opt.id)}
                className={`rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${
                  period === opt.id
                    ? 'bg-indigo-600 text-white'
                    : 'bg-white text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50'
                }`}
              >
                {opt.label}
              </button>
            ))}
          </div>
        }
      />

      {error && (
        <p className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          {error}
        </p>
      )}

      {loading && (
        <div className="mb-6 space-y-4">
          <LoadingState label="Cargando reportes…" variant="inline" className="py-6" />
          <StatGridSkeleton count={4} />
        </div>
      )}

      {analytics && (
        <p className="mb-4 text-sm text-slate-500">
          Periodo: {analytics.period.from} — {analytics.period.to}
        </p>
      )}

      <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
        Financiero
      </h2>
      <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="Utilidad realizada"
          value={formatCurrency(analytics?.monthlyRealizedProfit ?? 0)}
          hint="Cotizaciones ganadas"
          icon={TrendingUp}
        />
        <StatCard
          label="Utilidad potencial"
          value={formatCurrency(analytics?.monthlyPotentialProfit ?? 0)}
          hint="Todas las cotizaciones del periodo"
          icon={TrendingDown}
        />
        <StatCard
          label="Ganadas / Perdidas"
          value={
            analytics ? `${analytics.wonQuotes} / ${analytics.lostQuotes}` : '— / —'
          }
          hint={analytics ? `Tasa de cierre: ${analytics.winRate}%` : undefined}
          icon={Trophy}
        />
        <StatCard
          label="Ticket promedio"
          value={formatCurrency(analytics?.averageTicket ?? 0)}
          hint="Promedio de total por cotización"
          icon={Users}
        />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="Utilidad del periodo" />
          <CardBody>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <p className="text-sm text-slate-500">Realizada (ganadas)</p>
                <p className="text-3xl font-bold text-emerald-600">
                  {formatCurrency(analytics?.monthlyRealizedProfit ?? 0)}
                </p>
              </div>
              <div>
                <p className="text-sm text-slate-500">Potencial (todas)</p>
                <p className="text-3xl font-bold text-slate-900">
                  {formatCurrency(analytics?.monthlyPotentialProfit ?? 0)}
                </p>
              </div>
            </div>
            {analytics && analytics.profitTrend.length > 0 && (
              <div className="mt-6">
                <p className="mb-3 text-xs font-medium uppercase tracking-wide text-slate-400">
                  Tendencia últimos 6 meses
                </p>
                <div className="flex h-32 items-end gap-2">
                  {analytics.profitTrend.map((point) => (
                    <div key={point.month} className="flex flex-1 flex-col items-center gap-1">
                      <div className="flex w-full items-end justify-center gap-0.5" style={{ height: '96px' }}>
                        <div
                          className="w-2 rounded-t bg-emerald-400"
                          style={{
                            height: `${Math.max(4, (point.realizedProfit / trendMax) * 96)}px`,
                          }}
                          title={`Realizada: ${formatCurrency(point.realizedProfit)}`}
                        />
                        <div
                          className="w-2 rounded-t bg-indigo-300"
                          style={{
                            height: `${Math.max(4, (point.potentialProfit / trendMax) * 96)}px`,
                          }}
                          title={`Potencial: ${formatCurrency(point.potentialProfit)}`}
                        />
                      </div>
                      <span className="text-[10px] text-slate-400">{point.label}</span>
                    </div>
                  ))}
                </div>
                <div className="mt-2 flex gap-4 text-xs text-slate-500">
                  <span className="flex items-center gap-1">
                    <span className="inline-block h-2 w-2 rounded bg-emerald-400" />
                    Realizada
                  </span>
                  <span className="flex items-center gap-1">
                    <span className="inline-block h-2 w-2 rounded bg-indigo-300" />
                    Potencial
                  </span>
                </div>
              </div>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader title="Cotizaciones ganadas / perdidas" />
          <CardBody>
            <div className="flex gap-8">
              <div>
                <p className="text-2xl font-bold text-emerald-600">{analytics?.wonQuotes ?? 0}</p>
                <p className="text-sm text-slate-500">Ganadas</p>
              </div>
              <div>
                <p className="text-2xl font-bold text-red-600">{analytics?.lostQuotes ?? 0}</p>
                <p className="text-sm text-slate-500">Perdidas</p>
              </div>
              <div>
                <p className="text-2xl font-bold text-slate-700">{analytics?.winRate ?? 0}%</p>
                <p className="text-sm text-slate-500">Tasa de cierre</p>
              </div>
            </div>
            {closedTotal > 0 && (
              <div className="mt-4 flex h-3 overflow-hidden rounded-full bg-slate-100">
                <div
                  className="bg-emerald-500"
                  style={{ width: `${((analytics?.wonQuotes ?? 0) / closedTotal) * 100}%` }}
                />
                <div
                  className="bg-red-400"
                  style={{ width: `${((analytics?.lostQuotes ?? 0) / closedTotal) * 100}%` }}
                />
              </div>
            )}
          </CardBody>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader title="Utilidad por vendedor" />
          <CardBody>
            <SalespersonProfitList items={analytics?.profitBySalesperson ?? []} />
          </CardBody>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader title="Productos más cotizados" />
          <CardBody>
            {topQuoted.length === 0 ? (
              <p className="text-sm text-slate-500">Sin datos en el periodo seleccionado.</p>
            ) : (
              <div className="space-y-3">
                {topQuoted.map((p, i) => (
                  <div key={`${p.partNumber ?? ''}-${p.name}`} className="flex items-center gap-4">
                    <span className="w-6 text-sm font-medium text-slate-400">{i + 1}</span>
                    <div className="flex-1">
                      <div className="flex justify-between text-sm">
                        <span>{p.name}</span>
                        <span className="font-medium">{p.count}</span>
                      </div>
                      <div className="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
                        <div
                          className="h-full rounded-full bg-indigo-500"
                          style={{ width: `${(p.count / maxQuoted) * 100}%` }}
                        />
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardBody>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader title="Productos más solicitados" />
          <CardBody>
            {(analytics?.topRequestedProducts ?? []).length === 0 ? (
              <p className="text-sm text-slate-500">Sin solicitudes en el periodo.</p>
            ) : (
              <div className="space-y-3">
                {analytics!.topRequestedProducts.map((p, i) => (
                  <div key={`${p.partNumber ?? ''}-${p.name}`} className="flex items-center gap-4">
                    <span className="w-6 text-sm font-medium text-slate-400">{i + 1}</span>
                    <div className="flex-1">
                      <div className="flex justify-between text-sm">
                        <span>{p.name}</span>
                        <span className="font-medium">{p.count}</span>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardBody>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader title="Mayoristas más utilizados" />
          <CardBody>
            {(analytics?.topWholesalers ?? []).length === 0 ? (
              <p className="text-sm text-slate-500">
                Sin consultas a mayoristas en el periodo.
              </p>
            ) : (
              <table className="w-full text-sm">
                <thead className="text-slate-500">
                  <tr>
                    <th className="pb-2 text-left font-medium">Mayorista</th>
                    <th className="pb-2 text-right font-medium">Consultas</th>
                    <th className="pb-2 text-right font-medium">% del total</th>
                  </tr>
                </thead>
                <tbody>
                  {analytics!.topWholesalers.map((row) => (
                    <tr key={row.name} className="border-t border-slate-100">
                      <td className="py-2">{row.name}</td>
                      <td className="py-2 text-right">{row.count}</td>
                      <td className="py-2 text-right">{row.percent}%</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </CardBody>
        </Card>
      </div>
    </div>
  )
}
