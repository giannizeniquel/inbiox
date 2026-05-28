# 🧠 1. Estrategia general (decisión clave)

**Arquitectura local recomendada:**

* **Código dentro de WSL2 (`/home/...`)**
* **Backend Symfony en Docker (PHP-FPM + Nginx)**
* **Servicios en Docker** (MySQL, Redis, Mercure, Mailhog)
* **Frontend Angular nativo en WSL** (mejor DX)

👉 Resultado:

* entorno consistente (sin “funciona en mi máquina”)
* buena performance
* fácil evolución a producción

---

# 🧱 2. Estructura del proyecto

```bash id="proj_struct"
project/
├── docker/
│   ├── php/
│   │   └── Dockerfile
│   └── nginx/
│       └── default.conf
├── docker-compose.yml
├── Makefile
├── backend/ (Symfony)
├── frontend/ (Angular)
└── docs/
    └── architecture/
```

---

# 🐳 3. docker-compose.yml (base productiva)

```yaml id="compose_final"
version: "3.9"

services:
  php:
    build:
      context: ./docker/php
    container_name: chatapp_php
    volumes:
      - ./backend:/var/www/html
    working_dir: /var/www/html
    depends_on:
      - db
      - redis

  nginx:
    image: nginx:alpine
    container_name: chatapp_nginx
    ports:
      - "8000:80"
    volumes:
      - ./backend:/var/www/html
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf
    depends_on:
      - php

  db:
    image: mysql:8.0
    container_name: chatapp_db
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: chatapp
      MYSQL_USER: app
      MYSQL_PASSWORD: app
    ports:
      - "3306:3306"
    volumes:
      - db_data:/var/lib/mysql

  redis:
    image: redis:7
    container_name: chatapp_redis
    ports:
      - "6379:6379"

  mercure:
    image: dunglas/mercure
    container_name: chatapp_mercure
    environment:
      SERVER_NAME: ":80"
      MERCURE_PUBLISHER_JWT_KEY: "supersecret"
      MERCURE_SUBSCRIBER_JWT_KEY: "supersecret"
      MERCURE_EXTRA_DIRECTIVES: |
        cors_origins http://localhost:4200
        anonymous
    ports:
      - "3000:80"

  mailhog:
    image: mailhog/mailhog
    container_name: chatapp_mailhog
    ports:
      - "8025:8025"

  adminer:
    image: adminer
    container_name: chatapp_adminer
    ports:
      - "8080:8080"

volumes:
  db_data:
```

---

# 🧱 4. Dockerfile PHP

```dockerfile id="php_dockerfile"
FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git unzip libicu-dev libzip-dev \
    && docker-php-ext-install intl pdo pdo_mysql zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
```

---

# 🌐 5. Nginx config

```nginx id="nginx_conf"
server {
    listen 80;
    root /var/www/html/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass php:9000;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
    }
}
```

---

# ⚙️ 6. Configuración Symfony

## `.env.local`

```env id="env_final"
DATABASE_URL="mysql://app:app@db:3306/chatapp?serverVersion=8.0"

REDIS_URL=redis://redis:6379

MERCURE_URL=http://mercure/.well-known/mercure
MERCURE_PUBLIC_URL=http://localhost:3000/.well-known/mercure
MERCURE_JWT_SECRET=supersecret
```

---

# ⚡ 7. Makefile (clave para DX)

```makefile id="makefile_final"
up:
	docker-compose up -d

down:
	docker-compose down

build:
	docker-compose build

logs:
	docker-compose logs -f

bash:
	docker exec -it chatapp_php bash

migrate:
	docker exec chatapp_php php bin/console doctrine:migrations:migrate

seed:
	docker exec chatapp_php php bin/console app:seed

test:
	docker exec chatapp_php php bin/phpunit
```

---

# 🧠 8. Angular (frontend)

Ejecutar en WSL:

```bash id="angular_cmds"
cd frontend
npm install
ng serve
```

👉 URL: `http://localhost:4200`
👉 API: `http://localhost:8000`

---

# 📡 9. Webhooks (WhatsApp)

## Recomendado

* **Cloudflare Tunnel**

```bash id="cf_tunnel"
cloudflared tunnel --url http://localhost:8000
```

👉 endpoint público estable para webhooks

---

# 🔄 10. Flujo de desarrollo

1. `make up`
2. Crear proyecto Symfony
3. Configurar `.env.local`
4. Migraciones:

   ```bash
   make migrate
   ```
5. Levantar Angular
6. Conectar webhook

---

# 🤖 11. Trabajo con IA (OpenCode)

## Estructura recomendada

```bash id="ai_docs"
/docs/architecture/
  - domain.md
  - decisions.md
  - prompts.md
```

---

## Workflow

1. Definís modelo (vos)
2. IA genera:

   * entidades
   * migrations
3. Vos validás
4. IA ayuda con:

   * queries
   * tests

---

# ⚠️ 12. Buenas prácticas clave

## 📁 Ubicación

* SIEMPRE en `/home` (WSL)
* NUNCA en `/mnt/c`

---

## ⚡ Performance

* evitar demasiados mounts
* usar volúmenes solo donde importa

---

## 🔐 Config

* `.env.local` para dev
* `.env.test` para tests

---

## 📊 Logs

* usar Monolog en JSON
* facilita debugging

---

# 🧪 13. Testing mínimo

* PHPUnit
* Postman / Bruno
* logs claros

---

# 🚀 14. Evolución futura

Este setup permite:

* agregar workers (Symfony Messenger)
* separar servicios
* migrar a AWS sin fricción
* escalar Redis / DB

---

# 🎯 Conclusión

Este entorno:

✔ consistente (Docker backend)
✔ rápido (Angular nativo)
✔ cercano a producción
✔ optimizado para WSL2
✔ listo para trabajar con IA

👉 Es un setup profesional, no solo de prueba.
