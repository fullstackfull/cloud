import type { ButtonHTMLAttributes, ReactNode } from 'react'

import { buttonClasses, type ButtonSize, type ButtonVariant } from '@/components/buttonStyles'
import { Spinner } from '@/components/Spinner'

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant
  size?: ButtonSize
  loading?: boolean
  children: ReactNode
}

export function Button({
  variant = 'primary',
  size = 'md',
  loading = false,
  disabled,
  className,
  children,
  ...props
}: ButtonProps) {
  return (
    <button
      // Disabled while loading, so a slow provisioning request cannot be
      // submitted twice by an impatient click.
      disabled={disabled === true || loading}
      aria-busy={loading}
      className={buttonClasses(variant, size, className)}
      {...props}
    >
      {loading ? <Spinner /> : null}
      {children}
    </button>
  )
}
