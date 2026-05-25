#!/bin/sh
set -e

# Always apply migrations. Safe to run repeatedly; local compose runs this as a
# no-op after the `init` service has already migrated, and on Railway this is
# what sets the database up.
php artisan migrate --force

# Seed only when the database is empty, so we never double-seed an existing one.
if [ "$(php artisan tinker --execute='echo \App\Models\Team::count();' 2>/dev/null | tail -n1)" = "0" ]; then
    php artisan db:seed --force
fi

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
