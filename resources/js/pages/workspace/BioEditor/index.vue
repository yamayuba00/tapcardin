<script setup>
// U-05 — bio link milik satu kartu (1 kartu = 1 bio). Preview langsung: identitas, tautan, dan tema
// dihitung di klien dari isian form + katalog tema server, jadi tidak perlu menyimpan untuk melihat hasil.
import { computed, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Copy, ExternalLink, Pencil, RotateCcw } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSegmented from '@/components/ui/AppSegmented/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import AppToggle from '@/components/ui/AppToggle/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import ContactFieldsEditor from '@/components/profile/ContactFieldsEditor/index.vue'
import PublicProfileView from '@/components/profile/PublicProfileView/index.vue'
import { cardsApi, profilesApi } from '@/api/workspace'
import { useAction } from '@/composables/useAsync'
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
const theme = reactive({ ...BIO_DEFAULTS, accent: null })
const fields = ref([])
const errors = ref({})
const profileId = ref(null)
const contactCatalog = ref(null)

function apply(p) {
  profile.value = p
  Object.assign(form, {
    display_name: p.display_name, title: p.title || '', company: p.company || '', bio: p.bio || '',
    locale: p.locale, show_exchange_form: p.show_exchange_form, is_indexable: p.is_indexable,
  })
  Object.assign(theme, { ...BIO_DEFAULTS, accent: null, ...themeChoice(p.theme) })
  fields.value = p.fields.map((f) => ({ ...f, meta: { ...(f.meta || {}) } }))
}

async function load() {
  const c = (await cardsApi.get(token)).data
  if (!c.profile) throw new Error('no profile')
  card.value = c
  profileId.value = c.profile.id
  const [p, pv, cat, ct] = await Promise.all([profilesApi.get(profileId.value), profilesApi.preview(profileId.value), profilesApi.themes(), profilesApi.contactTypes()])
  contactCatalog.value = ct.data
  serverPreview.value = pv.data
  catalog.value = cat.data
  apply(p.data)
}
load().catch(() => router.replace(`/cards/${token}`))

// ── Preview langsung ───────────────────────────────────────
const visible = computed(() => fields.value.filter((f) => f.is_visible && String(f.value ?? '').trim()))
const typesByKey = computed(() => Object.fromEntries((contactCatalog.value?.types ?? []).map((t) => [t.key, t])))
const live = computed(() => {
  if (!serverPreview.value || !catalog.value) return null
  const first = (type) => visible.value.find((f) => f.type === type && f.is_primary) ?? visible.value.find((f) => f.type === type)
  // field yang tampil sebagai tombol cepat tidak diulang di daftar tautan (sama dengan server)
  const quickIdx = new Set(['whatsapp', 'phone', 'email'].map(first).filter(Boolean).map((f) => fields.value.indexOf(f)))
  return {
    ...serverPreview.value,
    display_name: form.display_name || '—',
    title: form.title, company: form.company, bio: form.bio,
    avatar_url: profile.value.avatar_url,
    theme: resolveTheme(catalog.value, theme),
    quick: Object.fromEntries(['whatsapp', 'phone', 'email'].filter(first).map((k) => [k, '#'])),
    links: previewLinks(fields.value, typesByKey.value, quickIdx),
    exchange: form.show_exchange_form ? { url: null, t: null } : null,
  }
})

