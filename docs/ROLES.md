# Роли и права доступа

Источник истины: `app/Support/Permissions.php` и `database/seeders/RolesAndPermissionsSeeder.php` (Spatie Permission, guard `web`).

Канон: **не плодим роли под фичи** — добавляем `permission`, границы данных — через scope (`tenant` / `department` / `store`).

## Канонические роли

| DB name | Alias | Слой | Scope | Web |
|---------|-------|------|-------|-----|
| `Super Admin` | Platform Admin | Platform | все tenant | да |
| `Заместитель` | Tenant Admin | Tenant | весь свой tenant | да |
| `Заведующий` | Store Manager | Field | свой магазин (через отдел) | да |
| `Программист` | Integrator | Tenant | view-stores/products + export | **нет** (API) |
| `Мерчандайзер` | Merchandiser | Field | каталог mobile/API | **нет** (mobile) |

> Legacy: Network Manager → Заместитель, Store Manager → Заведующий (в сидере).

## Матрица

| Возможность | Super Admin | Заместитель | Заведующий | Программист | Мерчандайзер |
|-------------|:-----------:|:-----------:|:----------:|:-----------:|:------------:|
| Магазины / отделы / оборудование | ✅ | CRUD* | view/edit своего магазина | view stores | view (API) |
| Товары | ✅ | ✅* | create/edit своего scope | view | view (API) |
| Планограммы / карта зала / полки | ✅ | ✅ | ✅ свой магазин | — | view (API) |
| Экспорт / Firebase JSON | ✅ | ✅ | ✅ только свои магазины | ✅ API | — |
| Пользователи tenant | ✅ | create/edit | — | — | — |
| Аналитика сети | ✅ | ✅ | — | — | — |
| Billing | ✅ | ✅ | — | — | — |
| Tenants (свой / платформа) | ✅ full | show/edit своего, **без delete** | — | — | — |
| Plans / Invoices / Legal / DB/SQL | ✅ | — | — | — | — |
| Web-панель | ✅ | ✅ | ✅ | ❌ → `/mobile-only` | ❌ → `/mobile-only` |

\* Без опасных delete/restore/block и без `manage-database` — см. `Permissions::forDeputy()`.

## Route gates (`routes/web.php`)

| Prefix | Roles |
|--------|-------|
| `/admin/*` | Super Admin |
| `/database/*`, product-bot | Super Admin |
| `/billing/*`, `/users/*`, `/tenants/*` | Super Admin, Заместитель |
| `/floor-plan/*`, `/planograms/*`, `/shelves/*`, `/export/*` | Super Admin, Заместитель, Заведующий |

Store Manager ownership: `EnsuresStoreAccess` в Store / Export / FloorPlan контроллерах (+ global scope по отделу).

## После деплоя / смены матрицы

```bash
php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder --force
php artisan permission:cache-reset
# или
php artisan optimize:clear
```

`RoleSeeder` — алиас на тот же сидер.

## Middleware

1. `auth`
2. `user.active`
3. `tenant.active` (Super Admin исключение)
4. `subscription.active` (billing без него)
5. `merchandiser.mobile` / `api.only` — Программист и Мерчандайзер → `/mobile-only`, API не трогаем
6. `role:…` — группы выше

## Группы permissions

Константы в `Permissions.php`. Новые экраны = новый permission + назначение **существующим** ролям.
