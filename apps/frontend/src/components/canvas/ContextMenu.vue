<script lang="ts">
export interface ContextMenuItem {
  id: string
  label: string
  disabled?: boolean
  danger?: boolean
  checked?: boolean
  hint?: string
  separator?: boolean
  children?: ContextMenuItem[]
  onSelect?: () => void | Promise<void>
}
</script>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const props = defineProps<{
  open: boolean
  x: number
  y: number
  items: ContextMenuItem[]
}>()

const emit = defineEmits<{
  close: []
}>()

const menuRef = ref<HTMLElement | null>(null)
const openSubmenuId = ref<string | null>(null)
let returnFocusTo: HTMLElement | null = null

function menuButtons(scope: HTMLElement | null = menuRef.value): HTMLButtonElement[] {
  return Array.from(scope?.querySelectorAll<HTMLButtonElement>('.canvas-context-menu__button:not(:disabled)') ?? [])
}

function focusFirstButton(): void {
  const button = menuButtons()[0]
  button?.focus()
}

function onMenuKeydown(event: KeyboardEvent): void {
  const target = event.target as HTMLElement
  const buttons = menuButtons(target.closest('.canvas-context-menu__submenu') as HTMLElement | null ?? menuRef.value)
  const inSubmenu = Boolean(target.closest('.canvas-context-menu__submenu'))
  const index = buttons.indexOf(target as HTMLButtonElement)

  switch (event.key) {
    case 'ArrowDown':
    case 'ArrowUp': {
      if (!buttons.length) return
      const delta = event.key === 'ArrowDown' ? 1 : -1
      buttons[(index + delta + buttons.length) % buttons.length]?.focus()
      break
    }
    case 'Home':
      buttons[0]?.focus()
      break
    case 'End':
      buttons[buttons.length - 1]?.focus()
      break
    case 'ArrowRight': {
      const item = props.items.find((candidate) => candidate.label === target.textContent?.trim())
      if (item?.children?.length) onMouseEnter(item)
      break
    }
    case 'ArrowLeft':
      if (inSubmenu) {
        openSubmenuId.value = null
        menuButtons()[0]?.focus()
      }
      break
    case 'Escape':
      emit('close')
      break
    default:
      return
  }
  event.preventDefault()
  event.stopPropagation()
}

const style = computed(() => {
  const padding = 8
  const width = 260
  const height = 360
  const maxX = typeof window !== 'undefined' ? window.innerWidth - width - padding : props.x
  const maxY = typeof window !== 'undefined' ? window.innerHeight - height - padding : props.y
  return {
    left: `${Math.max(padding, Math.min(props.x, maxX))}px`,
    top: `${Math.max(padding, Math.min(props.y, maxY))}px`,
  }
})

watch(
  () => props.open,
  async (open) => {
    if (open) {
      returnFocusTo = document.activeElement as HTMLElement | null
      await nextTick()
      focusFirstButton()
      return
    }
    openSubmenuId.value = null
    if (returnFocusTo?.isConnected) returnFocusTo.focus()
    returnFocusTo = null
  }
)

function onItemClick(item: ContextMenuItem) {
  if (item.disabled || item.separator) return
  if (item.children && item.children.length) {
    openSubmenuId.value = openSubmenuId.value === item.id ? null : item.id
    return
  }
  void item.onSelect?.()
  emit('close')
}

function onSubItemClick(item: ContextMenuItem) {
  if (item.disabled || item.separator) return
  void item.onSelect?.()
  emit('close')
}

function onMouseEnter(item: ContextMenuItem) {
  if (item.children && item.children.length) {
    openSubmenuId.value = item.id
  } else {
    openSubmenuId.value = null
  }
}

