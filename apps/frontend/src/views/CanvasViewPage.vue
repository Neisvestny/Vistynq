<script setup lang="ts">
/**
 * CanvasView (docs/ui-ux.md §2) - canvas page: loading/errors/header,
 * interactive graph render (R25), drag/resize + persist (R26),
 * menus/filters/search/tags (R27).
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
import CanvasAccessDialog from '@/components/access/CanvasAccessDialog.vue'
import NoteAccessDialog from '@/components/access/NoteAccessDialog.vue'
import CanvasBoard from '@/components/canvas/CanvasBoard.vue'
import CanvasSearch from '@/components/canvas/CanvasSearch.vue'
import ContextMenu, { type ContextMenuItem } from '@/components/canvas/ContextMenu.vue'
import ImportanceFilter from '@/components/canvas/ImportanceFilter.vue'
import LinkLayer from '@/components/canvas/LinkLayer.vue'
import TagMenu from '@/components/canvas/TagMenu.vue'
import UiBadge from '@/components/ui/UiBadge.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiInput from '@/components/ui/UiInput.vue'
import { useAccessGuard } from '@/composables/useAccessGuard'
import { useContextMenu } from '@/composables/useContextMenu'
import { useGraph } from '@/composables/useGraph'
import { useNodePositionSync } from '@/composables/useNodePositionSync'
import type { SavePlan } from '@/composables/useNoteDraft'
import type { ArchiveChange } from '@/lib/archive'
import { noteDetailToGraphNode, noteToGraphNode } from '@/lib/noteMapping'
import { useCanvasStore } from '@/stores/canvas'
import { useNotesStore } from '@/stores/notes'
import { useI18n } from 'vue-i18n'

/** Tiptap is heavy - load editor panel only on first open (R28). */
const NoteEditorModal = defineAsyncComponent(() => import('@/components/note/NoteEditorModal.vue'))

type PaneMenuContext = { kind: 'pane'; flowPosition: XYPosition }
type NodeMenuContext = { kind: 'node'; nodeId: string }
type EdgeMenuContext = { kind: 'edge'; edgeId: string }
type MenuContext = PaneMenuContext | NodeMenuContext | EdgeMenuContext

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const store = useCanvasStore()
const notesStore = useNotesStore()
const { canEditCanvas, canManageCanvasAccess, userId } = useAccessGuard()

const highlightedTag = ref<string | null>(null)
const tagMenuOpen = ref(false)
const importanceMenuOpen = ref(false)

/** Note editor panel (R28). */
const editorOpen = ref(false)
const editorRef = ref<{ requestClose: () => boolean } | null>(null)
const editorNoteId = ref<string | null>(null)

/** Archive panel (R29). */
const archiveOpen = ref(false)
const canvasAccessOpen = ref(false)
const noteAccessOpen = ref(false)
const noteAccessId = ref<string | null>(null)
const noteAccessVisibility = ref<'public' | 'private'>('public')

/** Linking mode (R30). */
const linkingSourceId = ref<string | null>(null)
const linkingPending = ref(false)

/** Selected edge (R30). */
const selectedEdgeId = ref<string | null>(null)
/** Selected node (R34 hotkeys). */
const selectedNodeId = ref<string | null>(null)
const hoveredNodeId = ref<string | null>(null)

/** Owner-only inline canvas rename. */
const renamingTitle = ref(false)
const titleDraft = ref('')
const titleSaving = ref(false)
const titleError = ref('')

const { flowNodes, flowEdges, visibleNodes, brokenEdgesCount } = useGraph({
  editableLayout: true,
  selectable: true,
  highlightedTag,
  selectedEdgeId,
  selectedNodeId,
  hoveredNodeId,
  linkingSourceId,
})

const boardRef = ref<InstanceType<typeof CanvasBoard> | null>(null)
const canvasSearchRef = ref<InstanceType<typeof CanvasSearch> | null>(null)
const contextMenu = useContextMenu<MenuContext>()
const contextMenuRef = ref<InstanceType<typeof ContextMenu> | null>(null)

const fileInputRef = ref<HTMLInputElement | null>(null)
const importAt = ref<XYPosition | null>(null)
const canvasFileDragDepth = ref(0)
const isCanvasFileDragging = computed(() => canvasFileDragDepth.value > 0)

