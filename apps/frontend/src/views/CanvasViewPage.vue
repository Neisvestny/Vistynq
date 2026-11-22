<script setup lang="ts">
/**
 * CanvasView (docs/ui-ux.md §2) — страница канваса: загрузка/ошибки/шапка,
 * интерактивный рендер графа (R25), drag/resize + persist (R26),
 * меню/фильтры/поиск/теги (R27).
 */
import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { NodeChange, XYPosition } from '@vue-flow/core'
import { accessApi } from '@/api/access'
import { canvasApi } from '@/api/canvas'
import { ApiError } from '@/api/http'
import { notesApi } from '@/api/notes'
import { tagsApi } from '@/api/tags'
import { notify } from '@/api/notify'
import type { GraphNode, NoteDetail, Tag } from '@/api/types'
import CanvasBoard from '@/components/canvas/CanvasBoard.vue'
import CanvasSearch from '@/components/canvas/CanvasSearch.vue'
import ContextMenu, { type ContextMenuItem } from '@/components/canvas/ContextMenu.vue'
import ImportanceFilter from '@/components/canvas/ImportanceFilter.vue'
import TagMenu from '@/components/canvas/TagMenu.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiButton from '@/components/ui/UiButton.vue'
import { useAccessGuard } from '@/composables/useAccessGuard'
import { useContextMenu } from '@/composables/useContextMenu'
import { useGraph } from '@/composables/useGraph'
import { useNodePositionSync } from '@/composables/useNodePositionSync'
import type { SavePlan } from '@/composables/useNoteDraft'
import { noteDetailToGraphNode } from '@/lib/noteMapping'
import { useCanvasStore } from '@/stores/canvas'
import { useNotesStore } from '@/stores/notes'

/** Tiptap тяжёлый — грузим панель редактора только при первом открытии (R28). */
const NoteEditorModal = defineAsyncComponent(
  () => import('@/components/note/NoteEditorModal.vue')
)

type PaneMenuContext = { kind: 'pane'; flowPosition: XYPosition }
type NodeMenuContext = { kind: 'node'; nodeId: string }
type MenuContext = PaneMenuContext | NodeMenuContext

const route = useRoute()
const router = useRouter()

const store = useCanvasStore()
const notesStore = useNotesStore()
const { canEditCanvas, userId } = useAccessGuard()

const highlightedTag = ref<string | null>(null)
const tagMenuOpen = ref(false)
const importanceMenuOpen = ref(false)

/** Панель редактора заметки (R28). */
const editorOpen = ref(false)
const editorNoteId = ref<string | null>(null)

const { flowNodes, flowEdges, visibleNodes, brokenEdgesCount } = useGraph({
  editableLayout: true,
  selectable: true,
  highlightedTag,
})

const boardRef = ref<InstanceType<typeof CanvasBoard> | null>(null)
const contextMenu = useContextMenu<MenuContext>()
const contextMenuRef = ref<InstanceType<typeof ContextMenu> | null>(null)

const fileInputRef = ref<HTMLInputElement | null>(null)
const importAt = ref<XYPosition | null>(null)

const canvasId = computed(() => store.canvas?.id ?? null)
const positionSync = useNodePositionSync(canvasId)

/** Псевдонимы store для шаблона (v-if сузит тип, как с локальными ref). */
const graph = computed(() => store.canvas)
const loading = computed(() => store.loading)
const error = computed(() => store.error)

const canLayoutInteract = computed(() => {
  if (canEditCanvas.value) return true
  const uid = userId.value
  if (!uid) return false
  return notesStore.nodes.some((node) => node.authorId === uid)
})

const roleLabel = computed(() => {
  switch (store.canvas?.access.myRole) {
    case 'owner':
      return 'Владелец'
    case 'editor':
      return 'Редактор'
    case 'viewer':
      return 'Зритель'
    default:
      return ''
  }
})

function clampImportance(v: number): number {
  return Math.min(5, Math.max(1, Math.round(v || 3)))
}

