import { setupServer } from 'msw/node'

/**
 * Sem handlers padrao: cada teste declara o que o servidor responde, com
 * `server.use(...)`. Uma requisicao nao declarada estoura em `onUnhandledRequest`
 * — e melhor um teste que quebra do que um que passa por acidente.
 */
export const server = setupServer()
