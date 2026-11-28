<?php

namespace App\Services;

use App\Exceptions\UploadRejected;
use App\Models\Project;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

/**
 * Safe handling of uploaded source code (FR-009..FR-013, FR-014, NFR-005, NFR-008, NFR-013).
 *
 * Layout on disk (private, never inside public/):
 *   storage/app/private/projects/{project_id}/{upload_id}/original.zip   (only for ZIP uploads, read-only)
 *   storage/app/private/projects/{project_id}/{upload_id}/source/...     (files the scanners will read)
 *
 * The uploaded original is never modified. Its SHA-256 is stored so integrity can be proven later.
 */
class UploadService
{
    public const DISK = 'local';

    // ------------------------------------------------------------------ store

    public function store(Project $project, UploadedFile $file, User $user, string $kind = 'original'): Upload
    {
        $tmp = $file->getRealPath();
        if ($tmp === false || ! is_file($tmp)) {
            throw new UploadRejected('The upload could not be read. Please try again.');
        }

        $originalName = $this->displayName($file->getClientOriginalName());
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $size = (int) filesize($tmp);
        $isArchive = $ext === 'zip';

        $this->assertAcceptable($ext, $tmp, $size, $isArchive);

        $upload = Upload::create([
            'project_id' => $project->id,
            'kind' => $kind,
            'original_name' => $originalName,
            'storage_path' => '',
            'sha256' => hash_file('sha256', $tmp),
            'size_bytes' => $size,
            'uploaded_by' => $user->id,
            'is_archive' => $isArchive,
        ]);

        $rel = "projects/{$project->id}/{$upload->id}";
        $abs = $this->abs($rel);

        try {
            File::ensureDirectoryExists($abs.'/source', 0750, true);

            if ($isArchive) {
                $stats = $this->extractArchive($tmp, $abs.'/source');
                $this->copyReadOnly($tmp, $abs.'/original.zip');
                $originalFile = 'original.zip';
            } else {
                $safeName = $this->safeSegment($originalName);
                $this->copyReadOnly($tmp, $abs.'/source/'.$safeName);
                $originalFile = 'source/'.$safeName;
                $stats = ['files' => 1, 'skipped' => 0, 'bytes' => $size];
            }

            $upload->update([
                'storage_path' => $rel,
                'original_file' => $originalFile,
                'file_count' => $stats['files'],
                'skipped_count' => $stats['skipped'],
                'extracted_bytes' => $stats['bytes'],
            ]);
        } catch (Throwable $e) {
            // Nothing half-finished is ever kept.
            File::deleteDirectory($abs);
            $upload->delete();
            throw $e;
        }

        return $upload->refresh();
    }

    // ----------------------------------------------------------------- delete

    public function delete(Upload $upload): void
    {
        if ($upload->storage_path !== '') {
            $this->safeDeleteDirectory($this->abs($upload->storage_path));
        }
        $upload->delete();
    }

    /** Remove every stored file of a project (used when the project is deleted, FR-014). */
    public function deleteProjectFiles(Project $project): void
    {
        $this->safeDeleteDirectory($this->abs("projects/{$project->id}"));
    }

