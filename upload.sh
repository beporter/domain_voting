#!/usr/bin/env bash
# Wrapper to set env vars and push configs, db and voting script to an sftp server.

set -eo pipefail # Can't use -u for this script due to ${!INDIRECT} substitution.

SFTP_HOST="${1?'Provide the name of a configured ssh host as the first arg'}"

if [ -r './.env' ]; then
    echo "## Importing env vars."
    set -a; source './.env'; set +a;
else
    echo "## No .env file to import."
    exit 1
fi

if ! command -v envsubst &>/dev/null; then
	echo "!! The \`envsubst\` command is not installed. Can not continue."
	exit 2
fi

# If a tmp/${SFTP_HOST}_template.htaccess file exists, use it to generate tmp/${SFTP_HOST}.htaccess.
if [ -r "tmp/${SFTP_HOST}_template.htaccess" ]; then
	TEMPLATE_FILE="tmp/${SFTP_HOST}_template.htaccess"
	echo "## Using custom ${TEMPLATE_FILE} for .htaccess."

# Otherwise generate tmp/${SFTP_HOST}.htaccess from example.htaccess
else
	TEMPLATE_FILE="example.htaccess"
	echo "## Using bundled ${TEMPLATE_FILE} for .htaccess."
fi
DEST_FILE="tmp/${SFTP_HOST}.htaccess"

echo "## Populating ${DEST_FILE}."
export REMOTE_WEBROOT="${SFTP_HOST^^}_REMOTE_WEBROOT"
export PUBLIC_WEBROOT="${SFTP_HOST^^}_PUBLIC_WEBROOT"
export VOTING_DB_PATH="${!REMOTE_WEBROOT}/../voting.sqlite3"
export PREFIXES_ONELINE="$(echo "$PREFIXES" | tr '\n' '|')"
export SUFFIXES_ONELINE="$(echo "$SUFFIXES" | tr '\n' '|')"
export TLDS_ONELINE="$(echo "$TLDS" | tr '\n' '|')"

envsubst < "${TEMPLATE_FILE}" > "${DEST_FILE}"

# TODO: Repeat the above logic for example-nginx.conf and add a `-put` below.

echo "## Uploading files."
sftp -b - $SFTP_HOST <<-EOB
	lpwd
	lls -a

	@cd ${!REMOTE_WEBROOT}
	pwd
	ls -a

	-put tmp/${SFTP_HOST}.htaccess .htaccess
	-put tmp/${SFTP_HOST}.sqlite3 ../voting.sqlite3
	put voting.php
EOB

if [ -n "${!PUBLIC_WEBROOT}" ]; then
    echo "## Opening public URL."
    open "${!PUBLIC_WEBROOT}/voting.php?action=vote&super=y"
fi
