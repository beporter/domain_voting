#!/usr/bin/env bash
# Wrapper to set env vars before starting php built in server.
# Run this from the project root folder: `./voting.sh`

set -eo pipefail

if [ -r './.env' ]; then
    echo "## Importing env vars."
    source .env
else
    echo "## No .env file to import."
    #cp example.env .env
fi

echo "## Running tests."
./test.sh

echo "## Starting local web server."
echo '## open http://localhost:8000/voting.php in your browser.'
php -S localhost:8000 -t ./
