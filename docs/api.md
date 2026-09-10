# API Reference (REST, JSON)

База: `/api/v1`. Ответы — `application/json; charset=utf-8`. Авторизация — `Authorization: Bearer <access>`; refresh живёт в httpOnly-cookie и не виден JS. Полная машинная спецификация — [openapi.yaml](openapi.yaml).

## Соглашения

- Пагинация (где нужна): `?page=1&per_page=50`, ответ `{items, page, per_page, total}`.
- Даты — ISO 8601 UTC. Id — uuid.
- Ошибки — единый формат:

```json
{ "error": { "code": "VALIDATION_FAILED", "message": "Проверьте поля формы", "details": { "email": ["некорректный email"] } } }
```

| HTTP | code | Когда |
|---|---|---|
| 400 | `BAD_REQUEST` | битый JSON/параметры |
| 401 | `UNAUTHORIZED` / `TOKEN_EXPIRED` | нет/истёк access |
| 403 | `FORBIDDEN` | нет прав (в т.ч. email не верифицирован — `EMAIL_NOT_VERIFIED`) |
| 404 | `NOT_FOUND` | не найдено **или скрыто политикой приватности** (чтобы не раскрывать существование) |
| 409 | `CONFLICT` | уникальность (email, тег, линк) |
| 410 | `GONE` | обращение к tombstone |
| 413 | `PAYLOAD_TOO_LARGE` | файл больше лимита |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | недопустимый mime |
| 422 | `VALIDATION_FAILED` | валидация |
| 429 | `TOO_MANY_REQUESTS` | throttling auth-эндпоинтов |

## Auth и профиль

| Метод | Путь | Доступ | Описание |
|---|---|---|---|
| POST | `/auth/register` | все | `{email, password, display_name}` → 201; письмо верификации; refresh-кука **не** выдаётся до верификации |
| POST | `/auth/verify-email` | все | `{token}` → 204 |
| POST | `/auth/resend-verification` | все | `{email}` → всегда 202 |
| POST | `/auth/login` | все | `{email, password}` → 200 `{user, accessToken, accessTokenExpiresIn}` + Set-Cookie refresh; 403 `EMAIL_NOT_VERIFIED` (с пометкой «письмо не пришло?» → resend) |
| POST | `/auth/refresh` | cookie | Ротация: новый access + новая кука; reuse → отзыв всей family (401) |
| POST | `/auth/logout` | auth | отзывает refresh-семью, сбрасывает куку → 204 |
| POST | `/auth/forgot-password` | все | `{email}` → всегда 202; письмо со ссылкой `reset?token=` |
| POST | `/auth/reset-password` | все | `{token, password}` → 204, отзывает все refresh |
| GET | `/auth/me` | auth | текущий пользователь |
| PATCH | `/profile` | auth | `{display_name?, email?}`; смена email → повторная верификация |
| POST | `/profile/avatar` | auth | multipart image ≤5MB |
| DELETE | `/profile/avatar` | auth | |

## Канвасы

| Метод | Путь | Роль | Описание |
|---|---|---|---|
| GET | `/canvases` | auth | мои канвасы + канвасы, куда инвайтили |
| POST | `/canvases` | verified | `{title}` → 201 |
| GET | `/canvases/{id}/graph` | ≥viewer | загрузка канваса целиком (см. ниже) |
| PATCH | `/canvases/{id}` | owner/editor(title) | `{title?}` |
| DELETE | `/canvases/{id}` | owner | hard delete с каскадом |
| GET | `/canvases/{id}/search?q=` | ≥viewer | совпадения по заголовкам и тегам |
| GET | `/canvases/{id}/archive` | ≥editor | `{notRelevant: [...], deleted: [...]}` |

Роль в таблице: `owner` — владелец канваса; `editor` — по инвайту канваса/заметки; автор заметки всегда может свою заметку.

### `GET /canvases/{id}/graph` — 200

```json
{
  "canvas": { "id": "...", "title": "Рабочее", "access": {"level":"private","myRole":"owner"} },
  "nodes": [
    { "id": "note-uuid", "title": "GPT vs LLama", "importance": 4, "isRelevant": true,
      "x": 120, "y": 80, "width": 220, "deletedAt": null, "tags": ["ml"], "authorId": "..." }
  ],
  "edges": [
    { "id": "dl-uuid", "type": "direct",   "from": "a", "to": "b", "state": "ok" },
    { "id": "il-uuid", "type": "indirect", "from": "a", "to": "c", "state": "removed" }
  ],
  "tags": [ { "id": "...", "name": "ml", "color": "#7c3aed" } ]
}
```

`state`: `ok` | `removed` (tombstone «ссылка удалена», видно только имевшим доступ — таким зрителям приходит `to` = null и `label`). Узлы/рёбра без доступа к концу **исключаются из ответа** целиком. `isRelevant=false` ноды приходят, фронт скрывает их без фильтра.

Позиции: `PATCH /canvases/{id}/nodes/{noteId}` `{x?, y?, width?}` (≥editor, дебаунс на клиенте).

## Заметки

