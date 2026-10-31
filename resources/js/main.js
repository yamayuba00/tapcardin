import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from '@/App/index.vue'
import { router } from '@/router'
import { applyStoredTheme } from '@/composables/useTheme'
import { initPwa } from '@/composables/usePwa'

applyStoredTheme()
initPwa()

createApp(App).use(createPinia()).use(router).mount('#app')
