export const DEFAULT_PREFERRED_WAREHOUSES = [
  'D2A',
  '53A',
  '35A',
  '01A',
  'CVA-46',
  'CVA-51',
  'CVA-54',
] as const

/** Opciones del selector “Almacén preferido” — toda la República (catálogo CT). */
export const PREFERRED_WAREHOUSE_OPTIONS = [
  { value: 'D2A', label: 'CEDIS Hermosillo (D2A)' },
  { value: 'CMT', label: 'CEDIS Monterrey (CMT)' },
  { value: 'CDMX', label: 'CDMX — Ciudad de México' },
  { value: 'HMO', label: 'HMO — Hermosillo' },
  { value: 'GDL', label: 'GDL — Guadalajara' },
  { value: 'QRO', label: 'QRO — Querétaro' },
  { value: 'PUE', label: 'PUE — Puebla' },
  { value: 'SLP', label: 'SLP — San Luis Potosí' },
  { value: 'AGS', label: 'AGS — Aguascalientes' },
  { value: 'LEO', label: 'LEO — León' },
  { value: 'VER', label: 'VER — Veracruz' },
  { value: 'MID', label: 'MID — Mérida' },
  { value: 'CUN', label: 'CUN — Cancún' },
  { value: 'ACA', label: 'ACA — Acapulco' },
  { value: 'CAM', label: 'CAM — Campeche' },
  { value: 'CEL', label: 'CEL — Celaya' },
  { value: 'CHI', label: 'CHI — Chihuahua' },
  { value: 'OBR', label: 'OBR — Ciudad Obregón' },
  { value: 'CDV', label: 'CDV — Ciudad Victoria' },
  { value: 'CTZ', label: 'CTZ — Coatzacoalcos' },
  { value: 'COL', label: 'COL — Colima' },
  { value: 'CUE', label: 'CUE — Cuernavaca' },
  { value: 'CLN', label: 'CLN — Culiacán' },
  { value: 'DGO', label: 'DGO — Durango' },
  { value: 'IRA', label: 'IRA — Irapuato' },
  { value: 'LMO', label: 'LMO — Los Mochis' },
  { value: 'MAZ', label: 'MAZ — Mazatlán' },
  { value: 'MOR', label: 'MOR — Morelia' },
  { value: 'OAX', label: 'OAX — Oaxaca' },
  { value: 'PAC', label: 'PAC — Pachuca' },
  { value: 'SLT', label: 'SLT — Saltillo' },
  { value: 'TAM', label: 'TAM — Tampico' },
  { value: 'TPC', label: 'TPC — Tepic' },
  { value: 'TXL', label: 'TXL — Tlaxcala' },
  { value: 'TOL', label: 'TOL — Toluca' },
  { value: 'TRN', label: 'TRN — Torreón' },
  { value: 'TXA', label: 'TXA — Tuxtla Gutiérrez' },
  { value: 'URP', label: 'URP — Uruapan' },
  { value: 'VHA', label: 'VHA — Villahermosa' },
  { value: 'XLP', label: 'XLP — Xalapa' },
  { value: 'ZAC', label: 'ZAC — Zacatecas' },
  { value: 'MTY', label: 'MTY — Monterrey (plaza)' },
] as const

export type PreferredWarehouseCode = (typeof PREFERRED_WAREHOUSE_OPTIONS)[number]['value']

export type WarehouseSelectOption = { value: string; label: string }

export type WarehouseOptionGroup = {
  label: string
  options: WarehouseSelectOption[]
}

/** Une opciones del API (backend) con el fallback local; garantiza CEDIS CMT/D2A. */
export function mergeWarehouseOptions(
  fromApi?: WarehouseSelectOption[] | null,
): WarehouseSelectOption[] {
  const base: WarehouseSelectOption[] =
    fromApi && fromApi.length > 0
      ? fromApi.map((o) => ({ value: o.value, label: o.label }))
      : PREFERRED_WAREHOUSE_OPTIONS.map((o) => ({ value: o.value, label: o.label }))

  const byValue = new Map(base.map((o) => [o.value.toUpperCase(), o]))

  // Etiquetas claras y presencia forzada de CEDIS.
  byValue.set('CMT', { value: 'CMT', label: 'CEDIS Monterrey (CMT)' })
  byValue.set('D2A', { value: 'D2A', label: 'CEDIS Hermosillo (D2A)' })

  const hubs = ['D2A', 'CMT', 'CDMX', 'HMO', 'GDL']
  const ordered: WarehouseSelectOption[] = []
  for (const hub of hubs) {
    const row = byValue.get(hub)
    if (row) {
      ordered.push(row)
      byValue.delete(hub)
    }
  }
  // Plaza Monterrey al final (no preferida por defecto).
  const mty = byValue.get('MTY')
  byValue.delete('MTY')

  const rest = [...byValue.values()].sort((a, b) => a.label.localeCompare(b.label, 'es'))
  return mty ? [...ordered, ...rest, mty] : [...ordered, ...rest]
}

/** Agrupa para mostrar varias opciones a la vez (CEDIS / hubs / resto). */
export function groupWarehouseOptions(
  options: WarehouseSelectOption[],
): WarehouseOptionGroup[] {
  const byValue = new Map(options.map((o) => [o.value.toUpperCase(), o]))
  const take = (codes: string[]) => {
    const rows: WarehouseSelectOption[] = []
    for (const code of codes) {
      const row = byValue.get(code)
      if (row) {
        rows.push(row)
        byValue.delete(code)
      }
    }
    return rows
  }

  const cedis = take(['D2A', 'CMT'])
  const hubs = take(['CDMX', 'HMO', 'GDL', 'QRO', 'PUE'])
  const rest = [...byValue.values()].sort((a, b) => a.label.localeCompare(b.label, 'es'))

  const groups: WarehouseOptionGroup[] = []
  if (cedis.length) groups.push({ label: 'CEDIS', options: cedis })
  if (hubs.length) groups.push({ label: 'Principales', options: hubs })
  if (rest.length) groups.push({ label: 'Toda la República', options: rest })
  return groups
}

export const NATIONAL_WAREHOUSE_PRIORITY_CSV = PREFERRED_WAREHOUSE_OPTIONS.map((o) => o.value).join(', ')
