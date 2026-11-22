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
import { linksApi } from '@/api/links'
import { notesApi } from '@/api/notes'
import { tagsApi } from '@/api/tags'
import { notify } from '@/api/notify'
import type { GraphEdge, GraphNode, Note, NoteDetail, Tag } from '@/api/types'
import ArchivePanel from '@/components/canvas/ArchivePanel.vue'
import CanvasBoard from '@/components/canvas/CanvasBoard.vue'
import CanvasSearch from '@/components/canvas/CanvasSearch.vue'
import ContextMenu, { type ContextMenuItem } from '@/components/canvas/ContextMenu.vue'
import ImportanceFilter from '@/components/canvas/ImportanceFilter.vue'
import LinkLayer from '@/components/canvas/LinkLayer.vue'
import TagMenu from '@/components/canvas/TagMenu.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiButton from '@/components/ui/UiButton.vue'
import { useAccessGuard } from '@/composables/useAccessGuard'
import { useContextMenu } from '@/composables/useContextMenu'
import { useGraph } from '@/composables/useGraph'
import { useNodePositionSync } from '@/composables/useNodePositionSync'
import type { SavePlan } from '@/composables/useNoteDraft'
import type { ArchiveChange } from '@/lib/archive'
import { noteDetailToGraphNode, noteToGraphNode } from '@/lib/noteMapping'
import { useCanvasStore } from '@/stores/canvas'
import { useNotesStore } from '@/stores/notes'

/** Tiptap тяжёлый — грузим панель редактора только при первом открытии (R28). */
const NoteEditorModal = defineAsyncComponent(() => import('@/components/note/NoteEditorModal.vue'))

type PaneMenuContext = { kind: 'pane'; flowPosition: XYPosition }
type NodeMenuContext = { kind: 'node'; nodeId: string }
type EdgeMenuContext = { kind: 'edge'; edgeId: string }
type MenuContext = PaneMenuContext | NodeMenuContext | EdgeMenuContext

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

/** Панель архива (R29). */
const archiveOpen = ref(false)

/** Режим связывания (R30). */
const linkingSourceId = ref<string | null>(null)
const linkingPending = ref(false)

/** Выбранное ребро (R30). */
const selectedEdgeId = ref<string | null>(null)

const { flowNodes, flowEdges, visibleNodes, brokenEdgesCount } = useGraph({
  editableLayout: true,
  selectable: true,
  highlightedTag,
  selectedEdgeId,
  linkingSourceId,
})

const boardRef = ref<InstanceType<typeof CanvasBoard> | null>(null)
const contextMenu = useContextMenu<MenuContext>()
const contextMenuRef = ref<InstanceType<typeof ContextMenu> | null>(null)

const fileInputRef = ref<HTMLInputElement | null>(null)
const importAt = ref<XYPosition | null>(null)
const canvasFileDragDepth = ref(0)
const isCanvasFileDragging = computed(() => canvasFileDragDepth.value > 0)

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
  selectedEdgeId.value = null
  cancelLinking()
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
  archiveOpen.value = false
  notesStore.select(nodeId)
  editorNoteId.value = nodeId
  editorOpen.value = true
}

