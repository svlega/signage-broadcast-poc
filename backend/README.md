# Signage Broadcast Engine (backend)

Laravel API + Reverb broadcasting. See the [repo root README](../README.md)
for architecture rationale, setup, and how this fits with the frontend.

Quick start:

```bash
composer install
cp .env.example .env && php artisan key:generate
# fill in REVERB_APP_ID / REVERB_APP_KEY / REVERB_APP_SECRET
php artisan migrate --seed
php artisan serve
php artisan reverb:start   # separate terminal
```
