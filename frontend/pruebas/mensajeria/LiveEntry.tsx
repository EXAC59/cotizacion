import { useEffect, useState } from 'react'
import { fetchCurrentUser, loginRequest, logoutRequest } from '@/lib/auth-api'
import { Button } from '@/components/ui/Button'
import type { User } from '@/types'
import { LiveMessaging } from './LiveMessaging'
import { isMessagingTest } from './live-api'

export function LiveEntry() {
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  useEffect(() => {
    let current = true
    void fetchCurrentUser().then((value) => { if (current) setUser(value) }).catch(() => { if (current) setError('No se pudo consultar la sesión. Recarga para intentar de nuevo.') }).finally(() => { if (current) setLoading(false) })
    return () => { current = false }
  }, [])
  if (loading) return <div className="messaging-preview live-login"><p role="status">Consultando tu sesión…</p></div>
  if (user) return <LiveMessaging user={user} onLogout={async () => { await logoutRequest(); setUser(null) }} />
  return <div className="messaging-preview live-login"><form className="flow-request-form live-login-card" onSubmit={async (event) => {
    event.preventDefault(); if (busy) return
    const form = new FormData(event.currentTarget); setBusy(true); setError('')
    try { const session = await loginRequest(String(form.get('login')), String(form.get('password'))); if (!session) throw new Error('No se pudo iniciar sesión.'); setUser(session) }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'No se pudo iniciar sesión.') }
    finally { setBusy(false) }
  }}><span className="heading-eyebrow">{isMessagingTest ? 'EXACTO · ENTORNO DE PRUEBAS' : 'EXACTO · COMPRAS Y VENTAS'}</span><h1>Mensajería</h1><p>{isMessagingTest ? 'Entra con tu usuario del sistema de pruebas para conversar sobre tus cotizaciones.' : 'Entra con tu usuario de Exacto para conversar sobre tus cotizaciones.'}</p><label>Usuario o correo<input name="login" autoComplete="username" required autoFocus /></label><label>Contraseña<input name="password" type="password" autoComplete="current-password" required /></label>{error && <p className="live-error" role="alert">{error}</p>}<Button type="submit" disabled={busy}>{busy ? 'Ingresando…' : 'Iniciar sesión'}</Button></form></div>
}
