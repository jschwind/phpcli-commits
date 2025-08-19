<?php

declare(strict_types=1);

/**
 * CommitRangeReporter - A PHP CLI tool to fetch and report commit ranges between tags from GitHub or GitLab.
 *
 * (c) 2025 Juergen Schwind <info@juergen-schwind.de>
 * GitHub: https://github.com/jschwind/phpcli-commits
 *
 * MIT License
 *
 */

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle !== '' && substr($haystack, 0, strlen($needle)) === $needle;
    }
}

final class CommitRangeReporter
{
    private const UA = 'commit-range-reporter/1.0';

    private string $provider; // 'github' | 'gitlab'
    private string $owner;
    private string $repo;
    private string $fromTag;
    private string $toTag;
    private bool   $stepTag;
    private ?string $githubTok;
    private ?string $gitlabTok;
    private string $gitlabHost;

    private int $timeout = 20;
    private int $maxRedirects = 5;

    public function __construct(
        string $provider = 'github',
        string $owner = 'owner-name',
        string $repo = 'repo-name',
        string $fromTag = '',
        string $toTag = '',
        bool $stepTag = false,
        ?string $githubTok = null,
        ?string $gitlabTok = null,
        string $gitlabHost = ''
    ) {
        $this->provider  = strtolower($provider) === 'gitlab' ? 'gitlab' : 'github';
        $this->owner     = $owner;
        $this->repo      = $repo;
        $this->fromTag   = $fromTag;
        $this->toTag     = $toTag;
        $this->stepTag   = $stepTag;
        $this->githubTok = $githubTok;
        $this->gitlabTok = $gitlabTok;
        $this->gitlabHost = rtrim($gitlabHost, '/');
    }

    public static function fromArgs(array $argv): array
    {
        $scriptDir = __DIR__;
        $argc = count($argv);

        $configFile = null;
        if ($argc >= 2 && is_file($argv[1])) {
            $configFile = $argv[1];
        } else {
            $cwdFallback = getcwd() . DIRECTORY_SEPARATOR . 'git.json';
            $dirFallback = $scriptDir . DIRECTORY_SEPARATOR . 'git.json';
            if (is_file($cwdFallback)) {
                $configFile = $cwdFallback;
            } elseif (is_file($dirFallback)) {
                $configFile = $dirFallback;
            }
        }

        if (!$configFile) {
            throw new RuntimeException('No configuration file found (expected: git.json or path as 1st argument)');
        }

        $jsonRaw = @file_get_contents($configFile);
        if ($jsonRaw === false) {
            throw new RuntimeException("Could not read configuration file: {$configFile}");
        }

        $cfg = json_decode($jsonRaw, true);
        if (!is_array($cfg)) {
            throw new RuntimeException("Invalid JSON in {$configFile}");
        }

        $provider  = $cfg['provider'] ?? 'github';
        $owner     = $cfg['owner'] ?? 'owner-name';
        $repo      = $cfg['repo'] ?? 'repo-name';
        $fromTag   = $cfg['fromTag'] ?? '';
        $toTag     = $cfg['toTag'] ?? '';
        $stepTag   = (bool)($cfg['stepTag'] ?? false);
        $ghTok     = $cfg['github_token'] ?? null;
        $glTok     = $cfg['gitlab_token'] ?? null;
        $glHost    = $cfg['gitlab_host'] ?? '';

        $reporter = new self($provider, $owner, $repo, $fromTag, $toTag, $stepTag, $ghTok, $glTok, $glHost);

        $outFile = $argc >= 3 ? (string)$argv[2] : 'commits.txt';

        return ['reporter' => $reporter, 'outputFile' => $outFile];
    }

