# API Reference (REST, JSON)

База: `/api/v1`. Ответы — `application/json; charset=utf-8`. Авторизация — `Authorization: Bearer <access>`; refresh живёт в httpOnly-cookie `vistynq_refresh` и не виден JS. Полная машинная спецификация — [openapi.yaml](openapi.yaml).

## Соглашения

- **Имена полей в JSON — `camelCase`** (`displayName`, `perPage`, `bodyMd`, `fromNoteId`). В БД — `snake_case`, см. [data-model.md](data-model.md).
- Даты — ISO 8601 UTC. Id — uuid.
- Пагинация (`/canvases`, `/canvases/{id}/search`, `/canvases/{id}/archive`): `?page=1&perPage=50` (по умолчанию 1 и 50, максимум 200), ответ `{items, page, perPage, total}`.
- Остальные списки (теги, участники доступа) — плоские массивы.
- Ошибки — единый формат:

```json
{ "error": { "code": "VALIDATION_FAILED", "message": "Проверьте поля формы", "details": { "email": ["некорректный email"] } } }
```

| HTTP | code | Когда |
|---|---|---|
| 400 | `BAD_REQUEST` | битый JSON/параметры |
| 401 | `UNAUTHORIZED` / `TOKEN_EXPIRED` | нет/невалиден/истёк access |
| 403 | `FORBIDDEN` | ресурс виден, но прав на действие недостаточно |
| 403 | `EMAIL_NOT_VERIFIED` | только `POST /auth/login`: email не подтверждён |
| 404 | `NOT_FOUND` | не найдено **или недоступно** (чтобы не раскрывать существование) |
| 409 | `CONFLICT` | уникальность (email, имя тега, ссылка), заметки из разных канвасов |
| 410 | `GONE` | токен протух; заметка стёрта (purge) |
| 413 | `PAYLOAD_TOO_LARGE` | файл больше лимита |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | недопустимый mime |
| 422 | `VALIDATION_FAILED` | валидация |
| 429 | `TOO_MANY_REQUESTS` | throttling `/auth/*` |
| 500 | `INTERNAL` | необработанная ошибка (без стека в prod) |

**Политика 403/404.** Если у пользователя нет доступа к ресурсу вообще — всегда `404`. `403` — только когда ресурс ему виден (например, viewer пытается редактировать).

## Роли и права

- **owner** — владелец канваса / автор заметки (один человек — создатель). Полные права на свой ресурс; владелец канваса имеет полные права и на все заметки канваса.
- **editor / viewer** — роль, выданная владельцем (`canvas_access` / `note_access`). Роль `editor` едина; её область зависит от того, на что она выдана: на канвас — весь канвас, на заметку — только эта заметка.
- В таблицах ниже:
  - **≥editor канваса** = owner канваса или editor канваса;
  - **≥viewer** = любой, у кого есть доступ к ресурсу (для заметки — с учётом её `visibility`);
  - **редактор заметки** = owner/автор ∪ owner канваса ∪ editor канваса (если заметка `public`) ∪ editor этой заметки;
  - **owner/author** = владелец канваса / автор заметки (и владелец канваса для любой заметки своего канваса).
- Приватная заметка (`visibility: private`) доступна только автору, владельцу канваса и тем, кому выдан доступ именно на неё; права editor/viewer на канвас на неё **не** распространяются.
- Пользователь без подтверждённого email войти не может, поэтому отдельной роли «неподтверждённый» у защищённых эндпоинтов нет.

## Системные

| Метод | Путь | Описание |
|---|---|---|
| GET | `/healthz` | `{"status":"ok"}` (с проверкой БД через `Database::ping()`); без авторизации |

## Auth и профиль

