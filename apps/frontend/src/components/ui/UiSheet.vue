<script setup lang="ts">
import { ref, watch } from 'vue'
import { DialogContent, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui'
import { X } from 'lucide-vue-next'
import { useI18n } from 'vue-i18n'

const props = withDefaults(
  defineProps<{
    open?: boolean
    side?: 'left' | 'right'
    title?: string
    /** Wide panel for lists with tags and buttons (archive, R29). */
    wide?: boolean
    /**
     * Do not close the panel automatically (Esc/click outside/✕), but request
     * close via `close-request` event. Used by note editor with unsaved draft (R28).
     */
    preventClose?: boolean
  }>(),
  {
    open: false,
    side: 'left',
    title: undefined,
    wide: false,
    preventClose: false,
  }
)

const emit = defineEmits<{
  'update:open': [value: boolean]
  'close-request': []
}>()

const { t } = useI18n()
const returnFocusTo = ref<HTMLElement | null>(null)

watch(
  () => props.open,
  (open, wasOpen) => {
    if (open && !wasOpen) returnFocusTo.value = document.activeElement as HTMLElement | null
  }
)

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

function onCloseAutoFocus(event: Event): void {
  const target = returnFocusTo.value
  if (target?.isConnected) {
    event.preventDefault()
    target.focus()
  }
  returnFocusTo.value = null
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
          :class="[`ui-sheet__content--${side}`, { 'ui-sheet__content--wide': wide }]"
          :aria-describedby="undefined"
          @escape-key-down="onDismiss"
          @pointer-down-outside="onDismiss"
          @focus-outside="onDismiss"
          @close-auto-focus="onCloseAutoFocus"
        >
          <DialogTitle v-if="title || $slots.title" class="ui-sheet__title">
            <slot name="title">{{ title }}</slot>
          </DialogTitle>

          <div class="ui-sheet__body">
            <slot />
          </div>

          <footer v-if="$slots.footer" class="ui-sheet__footer">
            <slot name="footer" />
          </footer>

          <slot name="close">
            <button
              class="ui-sheet__close"
              :class="'ui-sheet__close--' + side"
              :aria-label="t('common.close')"
              @click="requestClose"
            >
              <X :size="18" aria-hidden="true" />
            </button>
          </slot>
        </DialogContent>
      </Transition>
    </DialogPortal>
  </DialogRoot>
</template>
