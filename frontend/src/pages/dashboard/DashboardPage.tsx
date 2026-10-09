import { QuoteStatusBadge } from '@/components/ui/QuoteStatusBadge'
import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Plus, Bell, Clock3, ArrowRight,
} from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { TableSkeleton } from '@/components/ui/LoadingState'
import { DashboardWorkspace } from '@/pages/dashboard/DashboardWorkspace'
import { DashboardPendingQuotes, DashboardRecentQuotes } from '@/pages/dashboard/DashboardQuoteSections'
import { useAuth } from '@/hooks/useAuth'
import { usePermission } from '@/hooks/usePermission'
import { fetchDashboard } from '@/lib/dashboard-api'
import { useNotificationFocus } from '@/lib/notification-focus'
import {
  type QuoteStatus,
  type DashboardAlerts,
  type DashboardAnalytics,
} from '@/types'

const EMPTY_ALERTS: DashboardAlerts = {
  lowStock: [],
  pendingQuotes: [],
  purchaseRequestQuotes: [],
  unansweredQuotes: [],
  readyForSalesQuotes: [],
  integrationIssues: [],
  expiringQuotes: [],
  stuckProcessingRequests: [],
  pendingReviewRequests: [],
  unsentRequests: [],
}

type NotificationKind = 'purchase-request' | 'unanswered' | 'stuck' | 'integration'

type NotificationRow = {
  key: string
  kind: NotificationKind
  quoteStatus?: QuoteStatus
  typeLabel: string
  category: 'Notificación' | 'Recordatorio'
  actionLabel: string
  href?: string
  reference: string
  clientName: string
  createdByName?: string | null
  detail: string
  elementId?: string
  severity: 'green' | 'yellow' | 'orange' | 'red'
}

const ALERT_COLORS = {
  green: { row: 'bg-emerald-50/50', border: 'border-emerald-500', badge: 'bg-emerald-100 text-emerald-900', detail: 'text-emerald-800' },
  yellow: { row: 'bg-yellow-50/60', border: 'border-yellow-400', badge: 'bg-yellow-100 text-yellow-900', detail: 'text-yellow-900' },
  orange: { row: 'bg-orange-50/60', border: 'border-orange-500', badge: 'bg-orange-100 text-orange-900', detail: 'text-orange-800' },
  red: { row: 'bg-red-50/70', border: 'border-red-500', badge: 'bg-red-100 text-red-900', detail: 'font-semibold text-red-700' },
} as const

