# Vistynq — TODO

Рабочий чеклист по [ROADMAP.md](ROADMAP.md). Отмечай `[x]`, когда пункт сделан. Задача закрыта, когда выполнены все её пункты **и** критерий готовности.
Приоритеты: P0 критично · P1 важно · P2 желательно · P3 позже. В скобках — зависимости.

**Прогресс по этапам**:

- Этап 1 Основа: 6/7
- Этап 2 Backend: 4/13
- Этап 3 Backend-тесты: 1/2
- Этап 4 Frontend: 0/12
- Этап 5 Качество: 0/1
- Этап 6 Продакшн: 0/1
- Этап 7 Документация разработчика: 0/1

---

## Этап 1 — Основа (P0)

### R01 · Окружение (P0)

- [x] `docker-compose.yml`: postgres:15, minio + minio-init (бакет `vistynq-media`), mailpit
- [x] сервис `test-postgres` (порт 5433, БД `vistynq_test`)
- [x] сервис backend (nginx + php-fpm, `pdo_pgsql`, sodium) и frontend (vite)
- [x] `.env.example` в корне и `apps/backend/.env.example` со всеми переменными из architecture.md — корневой: только переменные интерполяции compose (добавлены `TEST_POSTGRES_*`), `apps/backend/.env.example`: полный контракт бэкенда (26 ключей = `Config.php`), включая `apps/frontend/.env.example` (`VITE_API_TARGET`); комментарии в env example-файрах убраны
- [x] `/healthz` возвращает `{"status":"ok"}`
- [x] **Критерий:** `docker compose up -d` поднимает всё, `curl :8000/api/v1/healthz` → ok

### R02 · Lefthook (P0)

- [x] исправить путь на `apps/backend/vendor/bin/php-cs-fixer` с `--config`
- [x] **Критерий:** стейдж `.php` не роняет хук — прогон подтверждён: `lefthook run pre-commit --commands format-php` (или `--files <файл>`) exit 0;

### R03 · PHP-скелет (P0, ← R01)

- [x] Config из env (типизированный)
- [x] error handler (`ApiException` → JSON `{error}`, 500 без стека в prod)
- [x] Router (fast-route), 404/405 → JSON
- [x] DI-контейнер (PSR-11)
- [x] middleware: CORS-whitelist, JSON-парсинг тела
- [x] `Support/Validator`, `Models/DTO`
- [x] `public/index.php` (front controller), `bin/serve`
- [x] **Критерий:** CORS preflight ок, валидация JSON-тел, prod-500 без стека

### R04 · Frontend-каркас (P0)

- [x] удалить scaffold create-vue (HelloWorld, TheWelcome, icons)
- [x] `index.html` (lang=ru, title «Vistynq»)
- [x] Vue Router + маршруты из ui-ux.md (заглушки, в т.ч. Landing на `/`, Dashboard на `/dashboard`, `/shared/canvas/:slug/note/:noteId`)
- [x] guards `requires-auth` и `guest-only`
- [ ] Pinia: stores `auth`, `canvases`, `canvas`, `notes`, `ui` — частично: реализован только store `auth`
- [x] http-клиент: Bearer, single-flight refresh, повтор запроса, повторный 401 = logout
- [ ] **Критерий:** запрос с Bearer проходит, параллельные 401 не дублируют refresh — частично: Bearer проверен живым запросом, параллельные 401 тестом не покрыты

### R05 · Миграционный раннер + auth-схема (P0, ← R01, R03)

- [x] `bin/migrate`: `schema_migrations`, применение в транзакции, `--status`
- [x] `0001` + corrective-миграция `0004`: `users` (с `pending_email`), `refresh_tokens`, `auth_tokens` (purpose: email_verify / email_change / password_reset) + индексы

### R06 · CI-1 (P0, ← R02)

- [x] workflow: Node 22 + pnpm, composer install
- [x] `turbo check` (eslint, vue-tsc, prettier-check)
- [x] php-cs-fixer check
- [x] **Критерий:** зелёный на пустом PR

