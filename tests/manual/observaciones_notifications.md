# Observaciones Notification Checks

These scripts help validate that unread observation notifications are delivered both through the new
Server‑Sent Events stream and the legacy polling endpoint.

## Prerequisites

1. The application server must be running and accessible (e.g. `php -S 127.0.0.1:8000 -t public`).
2. Create at least one desembarque record with associated observations for both a staff user and a
   client user so unread activity can be generated.
3. Open a browser session and sign in as either a staff (`admin`/`usuario`) or client (`cliente`) user to
   obtain the session cookies required by the API endpoints.

## Watching the SSE stream

1. Export your authenticated cookies to a Netscape cookie jar (most browsers offer an extension, or you
   can rely on `curl` after authenticating once):

   ```bash
   curl -c cookies.txt -b cookies.txt "http://127.0.0.1:8000/login.php" \
        --data "email=USER_EMAIL&password=USER_PASSWORD"
   ```

2. Start the observation stream while authenticated:

   ```bash
   curl -N -b cookies.txt -H "Accept: text/event-stream" \
        "http://127.0.0.1:8000/api/desembarques/observaciones_stream.php"
   ```

   Keep this terminal open—new unread activity will appear as `event: unread` messages.

3. From another browser tab (or via the API) create an observation on the same record using a different
   actor. You should see a new payload appear on the SSE stream within a few seconds, followed by the
   badge/toast updates in the UI.

## Validating unread state via CLI

The helper script `tests/manual/observaciones_notifications.php` wraps the shared unread-query logic so
you can confirm results for any user.

Run it after creating an observation as staff:

```bash
php tests/manual/observaciones_notifications.php \
    --role=usuario \
    --id=STAFF_USER_ID \
    --name="Staff Name" \
    --email=staff@example.com
```

Then run it with the client’s details to ensure the same observation is visible to the recipient:

```bash
php tests/manual/observaciones_notifications.php \
    --role=cliente \
    --id=CLIENT_USER_ID \
    --name="Client Name" \
    --email=client@example.com
```

The resulting JSON lists any unread desembarques. After opening the observation modal in the UI (which
marks items as read), rerun the commands—the arrays should be empty, confirming both staff and client
notifications are synchronized with the session state.

These manual checks cover both delivery mechanisms and the read-tracking behaviour that feeds the
UI badges and toast messages.
