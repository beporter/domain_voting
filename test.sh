#!/usr/bin/env bash
# Wrapper to set env vars before starting php built in server.
# Run this from the project root folder: `tmp/voting.sh`

set -eo pipefail

export VOTING_DB_PATH='./tmp/voting-test.sqlite3'
source .env

php -l webroot/voting.php

./phpstan.phar analyse --memory-limit=1G webroot/voting.php

./phpunit.phar VotingTest.php