const canvasId = computed(() => store.canvas?.id ?? null)
const positionSync = useNodePositionSync(canvasId)

/** Store aliases for template (v-if narrows type like local refs). */
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
      return t('common.owner')
    case 'editor':
      return t('common.editor')
    case 'viewer':
      return t('common.viewer')
    default:
      return ''
  }
})

const canRenameCanvas = computed(() => {
  const canvas = store.canvas
  return Boolean(canvas && canvas.ownerId === userId.value)
})

function startRename() {
  if (!canRenameCanvas.value) return
  titleDraft.value = graph.value?.title ?? ''
  titleError.value = ''
  renamingTitle.value = true
}

async function commitRename() {
  if (titleSaving.value) return
  const canvas = store.canvas
  const title = titleDraft.value.trim()
  if (!canvas || !title) {
    renamingTitle.value = false
    return
  }
  if (title === canvas.title) {
    renamingTitle.value = false
    return
  }

  titleSaving.value = true
  try {
    await canvasApi.update(canvas.id, { title })
    await refreshGraphSilently(false)
    renamingTitle.value = false
  } catch (apiError) {
    titleError.value = apiError instanceof ApiError ? apiError.message : t('canvas.renameFailed')
    notify({ title: t('canvas.renameFailed'), description: titleError.value, status: 'error' })
  } finally {
    titleSaving.value = false
  }
}

function cancelRename() {
  renamingTitle.value = false
  titleError.value = ''
}

function clampImportance(v: number): number {
  return Math.min(5, Math.max(1, Math.round(v || 3)))
}

let graphLoadSeq = 0

/** Reject commits from requests started before a canvas switch/reload. */
function isCurrentGraph(canvasIdAtStart: string | null | undefined, seq: number): boolean {
  return seq === graphLoadSeq && Boolean(canvasIdAtStart) && canvasIdAtStart === canvasId.value
}

async function loadGraph() {
  const id = route.params.id
  const sequence = ++graphLoadSeq
  selectedEdgeId.value = null
  selectedNodeId.value = null
  hoveredNodeId.value = null
  cancelLinking()
  await store.load(typeof id === 'string' ? id : '')
  if (sequence !== graphLoadSeq || (typeof id === 'string' && canvasId.value !== id)) return
  positionSync.syncFromNodes()
}

function applyPositionChange(id: string, position?: XYPosition) {
  if (!position) return
  positionSync.applyAndSchedule(id, { x: position.x, y: position.y })
}

