# Own It

A live training game on accountability. Each round shows a workplace excuse,
everyone rewrites it anonymously from their phone, and the room votes on the
best answer.

- Players open `/` and pick a nickname. No login.
- The host opens `/host` and enters the host key to run the rounds.
- The projector shows `/screen`: the same big view with no controls, plus a
  QR code for joining. Mirroring `/host` works too.
- Phones poll `/api/state` every 1.5 seconds, so there is nothing to run
  besides the web app and a database.

## Run it locally

    cp .env.example .env
    php artisan key:generate
    php artisan migrate
    php artisan serve

Set `HOST_KEY` in `.env` to whatever you want to type on `/host`.

## Deploy

Needs PHP 8.3+, any database Laravel supports, and two things set:

- `HOST_KEY` environment variable. With it empty, nobody can host.
- `php artisan migrate --force` as a deploy command.

The prompts a new game starts with are in `config/game.php`. The host can
also edit them from the lobby.

## Test

    php artisan test
