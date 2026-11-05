// Inti editor desain (kartu & lanyard): muat/simpan, sisi aktif, seleksi banyak, riwayat undo/redo,
// clipboard, tambah/hapus/duplikat, ratakan/distribusi, grup, kunci/sembunyi, urutan lapisan, brand kit,
// pintasan keyboard. Halaman Studio & LanyardStudio hanya menyusun tampilan di atasnya.
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useLeaveGuard } from '@/composables/useLeaveGuard'
import { studioApi } from '@/api/studio'
import {
  alignLayers, createLayer, distributeLayers, expandGroups, isFixed, isSystem, kitVars, moveLayer, newId, transplantLayers,
} from '@/lib/design'
import { createHistory } from '@/lib/history'
import { useToastStore } from '@/stores/toast'

const clipboard = { layers: [], from: null } // dibagi antar-halaman editor (kartu ↔ lanyard)

export function useDesignEditor(id) {
  const router = useRouter()
  const toast = useToastStore()

  const catalog = ref(null)
  const design = ref(null) // meta dari server: kind, name, kit, paired, lanyard_spec, …
  const sides = reactive({ front: null, back: null })
  const active = ref('front')
  const selection = ref([])
  const issues = ref([])
  const saving = ref(false)
  const dirty = ref(false)
  const grid = ref(0)
  const snap = ref(true)
  let history = createHistory({})
  const tick = ref(0)

  const side = computed(() => sides[active.value])
  const kit = computed(() => design.value?.kit ?? null)
  const vars = computed(() => ({ ...(catalog.value?.variables ?? {}), ...kitVars(kit.value) }))
  const selected = computed(() => side.value?.layers.filter((l) => selection.value.includes(l.id)) ?? [])
  const editable = computed(() => selected.value.filter((l) => !isFixed(l)))
  const canUndo = computed(() => tick.value >= 0 && history.canUndo)
  const canRedo = computed(() => tick.value >= 0 && history.canRedo)
  const errors = computed(() => issues.value.filter((i) => i.level === 'error'))
  const safe = computed(() => side.value?.zones?.safe ?? 4)

  function apply(d) {
    design.value = d
    sides.front = d.front
    sides.back = d.back
  }
  const snapshot = () => ({ front: sides.front, back: sides.back })
  function commit() {
    if (history.push(snapshot())) {
      dirty.value = true
      tick.value++
    }
  }
  function restore(state) {
    sides.front = state.front
    sides.back = state.back
    dirty.value = true
    tick.value++
    selection.value = selection.value.filter((sid) => side.value.layers.some((l) => l.id === sid))
  }
  const undo = () => history.canUndo && restore(history.undo())
  const redo = () => history.canRedo && restore(history.redo())

  async function load() {
    const [c, d] = await Promise.all([studioApi.catalog(), studioApi.get(id)])
    catalog.value = c.data
    apply(d.data)
    history = createHistory(snapshot())
    tick.value++
    issues.value = (await studioApi.validate(id)).data.issues
  }

  async function save(extra = {}) {
    saving.value = true
    try {
      const { data } = await studioApi.save(id, { name: design.value.name, front: sides.front, back: sides.back, ...extra })
      apply(data)
      issues.value = data.issues
      history.push(snapshot())
      tick.value++
      dirty.value = false
      toast.ok(data.issues.some((i) => i.level === 'error') ? 'Disimpan, tapi masih ada masalah cetak.' : 'Desain disimpan.')
      return data
    } catch (e) {
      toast.error(e.message)
      return null
    } finally {
      saving.value = false
    }
  }

  // ── seleksi ──────────────────────────────────────────────
  const select = (ids) => (selection.value = ids)
  const selectAll = () => (selection.value = side.value.layers.filter((l) => !isSystem(l) && !l.hidden).map((l) => l.id))
  function switchSide(s) {
    active.value = s
    selection.value = []
  }

  // ── ubah layer ───────────────────────────────────────────
  /** @param {{id:string, layer:object}[]} changes */
  function change(changes, record = false) {
    const layers = side.value.layers
    for (const { id: lid, layer } of changes) {
      const i = layers.findIndex((l) => l.id === lid)
      if (i >= 0 && !isFixed(layers[i])) layers[i] = layer
    }
    if (record) commit()
  }
  /** Patch properti ke semua layer terpilih yang bisa diubah (panel properti). */
  function patch(p, record = true) {
    change(editable.value.map((l) => ({ id: l.id, layer: { ...l, ...p } })), record)
  }
  /** Layer pengguna selalu disisipkan di bawah elemen sistem agar QR/NFC tetap paling depan. */
  function insert(list, target = side.value) {
    const layers = target.layers
    const firstSys = layers.findIndex(isSystem)
    layers.splice(firstSys < 0 ? layers.length : firstSys, 0, ...list)
    if (target === side.value) selection.value = list.map((l) => l.id)
    commit()
  }
  function ink() {
    const bg = side.value.background?.value ?? ''
    if (bg.startsWith('kit:') && kit.value) return 'kit:text'
    const dark = bg.startsWith('#') ? lum(bg) < 128 : false
    return catalog.value.palette.find((c) => c.name === (dark ? 'bone-50' : 'ink-950'))?.hex
  }
  function add(type, extra = {}) {
    insert([{ id: newId(side.value.layers), ...createLayer(type, side.value.size, ink(), extra) }])
  }
  async function addImage(file) {
    try {
      const { data } = await studioApi.uploadImage(id, file)
      const s = side.value.size
      const h = Math.min(20, s.h - 2 * safe.value)
      const w = Math.round(((h * data.px_w) / Math.max(data.px_h, 1)) * 100) / 100
      insert([{ id: newId(side.value.layers), ...createLayer('image', s, null, { asset: data.asset, url: data.url, px_w: data.px_w, px_h: data.px_h, w, h, x: (s.w - w) / 2, y: (s.h - h) / 2 }) }])
    } catch (err) {
      toast.error(err.message)
    }
  }
  function addKitLogo() {
    const logo = kit.value?.logo
    if (!logo) return toast.push('Unggah logo di panel Brand kit dulu.')
    const s = side.value.size
    const h = Math.min(14, s.h - 2 * safe.value)
    const w = Math.round(((h * logo.px_w) / Math.max(logo.px_h, 1)) * 100) / 100
    insert([{ id: newId(side.value.layers), type: 'image', asset: 'kit:logo', fit: 'contain', x: (s.w - w) / 2, y: (s.h - h) / 2, w, h }])
  }
  function remove() {
    const ids = editable.value.map((l) => l.id)
    if (!ids.length) return
    side.value.layers = side.value.layers.filter((l) => !ids.includes(l.id))
    selection.value = []
    commit()
  }
  function duplicate() {
    if (!editable.value.length) return
    const groupMap = {}
    const copies = editable.value.map((l) => {
      const nid = newId(side.value.layers)
      return { ...moveLayer(l, 2, 2), id: nid, ...(l.group ? { group: (groupMap[l.group] ??= `g${nid}`) } : {}) }
    })
    insert(copies)
  }
  function copy() {
    if (!editable.value.length) return false
    clipboard.layers = editable.value.map((l) => ({ ...l }))
    clipboard.from = { id, size: side.value.size }
    return true
  }
  function cut() {
    if (copy()) remove()
  }
  /** Tempel; dari desain/ukuran lain (kartu ↔ lanyard) otomatis diskalakan & ditengahkan. */
  function paste(target = side.value) {
    if (!clipboard.layers.length) return
    const sameSize = clipboard.from?.size?.w === target.size.w && clipboard.from?.size?.h === target.size.h
    const list = sameSize
      ? clipboard.layers.map((l) => ({ ...moveLayer(l, clipboard.from.id === id ? 2 : 0, clipboard.from.id === id ? 2 : 0), id: newId(target.layers) }))
      : transplantLayers(clipboard.layers, target.size, { safe: target.zones?.safe ?? 4, vars: vars.value, existing: target.layers })
    insert(list, target)
  }
  /** Salin terpilih ke sisi lain kartu (depan ↔ belakang). */
  function copyToOtherSide() {
    if (!copy()) return
    const other = sides[active.value === 'front' ? 'back' : 'front']
    paste(other)
    toast.ok(`Disalin ke sisi ${active.value === 'front' ? 'belakang' : 'depan'}.`)
  }

  // ── tata letak ───────────────────────────────────────────
  function align(how) {
    const list = editable.value
    if (!list.length) return
    const out = alignLayers(list, how, side.value.size, vars.value)
    change(out.map((l) => ({ id: l.id, layer: l })), true)
  }
  function distribute(axis) {
    const out = distributeLayers(editable.value, axis, vars.value)
    change(out.map((l) => ({ id: l.id, layer: l })), true)
  }
  function group() {
    if (editable.value.length < 2) return
    const g = `g${newId(side.value.layers)}`
    patch({ group: g })
  }
  function ungroup() {
    change(editable.value.map((l) => { const { group: _g, ...rest } = l; return { id: l.id, layer: rest } }), true)
  }
  /** Kunci/buka kunci memakai semua terpilih (termasuk yang terkunci). */
  function toggleLock(ids = selection.value) {
    const layers = side.value.layers
    const target = layers.filter((l) => ids.includes(l.id) && !isSystem(l))
    const lockAll = target.some((l) => !l.lock)
    for (const l of target) layers[layers.indexOf(l)] = { ...l, lock: lockAll || undefined }
    commit()
  }
  function toggleHide(lid) {
    const layers = side.value.layers
    const i = layers.findIndex((l) => l.id === lid)
    if (i < 0 || isSystem(layers[i])) return
    layers[i] = { ...layers[i], hidden: !layers[i].hidden || undefined }
    if (layers[i].hidden) selection.value = selection.value.filter((s) => s !== lid)
    commit()
  }
  /** Urutan tumpukan: forward / backward satu langkah, front / back paling atas/bawah (di bawah elemen sistem). */
  function zOrder(how) {
    const layers = side.value.layers
    const ids = editable.value.map((l) => l.id)
    if (!ids.length) return
    const user = layers.filter((l) => !isSystem(l))
    const sys = layers.filter(isSystem)
    let arr = [...user]
    if (how === 'front' || how === 'back') {
      const picked = arr.filter((l) => ids.includes(l.id))
      const rest = arr.filter((l) => !ids.includes(l.id))
      arr = how === 'front' ? [...rest, ...picked] : [...picked, ...rest]
    } else {
      const dir = how === 'forward' ? 1 : -1
      const idx = arr.map((l, i) => (ids.includes(l.id) ? i : -1)).filter((i) => i >= 0)
      for (const i of dir > 0 ? idx.reverse() : idx) {
        const j = i + dir
        if (j >= 0 && j < arr.length && !ids.includes(arr[j].id)) [arr[i], arr[j]] = [arr[j], arr[i]]
      }
    }
    side.value.layers = [...arr, ...sys]
    commit()
  }
  /** Daftar lapisan: pindahkan `lid` ke posisi `to` (indeks di antara layer pengguna). */
  function reorderTo(lid, to) {
    const user = side.value.layers.filter((l) => !isSystem(l))
    const sys = side.value.layers.filter(isSystem)
    const from = user.findIndex((l) => l.id === lid)
    if (from < 0) return
    const [item] = user.splice(from, 1)
    user.splice(Math.max(0, Math.min(to, user.length)), 0, item)
    side.value.layers = [...user, ...sys]
    commit()
  }
  function nudge(dx, dy) {
    change(editable.value.map((l) => ({ id: l.id, layer: moveLayer(l, dx, dy) })), true)
  }
  function setBackground(bg) {
    side.value.background = bg
    commit()
  }

  // ── brand kit ────────────────────────────────────────────
  async function useKit(kitId) {
    return save({ brand_kit_id: kitId })
  }
  function kitChanged(k) {
    // kit diubah dari panel: render langsung memakai warna baru (rujukan kit:*), daftar kit katalog ikut diperbarui
    design.value = { ...design.value, kit: k }
    const list = catalog.value.kits
    const i = list.findIndex((x) => x.id === k.id)
    if (i >= 0) list[i] = k
    else list.unshift(k)
  }

  // ── keyboard ─────────────────────────────────────────────
  function onKey(e) {
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) || document.activeElement?.isContentEditable
    const mod = e.ctrlKey || e.metaKey
    const k = e.key.toLowerCase()
    if (mod && k === 's') { e.preventDefault(); save(); return }
    if (typing) return
    if (mod && k === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); return }
    if (mod && k === 'y') { e.preventDefault(); redo(); return }
    if (mod && k === 'a') { e.preventDefault(); selectAll(); return }
    if (mod && k === 'c') { if (copy()) { e.preventDefault(); toast.push('Disalin. Tempel di kartu atau lanyard dengan Ctrl+V.') } return }
    if (mod && k === 'x') { e.preventDefault(); cut(); return }
    if (mod && k === 'v') { e.preventDefault(); paste(); return }
    if (mod && k === 'd') { e.preventDefault(); duplicate(); return }
    if (mod && k === 'g') { e.preventDefault(); e.shiftKey ? ungroup() : group(); return }
    if (mod && k === 'l') { e.preventDefault(); toggleLock(); return }
    if (mod && e.key === ']') { e.preventDefault(); zOrder(e.shiftKey ? 'front' : 'forward'); return }
    if (mod && e.key === '[') { e.preventDefault(); zOrder(e.shiftKey ? 'back' : 'backward'); return }
    if (e.key === 'Escape') { selection.value = []; return }
    if (!editable.value.length) return
    if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); remove(); return }
    const step = e.shiftKey ? 2 : 0.5 // DBD §10.3 — nudge 0,5 mm
    const d = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }[e.key]
    if (d) { e.preventDefault(); nudge(d[0], d[1]) }
  }

  onMounted(() => {
    load().catch(() => router.replace('/designs'))
    window.addEventListener('keydown', onKey)
  })
  onBeforeUnmount(() => window.removeEventListener('keydown', onKey))
  // keluar saat ada perubahan → dialog LeaveConfirm di halaman editor (bukan window.confirm)
  const leaveGuard = useLeaveGuard(() => dirty.value)

  return {
    leaveGuard, catalog, design, sides, side, active, selection, selected, editable, issues, errors, saving, dirty, grid, snap, kit, vars, safe,
    canUndo, canRedo, undo, redo, commit, save, load, select, selectAll, switchSide, change, patch, insert, add, addImage, addKitLogo,
    remove, duplicate, copy, cut, paste, copyToOtherSide, align, distribute, group, ungroup, toggleLock, toggleHide, zOrder, reorderTo,
    nudge, setBackground, useKit, kitChanged, expandGroups,
  }
}

function lum(hex) {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16))
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}