    public function run(string $outFile): void
    {
        $allTags = $this->fetchAllTags();
        if (!$allTags) {
            throw new RuntimeException('No tags found in repository.');
        }

        [$resolvedFrom, $resolvedTo] = $this->resolveSpecialKeywords(
            $this->fromTag,
            $this->toTag,
            $allTags
        );

        if (!$resolvedFrom || !$resolvedTo) {
            $preview = implode(', ', array_slice($this->sortTagsAsc($allTags), 0, 20));
            throw new RuntimeException(
                "Could not resolve fromTag/toTag. ".
                "fromTag='{$this->fromTag}'→'".($resolvedFrom ?? 'null')."', ".
                "toTag='{$this->toTag}'→'".($resolvedTo ?? 'null')."'. ".
                "Available tags (first 20): {$preview}".(count($allTags)>20?', ...':'')
            );
        }

        if (!$this->stepTag) {
            $this->ensureDir(dirname($outFile));
            $content = $this->runCompareAndCapture($resolvedFrom, $resolvedTo);
            if (@file_put_contents($outFile, $content) === false) {
                throw new RuntimeException("Could not write to output file: {$outFile}");
            }
            fwrite(STDERR, "✅ Report written to: {$outFile} (from {$resolvedFrom} to {$resolvedTo})\n");
            return;
        }

        $range = $this->tagsBetweenInclusive($allTags, $resolvedFrom, $resolvedTo);
        if (count($range) < 2) {
            throw new RuntimeException("Not enough tags between '{$resolvedFrom}' and '{$resolvedTo}' to step.");
        }

        $this->ensureDir(dirname($outFile));
        for ($i = 0; $i < count($range) - 1; $i++) {
            $a = $range[$i];
            $b = $range[$i + 1];

            $content = $this->runCompareAndCapture($a, $b);
            $safeA = preg_replace('~[^A-Za-z0-9._-]+~', '-', $a);
            $safeB = preg_replace('~[^A-Za-z0-9._-]+~', '-', $b);
            $stepFile = "{$outFile}.{$safeA}..{$safeB}.txt";

            if (@file_put_contents($stepFile, $content) === false) {
                throw new RuntimeException("Failed writing step file: {$stepFile}");
            }
            fwrite(STDERR, "✅ Wrote {$stepFile}\n");
        }
    }

    private function ensureDir(string $dir): void
    {
        if ($dir === '' || $dir === '.' || is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create output directory: {$dir}");
        }
    }

    private function httpRequest(string $url, array $headers): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => $this->maxRedirects,
            CURLOPT_TIMEOUT        => $this->timeout,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL error: {$err}");
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        $isJson = is_array($data);

        if ($status >= 400) {
            $msg = $isJson ? ($data['message'] ?? $data['error'] ?? "HTTP {$status}") : "HTTP {$status}";
            if ($status === 401 || $status === 403) {
                $msg .= ' – Hint: For GitLab, use PRIVATE-TOKEN: <PAT> or a valid OAuth token. On self-hosted instances, verify gitlab_host.';
            }
            if ($isJson && !empty($data['documentation_url'])) {
                $msg .= " | Docs: {$data['documentation_url']}";
            }
            throw new RuntimeException("API error ({$status}): {$msg}");
        }

