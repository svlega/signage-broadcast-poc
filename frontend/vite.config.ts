import { fileURLToPath, URL } from 'node:url'

// vitest/config re-exports Vite's defineConfig with the `test` block
// typed — using plain `vite`'s defineConfig here would make vue-tsc
// flag an unrecognized property the moment `test` is added below.
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  test: {
    // No DOM needed: composables under test are plain reactivity/timer
    // logic with no template, so the (much cheaper) node environment is
    // enough — jsdom would be pure overhead for this test suite's scope.
    environment: 'node',
    include: ['src/**/*.spec.ts'],
    // fake-indexeddb/auto installs a real (in-memory) IndexedDB
    // implementation onto the global scope before any test file runs —
    // useContentManifest.ts calls the global `indexedDB` directly, the
    // same way it does in a real browser, and node has no such global
    // on its own.
    setupFiles: ['fake-indexeddb/auto'],
  },
})
