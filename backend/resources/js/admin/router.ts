import { createRouter, createWebHistory } from 'vue-router'
import DashboardPage from '@admin/pages/DashboardPage.vue'
import MediaItemsPage from '@admin/pages/MediaItemsPage.vue'
import DevicesPage from '@admin/pages/DevicesPage.vue'
import IntegrationsPage from '@admin/pages/IntegrationsPage.vue'

/**
 * A real multi-page router — the deliberate opposite of the kiosk
 * player's choice to ship with none. This is an ordinary desktop admin
 * tool with no 24/7-runtime or low-RAM constraint, so the tradeoff that
 * argues against a router there doesn't apply here at all.
 */
export const router = createRouter({
  history: createWebHistory('/admin'),
  routes: [
    { path: '/', name: 'dashboard', component: DashboardPage },
    { path: '/media-items', name: 'media-items', component: MediaItemsPage },
    { path: '/devices', name: 'devices', component: DevicesPage },
    { path: '/integrations', name: 'integrations', component: IntegrationsPage },
  ],
})
