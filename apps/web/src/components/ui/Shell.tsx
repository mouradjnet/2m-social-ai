import type { ReactNode } from 'react'

export function Shell({ children }: { children: ReactNode }) {
  return <main className="mx-auto max-w-(--container-shell) px-12 py-16">{children}</main>
}
