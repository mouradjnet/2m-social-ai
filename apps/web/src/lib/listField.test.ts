import { expect, test } from 'vitest'
import { fromLines, toLines } from './listField'

test('toLines junta os itens com quebra de linha', () => {
  expect(toLines(['Carros', 'Motos'])).toBe('Carros\nMotos')
})

test('toLines de null ou undefined devolve string vazia', () => {
  expect(toLines(null)).toBe('')
  expect(toLines(undefined)).toBe('')
})

test('fromLines corta espacos e descarta linhas vazias, inclusive a ultima', () => {
  expect(fromLines('Carros\n  Motos  \n\nCaminhões\n')).toEqual(['Carros', 'Motos', 'Caminhões'])
})

test('fromLines de string vazia devolve array vazio', () => {
  expect(fromLines('')).toEqual([])
  expect(fromLines('   \n  \n')).toEqual([])
})

test('ida-e-volta preserva a lista', () => {
  const xs = ['Compra Segura', 'Negociação e Troca', 'Financiamento']
  expect(fromLines(toLines(xs))).toEqual(xs)
})
