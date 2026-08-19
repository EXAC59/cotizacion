import { apiFetch } from '@/lib/api-fetch'

import { ApiRequestError, formatApiErrorMessage, parseApiJson } from '@/lib/api-response'

import { getApiBase } from '@/lib/app-paths'



export type ComparatorWeights = {

  price: number

  stock: number

  warehouse: number

  performance: number

  preferred: number

}



export type WarehouseOption = {
  value: string
  label: string
  city?: string
  code?: string
  region?: string
}

export type WarehousesByWholesalerGroup = {
  wholesalerCode: string
  wholesalerName: string
  warehouses: WarehouseOption[]
}

export type ComparatorSettings = {
  weights: ComparatorWeights
  warehousePriority: string[]
  availableWarehouses?: WarehouseOption[]
  preferredWholesalerIds: string[]
  importPenalty: number
  leadDayPenalty: number
  minStockThreshold: number
  updatedAt?: string
}



export type UserComparatorPreferences = {

  preferredWarehouse: string

  /** Uno o varios almacenes/CEDIS preferidos (el primero = preferredWarehouse). */
  preferredWarehouses?: string[]

  autoApplyBest: boolean

  preferredWholesalerIds: string[]

  availableWarehouses?: WarehouseOption[]

  availableWarehousesByWholesaler?: WarehousesByWholesalerGroup[]

  updatedAt?: string | null

}



async function parseSettingsResponse<T>(response: Response): Promise<T> {

  const data = await parseApiJson<T & { message?: string; errors?: Record<string, string[] | string> }>(

    response,

  )

  if (!response.ok) {

    throw new ApiRequestError(formatApiErrorMessage(data, response.status), response.status, data.errors)

  }

  return data

}



export async function getComparatorSettings(): Promise<ComparatorSettings> {

  const response = await apiFetch(`${getApiBase()}/configuracion/comparador`)

  return parseSettingsResponse<ComparatorSettings>(response)

}



export async function updateComparatorSettings(

  payload: Partial<ComparatorSettings>,

): Promise<ComparatorSettings> {

  const response = await apiFetch(`${getApiBase()}/configuracion/comparador`, {

    method: 'PATCH',

    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },

    body: JSON.stringify(payload),

  })

  return parseSettingsResponse<ComparatorSettings>(response)

}



export async function getComparatorPreferences(): Promise<UserComparatorPreferences> {

  const response = await apiFetch(`${getApiBase()}/configuracion/comparador/preferencias`)

  return parseSettingsResponse<UserComparatorPreferences>(response)

}



export async function updateComparatorPreferences(

  payload: Partial<UserComparatorPreferences>,

): Promise<UserComparatorPreferences> {

  const response = await apiFetch(`${getApiBase()}/configuracion/comparador/preferencias`, {

    method: 'PATCH',

    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },

    body: JSON.stringify(payload),

  })

  return parseSettingsResponse<UserComparatorPreferences>(response)

}

