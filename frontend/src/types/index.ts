export type UserRole =
  | 'administrador'
  | 'gerente_compras'
  | 'ventas'

export type QuoteStatus =
  | 'solicitud_cotizaciones'
  | 'en_elaboracion'
  | 'pendiente_envio'
  | 'enviada'
  | 'modificacion'
  | 'aceptada'
  | 'facturada'

export type RequestStatus =
  | 'pendiente'
  | 'procesando'
  | 'procesada'
  | 'precios_listos'
  | 'error'

export const REQUEST_STATUS_LABELS: Record<RequestStatus, string> = {
  pendiente: 'Pendiente',
  procesando: 'Procesando',
  procesada: 'Procesada',
  precios_listos: 'Precios listos (ventas)',
  error: 'Error',
}

/** Estatus propio de la solicitud (independiente del pipeline n8n). */
export type RequestWorkflowStatus = 'en_elaboracion' | 'pendiente_envio' | 'enviada'

export const REQUEST_WORKFLOW_ORDER: RequestWorkflowStatus[] = [
  'en_elaboracion',
  'pendiente_envio',
  'enviada',
]

export const REQUEST_WORKFLOW_LABELS: Record<RequestWorkflowStatus, string> = {
  en_elaboracion: 'En elaboración',
  pendiente_envio: 'Lista / Terminada',
  enviada: 'Enviada',
}

export interface User {
  id: string
  name: string
  email: string
  username?: string
  folioCode?: string
  role: UserRole
  active?: boolean
}

export interface Client {
  id: string
  company: string
  rfc: string
  address: string
  contact: string
  email: string
  whatsapp: string
  paymentTerms: string
  createdAt: string
  /** Cotizaciones asociadas (listado). */
  quotesCount?: number
  stats?: ClientStats
}

export interface ClientStats {
  quotesCount: number
  quotesTotal: number
  lastQuoteAt?: string | null
}

export interface ClientQuoteSummary {
  id: string
  folio: string
  clientId: string
  status: QuoteStatus
  taxPercent: number
  total: number
  linesCount: number
  createdByName?: string | null
  createdAt?: string
  sentAt?: string | null
}

export interface ClientImportResult {
  created: number
  updated: number
  skipped: number
  errors: Array<{ row: number; message: string }>
}

export type InterpretacionVia = 'parser'

export interface QuoteRequest {
  id: string
  /** Folio autogenerado (ej. SOL-ERICKA-0001) */
  folio?: string
  clientId: string | null
  clientName?: string
  createdBy?: string | null
  createdByName?: string
  assignedToSales?: boolean
  assignedToCompras?: boolean
  needsExternalReview?: boolean
  reviewedBy?: string | null
  reviewedByName?: string
  reviewedAt?: string | null
  source: 'pdf' | 'excel' | 'word' | 'text'
  fileName?: string
  rawText?: string
  status: RequestStatus
  workflowStatus?: RequestWorkflowStatus
  createdAt: string
  updatedAt?: string
  lecturaAt?: string | null
  lines?: RequestLine[]
  /** Vista previa OCR antes de confirmar */
  previewLines?: RequestLine[]
  /** Motor que interpretó las líneas (siempre parser) */
  interpretacionVia?: InterpretacionVia
  errorMessage?: string
  /** Precios que sube compras para ventas */
  pricingLines?: RequestPricingLine[]
  pricingUploadedAt?: string
  /** Usuarios involucrados en la solicitud (1., 2., …) */
  involucrado?: string
}

export interface RequestLine {
  id: string
  quantity: number
  product: string
  partNumber: string
  brand: string
  description: string
  unit: string
  referenceCost?: number
  selectedWholesalerId?: string
  warehouse?: string
  offers?: WholesalerOffer[]
}

/** Línea con costo/stock cargado por compras */
export interface RequestPricingLine extends RequestLine {
  cost: number
  stock: number
  wholesalerName: string
  warehouse: string
}

export interface WholesalerOffer {
  wholesalerId: string
  wholesalerCode?: string
  wholesalerName: string
  /** SKU solicitado por el usuario; nunca se sustituye por una clave del mayorista. */
  partNumber?: string
  /** Referencia/SKU alterno utilizado por el catálogo o API del mayorista. */
  supplierPartNumber?: string | null
  description?: string
  cost: number
  unitCost?: number
  packSize?: number
  stock: number
  warehouse: string
  leadDays: number
  availabilityType?: 'local' | 'import'
  etaAt?: string | null
  performanceScore?: number
  score?: number
  rank?: number
  isBest?: boolean
  isSelected?: boolean
  reasons?: string[]
  error?: string | null
  /** true/false si el API indica cobro de flete; null/omitido = sin dato. */
  hasFreight?: boolean | null
  freightNote?: string | null
}

