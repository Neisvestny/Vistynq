# Vistynq — Roadmap развития продукта

> Версия: 2026-11-13 · Статус: согласовано с владельцем
>
> Источник правды по функциональности — `docs/`. Окружение (docker-compose) и CI живут в этом
> репозитории. Рабочий чеклист для отметки прогресса — [TODO.md](TODO.md).
>
> **Решения, зафиксированные на ревью:**
> - aws-sdk подключается на этапе файлов (R17); пароли — argon2id (нужен ext-sodium);
> - канвас удаляется hard-delete с каскадом; прод-параметры — заглушки до R36;
> - JSON и код — `camelCase`, БД — `snake_case`;
> - владелец канваса = автор канваса, автор заметки = её владелец; роль `editor` одна, различается область (канвас или заметка);
> - канвас: `private` / `public`; заметка: `public` / `private`; доступ существующему пользователю — мгновенный, без «принять/отклонить»;
> - переименовывает и удаляет канвас только владелец; `.md/.txt` не хранятся как вложения (импорт через `from-text`);
> - войти без подтверждённого email нельзя; смена email — через `pending_email`;
> - лендинг на `/` входит в v1, Dashboard — на `/dashboard`.
>
> ID задач сквозные и последовательные (R01–R37); прежняя нумерация (R17 «архитектура», R18 «ссылки», R31 «шеринг» и т. д.) упразднена.

## Приоритеты и сложность

- **P0** — критично и срочно · **P1** — важно для стабильности · **P2** — желательно · **P3** — на будущее.
- Сложность: **S** (часы) · **M** (дни) · **L** (недели) · **XL** (месяцы).

## Содержание

