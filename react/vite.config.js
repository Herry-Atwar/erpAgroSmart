import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  base: '/agrosmart/react/dist/',
  server: {
    proxy: {
      '/agrosmart/api': 'http://localhost',
    },
  },
})
