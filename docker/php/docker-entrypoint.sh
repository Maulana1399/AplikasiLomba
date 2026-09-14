#!/bin/sh
set -e

# Hapus manifest provider lama (bisa terbawa dari volume/image versi
# sebelumnya). PackageManifest akan membangun ulang dari vendor saat boot.
rm -f \
    /var/www/match/bootstrap/cache/packages.php \
    /var/www/match/bootstrap/cache/services.php

# Direktori yang ditulis Laravel: sesuaikan kepemilikan terhadap volume.
chown -R www-data:www-data \
    /var/www/match/storage \
    /var/www/match/bootstrap/cache

# Pastikan file SQLite ada dan berwenang
touch /var/www/match/database/database.sqlite
touch /var/www/match/database-data/database.sqlite 2>/dev/null || true
chown -R www-data:www-data \
    /var/www/match/database/database.sqlite 2>/dev/null || true
chown -R www-data:www-data \
    /var/www/match/database-data 2>/dev/null || true
find /var/www/match/database -mindepth 1 -maxdepth 1 \
    ! -name migrations \
    -exec chown -R www-data:www-data {} + 2>/dev/null || true

# Jalankan migration
php artisan migrate --force

# Bersihkan cache konfigurasi agar .env.docker terbaca
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Lanjutkan ke perintah yang diminta (default: php-fpm).
exec "$@"
