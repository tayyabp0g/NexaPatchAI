# HANDOFF: NexaPatch AI (state at the end of Day 3 + Day 4 groundwork)

**To continue with another AI: open `PROMPT_FOR_NEXT_AI.md` and paste it into the new chat together with the ZIP.**

## Done
- Day 1: auth, roles, projects, dashboard, audit log, tests, Docker.
- Day 2: safe upload (ZIP defences, hashes, immutable original, retention purge).
- Day 3: scanner (50 rules, OSV), findings UI, score/grade, PDF report, new dashboard, sidebar colour fix.
- Day 4 groundwork (not wired): deterministic line fixers, free-tier LLM clients (Gemini / OpenAI-compatible), AiFixer, FixRun and Patch models, fix_runs migration.

## Not done
- Fix engine (propose/approve/build ZIP), reviewer workflow screens, admin job monitor, Python adapter, production Docker, more rules. See PROMPT_FOR_NEXT_AI.md.

## Conventions
- `Gate::authorize(...)`; lists via `Project::visibleTo($user)`; originals never modified; secrets never stored (see `Redactor`); log security events with `AuditLogger::log()`.
- Scan mode: `SCAN_MODE=sync` (default) or `queue`. LLM is optional and off by default (`LLM_PROVIDER=none`).
- The code was never executed by its author (no PHP in the sandbox): run `php artisan test` first.