// ── Simpan ─────────────────────────────────────────────────
async function save() {
  errors.value = {}
  try {
    await run(async () => {
      await profilesApi.update(profileId.value, {
        display_name: form.display_name, title: form.title || null, company: form.company || null, bio: form.bio || null,
        theme: { ...theme }, locale: form.locale, show_exchange_form: form.show_exchange_form, is_indexable: form.is_indexable,
      })
      const res = await profilesApi.saveFields(profileId.value, fields.value
        .filter((f) => String(f.value ?? '').trim() !== '' || f.id)
        .map(({ id: fid, type, label, value, meta, is_primary, is_visible, in_vcard }) => ({ id: fid, type, label, value, meta, is_primary, is_visible, in_vcard })))
      apply(res.data)
    }, 'Bio link disimpan. Kartu langsung menampilkan perubahan.')
  } catch (e) {
    errors.value = e.fields || {}
  }
}

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

    <div class="flex flex-wrap items-center gap-2 rounded-md border border-line bg-surface px-4 py-3 text-sm">
      <span class="text-muted">Alamat bio link kartu ini:</span>
      <span class="min-w-0 flex-1 truncate font-mono text-fg">{{ card.url }}</span>
      <AppButton variant="ghost" @click="copyUrl"><Copy class="size-5" /> Salin</AppButton>
    </div>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
      <form class="grid gap-6" @submit.prevent="save">
        <AppPanel title="Identitas">
          <div class="grid gap-4">
            <div class="flex items-center gap-4">
              <img v-if="profile.avatar_url" :src="profile.avatar_url" alt="Foto profil" class="size-24 rounded-full object-cover" />
              <span v-else class="grid size-24 place-items-center rounded-full bg-surface-2 font-display text-[32px] font-semibold text-muted">{{ initials(form.display_name) }}</span>
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

        <AppPanel title="Tampilan">
          <div class="grid gap-6">
            <div class="grid gap-2">
              <span class="label">Tema</span>
              <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <button
                  v-for="p in catalog.presets"
                  :key="p.key"
                  type="button"
                  :aria-pressed="theme.preset === p.key"
                  class="cursor-pointer grid gap-2 rounded-md border border-line p-1.5 text-left text-sm aria-pressed:border-accent aria-pressed:outline-2 aria-pressed:outline-accent"
                  @click="Object.assign(theme, { preset: p.key, accent: null })"
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
                <button type="button" :aria-pressed="!theme.accent" class="cursor-pointer inline-flex h-9 items-center gap-1 rounded-full border border-line px-3 text-sm aria-pressed:border-accent aria-pressed:text-accent-text" @click="theme.accent = null">
                  <RotateCcw class="size-4" /> Bawaan
                </button>
                <button
                  v-for="a in catalog.accents"
                  :key="a"
                  type="button"
                  :title="a"
                  :aria-label="`Aksen ${a}`"
                  :aria-pressed="theme.accent === a"
                  class="cursor-pointer size-9 rounded-full border border-line-strong aria-pressed:outline-2 aria-pressed:outline-offset-2 aria-pressed:outline-accent"
                  :style="{ background: `var(--color-${a})` }"
                  @click="theme.accent = a"
                ></button>
              </div>
              <p v-if="theme.accent && presetOf(theme.preset)?.accent_vars[theme.accent]?.accent !== theme.accent" class="text-sm text-muted">
                Warna ini terlalu mirip dengan latar, jadi tombol memakai warna teks agar tetap terbaca.
              </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
              <div v-for="o in LAYOUT" :key="o.key" class="grid gap-2">
                <span class="label">{{ o.label }}</span>
                <AppSegmented v-model="theme[o.key]" :label="o.label" :options="o.options" class="flex-wrap" />
              </div>
            </div>
            <AppToggle :model-value="theme.decoration === 'rings'" @update:model-value="(v) => (theme.decoration = v ? 'rings' : 'none')">Hiasan cincin sinyal di header</AppToggle>
          </div>
        </AppPanel>

        <AppPanel title="Kontak & tautan">
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
        <span class="label">Preview langsung</span>
        <div class="rounded-[28px] border border-line-strong bg-surface p-2.5">
          <div class="h-[700px] overflow-y-auto overscroll-contain rounded-[20px] scrollbar-none" tabindex="0" aria-label="Preview bio link (bisa di-scroll)">
            <PublicProfileView :profile="live" preview />
          </div>
        </div>
        <span class="text-sm text-muted">Perubahan terlihat langsung; tekan Simpan agar tampil saat kartu di-tap.</span>
      </aside>
    </div>
  </template>
</template>