async function loadGraph() {
  const id = route.params.id
  await store.load(typeof id === 'string' ? id : '')
  positionSync.syncFromNodes()
}

function applyPositionChange(id: string, position?: XYPosition) {
  if (!position) return
  positionSync.applyAndSchedule(id, { x: position.x, y: position.y })
}

function onNodesChange(changes: NodeChange[]) {
  for (const change of changes) {
    if (change.type === 'position' && change.position) {
      // Во время drag обновляем локально и дебаунсим сохранение.
      if (change.dragging) {
        positionSync.beginGesture(change.id)
        notesStore.updateNodeGeometry(change.id, { x: change.position.x, y: change.position.y })
        positionSync.scheduleSave(change.id)
      } else {
        applyPositionChange(change.id, change.position)
      }
    }
    if (change.type === 'dimensions' && change.dimensions && change.resizing) {
      // Промежуточный resize: обновляем ширину локально, persist — на resize-end.
      notesStore.updateNodeGeometry(change.id, { width: change.dimensions.width })
    }
  }
}

function onNodeDragStart(payload: { id: string; position: XYPosition }) {
  positionSync.beginGesture(payload.id)
}

function onNodeDrag(payload: { id: string; position: XYPosition }) {
  positionSync.beginGesture(payload.id)
  notesStore.updateNodeGeometry(payload.id, { x: payload.position.x, y: payload.position.y })
  positionSync.scheduleSave(payload.id)
}

function onNodeDragStop(payload: { id: string; position: XYPosition }) {
  positionSync.applyAndFlush(payload.id, { x: payload.position.x, y: payload.position.y })
}

function onNodeResizeEnd(payload: {
  id: string
  width: number
  height?: number
  x?: number
  y?: number
}) {
  const patch: { x?: number; y?: number; width: number } = { width: payload.width }
  if (typeof payload.x === 'number') patch.x = payload.x
  if (typeof payload.y === 'number') patch.y = payload.y
  positionSync.applyAndFlush(payload.id, patch)
}

function ensureNodeVisible(nodeId: string) {
  const node = notesStore.getNodeById(nodeId)
  if (!node) return
  const level = clampImportance(node.importance)
  if (notesStore.importanceFilter[level] === false) {
    notesStore.setImportanceEnabled(level, true)
  }
  if (!node.isRelevant && !notesStore.showIrrelevant) {
    notesStore.setShowIrrelevant(true)
  }
}

function onSearchSelect(nodeId: string) {
  ensureNodeVisible(nodeId)
  notesStore.select(nodeId)
  // Дадим Vue отрендерить ноду, если она была скрыта фильтрами.
  requestAnimationFrame(() => {
    boardRef.value?.focusNode(nodeId)
  })
}

function openEditor(nodeId: string) {
  notesStore.select(nodeId)
  editorNoteId.value = nodeId
  editorOpen.value = true
}

function onOpenAccess(noteId: string) {
  // Заглушка до R32: диалог доступа будет реализован отдельной задачей.
  notify({
    title: 'Доступ к заметке',
    description: 'Диалог управления доступом появится в R32',
    status: 'default',
  })
  void noteId
}

/**
 * После сохранения редактора (R28): сначала сбрасываем отложенную геометрию,
 * затем обновляем граф. Тело/теги/актуальность влияют на рёбра, поэтому для
 * них делаем тихий рефреш графа; остальные поля — локальный upsert ноды.
 */
async function onNoteSaved(detail: NoteDetail, plan: SavePlan): Promise<void> {
  await positionSync.flushAll()

  const needsGraphRefresh =
    plan.bodyMd !== undefined ||
    plan.manifestPatch?.tagIds !== undefined ||
    plan.manifestPatch?.isRelevant !== undefined
  const cid = canvasId.value

  if (needsGraphRefresh && cid) {
    try {
      const graph = await canvasApi.graph(cid)
      store.edges = graph.edges
      store.tags = graph.tags
      notesStore.setNodes(graph.nodes)
      positionSync.syncFromNodes()
      return
    } catch {
      // Тихий рефреш не должен ломать уже сохранённую заметку — падаем ниже
      // к локальному обновлению ноды.
    }
  }

  notesStore.upsertNode(noteDetailToGraphNode(detail, notesStore.getNodeById(detail.id), store.tags))
}

