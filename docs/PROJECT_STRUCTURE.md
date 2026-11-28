# Project structure and where to make changes

This repository is a Laravel application at its root. It uses Laravel's normal
folders rather than separate top-level `frontend/` and `backend/` applications.
The browser UI is rendered by Laravel Blade templates; CSS and JavaScript are
served from `public/`.

## Folder map

| Folder or file | What belongs here | Start here when you want to... |
|---|---|---|
| `app/Http/Controllers/` | Backend request handlers | Change what a web request does or what response it returns |
| `app/Http/Controllers/Auth/` | Login, registration and password handlers | Change authentication request behavior |
| `app/Http/Controllers/Admin/` | Admin-only request handlers | Change an admin page action |
| `app/Http/Middleware/` | Request checks applied before controllers | Change role, account-status or security-header checks |
| `app/Models/` | Database models and relationships | Change how application data is represented |
| `app/Policies/` | Authorization decisions | Change who may view or manage a resource |
| `app/Jobs/` | Work dispatched to Laravel's queue | Change background scan or fix processing |
| `app/Services/` | Reusable application logic | Change upload, report, scan or fix behavior |
| `app/Services/Scanner/` | PHP scanner and scan helpers | Change source scanning behavior |
| `app/Services/Fixer/` | Patch generation and LLM integrations | Change AI-assisted fix behavior |
| `routes/web.php` | URL-to-controller mappings and route names | Add or change a page/action URL |
| `resources/views/` | Blade templates (the frontend HTML) | Change page content or layout |
| `resources/views/layouts/` | Shared page layouts | Change common page structure |
| `resources/views/partials/` | Reusable Blade fragments | Change a shared UI fragment |
| `public/css/` and `public/js/` | Browser CSS and JavaScript | Change styling or client-side interactions |
| `resources/security/` | Security scanner rule data | Change the configured PHP security rules |
| `engine/semgrep_runner.py` | Optional Semgrep/Gitleaks adapter | Change the optional Python tool integration |
| `database/migrations/` | Database schema changes | Add or change a database table/column |
| `database/seeders/` and `database/factories/` | Initial and test data | Change seeded or generated records |
| `storage/app/private/` | Private runtime uploads | Investigate stored upload files; do not put source code here |
| `tests/Feature/` and `tests/Unit/` | Automated behavior checks | Add or update tests |
| `bootstrap/app.php` | Laravel startup and route-file registration | Change which route files or middleware Laravel loads |
| `config/` | Application settings | Change configurable application defaults |
| `public/index.php` | HTTP entry point | Normally do not edit for a feature |

`app/Policies/ProjectPolicy.php` defines project permissions. It does not process
uploaded files; upload handling lives in `UploadController` and `UploadService`.

## Frontend, backend and API

- **Frontend pages:** `resources/views/` contains Blade templates. The shared
  application layout is `resources/views/layouts/app.blade.php`.
- **Frontend assets:** `public/css/` and `public/js/` contain files downloaded by
  the browser. The layout references them with Laravel's `asset()` helper.
- **Backend:** `app/` contains request controllers, authorization, models,
  services and background jobs.
- **Routes:** `routes/web.php` currently defines the application's routes.
  `bootstrap/app.php` registers this file.
- **API:** there is currently no separate `routes/api.php` or API controller
  area configured. Some web handlers can return JSON when a client requests it,
  but that does not make this a separate REST API module. Add and register a
  dedicated API route file only if a separate API is actually needed.
- **Python:** `engine/semgrep_runner.py` is an optional adapter, not a separate
  worker application. The PHP scanner works without it.

## Example: trace an upload change

Use this path when changing how a project upload works:

1. `routes/web.php` maps the upload URL to `UploadController`.
2. `app/Http/Controllers/UploadController.php` authorizes the project, validates
   the request and chooses the browser redirect or JSON response.
3. `app/Services/UploadService.php` handles upload storage and file operations.
4. `app/Models/Upload.php` and the related database migration describe the saved
   upload record.
5. `resources/views/uploads/` contains the upload page shown to the user.
6. `tests/Feature/UploadTest.php` covers the upload behavior.

For scan processing, start at the scan routes/controller, then follow the queued
job in `app/Jobs/RunScan.php` into `app/Services/Scanner/`.

## Quick way to choose a file

- Changing **what URL runs**? Start in `routes/web.php`.
- Changing **what happens on a request**? Start in the matching controller.
- Changing **reusable business or file logic**? Start in `app/Services/`.
- Changing **who is allowed**? Start in middleware or `app/Policies/`.
- Changing **what the user sees**? Start in `resources/views/`.
- Changing **colors or browser interactions**? Start in `public/css/` or `public/js/`.
- Changing **database structure**? Add a migration under `database/migrations/`.
- Changing **expected behavior**? Update or add a test under `tests/`.