defineExpose({ menuRef })
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      ref="menuRef"
      class="canvas-context-menu"
      :style="style"
      role="menu"
      :aria-label="t('common.menu')"
      tabindex="-1"
      @keydown="onMenuKeydown"
      @contextmenu.prevent
    >
      <ul class="canvas-context-menu__list">
        <template v-for="item in items" :key="item.id">
          <li v-if="item.separator" class="canvas-context-menu__separator" role="separator" />
          <li
            v-else
            class="canvas-context-menu__item"
            :class="{
              'canvas-context-menu__item--disabled': item.disabled,
              'canvas-context-menu__item--danger': item.danger,
              'canvas-context-menu__item--open': openSubmenuId === item.id,
            }"
            role="none"
            @mouseenter="onMouseEnter(item)"
          >
            <button
              type="button"
              class="canvas-context-menu__button"
              role="menuitem"
              :disabled="item.disabled"
              @click="onItemClick(item)"
            >
              <span class="canvas-context-menu__label">
                <span v-if="item.checked === true" class="canvas-context-menu__check">✓</span>
                <span
                  v-else-if="item.checked === false"
                  class="canvas-context-menu__check canvas-context-menu__check--empty"
                />
                {{ item.label }}
              </span>
              <span v-if="item.hint" class="canvas-context-menu__hint">{{ item.hint }}</span>
              <span v-else-if="item.children?.length" class="canvas-context-menu__arrow">›</span>
            </button>

            <div
              v-if="item.children?.length && openSubmenuId === item.id"
              class="canvas-context-menu canvas-context-menu__submenu"
              role="menu"
            >
              <ul class="canvas-context-menu__list">
                <template v-for="child in item.children" :key="child.id">
                  <li
                    v-if="child.separator"
                    class="canvas-context-menu__separator"
                    role="separator"
                  />
                  <li
                    v-else
                    class="canvas-context-menu__item"
                    :class="{
                      'canvas-context-menu__item--disabled': child.disabled,
                      'canvas-context-menu__item--danger': child.danger,
                    }"
                    role="none"
                  >
                    <button
                      type="button"
                      class="canvas-context-menu__button"
                      role="menuitem"
                      :disabled="child.disabled"
                      @click="onSubItemClick(child)"
                    >
                      <span class="canvas-context-menu__label">
                        <span v-if="child.checked === true" class="canvas-context-menu__check"
                          >✓</span
                        >
                        <span
                          v-else-if="child.checked === false"
                          class="canvas-context-menu__check canvas-context-menu__check--empty"
                        />
                        {{ child.label }}
                      </span>
                      <span v-if="child.hint" class="canvas-context-menu__hint">{{
                        child.hint
                      }}</span>
                    </button>
                  </li>
                </template>
              </ul>
            </div>
          </li>
        </template>
      </ul>
    </div>
  </Teleport>
</template>

<style scoped>
.canvas-context-menu {
  position: fixed;
  z-index: var(--z-overlay);
  min-width: 220px;
  max-width: 320px;
  padding: 0.3rem;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md, 10px);
  background: var(--color-surface-raised, var(--color-surface));
  box-shadow: var(--shadow-lg, var(--shadow-md));
}

.canvas-context-menu__submenu {
  position: absolute;
  top: -0.3rem;
  left: calc(100% - 4px);
  min-width: 220px;
  max-height: 320px;
  overflow: auto;
}

.canvas-context-menu__list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.canvas-context-menu__item {
  position: relative;
}

.canvas-context-menu__button {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.45rem 0.55rem;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--color-text);
  font-size: 0.875rem;
  text-align: left;
  cursor: pointer;
}

.canvas-context-menu__button:hover:not(:disabled),
.canvas-context-menu__item--open > .canvas-context-menu__button {
  background: var(--color-surface-alt, rgba(0, 0, 0, 0.04));
}

.canvas-context-menu__button:disabled {
  opacity: 0.45;
  cursor: default;
}

.canvas-context-menu__item--danger > .canvas-context-menu__button {
  color: var(--color-danger, #b42318);
}

.canvas-context-menu__label {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  min-width: 0;
}

.canvas-context-menu__check {
  width: 0.9rem;
  display: inline-flex;
  justify-content: center;
  color: var(--color-accent);
  font-size: 0.8rem;
}

.canvas-context-menu__check--empty {
  visibility: hidden;
}

.canvas-context-menu__hint,
.canvas-context-menu__arrow {
  color: var(--color-text-muted);
  font-size: 0.8rem;
  flex: 0 0 auto;
}

.canvas-context-menu__separator {
  height: 1px;
  margin: 0.25rem 0.2rem;
  background: var(--color-border-subtle, var(--color-border));
}
</style>
