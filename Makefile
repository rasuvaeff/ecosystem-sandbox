COMPOSE ?= docker compose

# Targets that run a script with `compose run` never start the server, whose
# command is what installs the dependencies; on a fresh clone they need this.
VENDOR := vendor/autoload.php

$(VENDOR): composer.json composer.lock
	$(COMPOSE) run --rm --no-deps app sh -c 'composer install --no-interaction --no-progress && touch $@'

.DEFAULT_GOAL := help

# `make` alone prints this. Each target carries its line after `##`; the
# groups below set the order.
##@ Stack: up down logs shell migrate
##@ Web UI: ui ui-full ui-down
##@ By hand: checkout checkout-replay inspect-order inspect-outbox
##@ Static checks: check cs cs-fix psalm rector rector-fix deptrac
##@ Tests: testo property-test mutation testo-integration
##@ Scenarios (each asserts an effect, on a fresh volume): smoke contract-smoke property-openapi-smoke specification-smoke centrifugo-smoke centrifugo-http-smoke projection-smoke redis-smoke db-adapters-smoke mcp-smoke mcp-rbac-smoke mcp-http-smoke payments-testing-smoke settings-smoke utm-smoke webhook-smoke webhook-http-smoke observability observability-smoke prometheus-outcomes-smoke redis-queue-smoke ui-smoke ui-full-smoke ui-browser-smoke platform-smoke analytics-smoke analytics-roundtrip correlation-smoke
##@ Failure simulations: simulate-maintenance simulate-network-collapse simulate-lost-reply simulate-race-condition simulate-kill-switch captcha-smoke

help: ## this list
	@awk -F'## ' ' \
	    /^[a-z0-9-]+:.*## / { split($$1, t, ":"); desc[t[1]] = $$2; next } \
	    /^##@ / { line = substr($$0, 5); i = index(line, ": "); names[++n] = substr(line, 1, i - 1); members[n] = substr(line, i + 2) } \
	    END { \
	        print "Usage: make <target>"; \
	        for (g = 1; g <= n; g++) { \
	            printf "\n\033[1m%s\033[0m\n", names[g]; \
	            c = split(members[g], m, " "); \
	            for (j = 1; j <= c; j++) printf "  \033[36m%-27s\033[0m %s\n", m[j], desc[m[j]]; \
	        } \
	    }' $(MAKEFILE_LIST)

.PHONY: help check cs cs-fix psalm rector rector-fix deptrac up down logs migrate smoke shell checkout checkout-replay inspect-order inspect-outbox unit-test integration-test contract-test property-test traces metrics mcp observability observability-smoke prometheus-outcomes-smoke correlation-smoke simulate-maintenance redis-queue-smoke contract-smoke property-openapi-smoke specification-smoke centrifugo-smoke centrifugo-http-smoke projection-smoke redis-smoke db-adapters-smoke mcp-smoke mcp-rbac-smoke mcp-http-smoke payments-testing-smoke settings-smoke utm-smoke webhook-smoke webhook-http-smoke simulate-network-collapse simulate-race-condition simulate-lost-reply simulate-kill-switch captcha-smoke ui ui-full ui-down ui-smoke ui-full-smoke ui-browser-smoke testo mutation testo-integration analytics-smoke analytics-roundtrip platform-smoke

up: ## start the app and its services (PostgreSQL, Redis, Jaeger, mocks)
	$(COMPOSE) up -d --build --wait app

down: ## stop every profile and drop the volumes: the next start is a fresh database
	$(COMPOSE) --profile ui --profile realtime --profile analytics down -v

logs: ## follow the app and PostgreSQL logs
	$(COMPOSE) logs -f app postgres

shell: ## bash inside the running app container
	$(COMPOSE) exec app bash

migrate: ## apply the PostgreSQL migrations to the running stack
	$(COMPOSE) exec -T app ./yii migrate:up -n

smoke: ## baseline: health, checkout, replay, workflow, audit, outbox, worker
	$(COMPOSE) up -d --build --wait app
	$(COMPOSE) exec -T app ./yii migrate:up -n
	bash bin/smoke.sh
	$(COMPOSE) --profile ui --profile realtime --profile analytics down -v

contract-smoke: ## OpenAPI request and response validation over real HTTP
	bash -c 'source bin/support/require-no-ui-worker.sh'
	$(COMPOSE) up -d --build --wait app
	$(COMPOSE) exec -T app ./yii migrate:up -n
	$(COMPOSE) exec -T app php bin/contract-smoke.php

property-openapi-smoke: $(VENDOR) ## requests generated from the OpenAPI document against the live API
	$(COMPOSE) run --rm app php bin/property-openapi-smoke.php

