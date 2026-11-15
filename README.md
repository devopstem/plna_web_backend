# PLNA Web Backend V 2.6

Веб-бэкенд экосистемы **PLNA** для управления планограммами розничных сетей: магазины, оборудование, товары, расстановки, план этажа, экспорт в мобильное приложение (Firebase), биллинг и администрирование арендаторов.

| | |
|---|---|
| **Version** | `2.6.0` (см. [VERSION](VERSION)) |
| **Author** | [DevOpsTem](https://github.com/devopstem-debug) (`devopstem`) |
| **License** | [MIT](LICENSE) |
| **Stack** | Laravel 13 · Inertia/React · PostgreSQL · Redis · Firebase · Reverb · Shield WAF |

## Для чего этот сайт

Система нужна сетям и магазинам, чтобы:

- вести каталог магазинов, отделов и товаров;
- описывать полки, холодильники и стенды;
- строить планограммы (расстановку SKU на уровнях полок);
- рисовать план торгового зала (расположение оборудования и стен);
- выгружать данные в Firebase Realtime Database для мобильного клиента;
- управлять пользователями, ролями, подписками и счетами в мультиарендной модели.

## Что нового в 2.6

- **Канонический RBAC (Spatie):** Super Admin / Заместитель / Заведующий / Программист / Мерчандайзер
- Route gates: `/admin/*` только Super Admin; billing/users/tenants — Tenant Admin+; floor-plan/planograms/shelves/export — Store Manager+
- Store Manager: ownership checks (`EnsuresStoreAccess`) + department/store scope; Export JSON/Firebase только для своих магазинов
- Программист и Мерчандайзер — API/mobile only (`EnsureApiOnlyRole` → `/mobile-only`)
- `RolesAndPermissionsSeeder`; Shield UUID patch для `user_id`; убран фейковый UI locale

### После обновления с 2.5

```bash
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder --force
php artisan permission:cache-reset
# или php artisan optimize:clear
```

## Что было в 2.5

- **Security hardening перед публичным продом:** закрыта открытая регистрация; усилена SQL-консоль; HTTP SQL-дамп убран (только offline `pg_dump`)
- Laravel Shield WAF (`ozankurt/laravel-security`) + Sanctum hardening
- Tenant-scope для Wall (закрыт IDOR); API: `throttle` + `subscription.active`; 2FA: rate limit + TTL сессии
- CI: `composer audit`, `npm audit --audit-level=high`, покрытие + SonarCloud Scan
- Ops: `REVERB_INTERNAL_*` и bind Reverb на localhost; бэкап БД перед migrate/reset; deploy `down → migrate → up`
- Frontend: вычищены мёртвые зависимости; один PWA через VitePWA

### Breaking относительно 2.4

- `POST /register` закрыт (пользователей создаёт админ)
- Маршрут `database/export-sql` удалён — бэкапы только через скрипты / `pg_dump` на сервере

## Что было в 2.4

- Полноэкранная форма магазина с картой (геокодирование OpenStreetMap)
- Экспорт магазина + полного каталога товаров в Firebase
- Двухфакторная аутентификация (TOTP + recovery-коды)
- Browser push + скрипты `install.sh` / `deploy.sh` для Ubuntu VPS

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

## Документация

Полный указатель: [docs/README.md](docs/README.md)

| Документ | Тема |
|----------|------|
| [OVERVIEW](docs/OVERVIEW.md) | Продукт |
| [ARCHITECTURE](docs/ARCHITECTURE.md) | Архитектура |
| [FIREBASE](docs/FIREBASE.md) | Экспорт / каталог / пользователи |
| [SECURITY](docs/SECURITY.md) | Безопасность |
| [ROLES](docs/ROLES.md) | Роли |

## Автор и лицензия

Copyright © 2024–2026 **DevOpsTem** ([@devopstem-debug](https://github.com/devopstem-debug)).

Распространяется по лицензии **MIT** — см. [LICENSE](LICENSE).
