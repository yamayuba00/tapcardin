<script setup>
// U-07 / W-09 — tombol cepat otomatis mencatat aktivitas (LD-04), tahap + alasan kalah wajib (LD-03), tag, timeline.
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowRight, Calendar, Info, Mail, MessageCircle, Nfc, Pencil, Phone, Plus, Trash2, X } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppField from '@/components/ui/AppField/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppModal from '@/components/ui/AppModal/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSelect from '@/components/ui/AppSelect/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import AppTextarea from '@/components/ui/AppTextarea/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import StageBadge from '@/components/common/StageBadge/index.vue'
import ReminderPanel from '@/components/leads/ReminderPanel/index.vue'
import { leadsApi } from '@/api/workspace'
import { useAction, useAsync } from '@/composables/useAsync'
import { ACTIVITY_LABELS, dateTime, rupiah, sourceLabel } from '@/lib/format'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const id = route.params.id
const { data: c, reload } = useAsync(() => leadsApi.get(id))
const { data: stages } = useAsync(() => leadsApi.stages())
const { busy, run } = useAction()

const note = ref('')
const tag = ref('')
const lost = ref(null)
const lostReason = ref('')
const removing = ref(false)
const lostReasons = computed(() => auth.workspace?.lost_reasons ?? [])

async function apply(fn, message) {
  const res = await run(fn, message).catch(() => null)
  if (res) c.value = res.data
  return res
}

function changeStage(stageId) {
  const s = stages.value.find((x) => x.id === stageId)
  if (s?.kind === 'lost') {
    lost.value = s
    lostReason.value = lostReasons.value[0]
    return
  }
  apply(() => leadsApi.stage(id, stageId), `Tahap: ${s.name}`)
}
async function confirmLost() {
  await apply(() => leadsApi.stage(id, lost.value.id, lostReason.value), 'Ditandai kalah')
  lost.value = null
}
async function addNote() {
  if (!note.value.trim()) return
  if (await apply(() => leadsApi.activity(id, 'note', note.value), 'Catatan ditambahkan')) note.value = ''
}
/** Tombol WA/telepon/email: buka aplikasi dan catat aktivitas otomatis. */
const quick = (type) => leadsApi.activity(id, type).then((r) => (c.value = r.data)).catch(() => {})
async function addTag() {
  const t = tag.value.trim()
  if (!t || c.value.tags.includes(t)) return
  if (await apply(() => leadsApi.update(id, { tags: [...c.value.tags, t] }))) tag.value = ''
}
const removeTag = (t) => apply(() => leadsApi.update(id, { tags: c.value.tags.filter((x) => x !== t) }))
async function destroy() {
  await run(() => leadsApi.remove(id)).catch(() => null)
  router.push('/connections')
}

const wa = computed(() => (c.value?.phone ? `https://wa.me/${c.value.phone.replace(/\D/g, '')}` : null))
const ICONS = { note: Pencil, call: Phone, whatsapp: MessageCircle, email: Mail, meeting: Calendar, stage_changed: ArrowRight, created: Plus, tap: Nfc }
const activityText = (a) => (a.type === 'stage_changed'
  ? `${a.meta.from_stage ?? '—'} → ${a.meta.to_stage}`
  : a.type === 'created' ? `Dari ${sourceLabel(a.meta.source)}${a.meta.deduplicated ? ' (digabung)' : ''}` : a.body)
</script>

