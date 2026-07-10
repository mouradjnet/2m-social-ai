import { render, screen } from '@testing-library/react'
import { expect, test } from 'vitest'
import { Button } from './Button'

test('renderiza o rotulo', () => {
  render(<Button>Gerar estratégia</Button>)

  expect(screen.getByRole('button', { name: 'Gerar estratégia' })).toBeInTheDocument()
})
