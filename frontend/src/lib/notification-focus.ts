import { useEffect, useRef } from 'react'
import { useLocation } from 'react-router-dom'

const SCROLL_OFFSET_PX = 16
const SPOTLIGHT_HINT_ATTR = 'data-scroll-spotlight-hint'
const FOCUS_FLAG = 'cotixacto.notificationFocus'

export type NotificationFocusTarget = {
  elementId: string
  label: string
}

export type NotificationFocusLocationState = {
  focus?: NotificationFocusTarget
  focusTs?: number
  /** @deprecated usar focus */
  scrollTo?: string
  scrollTs?: number
}

export const DASHBOARD_SECTIONS: Record<string, NotificationFocusTarget> = {
  alertas: { elementId: 'alertas', label: 'Notificaciones y recordatorios' },
  'cotizaciones-pendientes': {
    elementId: 'cotizaciones-pendientes',
    label: 'Cotizaciones pendientes',
  },
  'cotizaciones-listas-ventas': {
    elementId: 'cotizaciones-listas-ventas',
    label: 'Listas / Terminadas para ventas',
  },
  'cotizaciones-sin-avance': {
    elementId: 'cotizaciones-sin-avance',
    label: 'Cotizaciones sin avance',
  },
  'solicitudes-sin-revisar': {
    elementId: 'solicitudes-sin-revisar',
    label: 'Solicitudes sin revisar',
  },
  'lecturas-atascadas': {
    elementId: 'lecturas-atascadas',
    label: 'Lecturas atascadas',
  },
  'errores-integracion': {
    elementId: 'errores-integracion', label: 'Errores de integración',
  },
}

export function quoteDashboardFocus(
  quoteId: string,
  folio: string,
  label: string,
): NotificationFocusTarget {
  return {
    elementId: `dashboard-quote-${quoteId}`,
    label: `${folio} · ${label}`,
  }
}

export function recordatorioQuoteFocus(
  quoteId: string,
  folio: string,
  reasonLabel: string,
): NotificationFocusTarget {
  return {
    elementId: `recordatorio-quote-${quoteId}`,
    label: `${folio} · ${reasonLabel}`,
  }
}

export function quoteDetailFocus(folio: string, label: string): NotificationFocusTarget {
  return {
    elementId: 'quote-detail-panel',
    label: `${folio} · ${label}`,
  }
}

export function isDashboardPath(pathname: string): boolean {
  return /\/dashboard\/?$/.test(pathname)
}

function normalizePath(pathname: string): string {
  return pathname.replace(/\/$/, '') || '/'
}

export function markNotificationFocus(target: NotificationFocusTarget): void {
  try {
    sessionStorage.setItem(FOCUS_FLAG, JSON.stringify(target))
  } catch {
    /* ignore */
  }
}

export function resolveNotificationFocus(): NotificationFocusTarget | null {
  try {
    const raw = sessionStorage.getItem(FOCUS_FLAG)
    if (!raw) return null
    sessionStorage.removeItem(FOCUS_FLAG)
    return JSON.parse(raw) as NotificationFocusTarget
  } catch {
    return null
  }
}

function scrollGap(target: HTMLElement, main: HTMLElement, offset: number): number {
  return Math.abs(target.getBoundingClientRect().top - main.getBoundingClientRect().top - offset)
}

function targetVisibleInMain(target: HTMLElement, main: HTMLElement): boolean {
  const tr = target.getBoundingClientRect()
  const mr = main.getBoundingClientRect()
  return tr.top < mr.bottom - 8 && tr.bottom > mr.top + 8
}

function clearScrollSpotlight(target: HTMLElement): void {
  target.classList.remove('scroll-spotlight-target')
  target.querySelector(`[${SPOTLIGHT_HINT_ATTR}]`)?.remove()
}

/** Señal visual breve: indica el elemento sin ser un tour guiado. */
export function showScrollSpotlight(target: HTMLElement, label: string): void {
  clearScrollSpotlight(target)

  const hint = document.createElement('div')
  hint.className = 'scroll-spotlight-hint'
  hint.setAttribute(SPOTLIGHT_HINT_ATTR, '1')
  hint.setAttribute('role', 'status')
  hint.setAttribute('aria-live', 'polite')
  hint.textContent = `Aquí · ${label}`

  if (getComputedStyle(target).position === 'static') {
    target.classList.add('relative')
  }
  target.classList.add('scroll-spotlight-target')
  target.append(hint)

  window.setTimeout(() => clearScrollSpotlight(target), 4200)
}

function scheduleScrollSpotlight(target: HTMLElement, label: string, delayMs: number): void {
  window.setTimeout(() => showScrollSpotlight(target, label), delayMs)
}

