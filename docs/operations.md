# Application operations

## Application

Use `/up` as the external web-process health endpoint. It checks local database and cache connectivity only. Run production with `APP_DEBUG=false`.

## Scheduler and queue

Trigger `php artisan schedule:run` every minute and run one or more persistent `php artisan queue:work` processes. Administrators can inspect scheduler and worker heartbeats, queue backlog, and safe failed-job metadata at `/admin/operations`.

For a future multi-node deployment, review scheduled events for Laravel's `onOneServer()` protection as part of the production HA design.

## Failed jobs

Shell administrators can inspect and manage failures deliberately:

```bash
php artisan queue:failed
php artisan queue:retry <id>
php artisan queue:forget <id>
```

## Secrets

Provide WHM tokens through the administrator workflow. Never commit credentials to Git or copy tokens, authorization headers, or raw remote responses into logs.
