import { render, screen } from '@testing-library/react'
import { expect, test } from 'vitest'
import type { Pillar } from '@/lib/types'
import { Pillars } from './Pillars'

const PILARES: Pillar[] = [
  { name: 'Educação', weight: 40, description: 'Explicar o que o público não sabe.' },
  { name: 'Prova social', weight: 35, description: 'Casos e números verificáveis.' },
  { name: 'Bastidores', weight: 25, description: 'Mostrar o processo.' },
]

test('renderiza nome, peso e descrição de cada pilar', () => {
  render(<Pillars pillars={PILARES} />)

  expect(screen.getByText('Educação')).toBeInTheDocument()
  expect(screen.getByText('40%')).toBeInTheDocument()
  expect(screen.getByText('Prova social')).toBeInTheDocument()
  expect(screen.getByText('35%')).toBeInTheDocument()
  expect(screen.getByText('Bastidores')).toBeInTheDocument()
  expect(screen.getByText('25%')).toBeInTheDocument()
  expect(screen.getByText('Mostrar o processo.')).toBeInTheDocument()
})