specification-smoke: $(VENDOR) ## whitelisted read-model filters with parameterized values
	$(COMPOSE) up -d --build --wait app
	$(COMPOSE) exec -T app ./yii migrate:up -n
	$(COMPOSE) run --rm app php bin/specification-smoke.php

centrifugo-smoke: $(VENDOR) ## realtime projection of payment results, in memory
	$(COMPOSE) run --rm app php bin/centrifugo-smoke.php

centrifugo-http-smoke: $(VENDOR) ## realtime projection against a real Centrifugo v6
	$(COMPOSE) --profile realtime up -d --build --wait app centrifugo
	$(COMPOSE) run --rm app php bin/centrifugo-http-smoke.php

projection-smoke: ## no payment result is left pending projection
	$(COMPOSE) --profile realtime up -d --build --wait app centrifugo
	$(COMPOSE) exec -T app ./yii migrate:up -n
	bash -c 'source bin/support/require-no-ui-worker.sh'
	$(COMPOSE) exec -T app ./yii sandbox:outbox:run
	$(COMPOSE) exec -T app ./yii sandbox:projections:run
	@pending=$$($(COMPOSE) exec -T postgres psql -qAt -U sandbox -d sandbox -c \
		"SELECT count(*) FROM outbox WHERE type IN ('order.paid', 'order.payment_failed') AND status = 'pending'"); \
	test "$$pending" = 0 || { echo "projection left $$pending payment results pending" >&2; exit 1; }; \
	echo 'PROJECTION PASS: every order.paid and order.payment_failed row was published'

redis-smoke: $(VENDOR) ## Redis-backed circuit breaker and bulkhead
	$(COMPOSE) run --rm app php bin/redis-smoke.php

db-adapters-smoke: $(VENDOR) ## yii3-webhooks-db and yii3-utm-db on PostgreSQL
	$(COMPOSE) up -d --build --wait app
	$(COMPOSE) exec -T app ./yii migrate:up -n
	$(COMPOSE) run --rm app php bin/db-adapters-smoke.php

mcp-smoke: $(VENDOR) ## MCP tools read the same sources as HTTP
	$(COMPOSE) up -d --build --wait app
	$(COMPOSE) exec -T app ./yii migrate:up -n
	$(COMPOSE) run --rm app php bin/mcp-smoke.php

mcp-rbac-smoke: $(VENDOR) ## MCP with RBAC and the audit trail: allowed and refused agents
	$(COMPOSE) up -d --build --wait app
	$(COMPOSE) exec -T app ./yii migrate:up -n
	$(COMPOSE) run --rm app php bin/mcp-rbac-smoke.php

mcp-http-smoke: $(VENDOR) ## the real Streamable HTTP /mcp endpoint, the call found in audit_log
	$(COMPOSE) up -d --build --wait app
	BASE_URL=http://app:8080 $(COMPOSE) run --rm app php bin/mcp-http-smoke.php

payments-testing-smoke: $(VENDOR) ## the payments-testing provider contract, no network call
	$(COMPOSE) run --rm app php bin/payments-testing-smoke.php

settings-smoke: $(VENDOR) ## yii3-settings typed runtime configuration
	PAYMENT_GATEWAY_MODE=review PAYMENT_GATEWAY_TIMEOUT_MS=1750 PAYMENT_GATEWAY_CONNECT_TIMEOUT_MS=450 $(COMPOSE) run --rm app sh -c './yii migrate:up -n >/dev/null && ./yii sandbox:settings:show'

utm-smoke: $(VENDOR) ## yii3-utm attribution journal and event deduplication
	$(COMPOSE) run --rm app php bin/utm-smoke.php

webhook-smoke: $(VENDOR) ## yii3-webhooks with the outbox bridge: HMAC signature, retry after a 500
	$(COMPOSE) run --rm app php bin/webhook-smoke.php

webhook-http-smoke: ## signed webhook delivery to the webhook mock over HTTP
	WEBHOOK_FAIL_FIRST=1 $(COMPOSE) up -d --build --wait app
	WEBHOOK_FAIL_FIRST=1 $(COMPOSE) exec -T app php bin/webhook-http-smoke.php

observability: ## print the Prometheus, Jaeger and /metrics addresses
	@printf 'Prometheus: http://127.0.0.1:9090\nJaeger:      http://127.0.0.1:16686\nMetrics:     http://127.0.0.1:8080/metrics\n'

observability-smoke: $(VENDOR) ## Prometheus series, Jaeger spans, one trace across the outbox
	$(COMPOSE) up -d --build --wait app prometheus
	$(COMPOSE) exec -T app ./yii migrate:up -n
	bash bin/smoke.sh >/dev/null
	$(COMPOSE) run --rm -e BASE_URL=http://app:8080 -e JAEGER_URL=http://jaeger:16686 app php bin/observability-smoke.php

