<script setup>
// PL-03 detail — workspace yang diikuti, kartu, blokir, paksa keluar semua sesi.
import { useRoute } from 'vue-router'
import AppBadge from '@/components/ui/AppBadge/index.vue'
import AppButton from '@/components/ui/AppButton/index.vue'
import AppPanel from '@/components/ui/AppPanel/index.vue'
import AppSkeleton from '@/components/ui/AppSkeleton/index.vue'
import CardsTable from '@/components/admin/CardsTable/index.vue'
import PageHeader from '@/components/common/PageHeader/index.vue'
import { adminApi } from '@/api/admin'
import { useAction, useAsync } from '@/composables/useAsync'
import { usePagedList } from '@/composables/usePagedList'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()
const id = route.params.id
const { data: u, reload } = useAsync(() => adminApi.user(id))
const cards = usePagedList((p) => adminApi.userCards(id, p), {}, { url: false, sort: '-updated_at', perPage: 10 })
const { busy, run } = useAction()

async function act(fn, message) {
  await run(fn, message).catch(() => null)
  reload()
}
</script>

<template>
  <AppSkeleton v-if="!u" class="!h-60" />
  <template v-else>
    <PageHeader :title="u.name" back="/users" back-label="Pengguna">
      <template #meta><p class="text-muted">{{ u.email }}<span v-if="u.handle" class="font-mono"> · @{{ u.handle }}</span></p></template>
      <template v-if="auth.can('users.manage')">
        <AppButton variant="secondary" :loading="busy" @click="act(() => adminApi.logoutUser(id), 'Semua sesi diakhiri.')">Paksa keluar ({{ u.sessions }} sesi)</AppButton>
        <AppButton v-if="u.status === 'blocked'" variant="secondary" :loading="busy" @click="act(() => adminApi.unblockUser(id), 'Blokir dibuka.')">Buka blokir</AppButton>
        <AppButton v-else variant="danger" :loading="busy" @click="act(() => adminApi.blockUser(id), 'Pengguna diblokir dan semua sesinya diakhiri.')">Blokir</AppButton>
      </template>
    </PageHeader>

    <AppPanel title="Workspace yang diikuti">
      <ul>
        <li v-for="m in u.memberships" :key="m.tenant_id" class="flex items-center justify-between border-b border-line py-3 last:border-b-0">
          <RouterLink :to="`/tenants/${m.tenant_id}`" class="no-underline">
            <span class="block font-medium text-fg">{{ m.name }}</span>
            <span class="text-sm text-muted">{{ m.type === 'personal' ? 'Personal' : 'Perusahaan' }}</span>
          </RouterLink>
          <AppBadge tone="outline">{{ m.role }}</AppBadge>
        </li>
      </ul>
    </AppPanel>

    <CardsTable :list="cards" :show="['batch']" empty-text="Belum ada kartu." />
  </template>
</template>
