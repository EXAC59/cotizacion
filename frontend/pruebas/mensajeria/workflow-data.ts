import type { ChatRole, ChatThread } from './data'

export const STAGES = [
  { id: 'por_tomar', label: 'Por tomar', color: 'purple', description: 'Compras recibe y toma la solicitud' },
  { id: 'en_proceso', label: 'En proceso', color: 'blue', description: 'Compras confirma precios y disponibilidad' },
  { id: 'espera_ventas', label: 'Esperando a Ventas', color: 'orange', description: 'Ventas resuelve una duda o faltante' },
  { id: 'lista', label: 'Lista para enviar', color: 'green', description: 'Ventas revisa y envía al cliente' },
  { id: 'enviada', label: 'Enviada', color: 'teal', description: 'Ventas da seguimiento al cliente' },
  { id: 'cerrada', label: 'Cerrada', color: 'gray', description: 'Historial conservado' },
] as const
export type Stage = typeof STAGES[number]['id']
export type WorkflowRequest = {
  id: string; folio: string; client: string; sales: string; owner: string | null
  stage: Stage; priority: 'Alta' | 'Normal'; due: string; created: string; brief: string
  products: { description: string; quantity: number }[]
  checks: { stock: boolean; price: boolean; delivery: boolean }
  notices: Record<ChatRole, string | null>
}
export type RequestInput = Pick<WorkflowRequest, 'client' | 'priority' | 'due' | 'brief' | 'products'>
export const WORKFLOW_KEY = 'exacto.mensajeria.flujo.v1'
export const CHECK_LABELS = { stock: 'Disponibilidad confirmada', price: 'Precios y vigencia revisados', delivery: 'Tiempo de entrega confirmado' }
export function localDate(offset = 0) {
  const date = new Date(); date.setDate(date.getDate() + offset)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}
export function dateLabel(value: string) {
  return new Date(`${value}T12:00:00`).toLocaleDateString('es-MX', { day: '2-digit', month: 'short' })
}
export function stageInfo(stage: Stage) { return STAGES.find((item) => item.id === stage)! }
export function seedRequests(threads: ChatThread[]): WorkflowRequest[] {
  const existing = threads.map((thread): WorkflowRequest => ({
    id: thread.id, folio: thread.folio, client: thread.client, sales: thread.sales, owner: thread.purchasing,
    stage: thread.status === 'enviada' ? 'enviada' : thread.status === 'pendiente_envio' ? 'lista' : 'en_proceso',
    priority: thread.reminder ? 'Alta' : 'Normal', due: localDate(thread.reminder ? 0 : 1), created: localDate(-1),
    brief: thread.messages.find((message) => message.author === 'ventas')?.text ?? 'Confirmar las condiciones de esta cotización.',
    products: thread.products, checks: { stock: thread.status !== 'solicitud_cotizaciones', price: thread.status !== 'solicitud_cotizaciones', delivery: thread.status === 'enviada' || thread.status === 'pendiente_envio' },
    notices: { ventas: thread.status === 'pendiente_envio' ? 'Compras terminó la cotización. Está lista para revisar y enviar.' : null, compras: null },
  }))
  return [...existing, {
    id: 'request-demo-15', folio: 'COT-ERICKA-0015', client: 'Arquitectura del Noroeste', sales: 'Ericka Martínez', owner: null,
    stage: 'por_tomar', priority: 'Alta', due: localDate(), created: localDate(), brief: 'El cliente necesita 5 laptops para diseño. Confirmar una opción con 16 GB de RAM y entrega esta semana.',
    products: [{ description: 'Laptop para diseño · 16 GB RAM', quantity: 5 }], checks: { stock: false, price: false, delivery: false },
    notices: { ventas: null, compras: 'Ventas envió una nueva solicitud. Tómala para iniciar el seguimiento.' },
  }, {
    id: 'request-demo-16', folio: 'COT-MARIA-0016', client: 'Clínica del Valle', sales: 'María García', owner: null,
    stage: 'por_tomar', priority: 'Normal', due: localDate(1), created: localDate(), brief: 'Se requieren 2 impresoras de red y sus consumibles. El cliente acepta alternativas.',
    products: [{ description: 'Impresora multifuncional de red', quantity: 2 }], checks: { stock: false, price: false, delivery: false },
    notices: { ventas: null, compras: 'Nueva solicitud disponible en la bandeja de Compras.' },
  }]
}
export function loadRequests(threads: ChatThread[]): WorkflowRequest[] {
  try {
    const value: unknown = JSON.parse(localStorage.getItem(WORKFLOW_KEY) ?? 'null')
    if (Array.isArray(value) && value.length > 0 && value.every((item) => item &&
      typeof item.id === 'string' && typeof item.folio === 'string' && typeof item.client === 'string' && typeof item.sales === 'string' &&
      (item.owner === null || typeof item.owner === 'string') && STAGES.some((stage) => stage.id === item.stage) &&
      (item.stage !== 'por_tomar' || item.owner === null) && (item.stage === 'por_tomar' || typeof item.owner === 'string') &&
      ['Alta', 'Normal'].includes(item.priority) && /^\d{4}-\d{2}-\d{2}$/.test(item.due) && /^\d{4}-\d{2}-\d{2}$/.test(item.created) &&
      typeof item.brief === 'string' && item.checks && Object.keys(CHECK_LABELS).every((key) => typeof item.checks[key] === 'boolean') &&
      item.notices && ['ventas', 'compras'].every((key) => item.notices[key] === null || typeof item.notices[key] === 'string') &&
      Array.isArray(item.products) && item.products.length > 0 && item.products.every((product: { description?: unknown; quantity?: unknown }) => typeof product.description === 'string' && typeof product.quantity === 'number' && product.quantity > 0)
    ) && new Set(value.map((item) => item.id)).size === value.length &&
      value.every((item) => item.stage === 'por_tomar' || threads.some((thread) => thread.id === item.id))) return value as WorkflowRequest[]
  } catch { /* The independent demo falls back to valid examples. */ }
  return seedRequests(threads)
}
