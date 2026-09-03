import { useEffect } from 'react'
import { reloadOnceForSpaBuild } from '@/lib/spa-build-reload'

/** Recarga la SPA si hay un deploy nuevo (build-id.txt distinto al bundle en memoria). */
export function useSpaBuildSync(): void {
  useEffect(() => {
    const check = async () => {
      try {
        const res = await fetch(`${import.meta.env.BASE_URL}build-id.txt`, { cache: 'no-store' })
        if (!res.ok) return
        const remote = (await res.text()).trim()
        if (remote && remote !== __SPA_BUILD_ID__) {
          reloadOnceForSpaBuild()
        }
      } catch {
        /* ignore */
      }
    }

    void check()
    const timer = window.setInterval(() => void check(), 60_000)
    return () => window.clearInterval(timer)
  }, [])
}
