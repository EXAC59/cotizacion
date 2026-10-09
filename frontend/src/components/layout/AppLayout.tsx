import { useRef } from 'react'
import { Outlet } from 'react-router-dom'
import { AppNotificationsBanner } from '@/components/layout/AppNotificationsBanner'
import { Header } from '@/components/layout/Header'
import { Sidebar } from '@/components/layout/Sidebar'
import { PageTransition } from '@/components/motion/PageTransition'
import { useScrollReveal } from '@/components/motion/useScrollReveal'
import { useSpaBuildSync } from '@/hooks/useSpaBuildSync'
import { useMessagingUnreadCount } from '@/hooks/useMessagingUnreadCount'
import { X } from 'lucide-react'

export function AppLayout() {
  const mainRef = useRef<HTMLElement>(null)
  const drawerRef = useRef<HTMLDialogElement>(null)
  useScrollReveal(mainRef)
  useSpaBuildSync()
  const { count: messagingUnreadCount, enabled: messagingEnabled, updateCount } = useMessagingUnreadCount()

  return (
    <div className="flex h-svh overflow-hidden bg-slate-950">
      <div className="hidden md:flex"><Sidebar messagingUnreadCount={messagingUnreadCount} /></div>
      <dialog
        ref={drawerRef}
        id="mobile-navigation"
        aria-label="Menú de navegación"
        className="fixed inset-y-0 left-0 m-0 h-svh max-h-none w-64 max-w-[85vw] overflow-hidden border-0 p-0 backdrop:bg-slate-950/60 sm:w-72"
        onClick={(event) => { if (event.target === event.currentTarget) drawerRef.current?.close() }}
      >
        <button type="button" aria-label="Cerrar menú" className="absolute top-1 right-1 z-10 flex h-11 w-11 items-center justify-center rounded-xl bg-slate-900 text-white focus-visible:outline-2 focus-visible:outline-indigo-300" onClick={() => drawerRef.current?.close()}><X className="h-5 w-5" aria-hidden /></button>
        <Sidebar className="h-full" messagingUnreadCount={messagingUnreadCount} onNavigate={() => drawerRef.current?.close()} />
      </dialog>
      <div className="flex min-h-0 min-w-0 flex-1 flex-col bg-slate-100/80">
        <Header onMenuToggle={() => drawerRef.current?.showModal()}
          sharedPendingCount={messagingEnabled ? messagingUnreadCount : undefined}
          onPendingCountChange={messagingEnabled ? updateCount : undefined} />
        <AppNotificationsBanner />
        <main
          ref={mainRef}
          className="app-main-canvas min-h-0 flex-1 overflow-y-auto p-5 sm:p-6 lg:p-7"
        >
          <PageTransition>
            <Outlet />
          </PageTransition>
        </main>
      </div>
    </div>
  )
}
