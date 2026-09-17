#!/bin/bash
set -euo pipefail

# Only starts the local test processes. No credentials or production changes.
studio_project_dir="$(cd "$(dirname "$0")/.." && pwd)"
studio_php="$(command -v php)"
cd "$studio_project_dir"
studio_environment="$("$studio_php" artisan env --no-ansi)"
case "$studio_environment" in
  *'[local]'*) ;;
  *) printf '%s\n' 'Start geweigerd: deze helper is uitsluitend voor APP_ENV=local.' >&2; exit 1 ;;
esac
"$studio_php" artisan config:clear

if ! launchctl list nl.bbquality.pitboard-test >/dev/null 2>&1; then
  launchctl submit -l nl.bbquality.pitboard-test -p /bin/bash \
    -o "$studio_project_dir/storage/logs/local-server.out.log" \
    -e "$studio_project_dir/storage/logs/local-server.error.log" \
    -- /bin/bash -c 'cd "$1/public" && exec "$2" -d upload_max_filesize=12M -d post_max_size=50M -d max_execution_time=900 -S 127.0.0.1:8000 "$1/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"' studio-server "$studio_project_dir" "$studio_php"
fi
if ! launchctl list nl.bbquality.productstudio-worker >/dev/null 2>&1; then
  launchctl submit -l nl.bbquality.productstudio-worker -p /bin/bash \
    -o "$studio_project_dir/storage/logs/productstudio-worker.out.log" \
    -e "$studio_project_dir/storage/logs/productstudio-worker.error.log" \
    -- /bin/bash -c 'cd "$1" && exec "$2" artisan queue:work database --queue=product-content --sleep=2 --timeout=540 --tries=1 --max-time=3600' studio-worker "$studio_project_dir" "$studio_php"
fi
if ! launchctl list nl.bbquality.productstudio-images >/dev/null 2>&1; then
  launchctl submit -l nl.bbquality.productstudio-images -p /bin/bash \
    -o "$studio_project_dir/storage/logs/productstudio-images.out.log" \
    -e "$studio_project_dir/storage/logs/productstudio-images.error.log" \
    -- /bin/bash -c 'cd "$1" && exec "$2" artisan queue:work database --queue=images --sleep=2 --timeout=1200 --tries=1 --max-time=3600' studio-images "$studio_project_dir" "$studio_php"
fi
"$studio_php" artisan queue:restart
printf '%s\n' 'Productstudio: http://127.0.0.1:8000/ (lokale server, tekstworker en beeldworker)'
