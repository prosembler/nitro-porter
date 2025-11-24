#!/usr/bin/env bash

# New ENV?
ENV_FILE=./.env
if [ ! -f "$ENV_FILE" ]; then
    echo "No .env file found. Copying .env.docker."
    cp .env.docker .env
fi

# New config?
CONF_FILE=./config.php
if [ ! -f "$CONF_FILE" ]; then
    echo "No config.php file found. Copying config-sample.php."
    cp config-sample.php config.php
fi

# Build containers.
export UID
export GID="$(id -g)"
docker compose up --build -d

# Setup app.
docker exec -it porter-php sh -c "composer install"

# Enter workspace.
docker exec -it porter-php bash