### R07 · Синхронизация доков (P0)

- [x] единая модель доступа (owner = author, одна роль editor, private/public)
- [x] camelCase в API и коде, snake_case в БД
- [x] verify-email через POST; смена email через `pendingEmail`
- [x] `data-model` ↔ `api` ↔ `openapi` ↔ `architecture` ↔ `ui-ux` ↔ `user-guide` согласованы
- [x] лендинг, ROADMAP и TODO обновлены

---

## Этап 2 — Backend (P1–P2)

### R08 · Миграции домена (P1, ← R05)

- [x] `canvases` (access_level private/public, public_slug, CHECK slug) — выровнено миграцией `0004` (public_link → public, CHECK `(access_level='public') = (public_slug IS NOT NULL)`)
- [x] `notes` (visibility, importance, is_relevant, x/y/width, deleted_at) + индексы — `visibility` добавлен в `0004`, индекс `(author_id)` создан
- [x] `tags`, `note_tags` (индекс `(tag_id)`)
- [x] `direct_links` (UNIQUE, CHECK from≠to)
- [x] `indirect_links` (`to_note_id` SET NULL, `deleted_target_id`, `link_label`, UNIQUE `(from_note_id, raw_target)`) — колонки и UNIQUE добавлены в `0004` (дубль dev-данных предварительно удалён)
- [x] `deleted_notes` (canvas_id CASCADE) — FK `ON DELETE CASCADE` добавлен в `0004`
- [x] `canvas_access`, `note_access` (granted_by) — `granted_by` добавлен в `0004`
- [x] `invitations` (canvas_id/note_id, CHECK «ровно одно», статусы pending/accepted/revoked) — перенос из полиморфных `resource_*` и CHECK'и в `0004`
- [x] `attachments` (kind image/audio/avatar, note_id CASCADE) — CHECK без `text_import` и `note_id ON DELETE CASCADE` в `0004`

### R09 · Архитектура (P1, ← R03)

- [x] `*Interface` для всех репозиториев
- [x] `ContainerFactory` — единая точка биндингов
- [x] `Database::transaction()` и `Database::ping()`
- [x] маппинг snake_case ↔ camelCase в Models
- [x] тонкие контроллеры (Request → сервис → Response)

### R10 · Auth 1 (P1, ← R03, R05, R08, R09)

- [x] `POST /auth/register` (письмо верификации через PHPMailer → Mailpit)
- [ ] `POST /auth/verify-email` (регистрация и смена email) — частично: подтверждение регистрации работает (в dev скип `isDev()` убран — письмо всегда, токен читается из Mailpit), смена email (`email_change`) не реализована
- [x] `POST /auth/resend-verification` (всегда 202)
- [x] `POST /auth/login` (argon2id; 403 `EMAIL_NOT_VERIFIED`; access-JWT HS256, TTL 900 с)
- [x] `GET /auth/me`
- [ ] `PATCH /profile` (имя; email → `pendingEmail` + письмо на новый адрес) — частично: маршрут и контроллер отсутствуют, `pendingEmail` не реализован

### R11 · Auth 2 (P1, ← R10)

- [x] refresh-кука `vistynq_refresh` (HttpOnly, Secure по env, SameSite=Lax, Path)
- [x] хэш SHA-256 + ротация + `family_id`
- [x] reuse-detection → отзыв всей семьи (401)
- [x] `POST /auth/logout`
- [x] `forgot-password` (всегда 202) и `reset-password` (отзыв всех refresh)
- [x] rate-limit на `/auth/*` → 429
- [x] **Критерий:** старый refresh отзывает семейство; 429 при превышении

### R12 · Права + канвасы (P1, ← R08, R09, R10)

