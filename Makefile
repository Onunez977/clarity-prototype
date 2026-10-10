# Makefile for Platform / Clarity (insight-lpr/platform)
#
# Local Docker build/run of the production FrankenPHP image, plus ECR + Kamal
# deploy helpers. Targeting + secrets are read from .env.kamal (see
# .env.kamal.example). For CI deploys see .github/workflows/kamal-deploy.yml.

.PHONY: help prototype up build run run-bg restart logs stop clean \
        kamal-install kamal-secrets kamal-logs kamal-shell \
        ecr-login kamal-registry-login kamal-build kamal-deploy kamal-redeploy \
        debug-env

# ── Config loading ──────────────────────────────────────────────────────────
# Source .env.kamal if present (provides AWS_*, KAMAL_*, and app secrets).
# Copy .env.kamal.example -> .env.kamal (local deploy) and fill it in.
ifneq (,$(wildcard .env.kamal))
include .env.kamal
export
endif

# AWS profile used for local ECR login + account lookup (override in .env.kamal).
AWS_PROFILE ?= gql-prod
AWS_REGION ?= us-east-2
AWS_ECR_REPOSITORY ?= insight-lpr/platform
IMAGE ?= $(AWS_ECR_REPOSITORY):latest

# Local-build inputs (BuildKit + composer auth secret + Vite args).
COMPOSER_AUTH_FILE ?= $(HOME)/.composer/auth.json
APP_PORT ?= 8080

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-22s\033[0m %s\n", $$1, $$2}'

prototype up: ## Boot the detached prototype (sample data, no production services)
	./scripts/prototype-up.sh

# ==============================================================================
# Local Docker (production image) — do not use for the prototype
# ==============================================================================

build: ## Build the production Docker image locally
	DOCKER_BUILDKIT=1 docker build \
		--secret id=composer_auth,src=$(COMPOSER_AUTH_FILE) \
		--build-arg VITE_GOOGLE_MAPS_API_KEY="$(VITE_GOOGLE_MAPS_API_KEY)" \
		--build-arg VITE_APP_NAME="$(APP_NAME)" \
		--build-arg VITE_APP_URL="$(APP_URL)" \
		-t $(IMAGE) .

run: build ## Run the image in the foreground (http on APP_PORT, default 8080)
	docker rm -f platform-app 2>/dev/null || true
	docker run --rm -it \
		--name platform-app \
		-p $(APP_PORT):80 \
		--env-file .env.kamal \
		$(IMAGE)

run-bg: build ## Run the image detached (background)
	docker rm -f platform-app 2>/dev/null || true
	docker run -d \
		--name platform-app \
		-p $(APP_PORT):80 \
		--env-file .env.kamal \
		$(IMAGE)
	@echo "✅ Container started as 'platform-app' on http://localhost:$(APP_PORT)"
	@echo "   make logs   # follow logs"
	@echo "   make stop   # stop it"

restart: stop run-bg ## Restart the local container in background

logs: ## Follow logs from the local container
	docker logs -f platform-app

stop: ## Stop and remove the local container
	docker rm -f platform-app 2>/dev/null || true
	@echo "Container platform-app stopped (if it was running)"

clean: ## Remove the locally built image
	docker rmi $(IMAGE) 2>/dev/null || true

# ==============================================================================
# ECR + Kamal
#
# Targeting (KAMAL_SERVICE, KAMAL_SERVER, KAMAL_PROXY_HOST, APP_URL, …) and
# secrets come from .env.kamal. ECR auth is done locally via AWS_PROFILE; the
# resulting token is passed to Kamal as KAMAL_REGISTRY_PASSWORD so .kamal/secrets
# does not have to re-run `aws` on every host.
# ==============================================================================

kamal-install: ## Install Kamal locally (requires Ruby >= 3.1)
	gem install kamal -v '~> 2.0'

kamal-secrets: ## Edit Kamal secrets
	kamal secrets edit

kamal-logs: ## Tail logs from the deployed service
	kamal logs -f

kamal-shell: ## Open a shell on the deployed container
	kamal app exec --interactive --reuse bash

ecr-login: ## Login to ECR locally using the AWS profile
	@echo "→ Logging into ECR locally (profile: $(AWS_PROFILE), region: $(AWS_REGION))..."
	@aws ecr get-login-password --profile $(AWS_PROFILE) --region $(AWS_REGION) \
		| docker login --username AWS --password-stdin \
		$(shell aws sts get-caller-identity --profile $(AWS_PROFILE) --query Account --output text).dkr.ecr.$(AWS_REGION).amazonaws.com

# Current ECR login token, evaluated lazily when a recipe runs.
ECR_PASSWORD = $(shell aws ecr get-login-password --profile $(AWS_PROFILE) --region $(AWS_REGION))

kamal-registry-login: ecr-login ## Log the remote server(s) into ECR
	@echo "→ Logging remote servers into ECR..."
	@set -a; . ./.env.kamal 2>/dev/null || true; set +a; \
	KAMAL_REGISTRY_PASSWORD="$(ECR_PASSWORD)" kamal registry login

kamal-build: ## Build + push the image to ECR (no deploy)
	@echo "→ Building and pushing image..."
	@set -a; . ./.env.kamal 2>/dev/null || true; set +a; \
	KAMAL_REGISTRY_PASSWORD="$(ECR_PASSWORD)" kamal build push

kamal-deploy: kamal-registry-login ## Full deploy (build, push, boot) using .env.kamal targeting
	@echo "→ Deploying with Kamal to $(KAMAL_PROXY_HOST)..."
	@set -a; . ./.env.kamal 2>/dev/null || true; set +a; \
	KAMAL_REGISTRY_PASSWORD="$(ECR_PASSWORD)" kamal deploy --verbose

kamal-redeploy: kamal-registry-login ## Redeploy the latest pushed image (skip rebuild)
	@set -a; . ./.env.kamal 2>/dev/null || true; set +a; \
	KAMAL_REGISTRY_PASSWORD="$(ECR_PASSWORD)" kamal redeploy --verbose

debug-env: ## Print the resolved deploy targeting
	@echo "AWS_PROFILE          = $(AWS_PROFILE)"
	@echo "AWS_REGION           = $(AWS_REGION)"
	@echo "AWS_ACCOUNT_ID       = $(AWS_ACCOUNT_ID)"
	@echo "AWS_ECR_REPOSITORY   = $(AWS_ECR_REPOSITORY)"
	@echo "KAMAL_SERVICE        = $(KAMAL_SERVICE)"
	@echo "KAMAL_SERVER         = $(KAMAL_SERVER)"
	@echo "KAMAL_PROXY_HOST     = $(KAMAL_PROXY_HOST)"
	@echo "APP_URL              = $(APP_URL)"
