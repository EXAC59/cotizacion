import { useEffect, useState } from 'react'
import { Eye, EyeOff } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { resetCsrfCookie } from '@/lib/api-fetch'
import { Button } from '@/components/ui/Button'
import { InlineBusy } from '@/components/ui/LoadingState'
import { Card, CardBody } from '@/components/ui/Card'
import { Input, Label } from '@/components/ui/Input'
import { DEMO_ACCOUNTS } from '@/data/demo-users'
import { useAuth } from '@/hooks/useAuth'
import { useRbac } from '@/hooks/useRbac'
import { getDefaultRoute } from '@/lib/module-routes'
import { ROLE_LABELS } from '@/types'

export function LoginPage() {
  const { login, isAuthenticated, user } = useAuth()
  const { rolePermissions } = useRbac()
  const navigate = useNavigate()

  useEffect(() => {
    resetCsrfCookie()
  }, [])

  useEffect(() => {
    if (isAuthenticated && user) {
      navigate(getDefaultRoute(rolePermissions, user), { replace: true })
    }
  }, [isAuthenticated, user, rolePermissions, navigate])

  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setLoading(true)
    setError('')
    try {
      const logged = await login(username, password)
      if (logged) {
        navigate(getDefaultRoute(rolePermissions, logged), { replace: true })
      } else {
        setError('No se pudo iniciar sesión. Verifica tu rol de usuario.')
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Usuario/correo o contraseña incorrectos')
    } finally {
      setLoading(false)
    }
  }

  const fillDemoAccount = (accountUsername: string, accountPassword: string) => {
    setUsername(accountUsername)
    setPassword(accountPassword)
    setError('')
  }

  return (
    <Card className="w-full max-w-lg">
      <CardBody>
        <h2 className="text-xl font-semibold tracking-tight text-slate-900">Iniciar sesión</h2>
        <p className="mt-1.5 text-sm text-slate-500">Accede con tu usuario o correo del sistema</p>

        {import.meta.env.DEV && (
        <div className="mt-4 space-y-2">
          <p className="text-sm font-medium text-slate-900">Cuentas de demostración</p>
          <div className="grid gap-2 sm:grid-cols-2">
            {DEMO_ACCOUNTS.map((account) => (
              <div
                key={account.id}
                className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700"
              >
                <p className="font-medium text-slate-900">{account.name}</p>
                <p className="text-xs text-indigo-700">{ROLE_LABELS[account.role]}</p>
                <p className="mt-1 truncate text-xs">
                  <code className="rounded bg-white px-1">{account.username ?? account.email}</code>
                </p>
                <p className="text-xs">
                  <code className="rounded bg-white px-1">{account.password}</code>
                </p>
                <Button
                  type="button"
                  variant="secondary"
                  size="sm"
                  className="mt-2 w-full"
                  onClick={() => fillDemoAccount(account.username ?? account.email, account.password)}
                >
                  Usar esta cuenta
                </Button>
              </div>
            ))}
          </div>
        </div>
        )}

        <form onSubmit={handleSubmit} className="mt-6 space-y-4">
          <div>
            <Label htmlFor="username">Usuario o correo</Label>
            <Input
              id="username"
              type="text"
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              placeholder="ejemplo@exacto.mx"
              autoComplete="username"
              required
            />
          </div>
          <div>
            <Label htmlFor="password">Contraseña</Label>
            <div className="relative">
              <Input
                id="password"
                type={showPassword ? 'text' : 'password'}
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                placeholder="Contraseña"
                autoComplete="current-password"
                className="pr-10"
                required
              />
              <button
                type="button"
                onClick={() => setShowPassword((v) => !v)}
                className="absolute inset-y-0 right-0 flex items-center px-3 text-slate-500 hover:text-slate-800"
                aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
                tabIndex={-1}
              >
                {showPassword ? (
                  <EyeOff className="h-4 w-4" aria-hidden />
                ) : (
                  <Eye className="h-4 w-4" aria-hidden />
                )}
              </button>
            </div>
          </div>
          {error && <p className="text-sm text-red-600">{error}</p>}
          <Button type="submit" className="w-full" disabled={loading}>
            {loading ? <InlineBusy size="sm" label="Entrando…" /> : 'Entrar'}
          </Button>
        </form>
      </CardBody>
    </Card>
  )
}
