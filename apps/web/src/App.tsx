import { useState } from 'react'
import { Button } from '@/components/ui/Button'
import { Card, CardDescription, CardTitle } from '@/components/ui/Card'
import { Input } from '@/components/ui/Input'
import { Stepper, type Step } from '@/components/ui/Stepper'

const STEPS: Step[] = [
  { id: 'identity', label: 'Passo 1', title: 'Identidade' },
  { id: 'audience', label: 'Passo 2', title: 'Público-Alvo' },
  { id: 'positioning', label: 'Passo 3', title: 'Posicionamento de Mercado' },
  { id: 'social', label: 'Passo 4', title: 'Links Sociais' },
]

/**
 * Fase 1b: prova que os tokens do design system renderizam nos quatro
 * componentes base. A tela real do wizard chega na Fase 2, ligada ao PATCH
 * parcial de /projects/{project}/brand-profile.
 */
export default function App() {
  const [currentId, setCurrentId] = useState('identity')
  const [brandName, setBrandName] = useState('')

  const completedIds = brandName.trim() ? ['identity'] : []

  return (
    <div className="min-h-svh bg-surface">
      <main className="mx-auto max-w-(--container-shell) px-12 py-16">
        <h1 className="text-display-lg text-on-surface">Vamos definir sua marca.</h1>
        <p className="text-body-lg text-on-surface-variant mt-4 max-w-2xl">
          Sou seu Estrategista de IA. Vamos trabalhar juntos para estabelecer a
          identidade principal da sua marca.
        </p>

        <div className="mt-12 flex gap-16">
          <div className="w-64 shrink-0">
            <Stepper
              steps={STEPS}
              currentId={currentId}
              completedIds={completedIds}
              onSelect={setCurrentId}
            />
          </div>

          <Card className="flex-1" interactive>
            <span className="inline-flex items-center rounded-full bg-secondary-container px-3 py-1 text-label-sm font-display text-secondary">
              Estrategista de IA
            </span>

            <div className="mt-4">
              <CardTitle>Identidade Principal</CardTitle>
              <CardDescription>
                Como devemos chamá-lo e qual é a sua missão principal? Seja
                conciso; vamos expandir isso mais tarde.
              </CardDescription>
            </div>

            <div className="mt-6 flex flex-col gap-6">
              <Input
                label="Nome da marca"
                placeholder="ex: Acme Corp"
                value={brandName}
                onChange={(e) => setBrandName(e.target.value)}
                hint="Preencha para ver o passo 1 marcar como completo."
              />

              <Input
                label="Site"
                placeholder="https://"
                defaultValue="acme"
                error="Informe uma URL completa, começando com https://"
              />
            </div>

            <div className="mt-8 flex justify-end gap-3">
              <Button variant="ghost">Pular</Button>
              <Button variant="secondary">Salvar rascunho</Button>
              <Button>Próximo: Público-Alvo</Button>
            </div>
          </Card>
        </div>
      </main>
    </div>
  )
}