        return $isJson ? $data : [];
    }

    private function ghHeaders(): array
    {
        $h = [
            'Accept: application/vnd.github+json',
            'User-Agent: ' . self::UA,
        ];
        if ($this->githubTok) {
            $h[] = 'Authorization: Bearer ' . $this->githubTok;
        }
        return $h;
    }

    private function glHeaders(): array
    {
        $h = [
            'Accept: application/json',
            'User-Agent: ' . self::UA,
        ];
        if ($this->gitlabTok) {
            $h[] = 'Authorization: Bearer ' . $this->gitlabTok;
            $h[] = 'PRIVATE-TOKEN: ' . $this->gitlabTok;
        }
        return $h;
    }

    private function gh(string $path): array
    {
        $url = "https://api.github.com{$path}";
        return $this->httpRequest($url, $this->ghHeaders());
    }

    private function gl(string $path): array
    {
        $host = $this->gitlabHost !== '' ? $this->gitlabHost : 'https://gitlab.com';
        $url = rtrim($host, '/') . $path;
        return $this->httpRequest($url, $this->glHeaders());
    }

    private function githubCompare(string $from, string $to): array
    {
        $path = "/repos/{$this->owner}/{$this->repo}/compare/{$from}...{$to}";
        return $this->gh($path);
    }

    private function githubTags(): array
    {
        $path = "/repos/{$this->owner}/{$this->repo}/tags?per_page=100";
        return $this->gh($path);
    }

    private function gitlabProjectId(): string
    {
        return rawurlencode("{$this->owner}/{$this->repo}");
    }

    private function gitlabCompare(string $from, string $to): array
    {
        $pid = $this->gitlabProjectId();
        $path = "/api/v4/projects/{$pid}/repository/compare?from=" . rawurlencode($from) . "&to=" . rawurlencode($to);
        return $this->gl($path);
    }

    private function gitlabTags(): array
    {
        $pid = $this->gitlabProjectId();
        $path = "/api/v4/projects/{$pid}/repository/tags?per_page=100";
        return $this->gl($path);
    }

    /** @return list<string> */
    private function fetchAllTags(): array
    {
        $tags = ($this->provider === 'github') ? $this->githubTags() : $this->gitlabTags();
        $names = array_values(array_filter(array_map(
            fn(array $t) => (string)($t['name'] ?? ''),
            $tags
        )));
        return $names;
    }

    private static function tagKey(string $t): string
    {
        return ltrim($t, 'vV');
    }

    /** @param list<string> $tags */
    private function sortTagsAsc(array $tags): array
    {
        usort($tags, function ($a, $b) {
            return version_compare(self::tagKey($a), self::tagKey($b));
        });
        return $tags;
    }

    /** @param list<string> $tags */
    private function filterTagsByPrefix(array $tags, string $prefix): array
    {
        if ($prefix === '') {
            return $tags;
        }
        $p = rtrim($prefix, '.');
        return array_values(array_filter($tags, function (string $t) use ($prefix, $p) {
            $k = self::tagKey($t);
            return $k === $prefix || str_starts_with($k, $p . '.');
        }));
    }

    /** @param list<string> $allTags */
    private function resolveSpecialKeywords(string $fromTag, string $toTag, array $allTags): array
    {
        $sorted = $this->sortTagsAsc($allTags);

        switch (strtolower($fromTag)) {
            case 'first':
            case '':
                $resolvedFrom = $sorted[0] ?? null;
                break;
            default:
                $resolvedFrom = $this->resolveTag($fromTag, $allTags, 'min');
        }

        switch (strtolower($toTag)) {
            case 'current':
            case 'latest':
            case '':
                $resolvedTo = $sorted ? end($sorted) : null;
                break;
            default:
                $resolvedTo = $this->resolveTag($toTag, $allTags, 'max');
        }

        return [$resolvedFrom, $resolvedTo];
    }

    /** @param list<string> $allTags */
    private function resolveTag(string $want, array $allTags, string $which): ?string
    {
        $candidates = $want === '' ? $allTags : $this->filterTagsByPrefix($allTags, $want);
        if (!$candidates) {
            return null;
        }
        $sorted = $this->sortTagsAsc($candidates);
        return $which === 'max' ? end($sorted) ?: null : ($sorted[0] ?? null);
    }

    /** @param list<string> $allTags */
    private function tagsBetweenInclusive(array $allTags, string $from, string $to): array
    {
        $sorted = $this->sortTagsAsc($allTags);
        $startIdx = array_search($from, $sorted, true);
        $endIdx   = array_search($to, $sorted, true);
        if ($startIdx === false || $endIdx === false) {
            return [];
        }
        if ($startIdx > $endIdx) {
            [$startIdx, $endIdx] = [$endIdx, $startIdx];
        }
        return array_slice($sorted, $startIdx, $endIdx - $startIdx + 1);
    }

    private static function countAddDelFromUnifiedDiff(?string $diff): array
    {
        if (!$diff) return [0, 0];
        $adds = 0; $dels = 0;
        foreach (explode("\n", $diff) as $line) {
            if ($line === '') continue;
            $first = $line[0];
            if ($first === '+' && !str_starts_with($line, '+++')) $adds++;
            elseif ($first === '-' && !str_starts_with($line, '---')) $dels++;
        }
        return [$adds, $dels];
    }

    private function runCompareAndCapture(string $fromTag, string $toTag): string
    {
        ob_start();

        $this->printHeaderLine($fromTag, $toTag);

        if ($this->provider === 'github') {
            $data = $this->githubCompare($fromTag, $toTag);
            $commits = $data['commits'] ?? [];

            if (!$commits) {
                $this->printNoCommitsWithTagHint($this->githubTags());
                return (string)ob_get_clean();
            }

            foreach ($commits as $c) {
                $this->printCommitGithub($c);
            }

            $total = (int)($data['total_commits'] ?? count($commits));
            echo str_repeat('-', 60) . PHP_EOL;
            echo "Commit count: " . count($commits) . " (reported total: {$total})" . PHP_EOL;

            $files = $data['files'] ?? [];
            if ($files) {
                echo PHP_EOL . "File changes:" . PHP_EOL;
                foreach ($files as $f) {
                    $filename = $f['filename'] ?? 'unknown';
                    $status   = $f['status'] ?? 'modified';
                    $add      = (int)($f['additions'] ?? 0);
                    $del      = (int)($f['deletions'] ?? 0);

                    if ($status !== 'removed') {
                        echo "- {$filename} ({$status}, +{$add}/-{$del})" . PHP_EOL;
                        if (!empty($f['patch'])) {
                            echo "-----------------------------" . PHP_EOL;
                            echo $f['patch'] . PHP_EOL;
                            echo "-----------------------------" . PHP_EOL;
                        }
                    }
                }
            }
        } else {
            $data = $this->gitlabCompare($fromTag, $toTag);
            $commits = $data['commits'] ?? [];

            if (!$commits) {
                $this->printNoCommitsWithTagHint($this->gitlabTags());
                return (string)ob_get_clean();
            }

            foreach ($commits as $c) {
                $this->printCommitGitlab($c);
            }

            echo str_repeat('-', 60) . PHP_EOL;
            echo "Commit count: " . count($commits) . PHP_EOL;

            $diffs = $data['diffs'] ?? [];
            if ($diffs) {
                echo PHP_EOL . "File changes:" . PHP_EOL;
                foreach ($diffs as $d) {
                    $newPath = $d['new_path'] ?? null;
                    $oldPath = $d['old_path'] ?? null;
                    $status  = 'modified';
                    if (!empty($d['new_file']))     $status = 'added';
                    elseif (!empty($d['deleted_file'])) $status = 'removed';
                    elseif (!empty($d['renamed_file'])) $status = 'renamed';

                    [$add, $del] = self::countAddDelFromUnifiedDiff($d['diff'] ?? null);

                    if ($status !== 'removed') {
                        echo "- " . ($newPath ?? $oldPath ?? 'unknown') . " ({$status}, +{$add}/-{$del})" . PHP_EOL;
                        if (!empty($d['diff'])) {
                            echo "-----------------------------" . PHP_EOL;
                            echo $d['diff'] . PHP_EOL;
                            echo "-----------------------------" . PHP_EOL;
                        }
                    }
                }
            }
        }

        echo PHP_EOL;
        $this->printReleaseNotesPrompt($fromTag, $toTag);

        return (string)ob_get_clean();
    }

    private function printHeaderLine(string $fromTag, string $toTag): void
    {
        echo "Commits for [{$this->provider}] {$this->owner}/{$this->repo} from {$fromTag} to {$toTag}" . PHP_EOL;
        echo str_repeat('=', 60) . PHP_EOL;
    }

    private function printNoCommitsWithTagHint(array $tags): void
    {
        echo "No commits found or tags are invalid." . PHP_EOL;
        if (!empty($tags)) {
            $tagNames = array_map(static fn($t) => $t['name'] ?? '', $tags);
            $first20 = array_slice($tagNames, 0, 20);
            echo "Available tags: " . implode(', ', $first20) . (count($tagNames) > 20 ? ', ...' : '') . PHP_EOL;
        }
        echo PHP_EOL;
    }

    private function printCommitGithub(array $c): void
    {
        $commit = $c['commit'] ?? [];
        $authorName = $commit['author']['name'] ?? ($c['author']['login'] ?? 'Unknown');
        $dateIso = $commit['author']['date'] ?? '';
        $date = $dateIso ? (new DateTimeImmutable($dateIso))->format('Y-m-d H:i') : '';
        $shaShort = substr((string)($c['sha'] ?? ''), 0, 7);
        $message = trim((string)($commit['message'] ?? ''));

        $this->printCommitLines($shaShort, $authorName, $date, $message);
    }

    private function printCommitGitlab(array $c): void
    {
        $authorName = (string)($c['author_name'] ?? 'Unknown');
        $dateIso = (string)($c['created_at'] ?? '');
        $date = $dateIso ? (new DateTimeImmutable($dateIso))->format('Y-m-d H:i') : '';
        $shaShort = substr((string)($c['id'] ?? ($c['short_id'] ?? '')), 0, 7);
        $message = trim((string)($c['message'] ?? ($c['title'] ?? '')));

        $this->printCommitLines($shaShort, $authorName, $date, $message);
    }

    private function printCommitLines(string $shaShort, string $authorName, string $date, string $message): void
    {
        $firstLine = strtok($message, "\n") ?: '';
        echo "- [{$shaShort}] {$firstLine}" . PHP_EOL;
        echo "  by {$authorName} on {$date}" . PHP_EOL;

        $rest = trim(substr($message, strlen($firstLine)));
        if ($rest !== '') {
            foreach (explode("\n", $rest) as $line) {
                if (trim($line) === '') continue;
                echo "  {$line}" . PHP_EOL;
            }
        }
    }

    private function printReleaseNotesPrompt(string $fromTag, string $toTag): void
    {
        echo "Please generate Release Notes in the following style:" . PHP_EOL . PHP_EOL;
        echo "- Always start with:" . PHP_EOL;
        echo "## Release {$toTag} – Changelog Summary" . PHP_EOL . PHP_EOL;
        echo "This release offers a short summary (1–3 sentences). Depending on the number of changes, a single sentence may suffice." . PHP_EOL;
        echo "Explain the focus of the release (e.g., better defaults, bug fixes, cleanup) and why it matters to users." . PHP_EOL;
        echo "Concise but informative." . PHP_EOL . PHP_EOL;
        echo "- Use 2–12 bullet points. Each point:" . PHP_EOL;
        echo "  - Starts with a **short, bold title** (e.g., “Configuration Improvements”)." . PHP_EOL;
        echo "  - Followed by a one- to two-line explanation with the user benefit on a new line." . PHP_EOL . PHP_EOL;
        echo "- End with a **Compatibility** line (e.g., “Backward compatible” or “Breaking Change: …”, bold “Compatibility”, single line)." . PHP_EOL;
        echo "- Then add a --- line" . PHP_EOL . PHP_EOL;
        echo "- Always finish with a “Full Changelog” link in this format:" . PHP_EOL;

        if ($this->provider === 'github') {
            $url = "https://github.com/{$this->owner}/{$this->repo}/compare/{$fromTag}...{$toTag}";
            echo "{$url}" . PHP_EOL . PHP_EOL;
            echo "**Full Changelog**: [{$url}]({$url})" . PHP_EOL;
        } else {
            $host = $this->gitlabHost !== '' ? $this->gitlabHost : 'https://gitlab.com';
            $url = "{$host}/{$this->owner}/{$this->repo}/-/compare/{$fromTag}...{$toTag}";
            echo "{$url}" . PHP_EOL . PHP_EOL;
            echo "**Full Changelog**: [{$url}]({$url})" . PHP_EOL;
        }
    }
}

try {
    [$reporter, $outFile] = (function (array $argv) {
        $built = CommitRangeReporter::fromArgs($argv);
        return [$built['reporter'], $built['outputFile']];
    })($argv);

    $reporter->run($outFile);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "❌ " . $e->getMessage() . PHP_EOL);
    exit(1);
}
