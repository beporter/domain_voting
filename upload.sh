#!/usr/bin/env bash
# Wrapper to set env vars and push configs, db and voting script to an sftp server.

set -eo pipefail

SFTP_HOST="${1?'Provide the name of a configured ssh host as the first arg'}"

if [ -r './.env' ]; then
    echo "## Importing env vars."
    source .env
else
    echo "## No .env file to import."
    exit 1
fi

echo "## Uploading files."
sftp -b - $SFTP_HOST <<-EOB
	lpwd
	lls -a

	cd $REMOTE_WEBROOT
	pwd
	ls -a

	put tmp/${SFTP_HOST}.htaccess
	put tmp/${SFTP_HOST}.sqlite3 ../voting.sqlite3
	put voting.php
EOB

if [ -n "${PUBLIC_WEBROOT}" ]; then
    echo "## Opening public URL."
    open "${PUBLIC_WEBROOT}/voting.php?action=vote&super=y"
fi
