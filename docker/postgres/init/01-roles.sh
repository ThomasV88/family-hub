#!/bin/sh
# Creates the owner role (DDL) and the runtime role (DML only). Runs once on an empty data directory.
# Passwords come from files in /run/secrets if present, otherwise from FH_OWNER_PASSWORD / FH_APP_PASSWORD (dev only).
set -eu

if [ -r /run/secrets/db_owner_password ]; then
  OWNER_PW="$(cat /run/secrets/db_owner_password)"
else
  OWNER_PW="${FH_OWNER_PASSWORD:?FH_OWNER_PASSWORD or /run/secrets/db_owner_password required}"
fi
if [ -r /run/secrets/db_app_password ]; then
  APP_PW="$(cat /run/secrets/db_app_password)"
else
  APP_PW="${FH_APP_PASSWORD:?FH_APP_PASSWORD or /run/secrets/db_app_password required}"
fi

PSQL="psql -v ON_ERROR_STOP=1 --username $POSTGRES_USER"

$PSQL --dbname postgres -v owner_pw="$OWNER_PW" -v app_pw="$APP_PW" <<'SQL'
CREATE ROLE fh_owner LOGIN PASSWORD :'owner_pw';
CREATE ROLE fh_app LOGIN PASSWORD :'app_pw' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE DATABASE family_hub OWNER fh_owner;
SQL

DATABASES="family_hub"
if [ "${FH_CREATE_TEST_DB:-0}" = "1" ]; then
  $PSQL --dbname postgres -c 'CREATE DATABASE family_hub_test OWNER fh_owner'
  DATABASES="$DATABASES family_hub_test"
fi

for db in $DATABASES; do
  $PSQL --dbname "$db" <<SQL
REVOKE ALL ON DATABASE $db FROM PUBLIC;
GRANT CONNECT ON DATABASE $db TO fh_app;
GRANT USAGE ON SCHEMA public TO fh_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO fh_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO fh_app;
ALTER DEFAULT PRIVILEGES FOR ROLE fh_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO fh_app;
ALTER DEFAULT PRIVILEGES FOR ROLE fh_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO fh_app;
SQL
done
