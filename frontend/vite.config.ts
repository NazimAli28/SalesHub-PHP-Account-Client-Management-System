/// <reference types="vitest/config" />
import { copyFileSync, readFileSync } from 'node:fs'
import path from 'node:path'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv, type Plugin } from 'vite'

/** Matches modules of the given packages, with `/` or `\` separators (Windows paths). */
function vendor(packages: string): RegExp {
  const pattern = `node_modules/(${packages})/`.replaceAll('/', '[\\\\/]')
  return new RegExp(pattern)
}

// The e2e suite points the dev server at an isolated API (see playwright.config.ts).
const apiTarget = process.env.E2E_API_URL ?? 'http://localhost:8000'

/**
 * Static browser demo only (`vite build --mode demo`, GitHub Pages): ships MSW's service worker
 * (the file `npx msw init` would copy) and a 404.html copy of index.html, so deep links load the SPA.
 * Normal builds contain neither.
 */
function staticDemoAssets(): Plugin {
  let outDir = 'dist'
  return {
    name: 'saleshub-static-demo-assets',
    apply: 'build',
    configResolved(config) {
      outDir = path.resolve(config.root, config.build.outDir)
    },
    generateBundle() {
      this.emitFile({
        type: 'asset',
        fileName: 'mockServiceWorker.js',
        source: readFileSync(
          path.resolve(import.meta.dirname, 'node_modules/msw/lib/mockServiceWorker.js'),
          'utf8',
        ),
      })
    },
    closeBundle() {
      copyFileSync(path.join(outDir, 'index.html'), path.join(outDir, '404.html'))
    },
  }
}

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, import.meta.dirname, 'VITE_')
  const staticDemo = env.VITE_STATIC_DEMO === 'true'

  return {
    // The GitHub Pages demo lives under the repository path.
    base: staticDemo ? (env.VITE_BASE_PATH ?? '/') : '/',
    plugins: [react(), tailwindcss(), ...(staticDemo ? [staticDemoAssets()] : [])],
    resolve: {
      alias: {
        '@': path.resolve(import.meta.dirname, './src'),
      },
    },
    build: {
      rolldownOptions: {
        output: {
          // Long-lived vendor chunks: they change far less often than app code, so browsers keep
          // them cached across deploys. Route pages are split automatically by their lazy imports.
          codeSplitting: {
            groups: [
              { name: 'vendor-react', test: vendor('react|react-dom|scheduler'), priority: 30 },
              {
                name: 'vendor-router',
                test: vendor('react-router|cookie|set-cookie-parser'),
                priority: 20,
              },
              {
                name: 'vendor-query',
                test: vendor('@tanstack/(query-core|react-query)'),
                priority: 20,
              },
              // Static browser demo only (the in-browser API).
              { name: 'vendor-msw', test: vendor('msw|@mswjs/interceptors|graphql'), priority: 20 },
              {
                name: 'vendor-ui',
                test: vendor('radix-ui|@radix-ui|cmdk|sonner|@floating-ui'),
                priority: 10,
              },
            ],
          },
        },
      },
    },
    server: {
      port: 5173,
      // Forward API + auth calls to the Laravel dev server (php artisan serve)
      proxy: {
        '/api': apiTarget,
        '/sanctum': apiTarget,
      },
    },
    test: {
      environment: 'jsdom',
      globals: true,
      exclude: ['e2e/**', 'node_modules/**'],
      setupFiles: ['./src/test/setup.ts'],
      css: true,
      // Dialog/sheet/popover interaction tests can exceed the 5 s default when the whole suite
      // runs in parallel (notably on CI runners).
      testTimeout: 20_000,
    },
  }
})
