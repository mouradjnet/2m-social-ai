import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { BrandProfilePage } from '@/pages/BrandProfilePage'
import { LoginPage } from '@/pages/LoginPage'
import { ProjectsPage } from '@/pages/ProjectsPage'
import { CalendarPage } from '@/pages/CalendarPage'
import { ContentPage } from '@/pages/ContentPage'
import { InsightsPage } from '@/pages/InsightsPage'
import { StrategyPage } from '@/pages/StrategyPage'
import { TeamPage } from '@/pages/TeamPage'
import { AcceptInvitationPage } from '@/pages/AcceptInvitationPage'
import { getToken } from '@/lib/api'
import './index.css'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // 401 e 404 nao melhoram com insistencia.
      retry: (failureCount, error) => {
        const status = (error as { status?: number }).status
        if (status === 401 || status === 403 || status === 404) return false
        return failureCount < 2
      },
    },
  },
})

/** Guarda de rota. A autorizacao de verdade e do servidor; isto e so navegacao. */
function RequireAuth({ children }: { children: React.ReactNode }) {
  return getToken() ? children : <Navigate to="/login" replace />
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          {/* Sem RequireAuth: a propria pagina manda ao login e volta com o token. */}
          <Route path="/convite/:token" element={<AcceptInvitationPage />} />
          <Route
            path="/equipe"
            element={
              <RequireAuth>
                <TeamPage />
              </RequireAuth>
            }
          />
          <Route
            path="/"
            element={
              <RequireAuth>
                <ProjectsPage />
              </RequireAuth>
            }
          />
          <Route
            path="/projects/:projectId/brand-profile"
            element={
              <RequireAuth>
                <BrandProfilePage />
              </RequireAuth>
            }
          />
          <Route
            path="/projects/:projectId/strategy"
            element={
              <RequireAuth>
                <StrategyPage />
              </RequireAuth>
            }
          />
          <Route
            path="/projects/:projectId/content"
            element={
              <RequireAuth>
                <ContentPage />
              </RequireAuth>
            }
          />
          <Route
            path="/projects/:projectId/calendar"
            element={
              <RequireAuth>
                <CalendarPage />
              </RequireAuth>
            }
          />
          <Route
            path="/projects/:projectId/insights"
            element={
              <RequireAuth>
                <InsightsPage />
              </RequireAuth>
            }
          />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  </StrictMode>,
)
