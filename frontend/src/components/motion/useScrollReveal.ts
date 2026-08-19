import { useEffect, type RefObject } from 'react'
import { useLocation } from 'react-router-dom'

/**
 * Observa [data-reveal] dentro del contenedor con scroll y anima al entrar en viewport.
 */
export function useScrollReveal(containerRef: RefObject<HTMLElement | null>) {
  const location = useLocation()

  useEffect(() => {
    const root = containerRef.current
    if (!root) return

    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    if (reduced) {
      root.querySelectorAll('[data-reveal]').forEach((el) => el.classList.add('is-visible'))
      return
    }

    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible')
            observer.unobserve(entry.target)
          }
        }
      },
      {
        root,
        rootMargin: '0px 0px -8% 0px',
        threshold: 0.08,
      },
    )

    const observeAll = () => {
      root.querySelectorAll('[data-reveal]:not(.is-visible)').forEach((el, index) => {
        if (el instanceof HTMLElement) {
          el.style.setProperty('--reveal-delay', `${Math.min(index * 40, 280)}ms`)
        }
        observer.observe(el)
      })
    }

    observeAll()

    const mutation = new MutationObserver(() => observeAll())
    mutation.observe(root, { childList: true, subtree: true })

    return () => {
      observer.disconnect()
      mutation.disconnect()
    }
  }, [containerRef, location.pathname, location.search])
}
