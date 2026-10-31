<script setup lang="ts">
import {
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuPortal,
  DropdownMenuRoot,
  DropdownMenuTrigger,
} from 'reka-ui'

export interface MenuItem {
  label: string
  onSelect?: () => void
  disabled?: boolean
}

defineProps<{
  items: MenuItem[]
}>()
</script>

<template>
  <DropdownMenuRoot>
    <DropdownMenuTrigger as-child>
      <slot name="trigger" />
    </DropdownMenuTrigger>

    <DropdownMenuPortal>
      <DropdownMenuContent class="ui-menu__content">
        <DropdownMenuItem
          v-for="item in items"
          :key="item.label"
          class="ui-menu__item"
          :disabled="item.disabled"
          @select="item.onSelect?.()"
        >
          <slot name="item" :item="item">
            {{ item.label }}
          </slot>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenuPortal>
  </DropdownMenuRoot>
</template>
