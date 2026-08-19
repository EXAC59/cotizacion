import type { KeyboardEvent } from 'react'

export function preventNegativeNumberKey(
  event: KeyboardEvent<HTMLInputElement>,
): void {
  if (
    event.key === '-' ||
    event.key === '+' ||
    event.key === 'e' ||
    event.key === 'E' ||
    event.key === 'ArrowUp' ||
    event.key === 'ArrowDown' ||
    event.code === 'Minus' ||
    event.code === 'NumpadSubtract' ||
    event.code === 'ArrowUp' ||
    event.code === 'ArrowDown'
  ) {
    event.preventDefault()
  }
}

/** @deprecated Use preventNegativeNumberKey */
export const preventNegativeQuantityKey = preventNegativeNumberKey

/** Solo dígitos enteros positivos (vacío permitido mientras se edita). */
export function sanitizeQuantityInput(raw: string): string {
  return raw.replace(/\D/g, '')
}

/** Dígitos y un punto decimal; sin signo negativo. */
export function sanitizePositiveDecimalInput(raw: string): string {
  let cleaned = raw.replace(/-/g, '').replace(/[^\d.]/g, '')
  const parts = cleaned.split('.')
  if (parts.length > 2) {
    cleaned = `${parts[0]}.${parts.slice(1).join('')}`
  }
  return cleaned
}
