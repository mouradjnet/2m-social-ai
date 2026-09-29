import { expect, test } from 'vitest'
import { competitorsFromLines, competitorsToLines, fromLines, toLines } from './listField'

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

test('concorrente: cada linha vira {name, url}; o link https no fim e opcional', () => {
  expect(competitorsFromLines('Clínica Rival https://rival.com.br\n  Outra Marca  \n\n')).toEqual([
    { name: 'Clínica Rival', url: 'https://rival.com.br' },
    { name: 'Outra Marca', url: null },
  ])
})

test('concorrente so com o link usa o link como nome', () => {
  expect(competitorsFromLines('https://rival.com.br')).toEqual([
    { name: 'https://rival.com.br', url: 'https://rival.com.br' },
  ])
})

test('concorrentes do servidor voltam como linhas, e a ida-e-volta preserva', () => {
  const xs = [
    { name: 'Clínica Rival', url: 'https://rival.com.br' },
    { name: 'Outra Marca', url: null },
  ]
  expect(competitorsToLines(xs)).toBe('Clínica Rival https://rival.com.br\nOutra Marca')
  expect(competitorsFromLines(competitorsToLines(xs))).toEqual(xs)
  expect(competitorsToLines(null)).toBe('')
})
