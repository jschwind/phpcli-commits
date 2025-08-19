<?php
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle !== '' && substr($haystack, 0, strlen($needle)) === $needle;
    }
}

$fp = null;
if ($argc >= 3) {
    $outFile = $argv[2];
    $dir = dirname($outFile);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            fwrite(STDERR, "❌ Could not create output directory: $dir\n");
            exit(1);
        }
    }
    $fp = @fopen($outFile, "w");
    if (!$fp) {
        fwrite(STDERR, "❌ Could not open output file: $outFile\n");
        exit(1);
    }
    ob_start(function ($buf) use ($fp) {
        fwrite($fp, $buf);
        return $buf;
    });
    register_shutdown_function(function () use ($fp) {
        if ($fp) {
            fflush($fp);
            fclose($fp);
        }
    });
}

$owner      = "owner-name";
$repo       = "repo-name";
$fromTag    = "";
$toTag      = "";
$stepTag    = false;
$provider   = "github";
$githubTok  = null;
$gitlabTok  = null;
$gitlabHost = "";

$scriptDir  = __DIR__;
$configFile = null;

if ($argc >= 2 && is_file($argv[1])) {
    $configFile = $argv[1];
} else {
    $cwdFallback = getcwd() . DIRECTORY_SEPARATOR . "git.json";
    if (is_file($cwdFallback)) {
        $configFile = $cwdFallback;
    } else {
        $dirFallback = $scriptDir . DIRECTORY_SEPARATOR . "git.json";
        if (is_file($dirFallback)) {
            $configFile = $dirFallback;
        }
    }
}

if (!$configFile) {
    fwrite(STDERR, "❌ No configuration file found (expected: git.json or path as 1st argument)\n");
    exit(1);
}

$jsonRaw = @file_get_contents($configFile);
if ($jsonRaw === false) {
    fwrite(STDERR, "❌ Could not read configuration file: $configFile\n");
    exit(1);
}
$cfg = json_decode($jsonRaw, true);
if (!is_array($cfg)) {
    fwrite(STDERR, "❌ Invalid JSON in $configFile\n");
    exit(1);
}

$provider   = $cfg['provider']      ?? $provider;
$owner      = $cfg['owner']         ?? $owner;
$repo       = $cfg['repo']          ?? $repo;
$fromTag    = $cfg['fromTag']       ?? $fromTag;
$toTag      = $cfg['toTag']         ?? $toTag;
$githubTok  = $cfg['github_token']  ?? $githubTok;
$gitlabTok  = $cfg['gitlab_token']  ?? $gitlabTok;
$gitlabHost = rtrim($cfg['gitlab_host'] ?? $gitlabHost, "/");

function httpRequest(string $url, array $headers): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        fwrite(STDERR, "❌ cURL error: $err\n");
        exit(1);
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($status >= 400) {
        $msg = is_array($data) ? ($data['message'] ?? $data['error'] ?? "HTTP $status") : "HTTP $status";
        if ($status === 401 || $status === 403) {
            $msg .= " – Hint: For GitLab, use `PRIVATE-TOKEN: <PAT>` or a valid OAuth token. On self-hosted instances, also verify the base URL (gitlab_host).";
        }
        fwrite(STDERR, "❌ API error ($status): $msg\n");
        if (is_array($data) && !empty($data['documentation_url'])) {
            fwrite(STDERR, "Docs: {$data['documentation_url']}\n");
        }
        exit(1);
    }
    return is_array($data) ? $data : [];
}

function githubHeaders(?string $token): array {
    $h = [
        "Accept: application/vnd.github+json",
        "User-Agent: commit-range-script",
    ];
    if ($token) $h[] = "Authorization: Bearer ".$token;
    return $h;
}
function githubCompareUrl(string $owner, string $repo, string $fromTag, string $toTag): string {
    return "https://api.github.com/repos/$owner/$repo/compare/$fromTag...$toTag";
}
function githubTagsUrl(string $owner, string $repo): string {
    return "https://api.github.com/repos/$owner/$repo/tags?per_page=100";
}
function githubCompare(string $owner, string $repo, string $fromTag, string $toTag, ?string $token): array {
    return httpRequest(githubCompareUrl($owner, $repo, $fromTag, $toTag), githubHeaders($token));
}
function githubTags(string $owner, string $repo, ?string $token): array {
    return httpRequest(githubTagsUrl($owner, $repo), githubHeaders($token));
}