export interface ComparatorNotFoundWarehouse {
  code: string
  label: string
  reason?: 'not_found' | 'no_stock' | string
}

export interface ComparatorNotFound {
  wholesalerId: string
  wholesalerCode?: string
  wholesalerName: string
  reason?: 'not_found' | 'no_stock' | string
  message: string
  warehouses?: ComparatorNotFoundWarehouse[]
}

export interface ComparatorResult {
  partNumber: string
  quantity: number
  preferredWarehouse: string | null
  offers: WholesalerOffer[]
  best: WholesalerOffer | null
  demoMode: boolean
  /** Aviso informativo cuando no hay ofertas (producto no encontrado). No es fallo técnico. */
  errorMessage?: string | null
  notFoundSummary?: string | null
  notFound?: ComparatorNotFound[]
}

export interface QuoteLine {
  id: string
  quantity: number
  product: string
  partNumber: string
  cost: number
  marginPercent: number
  salePrice: number
  amount: number
  warehouse: string
  /** Si true, la partida sigue el margen global al cambiar el % global */
  usesGlobalMargin?: boolean
  offers?: WholesalerOffer[]
  selectedWholesalerId?: string
}

export interface QuoteEditLock {
  userId: string
  userName: string
  userEmail: string
  lockedAt?: string
  isOwn?: boolean
}

export interface Quote {
  id: string
  folio: string
  clientId: string
  clientName: string
  requestId?: string
  status: QuoteStatus
  validityDays?: number
  globalMarginPercent: number
  taxPercent: number
  notes: string
  customerObservations?: string
  internalNotes?: QuoteInternalNote[]
  createdAt: string
  sentAt?: string
  responseReceivedAt?: string
  invoiceNumber?: string
  editLock?: QuoteEditLock | null
  lines: QuoteLine[]
  /** Resumen desde API list (sin partidas cargadas) */
  linesCount?: number
  total?: number
  createdByName?: string
  ownedByViewer?: boolean
  assignedToSales?: boolean
  assignedToCompras?: boolean
  statusHistory?: QuoteStatusHistoryEntry[]
  followUp?: QuoteFollowUp | null
  followUpHistory?: QuoteFollowUpHistoryEntry[]
  eligibility?: QuoteNotifyEligibility
  /** Usuarios involucrados en la cotización (1., 2., …) */
  involucrado?: string
}

export interface QuoteInternalNote {
  id: string
  body: string
  userName?: string | null
  createdAt: string
}

export interface QuoteStatusHistoryEntry {
  fromStatus: QuoteStatus | null
  toStatus: QuoteStatus
  userName?: string | null
  createdAt: string
}

export type FollowUpStatus = 'negociacion' | 'ganada' | 'perdida'

export interface QuoteFollowUp {
  status: FollowUpStatus
  invoice?: string | null
  comments?: string | null
  remindAt?: string | null
  at?: string | null
  byUser?: string | null
  byUserId?: number | null
}

export interface QuoteFollowUpHistoryEntry {
  id: string
  userName: string
  fromStatus: FollowUpStatus | null
  toStatus: FollowUpStatus
  remindAt?: string | null
  invoice?: string | null
  comments?: string | null
  createdAt: string
}

export interface QuoteNotifyEligibility {
  eligible: boolean
  reasonCode: string | null
  blockReason: string | null
  daysIdle: number
  pendingUnread?: boolean
  /** Usuario ventas que recibirá el aviso (último toque o creador) */
  notifyRecipientId?: number | null
  notifyRecipientName?: string | null
}

export interface SalesNotificationItem {
  id: string
  quoteId: string
  folio?: string | null
  clientName: string
  audience: 'ventas' | 'compras'
  reasonCode: string
  reasonLabel: string
  message: string
  senderName?: string | null
  recipientId?: number | null
  recipientName?: string | null
  createdAt?: string | null
  readAt?: string | null
  read: boolean
  kind?: 'inbox' | 'pipeline'
}

export const FOLLOW_UP_STATUS_LABELS: Record<FollowUpStatus, string> = {
  negociacion: 'Negociación',
  ganada: 'Ganada',
  perdida: 'Perdida',
}

export interface Wholesaler {
  id: string
  name: string
  code: string
  integration: 'api' | 'xml' | 'csv' | 'ftp' | 'scraping'
  active: boolean
  configured?: boolean
}

