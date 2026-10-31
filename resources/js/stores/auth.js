import { defineStore } from 'pinia'
import { context } from '@/config'
import { authApi } from '@/api/auth'
import { meApi } from '@/api/workspace'
import { adminApi } from '@/api/admin'

/** Sesi pengguna (workspace) atau staf (admin). `me` = null berarti tamu. */
export const useAuthStore = defineStore('auth', {
  state: () => ({ me: null, loaded: false }),
  getters: {
    isLoggedIn: (s) => !!s.me,
    permissions: (s) => new Set(s.me?.permissions ?? []),
    workspace: (s) => s.me?.workspace ?? null,
    contactLabel: (s) => s.me?.workspace?.contact_label ?? 'Koneksi',
  },
  actions: {
    async load(force = false) {
      if (this.loaded && !force) return this.me
      try {
        const res = context === 'admin' ? await adminApi.me() : await meApi.get()
        this.me = res.data
      } catch {
        this.me = null
      }
      this.loaded = true
      return this.me
    },
    /** Izin dengan wildcard cakupan: `leads.view.*` cocok own/team/event/all. */
    can(permission) {
      if (permission.endsWith('.*')) {
        const prefix = permission.slice(0, -1)
        return [...this.permissions].some((p) => p.startsWith(prefix))
      }
      return this.permissions.has(permission)
    },
    async logout() {
      await authApi.logout().catch(() => {})
      this.me = null
      window.location.href = '/login'
    },
    clear() {
      this.me = null
      this.loaded = true
    },
  },
})
