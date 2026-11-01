<script setup>
// LD-05 — pengingat follow-up untuk satu lead: pilihan cepat (besok 09.00, 3 hari, 1 minggu) atau waktu sendiri,
// tandai selesai, tunda 1 hari. Pengingat otomatis (24 jam setelah lead baru) ikut tampil di sini.
import { computed, ref } from 'vue'
import { BellPlus, Check, Clock, Sparkles, Trash2 } from 'lucide-vue-next'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppInput from '@/components/ui/AppInput/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import { leadsApi, remindersApi } from '@/api/workspace'
import { useAction } from '@/composables/useAsync'
import { dateTime, relative } from '@/lib/format'

const props = defineProps({ leadId: { type: String, required: true }, reminders: { type: Array, default: () => [] }, editable: Boolean })
const emit = defineEmits(['changed'])
const { busy, run } = useAction()
const custom = ref('')
const note = ref('')

const at = (days, hour = 9) => {
  const d = new Date()
  d.setDate(d.getDate() + days)
  d.setHours(hour, 0, 0, 0)
  return d
}
const QUICK = computed(() => [
  { label: 'Besok 09.00', date: at(1) },
  { label: '3 hari', date: at(3) },
  { label: '1 minggu', date: at(7) },
])
const minLocal = computed(() => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16))

async function add(date) {
  const res = await run(() => leadsApi.addReminder(props.leadId, date.toISOString(), note.value || null), `Pengingat ${dateTime(date.toISOString())}`).catch(() => null)
  if (res) {
    note.value = ''
    custom.value = ''
    emit('changed')
  }
}
async function act(fn, message) {
  if (await run(fn, message).catch(() => null)) emit('changed')
}
const snooze = (r) => act(() => remindersApi.update(r.id, { due_at: new Date(Math.max(Date.now(), Date.parse(r.due_at)) + 86400000).toISOString() }), 'Ditunda 1 hari')
</script>

<template>
  <AppPanel title="Follow-up">
    <div class="grid gap-4">
      <ul v-if="reminders.length" class="grid gap-2">
        <li v-for="r in reminders" :key="r.id" :class="['flex flex-wrap items-center gap-3 rounded-md border px-3 py-2', r.overdue ? 'border-danger' : 'border-line']">
          <component :is="r.is_auto ? Sparkles : Clock" :class="['size-5 flex-none', r.overdue ? 'text-danger' : 'text-accent-text']" aria-hidden="true" />
          <span class="min-w-0 flex-1">
            <span class="block font-medium">{{ r.overdue ? 'Terlambat · ' : '' }}{{ relative(r.due_at) }} <span class="font-normal text-muted">({{ dateTime(r.due_at) }})</span></span>
            <span v-if="r.note" class="block text-sm text-muted">{{ r.note }}</span>
          </span>
          <span v-if="editable" class="flex w-full flex-wrap gap-1 sm:w-auto">
            <AppButton variant="secondary" :disabled="busy" @click="act(() => remindersApi.update(r.id, { done: true }), 'Follow-up selesai')"><Check class="size-5" /> Selesai</AppButton>
            <AppButton variant="ghost" :disabled="busy" @click="snooze(r)">Tunda</AppButton>
            <AppButton variant="ghost" icon aria-label="Hapus pengingat" :disabled="busy" @click="act(() => remindersApi.remove(r.id), 'Pengingat dihapus')"><Trash2 class="size-4" /></AppButton>
          </span>
        </li>
      </ul>
      <p v-else class="text-sm text-muted">Belum ada jadwal follow-up.</p>

      <div v-if="editable" class="grid gap-2">
        <AppInput v-model="note" maxlength="200" placeholder="Catatan pengingat (opsional), mis. kirim penawaran" aria-label="Catatan pengingat" />
        <div class="flex flex-wrap items-center gap-2">
          <AppButton v-for="q in QUICK" :key="q.label" variant="secondary" :disabled="busy" @click="add(q.date)"><BellPlus class="size-5" /> {{ q.label }}</AppButton>
          <form class="flex w-full min-w-0 gap-2 sm:w-auto" @submit.prevent="custom && add(new Date(custom))">
            <AppInput v-model="custom" type="datetime-local" :min="minLocal" aria-label="Waktu pengingat" class="min-w-0 flex-1 sm:!w-auto sm:flex-none" />
            <AppButton type="submit" variant="ghost" :disabled="busy || !custom">Jadwalkan</AppButton>
          </form>
        </div>
      </div>
    </div>
  </AppPanel>
</template>
