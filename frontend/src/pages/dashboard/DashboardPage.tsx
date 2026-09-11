import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import {
  AlertTriangle,
  FileCheck2,
  FileText,
  FileX2,
} from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { LoadingState, StatGridSkeleton } from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { QuoteSentFilterTabs, type QuoteSentFilter } from '@/components/ui/QuoteSentFilterTabs'
import { QuoteStatusBadge } from '@/components/ui/QuoteStatusBadge'
import { StatCard } from '@/components/ui/StatCard'
import { usePermission } from '@/hooks/usePermission'
import { fetchDashboard } from '@/lib/dashboard-api'
import { useNotificationFocus } from '@/lib/notification-focus'
import { formatCurrency, formatDateTime } from '@/lib/format'
import {
  type DashboardAlerts,
  type DashboardAnalytics,
  type DashboardQuoteSummary,
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

type NotificationKind = 'unanswered' | 'stuck' | 'integration'

type NotificationRow = {
  key: string
  kind: NotificationKind
  typeLabel: string
  href?: string
  reference: string
  clientName: string
  createdByName?: string | null
  detail: string
  elementId?: string
}

const KIND_BADGE: Record<NotificationKind, 'warning' | 'danger'> = {
  unanswered: 'warning',
  stuck: 'warning',
  integration: 'danger',
}

function SectionTitle({ children }: { children: ReactNode }) {
  return (
    <h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-slate-500">
      {children}
    </h2>
  )
}

export function DashboardPage() {
  const { canViewDashboardExecutive, canViewWholesalerIntegrationAlerts, isAdmin } = usePermission()
  const canViewExecutive = canViewDashboardExecutive()
  const canViewIntegrationAlerts = canViewWholesalerIntegrationAlerts()
  const canViewStuckReadings = isAdmin
  const [analytics, setAnalytics] = useState<DashboardAnalytics | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [sentFilter, setSentFilter] = useState<QuoteSentFilter>('unsent')

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
  const sentQuotes = analytics?.sentQuotes ?? []
  const unsentQuotes = analytics?.unsentQuotes ?? []
  const filteredQuotes = sentFilter === 'sent' ? sentQuotes : unsentQuotes

  const notificationRows = useMemo(
    () => buildNotificationRows(alerts, canViewIntegrationAlerts, canViewStuckReadings),
    [alerts, canViewIntegrationAlerts, canViewStuckReadings],
  )

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

      <SectionTitle>Resumen</SectionTitle>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <StatCard
          label="Cotizaciones no enviadas"
          value={String(quotesUnsent)}
          hint="Sin evidencia de envío al cliente"
          icon={FileX2}
        />
        <StatCard
          label="Cotizaciones enviadas"
          value={String(quotesSent)}
          hint="Enviadas al cliente"
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

      <div className="mt-8 space-y-6">
          <Card id="alertas" className="relative min-w-0 scroll-mt-28">
            <span id="cotizaciones-sin-avance" className="absolute top-0 left-0 h-px w-px overflow-hidden" />
            {canViewStuckReadings && (
              <span id="lecturas-atascadas" className="absolute top-0 left-0 h-px w-px overflow-hidden" />
            )}
            <span id="errores-integracion" className="absolute top-0 left-0 h-px w-px overflow-hidden" />
            <CardHeader
              title="Notificaciones y recordatorios"
              subtitle={notificationsSubtitle(
                analytics?.unansweredQuoteDays,
                canViewIntegrationAlerts,
                canViewStuckReadings,
              )}
              action={
                notificationRows.length > 0 ? (
                  <Badge variant={notificationRows.some((row) => row.kind === 'integration') ? 'danger' : 'warning'}>
                    {notificationRows.length}
                  </Badge>
                ) : undefined
              }
            />
            <CardBody className="p-0">
              <DashboardNotificationsTable rows={notificationRows} />
            </CardBody>
          </Card>

          <Card className="min-w-0">
            <CardHeader
              title="Cotizaciones recientes"
              subtitle="Últimas cotizaciones de tu alcance"
              action={
                <Link to="/cotizaciones" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">
                  Ver todas
                </Link>
              }
            />
            <CardBody className="p-0">
              <DashboardQuotesTable
                quotes={recentQuotes}
                emptyLabel="No hay cotizaciones recientes."
              />
            </CardBody>
          </Card>

        <Card>
          <CardHeader
            title="Operativa"
            subtitle="Cotizaciones enviadas y no enviadas al cliente"
            action={
              <div className="flex flex-wrap items-center gap-3">
                <QuoteSentFilterTabs
                  value={sentFilter}
                  onChange={setSentFilter}
                  unsentCount={quotesUnsent}
                  sentCount={quotesSent}
                />
                <Link to="/cotizaciones" className="text-sm font-medium text-indigo-600 hover:text-indigo-700">
                  Ver todas
                </Link>
              </div>
            }
          />
          <CardBody className="p-0">
            <DashboardQuotesTable
              quotes={filteredQuotes}
              emptyLabel={
                sentFilter === 'sent'
                  ? 'No hay cotizaciones enviadas.'
                  : 'No hay cotizaciones pendientes de envío.'
              }
              showSentAt={sentFilter === 'sent'}
            />
          </CardBody>
        </Card>
      </div>
    </div>
  )
}

