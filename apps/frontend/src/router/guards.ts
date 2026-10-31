/**
 * Router-guards (docs/ui-ux.md §Карта маршрутов):
 * - requiresAuth: нет сессии → /auth/login?redirect=…
 * - requiresGuest: уже вошёл → /dashboard
 * - requiresVerified: email не подтверждён → тост + редирект (api.md: CRUD заблокирован)
 */
import type { Router } from 'vue-router'
import { notify } from '@/api/notify'
import { useAuthStore } from '@/stores/auth'

export function setupGuards(router: Router): void {
  router.beforeEach(async (to) => {
    const meta = to.meta
    if (meta.requiresAuth !== true && meta.requiresGuest !== true && meta.requiresVerified !== true) {
      return true
    }

    const auth = useAuthStore()

    // После F5 guard ждёт тихое восстановление сессии, чтобы не пинать залогиненного на /login.
    await auth.ensureRestored()

    if (meta.requiresAuth === true && !auth.isAuthenticated) {
      return { name: 'login', query: { redirect: to.fullPath } }
    }

    if (meta.requiresGuest === true && auth.isAuthenticated) {
      return { name: 'dashboard' }
    }

    if (meta.requiresVerified === true && auth.isAuthenticated && !auth.isVerified) {
      notify({
        title: 'Email не подтверждён',
        description: 'Подтвердите почту по ссылке из письма — редактирование доступно после верификации',
        status: 'error',
      })

      return { name: 'dashboard' }
    }

    return true
  })
}