| Метод | Путь | Доступ | Описание |
|---|---|---|---|
| POST | `/auth/register` | все | `{email, password, displayName}` → 201; письмо верификации; refresh-кука **не** выдаётся. 409 — email занят |
| POST | `/auth/verify-email` | все | `{token}` → 204. Подтверждает и регистрацию, и смену email; 410 если токен протух. После подтверждения `pending`-инвайты на этот email превращаются в доступы |
| POST | `/auth/resend-verification` | все | `{email}` → всегда 202 |
| POST | `/auth/login` | все | `{email, password}` → 200 `{user, accessToken, accessTokenExpiresIn}` + Set-Cookie refresh; 401 — неверные данные; 403 `EMAIL_NOT_VERIFIED` (UI предлагает «письмо не пришло?» → resend) |
| POST | `/auth/refresh` | cookie | Ротация: `{user, accessToken, accessTokenExpiresIn}` + новая кука; reuse → отзыв всей family (401) |
| POST | `/auth/logout` | auth | отзывает refresh-семью, сбрасывает куку → 204 |
| POST | `/auth/forgot-password` | все | `{email}` → всегда 202; письмо со ссылкой на клиентский роут `/reset-password?token=` |
| POST | `/auth/reset-password` | все | `{token, password}` → 204, отзывает все refresh; 410 если токен протух |
| GET | `/auth/me` | auth | текущий пользователь |
| PATCH | `/profile` | auth | `{displayName?, email?}` → 200 User. Смена email: письмо на **новый** адрес, в ответе `pendingEmail`; `email` меняется после подтверждения; 409 — адрес занят |
| POST | `/profile/avatar` | auth | multipart `file` (image ≤5 МБ) → 201 `{avatarUrl}` |
| DELETE | `/profile/avatar` | auth | → 204 |

## Канвасы

| Метод | Путь | Доступ | Описание |
|---|---|---|---|
| GET | `/canvases` | auth | мои канвасы и те, к которым (или к заметкам которых) мне выдан доступ; `{items,...}`; у каждого `noteCount` — число доступных мне живых заметок, `updatedAt`, `access.myRole` |
| POST | `/canvases` | auth | `{title}` → 201 |
| GET | `/canvases/{id}/graph` | ≥viewer | загрузка канваса целиком (см. ниже) |
| PATCH | `/canvases/{id}` | **owner** | `{title?}`; пустое тело — no-op |
| DELETE | `/canvases/{id}` | owner | hard delete с каскадом (заметки, ссылки, теги, доступы, инвайты, tombstone, вложения) → 204 |
| PATCH | `/canvases/{id}/nodes/{noteId}` | ≥editor канваса или owner заметки | `{x?, y?, width?}` (120..800) → 204; дебаунс на клиенте |
| GET | `/canvases/{id}/search?q=&page=&perPage=` | ≥viewer | совпадения по заголовкам и тегам доступных заметок: `{items:[{id,title,importance,matchedTag}]}` |
| GET | `/canvases/{id}/archive?section=&page=&perPage=` | ≥editor канваса | `section=notRelevant\|deleted` → `{items:[Note],...}`; `deleted` — только soft-deleted (стёртые навсегда сюда не попадают) |

### `GET /canvases/{id}/graph` — 200

```json
{
  "canvas": { "id": "...", "title": "Рабочее", "ownerId": "...",
              "access": {"level":"private","publicSlug":null,"myRole":"owner"},
              "createdAt": "2026-11-02T10:00:00+00:00", "updatedAt": "2026-11-02T10:00:00+00:00" },
  "nodes": [
    { "id": "note-uuid", "title": "GPT vs LLama", "importance": 4, "isRelevant": true, "visibility": "public",
      "x": 120, "y": 80, "width": 220, "deletedAt": null, "tagIds": ["tag-uuid"], "authorId": "..." }
  ],
  "edges": [
    { "id": "dl-uuid", "type": "direct",   "from": "a", "to": "b", "state": "ok", "label": null },
    { "id": "il-uuid", "type": "indirect", "from": "a", "to": null, "state": "removed", "label": "[[c]]" }
  ],
  "tags": [ { "id": "tag-uuid", "name": "ml", "color": "#7c3aed" } ]
}
```

Правила выдачи:
- В `nodes` попадают только живые (не soft-deleted) заметки, которые пользователь может читать. `isRelevant=false` приходят, фронт скрывает их без фильтра. `access.publicSlug` виден только владельцу.
- Ребро `ok` — обе заметки доступны пользователю. Ребро с недоступным концом **исключается из ответа целиком**.
- `state: removed` («ссылка удалена») — только для зрителей, которым это положено:
  - **цель стёрта навсегда** (косвенная ссылка): `to = null`, `label` — исходный текст `[[...]]`; видно авторизованным пользователям, которые видят заметку-источник;
  - **цель в soft-delete** (прямая или косвенная): `to` = id цели (чтобы предложить «восстановить»); видно только ≥editor канваса;
  - у прямых ссылок на стёртую навсегда заметку пометки нет — ссылка удаляется вместе с заметкой.
