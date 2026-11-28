# PROMPT FOR THE NEXT AI (copy everything below this line into a new chat, and attach nexapatch-ai-complete.zip)

You are a senior Laravel/PHP and application-security engineer. Continue building the project in the attached ZIP: **NexaPatch AI**.
Read `README.md`, `HANDOFF.md`, `CHANGES.md` and `docs/NexaPatch_AI_SRS_v2.docx` first. Do not rewrite what already works.

## Product (what the owner wants)
A professional SaaS web app for software houses whose developers use ChatGPT-style tools and ship insecure code.
1. A company signs up / logs in, creates a project and uploads a ZIP (or one file) of its code.
2. The app analyses the code, shows vulnerabilities (with severity, file, line, explanation, fix advice) and creates a **PDF report**.   <- DONE
3. If the user asks, the app **fixes the vulnerabilities without changing the program's logic** and returns a **fixed ZIP**.   <- YOUR MAIN TASK
4. Security tools such as web shells ("php2shell" style), SQL injection, XSS, command injection, hard-coded secrets, weak crypto must be detected. Costs must stay at zero: any AI model used must be FREE tier (Gemini free API, Groq, OpenRouter free models, or local Ollama). Slow is acceptable; hitting token limits is not.

## Stack (already in place, keep it)
Laravel 12 (PHP 8.2+, tested with PHP 8.5), Blade + Bootstrap 5 + vanilla JS, MySQL (SQLite in tests), dompdf for PDF. The scanner is pure PHP (50 JSON rules in `resources/security/php_rules.json`). Developer machine: Windows, XAMPP MySQL, PHP in `C:\php`, Composer as `php composer.phar`. The assistant that wrote the code had NO PHP runtime: all code was only syntax-checked. **First run `php artisan test` and fix any failure before adding features.**

## Already done (do not redo)
- Auth (register/login/lockout/reset), roles admin/developer/reviewer, projects, reviewer assignment, audit log, admin users page.
- Safe upload (`App\Services\UploadService`): zip-slip/symlink/zip-bomb defences, SHA-256, immutable originals, retention purge command.
- Scanner (`App\Services\Scanner\*`, `App\Jobs\RunScan`), OSV dependency check, score/grade, findings pages, filters, review status, PDF report (`ReportService`), dashboard.
- Day 4 GROUNDWORK (written, unit-tested only, NOT wired to any route or screen):
  `app/Services/Fixer/LineFixers.php` (deterministic one-line fixers for ~20 rules), `LlmClient/GeminiClient/OpenAiClient/LlmFactory`, `AiFixer.php` (strict JSON edit proposal), models `FixRun` and `Patch` (with `edit` JSON, `method`, `fix_run_id`), migration `2026_11_15_000001_add_fix_runs.php`, config `nexapatch.llm` and `nexapatch.fix`, `UploadService::relativeFor()`, `Scan::fixRuns()`.

