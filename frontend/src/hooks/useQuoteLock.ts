import { useCallback, useEffect, useRef, useState } from 'react'
import {
  QuoteLockError,
  acquireQuoteLock,
  releaseQuoteLock,
  type AcquireQuoteLockResult,
} from '@/lib/quotes-api'
import type { QuoteEditLock } from '@/types'

export type QuoteLockState = 'idle' | 'pending' | 'held' | 'blocked'

const DEFAULT_HEARTBEAT_MS = 30_000

export function useQuoteLock(
  quoteId: string | undefined,
  enabled: boolean,
  onAcquired?: (result: AcquireQuoteLockResult) => void,
) {
  const [lockState, setLockState] = useState<QuoteLockState>(
    enabled && quoteId ? 'pending' : 'idle',
  )
  const [lockedBy, setLockedBy] = useState<QuoteEditLock | null>(null)
  const heartbeatMsRef = useRef(DEFAULT_HEARTBEAT_MS)
  const quoteIdRef = useRef(quoteId)
  const onAcquiredRef = useRef(onAcquired)
  const heldRef = useRef(false)

  useEffect(() => {
    quoteIdRef.current = quoteId
  }, [quoteId])

  useEffect(() => {
    onAcquiredRef.current = onAcquired
  }, [onAcquired])

  const tryAcquire = useCallback(async () => {
    const id = quoteIdRef.current
    if (!id || !enabled) {
      setLockState('idle')
      heldRef.current = false
      return
    }

    setLockState('pending')

    try {
      const result = await acquireQuoteLock(id)
      heartbeatMsRef.current = Math.max(15, result.heartbeatSeconds) * 1000
      setLockedBy(null)
      setLockState('held')
      heldRef.current = true
      onAcquiredRef.current?.(result)
    } catch (err) {
      heldRef.current = false
      if (err instanceof QuoteLockError) {
        setLockedBy(err.lockedBy)
        setLockState('blocked')
        return
      }

      setLockedBy(null)
      setLockState('blocked')
    }
  }, [enabled])

  useEffect(() => {
    if (!enabled || !quoteId) {
      setLockState('idle')
      setLockedBy(null)
      heldRef.current = false
      return
    }

    void tryAcquire()

    let heartbeatTimer: number | undefined

    const scheduleHeartbeat = () => {
      heartbeatTimer = window.setTimeout(() => {
        const id = quoteIdRef.current
        if (!id || !heldRef.current) {
          scheduleHeartbeat()
          return
        }

        void acquireQuoteLock(id)
          .then((result) => {
            heartbeatMsRef.current = Math.max(15, result.heartbeatSeconds) * 1000
            heldRef.current = true
            if (result.statusChanged) {
              onAcquiredRef.current?.(result)
            }
          })
          .catch((err) => {
            heldRef.current = false
            if (err instanceof QuoteLockError) {
              setLockedBy(err.lockedBy)
              setLockState('blocked')
            }
          })
          .finally(() => {
            scheduleHeartbeat()
          })
      }, heartbeatMsRef.current)
    }

    scheduleHeartbeat()

    const release = () => {
      const id = quoteIdRef.current
      if (id && heldRef.current) {
        heldRef.current = false
        void releaseQuoteLock(id)
      }
    }

    window.addEventListener('pagehide', release)
    window.addEventListener('beforeunload', release)

    return () => {
      if (heartbeatTimer !== undefined) {
        window.clearTimeout(heartbeatTimer)
      }
      window.removeEventListener('pagehide', release)
      window.removeEventListener('beforeunload', release)
      release()
    }
  }, [enabled, quoteId, tryAcquire])

  return {
    lockState,
    lockedBy,
    retryAcquire: tryAcquire,
    hasEditLock: lockState === 'held',
  }
}