function notificationsSubtitle(
  unansweredQuoteDays: number | undefined,
  canViewIntegrationAlerts: boolean,
  canViewStuckReadings: boolean,
): string {
  const topics = ['Sin avance']
  if (canViewStuckReadings) topics.push('lecturas atascadas')
  if (canViewIntegrationAlerts) topics.push('integración')

  const joined =
    topics.length === 1
      ? topics[0]
      : `${topics.slice(0, -1).join(', ')} y ${topics[topics.length - 1]}`

  if (unansweredQuoteDays) {
    return `${joined}. Cotizaciones detenidas a partir de ${unansweredQuoteDays} día${unansweredQuoteDays === 1 ? '' : 's'}.`
  }

  return joined
}

function buildNotificationRows(
  alerts: DashboardAlerts,
  canViewIntegrationAlerts: boolean,
  canViewStuckReadings: boolean,
): NotificationRow[] {
  const rows: NotificationRow[] = []

  for (const quote of alerts.unansweredQuotes) {
    rows.push({
      key: `unanswered-${quote.id}`,
      kind: 'unanswered',
      typeLabel: 'Sin avance',
      href: `/cotizaciones/${quote.id}`,
      reference: quote.folio,
      clientName: quote.clientName || '—',
      createdByName: quote.createdByName,
      detail: `${quote.daysWaiting ?? 0} día${(quote.daysWaiting ?? 0) === 1 ? '' : 's'} sin actividad`,
      elementId: `dashboard-quote-${quote.id}`,
    })
  }

  if (canViewStuckReadings) {
    for (const req of alerts.stuckProcessingRequests ?? []) {
      rows.push({
        key: `stuck-${req.id}`,
        kind: 'stuck',
        typeLabel: 'Lectura atascada',
        href: `/solicitudes/${req.id}`,
        reference: req.fileName || 'Solicitud',
        clientName: req.clientName || 'Sin cliente',
        detail: `${req.minutesStuck} min sin avance`,
        elementId: `dashboard-request-${req.id}`,
      })
    }
  }

  if (canViewIntegrationAlerts) {
    alerts.integrationIssues.forEach((issue, index) => {
      rows.push({
        key: `integration-${issue.type}-${issue.label}-${index}`,
        kind: 'integration',
        typeLabel: 'Integración',
        href: '/mayoristas',
        reference: issue.label,
        clientName: '—',
        detail: issue.detail || 'Problema de integración',
      })
    })
  }

  return rows
}

