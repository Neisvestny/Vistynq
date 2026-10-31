<script setup lang="ts">
import { RouterLink } from 'vue-router'

withDefaults(
  defineProps<{
    variant?: 'primary' | 'ghost'
    size?: 'sm' | 'md' | 'lg'
    type?: 'button' | 'submit' | 'reset'
    disabled?: boolean
    href?: string
    /** Внутренний маршрут (vue-router) вместо href. */
    to?: string
  }>(),
  {
    variant: 'primary',
    size: 'md',
    type: 'button',
    disabled: false,
    href: undefined,
    to: undefined,
  }
)
</script>

<template>
  <RouterLink
    v-if="to"
    :to="to"
    class="ui-btn"
    :class="[`ui-btn--${variant}`, { [`ui-btn--${size}`]: size !== 'md' }]"
  >
    <slot />
  </RouterLink>

  <a
    v-else-if="href"
    :href="href"
    class="ui-btn"
    :class="[`ui-btn--${variant}`, { [`ui-btn--${size}`]: size !== 'md' }]"
  >
    <slot />
  </a>

  <button
    v-else
    :type="type"
    :disabled="disabled"
    class="ui-btn"
    :class="[`ui-btn--${variant}`, { [`ui-btn--${size}`]: size !== 'md' }]"
  >
    <slot />
  </button>
</template>