- [x] `AccessService`: owner / editor / viewer, `visibility`, public-канвас — `visibility` учитывается в graph/archive/search и `GET /notes/{id}` (private: author/owner/note_access)
- [x] политика: нет доступа → 404, мало прав → 403
- [ ] `POST/GET /canvases` (включая выданные, `myRole: none` для доступа только к заметкам) — частично: `myRole: none` недостижим
- [x] `PATCH /canvases/{id}` и `DELETE` — только owner, hard delete каскадом (инвайты и tombstone'ы — через FK CASCADE из `0004`)
- [x] `GraphService`: один запрос без N+1, фильтрация недоступного
- [ ] состояния `removed` (стёртая цель — `to = null`; soft-delete — `to = id`, только ≥editor) — частично: при soft-delete цели в ребре `to = null` вместо `to = id`
- [x] **Критерий:** viewer не видит чужую приватную ноду и ребро к ней

### R13 · Заметки (P1, ← R12)

- [x] `POST /canvases/{id}/notes`
- [ ] `POST /canvases/{id}/notes/from-text` (multipart `.md/.txt` ≤1 МБ) — частично: маршрут отсутствует
- [x] `GET /notes/{id}`, `PATCH /notes/{id}` (манифест, `isRelevant` туда-обратно)
- [x] `PUT /notes/{id}/body`
- [x] `PATCH /canvases/{id}/nodes/{noteId}` (x, y, width 120–800)
- [x] `DELETE /notes/{id}` (soft) и `POST /notes/{id}/restore`
- [ ] `DELETE /notes/{id}/purge` (tombstone, `deleted_target_id`, каскад прямых ссылок, 403 для editor заметки) — частично: при purge не заполняется `deleted_target_id`
- [ ] `GET /canvases/{id}/archive?section=` — частично: параметр `section` игнорируется (ответ `{notRelevant, deleted}` вместо `{items, …}`)
- [ ] `GET /canvases/{id}/search` (с `matchedTag`) — частично: `matchedTag` не возвращается

### R14 · Теги (P1, ← R12)

- [x] `GET/POST /canvases/{id}/tags`, `PATCH/DELETE /tags/{id}` (409 на дубль)
- [x] синхронизация `note_tags` в транзакции с манифестом
- [x] editor заметки может назначать существующие теги

### R15 · Ссылки backend (P2, ← R13)

- [ ] `POST /links/direct` (409: дубль / разные канвасы / from = to) — частично: маршрут отсутствует (нет LinkController)
- [ ] `DELETE /links/direct/{id}`, `POST /links/direct/{id}/reverse` — частично: маршрутов нет
- [ ] `POST /canvases/{id}/notes/derive` — частично: маршрут отсутствует
- [x] `LinkParserService`: разбор `[[target|label]]`, резолв по uuid и заголовку
- [x] пересоздание `indirect_links` в транзакции, дедуп по цели
- [ ] табличные unit-тесты парсера — частично: тесты парсера отсутствуют

### R16 · Доступ и публикация backend (P2, ← R10, R12, R13)

- [ ] `PUT /canvases/{id}/access-level` (новый slug / обнуление)
- [ ] `PUT /notes/{id}/visibility`
- [ ] `POST …/access/invite`: аккаунт есть → мгновенный grant + письмо; нет → `pending`
- [ ] `GET …/access`, `PATCH/DELETE …/access/{userId}`, `DELETE …/invitations/{invitationId}`
- [ ] `InvitationService`: `pending` → `accepted` при подтверждении email
- [ ] только owner/author выдают и меняют доступ
- [ ] `GET /shared/canvases/{slug}` (только публичные заметки, без `removed`-рёбер)
- [ ] `GET /shared/canvases/{slug}/notes/{noteId}`

### R17 · Storage и файлы (P2, ← R08, R13)

- [ ] `StorageInterface`, LocalAdapter, S3Adapter (aws-sdk)
- [ ] проверка mime по содержимому (finfo) и лимитов (image 5 МБ, audio 25 МБ)
- [ ] ключи `canvases/{canvasId}/notes/{noteId}/{uuid}.{ext}` и `avatars/{userId}/{uuid}.{ext}`
- [ ] `POST /notes/{id}/attachments` (201 с `url`), `GET /attachments/{id}/url`, `DELETE /attachments/{id}`
- [ ] `POST/DELETE /profile/avatar`
- [ ] удаление объектов S3 после коммита при удалении заметки/канваса

### R18 · XSS (P2, ← R15, R16)

- [ ] `MarkdownRenderer` с санитизацией (`bodyHtml` на `/shared`)
- [ ] недоступные `[[wikilinks]]` → plain text
- [ ] DOMPurify в Tiptap

### R19 · Пагинация (P2, ← R12, R13)

- [ ] `page`/`perPage` (1/50, максимум 200) для `/canvases`, search, archive — частично: описано в openapi, в коде не реализовано
- [ ] формат `{items, page, perPage, total}`; openapi синхронизирован — частично: в openapi есть, бэкенд не отдаёт

### R20 · Логирование (P2, ← R03)

- [x] `RequestId` middleware — эхо валидного `X-Request-Id` или uuid v4 на каждом ответе (включая ошибки), атрибут `requestId`
- [ ] Logger: одна строка, json-контекст, без токенов и паролей — частично: нет, логирование через сырой `error_log`

---

## Этап 3 — Backend-тесты (P1)

### R21 · Тесты 1 (P1, ← R09, R11)

- [x] PHPUnit настроен, `composer test`, `test:unit`, `test:integration`
- [x] unit: JwtService, Validator, ErrorHandler
- [x] интеграция: register → verify → login → refresh (ротация + reuse → 401) → logout — verify-шаг выполняется токеном из Mailpit; login до verify покрыт 403 `EMAIL_NOT_VERIFIED`

### R22 · Тесты 2 (P1, ← R15, R16, R21)

- [x] матрица прав: owner / editor канваса / editor заметки / viewer / аноним
- [x] приватные заметки не видны (и ссылки на них)
- [x] graph-фильтрация и состояния `removed`
- [x] 404 vs 403
- [ ] purge: tombstone, 410 на restore, прямые ссылки удалены, косвенные → «ссылка удалена» — частично: нет проверки удаления прямых ссылок при purge
- [ ] мгновенный grant существующему пользователю; `pending` → `accepted` при подтверждении email — частично: не покрыты (InvitationService не существует)

---

## Этап 4 — Frontend (P1–P3)

### R23 · Frontend базовый (P1, ← R04, R11, R12, R19)

- [x] auth-экраны: логин, регистрация, верификация, забыли/сброс пароля
- [ ] «письмо не пришло» → resend на странице входа — частично: UI-кнопки resend нет
- [ ] Landing на `/` (описание, кнопки Войти/Регистрация / «Открыть дашборд») — частично: нет кнопок «Регистрация» и «Открыть дашборд» (есть «Войти» и «Начать»)
- [ ] Dashboard: карточки, бейджи роли и «публичный», постраничный список — частично: пагинации нет
- [ ] Profile: имя, email с `pendingEmail` — частично: `pendingEmail` не отображается
- [x] toasts (успех, сеть, «Сессия истекла»)
- [x] CanvasView read-only: скелетон, empty-state, retry-баннер

### R24 · Структура frontend (P2, ← R04, R23)

- [ ] composables: `useDebounce`, `useContextMenu`, `useDropFiles`, `useGraph`, `useAccessGuard` — частично: реализован только `useToast`
- [ ] типизированные api-модули по openapi — частично: только `auth` и `canvas` (+errors/notify)

### R25 · Vue Flow 1 (P2, ← R23, R12)

- [x] рендер нод и рёбер из `/graph`
- [x] стили нод по важности, рёбер (прямые/косвенные)
- [ ] зум к курсору, панорама — частично: только дефолты Vue Flow, явная настройка зума к курсору не сделана

### R26 · Vue Flow 2 (P2, ← R25)

- [ ] drag и resize нод — частично: выключено (`nodes-draggable=false`)
- [ ] PATCH позиций с дебаунсом 500 мс

### R27 · Vue Flow 3 (P2, ← R26, R14)

- [ ] контекстное меню по правам (editor — полное, viewer — «Открыть»)
- [ ] фильтр важности и «Показывать неактуальные»
- [ ] меню тегов, подсветка по hover, CRUD тегов
- [ ] поиск и центрирование камеры

### R28 · Редактор заметки (P2, ← R25, R13, R14)

- [ ] модалка/панель, `ManifestForm` (заголовок, важность, теги, актуальность)
- [ ] переключатель видимости и кнопка «Доступ» (для автора/владельца)
- [ ] Tiptap-редактор markdown (H1–H3, bold/italic, список, код, ссылка)
- [ ] «Сохранить»: PATCH манифеста + PUT тела
- [ ] read-only режим для viewer

### R29 · Архив-панель (P2, ← R27, R13)

- [ ] секции «Неактуальные» и «Удалённые» (постранично)
- [ ] «Вернуть», «Восстановить», «Стереть навсегда» с подтверждением и предупреждением

### R30 · Ссылки UI (P2, ← R27, R15, R28)

- [ ] режим связывания («Ссылка на другую заметку…»)
- [ ] «Создать от этой заметки»
- [ ] ПКМ по прямой связи (развернуть/удалить), Del
- [ ] стили «ссылка удалена» и кнопка «восстановить» (для editor+)
- [ ] расширение Tiptap `WikiLink` + автодополнение `[[`

### R31 · Файлы UI (P2, ← R17, R28)

- [ ] drop `.md/.txt` на канвас → `from-text`
- [ ] drop в редактор: картинка, аудио (плеер), `.md/.txt` → текст в курсор
- [ ] прогресс загрузки, тосты ошибок
- [ ] аватар в профиле

### R32 · Доступ UI (P2, ← R16, R18, R25)

- [ ] диалог «Доступ» канваса: «Только я» / «Публичная ссылка», копирование URL
- [ ] выдача по email с ролью, список участников и ожидающих, смена роли, отзыв
- [ ] диалог «Доступ» заметки + переключатель public/private
- [ ] `SharedCanvas` и `SharedNote` (read-only, баннер) — частично: страницы-заглушки с баннером, данные не подключены

### R33 · Vitest (P2, ← R24, R30)

- [ ] парсер `[[...]]` на клиенте — частично: тестов нет
- [x] stores
- [ ] guard-логика — частично: тестов нет

### R34 · i18n, hotkeys, полировка (P3, ← R29–R32)

- [ ] все строки через `t('…')`, словари `ru.json` / `en.json`
- [ ] переключатель языка в шапке и профиле
- [ ] горячие клавиши: Del, Ctrl+F, Esc
- [ ] ревизия пустых/загрузочных/ошибочных состояний

---

## Этап 5 — Качество (P1)

### R35 · CI-2 (P1, ← R22, R32, R33)

- [x] phpunit в Actions (сервис test-postgres)
- [x] vitest
- [ ] playwright + e2e `register → verify → canvas → note → link → public link` — частично: шаг verify (login → 403 → токен из Mailpit → verify) добавлен; шаги «link» и «public link» остаются `test.skip`
- [x] gitleaks secret-scan
- [x] openapi-check (валидация спецификации)

---

## Этап 6 — Продакшн (P3)

### R36 · Продакшн (P3, ← R01, R35)

- [ ] `apps/backend/Dockerfile` — частично: dev-сборка (сборка vendor на старте контейнера, без multi-stage/`--no-dev`)
- [ ] статика SPA за nginx
- [ ] nginx `limit_req` + CORS, healthcheck
- [ ] тег-релизы и CD-workflow
- [ ] прод-параметры (домен, S3-region, SMTP)

---

## Этап 7 — Документация разработчика (P3)

### R37 · Dev-docs (P3, ← R06)

- [ ] `CONTRIBUTING.md`, env-справочник — частично: нет CONTRIBUTING.md
- [ ] `docs/ADR`
- [ ] `Makefile`, turbo `test` — частично: turbo `test` есть, Makefile нет
- [x] `apps/frontend/README.md` (npm → pnpm)
- [ ] PHP-lint в корневом `turbo check` — частично: не включён (в `apps/backend/package.json` только `dev`)
