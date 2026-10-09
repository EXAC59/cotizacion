import type { QuoteStatus } from '@/types'

export type ChatRole = 'ventas' | 'compras'
export type ChatMessage = {
  id: string
  author: ChatRole | 'sistema'
  text: string
  time: string
  day: string
  readBy?: ChatRole[]
}
export type ChatThread = {
  id: string
  folio: string
  client: string
  sales: string
  purchasing: string
  status: QuoteStatus
  total: number
  unread: Record<ChatRole, number>
  reminder: string | null
  products: { description: string; quantity: number }[]
  messages: ChatMessage[]
}

export const ROLE_LABEL: Record<ChatRole, string> = { ventas: 'Ventas', compras: 'Compras' }
export const STORAGE_KEY = 'exacto.mensajeria.propuesta.v1'

export function seedThreads(): ChatThread[] {
  return [
    {
      id: 'quote-demo-12', folio: 'COT-ERICKA-0012', client: 'Distribuidora Norte Auditoría',
      sales: 'Ericka Martínez', purchasing: 'Ana López', status: 'en_elaboracion', total: 28420,
      unread: { ventas: 0, compras: 0 }, reminder: 'Confirmar disponibilidad antes de las 13:00 h.',
      products: [{ description: 'Laptop Lenovo ThinkPad E14', quantity: 3 }, { description: 'Monitor Dell de 24 pulgadas', quantity: 3 }],
      messages: [
        { id: '12-0', author: 'sistema', text: 'Ventas envió la solicitud de cotización a Compras.', time: '09:10', day: 'Hoy' },
        { id: '12-1', author: 'ventas', text: 'Hola, Ana. El cliente necesita 3 laptops y 3 monitores. ¿Nos ayudas a confirmar disponibilidad y tiempo de entrega?', time: '09:12', day: 'Hoy', readBy: ['compras'] },
        { id: '12-2', author: 'compras', text: 'Claro, Ericka. Las laptops están disponibles en BODEGA01. Estoy revisando los monitores para enviar todo en una sola entrega.', time: '09:18', day: 'Hoy', readBy: ['ventas'] },
        { id: '12-3', author: 'ventas', text: 'Perfecto. El cliente nos pide confirmar hoy antes de las 13:00 h.', time: '09:20', day: 'Hoy', readBy: ['compras'] },
        { id: '12-4', author: 'sistema', text: 'Recordatorio: confirmar disponibilidad antes de las 13:00 h.', time: '09:21', day: 'Hoy' },
        { id: '12-5', author: 'compras', text: 'También hay monitores disponibles. La entrega estimada es de 2 a 3 días hábiles. Te aviso aquí cuando termine la cotización.', time: '09:34', day: 'Hoy', readBy: ['ventas'] },
      ],
    },
    {
      id: 'quote-demo-11', folio: 'COT-ERICKA-0011', client: 'Soluciones del Pacífico',
      sales: 'Ericka Martínez', purchasing: 'Ana López', status: 'pendiente_envio', total: 15990,
      unread: { ventas: 2, compras: 0 }, reminder: null,
      products: [{ description: 'Equipo de escritorio HP Pro', quantity: 2 }],
      messages: [
        { id: '11-1', author: 'ventas', text: '¿Me confirmas la vigencia del precio para los dos equipos?', time: '08:45', day: 'Hoy', readBy: ['compras'] },
        { id: '11-2', author: 'sistema', text: 'La cotización cambió a Lista / Terminada.', time: '09:00', day: 'Hoy' },
        { id: '11-3', author: 'compras', text: 'El precio tiene vigencia de 5 días. Ya quedó lista para que la envíes al cliente.', time: '09:02', day: 'Hoy' },
        { id: '11-4', author: 'compras', text: 'La disponibilidad está confirmada para ambos equipos.', time: '09:03', day: 'Hoy' },
      ],
    },
    {
      id: 'quote-demo-10', folio: 'COT-MARIA-0010', client: 'Grupo Industrial Alameda',
      sales: 'María García', purchasing: 'Ana López', status: 'solicitud_cotizaciones', total: 32600,
      unread: { ventas: 1, compras: 1 }, reminder: 'Solicitud por revisar desde ayer.',
      products: [{ description: 'Impresora multifuncional Brother', quantity: 4 }],
      messages: [
        { id: '10-1', author: 'sistema', text: 'Nueva solicitud de cotización asignada a Compras.', time: '16:15', day: 'Ayer' },
        { id: '10-2', author: 'ventas', text: 'Necesitamos 4 impresoras con conexión de red. El cliente puede aceptar una alternativa equivalente.', time: '16:17', day: 'Ayer' },
        { id: '10-3', author: 'compras', text: 'Voy a revisar las alternativas y compartirte la mejor opción.', time: '08:56', day: 'Hoy' },
      ],
    },
    {
      id: 'quote-demo-8', folio: 'COT-ERICKA-0008', client: 'Tecnología y Servicios MX',
      sales: 'Ericka Martínez', purchasing: 'Luis Ramírez', status: 'enviada', total: 8760,
      unread: { ventas: 0, compras: 0 }, reminder: null,
      products: [{ description: 'Switch administrable de 24 puertos', quantity: 1 }],
      messages: [
        { id: '8-1', author: 'compras', text: 'Disponibilidad confirmada. Podemos mantener el precio hasta mañana.', time: '11:24', day: 'Ayer', readBy: ['ventas'] },
        { id: '8-2', author: 'ventas', text: 'Gracias, Luis. Ya envié la cotización y estoy esperando la respuesta del cliente.', time: '11:40', day: 'Ayer', readBy: ['compras'] },
      ],
    },
  ]
}