    private function safeDeleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            try {
                $items = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($items as $item) {
                    @chmod($item->getRealPath(), 0777);
                    if ($item->isDir()) {
                        @rmdir($item->getRealPath());
                    } else {
                        @unlink($item->getRealPath());
                    }
                }
                @rmdir($path);
            } catch (Throwable) {
                File::deleteDirectory($path);
            }
        } else {
            File::deleteDirectory($path);
        }
    }

    // ------------------------------------------------------------------- read

    public function abs(string $relative): string
    {
        return Storage::disk(self::DISK)->path($relative);
    }

    public function originalPath(Upload $upload): ?string
    {
        if ($upload->storage_path === '' || $upload->original_file === '') {
            return null;
        }
        $path = $this->abs($upload->storage_path.'/'.$upload->original_file);

        return is_file($path) ? $path : null;
    }

    /** True when the stored original still matches the SHA-256 taken at upload time. */
    public function verifyIntegrity(Upload $upload): bool
    {
        $path = $this->originalPath($upload);

        return $path !== null && hash_equals($upload->sha256, hash_file('sha256', $path));
    }

    /** @return array{files: list<array{path:string,size:int}>, truncated: bool} */
    public function listFiles(Upload $upload, int $limit = 500): array
    {
        $dir = $upload->storage_path !== '' ? $this->abs($upload->storage_path.'/source') : '';
        if ($dir === '' || ! is_dir($dir)) {
            return ['files' => [], 'truncated' => false];
        }

        $root = rtrim(realpath($dir), '/').'/';
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && ! $item->isLink()) {
                $files[] = ['path' => substr($item->getRealPath(), strlen($root)), 'size' => $item->getSize()];
            }
        }
        usort($files, fn ($a, $b) => strcmp($a['path'], $b['path']));

        return ['files' => array_slice($files, 0, $limit), 'truncated' => count($files) > $limit];
    }

    // ------------------------------------------------------------- validation

    private function assertAcceptable(string $ext, string $tmp, int $size, bool $isArchive): void
    {
        $allowed = config('nexapatch.upload.allowed_extensions');

        if ($size === 0) {
            throw new UploadRejected('The file is empty.');
        }
        if (! $isArchive && ! in_array($ext, $allowed, true)) {
            throw new UploadRejected('Files of type ".'.($ext ?: 'none').'" are not supported. Upload a source file ('
                .implode(', ', array_slice($allowed, 0, 8)).', ...) or a .zip project.');
        }

        if ($isArchive) {
            $head = (string) file_get_contents($tmp, false, null, 0, 4);
            if (! in_array($head, ["PK\x03\x04", "PK\x05\x06"], true)) {
                throw new UploadRejected('This file has a .zip name but is not a real ZIP archive.');
            }

            return;
        }

        if (! $this->isText($tmp)) {
            throw new UploadRejected('This file is binary, not text source code, so it was refused.');
        }
    }

    /** Source code is text: reject files containing NUL bytes in the first 8 KB. */
    private function isText(string $path): bool
    {
        $chunk = (string) file_get_contents($path, false, null, 0, 8192);

        return ! str_contains($chunk, "\0");
    }

    // ---------------------------------------------------------------- archives

    /**
     * Validate every entry first, then extract only safe, supported files.
     *
     * @return array{files:int, skipped:int, bytes:int}
     */
    private function extractArchive(string $zipPath, string $destDir): array
    {
        $cfg = config('nexapatch.upload');
        $maxTotal = $cfg['max_extracted_mb'] * 1024 * 1024;
        $maxFile = $cfg['max_file_mb'] * 1024 * 1024;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new UploadRejected('The ZIP file is damaged or is not a valid ZIP archive.');
        }

        try {
            $count = $zip->numFiles;
            if ($count === 0) {
                throw new UploadRejected('The ZIP file is empty.');
            }
            if ($count > $cfg['max_entries']) {
                throw new UploadRejected("The ZIP contains too many entries (limit {$cfg['max_entries']}).");
            }

            // ---- Pass 1: inspect everything before writing anything
            $plan = [];
            $seen = [];
            $skipped = 0;
            $declaredTotal = 0;

            for ($i = 0; $i < $count; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    throw new UploadRejected('The ZIP file is damaged.');
                }

                $name = (string) $stat['name'];
                $this->assertSafeEntryName($name);
                $this->assertRegularEntry($zip, $i);

                if (($stat['encryption_method'] ?? 0) !== 0) {
                    throw new UploadRejected('Password-protected ZIP files are not supported.');
                }
                if (str_ends_with($name, '/')) {
                    continue; // directory entry
                }

                $size = (int) $stat['size'];
                $compressed = (int) $stat['comp_size'];

                $declaredTotal += $size;
                if ($declaredTotal > $maxTotal) {
                    throw new UploadRejected("The ZIP expands to more than {$cfg['max_extracted_mb']} MB, which is not allowed.");
                }
                if ($size > 1048576 && $compressed > 0 && ($size / $compressed) > $cfg['max_ratio']) {
                    throw new UploadRejected('The ZIP has a suspiciously high compression ratio (possible zip bomb).');
                }

                $segments = $this->cleanSegments($name);
                if ($this->shouldSkip($segments)) {
                    $skipped++;
                    continue;
                }
                if ($size > $maxFile) {
                    throw new UploadRejected("'".implode('/', $segments)."' is larger than {$cfg['max_file_mb']} MB.");
                }

                $rel = implode('/', $segments);
                $key = strtolower($rel);
                if (isset($seen[$key])) {
                    throw new UploadRejected("The ZIP contains duplicate file names ('{$rel}').");
                }
                $seen[$key] = true;
                $plan[] = [$i, $rel, $size];
            }

            if ($plan === []) {
                throw new UploadRejected('No supported source files were found in the ZIP.');
            }

            // ---- Pass 2: extract with a hard byte limit (headers can lie)
            $destReal = realpath($destDir);
            if ($destReal === false) {
                throw new UploadRejected('The upload folder could not be prepared.');
            }

            $files = 0;
            $total = 0;
            foreach ($plan as [$index, $rel, $declared]) {
                $target = $destReal.'/'.$rel;
                File::ensureDirectoryExists(dirname($target), 0750, true);

                // Defence in depth: the final folder must still be inside the destination.
                $realDir = realpath(dirname($target));
                if ($realDir === false || ! str_starts_with($realDir.DIRECTORY_SEPARATOR, $destReal.DIRECTORY_SEPARATOR)) {
                    throw new UploadRejected('The ZIP contains an unsafe file path.');
                }

                $in = $zip->getStream((string) $zip->getNameIndex($index));
                if ($in === false) {
                    throw new UploadRejected('A file inside the ZIP could not be read.');
                }
                $out = fopen($target, 'wb');
                $bytes = 0;
                while (! feof($in)) {
                    $chunk = fread($in, 8192);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $bytes += strlen($chunk);
                    $total += strlen($chunk);
                    if ($bytes > $declared + 1024 || $total > $maxTotal) {
                        fclose($in);
                        fclose($out);
                        throw new UploadRejected('The ZIP expands to more data than it declared (possible zip bomb).');
                    }
                    fwrite($out, $chunk);
                }
                fclose($in);
                fclose($out);

                if (! $this->isText($target)) {
                    throw new UploadRejected("'{$rel}' is not a text source file.");
                }
                if (PHP_OS_FAMILY !== 'Windows') {
                    chmod($target, 0440);
                }
                $files++;
            }

            return ['files' => $files, 'skipped' => $skipped, 'bytes' => $total];
        } finally {
            $zip->close();
        }
    }

    /** Reject path traversal (zip-slip), absolute paths, drive letters, backslashes and control characters. */
    private function assertSafeEntryName(string $name): void
    {
        $bad = $name === ''
            || strlen($name) > 500
            || str_contains($name, '\\')
            || str_starts_with($name, '/')
            || preg_match('/^[A-Za-z]:/', $name) === 1
            || preg_match('/[\x00-\x1F]/', $name) === 1
            || in_array('..', explode('/', $name), true);

        if ($bad) {
            throw new UploadRejected('The ZIP contains an unsafe file path and was refused.');
        }
    }

    /** Reject symbolic links and special files stored in the archive. */
    private function assertRegularEntry(ZipArchive $zip, int $index): void
    {
        $opsys = 0;
        $attr = 0;
        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return;
        }
        if ($opsys !== ZipArchive::OPSYS_UNIX) {
            return;
        }

        $type = (($attr >> 16) & 0xFFFF) & 0170000;
        // 0 = unknown, 0100000 = regular file, 0040000 = directory
        if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
            throw new UploadRejected('The ZIP contains symbolic links or special files, which are not allowed.');
        }
    }

    /** @return list<string> */
    private function cleanSegments(string $name): array
    {
        $out = [];
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $out[] = $this->safeSegment($part);
        }

        return $out;
    }

    /** Keep only harmless characters in a single file or folder name. */
    private function safeSegment(string $name): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9._\- ]/', '_', $name));
        $clean = mb_substr($clean, 0, 150);

        return ($clean === '' || trim($clean, '.') === '') ? '_' : $clean;
    }

    /** @param list<string> $segments */
    private function shouldSkip(array $segments): bool
    {
        $cfg = config('nexapatch.upload');

        foreach (array_slice($segments, 0, -1) as $dir) {
            if (in_array(strtolower($dir), $cfg['skip_dirs'], true)) {
                return true;
            }
        }

        $file = (string) end($segments);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (in_array(strtolower($file), $cfg['allowed_filenames'], true)) {
            return false;
        }

        return ! in_array($ext, $cfg['allowed_extensions'], true);
    }

    /**
     * The clean relative path a ZIP entry gets inside the stored `source/` folder,
     * or null for directories and unsafe names. Used when the fixed ZIP is rebuilt.
     */
    public function relativeFor(string $entryName): ?string
    {
        try {
            $this->assertSafeEntryName($entryName);
        } catch (UploadRejected) {
            return null;
        }
        if (str_ends_with($entryName, '/')) {
            return null;
        }
        $segments = $this->cleanSegments($entryName);

        return $segments === [] ? null : implode('/', $segments);
    }

    // ----------------------------------------------------------------- helpers

    /** The name shown to users. Never used as a path. */
    private function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        $name = mb_substr($name, 0, 180);

        return $name === '' ? 'upload' : $name;
    }

    private function copyReadOnly(string $from, string $to): void
    {
        if (! copy($from, $to)) {
            throw new UploadRejected('The file could not be stored. Please try again.');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($to, 0440);
        }
    }
}
