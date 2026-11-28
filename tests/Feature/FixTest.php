<?php

namespace Tests\Feature;

use App\Models\FixRun;
use App\Models\Patch;
use App\Models\Project;
use App\Models\Scan;
use App\Models\Upload;
use App\Models\User;
use App\Services\Fixer\FixEngine;
use App\Services\UploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class FixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function createProject(User $owner): Project
    {
        $project = new Project(['name' => 'SecureShop', 'language' => 'php', 'retention_days' => 30]);
        $project->owner_id = $owner->id;
        $project->save();

        return $project;
    }

    private function createVulnerableZip(Project $project, User $user, array $files): Upload
    {
        $zipPath = sys_get_temp_dir().'/test_vuln_'.uniqid().'.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return app(UploadService::class)->store(
            $project,
            new UploadedFile($zipPath, 'vulnerable_app.zip', 'application/zip', null, true),
            $user
        );
    }

    public function test_propose_and_build_end_to_end_with_rules(): void
    {
        $dev = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $this->createProject($dev); // Keep project and user IDs distinct to catch audit actor mixups.
        $project = $this->createProject($dev);

        $code = <<<'PHP'
<?php
echo $_GET['name'];
phpinfo();
setcookie('remember', $token);
$api_key = "AKIA1111111111111111";
eval($_POST['cmd']);
PHP;

        $upload = $this->createVulnerableZip($project, $dev, [
            'index.php' => $code,
        ]);

        // Run initial scan
        $this->actingAs($dev)->post(route('projects.uploads.scans.store', [$project, $upload]))
            ->assertRedirect();

        $scan = Scan::where('upload_id', $upload->id)->firstOrFail();
        $this->assertNotEmpty($scan->findings);
        $beforeScore = $scan->score;

        // 1. Propose fixes
        $this->actingAs($dev)->post(route('scans.fixes.store', $scan))
            ->assertRedirect();

        $fixRun = FixRun::where('scan_id', $scan->id)->firstOrFail();
        $this->assertSame('ready', $fixRun->status);

        $verifiedPatches = $fixRun->patches()->where('status', 'verified')->get();
        $this->assertNotEmpty($verifiedPatches);
        $this->actingAs($dev)->get(route('fixes.show', $fixRun))
            ->assertOk()
            ->assertSee('Verified Patches')
            ->assertSee((string) $verifiedPatches->count());

        // Verify that no raw secrets are stored in DB patch rows
        foreach ($fixRun->patches as $patch) {
            $this->assertStringNotContainsString('AKIA1111111111111111', json_encode($patch->edit));
            $this->assertStringNotContainsString('AKIA1111111111111111', $patch->diff_text);
        }

        // 2. Build fixed ZIP with approved patches
        $patchIds = $verifiedPatches->pluck('id')->all();
        $this->actingAs($dev)->post(route('fixes.build', $fixRun), ['patches' => $patchIds])
            ->assertRedirect(route('fixes.show', $fixRun));

        $fixRun->refresh();
        $this->assertSame('completed', $fixRun->status);
        $this->assertGreaterThan(0, $fixRun->applied_count);
        $this->assertNotEmpty($fixRun->zip_path);
        $this->assertNotEmpty($fixRun->zip_sha256);
        $this->assertNotNull($fixRun->patched_upload_id);
        $this->assertNotNull($fixRun->after_scan_id);

        // Verify score improvement
        $afterScan = $fixRun->afterScan;
        $this->assertNotNull($afterScan);
        $this->assertGreaterThanOrEqual($beforeScore, $afterScan->score);

        // Verify ZIP contents
        $zipContent = Storage::disk(UploadService::DISK)->get($fixRun->zip_path);
        $this->assertNotNull($zipContent);

        $readZipPath = sys_get_temp_dir().'/read_fixed_'.uniqid().'.zip';
        file_put_contents($readZipPath, $zipContent);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($readZipPath));

        $changesDoc = $zip->getFromName('NEXAPATCH-CHANGES.md');
        $this->assertNotFalse($changesDoc);
        $this->assertStringContainsString('NexaPatch AI Repair Summary', $changesDoc);
        // Secrets must NOT leak into the changes markdown document
        $this->assertStringNotContainsString('AKIA1111111111111111', $changesDoc);

        // If secret was moved, verify private secrets file exists
        $secretsFile = $zip->getFromName('NEXAPATCH_SECRETS_TO_MOVE.nexapatch');
        if ($secretsFile !== false) {
            $this->assertStringContainsString('WARNING: These secrets were hard-coded in plaintext', $secretsFile);
        }

        $fixedIndex = $zip->getFromName('index.php');
        $this->assertNotFalse($fixedIndex);
        $this->assertStringContainsString('htmlspecialchars', $fixedIndex);
        $this->assertStringContainsString('removed by NexaPatch', $fixedIndex);
        $zip->close();
        @unlink($readZipPath);

        // Verify original upload was NEVER modified (immutable original policy)
        $this->assertTrue(app(UploadService::class)->verifyIntegrity($upload));

        // Verify audit log entry
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fix.built',
            'user_id' => $dev->id,
        ]);

        $this->actingAs($dev)->post(route('fixes.build', $fixRun), ['patches' => $patchIds])
            ->assertRedirect(route('fixes.show', $fixRun))
            ->assertSessionHasErrors('fix');
        $this->assertSame('completed', $fixRun->fresh()->status);
        $this->assertGreaterThan(0, $fixRun->fresh()->applied_count);
    }

    public function test_build_rejects_empty_approval_without_creating_a_zip(): void
    {
        $dev = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $project = $this->createProject($dev);
        $upload = $this->createVulnerableZip($project, $dev, [
            'index.php' => "<?php\necho \$_GET['q'];\n",
        ]);

        $this->actingAs($dev)->post(route('projects.uploads.scans.store', [$project, $upload]));
        $scan = Scan::where('upload_id', $upload->id)->firstOrFail();
        $this->actingAs($dev)->post(route('scans.fixes.store', $scan));
        $fixRun = FixRun::where('scan_id', $scan->id)->firstOrFail();

        $this->actingAs($dev)->from(route('fixes.show', $fixRun))
            ->post(route('fixes.build', $fixRun), [])
            ->assertRedirect(route('fixes.show', $fixRun))
            ->assertSessionHasErrors('patches');

        $this->assertSame('ready', $fixRun->fresh()->status);
        $this->assertNull($fixRun->fresh()->zip_path);
    }

    public function test_permissions_enforced_for_fix_runs(): void
    {
        $dev1 = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $dev2 = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $reviewer = User::factory()->create(['role' => User::ROLE_REVIEWER]);

        $project = $this->createProject($dev1);
        $project->reviewers()->attach($reviewer);

        $upload = $this->createVulnerableZip($project, $dev1, ['index.php' => "<?php echo \$_GET['x'];"]);
        $this->actingAs($dev1)->post(route('projects.uploads.scans.store', [$project, $upload]));
        $scan = Scan::where('upload_id', $upload->id)->firstOrFail();

        // Developer 2 cannot start fix run (403)
        $this->actingAs($dev2)->post(route('scans.fixes.store', $scan))
            ->assertForbidden();

        // Reviewer cannot start fix run (403 - reviewers cannot mutate upload)
        $this->actingAs($reviewer)->post(route('scans.fixes.store', $scan))
            ->assertForbidden();

        // Dev 1 can start fix run
        $this->actingAs($dev1)->post(route('scans.fixes.store', $scan))
            ->assertRedirect();
        $fixRun = FixRun::where('scan_id', $scan->id)->firstOrFail();

        // Reviewer can view fix run
        $this->actingAs($reviewer)->get(route('fixes.show', $fixRun))
            ->assertOk();

        // Reviewer cannot build (build creates a new upload)
        $this->actingAs($reviewer)->post(route('fixes.build', $fixRun), ['patches' => []])
            ->assertForbidden();
    }

    public function test_conflict_handling_skips_conflicted_patches(): void
    {
        $dev = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $project = $this->createProject($dev);

        $upload = $this->createVulnerableZip($project, $dev, ['index.php' => "<?php\necho \$_GET['a'];\n"]);
        $this->actingAs($dev)->post(route('projects.uploads.scans.store', [$project, $upload]));
        $scan = Scan::where('upload_id', $upload->id)->firstOrFail();

        $this->actingAs($dev)->post(route('scans.fixes.store', $scan));
        $fixRun = FixRun::where('scan_id', $scan->id)->firstOrFail();
        $patch = $fixRun->patches()->firstOrFail();

        // Intentionally tamper with the patch before_sha to simulate an edit conflict
        $edit = $patch->edit;
        $edit['before_sha'] = 'tampered_mismatched_sha';
        $patch->update(['edit' => $edit]);

        $this->actingAs($dev)->post(route('fixes.build', $fixRun), ['patches' => [$patch->id]]);

        $fixRun->refresh();
        $patch->refresh();

        // The conflicted patch must NOT be marked applied
        $this->assertNotSame('applied', $patch->status);
        $this->assertNotEmpty($fixRun->summary['conflicts'] ?? []);
        $this->assertSame('failed', $fixRun->status);
        $this->assertSame(1, $fixRun->failed_count);
        $this->assertNull($fixRun->zip_path);
    }

    public function test_ai_fixer_with_gemini_client(): void
    {
        config([
            'nexapatch.llm.provider' => 'gemini',
            'nexapatch.llm.gemini_key' => 'test-gemini-key',
            'nexapatch.llm.gemini_model' => 'gemini-2.5-flash',
            'nexapatch.llm.delay_ms' => 0,
        ]);

        $fakeResponse = json_encode([
            'start_line' => 2,
            'end_line' => 2,
            'replacement' => "echo htmlspecialchars(\$_GET['q'] ?? '', ENT_QUOTES, 'UTF-8');",
            'explanation' => 'Escaped query parameter to prevent XSS.',
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => $fakeResponse],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $dev = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $project = $this->createProject($dev);

        $upload = $this->createVulnerableZip($project, $dev, [
            'index.php' => "<?php\necho \$_GET['q'];\n",
        ]);

        $this->actingAs($dev)->post(route('projects.uploads.scans.store', [$project, $upload]));
        $scan = Scan::where('upload_id', $upload->id)->firstOrFail();

        // Request fix with AI enabled
        $this->actingAs($dev)->post(route('scans.fixes.store', $scan), ['use_ai' => '1']);

        $fixRun = FixRun::where('scan_id', $scan->id)->firstOrFail();
        $this->assertSame('ready', $fixRun->status);
        $this->assertTrue($fixRun->use_ai);
    }

    public function test_ai_fixer_handles_rate_limiting_cleanly(): void
    {
        config([
            'nexapatch.llm.provider' => 'openai',
            'nexapatch.llm.base_url' => 'https://api.groq.com/openai/v1',
            'nexapatch.llm.api_key' => 'test-groq-key',
            'nexapatch.llm.delay_ms' => 0,
        ]);

        // Simulate 429 Too Many Requests (Rate limit)
        Http::fake([
            'https://api.groq.com/*' => Http::response([
                'error' => ['message' => 'Rate limit reached'],
            ], 429),
        ]);

        $dev = User::factory()->create(['role' => User::ROLE_DEVELOPER]);
        $project = $this->createProject($dev);

        // A file with an issue that has no deterministic rule fixer so it calls AI
        $upload = $this->createVulnerableZip($project, $dev, [
            'index.php' => "<?php\ninclude \$_GET['page'] . '.php';\n",
        ]);

        $this->actingAs($dev)->post(route('projects.uploads.scans.store', [$project, $upload]));
        $scan = Scan::where('upload_id', $upload->id)->firstOrFail();

        $this->actingAs($dev)->post(route('scans.fixes.store', $scan), ['use_ai' => '1']);

        $fixRun = FixRun::where('scan_id', $scan->id)->firstOrFail();

        // Fix run must not crash and should record rate limit stopping AI
        $this->assertSame('ready', $fixRun->status);
        $this->assertNotEmpty($fixRun->summary['ai_stopped'] ?? null);
    }
}