function gitlabHeaders(?string $token): array {
    $h = [
        "Accept: application/json",
        "User-Agent: commit-range-script",
    ];
    if ($token) {
        $h[] = "Authorization: Bearer ".$token;
        $h[] = "PRIVATE-TOKEN: ".$token;
    }
    return $h;
}
function gitlabProjectId(string $host, string $owner, string $repo): string {
    return rawurlencode("$owner/$repo");
}
function gitlabCompareUrl(string $host, string $projectId, string $fromTag, string $toTag): string {
    return "$host/api/v4/projects/$projectId/repository/compare?from=".rawurlencode($fromTag)."&to=".rawurlencode($toTag);
}
function gitlabTagsUrl(string $host, string $projectId): string {
    return "$host/api/v4/projects/$projectId/repository/tags?per_page=100";
}
function gitlabCompare(string $host, string $owner, string $repo, string $fromTag, string $toTag, ?string $token): array {
    $pid = gitlabProjectId($host, $owner, $repo);
    return httpRequest(gitlabCompareUrl($host, $pid, $fromTag, $toTag), gitlabHeaders($token));
}
function gitlabTags(string $host, string $owner, string $repo, ?string $token): array {
    $pid = gitlabProjectId($host, $owner, $repo);
    return httpRequest(gitlabTagsUrl($host, $pid), gitlabHeaders($token));
}

function printHeaderLine($provider, $owner, $repo, $fromTag, $toTag): void {
    echo "Commits for [$provider] $owner/$repo from $fromTag to $toTag\n";
    echo str_repeat("=", 60) . "\n";
}
function printCommit(array $c, string $provider): void {
    if ($provider === "github") {
        $commit     = $c['commit'] ?? [];
        $authorName = $commit['author']['name'] ?? ($c['author']['login'] ?? 'Unknown');
        $dateIso    = $commit['author']['date'] ?? '';
        $date       = $dateIso ? (new DateTime($dateIso))->format('Y-m-d H:i') : '';
        $shaShort   = substr($c['sha'] ?? '', 0, 7);
        $message    = trim($commit['message'] ?? '');
    } else {
        $authorName = $c['author_name'] ?? 'Unknown';
        $dateIso    = $c['created_at'] ?? '';
        $date       = $dateIso ? (new DateTime($dateIso))->format('Y-m-d H:i') : '';
        $shaShort   = substr($c['id'] ?? ($c['short_id'] ?? ''), 0, 7);
        $message    = trim($c['message'] ?? ($c['title'] ?? ''));
    }
    $firstLine  = strtok($message, "\n");
    echo "- [$shaShort] $firstLine\n";
    echo "    by $authorName on $date\n";
    $rest = trim(substr($message, strlen($firstLine)));
    if ($rest !== '') {
        foreach (explode("\n", $rest) as $line) {
            if (trim($line) === '') continue;
            echo "    $line\n";
        }
    }
}
function countAddDelFromUnifiedDiff(?string $diff): array {
    if (!$diff) return [0,0];
    $adds = 0; $dels = 0;
    foreach (explode("\n", $diff) as $line) {
        if ($line === '') continue;
        if ($line[0] === '+' && !str_starts_with($line, '+++')) $adds++;
        elseif ($line[0] === '-' && !str_starts_with($line, '---')) $dels++;
    }
    return [$adds, $dels];
}

$provider = strtolower($provider) === "gitlab" ? "gitlab" : "github";

printHeaderLine($provider, $owner, $repo, $fromTag, $toTag);

