import { createApp } from 'vue'
import App from '@admin/App.vue'
import { router } from '@admin/router'

createApp(App).use(router).mount('#admin-app')
