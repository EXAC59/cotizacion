import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, Trash2 } from 'lucide-react'
import { DeleteClientDialog } from '@/components/clients/DeleteClientDialog'
import { Button } from '@/components/ui/Button'
import { Card, CardBody } from '@/components/ui/Card'
import { Input, Label, Textarea } from '@/components/ui/Input'
import { InlineBusy, LoadingState } from '@/components/ui/LoadingState'
import { PageHeader } from '@/components/ui/PageHeader'
import { usePermission } from '@/hooks/usePermission'
import {
  ClientDeleteError,
  createClient,
  deleteClient,
  getClient,
  updateClient,
} from '@/lib/clients-api'
import type { Client } from '@/types'

const empty: Omit<Client, 'id' | 'createdAt'> = {
  company: '',
  rfc: '',
  address: '',
  contact: '',
  email: '',
  whatsapp: '',
  paymentTerms: '',
}

function normalizeRfc(value: string): string {
  return value.trim().toUpperCase().replace(/[\s.-]/g, '')
}

function clientToForm(client: Client): Omit<Client, 'id' | 'createdAt'> {
  return {
    company: client.company,
    rfc: client.rfc,
    address: client.address,
    contact: client.contact,
    email: client.email,
    whatsapp: client.whatsapp,
    paymentTerms: client.paymentTerms,
  }
}

