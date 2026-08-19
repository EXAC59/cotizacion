import { useEffect, useRef, type ReactNode } from 'react'
import { useLocation } from 'react-router-dom'

/**
 * Entrada suave al cambiar de apartado (ruta).
 * Respeta prefers-reduced-motion vía CSS.
 */
export function PageTransition({ children }: { children: ReactNode }) {
  const location = useLocation()
  const shellRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const root = shellRef.current
    if (!root) return

    // Reinicia reveals al entrar a una pantalla nueva.
    root.querySelectorAll('[data-reveal].is-visible').forEach((el) => {
      el.classList.remove('is-visible')
    })

    // Fuerza reflow para relanzar animación de página.
    root.classList.remove('page-enter')
    void root.offsetWidth
    root.classList.add('page-enter')
  }, [location.pathname, location.search])

  return (
    <div ref={shellRef} className="page-shell page-enter" data-page={location.pathname}>
      {children}
    </div>
  )
}
