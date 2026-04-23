#!/usr/bin/env bash
# Wrapper to set env vars and push configs, db and voting script to an sftp server.

set -eo pipefail

# TODO: Accept an ssh config name to upload as command line args.
SFTP_HOST=$1
REMOTE_WEBROOT=''
PUBLIC_WEBROOT=''

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

open "${PUBLIC_WEBROOT}/voting.php?action=vote&super=y"