- [Roadmap](#roadmap)
- [Детализация этапов](#детализация-этапов)
- [Рекомендуемый порядок работы](#рекомендуемый-порядок-работы)
- [Быстрые улучшения](#быстрые-улучшения-один-рабочий-день)
- [План на ближайшие 7 дней](#план-на-ближайшие-7-дней)
- [Итог](#итог)

---

## Roadmap

| ID | P | Задача | Обоснование (что решает) | Файлы/директории | Зависит от | Сложность |
|----|-----|--------|--------------------------|------------------|-----------|-----------|
| R01 | P0 | Окружение: docker-compose (postgres15, **test-postgres :5433**, minio + minio-init, mailpit, nginx+php-fpm c pdo_pgsql, frontend), `.env.example` (root+backend), `/healthz` | Быстрый старт из доков мёртв; нет БД/почты/S3 | `docker-compose.yml`, `.env.example`, `apps/backend/.env.example`, `public/index.php` | — | M |
| R02 | P0 | Починить lefthook: путь `vendor/bin/php-cs-fixer` | Хук падает при стейдже `.php` | `lefthook.yml` | — | S |
| R03 | P0 | PHP-скелет: front controller, fast-route, DI (PSR-11), middleware (CORS-whitelist, JSON, error-handler), Config из env, Validator, Models/DTO, `bin/serve` | Заменяет `echo`; основа всех API | `public/index.php`, `src/{Kernel,Config,Http,Middleware,Support,Models}`, `bin/serve`, composer.json | R01 | L |
| R04 | P0 | Frontend-каркас: чистка create-vue, Vue Router+guards (`requires-auth`, `guest-only`), Pinia, http-клиент (Bearer, single-flight refresh, retry), базовые экраны-заглушки (в т.ч. Landing) | Скелет SPA до API | `apps/frontend/src/{main.ts,App.vue,router,stores,api,views}`, `index.html` | — | L |
| R05 | P0 | Миграционный раннер + auth-схема: `schema_migrations`, users (с `pending_email`), refresh_tokens, auth_tokens | Нет слоя схемы; доки обещают `php bin/migrate` | `apps/backend/bin/migrate`, `migrations/0001_*.sql` | R01, R03 | M |
| R06 | P0 | CI-1: lint/typecheck/format + php-cs-fixer (верный путь) | Никакой защиты изменений; root `check` не покрывает PHP | `.github/workflows/ci.yml` | R02 | S-M |
| R07 | P0 | Синхронизация доков: единая модель доступа, camelCase/snake_case, POST-верификация, единая позиция по тестам, README-квикстарт, валидация openapi.yaml | Устраняет противоречия контракта | `docs/*`, корневой `README.md` | — | S |
| R08 | P1 | Миграции домена: canvases, notes, tags, note_tags, direct_links, indirect_links, deleted_notes, canvas_access, note_access, invitations, attachments + индексы/CHECK/FK (см. data-model.md) | ER-модель отсутствует в БД | `migrations/0002_*.sql` | R05 | M |
| R09 | P1 | Архитектура: интерфейсы репозиториев, DI-контейнер (`ContainerFactory`), тонкие контроллеры, `Database::transaction()/ping()`, снейк↔кэмел в Models | Тестируемость, разделение слоёв | `src/Repositories/*Interface`, `src/Support/{Container,ContainerFactory,Database}`, `src/Models` | R03 | M |
| R10 | P1 | Auth-срез 1: register, verify-email (регистрация и смена email), resend, login (403 до верификации), access-JWT, `/auth/me`, `PATCH /profile` (+`pendingEmail`), PHPMailer | Без аккаунтов нет продукта | `Controllers/{Auth,Profile}`, `Services/{Auth,Jwt,Mail}`, `Repositories/{User,AuthToken}` | R03, R05, R08, R09 | M |
| R11 | P1 | Auth-срез 2: refresh-кука `vistynq_refresh`, hash+ротация+family/reuse-detection, logout, forgot/reset, rate-limit (429) | Долгая сессия безопасно | `Services/{Auth,RefreshToken}`, `Middleware/{RefreshCookie,RateLimit}` | R10 | M |
| R12 | P1 | Права + канвасы: `AccessService` (owner/author/editor/viewer, `visibility`, public-канвас, 404/403-политика), Canvas CRUD (rename/delete — только owner), `GET /canvases` (включая выданные), `GraphService` (граф одним запросом без N+1, фильтрация недоступного, состояния `removed`) | Единая точка прав; загрузка канваса | `Services/{Access,Canvas,Graph}`, `Repositories/{Canvas,Note}`, `Controllers/Canvas` | R08, R09, R10 | L |
| R13 | P1 | Заметки: create, `from-text`, манифест, body, PATCH позиций, soft-delete/restore/purge+tombstone (+`deleted_target_id`), архив (`section`), поиск (`matchedTag`) | Ядро продукта | `Services/{Note,Archive,Search}`, `Controllers/Note` | R12 | L |
| R14 | P1 | Теги: CRUD + транзакционная синхронизация `note_tags`; назначение тегов editor-ом заметки | Пер-канвасные теги | `Services/TagService`, `Controllers/Tag` | R12 | M |
| R15 | P2 | Ссылки (backend): прямые (create/delete/reverse, `derive`), `LinkParserService` (`[[target\|label]]`, пересоздание `indirect_links`, дедуп), состояния «ссылка удалена» при purge/soft-delete, табличные unit-тесты | Прямые и косвенные связи — ключевая фича | `Services/{Link,LinkParser}`, `Controllers/Link`, `Controllers/Note(body)` | R13 | L |
| R16 | P2 | Доступ и публикация (backend): `access-level`, `visibility`, выдача по email (мгновенная / `pending`), `InvitationService` + резолв при подтверждении email, смена роли/отзыв/отзыв инвайта, письма-уведомления, `/shared/canvases/{slug}` и `/shared/canvases/{slug}/notes/{noteId}` | Шеринг: бэкенд до UI | `Services/Invitation`, `Controllers/{Access,Shared}`, `Repositories/{Access,Invitation}` | R10, R12, R13 | L |
| R17 | P2 | Storage/upload: `StorageInterface` + LocalAdapter, S3/MinIO-адаптер на aws-sdk (установка пакета здесь), валидация finfo/mime, лимиты image 5 МБ / audio 25 МБ, uuid-ключи без имени файла, presigned TTL, `attachments` API, аватар, удаление объектов после коммита | Безопасные загрузки до S3 | `Services/Storage/`, `Controllers/Attachment`, `apps/backend/storage/` (gitignored), composer.json | R08, R13 | M |
| R18 | P2 | XSS: санитизация markdown (рендер `/shared`), экранирование чужих `[[...]]`, DOMPurify на Tiptap | Чужой контент безопасен | `Services/MarkdownRenderer`, `frontend/editor/` | R15, R16 | M |
| R19 | P2 | Пагинация `/canvases`, search, archive (`page`/`perPage`, `{items,page,perPage,total}`) + синхронизация openapi | Большие канвасы | `Services/*`, `docs/openapi.yaml` | R12, R13 | S-M |
| R20 | P2 | Логирование: request-id, structured single-line, без токенов/паролей | Диагностика | `Middleware/RequestId`, `Support/Logger` | R03 | S |
| R21 | P1 | Backend-тесты 1: PHPUnit unit (JwtService, Validator, ErrorHandler, AccessService-базис) + интеграция register→verify→login→refresh(reuse)→logout на test-postgres | Критичная логика без тестов | `apps/backend/tests/*`, composer `--dev phpunit` | R09, R11 | M |
| R22 | P1 | Backend-тесты 2: матрица прав, приватные заметки, graph-фильтрация, ссылки (парсер, purge, soft-delete), мгновенный grant/pending, tombstone/410 | Самые рискованные сценарии | `apps/backend/tests/{Unit,Integration}/*` | R15, R16, R21 | L |
| R23 | P1 | Frontend базовый: auth-экраны, **Landing**, Dashboard (список с ролями/бейджами, постранично), Profile (имя/email с `pendingEmail`), toasts, CanvasView read-only (загрузка `/graph`) | Пользовательские сценарии v1 | `views/{Landing,Dashboard,CanvasView,Profile}.vue`, `views/auth/*`, `components/ui/Toast` | R04, R11, R12, R19 | L |
| R24 | P2 | Frontend-структура: composables (useDebounce/useContextMenu/useDropFiles/useGraph), типизированные api-модули по openapi | Меньше дублирования | `apps/frontend/src/composables`, `src/api/*` | R04, R23 | M |
| R25 | P2 | Vue Flow 1: скелет канваса, рендер нод/рёбер из `/graph`, зум/пан | Интерактив продукта | `components/canvas/CanvasBoard.vue`, `NoteNode.vue` | R23, R12 | M |
| R26 | P2 | Vue Flow 2: drag/resize + PATCH позиций с дебаунсом 500 мс | Персистентность компоновки | `composables/useGraph`, `NoteNode.vue` | R25 | M |
| R27 | P2 | Vue Flow 3: контекстное меню (по правам), фильтр важности, меню/подсветка тегов, поиск-центрирование | Раскладка UX-спеки | `components/canvas/{ContextMenu,TagMenu,ImportanceFilter}` | R26, R14 | L |
| R28 | P2 | Редактор заметки: модалка, ManifestForm (видимость, теги), Tiptap-редактор, сохранение (PATCH + PUT body), read-only для viewer | Без редактора нет контента | `components/note/{NoteEditorModal,ManifestForm}`, `components/editor/*` | R25, R13, R14 | L |
| R29 | P2 | Архив-панель UI: неактуальные/удалённые (постранично), restore/purge с подтверждением | Жизненный цикл заметки в UI | `components/canvas/ArchivePanel.vue` | R27, R13 | M |
| R30 | P2 | Ссылки UI: режим связывания, derive, ПКМ по связи (развернуть/удалить, Del), edge-стили (в т.ч. «ссылка удалена»), автодополнение `[[` | Визуальный граф | `components/canvas/LinkLayer.vue`, `components/editor/WikiLink.*` | R27, R15, R28 | L |
| R31 | P2 | Файлы UI: drop (`.md/.txt` → `from-text`), вставка картинки/аудио, плеер, аватар | Загрузки по UX-спеке | `components/note/*`, `views/Profile.vue` | R17, R28 | L |
| R32 | P2 | Доступ UI: диалог «Доступ» (канвас и заметка), выдача по email (granted/pending), переключатели public/private, shared-страницы (`SharedCanvas`, `SharedNote`) | Шеринг | `components/share/*`, `views/{SharedCanvas,SharedNote}.vue` | R16, R18, R25 | L |
| R33 | P2 | Vitest + @vue/test-utils: парсер `[[...]]`, stores, guard-логика | Ключевая клиентская логика | `apps/frontend/src/**/__tests__`, `vitest.config.*` | R24, R30 | S-M |
| R34 | P3 | i18n RU/EN (vue-i18n), горячие клавиши, полировка состояний | Локализация, DX | `locales/*`, `views`, `styles/tokens.css` | R29, R30, R31, R32 | M |
| R35 | P1 | CI-2: phpunit, vitest, playwright (test-postgres), gitleaks secret-scan, openapi-check + e2e `register→verify→canvas→note→link→public` | Защита продакшн-путей | `.github/workflows/ci.yml`, `apps/frontend/e2e/*` | R22, R32, R33 | L |
| R36 | P3 | Продакшн: Dockerfile backend, статика SPA, healthcheck, тег-релизы, nginx limit_req+CORS; прод-параметры (домен, S3-region, SMTP) | Деплой вне dev-машин | `apps/backend/Dockerfile`, `docker/nginx.conf`, CD-workflow | R01, R35 | M |
| R37 | P3 | Документация разработчика: CONTRIBUTING, env-справочник, ADR, Makefile, turbo `test` | DX и онбординг | `CONTRIBUTING.md`, `docs/ADR`, `Makefile`, `package.json` | R06 | S |

> Примечания:
> - `aws-sdk-php` сознательно отложен до R17 — на скелете ставим `nikic/fast-route`, `firebase/php-jwt`; `phpmailer` — в R10 (письма верификации).
> - Хеш паролей — `password_hash(PASSWORD_ARGON2ID)`; при отсутствии ext-sodium — падение с явной ошибкой на старте.
> - Удаление канваса — hard-delete с каскадом (без восстановления), подтверждение в UI.
> - Порядок «R09 → R10» намеренный: auth уже строится на интерфейсах репозиториев и DI.

---

## Детализация этапов

### Этап 1 — Основа (P0)

**R01 · Окружение.** `docker-compose.yml`: postgres:15, **test-postgres** (порт 5433, отдельная БД `vistynq_test`), minio + minio-init (бакет `vistynq-media`), mailpit, backend (nginx + php-fpm с pdo_pgsql и sodium, mount `apps/backend`), frontend (vite dev server). `apps/backend/.env.example` — все переменные из env-таблицы `architecture.md`. `/healthz` → `{"status":"ok"}`.
**Критерий:** `docker compose up -d` поднимает всё; `curl :8000/api/v1/healthz` → ok. M.

**R02 · Lefthook.** Строка → `php -d memory_limit=512M apps/backend/vendor/bin/php-cs-fixer fix {staged_files} --config=apps/backend/.php-cs-fixer.dist.php`.
**Критерий:** стейджинг `.php` не роняет хук. S.

**R03 · PHP-скелет.** Порядок: Config → error handler → роутер → middleware → healthz → `bin/serve`.
Runtime deps: `nikic/fast-route`, `firebase/php-jwt`. DI — лёгкий PSR-11 (без магии).
**Критерии:** 404/405 → JSON `{error}`, CORS preflight ок, prod-500 без стека, валидация JSON-тел. L.

**R04 · Frontend-каркас.** Удалить scaffold-компоненты; `index.html` (lang=ru, title «Vistynq»). Маршруты из `ui-ux.md` (заглушки), guards `requires-auth` / `guest-only`.
http-клиент: перехват 401 → single-flight refresh (Promise-мьютекс) → повтор запроса → повторный 401 = logout.
**Критерий:** запрос с Bearer проходит, refresh не дублируется параллельными вызовами. L.

**R05 · Раннер миграций.** PHP CLI: `schema_migrations(version, applied_at)`, применение новых в транзакции, `--status`. Миграция 0001: users (в т.ч. `pending_email`), refresh_tokens, auth_tokens + индексы из data-model.md. M.

**R06 · CI-1.** Actions: setup-node 22 + pnpm, composer install, `turbo check`, php-cs-fixer check.
**Критерий:** зелёный на пустом PR. S-M.

**R07 · Синхронизация доков.** Выполнено в рамках ревью 2026-11-13: единая модель доступа (owner=author, одна роль editor, `private/public` у канваса и заметки, мгновенная выдача), camelCase в API / snake_case в БД, POST-верификация и смена email через `pendingEmail`, согласованные `data-model` ↔ `api` ↔ `openapi`, лендинг, обновлённые README/ROADMAP/TODO.

### Этап 2 — Backend (P1–P2)

**R08 · Домен-миграции.** Все таблицы заметок/доступов/ссылок/вложений + CHECK/UNIQUE/FK-каскады + partial-индексы. Важно: `indirect_links.to_note_id` — `ON DELETE SET NULL` + `deleted_target_id`; `attachments.note_id` — `CASCADE`; `invitations` с `canvas_id`/`note_id` и CHECK «ровно одно». M.

**R09 · Архитектура.** Интерфейсы репозиториев (моки для тестов), `ContainerFactory`, тонкие контроллеры, сервисы не трогают PDO/Request. Выполняется **до** auth. M.

**R10 · Auth-срез 1.** Регистрация, верификация email (auth_tokens + PHPMailer → Mailpit), resend, login (argon2id, 403 `EMAIL_NOT_VERIFIED`), access-JWT (HS256, TTL 900с), `/auth/me`, `PATCH /profile` с `pendingEmail` и подтверждением нового адреса (`purpose=email_change`). При подтверждении регистрации — hook для `InvitationService` (подключается в R16). M.

**R11 · Auth-срез 2.** Refresh-кука `vistynq_refresh` (HttpOnly, Secure по env, SameSite=Lax, Path=/api/v1/auth/refresh), SHA-256 хэш в БД, ротация + family_id, reuse → отзыв всей семьи, logout, forgot/reset (отзыв всех refresh), rate-limit на `/auth/*`.
**Критерий:** повторное использование старого refresh отзывает семейство (401); rate-limit даёт 429. M.

**R12 · Права + канвасы.** `AccessService` — единственная точка: owner/author/editor/viewer, `max(canvas_access, note_access)` для публичных заметок и только `note_access` для приватных, недоступное → 404, недостаточно прав → 403. Graph одной выборкой с SQL-фильтрацией + фильтр доступности; правила `removed`-рёбер из `api.md`; `GET /canvases` включает канвасы с выданным доступом (в т.ч. только к заметкам, `myRole: none`); rename/delete — только owner; hard-delete каскадом (+ объекты S3 после коммита, когда будет R17).
**Тест:** viewer не видит чужую приватную ноду и ребро к ней. L.

**R13 · Заметки.** Create, **`from-text`** (multipart `.md/.txt` ≤1 МБ), манифест (в т.ч. `isRelevant` туда-обратно), body, PATCH позиций, soft-delete → restore → purge (tombstone в `deleted_notes`, обновление `indirect_links.deleted_target_id`, удаление прямых ссылок каскадом), архив `?section=`, поиск с `matchedTag`. Сначала soft-жизненный цикл, purge — отдельным шагом. L.

**R14 · Теги.** CRUD + транзакционная перезапись `note_tags`; editor заметки может назначать существующие теги. M.

**R15 · Ссылки backend.** Прямые: `POST/DELETE /links/direct`, `reverse`, `derive`; проверки одного канваса, дубля, `from≠to`. `LinkParserService` — изолированный regex-парсер `[[target|label]]`, транзакционное пересоздание `indirect_links` при `PUT /notes/{id}/body`, состояния строки (живая / не найдена / стёрта). Табличные unit-кейсы. L.

**R16 · Доступ и публикация backend.** `PUT access-level` (новый slug / обнуление), `PUT visibility`, `access/invite` (аккаунт есть → мгновенный grant + письмо; нет → `pending`), смена роли, отзыв, отзыв инвайта, резолв `pending` при подтверждении email, `/shared/canvases/{slug}` и `/shared/canvases/{slug}/notes/{noteId}` (только публичные заметки, без `removed`-рёбер). Права выдачи — только owner/author. L.

**R17 · Storage/upload.** `StorageInterface` + LocalAdapter + S3-адаптер; ключ без имени файла; mime по содержимому (finfo); лимиты image 5 МБ / audio 25 МБ; presigned TTL 1 ч; `attachments` API; аватар; `StorageService` удаляет объекты после коммита (ошибки — в лог). M.

**R18 · XSS.** Санитизация markdown на `/shared` (`bodyHtml`), экранирование чужих `[[...]]`, DOMPurify в Tiptap. M.

**R19 · Пагинация.** `page`/`perPage` (по умолчанию 1/50, максимум 200) для `/canvases`, search, archive; `{items,page,perPage,total}`; openapi синхронно. S-M.

**R20 · Логирование.** `RequestId`, Logger (одна строка, json-контекст), без токенов/паролей. S.

### Этап 3 — Backend-тесты (P1)

**R21 · Тесты 1.** PHPUnit: JwtService, Validator, ErrorHandler; интеграция register→verify→login→refresh→logout на test-postgres (`AuthFlowTest`). M.

**R22 · Тесты 2.** Матрица прав (owner/editor канваса/editor заметки/viewer/аноним), приватные заметки, graph-фильтрация, 404 vs 403, парсер ссылок, purge/tombstone/410, мгновенная выдача и pending→accepted при подтверждении email. L.

Стратегия: unit (R15, R21, R33) → интеграция (R21, R22) → e2e (R35).

### Этап 4 — Frontend (P1–P3)

Порядок: R23 → R24 → R25 → R26 → R27 → R28 → R29 → R30 → R31 → R32 → R33 → R34.
Бэкенд-части (R12–R17) выполняются раньше соответствующих UI-задач.

**R23.** Auth-экраны (логин/регистрация/верификация/сброс, сообщение «письмо не пришло»), Landing, Dashboard (бейджи роли/публичный, постранично), CanvasView read-only, Profile (имя/email с `pendingEmail`), toasts. Черновик e2e-сценария для R35. L.
**R24.** Composables и типизированные api-модули. M.
**R25–R27.** Vue Flow: скелет и зум/пан → drag/resize + debounce 500 мс → контекстное меню по правам, фильтры, теги, поиск. M / M / L.
**R28.** Редактор заметки (манифест, видимость, Tiptap, сохранение). L.
**R29.** Панель архива. M.
**R30.** Связывание, derive, ПКМ по связи, «ссылка удалена», автодополнение `[[`. L.
**R31.** Drop/загрузка файлов, плеер, аватар. L.
**R32.** Диалог «Доступ», shared-страницы. L.
**R33.** Vitest. S-M.
**R34.** i18n, горячие клавиши, полировка. M.

### Этап 5 — Качество (P1)

**R35 · CI-2.** phpunit, vitest, playwright-service (test-postgres), gitleaks, openapi-check; e2e `register→verify→canvas→note→link→public`. L.

### Этап 6 — Продакшн (P3)

**R36.** Dockerfile backend, статика SPA, nginx `limit_req`+CORS, healthcheck, тег-релизы, CD. M.

### Этап 7 — Документация разработчика (P3)

**R37.** CONTRIBUTING, env-справочник, ADR, Makefile, turbo `test`. Правило: обновление `openapi.yaml` — в том же PR, что и изменение API. S.

### Сквозные темы

- **Безопасность:** R03 (ошибки без internals), R10/R11 (argon2id, JWT-HS256, ротация, reuse, cookie-флаги, rate-limit), R12 (404-политика), R17 (finfo, uuid-ключи, presigned TTL, лимиты), R18 (XSS), R20 (логи без секретов), R35 (secret-scan).
- **Производительность:** граф без N+1 (R12), индексы (R05/R08), пагинация (R19), debounce PATCH позиций (R26), кэш presigned URL на клиенте (R31), rate-limit nginx (R36).
- Вне v1: Postgres FTS, realtime, история версий, рисование на канвасе, медиа на канвасе, тёмная тема, фоновая чистка осиротевших объектов S3.

---

## Рекомендуемый порядок работы

**Критический путь:**

```
R01 → R03 → R05 → R08 → R09 → R10 → R11 → R12 → R13 → R15 → R16 → R22
 ↑      ↑                                      ↓
R02    R04 (параллельно)                      R14, R17 (параллельно с R15/R16)
R06/R07 (параллельны и независимы)
```

Затем:
- backend: `R14 ∥ R15 → R16 → R17 → R18 → R19 → R20`, тесты `R21` (после R11) и `R22` (после R15/R16);
- frontend: `R23 → R24 → R25 → R26 → R27 → R28 → R29 → R30 → R31 → R32 → R33 → R34`;
- финал: `R35 → R36`, `R37` — в любой момент после R06.

Правила: не начинать слой, пока не зафиксирован интерфейс предыдущего; UI-задача стартует, когда готов соответствующий backend; контракт — `docs/openapi.yaml`, он же источник правды для R04/R24.

---

## Быстрые улучшения (один рабочий день)

1. **R02** — починить lefthook php-путь.
2. **R07** — документация уже синхронизирована (см. TODO).
3. **R04-mini** — `index.html` (lang/title), удалить HelloWorld/TheWelcome/icons.
4. **R03-mini** — `public/index.php`: JSON-ответ с Content-Type вместо голого `echo`.
5. **R06-core** — минимальный CI: eslint + vue-tsc + php-cs-fixer + prettier-check.
6. **R01-mini** — `.env.example` (root + backend) по env-таблице из docs.
7. **R37-mini** — обновить `apps/frontend/README.md` (npm → pnpm) и корневой README-квикстарт.
8. **R37-mini** — подключить PHP-lint к корневому `turbo check`.

---

## План на ближайшие 7 дней

| День | Задачи | Результат |
|---|---|---|
| 1 | R01, R02 (R07 готово) | Docker-окружение поднято; хуки живые; контракт консистентен |
| 2 | R03 (Config, error-handler, роутер, healthz) | Валидный JSON API-каркас |
| 3 | R03 (DI, middleware, bin/serve) + R06 | CI зелёный; каркас продуман |
| 4 | R05 | Раннер миграций + auth-таблицы работают |
| 5 | R08 | Полная схема домена, индексы/CHECK |
| 6 | R04 | SPA с роутером, pinia, http-клиентом и guards |
| 7 | R09 (интерфейсы, DI) и старт R10 | Основа для auth; первые репозитории |

---

## Итог

**5 важнейших задач:** R01 (окружение/запуск), R03 (PHP-скелет), R05+R08 (миграции и схема), R04 (frontend-каркас), R12 (права и граф — сердце продукта).

**Если их не выполнить:** проект остаётся «доки + заглушки»; быстрый старт не работает; PHP-хуки падают; контракт API расплывается; разрыв «доки ↔ код» растёт, и первые критические решения (миграции, права, auth) придётся переделывать.

**После прохождения roadmap:** рабочий продукт v1 — канвасы-графы с wiki-связями и тегами, аккаунты и доступы (приватные/публичные канвасы и заметки, мгновенная выдача по email), файлы на S3/MinIO, лендинг, i18n, закрытый unit-интеграционно-e2e тестами, зелёный CI, воспроизводимое docker-окружение, синхронизированная документация и OpenAPI.