| Метод | Путь | Роль | Описание |
|---|---|---|---|
| POST | `/canvases/{id}/notes` | ≥editor | `{title?, x?, y?}` → 201 пустая заметка-нода |
| POST | `/canvases/{id}/notes/from-text` | ≥editor | `{attachmentId, title?, x?, y?}` — импорт .md/.txt → 201 |
| GET | `/notes/{id}` | ≥viewer | `{note (манифест+body), access}` |
| PATCH | `/notes/{id}` | редактор* | манифест: `{title?, importance?, isRelevant?, tagIds?}`; при `isRelevant:false` без `deletedAt` — перемещение в архив «неактуально» |
| PUT | `/notes/{id}/body` | редактор* | `{bodyMd}` → 200; сервер перепарсит `indirect_links` |
| DELETE | `/notes/{id}` | редактор* | soft delete (`deletedAt`) |
| POST | `/notes/{id}/restore` | ≥editor канваса | вернуть из «удалённых» (`deletedAt=null`) |
| DELETE | `/notes/{id}/purge` | ≥editor канваса | hard delete + tombstone |

\* редактор* = автор заметки ∪ editor канваса ∪ editor-инвайт самой заметки.

## Теги (per-canvas)

| Метод | Путь | Роль |
|---|---|---|
| GET | `/canvases/{id}/tags` | ≥viewer |
| POST | `/canvases/{id}/tags` | ≥editor — `{name, color}`; 409 при дубле |
| PATCH | `/tags/{id}` | ≥editor — переименование/цвет; обновляет все заметки канваса |
| DELETE | `/tags/{id}` | ≥editor — снимает тег со всех заметок |

## Ссылки

| Метод | Путь | Роль | Описание |
|---|---|---|---|
| POST | `/links/direct` | ≥editor | `{fromNoteId, toNoteId}` → 201; 409 на дубль; проверка одного канваса |
| DELETE | `/links/direct/{id}` | ≥editor | |
| POST | `/links/direct/{id}/reverse` | ≥editor | разворот стрелки |
| POST | `/canvases/{id}/notes/derive` | ≥editor | «создать от этой заметки»: `{parentNoteId, x?, y?}` → 201 `{note, link}`; родитель → новая (parent имеет ссылку на дочернюю) |

Косвенные ссылки создаются/удаляются только через `PUT /notes/{id}/body` (парсинг), отдельных write-эндпоинтов нет.

## Доступы и инвайты

Для ресурса `{resource}` = `canvases/{id}` или `notes/{id}`:

| Метод | Путь | Роль | Описание |
|---|---|---|---|
| GET | `/{resource}/access` | owner/author | список grant'ов + pending-инвайтов |
| PUT | `/{resource}/access-level` | owner/author | `{level: private\|specific\|public_link}`; при `public_link` генерируется/возвращается slug |
| POST | `/{resource}/access/invite` | owner/author | `{email, role}` → письмо-приглашение (или мгновенный grant, если юзер верифицирован) |
| PATCH | `/{resource}/access/{userId}` | owner/author | `{role}` |
| DELETE | `/{resource}/access/{userId}` | owner/author | отозвать |
| DELETE | `/{resource}/public-link` | owner/author | выключить публичную ссылку (slug → null) |
| GET | `/invitations` | auth | входящие pending-инвайты |
| POST | `/invitations/{id}/accept` · `/decline` | auth | |

## Файлы

| Метод | Путь | Роль | Описание |
|---|---|---|---|
| POST | `/notes/{id}/attachments` | редактор* | multipart `file`; лимиты/mime по kind → 201 `{attachment, url}` |
| GET | `/attachments/{id}/url` | ≥viewer заметки | presigned GET (1 ч) |
| DELETE | `/attachments/{id}` | редактор* | удалить объект в S3 + строку |

## Публичный доступ (анонимы, только чтение)

| Метод | Путь | Описание |
|---|---|---|
| GET | `/shared/canvases/{slug}` | тот же `graph`, но без приватных узлов, без `access`-деталей; 404 если уровень ≠ public_link |
| GET | `/shared/notes/{slug}` | заметка со статичным рендером markdown; недоступные `[[wikilinks]]` → plain text |

## Матрица прав (сводно)

| Действие | owner | author заметки | editor (инвайт канваса) | editor (инвайт заметки) | viewer | аноним (public) |
|---|---|---|---|---|---|---|
| Загрузить/смотреть канвас | ✔ | ✔¹ | ✔ | ✔¹ | ✔ | ✔ (read-only) |
| Двигать/ресайзить ноды | ✔ | ✔¹ | ✔ | ✖ | ✖ | ✖ |
| Создавать заметки | ✔ | ✔¹ | ✔ | ✖ | ✖ | ✖ |
| Редактировать **любую** заметку канваса | ✔ | ✔ (свою) | ✔ | ✖ | ✖ | ✖ |
| Редактировать свою заметку по инвайту | — | — | — | ✔ | ✖ | ✖ |
| Прямые линки (создать/удалить/развернуть) | ✔ | ✔² | ✔ | ✔² | ✖ | ✖ |
| Архив: вернуть/удалить/purge | ✔ | ✔¹ | ✔ | ✖ | ✖ | ✖ |
| Теги (create/rename/delete) | ✔ | ✔¹ | ✔ | ✔² | ✖ | ✖ |
| Доступы/инвайты/публичная ссылка | ✔ | ✔³ | ✖ | ✖ | ✖ | — |
| Смена уровня доступа | ✔ | ✔³ | ✖ | ✖ | ✖ | — |

¹ если имеет право на канвас (автор обычно editor или owner) · ² только между доступными ему заметками · ³ только в рамках своей заметки.
