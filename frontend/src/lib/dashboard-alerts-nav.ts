import type { NavigateFunction } from 'react-router-dom'
import {
  applyNotificationFocus,
  DASHBOARD_SECTIONS,
  isDashboardPath,
  markNotificationFocus,
  navigateWithNotificationFocus,
  resolveNotificationFocus,
  type NotificationFocusTarget,
} from '@/lib/notification-focus'

export type { NotificationFocusTarget } from '@/lib/notification-focus'
export {
  DASHBOARD_SECTIONS,
  applyNotificationFocus,
  navigateWithNotificationFocus,
  useNotificationFocus,
} from '@/lib/notification-focus'

/** @deprecated usar NotificationFocusLocationState */
export type DashboardScrollLocationState = {
  scrollTo?: string
  scrollTs?: number
}

export function markScrollToDashboardSection(targetId: string): void {
  const target = DASHBOARD_SECTIONS[targetId] ?? {
    elementId: targetId,
    label: 'Alertas',
  }
  markNotificationFocus(target)
}

export function resolveDashboardScrollTarget(hash: string): string | null {
  const focus = resolveNotificationFocus()
  if (focus) return focus.elementId
  if (hash.replace('#', '') === 'alertas') return 'alertas'
  return null
}

export function scrollToSectionWithRetry(elementId: string): void {
  const target = DASHBOARD_SECTIONS[elementId] ?? {
    elementId,
    label: 'Esta sección',
  }
  applyNotificationFocus(target)
}

export function goToDashboardAlerts(
  navigate: NavigateFunction,
  location: { pathname: string; search: string },
  targetId: string,
): void {
  const focus: NotificationFocusTarget =
    DASHBOARD_SECTIONS[targetId] ?? { elementId: targetId, label: 'Alertas' }

  if (isDashboardPath(location.pathname)) {
    applyNotificationFocus(focus)
    return
  }

  navigateWithNotificationFocus(
    navigate,
    location,
    { pathname: '/dashboard', hash: 'alertas' },
    focus,
  )
}
