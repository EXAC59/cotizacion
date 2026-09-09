import { useEffect, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import {
  AlertTriangle,
  FileCheck2,
  FileText,
  FileX2,
  Plug,
} from 'lucide-react'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { LoadingState, StatGridSkeleton } from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { QuoteStatusBadge } from '@/components/ui/QuoteStatusBadge'
import { StatCard } from '@/components/ui/StatCard'
import { usePermission } from '@/hooks/usePermission'
import { fetchDashboard } from '@/lib/dashboard-api'
import {
  useNotificationFocus,
} from '@/lib/notification-focus'
import { formatCurrency } from '@/lib/format'
import {
  type DashboardAlerts,
  type DashboardAnalytics,
} from '@/types'

const EMPTY_ALERTS: DashboardAlerts = {
  lowStock: [],
  pendingQuotes: [],
  unansweredQuotes: [],
  readyForSalesQuotes: [],
  integrationIssues: [],
  expiringQuotes: [],
  stuckProcessingRequests: [],
  pendingReviewRequests: [],
  unsentRequests: [],
}

function SectionTitle({ children }: { children: ReactNode }) {
  return (
    <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
      {children}
    </h2>
  )
}

export function DashboardPage() {
  const { canViewDashboardExecutive, canViewWholesalerIntegrationAlerts } = usePermission()
  const canViewExecutive = canViewDashboardExecutive()
  const canViewIntegrationAlerts = canViewWholesalerIntegrationAlerts()
  const [analytics, setAnalytics] = useState<DashboardAnalytics | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useNotificationFocus(!loading)

  useEffect(() => {
    fetchDashboard()
      .then(setAnalytics)
      .catch((err) =>
        setError(
          err instanceof Error
            ? err.message
            : 'No se pudieron cargar las métricas del dashboard.',
        ),
      )
      .finally(() => setLoading(false))
  }, [])

  const recentQuotes = analytics?.recentQuotes ?? []
  const alerts = analytics?.alerts ?? EMPTY_ALERTS

  const integrationAlertsCount = alerts.integrationIssues.length
  const quotesSent = analytics?.quotesSent ?? 0
  const quotesUnsent = analytics?.quotesUnsent ?? 0

  return (
    <div>
      <PageHeader
        title="Dashboard"
        description={
          canViewExecutive
            ? 'Resumen ejecutivo de cotizaciones, utilidad y solicitudes'
            : 'Resumen operativo de cotizaciones y solicitudes'
        }
      />

      {error && (
        <p className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          {error}
        </p>
      )}

      {loading && (
        <div className="mb-6 space-y-4">
          <LoadingState label="Cargando métricas…" variant="inline" className="py-6" />
          <StatGridSkeleton count={3} />
        </div>
      )}

      <SectionTitle>Cotizaciones</SectionTitle>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <StatCard
          label="Cotizaciones no enviadas"
          value={String(quotesUnsent)}
          hint="Sin evidencia de envío al cliente (sin sent_at)"
          icon={FileX2}
        />
        <StatCard
          label="Cotizaciones enviadas"
          value={String(quotesSent)}
          hint="Enviadas al cliente (con sent_at)"
          icon={FileCheck2}
        />
        {canViewIntegrationAlerts && (
          <StatCard
            label="Alertas de integración"
            value={String(integrationAlertsCount)}
            hint="Mayoristas / comparador"
            icon={AlertTriangle}
          />
        )}
        {!canViewIntegrationAlerts && (
          <StatCard
            label="Cotizaciones recientes"
            value={String(recentQuotes.length)}
            hint="Últimas en tu alcance"
            icon={FileText}
          />
        )}
      </div>

      <div className="my-6">
        <Card>
          <CardHeader
            title="Cotizaciones recientes"
            subtitle="Folio, cliente, quién la hizo y en qué estado está"
            action={
              <Link to="/cotizaciones" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">
                Ver todas
              </Link>
            }
          />
          <CardBody className="p-0">
            {recentQuotes.length === 0 ? (
              <p className="px-5 py-8 text-center text-sm text-slate-500">
                No hay cotizaciones recientes.
              </p>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="border-b border-slate-100 bg-slate-50 text-slate-500">
                    <tr>
                      <th className="px-5 py-3 font-medium">Folio</th>
                      <th className="px-5 py-3 font-medium">Cliente</th>
                      <th className="px-5 py-3 font-medium">Hecha por</th>
                      <th className="px-5 py-3 font-medium">Estado</th>
                      <th className="px-5 py-3 font-medium text-right">Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    {recentQuotes.map((q) => (
                      <tr key={q.id} className="border-b border-slate-50 hover:bg-slate-50/80">
                        <td className="px-5 py-3">
                          <Link
                            to={`/cotizaciones/${q.id}`}
                            className="font-medium text-indigo-600 hover:underline"
                          >
                            {q.folio}
                          </Link>
                        </td>
                        <td className="px-5 py-3 text-slate-600">{q.clientName || '—'}</td>
                        <td className="px-5 py-3 font-medium text-slate-800">
                          {q.createdByName?.trim() || 'Sin asignar'}
                        </td>
                        <td className="px-5 py-3">
                          <QuoteStatusBadge status={q.status} />
                        </td>
                        <td className="px-5 py-3 text-right font-medium">
                          {formatCurrency(q.total)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardBody>
        </Card>
      </div>

      <div id="alertas" className="relative scroll-mt-28">
        <SectionTitle>Notificaciones y recordatorios</SectionTitle>
      </div>
      <div className="mb-6 grid gap-4 lg:grid-cols-2">
        <Card id="cotizaciones-sin-avance" className="relative scroll-mt-28">
          <CardHeader
            title="Cotizaciones sin avance"
            subtitle={
              analytics?.unansweredQuoteDays
                ? `En elaboración o Lista / Terminada sin actividad por más de ${analytics.unansweredQuoteDays} día${analytics.unansweredQuoteDays === 1 ? '' : 's'}`
                : 'En elaboración o Lista / Terminada sin actividad reciente'
            }
          />
          <CardBody className="space-y-2">
            {alerts.unansweredQuotes.length === 0 ? (
              <p className="text-sm text-slate-500">No hay cotizaciones detenidas sin avance.</p>
            ) : (
              alerts.unansweredQuotes.map((quote) => (
                <div
                  id={`dashboard-quote-${quote.id}`}
                  key={quote.id}
                  className="relative flex items-center justify-between gap-2 rounded-lg border border-slate-100 px-3 py-2 text-sm"
                >
                  <div>
                    <Link
                      to={`/cotizaciones/${quote.id}`}
                      className="font-medium text-indigo-600 hover:underline"
                    >
                      {quote.folio}
                    </Link>
                    <p className="text-slate-500">{quote.clientName}</p>
                    <p className="text-xs text-slate-600">
                      Hecha por: {quote.createdByName?.trim() || 'Sin asignar'}
                    </p>
                  </div>
                  <span className="shrink-0 text-amber-700">
                    {quote.daysWaiting} días sin actividad
                  </span>
                </div>
              ))
            )}
          </CardBody>
        </Card>

        {(alerts.stuckProcessingRequests?.length ?? 0) > 0 && (
          <Card id="lecturas-atascadas" className="relative scroll-mt-28">
            <CardHeader
              title="Lecturas atascadas"
              subtitle="Solicitudes en procesamiento sin avance reciente"
              action={
                <Link to="/solicitudes" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">
                  Ver solicitudes
                </Link>
              }
            />
            <CardBody className="space-y-2">
              {alerts.stuckProcessingRequests.map((req) => (
                <div
                  id={`dashboard-request-${req.id}`}
                  key={req.id}
                  className="relative flex items-center justify-between gap-2 rounded-lg border border-amber-100 bg-amber-50/50 px-3 py-2 text-sm"
                >
                  <div className="min-w-0">
                    <Link
                      to={`/solicitudes/${req.id}`}
                      className="font-medium text-indigo-600 hover:underline"
                    >
                      {req.fileName || 'Solicitud'}
                    </Link>
                    <p className="truncate text-slate-500">{req.clientName || 'Sin cliente'}</p>
                  </div>
                  <span className="shrink-0 text-amber-800">
                    {req.minutesStuck} min sin avance
                  </span>
                </div>
              ))}
            </CardBody>
          </Card>
        )}

        {canViewIntegrationAlerts && (
          <Card id="errores-integracion" className="relative scroll-mt-28">
            <CardHeader
              title="Errores de integración"
              action={
                <Link to="/mayoristas" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">
                  Configurar
                </Link>
              }
            />
            <CardBody className="space-y-2">
              {alerts.integrationIssues.length === 0 ? (
                <p className="text-sm text-slate-500">Sin problemas de integración detectados.</p>
              ) : (
                alerts.integrationIssues.map((issue, index) => (
                  <div
                    key={`${issue.type}-${issue.label}-${index}`}
                    className="flex gap-2 rounded-lg border border-red-100 bg-red-50/50 px-3 py-2 text-sm"
                  >
                    <Plug className="mt-0.5 h-4 w-4 shrink-0 text-red-500" />
                    <div>
                      <p className="font-medium text-slate-800">{issue.label}</p>
                      {issue.detail && <p className="text-slate-600">{issue.detail}</p>}
                    </div>
                  </div>
                ))
              )}
            </CardBody>
          </Card>
        )}
      </div>

    </div>
  )
}