function scrollMainToTarget(
  target: HTMLElement,
  main: HTMLElement,
  offset: number,
  behavior: ScrollBehavior,
): number {
  const top =
    target.getBoundingClientRect().top -
    main.getBoundingClientRect().top +
    main.scrollTop -
    offset
  const maxScroll = Math.max(0, main.scrollHeight - main.clientHeight)
  const nextTop = Math.min(Math.max(0, top), maxScroll)
  main.scrollTo({ top: nextTop, behavior })
  return nextTop
}

/** Desplaza y señala un elemento dentro del canvas principal. */
export function scrollToFocusTarget(
  elementId: string,
  label: string,
  attempt = 0,
): void {
  const maxAttempts = 50
  const target = document.getElementById(elementId)
  const main = document.querySelector('main.app-main-canvas')

  if (!target) {
    if (attempt < maxAttempts) {
      requestAnimationFrame(() => scrollToFocusTarget(elementId, label, attempt + 1))
    }
    return
  }

  let gap = 0
  let atMaxScroll = false
  let visible = false
  let scrollTopBefore: number | null = null
  let scrollTopAfter: number | null = null

  if (main instanceof HTMLElement && main.contains(target)) {
    scrollTopBefore = main.scrollTop
    const prevMargin = target.style.scrollMarginTop
    target.style.scrollMarginTop = `${SCROLL_OFFSET_PX}px`
    scrollMainToTarget(target, main, SCROLL_OFFSET_PX, attempt === 0 ? 'smooth' : 'auto')
    target.style.scrollMarginTop = prevMargin
    scrollTopAfter = main.scrollTop
    gap = scrollGap(target, main, SCROLL_OFFSET_PX)
    atMaxScroll = main.scrollTop >= main.scrollHeight - main.clientHeight - 1
    visible = targetVisibleInMain(target, main)
  } else {
    target.style.scrollMarginTop = '7rem'
    target.scrollIntoView({ behavior: attempt === 0 ? 'smooth' : 'auto', block: 'start' })
    gap = Math.abs(target.getBoundingClientRect().top - SCROLL_OFFSET_PX)
    visible = true
  }

  const moved =
    scrollTopBefore !== null && scrollTopAfter !== null && scrollTopBefore !== scrollTopAfter
  const success = gap <= 12 || (visible && (atMaxScroll || moved))

  if (success) {
    const spotlightDelay = attempt === 0 && moved ? 480 : 0
    scheduleScrollSpotlight(target, label, spotlightDelay)
  } else if (attempt < maxAttempts) {
    requestAnimationFrame(() => scrollToFocusTarget(elementId, label, attempt + 1))
  }
}

export function applyNotificationFocus(target: NotificationFocusTarget): void {
  requestAnimationFrame(() => scrollToFocusTarget(target.elementId, target.label))
}

type NavigateLike = (
  to: { pathname: string; search?: string; hash?: string },
  options?: { replace?: boolean; state?: NotificationFocusLocationState | null },
) => void | Promise<void>

export function navigateWithNotificationFocus(
  navigate: NavigateLike,
  location: { pathname: string; search: string },
  destination: { pathname: string; search?: string; hash?: string },
  target: NotificationFocusTarget,
): void {
  const sameRoute =
    normalizePath(location.pathname) === normalizePath(destination.pathname) &&
    location.search === (destination.search ?? '')

  if (sameRoute) {
    applyNotificationFocus(target)
    return
  }

  markNotificationFocus(target)

  void navigate(
    {
      pathname: destination.pathname,
      search: destination.search,
      hash: destination.hash,
    },
    {
      state: { focus: target, focusTs: Date.now() },
    },
  )
}

function resolveFocusFromLocation(
  state: NotificationFocusLocationState | null,
  hash: string,
): NotificationFocusTarget | null {
  if (state?.focus) return state.focus
  if (state?.scrollTo) {
    return (
      DASHBOARD_SECTIONS[state.scrollTo] ?? {
        elementId: state.scrollTo,
        label: 'Alertas',
      }
    )
  }
  const fromStorage = resolveNotificationFocus()
  if (fromStorage) return fromStorage
  if (hash.replace('#', '') === 'alertas') return DASHBOARD_SECTIONS.alertas
  return null
}

/** Aplica foco al cargar la página (tras datos listos). */
export function useNotificationFocus(ready = true): void {
  const location = useLocation()
  const lastFocusTsRef = useRef(0)

  useEffect(() => {
    if (!ready) return

    const state = location.state as NotificationFocusLocationState | null
    const focusTs = state?.focusTs ?? state?.scrollTs ?? 0
    const focus = resolveFocusFromLocation(state, location.hash)
    if (!focus) return
    if (focusTs > 0 && focusTs === lastFocusTsRef.current) return
    if (focusTs > 0) lastFocusTsRef.current = focusTs

    const delayMs = focusTs > 0 || state?.scrollTo ? 450 : 0
    const timer = window.setTimeout(() => {
      applyNotificationFocus(focus)
    }, delayMs)
    return () => window.clearTimeout(timer)
  }, [ready, location.hash, location.pathname, location.state])
}
