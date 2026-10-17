# Ecosystem Sandbox

[![Build](https://github.com/rasuvaeff/ecosystem-sandbox/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/ecosystem-sandbox/actions/workflows/build.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/ecosystem-sandbox/actions/workflows/build.yml)
[![PHP](https://img.shields.io/badge/php-8.5-777BB4.svg)](composer.json)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

Runnable checkout-приложение, которое связывает независимые пакеты
`rasuvaeff/*` через Yii3-конфигурацию, PSR-интерфейсы и собственный бизнес-код.
Это не Composer-пакет и не фреймворк: каждая точка интеграции — явный код
приложения, который можно прочитать.

Этот документ и [README.md](README.md) — две версии одного руководства, правка
вносится в оба.

## Быстрый старт

```bash
git clone https://github.com/rasuvaeff/ecosystem-sandbox.git
cd ecosystem-sandbox
make smoke
```

`make smoke` собирает образы, поднимает PostgreSQL, Redis, Jaeger, Prometheus,
payment mock и webhook mock, применяет миграции, проводит checkout через HTTP и
worker и гасит стек обратно.

Пакеты `rasuvaeff/*` ставятся из **Packagist**, в выпущенных версиях — так же,
как их получило бы любое приложение. Песочнице ничего не нужно за пределами
своего каталога: Compose собирает и монтирует только этот checkout. На хосте
нужны лишь Docker с Compose v2 и `make`.

Образ работает на **PHP 8.5**, и это закреплено намеренно (`"php": "~8.5.0"` и
`config.platform.php` в `composer.json`, `php:8.5-cli-alpine`): песочница
показывает пакеты на самой новой поддерживаемой ими версии PHP, а `make check`
(php-cs-fixer, psalm level 1 без подавлений, deptrac, rector) держит код в этих рамках.

Собственный код приложения — тоже пример: слои DDD, нарезанные вертикальными
слайсами по контекстам, команды и запросы с одним обработчиком каждый,
контексты общаются доменными и интеграционными событиями, без ActiveRecord и
без service locator — см. [docs/architecture.md](docs/architecture.md). Схему
БД ведут штатные инструменты: `./yii migrate:up -n` (yiisoft/db-migration,
миграции всех пакетов и приложения) и `./yii clickhouse:migrations:migrate`
(yii3-clickhouse-toolkit). Том, созданный до этого перехода, содержит таблицы
без истории миграций: один раз выполните `make down`.

## Веб-интерфейс

```bash
make ui        # затем открыть http://127.0.0.1:8080
make ui-full   # то же плюс аналитика ClickHouse и Grafana
make ui-down
```

Страницы для человека, который не разработчик: каждая простыми словами
объясняет, что делает часть системы и зачем, на русском и английском. Каждое
число на них прочитано из работающей системы — базы, Redis, Jaeger, Centrifugo,
получателя вебхуков, — и ничего не анимируется по таймеру. Каждая страница
рендерится на сервере со своим head через `rasuvaeff/yii3-seo` (title,
description, canonical, Open Graph, JSON-LD; персональная страница заказа —
`noindex`), а `/robots.txt` не пускает поисковики в неразвёрнутую песочницу.

| Страница | Что показывает | Пакеты за ней |
|---|---|---|
| Оплата заказа | оформить заказ тестовой картой и смотреть по шагам: граф workflow (пройденный путь, возможные сейчас переходы), журнал аудита, живой индикатор предохранителя, статус по WebSocket, повтор запроса; «рекламные» ссылки приносят кампанию, которой засчитывается заказ; кнопка оплаты — A/B-тест (control / green); к оплаченному заказу прикладывается файл чека по подписанной ссылке | workflow, outbox, audit-log, idempotency, centrifugo, utm, utm-db, ab-testing, ab-testing-web, filestorage, filestorage-db, filestorage-web, filestorage-attachments |
| Предохранитель | состояние, окно сбоев, отсчёт до пробы, места bulkhead — из Redis | circuit-breaker, bulkhead, resilience, retry |
| Трассировка | трейс одного заказа «водопадом»: HTTP-запрос, задание worker'а, вызов банка — один трейс через outbox; само чтение идёт под бюджетом запроса (`X-Request-Timeout-Ms` сокращает его, 1 мс даёт 504) | telemetry, telemetry-otel, context, context-http |
| Пульт оператора | здоровье зависимостей, флаги с причиной, техработы, типизированные настройки, настоящий маскировщик аудита на примере; рубильники и настройки меняются на страницах администрирования самих пакетов (`/admin/flags`, `/admin/settings`) | health-check, feature-flags, feature-flags-db, feature-flags-ui, maintenance-mode, settings, settings-db, settings-ui, audit-log |
| Контракт API | запросы, сгенерированные из OpenAPI-документа, правильные и нарочно испорченные, браузер шлёт их в настоящий API; каждое расхождение показано | openapi-contract, property-testing-openapi |
| ИИ-агент | заранее прописанный разговор по MCP о заказе (без нейросети: отвечает настоящий MCP-сервер магазина): агент с правами и без (закрыто по умолчанию); каждый вызов, дошедший до инструмента, показан своей строкой журнала аудита | mcp, mcp-rbac-bridge, mcp-audit-log-bridge |
| Вебхуки | журнал попыток отправителя рядом с журналом получателя: подпись HMAC, нарочный 500, повтор | webhooks, webhooks-db, outbox-webhooks-bridge |
| Аналитика | результаты оплат в ClickHouse (`make ui-full`): отчёт — по заказам со страницы магазина, сгенерированные и скриптовые считаются отдельно; Grafana строит графики Prometheus и запрашивает ClickHouse напрямую; отчёт по кампаниям из PostgreSQL работает и в обычном `make ui`; A/B-тест сравнивает посетителей и конверсии по вариантам (в обычном `make ui` его события ждут в outbox) | clickhouse-toolkit, utm-db, ab-testing-outbox, ab-testing-clickhouse, outbox-clickhouse |
| Тесты | как магазин проверяет сам себя — по отчётам, которые оставили последние `make testo`, `make property-test` и `make mutation` в `build/`: unit-тесты слой за слоем, как сгенерированные случаи каждого property-теста распределились по веткам, и mutation-гейт — нарочно внесённые ошибки, где, и несколько из них с тестом, который каждую поймал | testo, property-testing-testo, infection, mutation-history |

| Карта | Сумма | Что происходит |
|---|---|---|
| Обычная карта | 19,99 | одобрено, `paid` |
| Карта без денег | 40,02 | отказ (402): окончательный, без повторов, `payment_failed` |
| Банк лёг ненадолго | 50,31 | банк недоступен **для всех** ~12 с: повторы, предохранитель размыкается, платёж проходит после его замыкания, `paid` |
| Банк лёг надолго | 50,30 | банк недоступен для всех ~45 с, дольше, чем ждёт outbox: `payment_failed`, как и любой заказ, сделанный в это время |
| Ответ банка потерялся | 50,40 | банк списывает деньги, но все ответы теряются: воркер сдаётся (`payment_failed`), потом сверка спрашивает банк, находит списание, и заказ становится `paid` |

Исход выбирается суммой, как тестовые карты у настоящих платёжных песочниц: у
payment mock нет управляющего эндпоинта. `make ui` добавляет сервис `worker`
(Compose-профиль `ui`), который каждую секунду обрабатывает outbox с
`OUTBOX_RETRY_DELAY_SECONDS=10` (три попытки перекрывают 15-секундный cooldown
предохранителя) и доставляет каждый результат оплаты одним разносчиком в
Centrifugo и получателю вебхуков, а в `make ui-full` — ещё и в ClickHouse
(`RESULT_SINKS`). Получатель вебхуков отклоняет первую попытку каждого события
(`WEBHOOK_FAIL_FIRST=1`), поэтому повтор всегда виден. Все остальные таргеты
оставляют задержку 0 и запускают worker вручную, поэтому `ui`-worker не должен
работать рядом с ними: такие сценарии отказываются стартовать, пока он работает,
а `make ui-down` (или `make down`) его останавливает.

| Проверка | Что доказывает |
|---|---|
| `make ui-smoke` | все страницы и ассеты, ключи текстов обоих языков, все четыре карты от начала до конца, разносчик (история Centrifugo, вебхук отклонён и принят, подписан), один трейс от запроса до вызова банка |
| `make ui-full-smoke` | результаты попадают в ClickHouse по одному разу, хотя каждый доставлен дважды; скриптовый заказ не попадает в отчёт; ClickHouse-панель Grafana возвращает заказы магазина; посетитель сохраняет свой вариант, подписанная квитанция засчитывается один раз, подделанная — никогда |
| `make ui-browser-smoke` | поднимает стек `ui`, гоняет его с хоста (Node 22+, Chrome) и гасит: ни одна страница не падает, страница заказа получает push по WebSocket, страница контракта не находит расхождений |

## Сценарий

```text
POST /api/v1/checkout
  -> correlation id, maintenance-гейт, kill switch платежей, claim идемпотентности
  -> SERVER span и RED-метрики
  -> валидация schema, RFC 9457 problem при ошибке
  -> одна транзакция PostgreSQL:
       заказ pending -> processing (FOR UPDATE)
       строка workflow-перехода
       audit-событие (с маскированием)
       outbox-строка payment.requested
  -> 202 Accepted
worker (sandbox:outbox:run)
  -> забирает payment.requested, переоткрывает correlation-scope запроса
  -> context deadline 3 c, bulkhead + retry + circuit breaker
  -> трассируемый PSR-18 вызов payment mock
  -> вторая транзакция: processing -> paid | payment_failed,
     audit-событие, outbox-строка order.paid | order.payment_failed
  -> нет ответа на последней попытке: payment.reconciliation_requested
worker (сверка)
  -> спрашивает банк, что стало со списанием (GET /payments/{key})
  -> списано: payment_failed -> paid в транзакции шага саги,
     outbox-строка order.paid
```

Внешний платёж никогда не выполняется внутри транзакции БД, поэтому rollback не
отменяет платёж, уже принятый провайдером. Outbox даёт at-least-once: и worker,
и обработчик результата идемпотентны, а вызов gateway всегда несёт стабильный
idempotency key.

Как worker классифицирует ответ gateway:

| Ответ | Результат |
|---|---|
| 2xx с `approved: true` | заказ `paid`, `order.paid` |
| 2xx с `approved: false` или 402 | отказ: заказ `payment_failed`, `order.payment_failed` |
| 5xx, 408, 409, 425, 429, транспортная ошибка, не-JSON тело | временный сбой: retry, учитывается circuit breaker; outbox доставит повторно |
| любой другой 4xx | отвергнут сам запрос: без retry, заказ `payment_failed`, outbox-строка терминальна |
| временный сбой на последней попытке outbox | заказ `payment_failed` (outcome `abandoned`), outbox-строка `failed`; сага запрашивает сверку, и если банк всё-таки списал деньги, заказ становится `paid` (outcome `recovered`) |

Отказ от доставки — осознанный выбор: вызов, упавший по таймауту, мог уже
списать деньги. Idempotency key gateway `payment-<sha256(orderId)>` (хеш: в
номере заказа бывают символы, недопустимые в ключе) превращает любой
следующий запрос на списание по этому заказу в тот же платёж, а не второй, а
checkout никогда не перезапускает заказ не в статусе `pending`.

## Эндпоинты

| Эндпоинт | Назначение |
|---|---|
| `GET /live` | liveness, не трогает зависимости |
| `GET /ready`, `GET /health` | readiness: PostgreSQL, payment mock, Redis |
| `GET /metrics` | Prometheus exposition |
| `POST /api/v1/checkout` | приём заказа на асинхронную оплату; запрос сначала сверяется с OpenAPI-документом (202; 400 — в том числе неверный формат `Idempotency-Key` или не-JSON тип содержимого; 409 — существующий id с другим клиентом или суммой либо тот же ключ ещё в обработке; 422 — ключ повторён с другим телом; 503) |
| `GET /api/v1/orders` | read-model с whitelisted-фильтрами `specification` (`status`, `minAmountCents`, `customerId`, `sort=amount\|recent`) |
| `GET /api/v1/orders/{id}` | один заказ (200, 404) |
| `GET /api/v1/orders/{id}/timeline` | история workflow-переходов (200, 404) |
| `GET /api/v1/ui/orders/{id}` | backend страницы заказа: evidence и, пока оплата не завершена, состояние защиты шлюза — одним ответом |
| `GET /api/v1/ui/tests` | backend страницы тестов: последние прогоны unit, property и mutation-гейта, прочитанные из `build/`; часть равна `null`, пока её цель не запускалась |
| `GET /api/v1/orders/{id}/evidence` | заказ, переходы, записи аудита и состояние доставки outbox, без payload; источник данных веб-интерфейса (200, 404) |
| `GET /files/{token}` | сохранённый файл (чек заказа) по подписанному токену со сроком действия, отдаётся на скачивание (`Content-Disposition: attachment`); любая ошибка — 404 |
| `GET /api/v1/orders/{id}/trace` | трейс оформления из Jaeger, только разрешённые атрибуты, под бюджетом запроса 5 с, который `X-Request-Timeout-Ms` может только сократить (504, когда он исчерпан) |
| `GET /api/v1/orders/{id}/realtime-token` | токен Centrifugo, подписанный только на канал этого заказа |
| `GET /api/v1/workflows/order` | места и переходы workflow заказа из определения в рантайме |
| `GET /api/v1/resilience/payment-gateway` | состояние предохранителя и bulkhead из Redis |
| `GET /api/v1/captcha` | требует ли checkout ответ reCAPTCHA (`CAPTCHA_ENABLED`) и публичный site key |
| `GET /api/v1/operator` | флаги, техработы, настройки, маскировщик аудита — только чтение, без секретов |
| `GET/POST /admin/flags…`, `/admin/settings…` | страницы администрирования `yii3-feature-flags-ui` и `yii3-settings-ui` над таблицами `feature_flags` и `settings` (серверный рендеринг, Bootstrap; без аутентификации) |
| `GET /api/v1/webhooks` | попытки отправителя вебхуков и журнал получателя |
| `GET /api/v1/analytics` | результаты оплат из ClickHouse (без него `available: false`), A/B-тест по вариантам, кампании из PostgreSQL |
| `GET /api/v1/experiments/checkout-button` | вариант кнопки оплаты для посетителя (ставит `ab_id` и подписанный cookie `ab_variants`), показ записан через outbox, плюс подписанная квитанция, которую checkout возвращает в `X-Ab-Receipt` |
| `GET /api/v1/contract/checkout-cases` | запросы оформления, сгенерированные из OpenAPI-документа; ничего не отправляется |
| `GET /api/v1/agent/transcript` | разговор по MCP внутри процесса: агент с правами и без, вместе с записанными им строками аудита |
| `GET /`, `GET /ui/` | веб-интерфейс (только встроенный сервер) |
| `GET /api/v1/tenant` | tenant, разрешённый из `X-Tenant-Id` |
| `POST /mcp` | MCP Streamable HTTP за shared secret (`X-Mcp-Secret`, `MCP_ENDPOINT_SECRET`); настоящий ассистент подключается командой `claude mcp add --transport http sandbox http://127.0.0.1:8080/mcp --header "X-Mcp-Secret: sandbox-mcp-secret"` и получает `order.status`, `order.timeline`, `payment.gateway_status` |

HTTP-контракт генерируется `yiisoft/yii-swagger` из swagger-php-атрибутов
экшенов (`#[OA\Get]`/`#[OA\Post]` на каждом `src/Endpoint/Rest/**/Action.php`,
схемы — в `src/Endpoint/Rest/Shared/Swagger/`), отдаётся по `/openapi.json`
(Swagger UI — `/docs`) и проверяется на реальном приложении пакетом
`rasuvaeff/openapi-contract`.

## Make-таргеты

`make` без аргументов (или `make help`) выводит все таргеты ниже с однострочным описанием.

CI (`.github/workflows/build.yml`) на каждый push и pull request гоняет все цели ниже: `check`, `testo` и `property-test` в одном job, mutation-гейт в другом (его журнал переезжает между прогонами в кэше Actions, поэтому рэтчет сравнивает с предыдущим прогоном) и каждый сценарий в отдельном job, так что каждый стартует на чистом volume. Job'ы quality и mutation пишут отчёт о тестах на страницу сводки прогона (`bin/test-summary.php`, тот же разбор, что у страницы тестов) и сохраняют отчёты артефактами: JUnit и HTML от testo, HTML-отчёт Infection по каждому мутанту.

### База

| Таргет | Что доказывает |
|---|---|
| `make up` / `down` / `logs` / `shell` / `migrate` | жизненный цикл стека |
| `make smoke` | health, checkout, replay, worker, заказ становится `paid` |
| `make checkout` / `checkout-replay` | один checkout; повтор ключа не создаёт второго payment-события |
| `make inspect-order ID=<id>` | заказ, переходы, audit- и outbox-строки одного заказа |
| `make inspect-outbox` | состояние outbox по типу и статусу |

### Сценарии

| Таргет | Что доказывает |
|---|---|
| `make simulate-race-condition` | 50 параллельных запросов: один переход, один audit-путь, одно payment-событие |
| `make mutation` | Infection по домену и сценариям (пол MSI 95) плюс рэтчет относительно прошлого прогона (rasuvaeff/mutation-history): мутант, убитый раньше, не должен выжить сейчас |
| `make simulate-lost-reply` | восстановление оплаты в саге: банк списал деньги, а ответ потерялся (5040); воркер сдаётся, заказ проваливается, сверка находит списание и всё-таки переводит заказ в оплаченные (`recover_payment`) — у банка одно списание, заказ `payment_failed`, затем `paid`, сага `recovered`, партнёр узнаёт сначала о `payment_failed`, потом о `paid`, по одному разу |
| `make simulate-network-collapse` | задержка gateway больше deadline: ограниченные retry, breaker, восстановление до `paid` после cooldown breaker'а; затем сбой дольше попыток outbox заканчивается `payment_failed` |
| `make simulate-kill-switch` | `payments.enabled`, выключенный формой `/admin/flags`, даёт RFC 9457 503, `/live` остаётся живым; после включения тот же ключ принимается |
| `make captcha-smoke` | опциональный reCAPTCHA-гейт на checkout против заглушки siteverify в платёжном моке: без токена или с поддельным — 403 и заказа нет, с проверенным — checkout проходит |
| `make simulate-maintenance` | maintenance 503 с `Retry-After`, исключённые health-пути, bypass по заголовку и отсутствие 503 под ключом идемпотентности |
| `make correlation-smoke` | id, выданный HTTP-запросу, — тот же, под которым лежат audit-строки обеих половин checkout |

### Тесты

| Таргет | Сьют |
|---|---|
| `make testo` / `unit-test` | unit-сьют Testo: маппинг gateway, классификация исходов воркера, guards workflow, retry outbox |
| `make property-test` | property-сьют: паритет schema/валидатора, schema против OpenAPI-документа, отказ на выходе за границы |
| `make contract-smoke` / `contract-test` | валидация request/response по OpenAPI на реальном HTTP (202, 200, 400, 404, 409), применение фильтров списка, percent-encoded id заказа |
| `make property-openapi-smoke` | 100 валидных и 50 негативных сгенерированных cases с детерминированным seed |
| `make testo-integration` / `integration-test` | Testo против живых PostgreSQL и Redis |
| `make platform-smoke` | tenancy, A/B exposure, ClickHouse routing, file storage, семантика reCAPTCHA |

### Профили пакетов

| Таргет | Пакеты |
|---|---|
| `make settings-smoke` | типизированная runtime-конфигурация `yii3-settings` |
| `make utm-smoke` | attribution journal `yii3-utm` и дедупликация событий; `make ui-smoke` гоняет настоящий путь: заголовки `X-Utm-*` на checkout, атрибуция в истории заказа, отчёт по кампаниям считает повторённый заказ один раз |
| `make webhook-smoke` / `webhook-http-smoke` | `yii3-webhooks` + `yii3-outbox-webhooks-bridge`, HMAC-подпись, retry после 500 |
| `make db-adapters-smoke` | `yii3-webhooks-db`, `yii3-utm-db` на PostgreSQL |
| `make specification-smoke` | whitelisted read-model фильтры, параметризованные значения |
| `make payments-testing-smoke` | контракт провайдера `payments-testing` без сетевого вызова |
| `make redis-smoke` / `redis-queue-smoke` | breaker и bulkhead на Redis; Redis Streams consumer group, `XAUTOCLAIM`, `XACK` |
| `make centrifugo-smoke` / `centrifugo-http-smoke` / `projection-smoke` | realtime-проекция `order.paid` и `order.payment_failed` in-memory и на реальном Centrifugo v6; ни один результат оплаты не остаётся pending |
| `make mcp-smoke` / `mcp-rbac-smoke` / `mcp-http-smoke` / `mcp` | MCP-инструменты, fail-closed RBAC, реальный Streamable HTTP эндпоинт; RBAC- и HTTP-смоуки находят свой вызов в `audit_log`, записанный на `MCP_USER_ID` |
| `make observability-smoke` / `prometheus-outcomes-smoke` / `traces` / `metrics` | серии Prometheus, спаны Jaeger, счётчики `paid` / `failed` / `rejected` |
| `make analytics-smoke` / `analytics-roundtrip` | опциональный профиль ClickHouse + Grafana и экспорт `order.paid` с чтением назад |

## Порядок middleware

```text
CorrelationId -> ErrorCatcher -> Otel -> RedMetrics -> ProblemDetails
  -> Maintenance -> Idempotency -> Tenancy -> Router
```

Порядок несущий, а не косметический:

- correlation — **самый внешний**: ответ, собранный выше него, не получает
  заголовок `X-Request-ID`, а лог-запись выше него не попадает ни под какой id.
  Когда `ProblemDetails` стоял снаружи, ответ `400` приходил без заголовка,
  а `404` из action — с ним;
- затем граница ошибок: любой сбой ниже рендерится как RFC 9457, и логгер
  `ErrorHandler` работает внутри correlation-scope;
- observability оборачивает рендерер проблем и видит статус, который получает
  клиент: action бросают свои 404/409/503, и когда `ProblemDetails` стоял
  снаружи, RED-метрики считали каждую из них как 500;
- observability намеренно оборачивает maintenance: maintenance-503 — это
  операционный сигнал, он обязан быть виден в RED-метриках и трассах;
- maintenance стоит перед idempotency, чтобы во время работ в хранилище вообще
  не писался claim.

Что таргеты действительно проверяют: `make simulate-maintenance` — 503, его
`Retry-After` и `X-Request-ID`, открытые health-эндпоинты, bypass по заголовку,
отсутствие bypass-токена в логах и приём того же ключа после работ;
`make correlation-smoke` — что id HTTP-запроса стоит в обеих audit-строках.
Последний шаг maintenance фиксирует контракт пакета, а не наш порядок:
`yii3-idempotency` кеширует только 2xx-ответы, поэтому 503 освобождает claim
при любом порядке — проверено перестановкой двух middleware (таргет остался
зелёным). Порядок остаётся предосторожностью, а тот же контракт для 503, который бросает
сам action, фиксирует `make simulate-kill-switch`.

## Одно определение payload

`App\Endpoint\Rest\Ordering\Checkout\Input::schema()` — единственное определение checkout-payload,
используемое в трёх местах, чтобы они не разъехались:

1. `parseOrThrow()` валидирует недоверенный JSON на границе HTTP и не выводит
   значения входа в ошибки;
2. `toJsonSchema()` сравнивается со схемой `CheckoutRequest` сгенерированного документа
   в property-сьюте;
3. `arbitrary()` генерирует валидные значения, которые property-сьют прогоняет
   через опубликованный контракт.

Сгенерированный документ остаётся внешним HTTP-контрактом: JSON Schema не проверяет
status codes, заголовки и тела ответов — это делает `openapi-contract`.

## Observability

- `/metrics` — exposition `yii3-metrics` + `yii3-metrics-prometheus`;
- Jaeger UI — `http://127.0.0.1:16686`, OTLP/HTTP на порт `4318`;
- Prometheus — `http://127.0.0.1:9090`;
- бизнес-счётчик `sandbox_payment_outcomes_total{outcome="paid|failed|rejected|abandoned|recovered"}`
  пишет worker, он не выводится из HTTP-статусов;
- `observability-smoke` дополнительно проверяет, что чувствительные атрибуты не
  попадают в спаны.

## Конфигурация

| Переменная | По умолчанию | Эффект |
|---|---|---|
| `APP_PORT` | `8080` | опубликованный порт приложения; интерфейс работает с любого порта хоста и по имени в локальной сети (Centrifugo принимает любой origin) |
| `CENTRIFUGO_PORT`, `JAEGER_UI_PORT`, `PROMETHEUS_PORT`, `GRAFANA_PORT`, `CLICKHOUSE_PORT` | `8000`, `16686`, `9090`, `13000`, `18123` | опубликованные порты побочных сервисов; порты Jaeger, Prometheus и Grafana страницы узнают из `/ui/ports.json`, адрес WebSocket (Centrifugo) приходит вместе с realtime-токеном. Publish API Centrifugo слушает внутренний порт 9000 и наружу не публикуется |
| `OUTBOX_RETRY_DELAY_SECONDS` | `0` (`10` у `ui`-worker) | пауза между попытками outbox — и для оплат, и для доставки результатов |
| `RESULT_SINKS` | `centrifugo` (`centrifugo,webhooks,receipts` в `make ui`, плюс `clickhouse` в `make ui-full`) | подписчики разносчика результатов, перечисляются явно |
| `FILES_SIGNING_KEY` | встроенное демо-значение | подписывает ссылки на скачивание чеков (не короче 32 байт) |
| `AB_EXPORT` | `0` (`1` в `make ui-full`) | worker выгружает A/B-события из outbox в ClickHouse (`sandbox:ab:export`, который один раз создаёт и аналитические таблицы) |
| `AB_AGGREGATE_SECRET`, `AB_COOKIE_SECRET`, `AB_RECEIPT_SECRET` | встроенные демо-значения | псевдонимные aggregate id в outbox, подпись cookie закреплённого варианта, подпись квитанции о показе |
| `CENTRIFUGO_TOKEN_SECRET` | встроенное демо-значение | подписывает токен подключения браузера (не короче 32 байт); compose передаёт одно значение и приложению, и Centrifugo |
| `CAPTCHA_ENABLED`, `RECAPTCHA_SITE_KEY`, `RECAPTCHA_SECRET`, `RECAPTCHA_VERIFY_URL` | `false`, тестовые ключи Google v2, siteverify Google | опциональный reCAPTCHA-гейт на checkout (`X-Captcha-Token`); выключен по умолчанию: проверке нужен интернет, и любой скриптовый checkout её бы не прошёл |
| `APP_PUBLIC_URL` | `http://127.0.0.1:8080` | база canonical- и Open Graph-адресов UI-страниц (yii3-seo) |
| `APP_INDEXABLE` | `false` | `true` пускает краулеров через `/robots.txt`; не включать до осознанного развёртывания |
| `PAYMENTS_ENABLED` | `true` | первое значение флага, data-миграция (`migrate:up`) засевает его в `feature_flags`; дальше решает таблица (и `/admin/flags`) — `false` превращает checkout в RFC 9457 503 |
| `MAINTENANCE_ENABLED` | `false` | `true` даёт 503 с `Retry-After` вне health-путей |
| `MAINTENANCE_RETRY_AFTER` | `120` | секунды в заголовке `Retry-After` |
| `MAINTENANCE_BYPASS_TOKEN` | `sandbox-maintenance-bypass` | значение `X-Maintenance-Bypass`, в конфиг попадает хешем |
| `PAYMENT_GATEWAY_MODE`, `PAYMENT_GATEWAY_TIMEOUT_MS`, `PAYMENT_GATEWAY_CONNECT_TIMEOUT_MS` | `sandbox`, `3000`, `1000` | типизированы через `yii3-settings` |
| `PAYMENT_MOCK_DELAY_MS`, `PAYMENT_MOCK_FAIL`, `PAYMENT_MOCK_UNAVAILABLE` | `0`, `0`, `0` | поведение payment mock |
| `MCP_ENDPOINT_SECRET`, `MCP_USER_ID` | `sandbox-mcp-secret`, `demo-user` | shared secret MCP и демонстрационная identity |

Полный список — [.env.example](.env.example).

## Ограничения

- страницы администрирования (`/admin/flags`, `/admin/settings`) **без
  аутентификации и без защиты от CSRF**: они для песочницы на машине
  разработчика. Шаблон редактирования флага — копия
  (`resources/admin/flag-edit.php`), потому что выпущенный `yii3-feature-flags-ui`
  читает `$csrf` без проверки и без CSRF-middleware отвечает 500;
- correlation id выдаётся здесь, присланный клиентом `X-Request-ID` **не**
  доверяется (`acceptIncoming: false`) — это дефолт пакета;
- `yii3-workflow-db` гарантирует уникальность idempotency key перехода;
  конкурентность заказа держит собственный `SELECT ... FOR UPDATE`;
- `rasuvaeff/context` задаёт кооперативный deadline и сам не прерывает
  блокирующий системный вызов. Deadline не даёт начать новую попытку вызова
  gateway; одна попытка ограничена `PAYMENT_GATEWAY_TIMEOUT_MS`, потому что у
  PSR-18 нет таймаута на запрос. Одобрение, пришедшее после deadline, всё равно
  оплачивает заказ;
- Kafka намеренно отсутствует: ни один пакет монорепо не реализует Kafka-
  транспорт, поэтому runnable queue-профиль использует Redis Streams;
- DB-backed реестры tenancy и A/B, браузерные A/B-cookie, endpoint загрузки
  файлов остаются отдельными профилями вне checkout-пути;
- адаптеры Stripe и PayPal в песочницу не входят: они не опубликованы, а
  песочница использует только выпущенные пакеты.
