import type { InputHTMLAttributes, TextareaHTMLAttributes } from 'react'

const fieldClass =
  'w-full rounded-xl border border-slate-200 bg-white/90 px-3 py-2 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20'

const NON_TEXT_TYPES = new Set([
  'checkbox',
  'radio',
  'file',
  'hidden',
  'submit',
  'button',
  'image',
  'reset',
  'color',
  'range',
])

function resolvePlaceholder(
  explicit: string | undefined,
  props: {
    type?: string
    name?: string
    title?: string
    'aria-label'?: string
  },
): string | undefined {
  if (explicit !== undefined && explicit !== '') return explicit
  if (explicit === '') return ''
  const type = props.type ?? 'text'
  if (NON_TEXT_TYPES.has(type)) return undefined
  return props['aria-label'] || props.title || props.name || 'Escribe aquí'
}

export function Label({
  children,
  htmlFor,
}: {
  children: React.ReactNode
  htmlFor?: string
}) {
  return (
    <label htmlFor={htmlFor} className="mb-1 block text-sm font-medium text-slate-700">
      {children}
    </label>
  )
}

export function Input({
  className = '',
  placeholder,
  type = 'text',
  ...props
}: InputHTMLAttributes<HTMLInputElement>) {
  const resolved = resolvePlaceholder(placeholder, {
    type,
    name: props.name,
    title: props.title,
    'aria-label': props['aria-label'],
  })
  return (
    <input
      type={type}
      className={`${fieldClass} ${className}`}
      {...props}
      placeholder={resolved}
    />
  )
}

export function Select({
  className = '',
  children,
  disabled,
  ...props
}: React.SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <select
      className={`${fieldClass} disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 ${className}`}
      disabled={disabled}
      {...props}
    >
      {children}
    </select>
  )
}

export function Textarea({
  className = '',
  placeholder,
  ...props
}: TextareaHTMLAttributes<HTMLTextAreaElement>) {
  const resolved = resolvePlaceholder(placeholder, {
    name: props.name,
    title: props.title,
    'aria-label': props['aria-label'],
  })
  return (
    <textarea
      className={`${fieldClass} min-h-[100px] resize-y ${className}`}
      {...props}
      placeholder={resolved}
    />
  )
}
