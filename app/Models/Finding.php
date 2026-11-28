<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Finding extends Model
{
    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    /** Statuses a user may set by hand. "fixed" is set by the repair engine later. */
    public const USER_STATUSES = ['open', 'ignored', 'false_positive'];

    protected $fillable = [
        'scan_id', 'rule_id', 'title', 'description', 'cwe_id', 'owasp_category',
        'severity', 'confidence', 'file_path', 'line_start', 'line_end', 'snippet', 'status',
        'engine', 'category', 'impact', 'remediation', 'references', 'fingerprint',
    ];

    protected function casts(): array
    {
        return ['references' => 'array'];
    }

    /** Most serious first, then by file and line. */
    public function scopeBySeverity(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('file_path')
            ->orderBy('line_start');
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(Scan::class);
    }

    public function patches(): HasMany
    {
        return $this->hasMany(Patch::class);
    }

    public function categoryLabel(): string
    {
        return self::labelFor($this->category);
    }

    public static function labelFor(?string $category): string
    {
        return $category ? Str::of($category)->replace('_', ' ')->title()->toString() : 'Other';
    }

    /** @return list<array{n:int,code:string,hit:bool}> */
    public function snippetLines(): array
    {
        if (! $this->snippet) {
            return [];
        }

        $out = [];
        foreach (preg_split('/\R/', $this->snippet) as $row) {
            if (preg_match('/^(\d+): ?(.*)$/s', $row, $m)) {
                $out[] = ['n' => (int) $m[1], 'code' => $m[2], 'hit' => (int) $m[1] === (int) $this->line_start];
            }
        }

        return $out;
    }
}
