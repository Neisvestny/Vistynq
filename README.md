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
- Node.js (LTS) и pnpm — только для работы фронтенда вне контейнера

> Статус: ветка `main` находится на стадии каркаса — продукт спроектирован в `docs/`,
> но ещё не реализован. План работ с приоритетами и детализацией — в [`docs/ROADMAP.md`](docs/ROADMAP.md).

## Установка

```bash
cp .env.example .env   # затем смените пароли и JWT_SECRET
docker compose up -d   # postgres, minio, mailpit, nginx+php-fpm (API), frontend (Vite)
```

Зависимости (composer/pnpm) при первом старте ставятся автоматически внутри контейнеров.

## Запуск

```bash
docker compose up -d   # всё окружение разом
```

После старта:

- Frontend: http://localhost:5173 (Vite; `/api` проксируется на бэкенд)
- API: http://localhost:8000/api/v1 · healthcheck: http://localhost:8000/api/v1/healthz
- Mailpit (SMTP-перехват): http://localhost:8025
- MinIO (S3) API/console: http://localhost:9000 / http://localhost:9001

Фронтенд только на хосте (без контейнера):

```bash
pnpm install
pnpm --filter frontend dev   # http://localhost:5173; /api идёт на localhost:8000
```

## Документация

- **Функциональные требования** — [`docs/requirements.md`](docs/requirements.md)
- **Технологический стек** — [`docs/README.md#стек`](docs/README.md#стек)

Полное описание продукта, архитектуры, модели данных, API и UI — в [`docs/`](docs/README.md): архитектура, ER-модель, API reference + OpenAPI, UI/UX-спецификация, гайд пользователя.
