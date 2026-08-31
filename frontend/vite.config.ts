import path from 'node:path'
import fs from 'node:fs'
import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig(({ mode, command }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const dockerBuild = process.env.DOCKER_BUILD === '1'
  const spaBuildId = process.env.SPA_BUILD_ID ?? `alerts-${new Date().toISOString()}`

  return {
    // Rutas absolutas bajo /spa/ (+ <base href> inyectado por Laravel)
    base: command === 'build' ? '/spa/' : '/',
    define: {
      __SPA_BUILD_ID__: JSON.stringify(spaBuildId),
    },
    plugins: [
      react(),
      tailwindcss(),
      {
        name: 'spa-build-id-file',
        closeBundle() {
          const outDir = dockerBuild
            ? path.resolve(__dirname, 'dist')
            : path.resolve(__dirname, '../public/spa')
          fs.writeFileSync(path.join(outDir, 'build-id.txt'), spaBuildId)
        },
      },
      {
        name: 'spa-inline-upgrade-poller',
        transformIndexHtml(html) {
          if (html.includes('checkForDeploy')) {
            return html
          }
          const snippet = `<script>
;(function(){var s=document.querySelector('script[type="module"]');if(!s)return;var l=s.getAttribute('src')||'';function c(){fetch('/spa/index.html',{cache:'no-store'}).then(function(r){return r.text()}).then(function(h){var m=h.match(/assets\\/index-[^"']+\\.js/);if(m&&l.indexOf(m[0])===-1)location.reload()})}c();setInterval(c,3e4)})();
</script>`
          return html.replace('</title>', `</title>\n    ${snippet}`)
        },
      },
    ],
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
