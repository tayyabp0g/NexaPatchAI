<?php

namespace Tests\Feature;

use App\Services\Scanner\Redactor;
use App\Services\Scanner\ScanScorer;
use App\Services\Scanner\StaticScanner;
use Tests\TestCase;

class ScannerRulesTest extends TestCase
{
    private function fixtureDir(): string
    {
        return base_path('tests/Fixtures/vulnerable');
    }

    /** The scanner must report exactly these (file:line:rule) results on the vulnerable fixtures. */
    public function test_scanner_finds_the_expected_issues_in_the_fixtures(): void
    {
        $result = (new StaticScanner())->scan($this->fixtureDir());

        $found = array_map(fn ($f) => $f['file_path'].':'.$f['line_start'].':'.$f['rule_id'], $result['findings']);
        sort($found);

        $expected = [
            'app.js:1:JS-XSS-001',
            'app.js:2:JS-EVAL-001',
            'app.js:3:JS-STORAGE-001',
            'auth.php:3:PHP-CRYPTO-001',
            'auth.php:4:PHP-CRYPTO-002',
            'auth.php:5:PHP-SESSION-001',
            'auth.php:6:PHP-COOKIE-001',
            'auth.php:8:PHP-CRYPTO-004',
            'auth.php:9:PHP-AUTHZ-001',
            'auth.php:10:PHP-CRYPTO-003',
            'auth.php:12:PHP-CSRF-001',
            'cmd.php:2:PHP-CMDI-001',
            'cmd.php:3:PHP-CMDI-002',
            'cmd.php:5:PHP-CMDI-003',
            'cmd.php:6:PHP-RCE-001',
            'cmd.php:7:PHP-RCE-002',
            'cmd.php:8:PHP-RCE-003',
            'cmd.php:9:PHP-UNSER-001',
            'cmd.php:10:PHP-UNSER-002',
            'cmd.php:11:PHP-RCE-004',
            'config.ini:1:PHP-CONF-002',
            'config.ini:2:PHP-CONF-003',
            'config.ini:3:PHP-CONF-005',
            'files.php:2:PHP-LFI-001',
            'files.php:3:PHP-LFI-002',
            'files.php:4:PHP-FILE-001',
            'files.php:5:PHP-SSRF-001',
            'files.php:6:PHP-UPLOAD-001',
            'files.php:7:PHP-REDIRECT-001',
            'files.php:8:PHP-VAROW-001',
            'files.php:9:PHP-MAIL-001',
            'page.html:1:HTML-LINK-001',
            'sample.env:1:PHP-CONF-002',
            'sample.env:2:PHP-SECRET-003',
            'secrets.php:2:PHP-SECRET-004',
            'secrets.php:3:PHP-SECRET-002',
            'secrets.php:4:PHP-SECRET-002',
            'secrets.php:5:PHP-SECRET-001',
            'shell.php:3:PHP-SHELL-001',
            'shell.php:4:PHP-SHELL-002',
            'shell.php:5:PHP-SHELL-004',
            'shell.php:6:PHP-SHELL-003',
            'sqli.php:3:PHP-SQLI-001',
            'sqli.php:4:PHP-SQLI-002',
            'sqli.php:5:PHP-SQLI-002',
            'sqli.php:6:PHP-SQLI-003',
            'sqli.php:7:PHP-SQLI-004',
            'upload2.php:3:PHP-UPLOAD-002',
            'xss.php:1:PHP-XSS-001',
            'xss.php:2:PHP-XSS-001',
            'xss.php:3:PHP-XSS-001',
            'xss.php:5:PHP-XSS-001',
        ];
        sort($expected);

        $this->assertSame($expected, $found, 'Scanner output differs from the expected fixture results.');
    }

    public function test_safe_code_produces_no_findings(): void
    {
        $result = (new StaticScanner())->scan($this->fixtureDir());

        $inSafe = array_filter($result['findings'], fn ($f) => $f['file_path'] === 'safe.php');
        $this->assertSame([], array_values($inSafe));
    }

    public function test_comment_lines_are_ignored(): void
    {
        $findings = (new StaticScanner())->scanContent('x.php', 'php', "<?php\n// eval(\$_POST['x']);\n# system(\$_GET['c']);\n");

        $this->assertSame([], $findings);
    }

    public function test_secrets_are_not_stored_in_snippets(): void
    {
        $result = (new StaticScanner())->scan($this->fixtureDir());

        $snippets = implode("\n", array_map(fn ($f) => (string) $f['snippet'], $result['findings']));
        foreach (['SuperSecret99', 'hunter2hunter2', 'AKIAIOSFODNN7EXAMPLE', 'Sup3rS3cret!', 'abc12345'] as $secret) {
            $this->assertStringNotContainsString($secret, $snippets, "Secret leaked in snippet: {$secret}");
        }
    }

    public function test_every_finding_has_explanations_and_references(): void
    {
        $result = (new StaticScanner())->scan($this->fixtureDir());

        $this->assertNotEmpty($result['findings']);
        foreach ($result['findings'] as $f) {
            $this->assertNotEmpty($f['description']);
            $this->assertNotEmpty($f['impact']);
            $this->assertNotEmpty($f['remediation']);
            $this->assertContains($f['severity'], ['critical', 'high', 'medium', 'low', 'info']);
            $this->assertContains($f['confidence'], ['confirmed', 'potential']);
        }
    }

    public function test_scorer_and_grades(): void
    {
        $this->assertSame(['score' => 100, 'grade' => 'A'], ScanScorer::score([]));
        $this->assertSame(['score' => 70, 'grade' => 'C'], ScanScorer::score(['high' => 3]));
        $this->assertSame(0, ScanScorer::score(['critical' => 10])['score']);
        $this->assertSame('F', ScanScorer::grade(10));
    }

    public function test_redactor_hides_values(): void
    {
        $this->assertSame("\$password = \"hu****\";", Redactor::redact('$password = "hunter2";'));
        $this->assertStringNotContainsString('AKIAIOSFODNN7EXAMPLE', Redactor::redact("key = 'AKIAIOSFODNN7EXAMPLE'"));
    }
}