- Для `state: ok` — `label: null`.

## Заметки

| Метод | Путь | Доступ | Описание |
|---|---|---|---|
| POST | `/canvases/{id}/notes` | ≥editor канваса | `{title?, x?, y?}` → 201 Note (автор — вызывающий; `visibility: public`) |
| POST | `/canvases/{id}/notes/from-text` | ≥editor канваса | multipart: `file` (.md/.txt ≤1 МБ), поля `title?`, `x?`, `y?` → 201 Note; тело файла → `bodyMd`, заголовок — из поля, иначе из имени файла |
| POST | `/canvases/{id}/notes/derive` | ≥editor канваса | «создать от этой заметки»: `{parentNoteId, x?, y?}` → 201 `{note, link}`; родитель → новая |
| GET | `/notes/{id}` | ≥viewer | 200 NoteDetail — плоский объект: Note + `{bodyMd, myRole}` |
| PATCH | `/notes/{id}` | редактор заметки | манифест: `{title?, importance?, isRelevant?, tagIds?}` → 200 NoteDetail; `isRelevant:false` — в архив «неактуально», `true` — возврат; `tagIds` — только существующие теги этого канваса. Назначать теги может и editor заметки |
| PUT | `/notes/{id}/body` | редактор заметки | `{bodyMd}` → 200 NoteDetail; сервер перепарсит `indirect_links` |
| DELETE | `/notes/{id}` | owner/author, ≥editor канваса | soft delete (`deletedAt`) → 204 |
| POST | `/notes/{id}/restore` | owner/author, ≥editor канваса | вернуть из «удалённых» → 200 Note; стёрта навсегда → 410; нет заметки/tombstone либо канвас недоступен → 404 |
| DELETE | `/notes/{id}/purge` | owner/author, ≥editor канваса | hard delete + tombstone → 204; editor только этой заметки → 403 |

Видимость заметки (`visibility`) меняется отдельным вызовом — см. «Доступ».

## Теги (per-canvas)

| Метод | Путь | Доступ |
|---|---|---|
| GET | `/canvases/{id}/tags` | ≥viewer — `[{id, name, color}]`, по имени |
| POST | `/canvases/{id}/tags` | ≥editor канваса — `{name, color?}` → 201; цвет `#rrggbb`; 409 при дубле имени без учёта регистра |
| PATCH | `/tags/{id}` | ≥editor канваса — `{name?, color?}` → 200; переименование видно на всех заметках сразу; пустое тело — no-op |
| DELETE | `/tags/{id}` | ≥editor канваса → 204 — hard delete, тег снимается со всех заметок (каскад `note_tags`) |

## Ссылки

| Метод | Путь | Доступ | Описание |
|---|---|---|---|
| POST | `/links/direct` | ≥editor канваса | `{fromNoteId, toNoteId}` → 201; 409 на дубль / разные канвасы / from==to |
| DELETE | `/links/direct/{id}` | ≥editor канваса | → 204 |
| POST | `/links/direct/{id}/reverse` | ≥editor канваса | разворот стрелки → 200 |

Косвенные ссылки создаются/удаляются только через `PUT /notes/{id}/body` (парсинг), отдельных write-эндпоинтов нет. «Создать от этой заметки» — `POST /canvases/{id}/notes/derive`.

## Доступ

Выдаёт и меняет доступ только **owner/author** ресурса (владелец канваса — для канваса и любой его заметки; автор — для своей заметки).

**Канвас**

| Метод | Путь | Описание |
|---|---|---|
| GET | `/canvases/{id}/access` | список участников и ожидающих инвайтов: `[{userId, email, role, pending, invitationId}]` |
| PUT | `/canvases/{id}/access-level` | `{level: private\|public}` → 200 `{level, publicSlug}`; `public` генерирует новый slug, `private` обнуляет |
| POST | `/canvases/{id}/access/invite` | `{email, role}` → 201 `{status: granted\|pending}` |
| PATCH | `/canvases/{id}/access/{userId}` | `{role}` → 204 |
| DELETE | `/canvases/{id}/access/{userId}` | отозвать → 204 |
| DELETE | `/canvases/{id}/invitations/{invitationId}` | отозвать ожидающий инвайт → 204 |

