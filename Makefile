SHELL := /bin/sh

.PHONY: fixture up down setup install seed-buyer build-admin battle-test logs test analyse

fixture:
	./docker/registry/build-fixture.sh

up: fixture
	docker compose up -d
	sh ./docker/wait-for-dockware.sh

down:
	docker compose down

setup: up install seed-buyer build-admin

install:
	docker compose exec -T buyer bash -lc "bin/console plugin:refresh && bin/console plugin:install --activate ExtensionMesh"
	docker compose exec -T seller bash -lc "bin/console plugin:refresh && bin/console plugin:install --activate ExtensionMesh"

seed-buyer:
	docker compose exec -T buyer bin/console plugin:zip-import /extension-mesh-registry/AcmeDemoPlugin-1.0.0.zip
	docker compose exec -T buyer bin/console plugin:install --activate AcmeDemoPlugin

build-admin:
	@set -e; \
	docker compose exec -T buyer bash -lc "bin/build-administration.sh" & buyer_pid=$$!; \
	docker compose exec -T seller bash -lc "bin/build-administration.sh" & seller_pid=$$!; \
	wait $$buyer_pid; buyer_status=$$?; \
	wait $$seller_pid; seller_status=$$?; \
	exit $$((buyer_status || seller_status))

battle-test: fixture
	docker compose exec -T buyer bin/console cache:clear --no-ansi
	docker compose exec -T seller bin/console cache:clear --no-ansi
	sh ./docker/test-interactions.sh
	sh ./docker/test-paid-entitlements.sh
	sh ./docker/test-repository-onboarding.sh

logs:
	docker compose logs -f buyer seller registry

test:
	vendor/bin/phpunit -c phpunit.xml.dist

analyse:
	vendor/bin/phpstan analyse --configuration=phpstan.neon --no-progress --memory-limit=1G