function ClientFormEditor({
  clientId,
  returnTo,
}: {
  clientId: string | undefined
  returnTo: string | null
}) {
  const isNew = !clientId || clientId === 'nuevo'
  const navigate = useNavigate()
  const { can } = usePermission()
  const canEdit = can('clientes', 'edit')
  const canCreate = can('clientes', 'create')
  const canDelete = can('clientes', 'delete')
  const canSave = isNew ? canCreate : canEdit

  const [form, setForm] = useState(empty)
  const [companyName, setCompanyName] = useState('')
  const [quotesCount, setQuotesCount] = useState(0)
  const [loading, setLoading] = useState(!isNew)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [deleteOpen, setDeleteOpen] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [deleteError, setDeleteError] = useState<string | null>(null)

  useEffect(() => {
    if (isNew || !clientId) return

    let cancelled = false
    setLoading(true)

    getClient(clientId)
      .then((client) => {
        if (!cancelled) {
          setForm(clientToForm(client))
          setCompanyName(client.company)
          setQuotesCount(client.stats?.quotesCount ?? client.quotesCount ?? 0)
        }
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : 'No se pudo cargar el cliente.')
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [clientId, isNew])

  const update = (field: keyof typeof form, value: string) => {
    setForm((f) => ({ ...f, [field]: value }))
    setError(null)
  }

  const validateForm = (): string | null => {
    if (!form.company.trim()) {
      return 'La empresa / razón social es obligatoria.'
    }
    if (form.company.trim().length > 255) {
      return 'La empresa / razón social no puede exceder 255 caracteres.'
    }
    const normalizedRfc = normalizeRfc(form.rfc)
    if (normalizedRfc && !/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u.test(normalizedRfc)) {
      return 'El RFC no tiene un formato mexicano válido.'
    }
    if (form.contact.trim().length > 255) {
      return 'El contacto no puede exceder 255 caracteres.'
    }
    if (form.whatsapp.trim().length > 30) {
      return 'El número de WhatsApp no puede exceder 30 caracteres.'
    }
    if (form.email.trim().length > 255) {
      return 'El correo no puede exceder 255 caracteres.'
    }
    if (form.email.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) {
      return 'El correo no tiene un formato válido.'
    }
    if (form.paymentTerms.trim().length > 120) {
      return 'Las condiciones de pago no pueden exceder 120 caracteres.'
    }
    return null
  }

  const handleSave = async () => {
    const validationError = validateForm()
    if (validationError) {
      setError(validationError)
      return
    }
    if (!canSave) {
      setError('No tienes permiso para guardar este cliente.')
      return
    }

    setSaving(true)
    setError(null)
    const payload = {
      ...form,
      company: form.company.trim(),
      rfc: form.rfc.trim(),
      contact: form.contact.trim(),
      email: form.email.trim(),
      whatsapp: form.whatsapp.trim(),
    }
    try {
      if (isNew) {
        const saved = await createClient(payload)
        if (returnTo) {
          const separator = returnTo.includes('?') ? '&' : '?'
          navigate(`${returnTo}${separator}clientId=${encodeURIComponent(saved.id)}`)
        } else {
          navigate(`/clientes/${saved.id}`)
        }
      } else if (clientId) {
        await updateClient(clientId, payload)
        navigate(`/clientes/${clientId}`)
      }
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'No se pudo guardar.')
    } finally {
      setSaving(false)
    }
  }

  const handleConfirmDelete = async () => {
    if (!clientId || isNew || !canDelete) return
    setDeleting(true)
    setDeleteError(null)
    try {
      await deleteClient(clientId)
      navigate('/clientes')
    } catch (err: unknown) {
      if (err instanceof ClientDeleteError && (err.quotesCount ?? 0) > 0) {
        setQuotesCount(err.quotesCount ?? 0)
      }
      setDeleteError(err instanceof Error ? err.message : 'No se pudo eliminar.')
      setDeleting(false)
    }
  }

  if (loading) {
    return <LoadingState label="Cargando cliente…" variant="page" />
  }

  if (!isNew && !canEdit) {
    return (
      <div>
        <p className="mb-4 text-sm text-red-600">No tienes permiso para editar clientes.</p>
        <Link to={`/clientes/${clientId}`}>
          <Button variant="secondary" size="sm">
            Volver
          </Button>
        </Link>
      </div>
    )
  }

  if (isNew && !canCreate) {
    return (
      <div>
        <p className="mb-4 text-sm text-red-600">No tienes permiso para crear clientes.</p>
        <Link to="/clientes">
          <Button variant="secondary" size="sm">
            Volver
          </Button>
        </Link>
      </div>
    )
  }

  return (
    <>
      <PageHeader
        title={isNew ? 'Nuevo cliente' : 'Editar cliente'}
        actions={
          <Link to={returnTo ?? (isNew ? '/clientes' : `/clientes/${clientId}`)}>
            <Button variant="secondary" size="sm">
              <ArrowLeft className="h-4 w-4" />
              Volver
            </Button>
          </Link>
        }
      />

      {error && (
        <p className="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
          {error}
        </p>
      )}

      <Card className="max-w-2xl">
        <CardBody className="grid gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Label>Empresa *</Label>
            <Input
              value={form.company}
              onChange={(e) => update('company', e.target.value)}
              placeholder="Nombre de la empresa"
              maxLength={255}
              required
            />
          </div>
          <div>
            <Label>RFC</Label>
            <Input
              value={form.rfc}
              onChange={(e) => update('rfc', e.target.value)}
              placeholder="RFC de la empresa"
              maxLength={20}
              autoCapitalize="characters"
              spellCheck={false}
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Condiciones de pago</Label>
            <Textarea
              rows={3}
              value={form.paymentTerms}
              onChange={(e) => update('paymentTerms', e.target.value)}
              placeholder="Ej. 30 días, transferencia bancaria…"
              maxLength={120}
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Domicilio fiscal</Label>
            <Textarea
              value={form.address}
              onChange={(e) => update('address', e.target.value)}
              placeholder="Calle, número, colonia, CP, ciudad"
            />
          </div>
          <div>
            <Label>Contacto</Label>
            <Input
              value={form.contact}
              onChange={(e) => update('contact', e.target.value)}
              placeholder="Nombre del contacto"
              maxLength={255}
            />
          </div>
          <div>
            <Label>WhatsApp</Label>
            <Input
              value={form.whatsapp}
              onChange={(e) => update('whatsapp', e.target.value)}
              placeholder="Número de WhatsApp"
              maxLength={30}
              inputMode="tel"
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Correo</Label>
            <Input
              type="email"
              value={form.email}
              onChange={(e) => update('email', e.target.value)}
              placeholder="correo@empresa.com"
              maxLength={255}
            />
          </div>
          <div className="flex gap-2 pt-2 sm:col-span-2">
            <Button onClick={() => void handleSave()} disabled={saving || deleting}>
              {saving ? (
                <>
                  <InlineBusy size="sm" label="Guardando…" />
                </>
              ) : (
                'Guardar'
              )}
            </Button>
            {!isNew && canDelete && (
              <Button
                variant="danger"
                onClick={() => {
                  setDeleteError(null)
                  setDeleteOpen(true)
                }}
                disabled={saving || deleting}
              >
                <Trash2 className="h-4 w-4" />
                Eliminar
              </Button>
            )}
          </div>
        </CardBody>
      </Card>

      <DeleteClientDialog
        open={deleteOpen}
        company={companyName || form.company}
        quotesCount={quotesCount}
        deleting={deleting}
        error={deleteError}
        onClose={() => {
          if (!deleting) {
            setDeleteOpen(false)
            setDeleteError(null)
          }
        }}
        onConfirm={() => void handleConfirmDelete()}
      />
    </>
  )
}

export function ClientFormPage() {
  const { id } = useParams()
  const [search] = useSearchParams()
  const returnTo = search.get('returnTo')

  return (
    <ClientFormEditor
      key={`${id ?? 'nuevo'}-${returnTo ?? ''}`}
      clientId={id}
      returnTo={returnTo}
    />
  )
}