function openArchive() {
  editorOpen.value = false
  archiveOpen.value = true
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
 * Тихий рефреш графа после изменений, влияющих на рёбра/видимость (R28/R29).
 * Сначала сбрасываем отложенную геометрию, иначе дебаунсированный PATCH
 * позиции может перезаписать свежие данные.
 */
async function refreshGraphSilently(notifyOnError = true): Promise<boolean> {
  const cid = canvasId.value
  if (!cid) return false
  await positionSync.flushAll()
  try {
    const graph = await canvasApi.graph(cid)
    store.edges = graph.edges
    store.tags = graph.tags
    notesStore.setNodes(graph.nodes)
    positionSync.syncFromNodes()
    return true
  } catch {
    if (notifyOnError) {
      notify({
        title: 'Не удалось обновить граф',
        description: 'Перезагрузите страницу, чтобы увидеть актуальное состояние',
        status: 'error',
      })
    }
    return false
  }
}

/**
 * После сохранения редактора (R28): тело/теги/актуальность влияют на рёбра,
 * поэтому для них — рефреш графа; остальные поля — локальный upsert ноды.
 */
async function onNoteSaved(detail: NoteDetail, plan: SavePlan): Promise<void> {
  const needsGraphRefresh =
    plan.bodyMd !== undefined ||
    plan.manifestPatch?.tagIds !== undefined ||
    plan.manifestPatch?.isRelevant !== undefined

  if (needsGraphRefresh) {
    // Тихий рефреш не должен ломать уже сохранённую заметку — падаем ниже
    // к локальному обновлению ноды.
    if (await refreshGraphSilently(false)) return
  } else {
    await positionSync.flushAll()
  }

  notesStore.upsertNode(
    noteDetailToGraphNode(detail, notesStore.getNodeById(detail.id), store.tags)
  )
}

/** Результат действия в архиве (R29) синхронизируем с графом. */
async function onArchiveChange(change: ArchiveChange): Promise<void> {
  switch (change.kind) {
    case 'make-relevant':
      notesStore.patchNode(change.noteId, { isRelevant: true })
      ensureNodeVisible(change.noteId)
      break

    case 'soft-delete':
      // Сервер оставляет рёбра state=removed с to=id — нужен авторитетный рефреш.
      await refreshGraphSilently()
      break

    case 'restore':
      // Нода возвращается в прежнюю позицию, рёбра removed → ok.
      if (!(await refreshGraphSilently())) {
        notesStore.upsertNode(noteToGraphNode(change.note, store.tags, userId.value ?? ''))
      }
      break

    case 'purge':
      // Рёбра получают to=null + label «ссылка удалена», меняется brokenEdgesCount.
      await refreshGraphSilently()
      break
  }
}

function isAuthorOrCanvasOwner(node: GraphNode): boolean {
  const uid = userId.value
  if (uid && node.authorId === uid) return true
  return store.canvas?.access.myRole === 'owner' || store.canvas?.ownerId === uid
}

function graphNodeFromNote(note: Note): GraphNode {
  return noteToGraphNode(note, store.tags, userId.value ?? '')
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

function cancelLinking() {
  linkingSourceId.value = null
  linkingPending.value = false
}

function startLinking(nodeId: string) {
  if (!canEditCanvas.value) return
  selectedEdgeId.value = null
  linkingSourceId.value = nodeId
  linkingPending.value = false
}

const linkingSourceTitle = computed(() => {
  const id = linkingSourceId.value
  return id ? (notesStore.getNodeById(id)?.title ?? null) : null
})

async function completeLinking(targetNodeId: string) {
  const sourceId = linkingSourceId.value
  if (!sourceId || linkingPending.value) return
  if (targetNodeId.startsWith('ghost:') || targetNodeId.startsWith('dangling:')) return
  if (!notesStore.getNodeById(targetNodeId)) return
  if (sourceId === targetNodeId) {
    notify({ title: 'Нельзя связать заметку саму с собой', status: 'error' })
    return
  }

  const exists = store.edges.some(
    (e) => e.type === 'direct' && e.state === 'ok' && e.from === sourceId && e.to === targetNodeId
  )
  if (exists) {
    notify({ title: 'Такая ссылка уже существует', status: 'error' })
    cancelLinking()
    return
  }

  linkingPending.value = true
  try {
    const link = await linksApi.createDirect({ fromNoteId: sourceId, toNoteId: targetNodeId })
    store.edges = [
      ...store.edges,
      {
        id: link.id,
        type: 'direct',
        from: link.fromNoteId,
        to: link.toNoteId,
        state: 'ok',
        label: null,
      },
    ]
    notify({ title: 'Ссылка создана', status: 'success' })
    cancelLinking()
  } catch (e) {
    notify({
      title: 'Не удалось создать ссылку',
      description: e instanceof ApiError ? e.message : undefined,
      status: 'error',
    })
  } finally {
    linkingPending.value = false
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

const selectedEdge = computed<GraphEdge | null>(
  () => store.edges.find((edge) => edge.id === selectedEdgeId.value) ?? null
)

function isTypingTarget(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false
  if (target.isContentEditable) return true
  const tag = target.tagName.toLowerCase()
  return tag === 'input' || tag === 'textarea' || tag === 'select'
}

async function reverseSelectedEdge(edgeId: string) {
  if (!canEditCanvas.value) return
  try {
    const link = await linksApi.reverseDirect(edgeId)
    store.edges = store.edges.map((edge) =>
      edge.id === edgeId ? { ...edge, from: link.fromNoteId, to: link.toNoteId } : edge
    )
    selectedEdgeId.value = edgeId
  } catch (e) {
    notify({
      title: 'Не удалось развернуть связь',
      description: e instanceof ApiError ? e.message : undefined,
      status: 'error',
    })
  }
}

async function deleteSelectedEdge(edgeId: string) {
  if (!canEditCanvas.value) return
  try {
    await linksApi.removeDirect(edgeId)
    store.edges = store.edges.filter((edge) => edge.id !== edgeId)
    if (selectedEdgeId.value === edgeId) selectedEdgeId.value = null
  } catch (e) {
    notify({
      title: 'Не удалось удалить связь',
      description: e instanceof ApiError ? e.message : undefined,
      status: 'error',
    })
  }
}

async function restoreGhostNote(noteId: string) {
  if (!canEditCanvas.value) return
  try {
    await notesApi.restore(noteId)
    await refreshGraphSilently()
    notify({ title: 'Заметка восстановлена', status: 'success' })
  } catch (e) {
    if (e instanceof ApiError && e.status === 410) {
      notify({
        title: 'Заметка удалена навсегда',
        description: 'Восстановить её нельзя',
        status: 'error',
      })
      await refreshGraphSilently()
      return
    }
    notify({
      title: 'Не удалось восстановить заметку',
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
    // Сервер оставляет removed-рёбра с to=id → только авторитетный рефреш.
    await refreshGraphSilently()
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

async function importTextFileAt(file: File, pos: XYPosition | null) {
  const cid = store.canvas?.id
  if (!cid) return
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

async function onImportFileChange(event: Event) {
  const input = event.target as HTMLInputElement | null
  const file = input?.files?.[0]
  const pos = importAt.value
  if (input) input.value = ''
  importAt.value = null
  if (!file) return
  await importTextFileAt(file, pos)
}

function canvasDropPosition(event: DragEvent): XYPosition | null {
  const board = boardRef.value as unknown as {
    screenToFlowCoordinate?: (p: { x: number; y: number }) => XYPosition
  } | null
  if (board?.screenToFlowCoordinate) {
    return board.screenToFlowCoordinate({ x: event.clientX, y: event.clientY })
  }
  return null
}

function hasFileDrag(event: DragEvent): boolean {
  return Array.from(event.dataTransfer?.types ?? []).includes('Files')
}

function onCanvasDragEnter(event: DragEvent) {
  if (!hasFileDrag(event)) return
  event.preventDefault()
  canvasFileDragDepth.value += 1
}

function onCanvasDragOver(event: DragEvent) {
  if (!hasFileDrag(event)) return
  event.preventDefault()
  if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy'
}

function onCanvasDragLeave(event: DragEvent) {
  if (!hasFileDrag(event)) return
  event.preventDefault()
  canvasFileDragDepth.value = Math.max(0, canvasFileDragDepth.value - 1)
}

async function onCanvasDrop(event: DragEvent) {
  if (!hasFileDrag(event)) return
  event.preventDefault()
  canvasFileDragDepth.value = 0

  const files = Array.from(event.dataTransfer?.files ?? [])
  if (files.length === 0) return

  const pos = canvasDropPosition(event)

  for (const file of files) {
    const name = file.name.toLowerCase()
    const type = file.type.toLowerCase()
    const isText = name.endsWith('.md') || name.endsWith('.txt')
    const isImage = type.startsWith('image/') || /\.(png|jpe?g|gif|webp)$/i.test(name)
    const isAudio = type.startsWith('audio/') || /\.(mp3|ogg|wav)$/i.test(name)

    if (isText) {
      if (!canEditCanvas.value) {
        notify({
          title: 'Недостаточно прав',
          description: 'Импортировать файл может только редактор канваса',
          status: 'error',
        })
        continue
      }
      await importTextFileAt(file, pos)
      continue
    }

    if (isImage || isAudio) {
      notify({ title: 'Файлы можно добавить внутри заметки', status: 'default' })
      continue
    }

    notify({
      title: 'Неподдерживаемый файл',
      description: 'На канвас можно перетащить только .md или .txt',
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
      onSelect: () => startLinking(node.id),
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

function buildEdgeMenu(edge: GraphEdge): ContextMenuItem[] {
  if (!canEditCanvas.value || edge.type !== 'direct' || edge.state !== 'ok') return []
  return [
    {
      id: 'reverse',
      label: 'Развернуть направление',
      onSelect: () => reverseSelectedEdge(edge.id),
    },
    {
      id: 'delete-link',
      label: 'Удалить связь',
      danger: true,
      onSelect: () => deleteSelectedEdge(edge.id),
    },
  ]
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
  if (ctx.kind === 'edge') {
    const edge = store.edges.find((e) => e.id === ctx.edgeId)
    return edge ? buildEdgeMenu(edge) : []
  }
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
  cancelLinking()
  selectedEdgeId.value = null
  const items = buildPaneMenu(payload.flowPosition)
  if (items.length === 0) return
  contextMenu.open(payload.event, { kind: 'pane', flowPosition: payload.flowPosition })
}

function onPaneClick() {
  selectedEdgeId.value = null
  if (linkingSourceId.value) cancelLinking()
}

function onNodeContextMenu(payload: { event: MouseEvent; nodeId: string }) {
  selectedEdgeId.value = null
  contextMenu.open(payload.event, { kind: 'node', nodeId: payload.nodeId })
}

function onNodeClick(payload: { event: MouseEvent; nodeId: string }) {
  selectedEdgeId.value = null
  if (linkingSourceId.value) {
    void completeLinking(payload.nodeId)
  }
}

function onEdgeClick(payload: { event: MouseEvent; edgeId: string }) {
  if (linkingSourceId.value) return
  const edge = store.edges.find((e) => e.id === payload.edgeId)
  if (!edge || edge.type !== 'direct' || edge.state !== 'ok') {
    selectedEdgeId.value = null
    return
  }
  selectedEdgeId.value = edge.id
}

function onEdgeContextMenu(payload: { event: MouseEvent; edgeId: string }) {
  if (linkingSourceId.value) return
  const edge = store.edges.find((e) => e.id === payload.edgeId)
  if (!edge) return
  selectedEdgeId.value = edge.type === 'direct' && edge.state === 'ok' ? edge.id : null
  if (edge.type !== 'direct' || edge.state !== 'ok' || !canEditCanvas.value) return
  contextMenu.open(payload.event, { kind: 'edge', edgeId: payload.edgeId })
}

function onGhostRestore(noteId: string) {
  void restoreGhostNote(noteId)
}

function onGlobalKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    if (contextMenu.state.open) return
    if (linkingSourceId.value) {
      event.preventDefault()
      cancelLinking()
    }
    return
  }

  if (event.key !== 'Delete') return
  if (!canEditCanvas.value) return
  if (contextMenu.state.open) return
  if (isTypingTarget(event.target) || isTypingTarget(document.activeElement)) return

  const edge = selectedEdge.value
  if (!edge || edge.type !== 'direct' || edge.state !== 'ok') return
  event.preventDefault()
  void deleteSelectedEdge(edge.id)
}

onMounted(() => {
  void loadGraph()
  window.addEventListener('keydown', onGlobalKeydown)
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
  window.removeEventListener('keydown', onGlobalKeydown)
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
        <UiButton
          v-if="canEditCanvas"
          variant="ghost"
          size="sm"
          :disabled="loading"
          @click="openArchive"
        >
          Архив
        </UiButton>
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
    <div
      v-else
      class="canvas-view__board"
      @dragenter="onCanvasDragEnter"
      @dragover="onCanvasDragOver"
      @dragleave="onCanvasDragLeave"
      @drop="onCanvasDrop"
    >
      <div v-if="isCanvasFileDragging" class="canvas-view__drop-overlay" aria-hidden="true">
        <div class="canvas-view__drop-overlay-card">
          Перетащите .md или .txt, чтобы создать заметку
        </div>
      </div>
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
        :edges-selectable="canEditCanvas"
        :linking="Boolean(linkingSourceId)"
        @nodes-change="onNodesChange"
        @node-drag-start="onNodeDragStart"
        @node-drag="onNodeDrag"
        @node-drag-stop="onNodeDragStop"
        @node-resize-end="onNodeResizeEnd"
        @pane-click="onPaneClick"
        @pane-context-menu="onPaneContextMenu"
        @node-click="onNodeClick"
        @node-context-menu="onNodeContextMenu"
        @node-open="openEditor"
        @edge-click="onEdgeClick"
        @edge-context-menu="onEdgeContextMenu"
        @ghost-restore="onGhostRestore"
      >
        <LinkLayer
          :linking="Boolean(linkingSourceId)"
          :source-title="linkingSourceTitle"
          @cancel="cancelLinking"
        />

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

      <!-- R29: архив неактуальных/удалённых заметок (≥editor) -->
      <ArchivePanel
        v-if="canEditCanvas && archiveOpen"
        v-model:open="archiveOpen"
        :canvas-id="canvasId"
        @changed="onArchiveChange"
      />
    </div>
  </div>
</template>