function DashboardQuotesTable({
  quotes,
  emptyLabel,
  showSentAt = false,
}: {
  quotes: DashboardQuoteSummary[]
  emptyLabel: string
  showSentAt?: boolean
}) {
  if (quotes.length === 0) {
    return <p className="px-5 py-8 text-center text-sm text-slate-500">{emptyLabel}</p>
  }

  const cell = 'px-4 py-2.5 align-middle'

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-left text-sm">
        <thead className="border-b border-slate-100 bg-slate-50 text-slate-500">
          <tr>
            <th className={`${cell} font-medium`}>Folio</th>
            <th className={`${cell} font-medium`}>Cliente</th>
            <th className={`${cell} font-medium`}>Hecha por</th>
            <th className={`${cell} font-medium`}>Estado</th>
            <th className={`${cell} font-medium`}>Envío</th>
            {showSentAt && <th className={`${cell} font-medium`}>Enviada</th>}
            <th className={`${cell} text-right font-medium`}>Total</th>
          </tr>
        </thead>
        <tbody>
          {quotes.map((q) => {
            const sent = Boolean(q.sentAt)
            return (
              <tr
                key={q.id}
                className={`border-b border-slate-50 last:border-0 hover:bg-slate-50/80 ${
                  sent ? '' : 'bg-slate-50/60'
                }`}
              >
                <td className={`${cell} whitespace-nowrap font-medium`}>
                  <Link
                    to={`/cotizaciones/${q.id}`}
                    className="text-indigo-600 hover:underline"
                    title={q.folio}
                  >
                    {q.folio}
                  </Link>
                </td>
                <td className={`${cell} text-slate-600`} title={q.clientName || undefined}>
                  {q.clientName || '—'}
                </td>
                <td
                  className={`${cell} font-medium text-slate-800`}
                  title={q.createdByName?.trim() || undefined}
                >
                  {q.createdByName?.trim() || 'Sin asignar'}
                </td>
                <td className={`${cell} whitespace-nowrap`}>
                  <QuoteStatusBadge status={q.status} />
                </td>
                <td className={`${cell} whitespace-nowrap`}>
                  <Badge variant={sent ? 'success' : 'muted'}>
                    {sent ? 'Enviada al cliente' : 'No enviada'}
                  </Badge>
                </td>
                {showSentAt && (
                  <td className={`${cell} whitespace-nowrap text-slate-600`}>
                    {q.sentAt ? formatDateTime(q.sentAt) : '—'}
                  </td>
                )}
                <td className={`${cell} whitespace-nowrap text-right font-medium`}>
                  {formatCurrency(q.total)}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

function DashboardNotificationsTable({ rows }: { rows: NotificationRow[] }) {
  if (rows.length === 0) {
    return (
      <p className="px-5 py-8 text-center text-sm text-slate-500">
        No hay notificaciones ni recordatorios.
      </p>
    )
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full table-fixed text-left text-sm">
        <colgroup>
          <col className="w-[22%]" />
          <col className="w-[18%]" />
          <col className="w-[20%]" />
          <col className="w-[18%]" />
          <col className="w-[22%]" />
        </colgroup>
        <thead className="border-b border-slate-100 bg-slate-50 text-slate-500">
          <tr>
            <th className="px-3 py-2.5 font-medium">Tipo</th>
            <th className="px-3 py-2.5 font-medium">Referencia</th>
            <th className="px-3 py-2.5 font-medium">Cliente</th>
            <th className="px-3 py-2.5 font-medium">Hecha por</th>
            <th className="px-3 py-2.5 font-medium">Detalle</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr
              id={row.elementId}
              key={row.key}
              className={`relative border-b border-slate-50 last:border-0 hover:bg-slate-50/80 ${
                row.kind === 'integration' ? 'bg-red-50/30' : 'bg-amber-50/20'
              }`}
            >
              <td className="px-3 py-2.5">
                <Badge variant={KIND_BADGE[row.kind]}>{row.typeLabel}</Badge>
              </td>
              <td className="truncate px-3 py-2.5">
                {row.href ? (
                  <Link to={row.href} className="font-medium text-indigo-600 hover:underline" title={row.reference}>
                    {row.reference}
                  </Link>
                ) : (
                  <span className="font-medium text-slate-800">{row.reference}</span>
                )}
              </td>
              <td className="truncate px-3 py-2.5 text-slate-600" title={row.clientName}>
                {row.clientName}
              </td>
              <td
                className="truncate px-3 py-2.5 font-medium text-slate-800"
                title={row.createdByName?.trim() || undefined}
              >
                {row.createdByName?.trim() || (row.kind === 'integration' ? '—' : 'Sin asignar')}
              </td>
              <td
                className={`truncate px-3 py-2.5 ${
                  row.kind === 'integration' ? 'text-red-700' : 'text-amber-800'
                }`}
                title={row.detail}
              >
                {row.detail}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
