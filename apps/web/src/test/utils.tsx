import type { ReactElement } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'

/**
 * Um QueryClient NOVO por teste. Um cliente compartilhado vaza cache entre
 * testes, e o retry padrao transforma um 404 esperado em segundos de espera.
 */
function newQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
}

/**
 * O MemoryRouter guarda a URL em memoria e NAO toca window.location. Asserir
 * `window.location.search` num teste destes passa sempre, testando nada. Esta
 * sonda expoe a query string do router.
 */
function LocationProbe() {
  const location = useLocation()

  return <span data-testid="location-search">{location.search}</span>
}

/** A query string atual do router, ex: '?run=42' ou '' quando limpa. */
export function currentSearch(): string {
  return screen.getByTestId('location-search').textContent ?? ''
}

interface Options {
  /** Rota que o componente ocupa, ex: '/projects/:projectId/strategy' */
  path: string
  /** URL inicial, ex: '/projects/1/strategy?run=42' */
  entry: string
}

export function renderWithProviders(ui: ReactElement, { path, entry }: Options) {
  return render(
    <QueryClientProvider client={newQueryClient()}>
      <MemoryRouter initialEntries={[entry]}>
        <Routes>
          <Route
            path={path}
            element={
              <>
                {ui}
                <LocationProbe />
              </>
            }
          />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}
