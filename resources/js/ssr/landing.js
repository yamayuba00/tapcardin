// Render server beranda marketing (Vue SSR). Dibangun `vite build --ssr` → bootstrap/ssr/landing.js, dijalankan
// App\Support\Marketing\LandingSsr: stdin = {config} (sama dengan data-config halaman), stdout = HTML isi #app.
import { createSSRApp } from 'vue'
import { createPinia } from 'pinia'
import { renderToString } from 'vue/server-renderer'
import { config } from '@/config'
import Home from '@/pages/marketing/Home/index.vue'

let input = ''
for await (const chunk of process.stdin) input += chunk
Object.assign(config, JSON.parse(input).config)

const app = createSSRApp(Home).use(createPinia())
app.config.warnHandler = () => {} // peringatan dev tidak boleh ikut ke stdout
process.stdout.write(await renderToString(app))