if ($provider === "github") {
    $data = githubCompare($owner, $repo, $fromTag, $toTag, $githubTok);
    $commits = $data['commits'] ?? [];
    if (!$commits) {
        echo "No commits found or tags are invalid.\n";
        $tags = githubTags($owner, $repo, $githubTok);
        if (!empty($tags)) {
            $tagNames = array_map(fn($t) => $t['name'] ?? '', $tags);
            echo "Available tags: " . implode(", ", array_slice($tagNames, 0, 20)) . (count($tagNames) > 20 ? ", ..." : "") . "\n";
        }
        exit(0);
    }
    foreach ($commits as $c) printCommit($c, "github");

    $total = $data['total_commits'] ?? count($commits);
    echo str_repeat("-", 60) . "\n";
    echo "Commit count: " . count($commits) . " (reported total: $total)\n";

    $files = $data['files'] ?? [];
    if ($files) {
        echo "\nFile changes:\n";
        foreach ($files as $f) {
            $filename = $f['filename'];
            $status   = $f['status'];
            $add      = $f['additions'] ?? 0;
            $del      = $f['deletions'] ?? 0;
            if ($status !== 'removed') {
                echo "- $filename ($status, +$add/-$del)\n";
                if (!empty($f['patch'])) {
                    echo "-----------------------------\n";
                    echo $f['patch'] . "\n";
                    echo "-----------------------------\n";
                }
            }
        }
    }
} else {
    $data = gitlabCompare($gitlabHost, $owner, $repo, $fromTag, $toTag, $gitlabTok);
    $commits = $data['commits'] ?? [];
    if (!$commits) {
        echo "No commits found or tags are invalid.\n";
        $tags = gitlabTags($gitlabHost, $owner, $repo, $gitlabTok);
        if (!empty($tags)) {
            $tagNames = array_map(fn($t) => $t['name'] ?? '', $tags);
            echo "Available tags: " . implode(", ", array_slice($tagNames, 0, 20)) . (count($tagNames) > 20 ? ", ..." : "") . "\n";
        }
        exit(0);
    }
    foreach ($commits as $c) printCommit($c, "gitlab");

    echo str_repeat("-", 60) . "\n";
    echo "Commit count: " . count($commits) . "\n";

    $diffs = $data['diffs'] ?? [];
    if ($diffs) {
        echo "\nFile changes:\n";
        foreach ($diffs as $d) {
            $newPath = $d['new_path'] ?? null;
            $oldPath = $d['old_path'] ?? null;
            $status  = 'modified';
            if (!empty($d['new_file'])) $status = 'added';
            elseif (!empty($d['deleted_file'])) $status = 'removed';
            elseif (!empty($d['renamed_file'])) $status = 'renamed';
            [$add, $del] = countAddDelFromUnifiedDiff($d['diff'] ?? null);
            if ($status !== 'removed') {
                echo "- " . ($newPath ?? $oldPath ?? 'unknown') . " ($status, +$add/-$del)\n";
                if (!empty($d['diff'])) {
                    echo "-----------------------------\n";
                    echo $d['diff'] . "\n";
                    echo "-----------------------------\n";
                }
            }
        }
    }
}

echo "\n";
echo "Please generate Release Notes in the following style:\n\n";
echo "- Always start with:\n";
echo "## Release $toTag – Changelog Summary\n\n";
echo "“This release offers a short summary (1–3 sentences). Depending on the number of changes, a single sentence may suffice.\n";
echo "Explain the focus of the release (e.g., better defaults, bug fixes, cleanup) and why it matters to users.\n";
echo "Concise but informative.”\n\n";
echo "- Use 2–12 bullet points. Each point:\n";
echo "  - Starts with a **short, bold title** (e.g., “Configuration Improvements”).\n";
echo "  - Followed by a one- to two-line explanation with the user benefit on a new line.\n\n";
echo "- End with a **Compatibility** line (e.g., “Backward compatible” or “Breaking Change: …”, bold “Compatibility”, single line).\n";
echo "- Then add a --- line\n\n";
echo "- Always finish with a “Full Changelog” link in this format:\n";
if (strtolower($provider) === "github") {
    echo "https://github.com/$owner/$repo/compare/$fromTag...$toTag\n\n";
    echo "**Full Changelog**: [https://github.com/$owner/$repo/compare/$fromTag...$toTag](https://github.com/$owner/$repo/compare/$fromTag...$toTag)\n";
} else {
    echo "$gitlabHost/$owner/$repo/-/compare/$fromTag...$toTag\n\n";
    echo "**Full Changelog**: [$gitlabHost/$owner/$repo/-/compare/$fromTag...$toTag]($gitlabHost/$owner/$repo/-/compare/$fromTag...$toTag)\n";
}
