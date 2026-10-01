# GHabits

A small private habit tracker for basic PHP shared hosting. It uses PHP sessions, a single app password, and SQLite storage.

## Requirements

- PHP 8.1 or newer
- PDO SQLite enabled (`pdo_sqlite`)
- Apache or compatible hosting that can run PHP in a subfolder

## Deploy

Upload these files to `public_html/ghabits` or the equivalent folder for `https://gmilic.com/ghabits`.

Open the URL once and create the single password. The app will create `data/ghabits.sqlite` automatically.

## Notes

- The interface is inspired by Loop Habit Tracker, but this is a separate PHP/SQLite implementation.
- Keep the `data/.htaccess` file in place so the SQLite database is not web-accessible on Apache hosting.
- Use the `EX` button to download a JSON backup.

