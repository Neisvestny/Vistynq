# Vistynq

Monorepo (Turborepo + pnpm workspaces) с Vue.js frontend и PHP backend.

## Структура

```
/
├── apps/
│   ├── frontend/   # Vue 3 + Vite + TypeScript (create-vue)
│   └── backend/    # минимальное PHP-приложение (php -S)
├── package.json
├── pnpm-workspace.yaml
├── turbo.json
├── .gitignore
└── README.md
```

## Требования

- Node.js (LTS) и pnpm
- PHP >= 8.2

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