## TASK A (highest priority): finish automatic fixing end to end
Design (follow it; it respects the SRS policies: original never overwritten, minimal change, verification, human approval):
1. `App\Services\Fixer\FixPolicy::check(...)` verifies ONE proposed edit: (a) policy: changed lines only within +-1 of the finding line, at most 12 changed lines, no new masked "****", added lines contain no secret values; (b) syntax: for php/phtml/inc use `token_get_all($code, TOKEN_PARSE)` (before parses and after must parse); json via json_decode; others "unavailable"; (c) rescan: run `StaticScanner::scanContent()` on whole file before and after: the target rule's count must drop and no other rule's count may rise. Store each result as a `verification_results` row (check_type policy|syntax|rescan, result pass|fail|unavailable).
2. `App\Services\Fixer\FixEngine::propose(FixRun $run)`: load the scan's open findings with a line number, group by file, work on a COPY of the lines (never the stored original), process findings from the LAST line to the first so line numbers stay valid. For each finding: try `LineFixers::fix()` (method "rules"); if null and `use_ai` is true and an LLM is configured, call `AiFixer::propose()` (method "ai") with a delay of `LLM_DELAY_MS` between calls, at most `LLM_MAX_CALLS` per run, stop using AI for the rest of the run on `LlmUnavailable` (rate limit) and say so in the summary. Build the candidate lines, run `FixPolicy`; create a `Patch` row (status `verified` if all required checks pass, else `unverified`; never apply unverified edits). Patch `edit` JSON = `{file,start,remove_count,before_sha,before_display[],add[],rule}` (store a SHA-1 of the removed lines and a REDACTED display copy, never the raw secret). Findings without a line (e.g. DEP-OSV-001) and findings no fixer can handle go into `fix_runs.summary.items` as skipped with a human reason or manual step (e.g. "composer update vendor/pkg"). Set `fix_runs.status = ready`.
3. `FixEngine::build(FixRun $run, array $approvedPatchIds, User $user)`: apply approved patches per file in descending `start` order, verifying `before_sha` first (conflict -> skip and report). If the upload is a ZIP, rebuild the ZIP from `original.zip`: copy every safe entry (use `UploadService::relativeFor()` to match files), replacing changed files; for a single-file upload zip just that file. Add `NEXAPATCH-CHANGES.md` (every change: file, line, rule, method, note, manual steps) and, when secrets were moved (rules in `LineFixers::SECRET_RULES`), a private file `NEXAPATCH_SECRETS_TO_MOVE.nexapatch` with `NAME=value` lines recomputed from the original line at build time (never stored in the database) plus a warning to rotate those secrets. Save ZIP + SHA-256 in `fix_runs`, mark patches approved/rejected and create `reviews` rows, audit-log everything.
4. Verification of the result: store the fixed ZIP as a new `uploads` row (kind `patched`, through `UploadService::store()` with `new UploadedFile($zip, name, 'application/zip', null, true)`), run `RunScan::dispatchSync()` on it and save `patched_upload_id`, `after_scan_id` and the before/after score in the summary. Show "before 40 (F) -> after 85 (B)" on the fix page.
5. `App\Jobs\RunFix` (same sync/queue pattern as `RunScan`), `FixController` (store, show, build, download) with routes `scans.fixes.store`, `fixes.show`, `fixes.build`, `fixes.download`; authorization = `manageUploads` for store/build, `view` for show/download.
6. UI: on `resources/views/scans/show.blade.php` replace the disabled "Fix issues and download ZIP" button with a form (checkbox "Use AI for the remaining issues" only when `LlmFactory::isConfigured()`, with a privacy note: only a few masked lines around each issue are sent). New `resources/views/fixes/show.blade.php`: counters (applied/skipped/failed), table of patches with diff (red/green), checkboxes (only verified patches, preselected), manual steps list, "Approve selected and build ZIP", then Download ZIP.
7. More deterministic fixers are welcome and cheap (no tokens): SQL injection with simple `$_GET` concatenation -> prepared statements only when the query shape is simple, redirect whitelist, `LIBXML_NOENT` removal, CSRF token helper. Keep the rule: if unsure, do NOT change code; explain instead.
8. Tests (`tests/Feature/FixTest.php`): propose+build on an upload with XSS/phpinfo/cookie/secret/backdoor lines; ZIP contents; before/after score improves; conflict handling; permissions (other developer 403, reviewer cannot build); LLM calls with `Http::fake()` for both providers including a 429 that must stop AI use; no secret value in DB rows or in `NEXAPATCH-CHANGES.md`.

## TASK B: product polish
- Add a "Fix" status/badge on findings fixed in the patched scan; scan comparison view (before vs after).
- Reviewer workflow: pending-approval list on the dashboard for reviewers; approve/reject comments.
- Admin: scan/job monitor page (queue length, failed jobs), settings page for upload limits, user enable/disable and password reset by admin.
- Add an optional Python adapter `engine/semgrep_runner.py` (Semgrep + Gitleaks JSON -> same finding format) that is used only if the tools are installed; the PHP scanner must keep working alone.
- More rules in `php_rules.json` (Laravel-specific: `DB::raw` with request data, mass assignment `$guarded = []`, `APP_DEBUG`, `Storage::` path traversal, `Blade {!! !!}` with request data) and JavaScript/Node later.
- Production: Dockerfile with nginx + php-fpm + queue worker + scheduler, `SCAN_MODE=queue`, HTTPS cookie settings, backups, rate limiting on uploads.
- Docs: update README, HANDOFF, CHANGES, SRS traceability table (requirement -> file/test).

## Rules for you
- Windows developer; give commands for PowerShell. Deliver changes as a **patch ZIP containing only new/changed files** (same folder paths) plus `CHANGES.md`, and a full ZIP at the end. Never include `vendor/`, `.env` or real secrets.
- You cannot trust code you have not run. Lint, write tests, and explain exactly which command the owner must run (`php artisan migrate`, `php artisan test`).
- Security first: never execute uploaded code on the app server, never store secret values, never send secrets to an AI, keep originals immutable, log security events in `audit_logs`.
- Keep answers short and in simple Roman Urdu/English mix for the owner; ask at most one question at a time.
