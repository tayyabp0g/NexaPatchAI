<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Security Assessment Report</title>
<style>
    @page { margin: 60px 40px 60px 40px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #1e293b; line-height: 1.45; }
    h1 { font-size: 24px; margin: 0 0 4px 0; color: #0f2342; }
    h2 { font-size: 15px; color: #0f2342; border-bottom: 2px solid #2b7fff; padding-bottom: 3px; margin: 22px 0 8px 0; }
    h3 { font-size: 11.5px; margin: 0 0 3px 0; }
    table { border-collapse: collapse; width: 100%; }
    th { background: #0f2342; color: #ffffff; text-align: left; padding: 5px 6px; font-size: 9.5px; }
    td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    .muted { color: #64748b; }
    .cover { background: #0f2342; color: #ffffff; padding: 26px 24px; margin-bottom: 14px; }
    .cover h1 { color: #ffffff; }
    .badge { padding: 2px 7px; color: #ffffff; font-size: 8.5px; font-weight: bold; text-transform: uppercase; }
    .critical { background: #7f1d1d; } .high { background: #dc3545; } .medium { background: #d97706; } .low { background: #0891b2; } .info { background: #6c757d; }
    .grade { font-size: 40px; font-weight: bold; }
    .finding { border: 1px solid #cbd5e1; padding: 8px 10px; margin-bottom: 10px; page-break-inside: avoid; }
    .code { background: #0f172a; color: #e2e8f0; font-family: "DejaVu Sans Mono", monospace; font-size: 8.5px; padding: 5px 6px; margin: 5px 0; white-space: pre-wrap; word-wrap: break-word; }
    .hit { background: #7f1d1d; color: #ffffff; }
    .fix { background: #eef5ff; border-left: 3px solid #2b7fff; padding: 5px 8px; margin-top: 5px; }
    #footer { position: fixed; bottom: -38px; left: 0; right: 0; text-align: center; font-size: 8.5px; color: #94a3b8; }
    #footer .pagenum:before { content: counter(page); }
</style>
</head>
<body>
<div id="footer">NexaPatch AI &middot; Confidential security report &middot; Page <span class="pagenum"></span></div>

<div class="cover">
    <div style="font-size:11px; letter-spacing:1px;">SECURITY ASSESSMENT REPORT</div>
    <h1>{{ $project->name }}</h1>
    <div>File analysed: {{ $upload->original_name }}</div>
    <div>Scan #{{ $scan->id }} &middot; {{ $scan->created_at->format('d M Y H:i') }} &middot; Report generated {{ $generatedAt->format('d M Y H:i') }}</div>
</div>

<table>
    <tr>
        <td style="width:25%; border:0; text-align:center;">
            <div class="muted">Security score</div>
            <div class="grade">{{ $scan->score ?? '-' }}</div>
            <div>Grade <strong>{{ $scan->grade ?? '-' }}</strong> (100 = best)</div>
        </td>
        <td style="border:0;">
            <h3>Executive summary</h3>
            @php($total = array_sum($counts))
            @if ($total === 0)
                <p>No open security issues were found by the automated checks. This does not prove the application is secure; see the limitations section.</p>
            @else
                <p>The scan found <strong>{{ $total }}</strong> open issue(s):
                    {{ $counts['critical'] }} critical, {{ $counts['high'] }} high, {{ $counts['medium'] }} medium, {{ $counts['low'] }} low and {{ $counts['info'] }} informational.
                    @if ($counts['critical'] + $counts['high'] > 0)
                        Critical and high issues can lead to data theft or server takeover and should be fixed before the application is used in production.
                    @endif
                </p>
            @endif
            <p class="muted">{{ $scan->files_scanned }} files analysed @if ($scan->files_skipped) ({{ $scan->files_skipped }} skipped) @endif &middot; engines: {{ implode(', ', $scan->engines_used ?? ['static']) }}</p>
        </td>
    </tr>
</table>

<h2>1. Findings by severity</h2>
<table>
    <tr><th>Severity</th><th>Open findings</th></tr>
    @foreach ($counts as $level => $n)
        <tr><td><span class="badge {{ $level }}">{{ $level }}</span></td><td>{{ $n }}</td></tr>
    @endforeach
</table>

@if ($categories->count())
<h2>2. Weakness types</h2>
<table>
    <tr><th>Type</th><th>Open findings</th></tr>
    @foreach ($categories as $cat => $n)
        <tr><td>{{ \App\Models\Finding::labelFor($cat) }}</td><td>{{ $n }}</td></tr>
    @endforeach
</table>
@endif

@if ($open->count())
<h2>3. Priority list</h2>
<table>
    <tr><th style="width:12%">Severity</th><th>Issue</th><th style="width:34%">Location</th></tr>
    @foreach ($open->take(15) as $f)
        <tr><td><span class="badge {{ $f->severity }}">{{ $f->severity }}</span></td><td>{{ $f->title }}</td><td>{{ $f->file_path }}{{ $f->line_start ? ':'.$f->line_start : '' }}</td></tr>
    @endforeach
</table>

<h2>4. Detailed findings</h2>
@foreach ($detailed as $i => $f)
    <div class="finding">
        <h3>{{ $i + 1 }}. {{ $f->title }}</h3>
        <div>
            <span class="badge {{ $f->severity }}">{{ $f->severity }}</span>
            <span class="muted">&nbsp;{{ $f->confidence }} &middot; {{ $f->rule_id }} &middot; {{ $f->cwe_id }} &middot; {{ $f->owasp_category }}</span>
        </div>
        <div style="margin-top:3px"><strong>Location:</strong> {{ $f->file_path }}{{ $f->line_start ? ':'.$f->line_start : '' }}</div>
        @if ($f->snippetLines())
            <div class="code">@foreach ($f->snippetLines() as $l)<div class="{{ $l['hit'] ? 'hit' : '' }}">{{ str_pad((string) $l['n'], 4, ' ', STR_PAD_LEFT) }}  {{ $l['code'] }}</div>@endforeach</div>
        @endif
        <div><strong>Problem:</strong> {{ $f->description }}</div>
        <div><strong>Impact:</strong> {{ $f->impact }}</div>
        <div class="fix"><strong>How to fix:</strong> {{ $f->remediation }}</div>
    </div>
@endforeach
@if ($omitted > 0)
    <p class="muted">{{ $omitted }} further lower-priority finding(s) are not listed here. Open the scan in NexaPatch AI to see all of them.</p>
@endif
@endif

@if ($closed->count())
<h2>5. Reviewed findings (not counted)</h2>
<table>
    <tr><th>Status</th><th>Issue</th><th>Location</th></tr>
    @foreach ($closed->take(60) as $f)
        <tr><td>{{ str_replace('_', ' ', $f->status) }}</td><td>{{ $f->title }}</td><td>{{ $f->file_path }}{{ $f->line_start ? ':'.$f->line_start : '' }}</td></tr>
    @endforeach
</table>
@endif

<h2>Scope, method and limitations</h2>
<p>The code was analysed statically: files were read and compared with {{ count(\App\Services\Scanner\RuleSet::load()) }} security rules (injection, XSS, command and code execution, web shells and backdoors, file handling, authentication, cryptography, configuration, hard-coded secrets) and, when a composer.lock file was present, with the public OSV vulnerability database. The uploaded code was never executed.</p>
<p>"Confirmed" means the pattern is a direct match for a dangerous construct. "Potential" means it needs a human review. Automated static analysis can miss logic flaws and can report false positives. This report does not guarantee that the application is secure. Secret values are hidden in the code excerpts.</p>
</body>
</html>
