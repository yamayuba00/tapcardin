import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from '@/App/index.vue'
import { router } from '@/router'
import { applyStoredTheme } from '@/composables/useTheme'
import { initPwa } from '@/composables/usePwa'

applyStoredTheme()
initPwa()

// mount setelah rute awal siap: isi hasil render server (beranda) langsung diganti halaman yang sama, tanpa kedip kosong
const app = createApp(App).use(createPinia()).use(router)
router.isReady().then(() => app.mount('#app'))