<template>
  <div v-if="!c" class="grid gap-4" aria-busy="true"><AppSkeleton class="!h-8 w-2/5" /><AppSkeleton class="!h-72" /></div>
  <template v-else>
    <PageHeader :title="c.name" back="/connections" :back-label="auth.contactLabel">
      <template #meta><p class="text-muted">{{ [c.job_title, c.company].filter(Boolean).join(' · ') }}</p></template>
      <AppSelect
        v-if="stages"
        :model-value="c.stage?.id"
        :disabled="busy"
        :options="stages.map((s) => ({ value: s.id, label: s.name }))"
        aria-label="Tahap"
        class="!w-auto"
        @update:model-value="changeStage"
      />
    </PageHeader>

    <div class="flex flex-wrap gap-2">
      <AppButton v-if="wa" variant="secondary" :href="wa" target="_blank" rel="noopener" @click="quick('whatsapp')"><MessageCircle class="size-5" /> WhatsApp</AppButton>
      <AppButton v-if="c.phone" variant="secondary" :href="`tel:${c.phone}`" @click="quick('call')"><Phone class="size-5" /> Telepon</AppButton>
      <AppButton v-if="c.email" variant="secondary" :href="`mailto:${c.email}`" @click="quick('email')"><Mail class="size-5" /> Email</AppButton>
    </div>

    <ReminderPanel :lead-id="id" :reminders="c.reminders || []" :editable="auth.can('leads.edit.*')" @changed="reload" />

    <div class="grid gap-4 md:grid-cols-2">
      <AppPanel title="Detail">
        <div class="grid gap-4">
          <dl class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm break-words sm:gap-x-6">
            <dt class="text-muted">Tahap</dt><dd><StageBadge :stage="c.stage" /><span v-if="c.lost_reason" class="text-muted"> · {{ c.lost_reason }}</span></dd>
            <dt class="text-muted">Email</dt><dd>{{ c.email || '—' }}</dd>
            <dt class="text-muted">Telepon</dt><dd class="font-mono">{{ c.phone || '—' }}</dd>
            <dt class="text-muted">Sumber</dt><dd>{{ sourceLabel(c.source) }}<span v-if="c.card"> · kartu {{ c.card.label || c.card.token }}</span><span v-if="c.event"> · {{ c.event.name }}</span></dd>
            <dt v-if="c.external_url" class="text-muted">Tautan</dt><dd v-if="c.external_url" class="truncate"><a :href="c.external_url" target="_blank" rel="noopener nofollow">{{ c.external_url }}</a></dd>
            <dt class="text-muted">Nilai</dt><dd>{{ rupiah(c.value_amount) }}</dd>
            <dt class="text-muted">Persetujuan</dt><dd>{{ c.consent_at ? dateTime(c.consent_at) : '—' }}</dd>
            <dt class="text-muted">Dibuat</dt><dd>{{ dateTime(c.created_at) }}</dd>
          </dl>
          <div class="grid gap-2">
            <span class="text-sm font-medium text-muted">Tag</span>
            <div class="flex flex-wrap items-center gap-2">
              <button
                v-for="t in c.tags"
                :key="t"
                type="button"
                class="cursor-pointer inline-flex h-[26px] items-center gap-1 rounded-full border border-line-strong px-2.5 font-mono text-xs uppercase"
                :aria-label="`Hapus tag ${t}`"
                @click="removeTag(t)"
              >{{ t }} <X class="size-3" /></button>
              <form @submit.prevent="addTag"><AppInput v-model="tag" maxlength="30" placeholder="Tambah tag" aria-label="Tag baru" class="!min-h-10 !w-40" /></form>
            </div>
          </div>
          <div v-if="auth.can('leads.delete')"><AppButton variant="ghost" @click="removing = true"><Trash2 class="size-5" /> Hapus</AppButton></div>
        </div>
      </AppPanel>

      <AppPanel v-if="c.forms?.length" title="Jawaban formulir" class="md:col-span-2">
        <div class="grid gap-4">
          <article v-for="f in c.forms" :key="f.id" class="grid gap-3 rounded-md border border-line p-4">
            <header class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
              <h3 class="font-semibold">{{ f.title }}</h3>
              <span class="text-sm text-muted">{{ dateTime(f.created_at) }}<template v-if="f.card"> · {{ f.card.label }}</template><template v-if="f.event"> · {{ f.event }}</template></span>
            </header>
            <dl class="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-[minmax(120px,200px)_minmax(0,1fr)]">
              <template v-for="(a, i) in f.answers" :key="i">
                <dt class="text-muted">{{ a.label }}</dt>
                <dd class="whitespace-pre-line break-words">{{ Array.isArray(a.value) ? a.value.join(', ') : a.value }}</dd>
              </template>
            </dl>
          </article>
        </div>
      </AppPanel>

      <AppPanel title="Timeline">
        <form class="mb-4 grid gap-2" @submit.prevent="addNote">
          <AppTextarea v-model="note" placeholder="Tulis catatan…" aria-label="Catatan baru" />
          <div><AppButton type="submit" variant="secondary" :disabled="busy || !note.trim()">Simpan catatan</AppButton></div>
        </form>
        <ul>
          <li v-for="a in c.activities" :key="a.id" class="grid grid-cols-[40px_minmax(0,1fr)] items-start gap-x-3 gap-y-0.5 border-b border-line py-3 last:border-b-0 sm:grid-cols-[40px_minmax(0,1fr)_auto] sm:items-center">
            <span class="row-span-2 grid size-10 flex-none place-items-center rounded-full bg-surface-2 text-muted sm:row-span-1">
              <component :is="ICONS[a.type] || Info" class="size-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
              <span class="block font-medium">{{ ACTIVITY_LABELS[a.type] }}<span v-if="a.user" class="text-muted"> · {{ a.user }}</span></span>
              <span v-if="activityText(a)" class="text-sm break-words text-muted">{{ activityText(a) }}</span>
            </div>
            <span class="text-xs text-muted sm:text-sm">{{ dateTime(a.occurred_at) }}</span>
          </li>
        </ul>
      </AppPanel>
    </div>

    <AppModal v-if="lost" title="Alasan kalah" @close="lost = null">
      <AppField label="Pilih alasan (wajib)"><AppSelect v-model="lostReason" :options="lostReasons.map((r) => ({ value: r, label: r }))" /></AppField>
      <template #footer>
        <AppButton variant="secondary" @click="lost = null">Batal</AppButton>
        <AppButton :loading="busy" @click="confirmLost">Simpan</AppButton>
      </template>
    </AppModal>
    <AppModal v-if="removing" :title="`Hapus ${c.name}?`" @close="removing = false">
      <p>Data kontak dan timeline akan dihapus dari workspace ini.</p>
      <template #footer>
        <AppButton variant="secondary" @click="removing = false">Batal</AppButton>
        <AppButton variant="danger" :loading="busy" @click="destroy">Hapus</AppButton>
      </template>
    </AppModal>
  </template>
</template>
