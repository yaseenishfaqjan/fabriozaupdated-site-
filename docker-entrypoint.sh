#!/bin/sh
# Prepare the session directory at CONTAINER START, not at image build.
#
# Why this file exists. PHP sessions were moved onto the /var/data volume so a
# deploy stops destroying them (recreating the container used to wipe /tmp,
# which logged the admin out and made an already-open login form fail with
# "Invalid CSRF token"). Creating the directory with RUN mkdir in the
# Dockerfile looks like it does the job and does not:
#
#   Docker seeds a volume from the image ONLY when that volume is EMPTY.
#   The production /var/data already holds fabrioza_crm.db, so nothing is
#   copied into it and /var/data/sessions simply never appears. PHP then
#   cannot open a session at all - session_start() fails and NOBODY can log
#   in. Verified against a volume pre-loaded with a database file: directory
#   absent, session write failed.
#
# A fresh-volume test passes in both cases, which is exactly how this would
# have reached production unnoticed.
set -e

mkdir -p /var/data/sessions
chown www-data:www-data /var/data/sessions
chmod 700 /var/data/sessions

# Hand back to the base image's own entrypoint so PHP extension setup and
# anything else it does still runs.
exec docker-php-entrypoint "$@"
