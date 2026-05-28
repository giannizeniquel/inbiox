.PHONY: up down build logs bash migrate seed test

up:
	docker-compose up -d

down:
	docker-compose down

build:
	docker-compose build

logs:
	docker-compose logs -f

bash:
	docker exec -it inbiox_php bash

migrate:
	docker exec inbiox_php php bin/console doctrine:migrations:migrate

seed:
	docker exec inbiox_php php bin/console app:seed

test:
	docker exec inbiox_php php bin/phpunit