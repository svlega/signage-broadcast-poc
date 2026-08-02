#!/bin/sh
set -e

# One container running both processes is a deliberate PoC-scale
# simplification: php-fpm and nginx normally scale independently (and a
# crash in one wouldn't be noticed here without an external healthcheck
# watching both), but splitting them into separate containers means
# sharing the built /public/build assets between them — a shared volume
# or object storage a full production setup would add, but that a
# from-scratch PoC doesn't need to prove twice.
php-fpm -D

# pid in /tmp, not the default /run/nginx.pid: /run is a fresh tmpfs
# owned by root at container start, and this whole image runs as
# non-root www-data (see Dockerfile) — /tmp is the one path guaranteed
# writable regardless of how the container's mounted.
exec nginx -g 'daemon off; pid /tmp/nginx.pid;'
