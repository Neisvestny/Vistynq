#!/usr/bin/env node
/**
 * pnpm setup — первичная настройка репозитория на любой ОС (Windows/Linux/macOS).
 *
 * Что делает:
 *   1. создаёт `.env` в корне (из .env.example) — для интерполяции docker compose;
 *   2. создаёт `apps/backend/.env` (из .env.host.example) — для backend на хосте;
 *   3. при необходимости ставит JS-зависимости (`pnpm install`);
 *   4. при необходимости ставит PHP-зависимости (`composer install`);
 *   5. проверяет окружение: php + расширения (pdo_pgsql, sodium, mbstring),
 *      composer, docker CLI и живой docker-демон.
 *
 * Выход с ненулевым кодом, если найдены блокеры, — с инструкцией, что делать.
 */

import { execSync } from 'node:child_process'
import { copyFileSync, existsSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')

/** @param {string} cmd */
function run(cmd, { timeout = 60_000 } = {}) {
  try {
    const out = execSync(cmd, {
      cwd: root,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout,
      windowsHide: true,
    })
    return { ok: true, out: (out ?? '').trim() }
  } catch (error) {
    const out = [error?.stdout, error?.stderr]
      .filter(Boolean)
      .join(' ')
      .trim()
    return { ok: false, out }
  }
}

const created = []
const blockers = []
const warnings = []
const done = []

// 1. .env файлы -------------------------------------------------------------
const envPairs = [
  { from: '.env.example', to: '.env', what: 'корневой .env (docker compose)' },
  {
    from: path.join('apps', 'backend', '.env.host.example'),
    to: path.join('apps', 'backend', '.env'),
    what: 'apps/backend/.env (backend на хосте)',
  },
]

for (const { from, to, what } of envPairs) {
  const target = path.join(root, to)
  if (existsSync(target)) {
    done.push(`${what} — уже есть`)
    continue
  }
  const source = path.join(root, from)
  if (!existsSync(source)) {
    blockers.push(`не найден шаблон ${from}`)
    continue
  }
  copyFileSync(source, target)
  created.push(`${what} — создан из ${from}`)
}

// 2. JS-зависимости ---------------------------------------------------------
if (existsSync(path.join(root, 'node_modules'))) {
  done.push('node_modules — уже установлены')
} else {
  const res = run('pnpm install')
  if (res.ok) {
    done.push('pnpm install — выполнен')
  } else {
    blockers.push('`pnpm install` не удался — запустите вручную и посмотрите вывод')
  }
}

// 3. PHP-зависимости --------------------------------------------------------
const vendorAutoload = path.join(root, 'apps', 'backend', 'vendor', 'autoload.php')
const composerCheck = run('composer --version', { timeout: 30_000 })

if (existsSync(vendorAutoload)) {
  done.push('apps/backend/vendor — уже установлен')
} else if (!composerCheck.ok) {
  blockers.push(
    'composer не найден — установите:\n' +
      '      Windows: winget install Composer.Composer\n' +
      '      Linux:   sudo apt install composer   (или https://getcomposer.org/download/)\n' +
      '      macOS:   brew install composer',
  )
} else {
  const res = run('composer install --no-interaction --no-progress', { timeout: 300_000 })
  if (res.ok) {
    done.push('composer install — выполнен (apps/backend/vendor)')
  } else {
    blockers.push('`composer install` не удался — запустите вручную в apps/backend')
  }
}

// 4. Проверка окружения -----------------------------------------------------
const phpCheck = run('php -v', { timeout: 30_000 })
if (!phpCheck.ok) {
  blockers.push(
    'php не найден в PATH — установите PHP 8.2+:\n' +
      '      Windows: winget install PHP.PHP.8.5\n' +
      '      Linux:   sudo apt install php php-pgsql php-mbstring\n' +
      '      macOS:   brew install php',
  )
} else {
  // Проверка расширений одним php-вызовом (код без $ — чтобы не ломать shell-escaping).
  const extCheck = run(
    `php -r "echo extension_loaded('pdo_pgsql') && extension_loaded('sodium') && extension_loaded('mbstring') ? '1' : '0';"`,
    { timeout: 30_000 },
  )
  if (extCheck.ok && extCheck.out === '1') {
    done.push('php-расширения (pdo_pgsql, sodium, mbstring) — загружены')
  } else {
    blockers.push(
      'в PHP не хватает расширений pdo_pgsql / sodium / mbstring.\n' +
        '      Инструкция по настройке php.ini — в docs/architecture.md, раздел «Настройка PHP».\n' +
        '      Быстрая проверка: php -m',
    )
  }
}

const dockerCheck = run('docker --version', { timeout: 30_000 })
if (!dockerCheck.ok) {
  blockers.push(
    'docker CLI не найден — установите Docker Desktop (Windows/macOS) или Docker Engine (Linux):\n' +
      '      https://docs.docker.com/get-docker/',
  )
} else {
  const daemon = run('docker info', { timeout: 30_000 })
  if (daemon.ok) {
    done.push('docker-демон — запущен')
  } else {
    blockers.push(
      'docker-демон не отвечает:\n' +
        '      Windows/macOS: запустите Docker Desktop и подождите 10–20 секунд\n' +
        '      Linux:         sudo systemctl start docker',
    )
  }
}

// 5. Итог -------------------------------------------------------------------
const line = '─'.repeat(60)
console.log(`\n${line}\n  pnpm setup — отчёт\n${line}`)

if (created.length) {
  console.log('\nСоздано:')
  for (const item of created) console.log(`  + ${item}`)
}
if (done.length) {
  console.log('\nГотово:')
  for (const item of done) console.log(`  ✓ ${item}`)
}
if (warnings.length) {
  console.log('\nПредупреждения:')
  for (const item of warnings) console.log(`  ! ${item}`)
}

if (blockers.length) {
  console.log('\nБлокеры:')
  for (const item of blockers) console.log(`  ✗ ${item}`)
  console.log(`\n${line}`)
  console.log('Исправьте блокеры и запустите `pnpm setup` ещё раз.')
  process.exit(1)
}

console.log(`
Следующие шаги:
  1. pnpm infra:up        # postgres + minio + mailpit в docker
  2. pnpm db:migrate      # схема БД
  3. pnpm dev             # backend (8000) + frontend (5173)

Smoke-проверки:
  http://localhost:8000/api/v1/healthz   → 200 {"status":"ok"}
  http://localhost:5173                  → frontend (запросы /api уходят на backend)
  http://localhost:8025                  → Mailpit (письма)
  http://localhost:9001                  → MinIO console (файлы)
${line}`)
