# Lynomia Cloud — build & operations entrypoints
# Every infrastructure target supports --check / --dry-run semantics.

SHELL := /bin/bash
.DEFAULT_GOAL := help

CP  := apps/control-plane
WEB := apps/web
COMPOSE := docker compose -f docker-compose.dev.yml

# ---------------------------------------------------------------- application

.PHONY: help
help: ## Show this help
	@grep -hE '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-24s\033[0m %s\n", $$1, $$2}'

.PHONY: bootstrap
bootstrap: ## Install all dependencies and prepare a fresh environment
	@bash scripts/bootstrap.sh

.PHONY: dev
dev: ## Start local development dependencies (Postgres, Redis, Mailpit)
	$(COMPOSE) up -d
	@bash scripts/wait-for-services.sh

.PHONY: dev-down
dev-down: ## Stop local development dependencies
	$(COMPOSE) down

.PHONY: dev-reset
dev-reset: ## Destroy and recreate local dev data volumes
	$(COMPOSE) down -v && $(COMPOSE) up -d && bash scripts/wait-for-services.sh

.PHONY: serve
serve: ## Run API + queue worker + frontend dev servers
	@bash scripts/serve.sh

.PHONY: migrate
migrate: ## Run database migrations
	cd $(CP) && php artisan migrate

.PHONY: fresh
fresh: ## Drop everything, migrate from empty DB and seed
	cd $(CP) && php artisan migrate:fresh --seed

.PHONY: test
test: test-backend test-frontend ## Run the full test suite

.PHONY: test-backend
test-backend: ## Run PHP tests (PHPUnit, against real PostgreSQL)
	cd $(CP) && php artisan test

.PHONY: test-frontend
test-frontend: ## Run frontend unit tests
	cd $(WEB) && npm run test -- --run

.PHONY: lint
lint: lint-backend lint-frontend ## Lint everything

.PHONY: lint-backend
lint-backend: ## Laravel Pint, plus PHPStan when its toolchain is installed
	cd $(CP) && ./vendor/bin/pint --test
	@# PHPStan lives in its own composer root so that a static-analysis upgrade can
	@# never move an application dependency. It is not part of `make bootstrap`
	@# because it is only needed by CI and by whoever is about to change types.
	@if [ -x "$(CP)/tools/phpstan/vendor/bin/phpstan" ]; then \
		cd $(CP) && tools/phpstan/vendor/bin/phpstan analyse --no-progress --memory-limit=1G; \
	else \
		echo "phpstan: not installed - run 'composer install --working-dir=$(CP)/tools/phpstan' to enable it"; \
	fi

.PHONY: fmt
fmt: ## Auto-format all code
	cd $(CP) && ./vendor/bin/pint
	cd $(WEB) && npm run format

.PHONY: lint-frontend
lint-frontend: ## ESLint + TypeScript typecheck
	cd $(WEB) && npm run lint && npm run typecheck

.PHONY: build
build: ## Production build of the frontend
	cd $(WEB) && npm run build

.PHONY: deploy-staging
deploy-staging: ## Deploy to staging via Ansible
	cd infrastructure/ansible && ansible-playbook -i inventories/staging playbooks/deploy-control-plane.yml

# ------------------------------------------------------------- infrastructure

.PHONY: infra-check
infra-check: ## Read-only preflight of all declared infrastructure (never mutates)
	cd infrastructure/ansible && ansible-playbook -i inventories/$(ENV) playbooks/preflight.yml --check --diff

.PHONY: infra-plan
infra-plan: ## OpenTofu plan (no apply)
	cd infrastructure/tofu && tofu plan

.PHONY: monitoring-deploy
monitoring-deploy: ## Deploy the observability stack
	cd infrastructure/ansible && ansible-playbook -i inventories/$(ENV) playbooks/monitoring.yml

.PHONY: monitoring-dev
monitoring-dev: ## Run the observability stack locally in Docker
	docker compose -f infrastructure/monitoring/docker-compose.monitoring.yml up -d

.PHONY: proxmox-postinstall
proxmox-postinstall: ## Post-install configuration for declared Proxmox nodes
	cd infrastructure/ansible && ansible-playbook -i inventories/$(ENV) playbooks/proxmox-postinstall.yml

.PHONY: pbs-configure
pbs-configure: ## Configure Proxmox Backup Server datastores, jobs and verification
	cd infrastructure/ansible && ansible-playbook -i inventories/$(ENV) playbooks/pbs-configure.yml

.PHONY: hosting-preflight
hosting-preflight: ## Validate hosting nodes before any panel installation
	cd infrastructure/ansible && ansible-playbook -i inventories/$(ENV) playbooks/hosting-preflight.yml --check --diff

ENV ?= development
