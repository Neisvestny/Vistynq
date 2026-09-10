# Vistynq — Roadmap развития продукта

> Версия: 2026-09-12 · Статус: согласовано с владельцем
>
> Источник правды по функциональности — `docs/`. Окружение (docker-compose) и CI живут в этом
> репозитории. Решения, зафиксированные на ревью: aws-sdk подключается на этапе файлов (R22);
> пароли — argon2id (требуется ext-sodium); канвас удаляется hard-delete с каскадом;
> прод-параметры — заглушки до R34.

## Приоритеты и сложность

- **P0** — критично и срочно · **P1** — важно для стабильности · **P2** — желательно · **P3** — на будущее.
- Сложность: **S** (часы) · **M** (дни) · **L** (недели) · **XL** (месяцы).

## Содержание

- [Roadmap](#roadmap)
- [Детализация этапов](#детализация-этапов)
- [Рекомендуемый порядок работы](#рекомендуемый-порядок-работы)
- [Быстрые улучшения](#быстрые-улучшения)
- [План на ближайшие 7 дней](#план-на-ближайшие-7-дней)
- [Итог](#итог)

---

## Roadmap

| ID | P | Задача | Обоснование (что решает) | Файлы/директории | Зависит от | Сложность |
|----|-----|--------|--------------------------|------------------|-----------|-----------|
| R01 | P0 | Окружение: docker-compose (postgres15, minio, mailpit, nginx+php-fpm c pdo_pgsql), `.env.example` (root+backend), `/healthz` | Быстрый старт из доков мёртв; нет БД/почты/S3 | `docker-compose.yml`, `.env.example`, `apps/backend/.env.example`, `public/index.php` | — | M |
| R02 | P0 | Починить lefthook: путь `vendor/bin/php-cs-fixer` | Хук падает при стейдже `.php` | `lefthook.yml` | — | S |
| R03 | P0 | PHP-скелет: front controller, fast-route, DI (PSR-11), middleware (CORS-whitelist, JSON, error-handler), Config из env, Validator, Models/DTO | Заменяет `echo`; основа всех API | `public/index.php`, `src/{Kernel,Config,Http,Middleware,Support,Models}`, composer.json | R01 | L |
| R04 | P0 | Frontend-каркас: чистка create-vue, Vue Router+guards, Pinia, http-клиент (Bearer, single-flight refresh, retry), базовые экраны-заглушки | Скелет SPA до API | `apps/frontend/src/{main.ts,App.vue,router,stores,api,views}`, `index.html` | — | L |
| R05 | P0 | Миграционный раннер + auth-схема: `schema_migrations`, users, refresh_tokens, auth_tokens | Нет слоя схемы; доки обещают `php bin/migrate` | `apps/backend/bin/migrate`, `migrations/0001_*.sql` | R01 | M |
| R06 | P0 | CI-1: lint/typecheck/format + php-cs-fixer (верный путь) | Никакой защиты изменений; root `check` не покрывает PHP | `.github/workflows/ci.yml` | R02 | S-M |
| R07 | P0 | Синхронизация доков: POST `/auth/verify-email`, единая позиция по тестам, README-квикстарт, валидация openapi.yaml | Устраняет противоречия контракта | `docs/architecture.md`, `docs/api.md`, `docs/README.md`, `docs/openapi.yaml`, корневой `README.md` | — | S |
| R08 | P1 | Миграции домена: canvas, notes, tags, note_tags, links, tombstones, access, invitations, attachments + индексы/CHECK/FK (колонки ресурсов — `canvas_id`/`note_id`, см. data-model.md) | ER-модель отсутствует в БД | `migrations/0002_*.sql` | R05 | M |
| R09 | P1 | Auth-срез 1: register, verify-email, resend, login, access-JWT, `/auth/me` | Без аккаунтов нет продукта | `src/Controllers/Auth`, `Services/{Auth,Jwt,Mail}`, `Repositories/{User,AuthToken}` | R03, R05, R08 | M |
| R10 | P1 | Auth-срез 2: refresh-кука, hash+ротация+family/reuse-detection, logout, forgot/reset, rate-limit | Долгая сессия безопасно | `Services/{Auth,RefreshToken}`, `Middleware/RateLimit` | R09 | M |
| R11 | P1 | Права + канвасы: AccessService, Canvas CRUD, Graph одним запросом без N+1, фильтрация недоступного; удаление канваса — hard-delete с каскадом | Единая точка прав; загрузка канваса | `Services/AccessService`, `Repositories/{Canvas,Note}`, `Controllers/Canvas` | R08, R17 | L |
| R12 | P1 | Заметки: манифест, body, PATCH позиций, soft-delete/restore/purge+tombstone, архив, поиск | Ядро продукта | `Services/{Note,Archive,Search}`, `Controllers/Note` | R11 | L |
| R13 | P1 | Теги: CRUD + транзакционная синхронизация `note_tags` | Пер-канвасные теги | `Services/TagService`, `Controllers/Tag` | R11 | M |
| R14 | P1 | Frontend: auth-экраны, Dashboard, CanvasView (read-рендер графа), Profile | Пользовательские сценарии v1 | `views/auth/*`, `views/{Dashboard,CanvasView,Profile}.vue` | R04, R09, R11 | L |
| R15 | P1 | Unit-тесты ядра + интеграция auth (PHPUnit, test-postgres) | Критичная логика без тестов | `apps/backend/tests/*`, composer `--dev phpunit` | R10, R17 | M |
| R16 | P1 | CI-2: phpunit/vitest/playwright, secret-scan, openapi-check + e2e-сценарий | Защита продакшн-путей | `.github/workflows/ci.yml` | R15, R14 | L |
| R17 | P1 | Архитектура: интерфейсы репозиториев, DI, тонкие контроллеры | Тестируемость, разделение слоёв | `src/Repositories/*Interface`, `src/Support/Container` | R03 | M |
| R18 | P2 | LinkParserService: разбор `[[target|label]]`, пересоздание `indirect_links`, табличные unit-тесты | Косвенные связи — ключевая фича | `Services/LinkParserService`, `Controllers/Note(body)` | R12 | M |
| R20 | P2 | Frontend-структура: composables (useDebounce/useContextMenu/useDropFiles/useGraph), типизированные api-модули | Меньше дублирования | `apps/frontend/src/composables`, `src/api/*` | R04, R14 | M |
| R21 | P2 | Vitest + @vue/test-utils: парсер `[[...]]`, stores, guard-логика | Ключевая клиентская логика | `apps/frontend/src/**/__tests__`, `vitest.config.*` | R20 | S-M |
| R22 | P2 | Storage/upload: `StorageInterface`+LocalAdapter, S3/MinIO-адаптер на aws-sdk (установка пакета здесь), валидация finfo/mime, лимиты, uuid-ключи, presigned TTL | Безопасные загрузки до S3 | `Services/Storage/`, `apps/backend/storage/` (gitignored), composer.json | R08 | M |
| R23 | P2 | XSS: санитизация markdown (/shared рендер), экранирование чужих `[[...]]`, DOMPurify на Tiptap | Чужой контент безопасен | `Services/MarkdownRenderer`, `frontend/editor/` | R18 | M |
| R24 | P2 | Пагинация search/archive/invitations/attachments + синхронизация openapi | Большие канвасы | `Services/*`, `docs/openapi.yaml` | R12 | S-M |
| R25 | P2 | Vue Flow 1: скелет канваса, рендер нод/рёбер из `/graph`, зум/пан | Интерактив продукта | `components/canvas/CanvasBoard.vue`, `NoteNode.vue` | R14, R11 | M |
| R26 | P2 | Vue Flow 2: drag/resize + PATCH позиций с дебаунсом 500 мс | Персистентность компоновки | `composables/useGraph`, `NoteNode.vue` | R25 | M |
| R27 | P2 | Vue Flow 3: контекст-меню, фильтр важности, подсветка тегов, поиск-центрирование | Раскладка UX-спеки | `components/canvas/{ContextMenu,TagMenu,ImportanceFilter}` | R26 | L |
| R28 | P2 | Архив-панель UI: неактуальные/удалённые, restore/purge с подтверждением | Жизненный цикл заметки в UI | `components/canvas/ArchivePanel.vue` | R27, R12 | M |
| R29 | P2 | Ссылки UI: режим связывания, derive, разворот/удаление, edge-стили, Tiptap + автодополнение `[[` | Визуальный граф | `components/canvas/LinkLayer.vue`, `editor/WikiLink.vue` | R27, R18 | L |
| R30 | P2 | Файлы UI: drop (md/txt → note), вставка картинки/аудио, плеер, from-text, аватар | Загрузки по UX-спеке | `components/note/*`, `views/Profile.vue` | R22, R29 | L |
| R31 | P2 | Доступы/инвайты UI: диалог, email-инвайты, публичная ссылка, shared-страницы, приватность графа | Шеринг | `components/share/*`, `views/{SharedCanvas,SharedNote}.vue` | R11, R25 | L |
| R32 | P2 | Логирование: request-id, structured single-line, без токенов/паролей | Диагностика | `Middleware/RequestId`, `Support/Logger` | R03 | S |
| R33 | P3 | i18n RU/EN (vue-i18n), полировка состояний, горячие клавиши | Локализация, DX | `locales/*`, `views`, `styles/tokens.css` | R28 | M |
| R34 | P3 | Продакшн: Dockerfile backend, статика SPA, healthcheck, тег-релизы, nginx limit_req+CORS; прод-параметры (домен, S3-region, SMTP) вносятся сюда | Деплой вне dev-машин | `apps/backend/Dockerfile`, `docker/nginx.conf`, CD-workflow | R01, R31 | M |
| R35 | P3 | Документация разработчика: README, CONTRIBUTING, env-справочник, ADR, Makefile, turbo `test` | DX и онбординг | `README.md`, `CONTRIBUTING.md`, `docs/ADR`, `Makefile`, `package.json` | R06 | S |

> Примечания:
> - R19 (отдельный «Validator») объединён с R03; R15/R16/R17 переставлены так, чтобы тесты покрывали уже формализованные интерфейсы.
> - `aws-sdk-php` сознательно отложен до R22 — на скелете ставим только `nikic/fast-route` и `firebase/php-jwt`, `phpmailer` добавляется к R10.
> - Хеш паролей — `password_hash(PASSWORD_ARGON2ID)`; при отсутствии ext-sodium — падение с явной ошибкой на старте.
> - Удаление канваса — hard-delete с каскадом (без восстановления), подтверждение в UI.

---

## Детализация этапов

### Этап 1 — Критические исправления и блокирующие проблемы (P0)

**R01 · Окружение.** `docker-compose.yml`: postgres:15, minio, mailpit, backend
(php:8.5-fpm-alpine + pdo_pgsql, mount `apps/backend`), frontend (vite dev server с прокси `/api`).
`apps/backend/.env.example` — все переменные из env-таблицы `docs/architecture.md`
(APP_ENV, DB_*, JWT_SECRET, S3_*, SMTP_*, COOKIE_*). `/healthz` в `public/index.php` → `{"status":"ok"}`.
**Критерий:** `docker compose up -d` поднимает всё; `curl :8000/api/v1/healthz` → ok. Сложность M.

**R02 · Lefthook.** Строка → `php -d memory_limit=512M apps/backend/vendor/bin/php-cs-fixer fix {staged_files} --config=apps/backend/.php-cs-fixer.dist.php`.
**Критерий:** стейджинг `.php` не роняет хук. S.

**R03 · PHP-скелет.** Порядок: Config → error handler → роутер → middleware → healthz.
Runtime deps: `nikic/fast-route`, `firebase/php-jwt`. DI — лёгкий PSR-11 (без магии).
**Критерии:** 404/405 → JSON `{error}`, CORS preflight ок, prod-500 без стека, валидация JSON-тел. L.

**R04 · Frontend-каркас.** Удалить scaffold-компоненты; `index.html` (lang=ru, title «Vistynq»).
http-клиент: перехват 401 → single-flight refresh (Promise-мьютекс) → повтор запроса → повторный 401 = logout.
**Критерий:** запрос с Bearer проходит, refresh не дублируется параллельными вызовами. L.

**R05 · Раннер миграций.** PHP CLI: `schema_migrations(version, applied_at)`, применение новых
в транзакции, `--status`. Миграция 0001: users, refresh_tokens, auth_tokens + все индексы из data-model.md. M.

**R06 · CI-1.** Actions: setup-node 22 + pnpm, composer install, `turbo check`, php-cs-fixer check.
**Критерий:** зелёный на пустом PR. S-M.

**R07 · Синхронизация доков.** В `architecture.md` заменить GET-верификацию на POST;
унифицировать позицию по тестам; README-квикстарт → реальные команды; заквотировать openapi-примеры.
S.

### Этап 2 — Стабилизация (P1)

**R08 · Домен-миграции.** Все таблицы заметок/доступов + CHECK/UNIQUE/FK-каскады + partial-индексы.
Колонки ресурсов в `canvas_access`/`note_access` — `canvas_id`/`note_id` (data-model.md). M.

**R09 · Auth-срез 1.** Регистрация, верификация email (auth_tokens + PHPMailer → Mailpit), resend,
login (argon2id), access-JWT (HS256, TTL 900с), `/auth/me`. M.

**R10 · Auth-срез 2.** Refresh-кука (HttpOnly, Secure по env, SameSite=Lax, Path=/api/v1/auth/refresh),
SHA-256 хэш в БД, ротация + family_id, reuse → отзыв всей семьи, logout, forgot/reset (отзыв всех refresh),
rate-limit на `/auth/*`.
**Критерий:** повторное использование старого refresh отзывает семейство (401); rate-limit даёт 429. M.

**R11 · Права + канвасы.** `AccessService` — единственная точка: owner/author/editor/viewer/public_link,
права = max(canvas, note), недоступное → 404 (политика приватности), Graph одной выборкой с SQL-фильтрацией
+ фильтр доступности, hard-delete канваса каскадом. Тест «viewer не видит чужую приватную ноду и ребро к ней». L.

**R12 · Заметки.** Манифест, body, позиции нод (PATCH), soft-delete → restore → purge (tombstone в
`deleted_notes`), архив «неактуальные/удалённые», поиск. Сначала soft-жизненный цикл, purge — отдельным шагом. L.

**R13 · Теги.** CRUD + транзакционная перезапись `note_tags`. M.

**R14 · Frontend базовый.** Экраны auth (логин/регистра/верификация/сброс), Dashboard (список канвасов +
инвайты-заглушка), CanvasView read-only (загрузка `/graph`), Profile (имя/email). Черновик e2e-сценария для R16. L.

**R15 · Unit + интеграция.** PHPUnit: AccessService, JwtService, Validator, ErrorHandler; интеграция
register→login→refresh→logout на test-postgres (сервис из compose). M.

**R16 · CI-2.** В Actions: phpunit, vitest, playwright-service (test-postgres), gitleaks secret-scan,
openapi-check; e2e-сценарий register→canvas→note→link→public-link. L.

**R17 · Архитектура.** Интерфейсы репозиториев (моки для R15), DI-контейнер, тонкие контроллеры,
сервисы не трогают PDO/Request. **Выполняется до R15.** M.

### Этап 3 — Улучшение архитектуры

**R17** (см. выше) — интерфейсы + DI.
**R18 · LinkParserService** — изолированный regex-парсер `[[target|label]]`, транзакционное пересоздание
`indirect_links` при `PUT /notes/{id}/body`, табличные unit-кейсы. M.
**R20 · Frontend-структура** — composables (useDebounce, useContextMenu, useDropFiles, useGraph)
и типизированные api-модули. M.

### Этап 4 — Тестирование и качество

Стратегия: unit (R15, R18, R21) → интеграция (R15) → e2e (R16).
Критичные непокрытые сейчас сценарии: матрица прав, refresh-reuse, purge/tombstone, graph-фильтрация,
парсинг ссылок, политика 404.

### Этап 5 — Безопасность

Встроено в реализацию: R03 (обработчик ошибок не раскрывает internals), R09/R10 (argon2id, JWT-HS256,
ротация, reuse-детекция, cookie-флаги, rate-limit), R22 (finfo по содержимому, uuid-ключи, presigned TTL,
лимиты image 5МБ/audio 25МБ/text 1МБ), R23 (санитизация markdown и XSS), R11 (404-приватность),
R32 (логирование без секретов), R16 (secret-scan в CI).

### Этап 6 — Производительность

Граф одной выборкой без N+1 (R11), индексы в миграциях (R05/R08 — сразу), пагинация (R24),
дебаунс PATCH позиций (R26), presigned-cache (R30 — не регенерировать URL на каждый рендер),
rate-limit на уровне nginx в проде (R34). Postgres FTS, realtime, история версий — вне v1.

### Этап 7 — Новая функциональность

Порядок: R25 → R26 → R27 → R28 (канвас), затем R29 (связи + автодополнение), R30 (файлы),
R31 (доступы/shared), R33 (i18n/полировка/горячие клавиши).
Бэкенд-часть парсера ссылок (R18) выполняется раньше UI-части (R29).

### Этап 8 — Документация, CI/CD и поддержка

R35 (doc-dev + Makefile + turbo `test`), R34 (продакшн/CD), R16 (полный CI).
Правило: обновление `openapi.yaml` — в том же PR, что и изменение API (иначе дрейф контракта).

---

## Рекомендуемый порядок работы

**Критический путь:**

```
R01 → R03 → R05 → R08 → R09 → R10 → R11 → R12 → R13
  ↑      ↑      ↑
R02   R04   R06/R07  (параллельны и независимы)
```

Затем: `R17 → R15 → R14 → R16`, далее параллельно пары `R18+R23`, `R22+R30`, `R20+R21`,
последовательно канвас `R25→R26→R27→R28→R29`, `R31` после `R11/R25`, финал `R24/R32/R33 → R34/R35`.

Правило: не начинать слой, пока не зафиксирован интерфейс предыдущего; контракт — `docs/openapi.yaml`,
он же источник правды для R04/R20.

---

## Быстрые улучшения (один рабочий день)

1. **R02** — починить lefthook php-путь.
2. **R07-core** — зафиксировать POST-верификацию, обновить `architecture.md`.
3. **R04-mini** — `index.html` (lang/title), удалить HelloWorld/TheWelcome/icons.
4. **R03-mini** — `public/index.php`: JSON-ответ с Content-Type вместо голого `echo`.
5. **R06-core** — минимальный CI: eslint + vue-tsc + php-cs-fixer + prettier-check.
6. **R01-mini** — `.env.example` (root + backend) по env-таблице из docs.
7. **R35-mini** — обновить `apps/frontend/README.md` (npm → pnpm) и корневой README-квикстарт.
8. **R35-mini** — подключить PHP-lint к корневому `turbo check`.

---

## План на ближайшие 7 дней

| День | Задачи | Результат |
|---|---|---|
| 1 | R01, R02, R07 | Docker-окружение поднято; хуки живые; контракт консистентен |
| 2 | R03 (Config, error-handler, роутер) | Валидный JSON API-каркас + healthz |
| 3 | R03 (DI, middleware) + R06 | CI зелёный; каркас продуман |
| 4 | R05 | Раннер миграций + auth-таблицы работают |
| 5 | R08 | Полная схема домена, индексы/CHECK |
| 6 | R04 | SPA с роутером, pinia, http-клиентом и guards |
| 7 | R09 (первый срез auth) + R15 (JwtService/Validator) | Регистрация и логин работают; первые unit-тесты |

---

## Итог

**5 важнейших задач:** R01 (окружение/запуск), R03 (PHP-скелет), R05+R08 (миграции и схема),
R04 (frontend-каркас), R06 (CI).

**Если их не выполнить:** проект остаётся «доки + заглушки»; быстрый старт не работает; PHP-хуки падают;
контракт API расплывается; коммиты без подстраховки; разрыв «доки ↔ код» растёт, и первые
критические решения (миграции, права, auth) придётся переделывать.

**После прохождения roadmap:** рабочий продукт v1 — канвасы-графы с wiki-связями и тегами, аккаунты
и доступы, файлы на S3/MinIO, i18n, закрытый unit-интеграционно-e2e тестами, зелёный CI,
воспроизводимое docker-окружение, синхронизированная документация и OpenAPI.