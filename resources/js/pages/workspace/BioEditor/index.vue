<script setup>
// U-05 — bio link milik satu kartu. Satu kartu boleh punya maks. 3 bio link (mis. Kerja / Pameran / Personal) dan
// hanya satu yang aktif — itulah yang tampil saat kartu di-tap. Identitas & kontak dipakai bersama semua bio;
// tiap bio punya nama, tema, dan susunan blok sendiri. Preview langsung dihitung di klien dari isian form.
import { computed, reactive, ref } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { CircleCheck, Copy, CopyPlus, ExternalLink, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-vue-next'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import AppToggle from '@/components/ui/AppToggle/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import BioBlocksEditor from '@/components/bio/BioBlocksEditor/index.vue'
import ContactFieldsEditor from '@/components/profile/ContactFieldsEditor/index.vue'
import PublicProfileView from '@/components/profile/PublicProfileView/index.vue'
import { bioPagesApi, cardsApi, profilesApi } from '@/api/workspace'
import { useAction } from '@/composables/useAsync'
import { MAX_PAGES, blockPayload, presentBlock } from '@/lib/bioBlocks'
import { BIO_DEFAULTS, resolveTheme, themeChoice, themeStyle } from '@/lib/bioTheme'
import { previewLinks } from '@/lib/contacts'
import { initials } from '@/lib/format'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()
const token = route.params.token
const { busy, run } = useAction()

const LAYOUT = [
  { key: 'header', label: 'Header', options: [{ value: 'center', label: 'Tengah' }, { value: 'left', label: 'Kiri' }, { value: 'cover', label: 'Sampul' }] },
  { key: 'avatar', label: 'Bentuk foto', options: [{ value: 'circle', label: 'Bulat' }, { value: 'rounded', label: 'Membulat' }, { value: 'square', label: 'Kotak' }] },
  { key: 'corners', label: 'Sudut tombol', options: [{ value: 'rounded', label: 'Membulat' }, { value: 'sharp', label: 'Tajam' }, { value: 'pill', label: 'Kapsul' }] },
  { key: 'button', label: 'Tombol utama', options: [{ value: 'solid', label: 'Penuh' }, { value: 'outline', label: 'Garis' }] },
  { key: 'links', label: 'Tampilan tautan', options: [{ value: 'list', label: 'Daftar' }, { value: 'buttons', label: 'Tombol' }, { value: 'grid', label: 'Grid' }] },
  { key: 'font', label: 'Font nama', options: [{ value: 'Syne', label: 'Syne' }, { value: 'IBM Plex Sans', label: 'Plex Sans' }, { value: 'IBM Plex Mono', label: 'Plex Mono' }] },
]

const card = ref(null)
const profile = ref(null)
const serverPreview = ref(null)
const catalog = ref(null)
const form = reactive({})
const fields = ref([])
const errors = ref({})
const profileId = ref(null)
const contactCatalog = ref(null)

// ── Halaman bio ────────────────────────────────────────────
const pages = ref([])
const saved = ref({}) // id halaman → snapshot terakhir yang tersimpan (deteksi perubahan)
const currentId = ref(null)
const page = computed(() => pages.value.find((p) => p.id === currentId.value) ?? null)

const toLocal = (p) => ({
  id: p.id,
  name: p.name,
  is_active: p.is_active,
  theme: { ...BIO_DEFAULTS, accent: null, ...themeChoice(p.theme) },
  blocks: p.blocks.map((b) => ({ ...b, key: b.id, data: structuredClone(b.data ?? {}) })),
})
const payload = (p) => ({ name: p.name.trim(), theme: { ...p.theme }, blocks: p.blocks.map(blockPayload) })
const snapshot = (p) => JSON.stringify(payload(p))
const isDirty = (p) => snapshot(p) !== saved.value[p.id]
const dirtyPages = computed(() => pages.value.filter(isDirty))

function remember(p) {
  saved.value = { ...saved.value, [p.id]: snapshot(p) }
}
/** Data server menggantikan salinan lokal satu halaman (setelah simpan / dibuat). */
function replacePage(serverPage) {
  const local = toLocal(serverPage)
  const i = pages.value.findIndex((p) => p.id === local.id)
  if (i === -1) pages.value = [...pages.value, local]
  else pages.value.splice(i, 1, local)
  remember(local)
  return local
}
/** Daftar dari server (aktifkan / hapus): ambil status aktif & buang yang terhapus, tanpa membuang editan lokal. */
function syncList(list) {
  pages.value = list.map((sp) => {
    const local = pages.value.find((p) => p.id === sp.id)
    if (local) return { ...local, is_active: sp.is_active }
    const fresh = toLocal(sp)
    remember(fresh)
    return fresh
  })
  if (!page.value) currentId.value = pages.value.find((p) => p.is_active)?.id ?? pages.value[0]?.id
}

function apply(p) {
  profile.value = p
  Object.assign(form, {
    display_name: p.display_name, title: p.title || '', company: p.company || '', bio: p.bio || '',
    locale: p.locale, show_exchange_form: p.show_exchange_form, is_indexable: p.is_indexable,
  })
  fields.value = p.fields.map((f) => ({ ...f, meta: { ...(f.meta || {}) } }))
}

async function load() {
  const c = (await cardsApi.get(token)).data
  if (!c.profile) throw new Error('no profile')
  card.value = c
  profileId.value = c.profile.id
  const [p, pv, cat, ct, pg] = await Promise.all([
    profilesApi.get(profileId.value), profilesApi.preview(profileId.value), profilesApi.themes(), profilesApi.contactTypes(), bioPagesApi.list(profileId.value),
  ])
  contactCatalog.value = ct.data
  serverPreview.value = pv.data
  catalog.value = cat.data
  apply(p.data)
  pages.value = []
  pg.data.forEach(replacePage)
  currentId.value = pages.value.find((x) => x.is_active)?.id ?? pages.value[0]?.id
}
load().catch(() => router.replace(`/cards/${token}`))

// ── Preview langsung ───────────────────────────────────────
const visible = computed(() => fields.value.filter((f) => f.is_visible && String(f.value ?? '').trim()))
const typesByKey = computed(() => Object.fromEntries((contactCatalog.value?.types ?? []).map((t) => [t.key, t])))
const live = computed(() => {
  if (!serverPreview.value || !catalog.value || !page.value) return null
  const first = (type) => visible.value.find((f) => f.type === type && f.is_primary) ?? visible.value.find((f) => f.type === type)
  // field yang tampil sebagai tombol cepat tidak diulang di daftar tautan (sama dengan server)
  const quickIdx = new Set(['whatsapp', 'phone', 'email'].map(first).filter(Boolean).map((f) => fields.value.indexOf(f)))
  return {
    ...serverPreview.value,
    display_name: form.display_name || '—',
    title: form.title, company: form.company, bio: form.bio,
    avatar_url: profile.value.avatar_url,
    theme: resolveTheme(catalog.value, page.value.theme),
    quick: Object.fromEntries(['whatsapp', 'phone', 'email'].filter(first).map((k) => [k, '#'])),
    links: previewLinks(fields.value, typesByKey.value, quickIdx),
    blocks: page.value.blocks.filter((b) => b.is_visible).map(presentBlock).filter(Boolean),
    exchange: form.show_exchange_form ? { url: null, t: null } : null,
  }
})

// ── Simpan ─────────────────────────────────────────────────
/** Simpan identitas, kontak, lalu setiap bio yang berubah. Bio yang gagal divalidasi langsung dibuka. */
async function save() {
  errors.value = {}
  let failing = null
  try {
    await run(async () => {
      await profilesApi.update(profileId.value, {
        display_name: form.display_name, title: form.title || null, company: form.company || null, bio: form.bio || null,
        locale: form.locale, show_exchange_form: form.show_exchange_form, is_indexable: form.is_indexable,
      })
      const res = await profilesApi.saveFields(profileId.value, fields.value
        .filter((f) => String(f.value ?? '').trim() !== '' || f.id)
        .map(({ id: fid, type, label, value, meta, is_primary, is_visible, in_vcard }) => ({ id: fid, type, label, value, meta, is_primary, is_visible, in_vcard })))
      apply(res.data)
      for (const p of dirtyPages.value) {
        failing = p.id
        replacePage((await bioPagesApi.save(profileId.value, p.id, payload(p))).data)
      }
      failing = null
    }, 'Bio link disimpan. Kartu langsung menampilkan perubahan.')
    return true
  } catch (e) {
    errors.value = e.fields || {}
    if (failing) currentId.value = failing
    return false
  }
}

// ── Kelola bio (buat, salin, aktifkan, hapus) ──────────────
const creating = ref(null) // { name, from: 'blank' | id halaman sumber }
const deleting = ref(null)
const working = ref(false)

function openCreate(from = 'blank') {
  const src = pages.value.find((p) => p.id === from)
  creating.value = { name: src ? `${src.name} (salinan)`.slice(0, 40) : '', from }
}
async function create() {
  const name = creating.value.name.trim()
  if (!name) return toast.error('Beri nama bio link ini, mis. "Pameran" atau "Personal".')
  // salinan memakai versi tersimpan → simpan perubahan dulu
  if (dirtyPages.value.length && !(await save())) return
  working.value = true
  try {
    const res = await bioPagesApi.create(profileId.value, { name, from: creating.value.from === 'blank' ? null : creating.value.from })
    currentId.value = replacePage(res.data).id
    creating.value = null
    toast.ok('Bio link baru dibuat. Isi lalu tekan Simpan; aktifkan bila ingin tampil saat kartu di-tap.')
  } catch (e) {
    toast.error(e.fields?.name?.[0] ?? e.message)
  } finally {
    working.value = false
  }
}
async function activate() {
  if (isDirty(page.value) && !(await save())) return
  working.value = true
  try {
    syncList((await bioPagesApi.activate(profileId.value, page.value.id)).data)
    toast.ok(`"${page.value.name}" sekarang tampil saat kartu di-tap.`)
  } catch (e) {
    toast.error(e.message)
  } finally {
    working.value = false
  }
}
async function remove() {
  working.value = true
  try {
    const id = deleting.value.id
    syncList((await bioPagesApi.remove(profileId.value, id)).data)
    const { [id]: _, ...rest } = saved.value
    saved.value = rest
    deleting.value = null
    toast.ok('Bio link dihapus.')
  } catch (e) {
    toast.error(e.message)
  } finally {
    working.value = false
  }
}

onBeforeRouteLeave(() => !dirtyPages.value.length || window.confirm('Ada perubahan bio link yang belum disimpan. Tinggalkan editor?'))

async function upload(e) {
  const file = e.target.files?.[0]
  e.target.value = ''
  if (!file) return
  const res = await run(() => profilesApi.uploadAvatar(profileId.value, file), 'Foto diperbarui').catch(() => null)
  if (res) profile.value.avatar_url = res.data.avatar_url
}
async function copyUrl() {
  try { await navigator.clipboard.writeText(card.value.url); toast.ok('Tautan disalin') } catch { toast.push(card.value.url) }
}
const presetOf = (key) => catalog.value?.presets.find((p) => p.key === key)
</script>

<template>
  <div v-if="!live" class="grid gap-4" aria-busy="true"><AppSkeleton class="!h-8 w-2/5" /><AppSkeleton class="!h-96" /></div>
  <template v-else>
    <PageHeader eyebrow="Bio link" :title="card.label || `Kartu ${card.short_code}`" :back="`/cards/${token}`" back-label="Kartu">
      <AppButton variant="secondary" :href="card.url" target="_blank" rel="noopener"><ExternalLink class="size-5" /> Buka</AppButton>
      <AppButton :loading="busy" @click="save">Simpan</AppButton>
    </PageHeader>

    <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-2 gap-y-1 rounded-md border border-line bg-surface px-4 py-3 text-sm sm:flex sm:flex-wrap">
      <span class="col-span-2 text-muted sm:col-span-1">Alamat bio link kartu ini:</span>
      <span class="min-w-0 truncate font-mono text-fg sm:flex-1">{{ card.url }}</span>
      <AppButton variant="ghost" @click="copyUrl"><Copy class="size-5" /> Salin</AppButton>
    </div>

    <!-- Pilihan bio link kartu ini (maks. 3, satu aktif) -->
    <section class="grid gap-3 rounded-md border border-line bg-surface p-4" aria-label="Bio link kartu ini">
      <div class="flex flex-wrap items-center gap-2" role="tablist" aria-label="Pilih bio link">
        <button
          v-for="p in pages"
          :key="p.id"
          type="button"
          role="tab"
          :aria-selected="p.id === currentId"
          class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-md border border-line px-4 font-semibold aria-selected:border-accent aria-selected:bg-surface-2 aria-selected:outline-2 aria-selected:outline-accent"
          @click="currentId = p.id"
        >
          <span class="max-w-40 truncate">{{ p.name || 'Tanpa nama' }}</span>
          <AppBadge v-if="p.is_active" tone="ok" dot>Aktif</AppBadge>
          <span v-if="isDirty(p)" class="size-2 rounded-full bg-warn" title="Belum disimpan" aria-label="Belum disimpan"></span>
        </button>
        <AppButton v-if="pages.length < MAX_PAGES" variant="ghost" :disabled="working" @click="openCreate()"><Plus class="size-5" /> Bio baru</AppButton>
        <span class="text-sm text-muted sm:ml-auto">{{ pages.length }}/{{ MAX_PAGES }} bio link</span>
      </div>

      <div class="grid gap-3 border-t border-line pt-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
        <AppField label="Nama bio link ini" hint="Hanya untuk Anda, mis. Kerja, Pameran, Personal." :error="errors.name?.[0]">
          <AppInput v-model="page.name" maxlength="40" :invalid="!!errors.name" />
        </AppField>
        <div class="flex flex-wrap items-center gap-2">
          <span v-if="page.is_active" class="flex min-h-11 items-center gap-2 text-sm text-ok"><CircleCheck class="size-5" /> Tampil saat kartu di-tap</span>
          <AppButton v-else :loading="working" @click="activate"><CircleCheck class="size-5" /> Jadikan aktif</AppButton>
          <AppButton v-if="pages.length < MAX_PAGES" variant="secondary" :disabled="working" @click="openCreate(page.id)"><CopyPlus class="size-5" /> Duplikat</AppButton>
          <AppButton v-if="!page.is_active" variant="ghost" :disabled="working" aria-label="Hapus bio link ini" @click="deleting = page"><Trash2 class="size-5 text-danger" /> Hapus</AppButton>
        </div>
      </div>
    </section>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
      <form class="grid min-w-0 gap-6" @submit.prevent="save">
        <AppPanel title="Identitas">
          <div class="grid gap-4">
            <p class="text-sm text-muted">Foto, nama, dan bio dipakai bersama oleh semua bio link kartu ini.</p>
            <div class="flex flex-wrap items-center gap-4">
              <img v-if="profile.avatar_url" :src="profile.avatar_url" alt="Foto profil" class="size-20 flex-none rounded-full object-cover sm:size-24" />
              <span v-else class="grid size-20 flex-none place-items-center rounded-full bg-surface-2 font-display text-[28px] font-semibold text-muted sm:size-24 sm:text-[32px]">{{ initials(form.display_name) }}</span>
              <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-md border border-line-strong px-5 font-semibold hover:bg-surface-2">
                <Pencil class="size-5" /> Ganti foto
                <input class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" @change="upload" />
              </label>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
              <AppField label="Nama tampil"><AppInput v-model="form.display_name" maxlength="120" required /></AppField>
              <AppField label="Jabatan"><AppInput v-model="form.title" maxlength="120" /></AppField>
              <AppField label="Perusahaan"><AppInput v-model="form.company" maxlength="120" /></AppField>
            </div>
            <AppField label="Bio" :hint="`${form.bio.length}/500`"><AppTextarea v-model="form.bio" maxlength="500" rows="3" /></AppField>
          </div>
        </AppPanel>

        <AppPanel :title="`Isi halaman · ${page.name || 'Tanpa nama'}`">
          <BioBlocksEditor :key="page.id" v-model="page.blocks" :profile-id="profileId" :errors="errors" />
        </AppPanel>

        <AppPanel :title="`Tampilan · ${page.name || 'Tanpa nama'}`">
          <div class="grid gap-6">
            <div class="grid gap-2">
              <span class="label">Tema</span>
              <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <button
                  v-for="p in catalog.presets"
                  :key="p.key"
                  type="button"
                  :aria-pressed="page.theme.preset === p.key"
                  class="cursor-pointer grid gap-2 rounded-md border border-line p-1.5 text-left text-sm aria-pressed:border-accent aria-pressed:outline-2 aria-pressed:outline-accent"
                  @click="Object.assign(page.theme, { preset: p.key, accent: null })"
                >
                  <span :data-theme="p.mode" :style="themeStyle(p)" class="grid h-16 content-between rounded-sm bg-bg p-2 text-fg">
                    <span class="font-display text-base leading-none font-bold">Aa</span>
                    <span class="flex gap-1"><span class="h-2 w-8 rounded-full bg-accent"></span><span class="h-2 w-4 rounded-full bg-line-strong"></span></span>
                  </span>
                  <span class="px-1 font-medium">{{ p.name }}</span>
                </button>
              </div>
            </div>

            <div class="grid gap-2">
              <span class="label">Warna aksen</span>
              <div class="flex flex-wrap gap-2">
                <button type="button" :aria-pressed="!page.theme.accent" class="cursor-pointer inline-flex h-9 items-center gap-1 rounded-full border border-line px-3 text-sm aria-pressed:border-accent aria-pressed:text-accent-text" @click="page.theme.accent = null">
                  <RotateCcw class="size-4" /> Bawaan
                </button>
                <button
                  v-for="a in catalog.accents"
                  :key="a"
                  type="button"
                  :title="a"
                  :aria-label="`Aksen ${a}`"
                  :aria-pressed="page.theme.accent === a"
                  class="cursor-pointer size-9 rounded-full border border-line-strong aria-pressed:outline-2 aria-pressed:outline-offset-2 aria-pressed:outline-accent"
                  :style="{ background: `var(--color-${a})` }"
                  @click="page.theme.accent = a"
                ></button>
              </div>
              <p v-if="page.theme.accent && presetOf(page.theme.preset)?.accent_vars[page.theme.accent]?.accent !== page.theme.accent" class="text-sm text-muted">
                Warna ini terlalu mirip dengan latar, jadi tombol memakai warna teks agar tetap terbaca.
              </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
              <div v-for="o in LAYOUT" :key="o.key" class="grid gap-2">
                <span class="label">{{ o.label }}</span>
                <AppSegmented v-model="page.theme[o.key]" :label="o.label" :options="o.options" class="flex-wrap" />
              </div>
            </div>
            <AppToggle :model-value="page.theme.decoration === 'rings'" @update:model-value="(v) => (page.theme.decoration = v ? 'rings' : 'none')">Hiasan cincin sinyal di header</AppToggle>
          </div>
        </AppPanel>

        <AppPanel title="Kontak & tautan">
          <p class="mb-4 text-sm text-muted">Dipakai bersama semua bio link dan masuk ke "Simpan Kontak". Tampil di halaman lewat blok <b class="text-fg">Kontak & tautan</b>.</p>
          <ContactFieldsEditor v-if="contactCatalog" v-model="fields" :catalog="contactCatalog" :profile-id="profileId" :errors="errors" />
        </AppPanel>

        <AppPanel title="Privasi & bahasa">
          <div class="grid gap-4">
            <AppField label="Bahasa bio"><AppSelect v-model="form.locale" :options="[{ value: 'id', label: 'Indonesia' }, { value: 'en', label: 'English' }]" /></AppField>
            <AppToggle v-model="form.show_exchange_form">Tampilkan tombol Tukar Kontak</AppToggle>
            <AppToggle v-model="form.is_indexable">Izinkan mesin pencari mengindeks bio ini</AppToggle>
          </div>
        </AppPanel>
      </form>

      <aside class="grid gap-2 xl:sticky xl:top-6">
        <span class="label">Preview · {{ page.name || 'Tanpa nama' }}<template v-if="!page.is_active"> (belum aktif)</template></span>
        <div class="mx-auto w-full max-w-[380px] rounded-[28px] border border-line-strong bg-surface p-2.5 xl:max-w-none">
          <div class="h-[560px] overflow-y-auto overscroll-contain rounded-[20px] scrollbar-none sm:h-[700px]" tabindex="0" aria-label="Preview bio link (bisa di-scroll)">
            <PublicProfileView :profile="live" preview />
          </div>
        </div>
        <span class="text-sm text-muted">Perubahan terlihat langsung; tekan Simpan agar tampil saat kartu di-tap.</span>
      </aside>
    </div>

    <AppModal v-if="creating" size="sm" :title="creating.from !== 'blank' ? 'Duplikat bio link' : 'Bio link baru'" description="Satu kartu bisa punya 3 bio link; hanya satu yang aktif saat kartu di-tap." @close="creating = null">
      <form id="bio-create" class="grid gap-4" @submit.prevent="create">
        <AppField label="Nama"><AppInput v-model="creating.name" maxlength="40" autofocus placeholder="mis. Pameran, Personal" /></AppField>
        <AppField label="Mulai dari">
          <AppSelect v-model="creating.from" :options="[{ value: 'blank', label: 'Halaman kosong (hanya kontak)' }, ...pages.map((p) => ({ value: p.id, label: `Salin dari ${p.name}` }))]" />
        </AppField>
        <p v-if="dirtyPages.length" class="text-sm text-muted">Perubahan yang belum disimpan akan disimpan dulu.</p>
      </form>
      <template #footer>
        <AppButton variant="secondary" @click="creating = null">Batal</AppButton>
        <AppButton type="submit" form="bio-create" :loading="working || busy">Buat</AppButton>
      </template>
    </AppModal>

    <AppModal v-if="deleting" size="sm" :title="`Hapus bio “${deleting.name}”?`" description="Blok dan gambar galeri di bio link ini ikut terhapus. Identitas dan kontak tidak terpengaruh." @close="deleting = null">
      <template #footer>
        <AppButton variant="secondary" @click="deleting = null">Batal</AppButton>
        <AppButton variant="danger" :loading="working" @click="remove">Hapus</AppButton>
      </template>
    </AppModal>
  </template>
</template>
