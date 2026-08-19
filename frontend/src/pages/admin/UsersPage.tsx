import { Link } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { LoadingState, TableSkeleton } from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { useRbac } from '@/hooks/useRbac'
import { ROLE_LABELS } from '@/types'
import { formatDate } from '@/lib/format'

export function UsersPage() {
  const { users, usersLoading } = useRbac()

  return (
    <div>
      <PageHeader
        title="Usuarios"
        description="Alta y gestión de cuentas del sistema"
        actions={
          <Link to="/admin/usuarios/nuevo">
            <Button size="sm">
              <Plus className="h-4 w-4" />
              Nuevo usuario
            </Button>
          </Link>
        }
      />

      <Card>
        <CardBody className="p-0">
          {usersLoading ? (
            <div>
              <LoadingState label="Cargando usuarios…" variant="inline" className="py-8" />
              <TableSkeleton rows={5} cols={6} />
            </div>
          ) : users.length === 0 ? (
            <p className="px-5 py-8 text-center text-sm text-slate-500">No hay usuarios registrados.</p>
          ) : (
          <table className="w-full text-left text-sm">
            <thead className="border-b bg-slate-50 text-slate-500">
              <tr>
                <th className="px-5 py-3 font-medium">Nombre</th>
                <th className="px-5 py-3 font-medium">Usuario</th>
                <th className="px-5 py-3 font-medium">Correo</th>
                <th className="px-5 py-3 font-medium">Código folio</th>
                <th className="px-5 py-3 font-medium">Rol</th>
                <th className="px-5 py-3 font-medium">Estado</th>
                <th className="px-5 py-3 font-medium">Firma</th>
                <th className="px-5 py-3 font-medium">Alta</th>
              </tr>
            </thead>
            <tbody>
              {users.map((u) => (
                <tr key={u.id} className="border-b border-slate-50 hover:bg-slate-50/80">
                  <td className="px-5 py-3">
                    <Link
                      to={`/admin/usuarios/${u.id}`}
                      className="font-medium text-indigo-600 hover:underline"
                    >
                      {u.name}
                    </Link>
                  </td>
                  <td className="px-5 py-3 font-mono text-xs text-slate-700">{u.username ?? '—'}</td>
                  <td className="px-5 py-3 text-slate-600">{u.email}</td>
                  <td className="px-5 py-3 font-mono text-xs text-slate-700">
                    {u.folioCode ?? '—'}
                  </td>
                  <td className="px-5 py-3">{ROLE_LABELS[u.role]}</td>
                  <td className="px-5 py-3">
                    <Badge variant={u.active ? 'success' : 'muted'}>
                      {u.active ? 'Activo' : 'Inactivo'}
                    </Badge>
                  </td>
                  <td className="px-5 py-3">
                    <Badge variant={u.hasSignature ? 'success' : 'muted'}>
                      {u.hasSignature ? 'Con firma' : 'Sin firma'}
                    </Badge>
                  </td>
                  <td className="px-5 py-3 text-slate-500">{formatDate(u.createdAt)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          )}
        </CardBody>
      </Card>
    </div>
  )
}