function isAuthorOrCanvasOwner(node: GraphNode): boolean {
  const uid = userId.value
  if (uid && node.authorId === uid) return true
  return store.canvas?.access.myRole === 'owner' || store.canvas?.ownerId === uid
}

function graphNodeFromNote(note: {
  id: string
  title?: string
  importance?: number
  isRelevant?: boolean
  visibility?: 'public' | 'private'
  x?: number
  y?: number
  width?: number
  deletedAt?: string | null
  authorId?: string
}): GraphNode {
  return {
    id: note.id,
    title: note.title ?? 'Без названия',
    importance: note.importance ?? 3,
    isRelevant: note.isRelevant ?? true,
    visibility: note.visibility ?? 'public',
    x: note.x ?? 0,
    y: note.y ?? 0,
    width: note.width ?? 220,
    deletedAt: note.deletedAt ?? null,
    tags: [],
    authorId: note.authorId ?? userId.value ?? '',
  }
}

function removeEdgesForNode(nodeId: string) {
  store.edges = store.edges.filter((e) => e.from !== nodeId && e.to !== nodeId)
}

async function createNoteAt(position: XYPosition) {
  const cid = store.canvas?.id
  if (!cid) return
  try {
    const note = await notesApi.create(cid, {
      x: Math.round(position.x),
      y: Math.round(position.y),
    })
    notesStore.upsertNode(graphNodeFromNote(note))
    notesStore.select(note.id)
    notify({ title: 'Заметка создана', status: 'success' })
  } catch (e) {
    notify({
      title: 'Не удалось создать заметку',
      description: e instanceof ApiError ? e.message : undefined,
      status: 'error',
    })
  }
}

async function deriveFromNode(nodeId: string) {
  const cid = store.canvas?.id
  const parent = notesStore.getNodeById(nodeId)
  if (!cid || !parent) return
  try {
    const res = await notesApi.derive(cid, {
      parentNoteId: nodeId,
      x: Math.round(parent.x + 40),
      y: Math.round(parent.y + 40),
    })
    notesStore.upsertNode(graphNodeFromNote(res.note))
    store.edges = [
      ...store.edges,
      {
        id: res.link.id,
        type: 'direct',
        from: res.link.fromNoteId,
        to: res.link.toNoteId,
        state: 'ok',
        label: null,
      },
    ]
    notesStore.select(res.note.id)
    notify({ title: 'Заметка создана от текущей', status: 'success' })
  } catch (e) {
    notify({
      title: 'Не удалось создать заметку',
      description: e instanceof ApiError ? e.message : undefined,
      status: 'error',
    })
  }
}

async function setImportance(nodeId: string, importance: number) {
  try {
    await notesApi.updateManifest(nodeId, { importance })
    notesStore.patchNode(nodeId, { importance })
  } catch (e) {
    notify({
      title: 'Не удалось изменить важность',
      description: e instanceof ApiError ? e.message : undefined,
      status: 'error',
    })
  }
}

async function setNodeRelevant(nodeId: string, isRelevant: boolean) {
  try {
    await notesApi.updateManifest(nodeId, { isRelevant })
    notesStore.patchNode(nodeId, { isRelevant })
  } catch (e) {
    notify({
      title: 'Не удалось обновить статус заметки',
      description: e instanceof ApiError ? e.message : undefined,
      status: 'error',
    })
  }
}

async function deleteNodeSoft(nodeId: string) {
  if (!window.confirm('Удалить заметку? Она попадёт в архив удалённых.')) return
  try {
    await notesApi.remove(nodeId)
    notesStore.removeNode(nodeId)
    removeEdgesForNode(nodeId)
    notify({ title: 'Заметка удалена', status: 'success' })
  } catch (e) {
    notify({
      title: 'Не удалось удалить заметку',
      description: e instanceof ApiError ? e.message : undefined,
      status: 'error',
    })
  }
}

