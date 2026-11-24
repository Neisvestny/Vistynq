<script setup lang="ts">
import { computed, getCurrentInstance, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { X } from 'lucide-vue-next'
import {
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogOverlay,
  DialogPortal,
  DialogRoot,
  DialogTitle,
  DialogTrigger,
} from 'reka-ui'

const props = defineProps<{
  title?: string
  description?: string
  /** Controlled mode. If omitted, dialog manages its own open state (trigger slot). */
  open?: boolean
  /** Keep the dialog open during an in-flight submit. */
  busy?: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
}>()

const { t } = useI18n()

/**
 * Vue castira otsutstvuyushchiy Boolean-prop v false, poetomu `props.open !== undefined`
 * vsegda true i nekontroliruemyy dialog nikogda ne otkryvaetsya. Smotrim nalicie
 * proda v originalnom vnode — tam ego net, esli <UiDialog> ispolzuetsya bez :open.
 */
const instance = getCurrentInstance()
const isControlled = computed(() => instance?.vnode.props != null && 'open' in instance.vnode.props)
const rootProps = computed(() =>
  isControlled.value ? { open: props.open } : { defaultOpen: false }
)

const returnFocusTo = ref<HTMLElement | null>(null)

watch(
  () => props.open,
  (open, wasOpen) => {
    if (open && !wasOpen) returnFocusTo.value = document.activeElement as HTMLElement | null
  }
)

function onOpenChange(value: boolean) {
  if (props.busy && !value) return
  emit('update:open', value)
}

function onDismiss(event: { preventDefault: () => void }) {
  if (!props.busy) return
  event.preventDefault()
}

function onCloseAutoFocus(event: Event) {
  const target = returnFocusTo.value
  if (isControlled.value && target?.isConnected) {
    event.preventDefault()
    target.focus()
  }
  returnFocusTo.value = null
}
</script>

<template>
  <DialogRoot v-bind="rootProps" @update:open="onOpenChange">
    <DialogTrigger v-if="$slots.trigger" as-child>
      <slot name="trigger" />
    </DialogTrigger>

    <DialogPortal>
      <DialogOverlay class="ui-dialog__overlay" />
      <DialogContent
        class="ui-dialog__content"
        :aria-busy="busy || undefined"
        @escape-key-down="onDismiss"
        @pointer-down-outside="onDismiss"
        @close-auto-focus="onCloseAutoFocus"
      >
        <DialogTitle v-if="title || $slots.title" class="ui-dialog__title">
          <slot name="title">{{ title }}</slot>
        </DialogTitle>

        <DialogDescription v-if="description || $slots.description" class="ui-dialog__description">
          <slot name="description">{{ description }}</slot>
        </DialogDescription>

        <slot />

        <DialogClose as-child>
          <slot name="close">
            <button class="ui-dialog__close" type="button" :aria-label="t('common.close')">
              <X :size="18" aria-hidden="true" />
            </button>
          </slot>
        </DialogClose>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
