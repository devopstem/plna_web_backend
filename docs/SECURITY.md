# Безопасность

Версия документа: PLNA Web Backend **2.5**.

## Секреты

| Артефакт | Где хранить | В git? |
|----------|-------------|--------|
| `.env` | только на сервере / локально | ❌ |
| Firebase service account JSON | `storage/app/private/...` или секрет-хранилище | ❌ |
| `APP_KEY` | `.env` | ❌ |
| `LS_AUDIT_HMAC_SECRET` / `LS_BYPASS_KEY` | `.env` (Shield) | ❌ |
| Reverb / mail / AWS keys | `.env` | ❌ |
| `SONAR_TOKEN` | GitHub Actions secret | ❌ |

`.gitignore` уже исключает `.env`, `vendor/`, `node_modules/`, `storage/app/private/**`, `*adminsdk*.json`, `firebase-credentials.json`.

Диск `local` (`storage/app/private`) публично **не** отдаётся (`serve => false`).

## Доступ и tenancy

- Роли и permissions — [ROLES.md](ROLES.md)
- Открытая саморегистрация **закрыта** — пользователей создаёт админ с привязкой к tenant/роли
- Database Viewer / SQL-консоль — permission `manage-database`; только SELECT по whitelist-таблицам, blocklist `pg_*`, timeout + LIMIT
- HTTP SQL-дамп всей БД **удалён** — бэкапы только offline (`scripts/deploy.sh` / `pg_dump`)
- Wall и store-scoped модели проверяют tenant (global scope / `Store::query()`)
- API `/api/v1` зеркалит web: `throttle:api` + `subscription.active`

## WAF (Laravel Shield)

Пакет: `ozankurt/laravel-security` (config: `config/shield.php`).

Регистрация middleware — `bootstrap/app.php` (группы `web` и `api`), **не** `Http/Kernel.php`.

```env
FIREWALL_ENABLED=true
FIREWALL_DASHBOARD_ENABLED=false
LS_HONEYPOT_ENABLED=true
LS_HEADERS_ENABLED=true
LS_CSP_ENABLED=false
LS_BYPASS_IPS=127.0.0.1,OFFICE_IP
LS_AUDIT_HMAC_SECRET=...
LS_BYPASS_KEY=...
```

В `APP_ENV=testing` / `FIREWALL_ENABLED=false` слой не вешается (PHPUnit).

После migrate на проде, если lookup-таблицы пустые:

```bash
php artisan db:seed --class="OzanKurt\\Shield\\Database\\Seeders\\LookupTableSeeder"
php artisan db:seed --class="OzanKurt\\Shield\\Database\\Seeders\\BuiltinWafRuleSeeder"
```

Sanctum: `pushery/sanctum-hardening-for-laravel` (`config/sanctum-hardening.php`) — throttle/ограничения токенов поверх Sanctum, без смены login-flow.

## 2FA

- Challenge: `throttle:two-factor` (5/мин)
- Сессия `login.id` живёт 10 минут (`login.id_expires_at`)

## Сессии и HTTPS

На публичном HTTPS:

```env
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SECURE_HEADERS_HSTS=true
```

## Content-Security-Policy (production)

Middleware `SecurityHeaders` разрешает:

- тайлы карты OpenStreetMap (`img-src`);
- геокодирование Nominatim (`connect-src`);
- WebSocket Reverb (из `REVERB_*` в `.env`).

На HTTPS Reverb в браузере — через **443** (Nginx → localhost:8080), не `:8080` снаружи:

```env
REVERB_HOST=example.com
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=127.0.0.1
REVERB_INTERNAL_HOST=127.0.0.1
REVERB_INTERNAL_PORT=8080
REVERB_INTERNAL_SCHEME=http
```

Дополнительные origins: `CSP_CONNECT_SRC_EXTRA`, `CSP_IMG_SRC_EXTRA`.

## CI-аудиты и SonarCloud

Workflow `.github/workflows/ci.yml`:

1. `composer audit` — падает при известных CVE в PHP-зависимостях
2. `npm audit --audit-level=high` — падает при high/critical в JS
3. PHPUnit + Clover coverage
4. SonarCloud Scan (`secrets.SONAR_TOKEN`, `sonar-project.properties`)

Локально: `composer run audit`.

## Инциденты с credentials

Если service account Firebase или `.env` попали в публичный репозиторий:

1. Немедленно ротируйте ключи в Google Cloud / Firebase.
2. Смените `APP_KEY` только с пониманием последствий (сессии/шифрованные поля).
3. Удалите секреты из истории git (`git filter-repo` / BFG) или сделайте новый репозиторий без них.
