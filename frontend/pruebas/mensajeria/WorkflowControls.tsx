import { ArrowRight, Check, GitBranch, Users } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import type { ChatRole } from './data'
import { CHECK_LABELS, localDate, stageInfo, type RequestInput, type Stage, type WorkflowRequest } from './workflow-data'

export function StageBadge({ stage }: { stage: Stage }) {
  const info = stageInfo(stage)
  return <span className={`flow-status flow-${info.color}`}><span />{info.label}</span>
}

export function RequestForm({ onCreate }: { onCreate: (input: RequestInput) => void }) {
  return <form className="flow-request-form" onSubmit={(event) => {
    event.preventDefault()
    const data = new FormData(event.currentTarget)
    const client = String(data.get('client') ?? '').trim(); const description = String(data.get('product') ?? '').trim(); const brief = String(data.get('brief') ?? '').trim()
    if (!client || !description || !brief) return
    onCreate({ client, products: [{ description, quantity: Number(data.get('quantity')) }], brief, due: String(data.get('due')), priority: data.get('priority') === 'Alta' ? 'Alta' : 'Normal' })
  }}><p>Compras recibirá el aviso. El chat se abrirá cuando alguien tome la solicitud.</p><label>Cliente<input name="client" required maxLength={120} placeholder="Nombre del cliente" autoFocus /></label><label>Producto o requerimiento<input name="product" required maxLength={180} placeholder="Ej. Laptop con 16 GB RAM" /></label><div className="flow-form-pair"><label>Cantidad<input name="quantity" required type="number" min={1} max={9999} step={1} defaultValue={1} /></label><label>Prioridad<select aria-label="Prioridad" name="priority" defaultValue="Normal"><option>Normal</option><option>Alta</option></select></label></div><label>Fecha compromiso<input name="due" required type="date" min={localDate()} defaultValue={localDate(1)} /></label><label>Lo que Compras necesita saber<textarea name="brief" required maxLength={2000} rows={3} placeholder="Características, alternativas permitidas y fecha que necesita el cliente…" /></label><div className="flow-form-notice"><BellNote />Se asignará un folio de prueba y se avisará a la bandeja de Compras.</div><Button type="submit">Enviar solicitud a Compras<ArrowRight size={16} aria-hidden /></Button></form>
}
function BellNote() { return <Users size={17} aria-hidden /> }

export function WorkflowFollowup({ request, role, onCheck, onAction }: {
  request: WorkflowRequest; role: ChatRole; onCheck: (key: keyof WorkflowRequest['checks']) => void
  onAction: (action: 'question' | 'resume' | 'ready' | 'sent' | 'close' | 'reopen') => void
}) {
  const complete = Object.values(request.checks).every(Boolean)
  return <div className="flow-followup"><div className="flow-followup-heading"><span><GitBranch size={15} aria-hidden />Seguimiento compartido</span><StageBadge stage={request.stage} /></div>
    <p>{request.stage === 'en_proceso' ? 'Compras está preparando la cotización.' : request.stage === 'espera_ventas' ? 'Falta una respuesta de Ventas para continuar.' : request.stage === 'lista' ? 'Compras terminó. Ventas revisa y envía al cliente.' : request.stage === 'enviada' ? 'Ventas da seguimiento a la respuesta del cliente.' : 'Seguimiento cerrado. El historial permanece disponible.'}</p>
    <div className="flow-checks">{(Object.entries(CHECK_LABELS) as [keyof WorkflowRequest['checks'], string][]).map(([key, label]) => <label key={key}><input type="checkbox" checked={request.checks[key]} disabled={role !== 'compras' || request.stage !== 'en_proceso'} onChange={() => onCheck(key)} />{label}</label>)}</div>
    <div className="flow-followup-actions">{role === 'compras' && request.stage === 'en_proceso' && <><Button variant="secondary" onClick={() => onAction('question')}>Pedir información</Button><Button disabled={!complete} title={!complete ? 'Confirma los tres puntos antes de terminar' : undefined} onClick={() => onAction('ready')}>Marcar lista</Button></>}{role === 'ventas' && request.stage === 'espera_ventas' && <Button onClick={() => onAction('resume')}>Responder y continuar</Button>}{role === 'ventas' && request.stage === 'lista' && <><Button variant="secondary" onClick={() => onAction('reopen')}>Solicitar ajuste</Button><Button onClick={() => onAction('sent')}>Confirmar envío al cliente</Button></>}{role === 'ventas' && request.stage === 'enviada' && <Button onClick={() => onAction('close')}>Cerrar seguimiento</Button>}{request.stage === 'cerrada' && <span className="flow-archived"><Check size={15} aria-hidden />Historial conservado</span>}</div>
    {role === 'compras' && request.stage === 'en_proceso' && !complete && <small>Completa los tres puntos para pasar a Ventas.</small>}
  </div>
}
