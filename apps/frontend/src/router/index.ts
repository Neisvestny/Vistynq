/**
 * Карта маршрутов (docs/ui-ux.md; решение: Dashboard на /dashboard, лендинг на /).
 */
import { createRouter, createWebHistory } from 'vue-router'
import { setupGuards } from './guards'
import AppLayout from '@/layouts/AppLayout.vue'
import LandingLayout from '@/layouts/LandingLayout.vue'

export const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      component: LandingLayout,
      children: [{ path: '', name: 'home', component: () => import('@/views/HomePage.vue') }],
    },
    {
      // Auth-экраны: центрированная карточка без шапки приложения.
      path: '/',
      component: () => import('@/layouts/AuthLayout.vue'),
      children: [
        {
          path: 'login',
          name: 'login',
          component: () => import('@/views/auth/LoginPage.vue'),
          meta: { requiresGuest: true },
        },
        {
          path: 'register',
          name: 'register',
          component: () => import('@/views/auth/RegisterPage.vue'),
          meta: { requiresGuest: true },
        },
        {
          path: 'verify-email',
          name: 'verify-email',
          component: () => import('@/views/auth/VerifyEmailPage.vue'),
        },
        {
          path: 'forgot-password',
          name: 'forgot-password',
          component: () => import('@/views/auth/ForgotPasswordPage.vue'),
          meta: { requiresGuest: true },
        },
        {
          path: 'reset-password',
          name: 'reset-password',
          component: () => import('@/views/auth/ResetPasswordPage.vue'),
        },
      ],
    },
    {
      path: '/',
      component: AppLayout,
      children: [
        {
          path: 'dashboard',
          name: 'dashboard',
          component: () => import('@/views/DashboardPage.vue'),
          meta: { requiresAuth: true },
        },
        {
          path: 'canvas/:id',
          name: 'canvas',
          component: () => import('@/views/CanvasViewPage.vue'),
          meta: { requiresAuth: true, requiresVerified: true },
        },
        {
          path: 'profile',
          name: 'profile',
          component: () => import('@/views/ProfilePage.vue'),
          meta: { requiresAuth: true },
        },
      ],
    },
    {
      path: '/shared/canvas/:slug',
      name: 'shared-canvas',
      component: () => import('@/views/SharedCanvasPage.vue'),
    },
    {
      path: '/shared/canvas/:slug/note/:noteId',
      name: 'shared-note',
      component: () => import('@/views/SharedNotePage.vue'),
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundPage.vue'),
    },
  ],
  scrollBehavior(to, from, savedPosition) {
    if (savedPosition) {
      return savedPosition
    }
    if (to.hash) {
      return { el: to.hash }
    }

    return { top: 0 }
  },
})

setupGuards(router)