export function DashboardPage() {
  const { user } = useAuth()
  const { canViewWholesalerIntegrationAlerts, canCreateQuotes, isAdmin } = usePermission()
  const canViewIntegrationAlerts = canViewWholesalerIntegrationAlerts()
  const canViewStuckReadings = isAdmin
  const [analytics, setAnalytics] = useState<DashboardAnalytics | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useNotificationFocus(!loading)

  useEffect(() => {
    const refreshDashboard = () => {
      fetchDashboard()
        .then((next) => { setAnalytics(next); setError(null) })
        .catch((err) =>
          setError(
            err instanceof Error
              ? err.message
              : 'No se pudieron cargar las métricas del dashboard.',
          ),
        )
        .finally(() => setLoading(false))
    }
    refreshDashboard()
    const timer = window.setInterval(refreshDashboard, 15_000)
    window.addEventListener('cotizacion:data-changed', refreshDashboard)
    window.addEventListener('focus', refreshDashboard)
    return () => {
      window.clearInterval(timer)
      window.removeEventListener('cotizacion:data-changed', refreshDashboard)
      window.removeEventListener('focus', refreshDashboard)
    }
  }, [])

  const alerts = analytics?.alerts ?? EMPTY_ALERTS

  const notificationRows = useMemo(
    () => buildNotificationRows(alerts, canViewIntegrationAlerts, canViewStuckReadings),
    [alerts, canViewIntegrationAlerts, canViewStuckReadings],
  )

  const reminderQuoteIds = useMemo(() => new Set(alerts.unansweredQuotes.map((quote) => quote.id)), [alerts.unansweredQuotes])

  return (
    <div>
      <header className="mb-5 flex flex-wrap items-center justify-between gap-4">
        <div>
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="text-2xl font-semibold tracking-tight text-slate-900">Dashboard</h1>
            {import.meta.env.VITE_DASHBOARD_PREVIEW === 'true' && <Badge variant="brand">Pruebas</Badge>}
          </div>
          <p className="mt-1 text-sm text-slate-600">Cotizaciones y seguimiento · {user?.role === 'administrador' ? 'Administración' : user?.role === 'gerente_compras' ? 'Compras' : 'Ventas'}</p>
        </div>
        {canCreateQuotes() && <Link to="/cotizaciones/nueva" className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 text-sm font-medium text-white transition hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600"><Plus className="h-4 w-4" aria-hidden />Nueva cotización</Link>}
      </header>

      {error && (
        <p className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          {error}
        </p>
      )}

      <div className="space-y-5">
        <DashboardPendingQuotes reminderQuoteIds={reminderQuoteIds} />
          <Card id="alertas" className="relative min-w-0 scroll-mt-28">
            <CardHeader
              title="NOTIFICACIONES Y RECORDATORIOS"
              subtitle="Solicitudes de cotización y seguimiento."
              action={
                notificationRows.length > 0 ? (
                  <Badge variant={notificationRows.some((row) => row.severity === 'red') ? 'danger' : 'warning'}>
                    {notificationRows.length}
                  </Badge>
                ) : undefined
              }
            />
            <CardBody className="p-0!">
              {loading ? <TableSkeleton rows={3} cols={6} /> : <DashboardNotificationsTable rows={notificationRows} isSales={user?.role === 'ventas'} />}
            </CardBody>
          </Card>

          <DashboardWorkspace reminderQuoteIds={reminderQuoteIds} />
          <DashboardRecentQuotes reminderQuoteIds={reminderQuoteIds} />


      </div>
    </div>
  )
}

function buildNotificationRows(
  alerts: DashboardAlerts,
  canViewIntegrationAlerts: boolean,
  canViewStuckReadings: boolean,
): NotificationRow[] {
  const rows: NotificationRow[] = []

  const purchaseQuotes = [...(alerts.purchaseRequestQuotes ?? [])].sort((a, b) => {
    const priority = (quote: typeof a) => quote.purchaseAttentionStatus === 'en_atencion' ? 2 : quote.purchaseEscalatedAt ? 0 : 1
    return priority(a) - priority(b)
      || (Date.parse(a.requestCreatedAt ?? '') || 0) - (Date.parse(b.requestCreatedAt ?? '') || 0)
      || a.folio.localeCompare(b.folio)
  })
  for (const quote of purchaseQuotes) {
    rows.push({
      key: `purchase-request-${quote.id}`,
      kind: 'purchase-request',
      quoteStatus: quote.status,
      category: 'Notificación',
      actionLabel: 'Abrir cotización',
      severity: quote.purchaseAttentionStatus === 'en_atencion' ? 'green' : quote.purchaseEscalatedAt ? 'red' : 'yellow',
      typeLabel: quote.purchaseAttentionStatus === 'en_atencion' ? 'En atención' : quote.purchaseEscalatedAt ? 'Urgente · sin responsable' : 'Pendiente de Compras',
      href: `/cotizaciones/${quote.id}`,
      reference: quote.folio,
      clientName: quote.clientName || '—',
      createdByName: quote.createdByName,
      detail: quote.purchaseAttentionStatus === 'en_atencion'
        ? `Atiende ${quote.purchaseAssigneeName ?? 'un comprador'}`
        : quote.purchaseEscalatedAt
          ? 'Urgente: lleva demasiado tiempo sin responsable'
          : 'Pendiente de revisión por Compras',
      elementId: `dashboard-quote-${quote.id}`,
    })
  }

  const idleQuotes = [...alerts.unansweredQuotes].sort((a, b) =>
    (b.daysWaiting ?? 0) - (a.daysWaiting ?? 0)
      || (Date.parse(a.lastActivityAt ?? '') || 0) - (Date.parse(b.lastActivityAt ?? '') || 0)
      || a.folio.localeCompare(b.folio),
  )
  for (const quote of idleQuotes) {
    rows.push({
      key: `unanswered-${quote.id}`,
      kind: 'unanswered',
      quoteStatus: quote.status,
      category: 'Recordatorio',
      actionLabel: 'Abrir recordatorio',
      severity: (quote.daysWaiting ?? 0) >= 7 ? 'red' : 'orange',
      typeLabel: (quote.daysWaiting ?? 0) >= 7 ? 'Urgente · sin avance' : 'Sin avance',
      href: `/recordatorios?quote=${encodeURIComponent(quote.id)}`,
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
        category: 'Notificación',
        actionLabel: 'Abrir solicitud',
        severity: 'orange',
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
        category: 'Notificación',
        actionLabel: 'Ver mayoristas',
        severity: 'red',
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

function DashboardNotificationsTable({ rows, isSales }: { rows: NotificationRow[]; isSales: boolean }) {
  const [filter, setFilter] = useState<'all' | NotificationRow['severity']>('all')
  const matchesFilter = (row: NotificationRow, value: typeof filter): boolean => {
    if (value === 'all') return true
    if (value === 'orange') return row.kind === 'unanswered' && row.severity === 'orange'
    if (value === 'red') return (row.kind === 'purchase-request' || row.kind === 'unanswered') && row.severity === 'red'
    return row.kind === 'purchase-request' && row.severity === value
  }
  const visibleRows = rows.filter((row) => matchesFilter(row, filter))
  const groups: { kind: NotificationKind; label: string; id?: string }[] = [
    { kind: 'purchase-request', label: 'Notificaciones · solicitudes de Compras' },
    { kind: 'unanswered', label: 'Recordatorios · cotizaciones sin avance, de más antiguas a más recientes', id: 'cotizaciones-sin-avance' },
    { kind: 'stuck', label: 'Notificaciones · lecturas de archivos atascadas', id: 'lecturas-atascadas' },
    { kind: 'integration', label: 'Notificaciones · problemas de integración', id: 'errores-integracion' },
  ]
  const filters = isSales
    ? ([['all', 'Todos'], ['orange', '3–6 días'], ['red', 'Urgente / 7+ días']] as const)
    : ([
        ['all', 'Todos'], ['green', 'En atención'], ['yellow', 'Pendiente de Compras'],
        ['orange', '3–6 días'], ['red', 'Urgente / 7+ días'],
      ] as const)
  return <>
    <div role="group" aria-label="Filtrar notificaciones y recordatorios" className="flex flex-wrap gap-2 border-b border-slate-200 px-5 py-3">
      {filters.map(([value, label]) => <button key={value} type="button" aria-pressed={filter === value} onClick={() => setFilter(value)} className={`inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 ${value === 'all' ? 'bg-slate-100 text-slate-800' : ALERT_COLORS[value].badge} ${filter === value ? 'border-slate-700 ring-2 ring-slate-500/25' : 'border-transparent hover:border-slate-400'}`}>
        <span>{label}</span><span className="rounded bg-white/70 px-1.5 py-0.5 text-xs tabular-nums">{rows.filter((row) => matchesFilter(row, value)).length}</span>
      </button>)}
    </div>
    <p role="status" className="border-b border-slate-100 px-5 py-2 text-xs text-slate-600">Mostrando {visibleRows.length} de {rows.length} avisos</p>
    {visibleRows.length === 0 ? <p className="px-5 py-8 text-center text-sm text-slate-600">{rows.length === 0 ? 'No hay notificaciones ni recordatorios.' : 'No hay avisos para este filtro.'}</p> :
    <div className="overflow-x-auto">
      <table className="w-full min-w-[800px] text-left text-sm" aria-label="Notificaciones y recordatorios">
        <thead className="border-b border-slate-200 bg-slate-50 text-slate-600"><tr>
          {['Tipo de aviso / Prioridad', 'Folio', 'Estatus de cotización', 'Cliente', 'Hecha por', 'Detalle'].map((label) => <th key={label} className="px-4 py-3 font-medium">{label}</th>)}
        </tr></thead>
        {groups.map((group) => {
          const groupRows = visibleRows.filter((row) => row.kind === group.kind)
          if (groupRows.length === 0) return null
          return <tbody key={group.kind}>
            <tr id={group.id} className="scroll-mt-28 border-y border-slate-200 bg-slate-100"><th colSpan={6} scope="colgroup" className="px-4 py-3 text-sm font-semibold text-slate-800">{group.label}<span className="ml-2 font-normal text-slate-600">({groupRows.length})</span></th></tr>
            {groupRows.map((row) => {
              const colors = ALERT_COLORS[row.severity]
              const CategoryIcon = row.category === 'Recordatorio' ? Clock3 : Bell
              return <tr id={row.elementId} key={row.key} className={`relative border-b border-slate-100 ${colors.row}`}>
                <td className={`border-l-4 px-4 py-3 ${colors.border}`}><span className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-slate-900"><CategoryIcon className="h-4 w-4 shrink-0" aria-hidden="true" />{row.category}</span><span className={`inline-flex rounded-md px-2.5 py-1 text-xs font-semibold ${colors.badge}`}>{row.typeLabel}</span></td>
                <td className="whitespace-nowrap px-4 py-3">{row.href ? <Link to={row.href} className="font-medium text-indigo-700 hover:underline focus-visible:outline-2 focus-visible:outline-indigo-600" title={row.reference}>{row.reference}</Link> : <span className="font-medium text-slate-800">{row.reference}</span>}                <div className="mt-2">{row.href && <Link to={row.href} aria-label={`${row.actionLabel}: ${row.reference}`} className="inline-flex min-h-11 items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:border-indigo-400 hover:text-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">{row.actionLabel}<ArrowRight className="h-4 w-4" aria-hidden="true" /></Link>}</div></td>
                <td className="px-4 py-3">{row.quoteStatus ? <QuoteStatusBadge status={row.quoteStatus} /> : <span className="text-slate-500">No aplica</span>}</td>
                <td className="px-4 py-3 text-slate-700" title={row.clientName}>{row.clientName}</td>
                <td className="px-4 py-3 text-slate-700" title={row.createdByName?.trim() || undefined}>{row.createdByName?.trim() || (row.kind === 'integration' ? '—' : 'Sin asignar')}</td>
                <td className={`min-w-52 px-4 py-3 ${colors.detail}`} title={row.detail}>{row.detail}</td>

              </tr>
            })}
          </tbody>
        })}
      </table>
    </div>}
  </>
}
