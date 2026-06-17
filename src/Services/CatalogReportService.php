<?php

declare(strict_types=1);

namespace PanicMic\Services;

use PDO;

/**
 * Generates import/discovery reports for the curated catalog.
 *
 * Reports are written to storage/catalog-reports/ as JSON and HTML.
 */
final class CatalogReportService
{
    public function __construct(private readonly string $reportDir) {}

    /**
     * Generate a report for an import run.
     *
     * @param array<string,mixed> $runRow  Row from shared_song_import_runs
     * @return array{json_path:string,html_path:string}
     */
    public function generateImportReport(PDO $db, array $runRow, string $warnings = ''): array
    {
        $runId    = (int)$runRow['id'];
        $slug     = (string)$runRow['source_slug'];
        $ts       = date('Ymd-His', strtotime((string)($runRow['started_at'] ?? 'now')));
        $baseName = "import-{$slug}-{$ts}";

        // Gather top songs by karaoke_score created/matched in this run
        $topKaraoke = $db->prepare(
            'SELECT ss.id, ss.title, ss.artist, ss.year, ss.karaoke_score, ss.source_score
             FROM shared_songs ss
             INNER JOIN shared_song_candidates c ON c.shared_song_id = ss.id
             INNER JOIN shared_song_source_lists l ON l.id = c.source_list_id
             INNER JOIN shared_song_sources src ON src.id = l.source_id
             WHERE ss.is_active = 1 AND c.created_at >= ?
             ORDER BY ss.karaoke_score DESC LIMIT 20'
        );
        $topKaraoke->execute([$runRow['started_at']]);
        $topSongs = $topKaraoke->fetchAll();

        // Warnings
        $warnRows = CatalogSourceService::warningsForRun($db, $runId);

        // Possible duplicates (candidates with match_status = 'possible_duplicate')
        $dupeStmt = $db->prepare(
            'SELECT c.*, l.slug AS list_slug, l.title AS list_title
             FROM shared_song_candidates c
             JOIN shared_song_source_lists l ON l.id = c.source_list_id
             WHERE c.match_status = ? AND c.created_at >= ?
             LIMIT 50'
        );
        $dupeStmt->execute(['possible_duplicate', $runRow['started_at']]);
        $dupes = $dupeStmt->fetchAll();

        $report = [
            'run_id'           => $runId,
            'source_slug'      => $slug,
            'status'           => $runRow['status'],
            'started_at'       => $runRow['started_at'],
            'finished_at'      => $runRow['finished_at'],
            'total_seen'       => (int)$runRow['total_seen'],
            'total_imported'   => (int)$runRow['total_imported'],
            'total_skipped'    => (int)$runRow['total_skipped'],
            'total_created'    => (int)$runRow['total_created'],
            'total_matched'    => (int)$runRow['total_matched'],
            'total_needs_review'=> (int)$runRow['total_needs_review'],
            'top_songs'        => $topSongs,
            'possible_dupes'   => $dupes,
            'warnings'         => $warnRows,
            'generated_at'     => date('c'),
        ];

        if (!is_dir($this->reportDir)) {
            mkdir($this->reportDir, 0755, true);
        }

        $jsonPath = $this->reportDir . '/' . $baseName . '.json';
        $htmlPath = $this->reportDir . '/' . $baseName . '.html';

        file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($htmlPath, $this->renderHtml($report));

        return ['json_path' => $jsonPath, 'html_path' => $htmlPath];
    }

    /**
     * List report files (JSON) in the report dir, newest first.
     *
     * @return list<array{name:string,path:string,size:int,mtime:int}>
     */
    public function listReports(): array
    {
        if (!is_dir($this->reportDir)) {
            return [];
        }
        $files = glob($this->reportDir . '/*.json') ?: [];
        usort($files, static fn (string $a, string $b) => filemtime($b) - filemtime($a));
        return array_map(static fn (string $f): array => [
            'name'  => basename($f),
            'path'  => $f,
            'size'  => (int)filesize($f),
            'mtime' => (int)filemtime($f),
        ], $files);
    }

    /** @param array<string,mixed> $report */
    private function renderHtml(array $report): string
    {
        $h = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $run = $report;

        $warningRows = '';
        foreach ($report['warnings'] ?? [] as $w) {
            $warningRows .= '<tr><td>' . $h($w['warning_type']) . '</td><td>' . $h($w['message']) . '</td></tr>';
        }

        $songRows = '';
        foreach ($report['top_songs'] ?? [] as $s) {
            $songRows .= '<tr>'
                . '<td>' . $h($s['title']) . '</td>'
                . '<td>' . $h($s['artist']) . '</td>'
                . '<td>' . $h($s['year'] ?? '') . '</td>'
                . '<td>' . $h($s['karaoke_score'] ?? 0) . '</td>'
                . '</tr>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Import Report: {$h($run['source_slug'])}</title>
<style>
  body{font-family:system-ui,sans-serif;max-width:900px;margin:2rem auto;padding:0 1rem}
  h1{font-size:1.4rem}table{border-collapse:collapse;width:100%}
  th,td{border:1px solid #ccc;padding:.4rem .6rem;text-align:left;font-size:.85rem}
  th{background:#f5f5f5}
  .stats{display:flex;gap:1.5rem;flex-wrap:wrap;margin:1rem 0}
  .stat{background:#f9f9f9;border:1px solid #ddd;border-radius:6px;padding:.6rem 1rem;min-width:120px}
  .stat-num{font-size:1.5rem;font-weight:bold}
  .stat-label{font-size:.75rem;color:#666}
</style></head>
<body>
<h1>Import Report: {$h($run['source_slug'])}</h1>
<p>Status: <strong>{$h($run['status'])}</strong> &mdash; {$h($run['started_at'])} → {$h($run['finished_at'] ?? 'in progress')}</p>
<div class="stats">
  <div class="stat"><div class="stat-num">{$h($run['total_seen'])}</div><div class="stat-label">Seen</div></div>
  <div class="stat"><div class="stat-num">{$h($run['total_imported'])}</div><div class="stat-label">Imported</div></div>
  <div class="stat"><div class="stat-num">{$h($run['total_created'])}</div><div class="stat-label">Created</div></div>
  <div class="stat"><div class="stat-num">{$h($run['total_matched'])}</div><div class="stat-label">Matched</div></div>
  <div class="stat"><div class="stat-num">{$h($run['total_skipped'])}</div><div class="stat-label">Skipped</div></div>
  <div class="stat"><div class="stat-num">{$h($run['total_needs_review'])}</div><div class="stat-label">Needs review</div></div>
</div>

<h2>Top Songs by Karaoke Score</h2>
<table>
  <tr><th>Title</th><th>Artist</th><th>Year</th><th>Karaoke Score</th></tr>
  {$songRows}
</table>

<h2>Warnings ({$h(count($report['warnings'] ?? []))})</h2>
<table>
  <tr><th>Type</th><th>Message</th></tr>
  {$warningRows}
</table>

<p class="muted">Generated: {$h($report['generated_at'])}</p>
</body></html>
HTML;
    }
}
