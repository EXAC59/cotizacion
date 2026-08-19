import { Link } from 'react-router-dom'
import { Users, KeyRound } from 'lucide-react'
import { Card, CardBody } from '@/components/ui/Card'
import { PageHeader } from '@/components/ui/PageHeader'
import { useRbac } from '@/hooks/useRbac'

export function AdminHomePage() {
  const { users } = useRbac()

  return (
    <div>
      <PageHeader
        title="Panel de administración"
        description="Usuarios, roles y permisos por módulo"
      />

      <div className="grid gap-4 md:grid-cols-2">
        <Link to="/admin/usuarios">
          <Card className="transition-shadow hover:shadow-md">
            <CardBody className="flex items-start gap-4">
              <div className="rounded-lg bg-indigo-100 p-3 text-indigo-600">
                <Users className="h-6 w-6" />
              </div>
              <div>
                <h3 className="font-semibold text-slate-900">Usuarios</h3>
                <p className="mt-1 text-sm text-slate-500">
                  {users.length} registrados — alta, edición y desactivación
                </p>
              </div>
            </CardBody>
          </Card>
        </Link>

        <Link to="/admin/roles">
          <Card className="transition-shadow hover:shadow-md">
            <CardBody className="flex items-start gap-4">
              <div className="rounded-lg bg-violet-100 p-3 text-violet-600">
                <KeyRound className="h-6 w-6" />
              </div>
              <div>
                <h3 className="font-semibold text-slate-900">Roles y permisos</h3>
                <p className="mt-1 text-sm text-slate-500">
                  Matriz de acceso por módulo (ver, crear, editar…)
                </p>
              </div>
            </CardBody>
          </Card>
        </Link>
      </div>
    </div>
  )
}