export interface InventoryCatalogItem {
  partNumber: string
  product: string
  stock: number
  warehouse: string
}

export interface LowStockAlert {
  partNumber: string
  product: string
  stock: number
  warehouse: string
  minimum: number
  wholesalerName?: string | null
  wholesalerCode?: string | null
}

export interface SalespersonProfit {
  userId: number | null
  name: string
  profit: number
  quoteCount: number
}

export interface DashboardAlertQuote {
  id: string
  folio: string
  clientName: string
  /** Nombre de quien creó la cotización */
  createdByName?: string | null
  daysWaiting?: number
  expiresAt?: string
  expired?: boolean
  daysRemaining?: number
  updatedAt?: string
  lastActivityAt?: string
}

export interface DashboardIntegrationIssue {
  type: 'wholesaler' | 'comparison'
  label: string
  detail?: string
}

export interface DashboardStuckRequest {
  id: string
  fileName?: string | null
  clientName: string
  minutesStuck: number
  updatedAt: string
}

export interface DashboardPendingReviewRequest {
  id: string
  fileName?: string | null
  clientName: string
  createdAt: string
}

export interface DashboardUnsentRequest extends DashboardPendingReviewRequest {
  createdByName?: string | null
  workflowStatus: Extract<RequestWorkflowStatus, 'en_elaboracion' | 'pendiente_envio'>
}

export interface DashboardAlerts {
  lowStock: LowStockAlert[]
  pendingQuotes: DashboardAlertQuote[]
  unansweredQuotes: DashboardAlertQuote[]
  readyForSalesQuotes: DashboardAlertQuote[]
  integrationIssues: DashboardIntegrationIssue[]
  expiringQuotes: DashboardAlertQuote[]
  stuckProcessingRequests: DashboardStuckRequest[]
  pendingReviewRequests: DashboardPendingReviewRequest[]
  unsentRequests: DashboardUnsentRequest[]
}

export interface AppSettings {
  minStockAlert: number
  defaultMarginPercent?: number
  taxPercent?: number
  quoteValidityDays?: number
  currencyCode?: string
}

export interface DashboardStats {
  recentQuotes: Quote[]
  pendingRequests: number
  topProducts: { name: string; count: number }[]
  quotesByStatus: Record<QuoteStatus, number>
  lowStockItems: LowStockAlert[]
}

export interface ProductRank {
  name: string
  partNumber?: string
  count: number
}

export interface DashboardQuoteSummary {
  id: string
  folio: string
  clientName: string
  /** Nombre de quien creó la cotización */
  createdByName?: string | null
  status: QuoteStatus
  total: number
  taxPercent: number
  createdAt?: string
}

export interface AnalyticsPeriod {
  from: string
  to: string
}

export interface DashboardAnalytics {
  period: AnalyticsPeriod
  monthlyRealizedProfit: number
  monthlyPotentialProfit: number
  averageTicket: number
  wonQuotes: number
  lostQuotes: number
  winRate: number
  alerts: DashboardAlerts
  pendingRequests: number
  unansweredQuoteDays?: number
  quotesByStatus: Record<QuoteStatus, number>
  recentQuotes: DashboardQuoteSummary[]
  topRequestedProducts: ProductRank[]
  topQuotedProducts: ProductRank[]
}

export interface ProfitTrendPoint {
  month: string
  label: string
  realizedProfit: number
  potentialProfit: number
}

export interface WholesalerUsage {
  name: string
  count: number
  percent: number
}

export interface ReportesAnalytics {
  period: AnalyticsPeriod
  monthlyRealizedProfit: number
  monthlyPotentialProfit: number
  averageTicket: number
  wonQuotes: number
  lostQuotes: number
  winRate: number
  profitBySalesperson: SalespersonProfit[]
  profitTrend: ProfitTrendPoint[]
  topQuotedProducts: ProductRank[]
  topRequestedProducts: ProductRank[]
  topWholesalers: WholesalerUsage[]
}

export const QUOTE_STATUS_LABELS: Record<QuoteStatus, string> = {
  solicitud_cotizaciones: 'Solicitud de cotizaciones',
  en_elaboracion: 'En elaboración',
  pendiente_envio: 'Lista / Terminada',
  enviada: 'Enviada',
  modificacion: 'Modificación cotización',
  aceptada: 'Aceptada',
  facturada: 'Facturada',
}

export const ROLE_LABELS: Record<UserRole, string> = {
  administrador: 'Administrador',
  gerente_compras: 'Compras',
  ventas: 'Ventas',
}
