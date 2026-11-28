<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Upload;
use App\Models\User;
use App\Services\UploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    // ------------------------------------------------------------- helpers

    private function project(User $owner, int $retention = 30): Project
    {
        $project = new Project(['name' => 'Shop', 'language' => 'php', 'retention_days' => $retention]);
        $project->owner_id = $owner->id;
        $project->save();

        return $project;
    }

    private function phpFile(string $name = 'index.php', string $content = "<?php echo 'hi';"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /** @param array<string,string> $entries */
    private function zip(array $entries, ?callable $tweak = null, string $name = 'project.zip'): UploadedFile
    {
        $path = sys_get_temp_dir().'/np_'.uniqid().'.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        if ($tweak) {
            $tweak($zip);
        }
        $zip->close();

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }

    private function upload(User $user, Project $project, UploadedFile $file, array $headers = [])
    {
        return $this->actingAs($user)->post(route('projects.uploads.store', $project), ['file' => $file], $headers);
    }

    private function assertNothingStored(): void
    {
        $this->assertSame(0, Upload::count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    // -------------------------------------------------------------- success

    public function test_single_php_file_is_stored_with_its_hash(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);

        $this->upload($dev, $project, $this->phpFile())->assertRedirect(route('projects.show', $project));

        $upload = Upload::firstOrFail();
        $this->assertSame(hash('sha256', "<?php echo 'hi';"), $upload->sha256);
        $this->assertSame('source/index.php', $upload->original_file);
        $this->assertFalse($upload->is_archive);
        $this->assertSame(1, $upload->file_count);
        $this->assertSame($dev->id, $upload->uploaded_by);
        Storage::disk('local')->assertExists($upload->storage_path.'/source/index.php');
        $this->assertDatabaseHas('audit_logs', ['action' => 'upload.created', 'user_id' => $dev->id]);
    }

    public function test_zip_is_extracted_and_non_source_files_are_skipped(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);

        $file = $this->zip([
            'app/index.php' => '<?php echo 1;',
            'README.md' => '# Shop',
            'logo.png' => "\x89PNG\r\n",
            'vendor/lib/a.php' => '<?php',
        ]);

        $this->upload($dev, $project, $file)->assertRedirect();

        $upload = Upload::firstOrFail();
        $this->assertTrue($upload->is_archive);
        $this->assertSame(2, $upload->file_count);
        $this->assertSame(2, $upload->skipped_count);
        Storage::disk('local')->assertExists($upload->storage_path.'/original.zip');
        Storage::disk('local')->assertExists($upload->storage_path.'/source/app/index.php');
        Storage::disk('local')->assertExists($upload->storage_path.'/source/README.md');
        Storage::disk('local')->assertMissing($upload->storage_path.'/source/vendor/lib/a.php');
        Storage::disk('local')->assertMissing($upload->storage_path.'/source/logo.png');
    }

    public function test_ajax_upload_returns_json_with_redirect(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);

        $this->upload($dev, $project, $this->phpFile(), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['message', 'redirect']);
    }

    // ------------------------------------------------------------ rejections

    public function test_zip_slip_path_traversal_is_rejected_and_nothing_is_written(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);

        $file = $this->zip(['ok.php' => '<?php', '../evil.php' => '<?php system($_GET["c"]);']);

        $this->upload($dev, $project, $file)->assertSessionHasErrors('file');

        $this->assertNothingStored();
        $this->assertDatabaseHas('audit_logs', ['action' => 'upload.rejected']);
    }

    public function test_symbolic_links_inside_zip_are_rejected(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);

        $file = $this->zip(['link.php' => '/etc/passwd'], function (ZipArchive $zip) {
            $zip->setExternalAttributesName('link.php', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });

        $this->upload($dev, $project, $file)->assertSessionHasErrors('file');
        $this->assertNothingStored();
    }

    public function test_zip_bomb_by_compression_ratio_is_rejected(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);

        $file = $this->zip(['big.php' => str_repeat('A', 5 * 1024 * 1024)]);

        $this->upload($dev, $project, $file)->assertSessionHasErrors('file');
        $this->assertNothingStored();
    }

    public function test_zip_with_too_many_entries_is_rejected(): void
    {
        config(['nexapatch.upload.max_entries' => 3]);
        $dev = User::factory()->create();
        $project = $this->project($dev);

        $file = $this->zip(['a.php' => '1', 'b.php' => '2', 'c.php' => '3', 'd.php' => '4', 'e.php' => '5']);

        $this->upload($dev, $project, $file)->assertSessionHasErrors('file');
        $this->assertNothingStored();
    }

    public function test_zip_without_any_source_files_is_rejected(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);

        $this->upload($dev, $project, $this->zip(['logo.png' => 'x', 'vendor/a.php' => '<?php']))
            ->assertSessionHasErrors('file');
        $this->assertNothingStored();
    }

    public function test_unsupported_file_type_is_rejected(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);

        $this->upload($dev, $project, $this->phpFile('run.exe', 'MZ-program'))->assertSessionHasErrors('file');
        $this->assertNothingStored();
    }

    public function test_binary_file_with_php_name_is_rejected(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);

        $this->upload($dev, $project, $this->phpFile('x.php', "<?php\0\0\0binary"))->assertSessionHasErrors('file');
        $this->assertNothingStored();
    }

    public function test_fake_zip_is_rejected(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);

        $this->upload($dev, $project, $this->phpFile('a.zip', 'this is not a zip'))->assertSessionHasErrors('file');
        $this->assertNothingStored();
    }

    public function test_empty_file_is_rejected(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);

        $this->upload($dev, $project, $this->phpFile('empty.php', ''))->assertSessionHasErrors('file');
        $this->assertNothingStored();
    }

    public function test_file_over_the_size_limit_is_rejected(): void
    {
        config(['nexapatch.upload_max_mb' => 1]);
        $dev = User::factory()->create();
        $project = $this->project($dev);

        $this->upload($dev, $project, UploadedFile::fake()->create('big.php', 2048))->assertSessionHasErrors('file');
        $this->assertNothingStored();
    }

    public function test_rejected_ajax_upload_returns_422_json(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);

        $this->upload($dev, $project, $this->phpFile('run.exe', 'MZ'), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['file']]);
    }

    // ------------------------------------------------------------ permissions

    public function test_other_developer_and_reviewer_cannot_upload(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $reviewer = User::factory()->reviewer()->create();
        $project = $this->project($owner);
        $project->reviewers()->attach($reviewer->id);

        $this->upload($other, $project, $this->phpFile())->assertForbidden();
        $this->upload($reviewer, $project, $this->phpFile())->assertForbidden();
        $this->assertNothingStored();
    }

    public function test_admin_can_upload_to_any_project(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $project = $this->project($owner);

        $this->upload($admin, $project, $this->phpFile())->assertRedirect();
        $this->assertSame(1, Upload::count());
    }

    public function test_view_and_download_rules(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $reviewer = User::factory()->reviewer()->create();
        $project = $this->project($owner);
        $project->reviewers()->attach($reviewer->id);
        $this->upload($owner, $project, $this->phpFile());
        $upload = Upload::firstOrFail();

        $this->actingAs($owner)->get(route('projects.uploads.show', [$project, $upload]))
            ->assertOk()->assertSee('index.php')->assertSee('Unchanged');
        $this->actingAs($owner)->get(route('projects.uploads.download', [$project, $upload]))
            ->assertOk()->assertDownload('index.php');
        $this->actingAs($reviewer)->get(route('projects.uploads.download', [$project, $upload]))->assertOk();

        $this->actingAs($other)->get(route('projects.uploads.show', [$project, $upload]))->assertForbidden();
        $this->actingAs($other)->get(route('projects.uploads.download', [$project, $upload]))->assertForbidden();
    }

    public function test_upload_id_from_another_project_gives_404(): void
    {
        $dev = User::factory()->create();
        $a = $this->project($dev);
        $b = $this->project($dev);
        $this->upload($dev, $a, $this->phpFile());
        $upload = Upload::firstOrFail();

        $this->actingAs($dev)->get(route('projects.uploads.show', [$b, $upload]))->assertNotFound();
        $this->actingAs($dev)->get(route('projects.uploads.download', [$b, $upload]))->assertNotFound();
        $this->actingAs($dev)->delete(route('projects.uploads.destroy', [$b, $upload]))->assertNotFound();
    }

    // ---------------------------------------------------------------- cleanup

    public function test_owner_can_delete_upload_and_files_are_removed(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->reviewer()->create();
        $project = $this->project($owner);
        $project->reviewers()->attach($reviewer->id);
        $this->upload($owner, $project, $this->phpFile());
        $upload = Upload::firstOrFail();

        $this->actingAs($reviewer)->delete(route('projects.uploads.destroy', [$project, $upload]))->assertForbidden();

        $this->actingAs($owner)->delete(route('projects.uploads.destroy', [$project, $upload]))->assertRedirect();
        $this->assertNothingStored();
        $this->assertDatabaseHas('audit_logs', ['action' => 'upload.deleted']);
    }

    public function test_deleting_a_project_deletes_its_files(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);
        $this->upload($dev, $project, $this->zip(['a.php' => '<?php']));
        $this->assertNotEmpty(Storage::disk('local')->allFiles());

        $this->actingAs($dev)->delete(route('projects.destroy', $project))->assertRedirect('/projects');

        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_purge_command_deletes_only_expired_uploads(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev, 7);

        $this->upload($dev, $project, $this->phpFile('old.php'));
        Upload::query()->update(['created_at' => now()->subDays(10)]);
        $this->upload($dev, $project, $this->phpFile('new.php'));

        $this->artisan('nexapatch:purge-expired')->assertSuccessful();

        $this->assertSame(1, Upload::count());
        $this->assertSame('new.php', Upload::first()->original_name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'upload.expired_deleted']);
    }

    // -------------------------------------------------------------- integrity

    public function test_integrity_check_detects_a_modified_original(): void
    {
        $dev = User::factory()->create();
        $project = $this->project($dev);
        $this->upload($dev, $project, $this->phpFile());
        $upload = Upload::firstOrFail();
        $service = app(UploadService::class);

        $this->assertTrue($service->verifyIntegrity($upload));

        $path = $service->originalPath($upload);
        chmod($path, 0644);
        file_put_contents($path, '<?php /* tampered */');

        $this->assertFalse($service->verifyIntegrity($upload));
    }
}
