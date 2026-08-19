.PHONY: up down build logs shell migrate fresh lectura-up lectura-down lectura-logs prod staging staging-logs staging-down

up:
	docker compose up -d --build

down:
	docker compose down

build:
	docker compose build

logs:
	docker compose logs -f

shell:
	docker compose exec api sh

migrate:
	docker compose exec api php artisan migrate

fresh:
	docker compose exec api php artisan migrate:fresh --seed

prod:
	docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

staging:
	docker compose -f docker-compose.yml -f docker-compose.staging.yml up -d --build

staging-integrations:
	docker compose -f docker-compose.yml -f docker-compose.staging.yml --profile integrations up -d --build

staging-logs:
	docker compose -f docker-compose.yml -f docker-compose.staging.yml logs -f

staging-down:
	docker compose -f docker-compose.yml -f docker-compose.staging.yml down

lectura-up:
	docker compose -f docker-compose.lectura.yml up -d

lectura-down:
	docker compose -f docker-compose.lectura.yml down

lectura-logs:
	docker compose -f docker-compose.lectura.yml logs -f
