import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Eye, EyeOff, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { Input, Label, Select } from '@/components/ui/Input'
import { PageHeader } from '@/components/ui/PageHeader'
import { LoadingState } from '@/components/ui/LoadingState'
import { useAuth } from '@/hooks/useAuth'
import { useRbac } from '@/hooks/useRbac'
import { apiFetch } from '@/lib/api-fetch'
import { ROLE_LABELS, type UserRole } from '@/types'
import type { StoredUser } from '@/types/rbac'

const ROLES = Object.keys(ROLE_LABELS) as UserRole[]

function normalizeFolioCode(raw: string): string {
  return raw
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, '')
    .slice(0, 12)
}

function deriveFolioCodeFromName(name: string): string {
  const firstWord = name.trim().split(/\s+/)[0] ?? ''
  const fromFirstWord = normalizeFolioCode(firstWord)
  return fromFirstWord.length >= 2 ? fromFirstWord : ''
}

function normalizeUsername(raw: string): string {
  return raw.trim().toLowerCase().replace(/[^a-z0-9._-]/g, '').slice(0, 64)
}

function PasswordField({
  id,
  label,
  value,
  onChange,
  placeholder,
  autoComplete,
  required,
}: {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
  placeholder: string
  autoComplete: string
  required?: boolean
}) {
  const [show, setShow] = useState(false)

  return (
    <div>
      <Label htmlFor={id}>{label}</Label>
      <div className="relative">
        <Input
          id={id}
          type={show ? 'text' : 'password'}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder={placeholder}
          autoComplete={autoComplete}
          className="pr-10"
          required={required}
        />
        <button
          type="button"
          onClick={() => setShow((v) => !v)}
          className="absolute inset-y-0 right-0 flex items-center px-3 text-slate-500 hover:text-slate-800"
          aria-label={show ? 'Ocultar contraseña' : 'Mostrar contraseña'}
          tabIndex={-1}
        >
          {show ? <EyeOff className="h-4 w-4" aria-hidden /> : <Eye className="h-4 w-4" aria-hidden />}
        </button>
      </div>
    </div>
  )
}

