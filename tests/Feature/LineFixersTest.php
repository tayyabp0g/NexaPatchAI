<?php

namespace Tests\Feature;

use App\Services\Fixer\LineFixers;
use Tests\TestCase;

/** Groundwork for Day 4 (automatic fixes). These test the deterministic one-line fixers. */
class LineFixersTest extends TestCase
{
    private function fix(string $rule, string $kind, string $line, ?string $prev = null): ?array
    {
        return LineFixers::fix($rule, $kind, $line, $prev);
    }

    public function test_xss_input_is_escaped(): void
    {
        $fix = $this->fix('PHP-XSS-001', 'php', "echo \$_GET['name'];");

        $this->assertStringContainsString("htmlspecialchars(\$_GET['name'] ?? '', ENT_QUOTES, 'UTF-8')", $fix['lines'][0]);
    }

    public function test_already_escaped_input_is_left_alone(): void
    {
        $this->assertNull($this->fix('PHP-XSS-001', 'php', "echo htmlspecialchars(\$_GET['a']);"));
    }

    public function test_config_fixes(): void
    {
        $this->assertSame('display_errors = Off', $this->fix('PHP-CONF-002', 'ini', 'display_errors = On')['lines'][0]);
        $this->assertSame('APP_DEBUG=false', $this->fix('PHP-CONF-002', 'env', 'APP_DEBUG=true')['lines'][0]);
        $this->assertSame('allow_url_include = Off', $this->fix('PHP-CONF-003', 'ini', 'allow_url_include = On')['lines'][0]);
        $this->assertStringContainsString('-Indexes', $this->fix('PHP-CONF-005', 'htaccess', 'Options +Indexes')['lines'][0]);
        $this->assertStringContainsString('removed by NexaPatch', $this->fix('PHP-CONF-001', 'php', '    phpinfo();')['lines'][0]);
    }

    public function test_cookie_gets_security_flags(): void
    {
        $line = $this->fix('PHP-COOKIE-001', 'php', "setcookie('remember', \$token);")['lines'][0];

        $this->assertStringContainsString("'httponly' => true", $line);
        $this->assertStringContainsString("'samesite' => 'Lax'", $line);
    }

    public function test_predictable_tokens_use_random_bytes(): void
    {
        $line = $this->fix('PHP-CRYPTO-002', 'php', '$token = md5(uniqid(rand(), true));')['lines'][0];

        $this->assertSame('$token = bin2hex(random_bytes(16));', $line);
    }

    public function test_tls_verification_is_turned_on(): void
    {
        $this->assertSame('curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);', $this->fix('PHP-CRYPTO-004', 'php', 'curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);')['lines'][0]);
        $this->assertSame('curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);', $this->fix('PHP-CRYPTO-004', 'php', 'curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);')['lines'][0]);
    }

    public function test_session_regenerate_is_inserted_but_not_into_braceless_blocks(): void
    {
        $fix = $this->fix('PHP-SESSION-001', 'php', "    \$_SESSION['user_id'] = \$id;", '    $x = 1;');
        $this->assertSame(['    session_regenerate_id(true);', "    \$_SESSION['user_id'] = \$id;"], $fix['lines']);

        $this->assertNull($this->fix('PHP-SESSION-001', 'php', "    \$_SESSION['user_id'] = \$id;", '    if ($ok)'));
    }

    public function test_backdoor_statement_is_replaced_with_an_empty_statement(): void
    {
        $line = $this->fix('PHP-SHELL-001', 'php', "eval(base64_decode('ZWNobyAxOw=='));")['lines'][0];

        $this->assertStringStartsWith(';', $line);
        $this->assertStringNotContainsString('eval', $line);
        $this->assertNull($this->fix('PHP-SHELL-001', 'php', '$a = 1; eval(base64_decode($x));'));
    }

    public function test_path_and_deserialization_and_extract_fixes(): void
    {
        $this->assertStringContainsString("basename(\$_GET['file'])", $this->fix('PHP-FILE-001', 'php', "\$d = file_get_contents(\$_GET['file']);")['lines'][0]);
        $this->assertStringContainsString("['allowed_classes' => false]", $this->fix('PHP-UNSER-002', 'php', '$o = unserialize($stored);')['lines'][0]);
        $this->assertStringContainsString('EXTR_SKIP', $this->fix('PHP-VAROW-001', 'php', 'extract($_POST);')['lines'][0]);
    }

    public function test_hardcoded_secrets_move_to_environment_variables(): void
    {
        $fix = $this->fix('PHP-SECRET-002', 'php', '$db_password = "hunter2hunter2";');

        $this->assertSame('$db_password = getenv(\'DB_PASSWORD\') ?: \'\';', $fix['lines'][0]);
        $this->assertSame(['DB_PASSWORD' => 'hunter2hunter2'], $fix['secrets']);

        $conn = $this->fix('PHP-SECRET-004', 'php', '$c = mysqli_connect("localhost", "root", "SuperSecret99", "shop");');
        $this->assertStringContainsString("getenv('DB_PASSWORD')", $conn['lines'][0]);
        $this->assertStringNotContainsString('SuperSecret99', $conn['lines'][0]);
        $this->assertSame('SuperSecret99', $conn['secrets']['DB_PASSWORD']);
    }

    public function test_unknown_rules_return_null(): void
    {
        $this->assertNull($this->fix('PHP-UNKNOWN-999', 'php', '$x = 1;'));
        $this->assertFalse(LineFixers::supports('PHP-UNKNOWN-999'));
        $this->assertTrue(LineFixers::supports('PHP-XSS-001'));
        $this->assertTrue(LineFixers::supports('PHP-SQLI-001'));
    }
}
