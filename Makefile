env:
	@test -f .env || cp .env.example .env

certs:
	./scripts/gen-certs.sh

tenant-cert:
	@if [ -z "$(host)" ]; then \
		echo "Usage: make tenant-cert host=<tenant-host> [extra=\"alt1 alt2\"]" >&2; \
		exit 1; \
	fi
	./scripts/gencerts.sh $(host) $(extra)

up:
	docker compose up --build

down:
	docker compose down -v

logs:
	docker compose logs -f app

sh:
	docker compose exec app /bin/bash

test:
	docker compose exec -e APP_ENV=test -e APP_DEBUG=1 -e SYMFONY_DEPRECATIONS_HELPER=weak app ./vendor/bin/simple-phpunit

fmt:
	docker compose exec app ./vendor/bin/phpcbf

lint:
	docker compose exec app ./vendor/bin/phpcs
