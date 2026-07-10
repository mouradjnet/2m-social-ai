import type { Pillar } from '@/lib/types'

/**
 * Nao confere que os pesos somam 100. Quem garante isso e o
 * StrategistAgent::validate() no servidor; duplicar a regra aqui criaria
 * dois donos para ela.
 */
export function Pillars({ pillars }: { pillars: Pillar[] }) {
  return (
    <ul className="mt-6 space-y-4">
      {pillars.map((pillar) => (
        <li key={pillar.name}>
          <div className="flex items-baseline justify-between gap-4">
            <span className="text-label-md text-on-surface">{pillar.name}</span>
            <span className="text-label-sm text-on-surface-variant">{pillar.weight}%</span>
          </div>

          <div className="bg-surface-container mt-2 h-1.5 w-full rounded-full">
            <div className="bg-primary h-1.5 rounded-full" style={{ width: `${pillar.weight}%` }} />
          </div>

          <p className="text-body-sm text-on-surface-variant mt-2">{pillar.description}</p>
        </li>
      ))}
    </ul>
  )
}