function UserFormEditor({ userId }: { userId: string | undefined }) {
  const isNew = !userId || userId === 'nuevo'
  const navigate = useNavigate()
  const { user: currentUser } = useAuth()
  const { users, usersLoading, saveUser, deleteUser } = useRbac()
  const existing = !isNew && userId ? users.find((u) => u.id === userId) : undefined

  const [name, setName] = useState(() => existing?.name ?? '')
  const [username, setUsername] = useState(() => existing?.username ?? '')
  const [email, setEmail] = useState(() => existing?.email ?? '')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [role, setRole] = useState<UserRole>(() => existing?.role ?? 'ventas')
  const [active, setActive] = useState(() => existing?.active ?? true)
  const [signatureFile, setSignatureFile] = useState<File | null>(null)
  const [signaturePreview, setSignaturePreview] = useState<string | null>(null)
  const [removeSignature, setRemoveSignature] = useState(false)
  const [hydratedId, setHydratedId] = useState<string | null>(() => existing?.id ?? null)

  // Si la lista de usuarios llega después del mount, rellenar el formulario.
  useEffect(() => {
    if (!existing || hydratedId === existing.id) return
    setName(existing.name ?? '')
    setUsername(existing.username ?? '')
    setEmail(existing.email ?? '')
    setRole(existing.role ?? 'ventas')
    setActive(existing.active ?? true)
    if (!signatureFile) {
      setSignaturePreview(null)
      setRemoveSignature(false)
    }
    setHydratedId(existing.id)
  }, [existing, hydratedId, signatureFile])

  useEffect(() => {
    if (!signatureFile) return
    const url = URL.createObjectURL(signatureFile)
    setSignaturePreview(url)
    return () => URL.revokeObjectURL(url)
  }, [signatureFile])

  // Firma remota vía API autenticada (no URL pública /storage).
  useEffect(() => {
    if (signatureFile || removeSignature) return
    const remote = existing?.signatureUrl
    if (!remote) {
      setSignaturePreview(null)
      return
    }
    if (remote.startsWith('blob:') || remote.startsWith('data:')) {
      setSignaturePreview(remote)
      return
    }

    let cancelled = false
    let objectUrl: string | null = null

    ;(async () => {
      try {
        const res = await apiFetch(remote)
        if (!res.ok || cancelled) return
        const blob = await res.blob()
        if (cancelled) return
        objectUrl = URL.createObjectURL(blob)
        setSignaturePreview(objectUrl)
      } catch {
        if (!cancelled) setSignaturePreview(null)
      }
    })()

    return () => {
      cancelled = true
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [existing?.signatureUrl, signatureFile, removeSignature])

  // Solo primer nombre → código de folio (no editable).
  const folioCode = deriveFolioCodeFromName(name)
  const folioPreview = folioCode ? `COT-${folioCode}-0001` : ''

  const handleSignatureChange = (file: File | null) => {
    if (!file) {
      setSignatureFile(null)
      return
    }
    if (!/^image\/(png|jpeg|jpg|webp)$/i.test(file.type) && !/\.(png|jpe?g|webp)$/i.test(file.name)) {
      alert('Usa una imagen PNG, JPG o WEBP (máx. 2 MB).')
      return
    }
    if (file.size > 2 * 1024 * 1024) {
      alert('La firma no debe superar 2 MB.')
      return
    }
    setRemoveSignature(false)
    setSignatureFile(file)
  }

  const handleRemoveSignature = () => {
    setSignatureFile(null)
    setSignaturePreview(null)
    setRemoveSignature(Boolean(existing?.hasSignature || existing?.signatureUrl))
  }

  const handleSave = async () => {
    const finalUsername = normalizeUsername(username)
    if (!name.trim() || !finalUsername) {
      alert('Nombre y usuario son obligatorios')
      return
    }
    if (finalUsername.length < 2) {
      alert('El usuario debe tener al menos 2 caracteres')
      return
    }
    if (isNew && !password.trim()) {
      alert('Define una contraseña para el usuario nuevo')
      return
    }
    if (password.trim() !== '' && password.trim().length < 8) {
      alert('La contraseña debe tener al menos 8 caracteres')
      return
    }
    if (password.trim() !== '' && password !== passwordConfirmation) {
      alert('La contraseña y su confirmación no coinciden')
      return
    }
    const finalFolioCode = folioCode
    if (finalFolioCode.length < 2) {
      alert('El primer nombre debe tener al menos 2 letras o números para el folio.')
      return
    }
    const duplicateFolio = users.find(
      (u) => (u.folioCode ?? '').toUpperCase() === finalFolioCode && u.id !== existing?.id,
    )
    if (duplicateFolio) {
      alert('Ya existe un usuario con ese código de folio (mismo primer nombre). Cambia el nombre completo.')
      return
    }
    const duplicateUsername = users.find(
      (u) => (u.username ?? '').toLowerCase() === finalUsername && u.id !== existing?.id,
    )
    if (duplicateUsername) {
      alert('Ya existe un usuario con ese nombre de usuario')
      return
    }
    if (email.trim() && !isNew) {
      const duplicateEmail = users.find(
        (u) => u.email.toLowerCase() === email.trim().toLowerCase() && u.id !== existing?.id,
      )
      if (duplicateEmail) {
        alert('Ya existe un usuario con ese correo')
        return
      }
    }

    const stored: StoredUser = {
      id: existing?.id ?? `u${Date.now()}`,
      name: name.trim(),
      username: finalUsername,
      email: email.trim().toLowerCase(),
      folioCode: finalFolioCode,
      role,
      password: password.trim(),
      passwordConfirmation: passwordConfirmation.trim(),
      active,
      signatureFile,
      removeSignature: removeSignature && !signatureFile,
      createdAt: existing?.createdAt ?? new Date().toISOString(),
    }
    try {
      await saveUser(stored)
      navigate('/admin/usuarios')
    } catch (err) {
      alert(err instanceof Error ? err.message : 'No se pudo guardar el usuario')
    }
  }

  const handleDelete = async () => {
    if (!existing) return
    if (existing.id === currentUser?.id) {
      alert('No puedes eliminar tu propia cuenta')
      return
    }
    if (confirm(`¿Eliminar a ${existing.name}?`)) {
      try {
        await deleteUser(existing.id)
        navigate('/admin/usuarios')
      } catch (err) {
        alert(err instanceof Error ? err.message : 'No se pudo eliminar el usuario')
      }
    }
  }

  if (!isNew && usersLoading && !existing) {
    return (
      <>
        <PageHeader title="Editar usuario" />
        <LoadingState label="Cargando usuario…" variant="section" />
      </>
    )
  }

  if (!isNew && !usersLoading && !existing) {
    return (
      <>
        <PageHeader
          title="Editar usuario"
          actions={
            <Link to="/admin/usuarios">
              <Button variant="secondary" size="sm">
                <ArrowLeft className="h-4 w-4" />
                Volver
              </Button>
            </Link>
          }
        />
        <p className="text-sm text-red-600">Usuario no encontrado.</p>
      </>
    )
  }

  return (
    <>
      <PageHeader
        title={isNew ? 'Nuevo usuario' : 'Editar usuario'}
        actions={
          <Link to="/admin/usuarios">
            <Button variant="secondary" size="sm">
              <ArrowLeft className="h-4 w-4" />
              Volver
            </Button>
          </Link>
        }
      />

      <Card className="max-w-lg">
        <CardBody className="space-y-4">
          <div>
            <Label htmlFor="user-name">Nombre completo</Label>
            <Input
              id="user-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Nombre completo"
            />
            <p className="mt-2 text-xs text-slate-500">
              Folio que usarán sus cotizaciones (primer nombre):
            </p>
            <p
              id="user-folio"
              className="mt-0.5 font-mono text-base font-semibold tracking-wide text-indigo-700"
              aria-live="polite"
            >
              {folioPreview || 'Escribe el nombre para ver el folio…'}
            </p>
          </div>
          <div>
            <Label htmlFor="user-username">Nombre de usuario (inicio de sesión)</Label>
            <Input
              id="user-username"
              value={username}
              onChange={(e) => setUsername(normalizeUsername(e.target.value))}
              placeholder="ej. admin, erikita.mtz"
              autoComplete="off"
            />
            <p className="mt-1 text-xs text-slate-500">
              Con este usuario entrará al sistema. Solo letras, números, punto, guion y guion bajo.
            </p>
          </div>
          <div>
            <Label htmlFor="user-email">Correo (opcional)</Label>
            <Input
              id="user-email"
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="opcional@empresa.com"
            />
          </div>
          <div>
            <Label htmlFor="user-signature">Firma electrónica</Label>
            <p className="mb-2 text-xs text-slate-500">
              Imagen PNG/JPG/WEBP (máx. 2 MB). Se usa en el PDF de cotizaciones creadas por este
              usuario. Ideal con fondo transparente.
            </p>
            <Input
              id="user-signature"
              type="file"
              accept="image/png,image/jpeg,image/jpg,image/webp,.png,.jpg,.jpeg,.webp"
              onChange={(e) => handleSignatureChange(e.target.files?.[0] ?? null)}
            />
            {signaturePreview && (
              <div className="mt-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                <img
                  src={signaturePreview}
                  alt="Vista previa de la firma"
                  className="mx-auto max-h-24 object-contain"
                />
              </div>
            )}
            {(signaturePreview || existing?.hasSignature) && (
              <button
                type="button"
                onClick={handleRemoveSignature}
                className="mt-2 text-sm text-red-600 hover:underline"
              >
                Quitar firma
              </button>
            )}
            {removeSignature && !signatureFile && (
              <p className="mt-1 text-xs text-amber-700">La firma se eliminará al guardar.</p>
            )}
          </div>
          <PasswordField
            id="user-password"
            label={isNew ? 'Contraseña' : 'Nueva contraseña (opcional)'}
            value={password}
            onChange={setPassword}
            placeholder={isNew ? 'Contraseña' : 'Dejar vacío para no cambiar'}
            autoComplete="new-password"
            required={isNew}
          />
          <PasswordField
            id="user-password-confirmation"
            label="Confirmar contraseña"
            value={passwordConfirmation}
            onChange={setPasswordConfirmation}
            placeholder="Repite la contraseña"
            autoComplete="new-password"
            required={isNew || password.trim() !== ''}
          />
          <div>
            <Label htmlFor="user-role">Rol</Label>
            <Select
              id="user-role"
              value={role}
              onChange={(e) => setRole(e.target.value as UserRole)}
            >
              {ROLES.map((r) => (
                <option key={r} value={r}>
                  {ROLE_LABELS[r]}
                </option>
              ))}
            </Select>
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={active}
              onChange={(e) => setActive(e.target.checked)}
              className="rounded border-slate-300"
            />
            Usuario activo
          </label>
          <div className="flex gap-2 pt-2">
            <Button onClick={handleSave}>Guardar</Button>
            {!isNew && (
              <Button variant="danger" onClick={handleDelete}>
                <Trash2 className="h-4 w-4" />
                Eliminar
              </Button>
            )}
          </div>
        </CardBody>
      </Card>
    </>
  )
}

export function UserFormPage() {
  const { id } = useParams()
  return <UserFormEditor key={id ?? 'nuevo'} userId={id} />
}
