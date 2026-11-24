/**
 * Router-guards (docs/ui-ux.md §Karta marshrutov):
 * - requiresAuth: net sessii → /login?redirect=…
 * - requiresGuest: uzhe voshyol → /dashboard
 * Verified email is enforced by the API, there is no separate route guard.
 */
import type { Router } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

export function setupGuards(router: Router): void {
  router.beforeEach(async (to) => {
    const meta = to.meta
    if (meta.requiresAuth !== true && meta.requiresGuest !== true) {
      return true
    }

    const auth = useAuthStore()

    // Posle F5 guard zhdyot tikhoe vosstanovlenie sessii, chtoby ne pinat zaloginennogo na /login.
    await auth.ensureRestored()

    if (meta.requiresAuth === true && !auth.isAuthenticated) {
      return { name: 'login', query: { redirect: to.fullPath } }
    }

    if (meta.requiresGuest === true && auth.isAuthenticated) {
      return { name: 'dashboard' }
    }

    return true
  })
}