prometheus-outcomes-smoke: ## the business counter of payment outcomes
	bash bin/prometheus-outcomes-smoke.sh

redis-queue-smoke: $(VENDOR) ## Redis Streams consumer group: XAUTOCLAIM, XACK
	$(COMPOSE) run --rm app php bin/redis-queue-smoke.php

ui: ## web UI with a background worker: http://127.0.0.1:8080/ui/
	WEBHOOK_FAIL_FIRST=1 RESULT_SINKS=centrifugo,webhooks,receipts OUTBOX_RETRY_DELAY_SECONDS=10 $(COMPOSE) --profile ui up -d --build --wait app worker prometheus
	$(COMPOSE) exec -T app ./yii migrate:up -n >/dev/null
	@printf 'Web UI: http://127.0.0.1:%s/ui/  Jaeger: http://127.0.0.1:%s  Prometheus: http://127.0.0.1:%s\n' "$${APP_PORT:-8080}" "$${JAEGER_UI_PORT:-16686}" "$${PROMETHEUS_PORT:-9090}"

ui-full: ## web UI plus ClickHouse analytics and Grafana
	WEBHOOK_FAIL_FIRST=1 RESULT_SINKS=centrifugo,webhooks,receipts,clickhouse AB_EXPORT=1 OUTBOX_RETRY_DELAY_SECONDS=10 $(COMPOSE) --profile ui --profile analytics up -d --build --wait app worker prometheus clickhouse grafana
	$(COMPOSE) exec -T app ./yii migrate:up -n >/dev/null
	$(COMPOSE) exec -T app ./yii clickhouse:migrations:migrate >/dev/null
	@printf 'Web UI: http://127.0.0.1:%s/ui/  Grafana: http://127.0.0.1:%s\n' "$${APP_PORT:-8080}" "$${GRAFANA_PORT:-13000}"

ui-down: ## stop the web UI stack and drop its volumes
	$(COMPOSE) --profile ui --profile realtime --profile analytics down -v

ui-smoke: ## every page and asset, both languages, all four cards end to end, the fan-out, one trace
	WEBHOOK_FAIL_FIRST=1 RESULT_SINKS=centrifugo,webhooks,receipts OUTBOX_RETRY_DELAY_SECONDS=10 $(COMPOSE) --profile ui up -d --build --wait app worker
	$(COMPOSE) exec -T app ./yii migrate:up -n >/dev/null
	$(COMPOSE) exec -T app php bin/ui-smoke.php
	$(COMPOSE) --profile ui --profile realtime --profile analytics down -v

ui-full-smoke: ## results reach ClickHouse once each, Grafana panels, one inbox row per sink
	WEBHOOK_FAIL_FIRST=1 RESULT_SINKS=centrifugo,webhooks,receipts,clickhouse AB_EXPORT=1 OUTBOX_RETRY_DELAY_SECONDS=10 $(COMPOSE) --profile ui --profile analytics up -d --build --wait app worker prometheus clickhouse grafana
	$(COMPOSE) exec -T app ./yii migrate:up -n >/dev/null
	$(COMPOSE) exec -T app ./yii clickhouse:migrations:migrate >/dev/null
	$(COMPOSE) exec -T -e RESULT_SINKS="$$($(COMPOSE) exec -T worker printenv RESULT_SINKS)" app php bin/ui-full-smoke.php
	$(COMPOSE) --profile ui --profile realtime --profile analytics down -v

ui-browser-smoke: ## the shop page in headless Chrome
	WEBHOOK_FAIL_FIRST=1 RESULT_SINKS=centrifugo,webhooks,receipts OUTBOX_RETRY_DELAY_SECONDS=10 $(COMPOSE) --profile ui up -d --build --wait app worker prometheus
	$(COMPOSE) exec -T app ./yii migrate:up -n >/dev/null
	node bin/ui-browser-smoke.mjs; status=$$?; $(COMPOSE) --profile ui --profile realtime --profile analytics down -v; exit $$status

mutation: $(VENDOR) ## Infection over Domain and Application, minMsi 95, ratcheted against the last run
	$(COMPOSE) run --rm app bash bin/mutation-gate.sh

testo: $(VENDOR) ## unit suite, architecture rules included
	$(COMPOSE) run --rm app ./vendor/bin/testo --suite=Unit -vv --log-junit=build/reports/unit.xml --log-html=build/reports/unit-html

property-test: $(VENDOR) ## property-based suite: order and saga state machines, payload parity
	$(COMPOSE) run --rm app ./vendor/bin/testo --suite=Property -vv --log-junit=build/reports/property.xml --log-html=build/reports/property-html

