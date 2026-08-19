import type { ButtonHTMLAttributes, ReactNode } from 'react'

const variants = {
  primary:
    'bg-indigo-600 text-white hover:bg-indigo-700 shadow-[0_8px_20px_-10px_rgb(79_70_229/0.85)]',
  secondary:
    'bg-white/90 text-slate-700 border border-slate-200 hover:bg-slate-50 hover:border-slate-300 shadow-sm',
  ghost: 'text-slate-600 hover:bg-slate-100/80',
  danger: 'bg-red-600 text-white hover:bg-red-700 shadow-[0_8px_20px_-10px_rgb(220_38_38/0.7)]',
} as const

const sizes = {
  sm: 'px-3 py-1.5 text-sm',
  md: 'px-4 py-2 text-sm',
  lg: 'px-5 py-2.5',
} as const

export function Button({
  children,
  variant = 'primary',
  size = 'md',
  className = '',
  type = 'button',
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
  children: ReactNode
  variant?: keyof typeof variants
  size?: keyof typeof sizes
}) {
  return (
    <button
      type={type}
      className={`inline-flex items-center justify-center gap-2 rounded-xl font-medium transition-[color,background-color,box-shadow,transform] duration-200 active:scale-[0.98] disabled:opacity-50 disabled:pointer-events-none ${variants[variant]} ${sizes[size]} ${className}`}
      {...props}
    >
      {children}
    </button>
  )
}
