/// <reference types="vitest/config" />
import path from 'node:path'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

/** Matches modules of the given packages, with `/` or `\` separators (Windows paths). */
function vendor(packages: string): RegExp {
  const pattern = `node_modules/(${packages})/`.replaceAll('/', '[\\\\/]')
  return new RegExp(pattern)
}

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
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
      '/api': 'http://localhost:8000',
      '/sanctum': 'http://localhost:8000',
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    css: true,
    // Dialog/sheet/popover interaction tests can exceed the 5 s default when the whole suite
    // runs in parallel (notably on CI runners).
    testTimeout: 20_000,
  },
})
