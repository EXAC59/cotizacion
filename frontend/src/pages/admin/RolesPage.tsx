import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowLeft, RotateCcw, Save } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody, CardHeader } from '@/components/ui/Card'
import { InlineBusy, LoadingState } from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { useRbac } from '@/hooks/useRbac'
import { ROLE_LABELS, type UserRole } from '@/types'
import {
  ACTION_LABELS,
  MODULE_ACTIONS,
  MODULE_IDS,
  MODULE_LABELS,
  type ModuleId,
  type PermissionAction,
  type RolePermissionMap,
} from '@/types/rbac'

const ROLES = Object.keys(ROLE_LABELS) as UserRole[]

function RolesMatrixEditor({
  initial,
  onSave,
  onResetRequest,
}: {
  initial: RolePermissionMap
  onSave: (draft: RolePermissionMap) => Promise<void>
  onResetRequest: () => Promise<void>
}) {
  const [draft, setDraft] = useState(initial)
  const [saved, setSaved] = useState(true)
  const [saving, setSaving] = useState(false)
  const [resetting, setResetting] = useState(false)
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null)

  useEffect(() => {
    setDraft(initial)
    setSaved(true)
  }, [initial])

  const toggle = (role: UserRole, module: ModuleId, action: PermissionAction) => {
    if (role === 'administrador') return
    setSaved(false)
    setMessage(null)
    setDraft((prev) => {
      const current = [...(prev[role][module] ?? [])]
      const next = current.includes(action)
        ? current.filter((a) => a !== action)
        : [...current, action]
      return {
        ...prev,
        [role]: { ...prev[role], [module]: next },
      }
    })
  }

  const hasAction = (role: UserRole, module: ModuleId, action: PermissionAction) =>
    draft[role][module]?.includes(action) ?? false

  const handleSave = async () => {
    setSaving(true)
    setMessage(null)
    try {
      await onSave(draft)
      setSaved(true)
      setMessage({ type: 'success', text: 'Permisos guardados correctamente.' })
    } catch (err: unknown) {
      setMessage({
        type: 'error',
        text: err instanceof Error ? err.message : 'No se pudieron guardar los permisos.',
      })
    } finally {
      setSaving(false)
    }
  }

  const handleReset = async () => {
    if (!confirm('¿Restaurar permisos por defecto de todos los roles?')) return

    setResetting(true)
    setMessage(null)
    try {
      await onResetRequest()
      setSaved(true)
      setMessage({ type: 'success', text: 'Permisos restaurados a valores por defecto.' })
    } catch (err: unknown) {
      setMessage({
        type: 'error',
        text: err instanceof Error ? err.message : 'No se pudieron restaurar los permisos.',
      })
    } finally {
      setResetting(false)
    }
  }

  return (
    <>
      {!saved && (
        <p className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900">
          Tienes cambios sin guardar.
        </p>
      )}

      {message && (
        <p
          className={`mb-4 rounded-lg border px-4 py-2 text-sm ${
            message.type === 'success'
              ? 'border-emerald-200 bg-emerald-50 text-emerald-900'
              : 'border-red-200 bg-red-50 text-red-900'
          }`}
        >
          {message.text}
        </p>
      )}

      <Card>
        <CardHeader
          title="Matriz de permisos"
          subtitle="El rol Administrador siempre tiene acceso total"
        />
        <CardBody className="overflow-x-auto p-0">
          <table className="w-full min-w-[900px] text-left text-sm">
            <thead className="border-b bg-slate-50 text-slate-500">
              <tr>
                <th className="sticky left-0 z-10 bg-slate-50 px-4 py-3 font-medium">Rol</th>
                {MODULE_IDS.map((m) => (
                  <th key={m} className="px-3 py-3 font-medium">
                    {MODULE_LABELS[m]}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {ROLES.map((role) => (
                <tr key={role} className="border-b border-slate-50">
                  <td className="sticky left-0 z-10 bg-white px-4 py-3 font-medium text-slate-900">
                    {ROLE_LABELS[role]}
                    {role === 'administrador' && (
                      <span className="mt-0.5 block text-xs font-normal text-slate-400">
                        Acceso completo
                      </span>
                    )}
                  </td>
                  {MODULE_IDS.map((module) => (
                    <td key={module} className="px-3 py-3 align-top">
                      <div className="flex flex-col gap-1">
                        {MODULE_ACTIONS[module].map((action) => (
                          <label
                            key={action}
                            className={`flex items-center gap-1.5 text-xs ${
                              role === 'administrador' ? 'opacity-50' : ''
                            }`}
                          >
                            <input
                              type="checkbox"
                              checked={
                                role === 'administrador' || hasAction(role, module, action)
                              }
                              disabled={role === 'administrador' || saving || resetting}
                              onChange={() => toggle(role, module, action)}
                              className="rounded border-slate-300"
                            />
                            {ACTION_LABELS[action]}
                          </label>
                        ))}
                      </div>
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </CardBody>
      </Card>

      <div className="mt-4 flex gap-2">
        <Button size="sm" onClick={handleSave} disabled={saved || saving || resetting}>
          {saving ? <InlineBusy size="sm" /> : <Save className="h-4 w-4" />}
          Guardar cambios
        </Button>
        <Button
          variant="secondary"
          size="sm"
          onClick={handleReset}
          disabled={saving || resetting}
        >
          {resetting ? (
            <InlineBusy size="sm" />
          ) : (
            <RotateCcw className="h-4 w-4" />
          )}
          Restaurar valores por defecto
        </Button>
      </div>
    </>
  )
}

export function RolesPage() {
  const {
    rolePermissions,
    replaceRolePermissions,
    resetRolePermissions,
    permissionsLoading,
    permissionsSource,
  } = useRbac()

  return (
    <div>
      <PageHeader
        title="Roles y permisos"
        description="Define qué puede hacer cada rol en cada módulo"
        actions={
          <Link to="/admin">
            <Button variant="secondary" size="sm">
              <ArrowLeft className="h-4 w-4" />
              Panel admin
            </Button>
          </Link>
        }
      />

      {permissionsLoading ? (
        <Card>
          <CardBody>
            <LoadingState label="Cargando matriz de permisos…" variant="section" />
          </CardBody>
        </Card>
      ) : (
        <>
          {permissionsSource === 'local' && (
            <p className="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-2 text-sm text-slate-700">
              Usando permisos locales. Si el servidor está disponible, ejecuta las migraciones para
              persistir cambios en la base de datos.
            </p>
          )}

          <RolesMatrixEditor
            initial={rolePermissions}
            onSave={replaceRolePermissions}
            onResetRequest={resetRolePermissions}
          />
        </>
      )}
    </div>
  )
}
