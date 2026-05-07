#!/usr/bin/env bash
# Wrapper to set env vars before starting php built in server.
# Run this from the project root folder: `tmp/voting.sh`

set -eo pipefail

export VOTING_DB_PATH='./tmp/voting-test.sqlite3'
source .env

if ! command -v php &>/dev/null; then
    echo "!! Couldn't find php in PATH. Please install php."
    exit 1
fi

if [ ! -x ./tmp/phpstan.phar ]; then
    echo "## Downloading phpstan to ./tmp"
    wget https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar -O ./tmp/phpstan.phar
    chmod +x ./tmp/phpstan.phar
fi

if [ ! -x ./tmp/phpunit.phar ]; then
    echo "## Downloading phpunit to ./tmp"
    wget https://phar.phpunit.de/phpunit-13.1.8.phar -O ./tmp/phpunit.phar
    chmod +x ./tmp/phpunit.phar
fi

php -l ./voting.php

./tmp/phpstan.phar analyse --memory-limit=1G ./voting.php

./tmp/phpunit.phar VotingTest.php
