import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { CheckCircle2, Info, AlertTriangle } from 'lucide-react'

type ToastVariant = 'success' | 'info' | 'warning'

type ToastItem = {
  id: number
  message: string
  variant: ToastVariant
}

type ToastContextValue = {
  toast: (message: string, variant?: ToastVariant) => void
}

const ToastContext = createContext<ToastContextValue | null>(null)

const variantStyles: Record<ToastVariant, string> = {
  success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
  info: 'border-indigo-200 bg-indigo-50 text-indigo-900',
  warning: 'border-amber-200 bg-amber-50 text-amber-900',
}

const variantIcons: Record<ToastVariant, ReactNode> = {
  success: <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600" />,
  info: <Info className="h-4 w-4 shrink-0 text-indigo-600" />,
  warning: <AlertTriangle className="h-4 w-4 shrink-0 text-amber-600" />,
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<ToastItem[]>([])
  const nextId = useRef(1)

  const dismiss = useCallback((id: number) => {
    setToasts((current) => current.filter((item) => item.id !== id))
  }, [])

  const toast = useCallback(
    (message: string, variant: ToastVariant = 'success') => {
      const id = nextId.current++
      setToasts((current) => [...current, { id, message, variant }])
      window.setTimeout(() => dismiss(id), 4200)
    },
    [dismiss],
  )

  // Limpieza al desmontar (evita toasts huérfanos entre rutas).
  useEffect(() => {
    return () => setToasts([])
  }, [])

  return (
    <ToastContext.Provider value={{ toast }}>
      {children}
      <div
        aria-live="polite"
        className="pointer-events-none fixed right-4 top-4 z-[100] flex w-[min(92vw,22rem)] flex-col gap-2"
      >
        {toasts.map((item) => (
          <div
            key={item.id}
            role="status"
            className={`pointer-events-auto flex items-start gap-2.5 rounded-xl border px-4 py-3 text-sm shadow-lg shadow-slate-900/10 ${variantStyles[item.variant]} animate-fade-up`}
          >
            {variantIcons[item.variant]}
            <span className="min-w-0 flex-1">{item.message}</span>
            <button
              type="button"
              onClick={() => dismiss(item.id)}
              className="-m-1 rounded p-1 opacity-50 transition hover:opacity-100"
              aria-label="Cerrar aviso"
            >
              ✕
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast(): ToastContextValue {
  const ctx = useContext(ToastContext)
  if (!ctx) {
    throw new Error('useToast debe usarse dentro de <ToastProvider>')
  }
  return ctx
}
