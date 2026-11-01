import { reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'

/**
 * Tabel server-side: filter, halaman, urutan (`sort` = `kolom` / `-kolom`), dan jumlah per halaman
 * semuanya dikirim ke server (App\Support\Http\Table). Untuk daftar utama halaman, state tersinkron
 * ke query URL (tersimpan saat kembali); tabel di dalam halaman detail memakai `{ url: false }`.
 * @param {(params: object) => Promise<{data: any[], meta: object}>} fetcher
 * @param {Record<string, string>} defaults nilai awal filter
 * @param {{ url?: boolean, sort?: string, perPage?: number }} [opts]
 */
export function usePagedList(fetcher, defaults = {}, opts = {}) {
  const url = opts.url !== false
  const route = useRoute()
  const router = useRouter()
  const q = url ? route.query : {}
  const filters = reactive({ ...defaults, ...pick(q, Object.keys(defaults)) })
  const page = ref(Number(q.page) || 1)
  const sort = ref(q.sort ? String(q.sort) : opts.sort ?? '')
  const perPage = ref(Number(q.per_page) || opts.perPage || 25)
  const rows = ref(null)
  const meta = ref(null)
  const loading = ref(false)

  let seq = 0
  async function load() {
    const mine = ++seq // abaikan respons lama bila pengguna cepat berganti halaman/urutan
    loading.value = true
    try {
      const res = await fetcher(clean({ ...filters, page: page.value, sort: sort.value, per_page: perPage.value }))
      if (mine !== seq) return
      rows.value = res.data
      meta.value = res.meta
    } finally {
      if (mine === seq) loading.value = false
    }
  }

  function search() {
    page.value = 1
    sync()
  }
  function goto(p) {
    page.value = p
    sync()
  }
  /** Klik header: naik → turun → naik. Dengan `dir` ('asc' | 'desc') arah ditetapkan langsung (menu Urutkan di ponsel). */
  function toggleSort(key, dir) {
    sort.value = dir === 'desc' ? `-${key}` : dir === 'asc' ? key : sort.value === key ? `-${key}` : key
    search()
  }
  function setPerPage(n) {
    perPage.value = Number(n)
    search()
  }
  function sync() {
    if (url) {
      const query = { ...filters, page: page.value > 1 ? page.value : '', sort: sort.value !== (opts.sort ?? '') ? sort.value : '', per_page: perPage.value !== (opts.perPage || 25) ? perPage.value : '' }
      router.replace({ query: clean(query) })
    }
    load()
  }

  watch(() => ({ ...filters }), (now, before) => {
    // select langsung memuat; input teks memakai tombol Cari / Enter
    const changed = Object.keys(now).find((k) => now[k] !== before[k])
    if (changed && changed !== 'q' && changed !== 'action') search()
  })
  load()

  return { filters, rows, meta, loading, sort, perPage, search, goto, toggleSort, setPerPage, reload: load }
}

function pick(obj, keys) {
  return Object.fromEntries(keys.filter((k) => obj[k] != null).map((k) => [k, String(obj[k])]))
}
const clean = (o) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== '' && v != null))
