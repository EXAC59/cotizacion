import { useRef } from 'react'
import { Outlet } from 'react-router-dom'
import { AppNotificationsBanner } from '@/components/layout/AppNotificationsBanner'
import { Header } from '@/components/layout/Header'
import { Sidebar } from '@/components/layout/Sidebar'
import { PageTransition } from '@/components/motion/PageTransition'
import { useScrollReveal } from '@/components/motion/useScrollReveal'

export function AppLayout() {
  const mainRef = useRef<HTMLElement>(null)
  useScrollReveal(mainRef)

  return (
    <div className="flex min-h-svh bg-slate-950">
      <Sidebar />
      <div className="flex min-w-0 flex-1 flex-col bg-slate-100/80">
        <Header />
        <AppNotificationsBanner />
        <main ref={mainRef} className="app-main-canvas flex-1 overflow-auto p-5 sm:p-6 lg:p-7">
          <PageTransition>
            <Outlet />
          </PageTransition>
        </main>
      </div>
    </div>
  )
}
