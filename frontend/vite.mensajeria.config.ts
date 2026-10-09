import path from 'node:path'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// Independent chat entry; live mode consumes the API on the same origin.
export default defineConfig({
  root: path.resolve(import.meta.dirname, 'pruebas/mensajeria'),
  base: './',
  plugins: [react(), tailwindcss()],
  define: { __SPA_BUILD_ID__: JSON.stringify('') },
  css: { postcss: { plugins: [] } },
  resolve: {
    alias: { '@': path.resolve(import.meta.dirname, 'src') },
    extensions: ['.tsx', '.ts', '.jsx', '.js', '.mjs', '.mts', '.cjs', '.cts', '.json'],
  },
  server: { host: '127.0.0.1', port: 5180, strictPort: true },
  preview: { host: '127.0.0.1', port: 4180, strictPort: true },
  build: { outDir: '../../.preview-build/mensajeria', emptyOutDir: true },
})
