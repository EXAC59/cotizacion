/**
 * Alias BODEGAxx para personal de ventas (no mostrar nombre real del mayorista).
 * Espejo de config/wholesaler_sales_aliases.php — mantener sincronizado.
 *
 * Al integrar un mayorista nuevo: asignar el siguiente BODEGAxx y avisar.
 */

const SALES_ALIAS_BY_CODE: Record<string, string> = {
  CT: 'BODEGA01',
  CVA: 'BODEGA02',
  EXEL: 'BODEGA03',
  INGRAM: 'BODEGA04',
  ASC: 'BODEGA05',
  SYSCOM: 'BODEGA06',
  TEAM: 'BODEGA07',
  AZERTY: 'BODEGA08',
  EVERTEK: 'BODEGA09',
  CALCOM: 'BODEGA10',
  ROWAN: 'BODEGA11',
  EPCOM: 'BODEGA12',
  TVC: 'BODEGA13',
  TECHDATA: 'BODEGA14',
  AMAZON: 'BODEGA15',
  EBAY: 'BODEGA16',
  INTCOMEX: 'BODEGA17',
  TECNOSINERGIA: 'BODEGA18',
  INTTELEC: 'BODEGA19',
  PCH: 'BODEGA20',
  MALABS: 'BODEGA21',
  DC: 'BODEGA22',
  DAISYYEK: 'BODEGA23',
  INALARM: 'BODEGA24',
}

const NAME_HINTS: Array<{ match: RegExp; code: string }> = [
  { match: /\bct\b|internacional/i, code: 'CT' },
  { match: /\bcva\b/i, code: 'CVA' },
  { match: /\bexel\b|\bexcel\b/i, code: 'EXEL' },
  { match: /\bingram\b/i, code: 'INGRAM' },
  { match: /\basc\b|\basi\b/i, code: 'ASC' },
]

export function shouldHideWholesalerNames(role?: string | null): boolean {
  return role === 'ventas'
}

export function wholesalerSalesAlias(
  code?: string | null,
  name?: string | null,
): string {
  const normalized = (code ?? '').trim().toUpperCase()
  if (normalized && SALES_ALIAS_BY_CODE[normalized]) {
    return SALES_ALIAS_BY_CODE[normalized]
  }

  const label = (name ?? '').trim()
  if (label) {
    for (const hint of NAME_HINTS) {
      if (hint.match.test(label)) {
        return SALES_ALIAS_BY_CODE[hint.code] ?? 'BODEGA'
      }
    }
    const upper = label.toUpperCase()
    if (upper.startsWith('BODEGA')) {
      return label
    }
  }

  return 'BODEGA'
}

export function displayWholesalerName(params: {
  role?: string | null
  code?: string | null
  name?: string | null
}): string {
  const { role, code, name } = params
  if (!shouldHideWholesalerNames(role)) {
    return (name ?? code ?? 'Mayorista').trim() || 'Mayorista'
  }
  return wholesalerSalesAlias(code, name)
}

/** Etiqueta de almacén para ventas: con ciudad, sin nombre del mayorista. */
export function displayWarehouseLabel(params: {
  role?: string | null
  warehouse?: string | null
}): string {
  const raw = (params.warehouse ?? '').trim()
  if (!raw) return '—'
  if (!shouldHideWholesalerNames(params.role)) return raw

  // Si ya viene enmascarado desde API con ciudad, respétalo.
  if (/^Almacén\b/i.test(raw)) return raw

  const upper = raw.toUpperCase()
  const codeMatch = raw.match(/\b([0-9]{2}A|D2A)\b/i)
  const code = (codeMatch?.[1] ?? '').toUpperCase()

  const cedisByCode: Record<string, string> = {
    '53A': 'Monterrey',
    D2A: 'Hermosillo',
    '35A': 'Azcapotzalco',
  }
  if (code && cedisByCode[code]) {
    return `Almacén CEDIS ${cedisByCode[code]}`
  }

  if (upper.includes('CEDIS')) {
    const place = raw
      .replace(/^.*CEDIS\s+/i, '')
      .replace(/\s*\([^)]*\)\s*$/, '')
      .trim()
    return place ? `Almacén CEDIS ${place}` : 'Almacén CEDIS'
  }

  // Plazas / sucursales: usa el nombre de ciudad del label si viene.
  const cityMatch = raw.match(/^([^-(]+)/)
  const city = (cityMatch?.[1] ?? raw).trim()
  if (city) return `Almacén ${city}`

  return `Almacén ${code || '—'}`
}
