# Security scanner setup

NexaPatch always runs its built-in rule scanner. It can also invoke Semgrep and
Gitleaks as local command-line tools through `engine/semgrep_runner.py`. The
uploaded source is not sent to a hosted scanner. Semgrep may need internet
access to download the configured ruleset; metrics are disabled in the runner.
OSV.dev is a separate network dependency lookup for Composer packages.

## Ubuntu deployment

1. Install Python 3 and make the `semgrep` and `gitleaks` executables available
   on the `PATH` of the same operating-system account that runs PHP-FPM or the
   Laravel queue worker.
2. Install Semgrep using its official installation instructions. Install
   Gitleaks from its official release and verify the release checksum before
   placing the binary on that `PATH`.
3. Configure the application environment:

   ```dotenv
   SECURITY_TOOLS_ENABLED=true
   SECURITY_PYTHON=python3
   SEMGREP_ENABLED=true
   SEMGREP_CONFIG=p/security-audit
   GITLEAKS_ENABLED=true
   SECURITY_TOOLS_TIMEOUT=450
   ```

4. Restart PHP-FPM and queue workers after changing environment variables.
   Run a test scan and confirm the scan page lists `semgrep` and `gitleaks`
   without an `:unavailable` or `:failed` suffix.
5. Use queued scans in production (`SCAN_MODE=queue`) and keep the queue
   worker's timeout longer than `SECURITY_TOOLS_TIMEOUT`.

Set `SECURITY_TOOLS_ENABLED=false` to turn off both external scanners.
`SEMGREP_ENABLED=false` or `GITLEAKS_ENABLED=false` disables one engine. The
scan result records disabled, unavailable, and failed engines so that missing
coverage is visible instead of being reported as a successful scan.

## Current coverage and limitations

- The built-in rules are primarily PHP-focused and are heuristic pattern
  matches, not a complete parser or taint analysis.
- Semgrep uses the configured `p/security-audit` ruleset for supported
  languages in the project; exact language coverage depends on the rules in
  that ruleset. It does not guarantee complete PHP, HTML, JavaScript, or CSS
  coverage.
- Standalone `.sql` files currently have no dedicated NexaPatch SQL rule set.
  SQL-injection detections depend on supported source-language rules finding
  unsafe query construction.
- Gitleaks identifies likely secrets. Its raw secret and matched source text
  are not included in NexaPatch findings or snippets.
- OSV dependency lookup currently covers `composer.lock`, not npm lockfiles.
- A clean report is not proof that a project is secure. Review findings and
  use language-specific testing and expert review before deployment.