async function toggleVisibility(node: GraphNode) {
  const next = (node.visibility ?? 'public') === 'public' ? 'private' : 'public'
  try {
    await accessApi.setNoteVisibility(node.id, next)
    notesStore.patchNode(node.id, { visibility: next })
    notify({
      title: next === 'private' ? 'Заметка стала приватной' : 'Заметка стала публичной',
      status: 'success',
    })
  } catch (e) {
    notify({
      title: 'Не удалось изменить видимость',
      description: e instanceof ApiError ? e.message : undefined,
      status: 'error',
    })
  }
}

function getTagIdsForNode(node: GraphNode): string[] {
  const byName = new Map(store.tags.map((t) => [t.name, t.id]))
  return node.tags.map((name) => byName.get(name)).filter((id): id is string => Boolean(id))
}

async function toggleNodeTag(node: GraphNode, tag: Tag, assigned: boolean) {
  const current = new Set(getTagIdsForNode(node))
  if (assigned) current.delete(tag.id)
  else current.add(tag.id)
  try {
    await notesApi.updateManifest(node.id, { tagIds: Array.from(current) })
    const names = store.tags.filter((t) => current.has(t.id)).map((t) => t.name)
    notesStore.patchNode(node.id, { tags: names })
  } catch (e) {
    notify({
      title: 'Не удалось обновить теги',
      description: e instanceof ApiError ? e.message : undefined,
      status: 'error',
    })
  }
}

async function createAndAssignTag(node: GraphNode) {
  const cid = store.canvas?.id
  if (!cid) return
  const name = window.prompt('Название нового тега')
  if (!name || !name.trim()) return
  try {
    const tag = await tagsApi.create(cid, { name: name.trim() })
    store.tags = [...store.tags, tag].sort((a, b) => a.name.localeCompare(b.name, 'ru'))
    const ids = Array.from(new Set([...getTagIdsForNode(node), tag.id]))
    await notesApi.updateManifest(node.id, { tagIds: ids })
    const names = store.tags.filter((t) => ids.includes(t.id)).map((t) => t.name)
    notesStore.patchNode(node.id, { tags: names })
    notify({ title: 'Тег создан и назначен', status: 'success' })
  } catch (e) {
    notify({
      title: 'Не удалось создать тег',
      description: e instanceof ApiError ? e.message : undefined,
      status: 'error',
    })
  }
}

function triggerImportAt(position: XYPosition) {
  importAt.value = position
  fileInputRef.value?.click()
}

async function onImportFileChange(event: Event) {
  const cid = store.canvas?.id
  const input = event.target as HTMLInputElement | null
  const file = input?.files?.[0]
  const pos = importAt.value
  if (input) input.value = ''
  importAt.value = null
  if (!cid || !file) return
  try {
    const note = await notesApi.createFromText(cid, {
      file,
      x: pos ? Math.round(pos.x) : undefined,
      y: pos ? Math.round(pos.y) : undefined,
    })
    notesStore.upsertNode(graphNodeFromNote(note))
    notesStore.select(note.id)
    notify({ title: 'Файл импортирован как заметка', status: 'success' })
  } catch (e) {
    notify({
      title: 'Не удалось импортировать файл',
      description: e instanceof ApiError ? e.message : undefined,
      status: 'error',
    })
  }
}

