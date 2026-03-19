.PHONY: up down setup test lint phpunit update-snapshots build composer-install test-docker lint-docker phpunit-docker

WP_VERSION ?= latest

up:
	docker compose up -d --wait

down:
	docker compose down

setup: up composer-install
	bash scripts/wp-test-setup.sh

build:
	npm ci && npm run build

# Run tests in Docker (recommended if composer/php not installed locally)
test-docker: up composer-install
	docker compose exec -T cli bash -c "cd /var/www/html/wp-content/plugins/kratt && vendor/bin/phpstan analyse --no-progress"
	docker compose exec -T cli bash -c "cd /var/www/html/wp-content/plugins/kratt && vendor/bin/phpcs"
	docker compose exec -T cli bash -c "cd /var/www/html/wp-content/plugins/kratt && bash bin/install-wp-tests.sh wordpress_test wordpress wordpress db $(WP_VERSION)"
	docker compose exec -T cli bash -c "cd /var/www/html/wp-content/plugins/kratt && vendor/bin/phpunit"

lint-docker: up composer-install
	docker compose exec -T cli bash -c "cd /var/www/html/wp-content/plugins/kratt && vendor/bin/phpstan analyse --no-progress"
	docker compose exec -T cli bash -c "cd /var/www/html/wp-content/plugins/kratt && vendor/bin/phpcs"

phpunit-docker: up composer-install
	docker compose exec -T cli bash -c "cd /var/www/html/wp-content/plugins/kratt && bash bin/install-wp-tests.sh wordpress_test wordpress wordpress db $(WP_VERSION)"
	docker compose exec -T cli bash -c "cd /var/www/html/wp-content/plugins/kratt && vendor/bin/phpunit"

composer-install:
	docker compose run --rm composer install --no-interaction

# Run tests locally (requires composer and php installed)
test:
	composer install --no-interaction
	vendor/bin/phpstan analyse --no-progress
	vendor/bin/phpcs
	vendor/bin/phpunit

lint:
	vendor/bin/phpstan analyse --no-progress
	vendor/bin/phpcs

phpunit:
	vendor/bin/phpunit
