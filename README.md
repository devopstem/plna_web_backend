# PLNA Web Backend V 2.7

Веб-бэкенд экосистемы **PLNA** для управления планограммами розничных сетей: магазины, оборудование, товары, расстановки, план этажа, экспорт в мобильное приложение (Firebase), биллинг и администрирование арендаторов.

| | |
|---|---|
| **Version** | `2.7.0` (см. [VERSION](VERSION)) |
| **Author** | [DevOpsTem](https://github.com/devopstem-debug) (`devopstem`) |
| **License** | [Proprietary](LICENSE) — не open source / не MIT |
| **Stack** | Laravel 13 · Inertia/React · PostgreSQL · Redis · Firebase · Reverb · Shield WAF |

## Для чего этот сайт

Система нужна сетям и магазинам, чтобы:

- вести каталог магазинов, отделов и товаров;
- описывать полки, холодильники и стенды;
- строить планограммы (расстановку SKU на уровнях полок);
- рисовать план торгового зала (расположение оборудования и стен);
- выгружать данные в Firebase Realtime Database для мобильного клиента;
- управлять пользователями, ролями, подписками и счетами в мультиарендной модели.

Это **production-grade** control center (не MVP): tenancy, RBAC, WAF, 2FA, биллинг, очереди Firebase, CI + SonarCloud Quality Gate.

## Что нового в 2.7

- **Каталог товаров:** запись глобальных SKU только у Super Admin; арендаторы создают/меняют только private label; CSV-импорт не портит общий каталог
- **Sheet import (OCR):** tenant + store ACL, stream картинки (без base64 в Inertia), частичный commit без ложного `committed`
- **Экспорт Firebase:** ручная синхронизация через очередь (не sync в HTTP); SyncLog latest-by-store через `DISTINCT ON`
- **Память/ops:** хвост `laravel.log` ≤ 2 MB; billing approve/reject с `lockForUpdate`
- **Лицензия:** proprietary (закрытый код) вместо MIT
- **CI/SonarCloud:** Postgres 16, `npm run build`, Clover + JUnit, обязательный `SONAR_TOKEN`, Quality Gate

### После обновления с 2.6

```bash
php artisan migrate --force
php artisan optimize:clear
# убедитесь, что queue worker запущен — ручной Firebase sync теперь в очереди
php artisan queue:work
```

## Что было в 2.6

- **Канонический RBAC (Spatie):** Super Admin / Заместитель / Заведующий / Программист / Мерчандайзер
- Route gates: `/admin/*` только Super Admin; billing/users/tenants — Tenant Admin+; floor-plan/planograms/shelves/export — Store Manager+
- Store Manager: ownership checks (`EnsuresStoreAccess`) + department/store scope; Export JSON/Firebase только для своих магазинов
- Программист и Мерчандайзер — API/mobile only (`EnsureApiOnlyRole` → `/mobile-only`)
- `RolesAndPermissionsSeeder`; Shield UUID patch для `user_id`

### После обновления с 2.5

```bash
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder --force
php artisan permission:cache-reset
```

## Что было в 2.5

- **Security hardening перед публичным продом:** закрыта открытая регистрация; усилена SQL-консоль; HTTP SQL-дамп убран (только offline `pg_dump`)
- Laravel Shield WAF + Sanctum hardening; 2FA rate limit; CI audits + SonarCloud
- Ops: Reverb на localhost; бэкап БД перед migrate; deploy `down → migrate → up`

## Быстрый старт (локально)

См. [docs/SETUP.md](docs/SETUP.md).

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
npm ci && npm run build
php artisan serve
```

## Деплой на VPS

См. [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

```bash
# новый сервер (репозиторий уже склонирован)
sudo DOMAIN=your-domain.tld WITH_SSL=1 ./scripts/install.sh

# обновление (pg_dump перед migrate по умолчанию)
sudo ./scripts/deploy.sh
```

После первой установки Shield на проде (если lookup-таблицы пустые):

```bash
php artisan db:seed --class="OzanKurt\\Shield\\Database\\Seeders\\LookupTableSeeder"
php artisan db:seed --class="OzanKurt\\Shield\\Database\\Seeders\\BuiltinWafRuleSeeder"
```

В `.env` продакшена: `FIREWALL_ENABLED=true`, `LS_AUDIT_HMAC_SECRET`, `LS_BYPASS_KEY`, `LS_BYPASS_IPS`.

## CI / SonarCloud

Workflow: [`.github/workflows/ci.yml`](.github/workflows/ci.yml)

1. `composer audit` + `npm audit --audit-level=high`
2. `npm run build`
3. migrate + PHPUnit (Clover + JUnit)
4. Pint + PHPStan (Larastan)
5. SonarCloud Scan + **Quality Gate** (`secrets.SONAR_TOKEN`)

Конфиг анализа: [`sonar-project.properties`](sonar-project.properties). Подробнее: [docs/SECURITY.md](docs/SECURITY.md).

## Документация

Полный указатель: [docs/README.md](docs/README.md)

| Документ | Тема |
|----------|------|
| [OVERVIEW](docs/OVERVIEW.md) | Продукт |
| [ARCHITECTURE](docs/ARCHITECTURE.md) | Архитектура |
| [FIREBASE](docs/FIREBASE.md) | Экспорт / каталог / пользователи |
| [SECURITY](docs/SECURITY.md) | Безопасность + CI |
| [ROLES](docs/ROLES.md) | Роли |

## Автор и лицензия

Copyright © 2024–2026 **DevOpsTem** ([@devopstem-debug](https://github.com/devopstem-debug)).

**Proprietary software** — см. [LICENSE](LICENSE). Исходный код и документация не являются MIT/open source; коммерческое использование и распространение — только по письменному соглашению с правообладателем. Сторонние зависимости (Composer/npm) сохраняют свои исходные лицензии.
