import './assets/main.css'

import { createApp } from 'vue'
import App from './App.vue'

// No router, no Pinia: this player renders exactly one always-on view
// and its runtime state (schedule, socket status, rotation index) lives
// in a handful of composables local to SignagePlayer.vue. Both libraries
// are genuinely good tools, but every dependency is JS the SoC has to
// parse and hold in memory for the entire 24/7 run — cutting the ones
// that would sit idle here is itself a low-power design decision, not
// an oversight.
createApp(App).mount('#app')

// Offline resilience, part 2 of 2 (part 1 is useContentManifest's
// IndexedDB manifest). The Service Worker caches the actual media bytes
// (images/video) so a reconnect-then-disconnect cycle — or a full power
// cycle with no network — still plays the last known-good playlist
// instead of a broken-image icon.
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/service-worker.js').catch((error) => {
      console.error('Service worker registration failed:', error)
    })
  })
}
