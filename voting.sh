#!/usr/bin/env bash
# Wrapper to set env vars before starting php built in server.
# Run this from the project root folder: `./voting.sh`

set -eo pipefail

export VOTING_DB_PATH='./tmp/voting.sqlite3'
export PORKBUN_API_TOKEN=''
export PORKBUN_SECRET_API_TOKEN=''
# TODO: Replace above with `source .env`

./test.sh

echo 'open http://localhost:8000/voting.php in your browser.'
php -S localhost:8000 -t ./
