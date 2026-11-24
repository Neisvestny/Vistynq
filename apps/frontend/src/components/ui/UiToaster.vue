<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { CircleAlert, CircleCheck, Info, Info as InfoIcon, TriangleAlert, X } from 'lucide-vue-next'
import { ToastClose, ToastDescription, ToastRoot, ToastTitle, ToastViewport, useToastManager } from 'reka-ui'

const { t } = useI18n()
const { toasts } = useToastManager()

const statusIcons = {
  info: Info,
  success: CircleCheck,
  error: CircleAlert,
  warning: TriangleAlert,
} as const

function statusIcon(status: string | undefined) {
  return status && status in statusIcons ? statusIcons[status as keyof typeof statusIcons] : InfoIcon
}
</script>

<template>
  <ToastViewport
    as="div"
    class="ui-toast__viewport"
    :label="t('common.notifications')"
  >
    <div v-for="toast in toasts" :key="toast.id">
      <Transition name="toast-slide">
        <ToastRoot as="div" class="ui-toast__root" :toast="toast" :data-status="toast.status">
          <component :is="statusIcon(toast.status)" class="ui-toast__icon" aria-hidden="true" />
          <div>
            <ToastTitle class="ui-toast__title">
              {{ toast.title }}
            </ToastTitle>
            <ToastDescription v-if="toast.description" as="p" class="ui-toast__description">
              {{ toast.description }}
            </ToastDescription>
          </div>

          <ToastClose class="ui-toast__close" :aria-label="t('common.closeNotification')">
            <X :size="16" aria-hidden="true" />
          </ToastClose>
        </ToastRoot>
      </Transition>
    </div>
  </ToastViewport>
</template>
