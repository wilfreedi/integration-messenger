up:
	docker compose up --build -d

down:
	docker compose down

logs:
	docker compose logs -f caddy app postgres telegram-gateway

test:
	php tests/run.php

smoke:
	docker compose exec app php scripts/smoke_test.php

state:
	docker compose exec app php scripts/inspect_state.php

tg-test:
	cd services/telegram-gateway && PYTHONPATH=. python tests/run.py

tg-health:
	curl http://127.0.0.1:8090/health

reset:
	docker compose exec app php scripts/reset_database.php
	docker compose exec app php scripts/seed_demo.php
