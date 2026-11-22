/**
 * Router-guards (docs/ui-ux.md §Karta marshrutov):
 * - requiresAuth: net sessii → /auth/login?redirect=…
 * - requiresGuest: uzhe voshyol → /dashboard
 * - requiresVerified: email ne podtverzhdyon → tost + redirekt (api.md: CRUD zablokirovan)
 */
import type { Router } from 'vue-router'
import { tGlobal } from '@/i18n'
import { notify } from '@/api/notify'
import { useAuthStore } from '@/stores/auth'

export function setupGuards(router: Router): void {
  router.beforeEach(async (to) => {
    const meta = to.meta
    if (
      meta.requiresAuth !== true &&
      meta.requiresGuest !== true &&
      meta.requiresVerified !== true
    ) {
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

    if (meta.requiresVerified === true && auth.isAuthenticated && !auth.isVerified) {
      notify({
        title: tGlobal('auth.emailNotVerified'),
        description: tGlobal('auth.emailNotVerifiedDescription'),
        status: 'error',
      })

      return { name: 'dashboard' }
    }

    return true
  })
}