export const AVAILABLE_QUOTES: Omit<ChatThread, 'messages' | 'unread'>[] = [
  { id: 'quote-demo-7', folio: 'COT-ERICKA-0007', client: 'Comercial del Norte', sales: 'Ericka Martínez', purchasing: 'Ana López', status: 'solicitud_cotizaciones', total: 12350, reminder: null, products: [{ description: 'Access point empresarial', quantity: 5 }] },
  { id: 'quote-demo-14', folio: 'COT-MARIA-0014', client: 'Oficinas del Centro', sales: 'María García', purchasing: 'Luis Ramírez', status: 'en_elaboracion', total: 18900, reminder: null, products: [{ description: 'Monitor de 27 pulgadas', quantity: 6 }] },
]

export function loadThreads(): ChatThread[] {
  try {
    const value: unknown = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? 'null')
    if (Array.isArray(value) && value.length > 0 && value.every((thread) =>
      thread && typeof thread.id === 'string' && typeof thread.folio === 'string' &&
      typeof thread.client === 'string' && typeof thread.sales === 'string' && typeof thread.purchasing === 'string' &&
      ['solicitud_cotizaciones', 'en_elaboracion', 'pendiente_envio', 'enviada'].includes(thread.status) &&
      typeof thread.total === 'number' && Number.isFinite(thread.total) &&
      thread.unread && Number.isInteger(thread.unread.ventas) && Number.isInteger(thread.unread.compras) && thread.unread.ventas >= 0 && thread.unread.compras >= 0 &&
      (thread.reminder === null || typeof thread.reminder === 'string') &&
      Array.isArray(thread.products) && thread.products.every((item: { description?: unknown; quantity?: unknown }) => typeof item.description === 'string' && typeof item.quantity === 'number') &&
      Array.isArray(thread.messages) && thread.messages.length > 0 && thread.messages.every((message: ChatMessage) =>
        message && typeof message.id === 'string' && typeof message.text === 'string' &&
        ['ventas', 'compras', 'sistema'].includes(message.author) && typeof message.time === 'string' && typeof message.day === 'string' &&
        (message.readBy === undefined || (Array.isArray(message.readBy) && message.readBy.every((role) => role === 'ventas' || role === 'compras')))
      ),
    ) && new Set(value.map((thread) => thread.id)).size === value.length) return value as ChatThread[]
  } catch { /* A blocked or invalid local store falls back to sample conversations. */ }
  return seedThreads()
}
