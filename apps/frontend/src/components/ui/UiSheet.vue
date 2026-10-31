<script setup lang="ts">
import {
  DialogClose,
  DialogContent,
  DialogOverlay,
  DialogPortal,
  DialogRoot,
  DialogTitle,
} from 'reka-ui'

withDefaults(
  defineProps<{
    open?: boolean
    side?: 'left' | 'right'
    title?: string
  }>(),
  {
    open: false,
    side: 'left',
    title: undefined,
  }
)

defineEmits<{
  'update:open': [value: boolean]
}>()
</script>

<template>
  <DialogRoot :open="open" @update:open="$emit('update:open', $event)">
    <DialogPortal>
      <Transition name="sheet-overlay">
        <DialogOverlay class="ui-sheet__overlay" />
      </Transition>

      <Transition :name="`sheet-panel--${side}`">
        <DialogContent
          class="ui-sheet__content"
          :class="`ui-sheet__content--${side}`"
          :aria-describedby="undefined"
        >
          <DialogTitle v-if="title || $slots.title" class="ui-sheet__title">
            <slot name="title">{{ title }}</slot>
          </DialogTitle>

          <div class="ui-sheet__body">
            <slot />
          </div>

          <DialogClose as-child>
            <slot name="close">
              <button class="ui-sheet__close" aria-label="Закрыть меню">
                <svg
                  width="18"
                  height="18"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  aria-hidden="true"
                >
                  <path d="M18 6 6 18" />
                  <path d="m6 6 12 12" />
                </svg>
              </button>
            </slot>
          </DialogClose>
        </DialogContent>
      </Transition>
    </DialogPortal>
  </DialogRoot>
</template>
