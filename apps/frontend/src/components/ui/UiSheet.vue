<script setup lang="ts">
import {
  DialogContent,
  DialogOverlay,
  DialogPortal,
  DialogRoot,
  DialogTitle,
} from 'reka-ui'

const props = withDefaults(
  defineProps<{
    open?: boolean
    side?: 'left' | 'right'
    title?: string
    /**
     * Не закрывать панель автоматически (Esc/клика вне/✕), а запросить
     * закрытие через событие `close-request`. Используется редактором
     * заметки с несохранённым черновиком (R28).
     */
    preventClose?: boolean
  }>(),
  {
    open: false,
    side: 'left',
    title: undefined,
    preventClose: false,
  }
)

const emit = defineEmits<{
  'update:open': [value: boolean]
  'close-request': []
}>()

function requestClose(): void {
  if (props.preventClose) {
    emit('close-request')
    return
  }
  emit('update:open', false)
}

function onOpenChange(value: boolean): void {
  if (!value) {
    requestClose()
    return
  }
  emit('update:open', true)
}

function onDismiss(event: { preventDefault: () => void }): void {
  if (!props.preventClose) return
  event.preventDefault()
  emit('close-request')
}
</script>

<template>
  <DialogRoot :open="open" @update:open="onOpenChange">
    <DialogPortal>
      <Transition name="sheet-overlay">
        <DialogOverlay class="ui-sheet__overlay" />
      </Transition>

      <Transition :name="`sheet-panel--${side}`">
        <DialogContent
          class="ui-sheet__content"
          :class="`ui-sheet__content--${side}`"
          :aria-describedby="undefined"
          @escape-key-down="onDismiss"
          @pointer-down-outside="onDismiss"
          @focus-outside="onDismiss"
        >
          <DialogTitle v-if="title || $slots.title" class="ui-sheet__title">
            <slot name="title">{{ title }}</slot>
          </DialogTitle>

          <div class="ui-sheet__body">
            <slot />
          </div>

          <slot name="close">
            <button class="ui-sheet__close" aria-label="Закрыть меню" @click="requestClose">
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
        </DialogContent>
      </Transition>
    </DialogPortal>
  </DialogRoot>
</template>