function onNodesChange(changes: NodeChange[]) {
  for (const change of changes) {
    if (change.type === 'position' && change.position) {
      // During drag, update locally and debounce persistence.
      if (change.dragging) {
        positionSync.beginGesture(change.id)
        notesStore.updateNodeGeometry(change.id, { x: change.position.x, y: change.position.y })
        positionSync.scheduleSave(change.id)
      } else {
        applyPositionChange(change.id, change.position)
      }
    }
    if (change.type === 'dimensions' && change.dimensions && change.resizing) {
      // Intermediate resize: update width locally, persist on resize-end.
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
  // Let Vue render the node if it was hidden by filters.
  requestAnimationFrame(() => {
    boardRef.value?.focusNode(nodeId)
  })
}

function openEditor(nodeId: string) {
  if (editorOpen.value && editorRef.value && !editorRef.value.requestClose()) return
  if (editorOpen.value && !editorRef.value) return
  archiveOpen.value = false
  notesStore.select(nodeId)
  editorNoteId.value = nodeId
  editorOpen.value = true
}

function openArchive() {
  if (editorOpen.value && editorRef.value && !editorRef.value.requestClose()) return
  if (editorOpen.value && !editorRef.value) return
  editorOpen.value = false
  archiveOpen.value = true
}

function openNoteAccess(noteId: string): void {
  const node = notesStore.getNodeById(noteId)
  noteAccessId.value = noteId
  noteAccessVisibility.value = node?.visibility ?? 'public'
  noteAccessOpen.value = true
}

function onCanvasAccessLevelChanged(payload: {
  level: 'private' | 'public'
  publicSlug: string | null
}): void {
  if (store.canvas) {
    store.canvas = {
      ...store.canvas,
      access: { ...store.canvas.access, level: payload.level, publicSlug: payload.publicSlug },
    }
  }
}

function onNoteVisibilityChanged(visibility: 'public' | 'private'): void {
  noteAccessVisibility.value = visibility
  const noteId = noteAccessId.value
  if (noteId) {
    const node = notesStore.getNodeById(noteId)
    if (node) notesStore.upsertNode({ ...node, visibility })
  }
  void refreshGraphSilently(false)
}

function onEditorVisibilityChanged(noteId: string, visibility: 'public' | 'private'): void {
  const node = notesStore.getNodeById(noteId)
  if (node) notesStore.upsertNode({ ...node, visibility })
  if (noteAccessId.value === noteId) noteAccessVisibility.value = visibility
  void refreshGraphSilently(false)
}

/**
 * Silent graph refresh after changes affecting edges/visibility (R28/R29).
 * Flush pending geometry first, otherwise a debounced position PATCH
 * may overwrite fresh data.
 */
async function refreshGraphSilently(notifyOnError = true): Promise<boolean> {
  const cid = canvasId.value
  if (!cid) return false
  await positionSync.flushAll()
  try {
    const graph = await canvasApi.graph(cid)
    if (canvasId.value !== cid) return false
    store.edges = graph.edges
    store.tags = graph.tags
    notesStore.setNodes(graph.nodes)
    positionSync.syncFromNodes()
    return true
  } catch {
    if (notifyOnError) {
      notify({
        title: t('canvas.refreshError'),
        description: t('canvas.refreshErrorDescription'),
        status: 'error',
      })
    }
    return false
  }
}

/**
 * After editor save (R28): body/tags/relevance affect edges,
 * so refresh graph for them; other fields use a local node upsert.
 */
async function onNoteSaved(detail: NoteDetail, plan: SavePlan): Promise<void> {
  const needsGraphRefresh =
    plan.bodyMd !== undefined ||
    plan.manifestPatch?.tagIds !== undefined ||
    plan.manifestPatch?.isRelevant !== undefined

  if (needsGraphRefresh) {
    // Silent refresh must not break an already saved note - fall back
    // to local node update.
    if (await refreshGraphSilently(false)) return
  } else {
    await positionSync.flushAll()
  }

  notesStore.upsertNode(
    noteDetailToGraphNode(detail, notesStore.getNodeById(detail.id), store.tags)
  )
}

/** Tag list changed on the server: flush pending geometry, then refresh once. */
async function onTagsChanged(): Promise<void> {
  await positionSync.flushAll()
  await refreshGraphSilently(false)
}

/** Sync archive action result with the graph (R29). */
async function onArchiveChange(change: ArchiveChange): Promise<void> {
  switch (change.kind) {
    case 'make-relevant':
      notesStore.patchNode(change.noteId, { isRelevant: true })
      ensureNodeVisible(change.noteId)
      await refreshGraphSilently(false)
      break

    case 'soft-delete':
      // Server keeps edges state=removed with to=id - authoritative refresh needed.
      await refreshGraphSilently()
      break

    case 'restore':
      // Node returns to previous position, removed edges become ok.
      if (!(await refreshGraphSilently())) {
        notesStore.upsertNode(noteToGraphNode(change.note, store.tags, userId.value ?? ''))
      }
      break

    case 'purge':
      // Edges get to=null + deleted-link label, brokenEdgesCount changes.
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
  const seq = graphLoadSeq
  if (!cid) return
  try {
    const note = await notesApi.create(cid, {
      x: Math.round(position.x),
      y: Math.round(position.y),
    })
    if (!isCurrentGraph(cid, seq)) return
    notesStore.upsertNode(graphNodeFromNote(note))
    notesStore.select(note.id)
    notify({ title: t('canvas.noteCreatedSimple'), status: 'success' })
  } catch (e) {
    notify({
      title: t('canvas.noteCreateFailed'),
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
  if (targetNodeId.startsWith('ghost:') || targetNodeId.startsWith('dangling:')) {
    notify({ title: t('canvas.cannotLinkTarget'), status: 'error' })
    cancelLinking()
    return
  }
  if (!notesStore.getNodeById(targetNodeId)) return
  if (sourceId === targetNodeId) {
    notify({ title: t('canvas.cannotLinkSelf'), status: 'error' })
    return
  }

  const exists = store.edges.some(
    (e) => e.type === 'direct' && e.state === 'ok' && e.from === sourceId && e.to === targetNodeId
  )
  if (exists) {
    notify({ title: t('canvas.linkExists'), status: 'error' })
    cancelLinking()
    return
  }

  const cid = store.canvas?.id
  const seq = graphLoadSeq
  linkingPending.value = true
  try {
    const link = await linksApi.createDirect({ fromNoteId: sourceId, toNoteId: targetNodeId })
    if (!isCurrentGraph(cid, seq)) return
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
    notify({ title: t('canvas.linkCreated'), status: 'success' })
    cancelLinking()
  } catch (e) {
    notify({
      title: t('canvas.linkCreateFailed'),
      description: e instanceof ApiError ? e.message : undefined,
      status: 'error',
    })
  } finally {
    linkingPending.value = false
  }
}

async function deriveFromNode(nodeId: string) {
  const cid = store.canvas?.id
  const seq = graphLoadSeq
  const parent = notesStore.getNodeById(nodeId)
  if (!cid || !parent) return
  try {
    const res = await notesApi.derive(cid, {
      parentNoteId: nodeId,
      x: Math.round(parent.x + 40),
      y: Math.round(parent.y + 40),
    })
    if (!isCurrentGraph(cid, seq)) return
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
    notify({ title: t('canvas.noteCreatedFrom'), status: 'success' })
  } catch (e) {
    notify({
      title: t('canvas.noteCreateFailed'),
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
  const cid = store.canvas?.id
  const seq = graphLoadSeq
  try {
    const link = await linksApi.reverseDirect(edgeId)
    if (!isCurrentGraph(cid, seq)) return
    store.edges = store.edges.map((edge) =>
      edge.id === edgeId ? { ...edge, from: link.fromNoteId, to: link.toNoteId } : edge
    )
    selectedEdgeId.value = edgeId
  } catch (e) {
    notify({
      title: t('canvas.linkReverseFailed'),
      description: e instanceof ApiError ? e.message : undefined,
      status: 'error',
    })
  }
}

async function deleteSelectedEdge(edgeId: string) {
  if (!canEditCanvas.value) return
  const cid = store.canvas?.id
  const seq = graphLoadSeq
  try {
    await linksApi.removeDirect(edgeId)
    if (!isCurrentGraph(cid, seq)) return
    store.edges = store.edges.filter((edge) => edge.id !== edgeId)
    if (selectedEdgeId.value === edgeId) selectedEdgeId.value = null
  } catch (e) {
    notify({
      title: t('canvas.linkDeleteFailed'),
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
    notify({ title: t('canvas.noteRestored'), status: 'success' })
  } catch (e) {
    if (e instanceof ApiError && e.status === 410) {
      notify({
        title: t('canvas.notePurged'),
        description: t('canvas.notePurgedDescription'),
        status: 'error',
      })
      await refreshGraphSilently()
      return
    }
    notify({
      title: t('canvas.noteRestoreFailed'),
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
      title: t('canvas.importanceFailed'),
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
      title: t('canvas.noteStatusFailed'),
      description: e instanceof ApiError ? e.message : undefined,
      status: 'error',
    })
  }
}

async function deleteNodeSoft(nodeId: string) {
  if (!window.confirm(t('canvas.noteDeleteConfirm'))) return
  try {
    await notesApi.remove(nodeId)
    // Server keeps removed edges with to=id - authoritative refresh only.
    await refreshGraphSilently()
    notify({ title: t('canvas.noteDeleted'), status: 'success' })
  } catch (e) {
    notify({
      title: t('canvas.noteDeleteFailed'),
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
      title: next === 'private' ? t('canvas.visibilityPrivate') : t('canvas.visibilityPublic'),
      status: 'success',
    })
  } catch (e) {
    notify({
      title: t('canvas.visibilityFailed'),
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
      title: t('canvas.tagsUpdateFailed'),
      description: e instanceof ApiError ? e.message : undefined,
      status: 'error',
    })
  }
}

async function createAndAssignTag(node: GraphNode) {
  const cid = store.canvas?.id
  if (!cid) return
  const name = window.prompt(t('canvas.newTagPrompt'))
  if (!name || !name.trim()) return
  try {
    const tag = await tagsApi.create(cid, { name: name.trim() })
    store.tags = [...store.tags, tag].sort((a, b) => a.name.localeCompare(b.name, 'ru'))
    const ids = Array.from(new Set([...getTagIdsForNode(node), tag.id]))
    await notesApi.updateManifest(node.id, { tagIds: ids })
    const names = store.tags.filter((t) => ids.includes(t.id)).map((t) => t.name)
    notesStore.patchNode(node.id, { tags: names })
    notify({ title: t('canvas.tagCreatedAssigned'), status: 'success' })
  } catch (e) {
    notify({
      title: t('canvas.tagCreateFailed'),
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
    notify({ title: t('canvas.fileImported'), status: 'success' })
  } catch (e) {
    notify({
      title: t('canvas.fileImportFailed'),
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
          title: t('canvas.insufficientRights'),
          description: t('canvas.importEditorOnly'),
          status: 'error',
        })
        continue
      }
      await importTextFileAt(file, pos)
      continue
    }

    if (isImage || isAudio) {
      notify({ title: t('canvas.filesInNote'), status: 'default' })
      continue
    }

    notify({
      title: t('canvas.unsupportedFile'),
      description: t('canvas.unsupportedFileDescription'),
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
        label: t('common.open'),
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
    { id: 'tag-new', label: t('canvas.newTagMenu'), onSelect: () => createAndAssignTag(node) },
  ]

  const importanceChildren: ContextMenuItem[] = [1, 2, 3, 4, 5].map((level) => ({
    id: `imp-${level}`,
    label: t('canvas.importanceLevel', { level }),
    checked: clampImportance(node.importance) === level,
    onSelect: () => setImportance(node.id, level),
  }))

  const items: ContextMenuItem[] = [
    {
      id: 'open',
      label: t('common.open'),
      onSelect: () => openEditor(node.id),
    },
    { id: 'derive', label: t('canvas.deriveFromNote'), onSelect: () => deriveFromNode(node.id) },
    {
      id: 'link',
      label: t('canvas.linkToAnother'),
      onSelect: () => startLinking(node.id),
    },
    { id: 'tags', label: t('tags.title'), children: tagChildren },
    { id: 'importance', label: t('importance.title'), children: importanceChildren },
  ]

  if (canManageAccess) {
    const visLabel =
      (node.visibility ?? 'public') === 'public' ? t('canvas.makePrivate') : t('canvas.makePublic')
    items.push(
      { id: 'visibility', label: visLabel, onSelect: () => toggleVisibility(node) },
      {
        id: 'access',
        label: t('canvas.accessEllipsis'),
        onSelect: () => openNoteAccess(node.id),
      }
    )
  }

  items.push(
    {
      id: 'irrelevant',
      label: t('canvas.markIrrelevant'),
      onSelect: () => setNodeRelevant(node.id, false),
    },
    {
      id: 'delete',
      label: t('common.delete'),
      danger: true,
      onSelect: () => deleteNodeSoft(node.id),
    }
  )

  return items
}

function buildEdgeMenu(edge: GraphEdge): ContextMenuItem[] {
  if (!canEditCanvas.value || edge.type !== 'direct' || edge.state !== 'ok') return []
  return [
    {
      id: 'reverse',
      label: t('canvas.reverseDirection'),
      onSelect: () => reverseSelectedEdge(edge.id),
    },
    {
      id: 'delete-link',
      label: t('canvas.deleteLink'),
      danger: true,
      onSelect: () => deleteSelectedEdge(edge.id),
    },
  ]
}

function buildPaneMenu(flowPosition: XYPosition): ContextMenuItem[] {
  const items: ContextMenuItem[] = []
  if (canEditCanvas.value) {
    items.push(
      { id: 'create', label: t('canvas.createNote'), onSelect: () => createNoteAt(flowPosition) },
      { id: 'import', label: t('canvas.importFile'), onSelect: () => triggerImportAt(flowPosition) }
    )
  }
  items.push({
    id: 'zoom-center',
    label: t('canvas.zoomCenter'),
    onSelect: () => boardRef.value?.fitAll(),
  })
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
  selectedNodeId.value = null
  const items = buildPaneMenu(payload.flowPosition)
  if (items.length === 0) return
  contextMenu.open(payload.event, { kind: 'pane', flowPosition: payload.flowPosition })
}

function onPaneClick() {
  selectedEdgeId.value = null
  selectedNodeId.value = null
  if (linkingSourceId.value) cancelLinking()
}

function onNodeContextMenu(payload: { event: MouseEvent; nodeId: string }) {
  selectedEdgeId.value = null
  selectedNodeId.value = payload.nodeId
  contextMenu.open(payload.event, { kind: 'node', nodeId: payload.nodeId })
}

function onNodeClick(payload: { event: MouseEvent; nodeId: string }) {
  selectedEdgeId.value = null
  selectedNodeId.value = payload.nodeId
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

function onNodeFocus(nodeId: string) {
  selectedEdgeId.value = null
  selectedNodeId.value = nodeId || null
}

function onNodeHover(nodeId: string | null) {
  hoveredNodeId.value = nodeId
}

function onNodeDelete(nodeId: string) {
  if (!canEditCanvas.value) return
  void deleteNodeSoft(nodeId)
}

function onEdgeSelected(edgeId: string) {
  selectedEdgeId.value = edgeId
  selectedNodeId.value = null
}

function onEdgeDeleted(edgeId: string) {
  if (!canEditCanvas.value) return
  void deleteSelectedEdge(edgeId)
}

function onGhostRestore(noteId: string) {
  void restoreGhostNote(noteId)
}

function onGlobalKeydown(event: KeyboardEvent) {
  const typing = isTypingTarget(event.target) || isTypingTarget(document.activeElement)
  const inCanvasSearch = Boolean(canvasSearchRef.value?.rootEl?.contains(event.target as Node))
  const overlayOpen =
    editorOpen.value || archiveOpen.value || canvasAccessOpen.value || noteAccessOpen.value

  if (event.key === 'Escape') {
    if (contextMenu.state.open) contextMenu.close()
    if (linkingSourceId.value) {
      event.preventDefault()
      cancelLinking()
    }
    return
  }

  if (overlayOpen) return

  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'f') {
    if (typing && !inCanvasSearch) return
    if (error.value || loading.value || !store.canvas) return
    event.preventDefault()
    canvasSearchRef.value?.focus()
    return
  }

  if (event.key !== 'Delete') return
  if (!canEditCanvas.value) return
  if (contextMenu.state.open) return
  if (typing) return

  const edge = selectedEdge.value
  if (edge && edge.type === 'direct' && edge.state === 'ok') {
    event.preventDefault()
    void deleteSelectedEdge(edge.id)
    return
  }

  if (selectedNodeId.value) {
    event.preventDefault()
    void deleteNodeSoft(selectedNodeId.value)
  }
}

onMounted(() => {
  void loadGraph()
  window.addEventListener('keydown', onGlobalKeydown)
})

// Navigating between canvases on the same route reloads the graph
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
    <!-- Canvas header -->
    <header v-if="graph && !error" class="canvas-view__header">
      <div class="canvas-view__heading">
        <UiInput
          v-if="renamingTitle"
          id="canvas-title-rename"
          v-model="titleDraft"
          class="canvas-view__rename"
          :disabled="titleSaving"
          :aria-label="t('canvas.renameTitle')"
          @keydown.enter.prevent="commitRename"
          @keydown.esc.prevent="cancelRename"
          @focusout="commitRename"
        />
        <h1 v-else class="canvas-view__title">
          {{ graph.title }}
          <button
            v-if="canRenameCanvas"
            type="button"
            class="canvas-view__rename-trigger"
            :aria-label="t('canvas.renameTitle')"
            @click="startRename"
          >
            ✎
          </button>
        </h1>
        <UiBadge v-if="roleLabel">{{ roleLabel }}</UiBadge>
        <UiBadge v-if="graph.access.publicSlug" tone="accent">{{ t('common.public') }}</UiBadge>
      </div>

      <div class="canvas-view__toolbar">
        <CanvasSearch
          v-if="store.canvas"
          ref="canvasSearchRef"
          :canvas-id="store.canvas.id"
          @select="onSearchSelect"
        />
        <TagMenu
          v-model:open="tagMenuOpen"
          v-model:highlighted-tag="highlightedTag"
          @changed="onTagsChanged"
        />
        <ImportanceFilter v-model:open="importanceMenuOpen" />
        <UiButton
          v-if="canEditCanvas"
          variant="ghost"
          size="sm"
          :disabled="loading"
          @click="openArchive"
        >
          {{ t('canvas.archive') }}
        </UiButton>
        <UiButton
          v-if="canManageCanvasAccess"
          variant="ghost"
          size="sm"
          :disabled="loading"
          @click="canvasAccessOpen = true"
        >
          {{ t('canvas.access') }}
        </UiButton>
      </div>

      <div class="canvas-view__meta">
        <span v-if="brokenEdgesCount > 0" class="canvas-view__broken">
          {{ t('canvas.brokenEdges', { count: brokenEdgesCount }) }}
        </span>
        <span class="canvas-view__count">{{
          t('canvas.notesCount', { count: visibleNodes.length })
        }}</span>
      </div>
    </header>

    <!-- Loading -->
    <div v-if="loading" class="canvas-view__board canvas-view__board--loading" aria-busy="true">
      <div class="canvas-view__skeleton" />
    </div>

    <!-- Error -->
    <div v-else-if="error" class="canvas-view__error">
      <p class="canvas-view__error-title">
        {{
          error.status === 404 || error.status === 403
            ? t('canvas.notFound')
            : t('canvas.loadError')
        }}
      </p>
      <p class="canvas-view__error-text">{{ error.message }}</p>
      <div class="canvas-view__error-actions">
        <UiButton size="sm" @click="router.push({ name: 'dashboard' })">{{
          t('canvas.toCanvasList')
        }}</UiButton>
        <UiButton
          v-if="error.status !== 404 && error.status !== 403"
          variant="ghost"
          size="sm"
          @click="loadGraph"
        >
          {{ t('common.retry') }}
        </UiButton>
      </div>
    </div>

    <!-- Graph -->
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
          {{ t('canvas.dropToCreate') }}
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
        @node-focus="onNodeFocus"
        @node-hover="onNodeHover"
        @node-delete="onNodeDelete"
        @edge-click="onEdgeClick"
        @edge-select="onEdgeSelected"
        @edge-delete="onEdgeDeleted"
        @edge-context-menu="onEdgeContextMenu"
        @ghost-restore="onGhostRestore"
      >
        <LinkLayer
          :linking="Boolean(linkingSourceId)"
          :source-title="linkingSourceTitle"
          @cancel="cancelLinking"
        />

        <!-- Empty canvas -->
        <div v-if="visibleNodes.length === 0" class="canvas-view__empty">
          <p class="canvas-view__empty-title">{{ t('canvas.emptyTitle') }}</p>
          <p class="canvas-view__empty-text">
            {{ t('canvas.emptyText') }}
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

      <!-- R28: note editor panel (read-only determined inside) -->
      <NoteEditorModal
        v-if="editorOpen"
        ref="editorRef"
        v-model:open="editorOpen"
        :note-id="editorNoteId"
        @saved="onNoteSaved"
        @visibility-changed="onEditorVisibilityChanged"
      />

      <!-- R29: archive of not relevant/deleted notes (>=editor) -->
      <ArchivePanel
        v-if="canEditCanvas && archiveOpen"
        v-model:open="archiveOpen"
        :canvas-id="canvasId"
        @before-action="(done) => void positionSync.flushAll().then(done)"
        @changed="onArchiveChange"
      />

      <CanvasAccessDialog
        v-if="store.canvas && canManageCanvasAccess"
        v-model:open="canvasAccessOpen"
        :canvas-id="store.canvas.id"
        :access-level="store.canvas.access.level"
        :public-slug="store.canvas.access.publicSlug"
        @access-level-changed="onCanvasAccessLevelChanged"
      />

      <NoteAccessDialog
        v-if="noteAccessId"
        v-model:open="noteAccessOpen"
        :note-id="noteAccessId"
        :visibility="noteAccessVisibility"
        @visibility-changed="onNoteVisibilityChanged"
      />
    </div>
  </div>
</template>
