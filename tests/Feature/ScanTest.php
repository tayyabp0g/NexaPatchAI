<?php

namespace Tests\Feature;

use App\Models\Finding;
use App\Models\Project;
use App\Models\Scan;
use App\Models\Upload;
use App\Models\User;
use App\Services\ReportService;
use App\Services\Scanner\ExternalScanner;
use App\Services\UploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ScanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['nexapatch.external_scanners.enabled' => false]);
    }

    private function project(User $owner): Project
    {
        $p = new Project(['name' => 'Shop', 'language' => 'php', 'retention_days' => 30]);
        $p->owner_id = $owner->id;
        $p->save();

        return $p;
    }

    /** @param array<string,string> $entries */
    private function uploadZip(User $user, Project $project, array $entries): Upload
    {
        $path = sys_get_temp_dir().'/np_scan_'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return app(UploadService::class)->store($project, new UploadedFile($path, 'app.zip', 'application/zip', null, true), $user);
    }

    private function vulnerableEntries(): array
    {
        return [
            'index.php' => "<?php\n\$r = mysqli_query(\$c, \"SELECT * FROM u WHERE id=\" . \$_GET['id']);\necho \$_GET['name'];\n",
            'config.php' => "<?php\n\$password = \"hunter2hunter2\";\n",
            'shell.php' => "<?php\neval(base64_decode('ZWNobyAxOw=='));\n",
        ];
    }

    private function runScan(User $user, Project $project, Upload $upload)
    {
        return $this->actingAs($user)->post(route('projects.uploads.scans.store', [$project, $upload]));
    }

    public function test_owner_can_scan_and_findings_are_stored(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);
        $upload = $this->uploadZip($dev, $project, $this->vulnerableEntries());

        $response = $this->runScan($dev, $project, $upload);

        $scan = Scan::firstOrFail();
        $response->assertRedirect(route('scans.show', $scan));
        $this->assertSame('completed', $scan->status);
        $this->assertSame(3, $scan->files_scanned);
        $this->assertGreaterThanOrEqual(4, $scan->findings()->count());
        $this->assertLessThan(100, $scan->score);
        $this->assertNotNull($scan->grade);
        $this->assertTrue($scan->findings()->where('rule_id', 'PHP-SHELL-001')->exists());
        $this->assertTrue($scan->findings()->where('rule_id', 'PHP-SQLI-001')->exists());
        $this->assertDatabaseHas('audit_logs', ['action' => 'scan.completed']);
    }

    public function test_secret_values_never_reach_the_database(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);
        $upload = $this->uploadZip($dev, $project, $this->vulnerableEntries());
        $this->runScan($dev, $project, $upload);

        $all = Finding::pluck('snippet')->implode("\n");
        $this->assertStringNotContainsString('hunter2hunter2', $all);
    }

    public function test_scan_page_lists_findings_with_filters(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);
        $upload = $this->uploadZip($dev, $project, $this->vulnerableEntries());
        $this->runScan($dev, $project, $upload);
        $scan = Scan::firstOrFail();

        $this->actingAs($dev)->get(route('scans.show', $scan))->assertOk()->assertSee('Obfuscated eval');

        $this->actingAs($dev)->get(route('scans.show', ['scan' => $scan, 'severity' => ['critical']]))
            ->assertOk()->assertSee('Obfuscated eval')->assertDontSee('Hard-coded password or secret');

        $this->actingAs($dev)->get(route('scans.show', ['scan' => $scan, 'q' => 'config.php']))
            ->assertOk()->assertSee('Hard-coded password or secret')->assertDontSee('Obfuscated eval');
    }

    public function test_finding_detail_page_shows_fix_advice_and_hides_secrets(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);
        $upload = $this->uploadZip($dev, $project, $this->vulnerableEntries());
        $this->runScan($dev, $project, $upload);
        $finding = Finding::where('rule_id', 'PHP-SECRET-002')->firstOrFail();

        $this->actingAs($dev)->get(route('findings.show', $finding))
            ->assertOk()->assertSee('How to fix it')->assertSee('environment variables')->assertDontSee('hunter2hunter2');
    }

    public function test_marking_a_finding_ignored_improves_the_score(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);
        $upload = $this->uploadZip($dev, $project, $this->vulnerableEntries());
        $this->runScan($dev, $project, $upload);
        $scan = Scan::firstOrFail();
        $before = $scan->score;
        $finding = $scan->findings()->where('severity', 'critical')->firstOrFail();

        $this->actingAs($dev)->patch(route('findings.status', $finding), ['status' => 'ignored'])->assertRedirect();

        $this->assertSame('ignored', $finding->fresh()->status);
        $this->assertGreaterThan($before, $scan->fresh()->score);
        $this->assertDatabaseHas('audit_logs', ['action' => 'finding.status_changed']);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);
        $upload = $this->uploadZip($dev, $project, $this->vulnerableEntries());
        $this->runScan($dev, $project, $upload);
        $finding = Finding::firstOrFail();

        $this->actingAs($dev)->patch(route('findings.status', $finding), ['status' => 'fixed'])->assertSessionHasErrors('status');
    }

    public function test_permissions(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $reviewer = User::factory()->reviewer()->create();
        $project = $this->project($owner);
        $project->reviewers()->attach($reviewer->id);
        $upload = $this->uploadZip($owner, $project, $this->vulnerableEntries());

        $this->runScan($other, $project, $upload)->assertForbidden();
        $this->runScan($reviewer, $project, $upload)->assertForbidden();
        $this->assertSame(0, Scan::count());

        $this->runScan($owner, $project, $upload);
        $scan = Scan::firstOrFail();
        $finding = Finding::firstOrFail();

        $this->actingAs($other)->get(route('scans.show', $scan))->assertForbidden();
        $this->actingAs($other)->get(route('scans.status', $scan))->assertForbidden();
        $this->actingAs($other)->get(route('findings.show', $finding))->assertForbidden();
        $this->actingAs($other)->get(route('scans.report', $scan))->assertForbidden();

        $this->actingAs($reviewer)->get(route('scans.show', $scan))->assertOk();
        $this->actingAs($reviewer)->get(route('findings.show', $finding))->assertOk();
        $this->actingAs($reviewer)->patch(route('findings.status', $finding), ['status' => 'ignored'])->assertForbidden();
    }

    public function test_second_scan_is_refused_while_one_is_running(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);
        $upload = $this->uploadZip($dev, $project, $this->vulnerableEntries());
        Scan::create(['upload_id' => $upload->id, 'started_by' => $dev->id, 'status' => 'running']);

        $this->runScan($dev, $project, $upload)->assertSessionHasErrors('scan');
        $this->assertSame(1, Scan::count());
    }

    public function test_status_endpoint_returns_json(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);
        $upload = $this->uploadZip($dev, $project, $this->vulnerableEntries());
        $this->runScan($dev, $project, $upload);

        $this->actingAs($dev)->getJson(route('scans.status', Scan::firstOrFail()))
            ->assertOk()->assertJson(['status' => 'completed', 'finished' => true]);
    }

    public function test_vulnerable_dependencies_are_reported_from_osv(): void
    {
        Http::fake(['api.osv.dev/*' => Http::response(['results' => [['vulns' => [['id' => 'GHSA-aaaa-bbbb-cccc']]], []]])]);
        $dev = User::factory()->create();
        $project = $this->project($dev);
        $lock = json_encode(['packages' => [['name' => 'vendor/old', 'version' => 'v1.0.0'], ['name' => 'vendor/new', 'version' => '2.0.0']]]);
        $upload = $this->uploadZip($dev, $project, ['index.php' => '<?php echo 1;', 'composer.lock' => $lock]);

        $this->runScan($dev, $project, $upload);

        $scan = Scan::firstOrFail();
        $this->assertContains('osv', $scan->engines_used);
        $finding = $scan->findings()->where('rule_id', 'DEP-OSV-001')->firstOrFail();
        $this->assertStringContainsString('vendor/old', $finding->title);
        $this->assertSame(1, $scan->findings()->where('rule_id', 'DEP-OSV-001')->count());
    }

    public function test_scan_still_completes_when_osv_is_unreachable(): void
    {
        Http::fake(['api.osv.dev/*' => Http::response('error', 500)]);
        $dev = User::factory()->create();
        $project = $this->project($dev);
        $lock = json_encode(['packages' => [['name' => 'vendor/old', 'version' => '1.0.0']]]);
        $upload = $this->uploadZip($dev, $project, ['index.php' => '<?php echo 1;', 'composer.lock' => $lock]);

        $this->runScan($dev, $project, $upload);

        $scan = Scan::firstOrFail();
        $this->assertSame('completed', $scan->status);
        $this->assertContains('osv:unavailable', $scan->engines_used);
    }

    public function test_external_scanner_findings_and_statuses_are_recorded(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);
        $upload = $this->uploadZip($dev, $project, ['index.php' => '<?php echo 1;']);
        $externalScanner = \Mockery::mock(ExternalScanner::class);
        $externalScanner->shouldReceive('scan')->once()->andReturn([
            'engines' => ['semgrep' => 'completed', 'gitleaks' => 'unavailable'],
            'findings' => [[
                'rule_id' => 'php.security.audit.test',
                'title' => 'Semgrep test finding',
                'description' => 'A test finding from an external scanner.',
                'severity' => 'high',
                'confidence' => 'potential',
                'category' => 'static_analysis',
                'cwe_id' => 'CWE-79',
                'owasp_category' => null,
                'impact' => 'Test impact.',
                'remediation' => 'Test remediation.',
                'references' => [],
                'file_path' => 'index.php',
                'line_start' => 1,
                'line_end' => 1,
                'snippet' => null,
                'engine' => 'semgrep',
                'fingerprint' => str_repeat('a', 40),
            ]],
        ]);
        $this->app->instance(ExternalScanner::class, $externalScanner);

        $this->runScan($dev, $project, $upload);

        $scan = Scan::firstOrFail();
        $this->assertSame('completed', $scan->status);
        $this->assertContains('semgrep', $scan->engines_used);
        $this->assertContains('gitleaks:unavailable', $scan->engines_used);
        $this->assertDatabaseHas('findings', [
            'scan_id' => $scan->id,
            'rule_id' => 'php.security.audit.test',
            'engine' => 'semgrep',
        ]);
        $this->actingAs($dev)->get(route('scans.show', $scan))
            ->assertOk()
            ->assertSee('gitleaks:unavailable');
    }

    public function test_clean_project_gets_a_perfect_score(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);
        $upload = $this->uploadZip($dev, $project, ['index.php' => "<?php\necho htmlspecialchars(\$_GET['n'] ?? '', ENT_QUOTES);\n"]);

        $this->runScan($dev, $project, $upload);

        $scan = Scan::firstOrFail();
        $this->assertSame(100, $scan->score);
        $this->assertSame('A', $scan->grade);
    }

    public function test_dashboard_shows_scan_data(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);
        $upload = $this->uploadZip($dev, $project, $this->vulnerableEntries());
        $this->runScan($dev, $project, $upload);

        $this->actingAs($dev)->get('/dashboard')->assertOk()->assertSee('Security score')->assertSee('Needs attention');
    }

    public function test_pdf_report_is_generated(): void
    {
        if (! app(ReportService::class)->available()) {
            $this->markTestSkipped('dompdf is not installed (php composer.phar require dompdf/dompdf).');
        }

        $dev = User::factory()->create();
        $project = $this->project($dev);
        $upload = $this->uploadZip($dev, $project, $this->vulnerableEntries());
        $this->runScan($dev, $project, $upload);
        $scan = Scan::firstOrFail();

        $response = $this->actingAs($dev)->get(route('scans.report', $scan));

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->assertDatabaseHas('reports', ['scan_id' => $scan->id]);
    }

    public function test_report_without_pdf_library_shows_a_clear_message(): void
    {
        if (app(ReportService::class)->available()) {
            $this->markTestSkipped('dompdf is installed.');
        }

        $dev = User::factory()->create();
        $project = $this->project($dev);
        $upload = $this->uploadZip($dev, $project, $this->vulnerableEntries());
        $this->runScan($dev, $project, $upload);

        $this->actingAs($dev)->get(route('scans.report', Scan::firstOrFail()))->assertSessionHasErrors('report');
    }
}
