import '@testing-library/jest-dom/vitest'
import { cleanup, configure } from '@testing-library/react'
import { afterAll, afterEach, beforeAll } from 'vitest'
import { server } from './server'

// O findBy* desiste em 1000ms por padrao, mas o polling da tela de Estrategia
// so refaz a busca depois de POLL_INTERVAL_MS (1500ms). Sem esta folga, os
// testes de geracao morrem antes do segundo poll acontecer.
configure({ asyncUtilTimeout: 5000 })

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }))

afterEach(() => {
  cleanup()
  server.resetHandlers()
  localStorage.clear()
})

afterAll(() => server.close())
