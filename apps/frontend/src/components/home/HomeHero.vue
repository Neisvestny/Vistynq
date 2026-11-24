<template>
  <section class="hero">
    <div class="container hero__inner">
      <div class="hero__copy">
        <h1>
          {{ t('home.heroTitleBefore') }}<span class="accent">{{ t('home.heroTitleAccent') }}</span>
        </h1>

        <p class="lead">{{ t('home.heroLead') }}</p>

        <div class="hero__actions">
          <UiButton v-if="auth.isAuthenticated" size="lg" to="/dashboard">
            {{ t('home.openDashboard') }}
          </UiButton>
          <template v-else>
            <UiButton size="lg" to="/register">{{ t('home.register') }}</UiButton>
            <UiButton variant="ghost" size="lg" to="/login">{{ t('home.login') }}</UiButton>
          </template>
        </div>
      </div>

      <div class="hero__art" aria-hidden="true">
        <svg viewBox="0 0 460 360" fill="none" xmlns="http://www.w3.org/2000/svg">
          <defs>
            <linearGradient id="hero-card-fill" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0" stop-color="#ffffff" />
              <stop offset="1" stop-color="#f6f7fb" />
            </linearGradient>
            <filter id="hero-shadow" x="-30%" y="-30%" width="160%" height="180%">
              <feDropShadow
                dx="0"
                dy="10"
                stdDeviation="14"
                flood-color="#1b2440"
                flood-opacity="0.12"
              />
            </filter>
            <marker
              id="hero-arrow"
              viewBox="0 0 10 10"
              refX="9"
              refY="5"
              markerWidth="7"
              markerHeight="7"
              orient="auto"
            >
              <path d="M0 0 10 5 0 10Z" fill="#3b5bdb" />
            </marker>
            <marker
              id="hero-arrow-soft"
              viewBox="0 0 10 10"
              refX="9"
              refY="5"
              markerWidth="7"
              markerHeight="7"
              orient="auto"
            >
              <path d="M0 0 10 5 0 10Z" fill="#8d9ac2" />
            </marker>
          </defs>

          <circle cx="248" cy="178" r="158" fill="#eaeefc" opacity="0.7" />
          <circle cx="248" cy="178" r="112" stroke="#cdd4e3" stroke-dasharray="4 7" />

          <path
            d="M196 113 285 82"
            stroke="#3b5bdb"
            stroke-width="2"
            marker-end="url(#hero-arrow)"
          />
          <path
            d="M196 140 118 214"
            stroke="#3b5bdb"
            stroke-width="2"
            marker-end="url(#hero-arrow)"
          />
          <path
            d="M292 108 344 202"
            stroke="#8d9ac2"
            stroke-width="2"
            stroke-dasharray="6 6"
            marker-end="url(#hero-arrow-soft)"
          />
          <path
            d="M136 238 300 264"
            stroke="#8d9ac2"
            stroke-width="2"
            stroke-dasharray="6 6"
            marker-end="url(#hero-arrow-soft)"
          />

          <g filter="url(#hero-shadow)">
            <rect
              x="104"
              y="60"
              width="104"
              height="62"
              rx="10"
              fill="url(#hero-card-fill)"
              stroke="#3b5bdb"
              stroke-width="2"
            />
            <rect
              x="36"
              y="194"
              width="104"
              height="62"
              rx="10"
              fill="url(#hero-card-fill)"
              stroke="#cdd4e3"
            />
            <rect
              x="278"
              y="60"
              width="104"
              height="62"
              rx="10"
              fill="url(#hero-card-fill)"
              stroke="#cdd4e3"
            />
            <rect
              x="282"
              y="242"
              width="104"
              height="62"
              rx="10"
              fill="url(#hero-card-fill)"
              stroke="#ea840b"
              stroke-width="2"
            />
          </g>

          <rect x="116" y="74" width="56" height="7" rx="3.5" fill="#14161c" />
          <rect x="116" y="89" width="74" height="5" rx="2.5" fill="#8d9ac2" />
          <rect x="116" y="100" width="46" height="5" rx="2.5" fill="#8d9ac2" />

          <rect x="48" y="208" width="62" height="7" rx="3.5" fill="#14161c" />
          <rect x="48" y="223" width="78" height="5" rx="2.5" fill="#8d9ac2" />
          <rect x="48" y="234" width="38" height="5" rx="2.5" fill="#3b5bdb" />

          <rect x="290" y="74" width="52" height="7" rx="3.5" fill="#14161c" />
          <rect x="290" y="89" width="70" height="5" rx="2.5" fill="#8d9ac2" />

          <rect x="294" y="256" width="64" height="7" rx="3.5" fill="#14161c" />
          <rect x="294" y="271" width="76" height="5" rx="2.5" fill="#8d9ac2" />
          <rect x="294" y="282" width="48" height="5" rx="2.5" fill="#ea840b" />

          <g fill="#3b5bdb">
            <circle cx="196" cy="113" r="4" />
            <circle cx="196" cy="140" r="4" />
            <circle cx="136" cy="238" r="4" />
          </g>
        </svg>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import UiButton from '../ui/UiButton.vue'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const auth = useAuthStore()

onMounted(() => {
  void auth.ensureRestored()
})
</script>