function buildNodeMenu(node: GraphNode): ContextMenuItem[] {
  const full = canEditCanvas.value
  if (!full) {
    return [
      {
        id: 'open',
        label: 'Открыть',
        onSelect: () => openEditor(node.id),
      },
    ]
  }

  const canManageAccess = isAuthorOrCanvasOwner(node)
  const tagNames = new Set(node.tags)
  const tagChildren: ContextMenuItem[] = [
    ...store.tags.map((tag) => ({
      id: `tag-${tag.id}`,
      label: tag.name,
      checked: tagNames.has(tag.name),
      onSelect: () => toggleNodeTag(node, tag, tagNames.has(tag.name)),
    })),
    { id: 'tag-sep', label: '', separator: true },
    { id: 'tag-new', label: 'Новый тег…', onSelect: () => createAndAssignTag(node) },
  ]

  const importanceChildren: ContextMenuItem[] = [1, 2, 3, 4, 5].map((level) => ({
    id: `imp-${level}`,
    label: `Важность ${level}`,
    checked: clampImportance(node.importance) === level,
    onSelect: () => setImportance(node.id, level),
  }))

  const items: ContextMenuItem[] = [
    {
      id: 'open',
      label: 'Открыть',
      onSelect: () => openEditor(node.id),
    },
    { id: 'derive', label: 'Создать от этой заметки', onSelect: () => deriveFromNode(node.id) },
    {
      id: 'link',
      label: 'Ссылка на другую заметку…',
      disabled: true,
      hint: 'R30',
      onSelect: () => notify({ title: 'Режим связывания появится в R30' }),
    },
    { id: 'tags', label: 'Теги', children: tagChildren },
    { id: 'importance', label: 'Важность', children: importanceChildren },
  ]

  if (canManageAccess) {
    const visLabel =
      (node.visibility ?? 'public') === 'public' ? 'Сделать приватной' : 'Сделать публичной'
    items.push(
      { id: 'visibility', label: visLabel, onSelect: () => toggleVisibility(node) },
      {
        id: 'access',
        label: 'Доступ…',
        disabled: true,
        hint: 'R32',
        onSelect: () => notify({ title: 'Диалог доступа появится в R32' }),
      }
    )
  }

  items.push(
    {
      id: 'irrelevant',
      label: 'Поместить в архив «неактуально»',
      onSelect: () => setNodeRelevant(node.id, false),
    },
    { id: 'delete', label: 'Удалить', danger: true, onSelect: () => deleteNodeSoft(node.id) }
  )

  return items
}

function buildPaneMenu(flowPosition: XYPosition): ContextMenuItem[] {
  const items: ContextMenuItem[] = []
  if (canEditCanvas.value) {
    items.push(
      { id: 'create', label: 'Создать заметку', onSelect: () => createNoteAt(flowPosition) },
      { id: 'import', label: 'Импортировать файл…', onSelect: () => triggerImportAt(flowPosition) }
    )
  }
  items.push({ id: 'zoom-center', label: 'Зум в центр', onSelect: () => boardRef.value?.fitAll() })
  return items
}

const menuItems = computed<ContextMenuItem[]>(() => {
  const ctx = contextMenu.context.value
  if (!ctx) return []
  if (ctx.kind === 'pane') return buildPaneMenu(ctx.flowPosition)
  const node = notesStore.getNodeById(ctx.nodeId)
  if (!node) return []
  return buildNodeMenu(node)
})

watch(
  () => [contextMenu.state.open, menuItems.value.length] as const,
  ([open, count]) => {
    if (open && count === 0) contextMenu.close()
  }
)

watch(
  [contextMenuRef, () => contextMenu.state.open],
  () => {
    const exposed = contextMenuRef.value?.menuRef as
      HTMLElement | { value: HTMLElement | null } | null | undefined
    contextMenu.menuEl.value =
      exposed && typeof exposed === 'object' && 'value' in exposed
        ? exposed.value
        : ((exposed as HTMLElement | null) ?? null)
  },
  { flush: 'post' }
)

function onPaneContextMenu(payload: { event: MouseEvent; flowPosition: XYPosition }) {
  const items = buildPaneMenu(payload.flowPosition)
  if (items.length === 0) return
  contextMenu.open(payload.event, { kind: 'pane', flowPosition: payload.flowPosition })
}

function onNodeContextMenu(payload: { event: MouseEvent; nodeId: string }) {
  contextMenu.open(payload.event, { kind: 'node', nodeId: payload.nodeId })
}

onMounted(() => {
  void loadGraph()
})

// Переход между канвасами на том же маршруте — перезагрузка графа
watch(
  () => route.params.id,
  async (id, prev) => {
    if (id !== prev) {
      await positionSync.flushAll()
      await loadGraph()
    }
  }
)

