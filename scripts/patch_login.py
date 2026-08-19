from pathlib import Path

login_path = Path(r"c:\laragon\www\cotizacion\frontend\src\pages\auth\LoginPage.tsx")
login_path.write_text("""import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { Input, Label } from '@/components/ui/Input'
import { DEMO_ACCOUNTS } from '@/data/demo-users'
import { useAuth } from '@/hooks/useAuth'
import { ROLE_LABELS } from '@/types'

export function LoginPage() {
  const { login, isAuthenticated } = useAuth()
  const navigate = useNavigate()

  useEffect(() => {
    if (isAuthenticated) navigate('/dashboard', { replace: true })
  }, [isAuthenticated, navigate])

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setLoading(true)
    setError('')
    const ok = await login(email, password)
    setLoading(false)
    if (ok) navigate('/dashboard')
    else setError('Correo o contraseña incorrectos')
  }

  const useAccount = (accountEmail: string, accountPassword: string) => {
    setEmail(accountEmail)
    setPassword(accountPassword)
    setError('')
  }

  return (
    <Card className="w-full max-w-lg">
      <CardBody>
        <h2 className="text-xl font-semibold text-slate-900">Iniciar sesión</h2>
        <p className="mt-1 text-sm text-slate-500">Accede con tu usuario del sistema</p>

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
                  <code className="rounded bg-white px-1">{account.email}</code>
                </p>
                <p className="text-xs">
                  <code className="rounded bg-white px-1">{account.password}</code>
                </p>
                <Button
                  type="button"
                  variant="secondary"
                  size="sm"
                  className="mt-2 w-full"
                  onClick={() => useAccount(account.email, account.password)}
                >
                  Usar esta cuenta
                </Button>
              </div>
            ))}
          </div>
        </div>

        <form onSubmit={handleSubmit} className="mt-6 space-y-4">
          <div>
            <Label htmlFor="email">Correo</Label>
            <Input
              id="email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              autoComplete="username"
              required
            />
          </div>
          <div>
            <Label htmlFor="password">Contraseña</Label>
            <Input
              id="password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              autoComplete="current-password"
              required
            />
          </div>
          {error && <p className="text-sm text-red-600">{error}</p>}
          <Button type="submit" className="w-full" disabled={loading}>
            {loading ? 'Entrando…' : 'Entrar'}
          </Button>
        </form>
      </CardBody>
    </Card>
  )
}
""", encoding="utf-8")
print("LoginPage updated")