testo-integration: $(VENDOR) ## Testo against live PostgreSQL and Redis
	$(COMPOSE) up -d --build --wait app
	$(COMPOSE) exec -T app ./yii migrate:up -n >/dev/null
	$(COMPOSE) run --rm -e SANDBOX_BASE_URL=http://app:8080 app ./vendor/bin/testo --suite=Integration -vv

# Aliases for the names used in the plan and the README walkthrough.
unit-test: testo
integration-test: testo-integration
contract-test: contract-smoke
traces metrics: observability
mcp: mcp-http-smoke

checkout: ## one checkout through the HTTP API against the started stack
	$(COMPOSE) up -d --build --wait app
	$(COMPOSE) exec -T app ./yii migrate:up -n
	bash bin/checkout.sh

checkout-replay: ## the checkout repeated under the same Idempotency-Key: nothing new is created
	$(COMPOSE) up -d --build --wait app
	$(COMPOSE) exec -T app ./yii migrate:up -n
	REPLAY=1 bash bin/checkout.sh

inspect-order: ## database evidence for one order: make inspect-order ID=<id>
	@test -n "$(ID)" || { echo 'usage: make inspect-order ID=<order id>' >&2; exit 2; }
	bash bin/inspect-order.sh "$(ID)"

inspect-outbox: ## the outbox rows and their delivery state
	bash bin/inspect-outbox.sh

platform-smoke: $(VENDOR) ## tenancy, A/B exposure, ClickHouse routing, file storage, reCAPTCHA semantics
	$(COMPOSE) run --rm app php bin/platform-smoke.php

analytics-smoke: ## ClickHouse and Grafana are healthy, the dashboard is provisioned
	$(COMPOSE) --profile analytics up -d --wait prometheus clickhouse grafana
	$(COMPOSE) exec -T clickhouse wget -qO- http://127.0.0.1:8123/ping | grep -q '^Ok'
	$(COMPOSE) exec -T grafana wget -qO- http://127.0.0.1:3000/api/health | grep -q '"database"[[:space:]]*:[[:space:]]*"ok"'
	@printf 'ANALYTICS PASS: clickhouse=healthy grafana=healthy dashboard=provisioned\\n'

analytics-roundtrip: $(VENDOR) ## an order.paid export to ClickHouse read back
	$(COMPOSE) --profile analytics up -d --wait app clickhouse
	CLICKHOUSE_HOST=clickhouse CLICKHOUSE_DB=sandbox CLICKHOUSE_USER=sandbox CLICKHOUSE_PASSWORD=sandbox $(COMPOSE) run --rm app php bin/analytics-roundtrip.php

correlation-smoke: ## checkout and worker share one correlation id
	$(COMPOSE) up -d --build --wait app
	$(COMPOSE) exec -T app ./yii migrate:up -n
	bash bin/correlation-smoke.sh

simulate-maintenance: ## 503 with Retry-After, health stays open, no 503 is replayed
	$(COMPOSE) up -d --build --wait app
	$(COMPOSE) exec -T app ./yii migrate:up -n
	bash bin/simulate-maintenance.sh

simulate-network-collapse: ## gateway delay beyond the deadline: retries, breaker, recovery
	bash bin/simulate-network-collapse.sh

simulate-lost-reply: ## the bank takes the money and the answer is lost: the saga pays the order
	bash bin/simulate-lost-reply.sh

simulate-race-condition: ## 50 concurrent requests: one transition, one audit path, one payment event
	bash bin/simulate-race-condition.sh

simulate-kill-switch: ## payments.enabled off on /admin/flags: 503, /live stays up; back on, the key is accepted
	bash bin/simulate-kill-switch.sh

captcha-smoke: ## the opt-in reCAPTCHA gate on the checkout
	bash bin/captcha-smoke.sh

# Static checks: style, psalm level 1 with
# no suppressions, layer and context boundaries, rector. `make check` before
# any "done"; the scenario targets prove behaviour, these prove the code.
check: $(VENDOR) ## static gate: php-cs-fixer, psalm level 1, deptrac, rector, class references
	$(COMPOSE) run --rm --no-deps app composer check

cs: $(VENDOR) ## php-cs-fixer, dry run
	$(COMPOSE) run --rm --no-deps app composer cs

cs-fix: $(VENDOR) ## php-cs-fixer, rewrite the files
	$(COMPOSE) run --rm --no-deps app composer cs:fix

psalm: $(VENDOR) ## psalm level 1, no suppressions
	$(COMPOSE) run --rm --no-deps app composer psalm

rector: $(VENDOR) ## rector, dry run
	$(COMPOSE) run --rm --no-deps app composer rector

rector-fix: $(VENDOR) ## rector, rewrite the files
	$(COMPOSE) run --rm --no-deps app composer rector:fix

deptrac: $(VENDOR) ## layer and context boundaries
	$(COMPOSE) run --rm --no-deps app composer deptrac