watch(
  () => store.canvas?.id,
  (id, prev) => {
    if (id && id !== prev) positionSync.syncFromNodes()
  }
)

onBeforeUnmount(() => {
  void positionSync.flushAll()
})
</script>

<template>
  <div class="canvas-view">
    <!-- Шапка канваса -->
    <header v-if="graph && !error" class="canvas-view__header">
      <div class="canvas-view__heading">
        <h1 class="canvas-view__title">{{ graph.title }}</h1>
        <UiBadge v-if="roleLabel">{{ roleLabel }}</UiBadge>
        <UiBadge v-if="graph.access.publicSlug" tone="accent">Публичный</UiBadge>
      </div>

      <div class="canvas-view__toolbar">
        <CanvasSearch v-if="store.canvas" :canvas-id="store.canvas.id" @select="onSearchSelect" />
        <TagMenu v-model:open="tagMenuOpen" v-model:highlighted-tag="highlightedTag" />
        <ImportanceFilter v-model:open="importanceMenuOpen" />
      </div>

      <div class="canvas-view__meta">
        <span v-if="brokenEdgesCount > 0" class="canvas-view__broken">
          {{ brokenEdgesCount }} связ. с удалёнными заметками
        </span>
        <span class="canvas-view__count">{{ visibleNodes.length }} зам.</span>
      </div>
    </header>

    <!-- Загрузка -->
    <div v-if="loading" class="canvas-view__board canvas-view__board--loading" aria-busy="true">
      <div class="canvas-view__skeleton" />
    </div>

    <!-- Ошибка -->
    <div v-else-if="error" class="canvas-view__error">
      <p class="canvas-view__error-title">
        {{
          error.status === 404 || error.status === 403
            ? 'Канвас недоступен'
            : 'Не удалось загрузить канвас'
        }}
      </p>
      <p class="canvas-view__error-text">{{ error.message }}</p>
      <div class="canvas-view__error-actions">
        <UiButton size="sm" @click="router.push({ name: 'dashboard' })">К списку канвасов</UiButton>
        <UiButton
          v-if="error.status !== 404 && error.status !== 403"
          variant="ghost"
          size="sm"
          @click="loadGraph"
        >
          Повторить
        </UiButton>
      </div>
    </div>

    <!-- Граф -->
    <div v-else class="canvas-view__board">
      <input
        ref="fileInputRef"
        type="file"
        accept=".md,.txt,text/markdown,text/plain"
        class="canvas-view__file-input"
        @change="onImportFileChange"
      />

      <CanvasBoard
        ref="boardRef"
        :nodes="flowNodes"
        :edges="flowEdges"
        :layout-editable="canLayoutInteract"
        @nodes-change="onNodesChange"
        @node-drag-start="onNodeDragStart"
        @node-drag="onNodeDrag"
        @node-drag-stop="onNodeDragStop"
        @node-resize-end="onNodeResizeEnd"
        @pane-context-menu="onPaneContextMenu"
        @node-context-menu="onNodeContextMenu"
        @node-open="openEditor"
      >
        <!-- Пустой канвас -->
        <div v-if="visibleNodes.length === 0" class="canvas-view__empty">
          <p class="canvas-view__empty-title">Здесь пока нет заметок</p>
          <p class="canvas-view__empty-text">
            Перетащите заметки, настройте фильтры и используйте поиск по канвасу.
          </p>
        </div>
      </CanvasBoard>

      <ContextMenu
        ref="contextMenuRef"
        :open="contextMenu.state.open"
        :x="contextMenu.state.x"
        :y="contextMenu.state.y"
        :items="menuItems"
        @close="contextMenu.close()"
      />

      <!-- R28: панель редактора заметки (read-only определяется внутри) -->
      <NoteEditorModal
        v-if="editorOpen"
        v-model:open="editorOpen"
        :note-id="editorNoteId"
        @saved="onNoteSaved"
        @open-access="onOpenAccess"
      />
    </div>
  </div>
</template>
