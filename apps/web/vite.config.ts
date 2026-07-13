import { fileURLToPath, URL } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
// `defineConfig` de 'vitest/config', nao de 'vite': so essa versao aceita o
// bloco `test`. O resto da config e identico.
import { defineConfig } from 'vitest/config'

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    proxy: {
      '/api': 'http://127.0.0.1:8000',
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    css: false,
    // A tela formata data e hora no fuso da MAQUINA. Sem fixar um, o mesmo teste
    // passava aqui (America/Cayenne, UTC-3) e quebrava no CI (UTC): a peça agendada
    // aparecia às 13:00 em vez de 10:00. O teste media o relógio de quem rodava, não
    // o produto. `America/Sao_Paulo` é o mesmo default de `projects.timezone`.
    env: { TZ: 'America/Sao_Paulo' },
  },
})