**Заметка** — то же самое, но вместо `access-level` — видимость:

| Метод | Путь | Описание |
|---|---|---|
| GET | `/notes/{id}/access` | участники и инвайты заметки |
| PUT | `/notes/{id}/visibility` | `{visibility: public\|private}` → 200 `{visibility}` |
| POST | `/notes/{id}/access/invite` | `{email, role}` → 201 `{status}` |
| PATCH | `/notes/{id}/access/{userId}` | `{role}` → 204 |
| DELETE | `/notes/{id}/access/{userId}` | → 204 |
| DELETE | `/notes/{id}/invitations/{invitationId}` | → 204 |

**Как работает приглашение (`access/invite`):**
- пользователь с таким email **уже зарегистрирован** → доступ выдаётся **мгновенно** (`status: granted`), ему уходит письмо-уведомление; принимать ничего не нужно;
- аккаунта нет → создаётся `pending`-инвайт, уходит письмо со ссылкой на регистрацию (`status: pending`); после регистрации и подтверждения email доступ выдаётся автоматически.

Отдельных эндпоинтов «принять/отклонить» нет. Пользователь видит выданные ему канвасы в `GET /canvases`.

## Файлы

| Метод | Путь | Доступ | Описание |
|---|---|---|---|
| POST | `/notes/{id}/attachments` | редактор заметки | multipart `file` (image png/jpg/gif/webp ≤5 МБ, audio mp3/ogg/wav ≤25 МБ) → 201 Attachment (с полем `url` — presigned GET, 1 ч); 413 / 415 |
| GET | `/attachments/{id}/url` | ≥viewer заметки | `{url, expiresIn}` — свежий presigned GET |
| DELETE | `/attachments/{id}` | редактор заметки | удалить объект в S3 + строку → 204 |

Аватар загружается через `/profile/avatar`. `.md/.txt` вложениями не становятся: на канвас — `notes/from-text`, в редактор — клиент читает файл и вставляет текст.

## Публичный доступ (анонимы, только чтение)

| Метод | Путь | Описание |
|---|---|---|
| GET | `/shared/canvases/{slug}` | тот же `graph`, но только публичные заметки, без `removed`-рёбер и без `access`-деталей; 404 если канвас не `public` |
| GET | `/shared/canvases/{slug}/notes/{noteId}` | `{note, bodyHtml}` — публичная заметка публичного канваса со статичным рендером markdown; недоступные `[[wikilinks]]` → plain text; 404 для приватных |

Анонимы не ходят в `/canvases/*` и `/notes/*`: эти маршруты всегда требуют Bearer, а недействительный Bearer — 401.

## Матрица прав (сводно)

| Действие | owner / author | editor (канвас) | editor (только заметка) | viewer | аноним (public) |
|---|---|---|---|---|---|
| Загрузить/смотреть канвас | ✔ | ✔ | ✔¹ | ✔ | ✔ (read-only, публичные заметки) |
| Двигать/ресайзить ноды | ✔ | ✔ | ✖ | ✖ | ✖ |
| Создавать заметки | ✔ | ✔ | ✖ | ✖ | ✖ |
| Редактировать манифест/тело заметки | ✔ (любую в своём канвасе; автор — свою) | ✔ (публичные²) | ✔ (только свою выданную) | ✖ | ✖ |
| Архив «неактуально», soft delete, restore, purge | ✔ | ✔ (публичные²) | ✖ | ✖ | ✖ |
| Прямые линки (создать/удалить/развернуть) | ✔ | ✔ | ✖ | ✖ | ✖ |
| Теги: создать/переименовать/удалить | ✔ | ✔ | ✖ | ✖ | ✖ |
| Теги: назначить заметке | ✔ | ✔ | ✔ | ✖ | ✖ |
| Файлы заметки (upload/delete) | ✔ | ✔ (публичные²) | ✔ | ✖ | ✖ |
| Доступы, инвайты, видимость, публичная ссылка | ✔ | ✖ | ✖ | ✖ | — |
| Переименовать / удалить канвас | ✔ (только владелец) | ✖ | ✖ | ✖ | ✖ |

¹ только выданные ему заметки. ² приватные заметки других людей editor/viewer канваса не видят и не правят: только автор, владелец канваса и те, кому выдан доступ на эту заметку.
