import { expect, test } from 'vitest'
import { ApiError } from './api'

const erro = new ApiError(422, {
  message: 'x',
  errors: {
    instagram: ['link inválido'],
    'colors.1': ['cor inválida'],
    'competitors.0.url': ['link do concorrente inválido'],
  },
})

test('fieldError acha o erro do proprio campo', () => {
  expect(erro.fieldError('instagram')).toBe('link inválido')
})

test('fieldError de campo de lista acha o erro do item (colors.1, competitors.0.url)', () => {
  // O Laravel valida `colors.*` e devolve a chave do item: procurar so `colors`
  // deixava o erro invisivel e o botao parecia nao fazer nada.
  expect(erro.fieldError('colors')).toBe('cor inválida')
  expect(erro.fieldError('competitors')).toBe('link do concorrente inválido')
})

test('fieldError nao confunde prefixo de outro campo', () => {
  expect(erro.fieldError('color')).toBeUndefined()
  expect(erro.fieldError('website')).toBeUndefined()
})
