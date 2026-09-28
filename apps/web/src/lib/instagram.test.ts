import { expect, test } from 'vitest'
import { composeCaption, parseHashtags } from './instagram'

test('a legenda junta texto, CTA e hashtags como o servidor publica', () => {
  expect(
    composeCaption({
      caption: ' Seu ciclo diz muito. ',
      cta: 'Agende sua consulta.',
      hashtags: ['saudefeminina', '#ginecologia'],
    }),
  ).toBe('Seu ciclo diz muito.\n\nAgende sua consulta.\n\n#saudefeminina #ginecologia')
})

test('partes vazias nao deixam linhas em branco sobrando', () => {
  expect(composeCaption({ caption: 'So texto', cta: null, hashtags: [] })).toBe('So texto')
})

test('hashtags aceitam espaco, virgula e # repetido', () => {
  expect(parseHashtags('#saude bem_estar, ##rotina  ')).toEqual(['#saude', '#bem_estar', '#rotina'])
})
