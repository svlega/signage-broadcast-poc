#!/bin/sh
set -e

# Regenerates bootstrap/cache/packages.php against the vendor/ actually
# present in *this* image (--no-dev). The .dockerignore keeps any
# host-generated copy of that file out of the build entirely — running
# this explicitly, every container start, is what makes that correct
# regardless of what state the image was built from.
php artisan package:discover --ansi

# Config caching is safe and worth it here (no env-dependent closures in
# config/*.php). Route caching is deliberately skipped: routes/web.php
# defines a couple of closure routes (the SPA catch-all, the welcome
# page) and `route:cache` hard-fails on any Closure-based route rather
# than silently degrading.
php artisan config:cache

# Deliberately NOT running `migrate` here. This entrypoint is shared by
# web, queue, and reverb, all of which start at roughly the same time —
# three containers racing to create the same `migrations` table against
# a cold database is a real failure, not a hypothetical one: it's
# exactly what happens if this line is added back. Migrations run
# exactly once, in the dedicated `migrate` service (see
# docker-compose.yml), which the others wait on via
# `condition: service_completed_successfully`.

exec "$@"
