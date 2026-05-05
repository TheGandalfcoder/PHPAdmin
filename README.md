# PHP processing example

This small example demonstrates centralizing all server-side processing in a separate PHP file (`processor.php`) while keeping the UI in `main.php`.

Quick start (macOS):

```bash

php -S localhost:8000
```

Open `http://localhost:8000/main.php` in your browser.

Notes:
- All form submissions post to `processor.php`.
- `processor.php` returns JSON when the request includes `Accept: application/json`.
- Add or modify the `$fields` array inside `processor.php` to process additional form inputs.
- Secure and harden further for production (CSRF, input validation, output escaping, database prepared statements, rate-limiting).
