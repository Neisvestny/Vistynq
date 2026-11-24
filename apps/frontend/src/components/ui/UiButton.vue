<script setup lang="ts">
import { RouterLink } from 'vue-router'

const props = withDefaults(
  defineProps<{
    variant?: 'primary' | 'ghost' | 'danger'
    size?: 'sm' | 'md' | 'lg'
    type?: 'button' | 'submit' | 'reset'
    disabled?: boolean
    href?: string
    /** Vnutrenniy marshrut (vue-router) vmesto href. */
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

const classes = () => [
  'ui-btn',
  `ui-btn--${props.variant}`,
  { [`ui-btn--${props.size}`]: props.size !== 'md' },
]

/** RouterLink/<a> have no disabled attribute — block activation explicitly. */
function onClick(event: MouseEvent) {
  if (!props.disabled) return
  event.preventDefault()
  event.stopImmediatePropagation()
}
</script>

<template>
  <RouterLink
    v-if="to"
    :to="to"
    class="ui-btn"
    :class="classes()"
    :aria-disabled="disabled || undefined"
    :tabindex="disabled ? -1 : undefined"
    @click="onClick"
  >
    <slot />
  </RouterLink>

  <a
    v-else-if="href"
    :href="disabled ? undefined : href"
    class="ui-btn"
    :class="classes()"
    :aria-disabled="disabled || undefined"
    :tabindex="disabled ? -1 : undefined"
    @click="onClick"
  >
    <slot />
  </a>

  <button v-else :type="type" :disabled="disabled" class="ui-btn" :class="classes()">
    <slot />
  </button>
</template>
