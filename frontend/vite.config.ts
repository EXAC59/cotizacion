import path from 'node:path'
import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig(({ mode, command }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const dockerBuild = process.env.DOCKER_BUILD === '1'

  return {
    // Rutas absolutas bajo /spa/ (+ <base href> inyectado por Laravel)
    base: command === 'build' ? '/spa/' : '/',
    plugins: [react(), tailwindcss()],
    build: {
      outDir: dockerBuild ? 'dist' : '../public/spa',
      emptyOutDir: true,
    },
    resolve: {
      alias: {
        '@': path.resolve(__dirname, './src'),
      },
    },
    server: {
      port: 5173,
      proxy: {
        '/api': {
          target: env.VITE_API_PROXY_TARGET ?? 'http://cotizacion.test',
          changeOrigin: true,
        },
        '/sanctum': {
          target: env.VITE_API_PROXY_TARGET ?? 'http://cotizacion.test',
          changeOrigin: true,
        },
      },
    },
  }
})
