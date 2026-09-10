# Vistynq

Monorepo (Turborepo + pnpm workspaces) с Vue.js frontend и PHP backend.

## Структура

```
/
├── apps/
│   ├── frontend/   # Vue 3 + Vite + TypeScript (create-vue)
│   └── backend/    # минимальное PHP-приложение (php -S)
├── docs/           # документация: архитектура, модель данных, API, UI/UX, гайд
├── package.json
├── pnpm-workspace.yaml
├── turbo.json
├── .gitignore
└── README.md
```

## Требования

- Node.js (LTS) и pnpm
- PHP >= 8.2

> Статус: ветка `main` находится на стадии каркаса — продукт спроектирован в `docs/`,
> но ещё не реализован. План работ с приоритетами и детализацией — в [`docs/ROADMAP.md`](docs/ROADMAP.md).

## Установка

```bash
pnpm install
```

## Запуск

```bash
pnpm dev            # TUI Turborepo: переключение между консолью frontend/backend — стрелки ↑/↓
pnpm build          # сборка frontend (turbo run build)
```

По отдельности:

```bash
pnpm --filter frontend dev    # Vue dev-сервер (http://localhost:5173)
pnpm --filter backend dev     # PHP built-in сервер (http://127.0.0.1:8000)
```

## Документация

Полное описание продукта, архитектуры, модели данных, API и UI — в [`docs/`](docs/README.md): архитектура, ER-модель, API reference + OpenAPI, UI/UX-спецификация, гайд пользователя.
