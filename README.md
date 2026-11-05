# Vistynq

Monorepo (Turborepo + pnpm workspaces) с Vue.js frontend и PHP backend.

## Структура

```
/
├── apps/
│   ├── frontend/   # Vue 3 + Vite + TypeScript (create-vue)
│   └── backend/    # PHP 8.5 + nginx+php-fpm в docker compose (dev)
├── docs/           # документация: архитектура, модель данных, API, UI/UX, гайд
├── package.json
├── pnpm-workspace.yaml
├── turbo.json
├── .gitignore
└── README.md
```

## Команда

3 курс · группа ПИ-241

| Участник                    | Роль |
| --------------------------- | ---- |
| Жилин Константин Алексеевич | —    |
| Каща Артём Дмитриевич       | —    |
| Кишкунов Руслан Андреевич   | —    |

## Требования

- Docker (Compose v2+)
- Node.js (LTS) и pnpm — для запуска приложения на хосте (`turbo dev` поднимает и backend, и frontend)

> Статус: ветка `main` находится на стадии каркаса — продукт спроектирован в `docs/`,
> но ещё не реализован. План работ с приоритетами и детализацией — в [`docs/ROADMAP.md`](docs/ROADMAP.md).

## Установка и запуск

Основной путь — инфраструктура в docker, приложение на хосте:

```bash
cp .env.example .env                             # затем смените пароли и JWT_SECRET
docker compose up -d postgres minio minio-init mailpit
cp apps/backend/.env.example apps/backend/.env   # для работы на хосте: DB_HOST=127.0.0.1
pnpm install
pnpm db:migrate                                  # миграции (bin/migrate)
pnpm dev                                         # turbo: backend (bin/serve) + frontend (Vite)
```

Альтернатива — всё в docker (nginx+php-fpm вместо backend на хосте; порты те же,
поэтому два способа одновременно не запускаются):

```bash
docker compose up -d                             # + nginx+php-fpm (API), frontend (Vite)
docker compose exec backend php bin/migrate
```

После старта:

- Frontend: http://localhost:5173 (Vite; `/api` проксируется на бэкенд)
- API: http://localhost:8000/api/v1 · healthcheck: http://localhost:8000/api/v1/healthz
- Mailpit (SMTP-перехват): http://localhost:8025
- MinIO (S3) API/console: http://localhost:9000 / http://localhost:9001

`GET /api/v1/healthz` отвечает `200 {"status":"ok"}` только при живой БД;
при недоступности — `503` с `reason` (`pdo_pgsql_missing` / `database_unreachable`),
любой другой путь — `404 {"error":{...}}`.

## Тесты

PHPUnit (unit + интеграция) против изолированной test-postgres БД — см.
[`docs/architecture.md#запуск-backend-тестов-r15`](docs/architecture.md):

```bash
docker compose up -d test-postgres                  # тестовая БД, порт 5433
cd apps/backend
DB_HOST=127.0.0.1 DB_PORT=5433 DB_NAME=vistynq_test php bin/migrate
DB_HOST=127.0.0.1 DB_PORT=5433 DB_NAME=vistynq_test composer test
```

## Документация

- **Функциональные требования** — [`docs/requirements.md`](docs/requirements.md)
- **Технологический стек** — [`docs/README.md#стек`](docs/README.md#стек)

Полное описание продукта, архитектуры, модели данных, API и UI — в [`docs/`](docs/README.md): архитектура, ER-модель, API reference + OpenAPI, UI/UX-спецификация, гайд пользователя.
