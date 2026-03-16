<?php
/**
 * PHP Web Router
 * Handles all incoming requests and routes them according to a configurable ruleset.
 */

define('CONFIG_FILE', __DIR__ . '/router.conf');
define('MAX_REDIRECTS', 10); // Hard safety limit

// ─── Load & Parse Config ────────────────────────────────────────────────────

function loadConfig(): array {
    $config = [
        'settings' => [
            'managed_domains' => [],
            'allowed_redirects' => 5,
            'logging' => false,
            'logfile' => __DIR__ . '/router.log',
            'maxsize' => 1000,
        ],
        'redirects' => [],
    ];

    if (!file_exists(CONFIG_FILE)) {
        return $config;
    }

    $lines = file(CONFIG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $section = null;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;

        // Section header
        if (preg_match('/^\[(.+)\]$/', $line, $m)) {
            $section = strtolower($m[1]);
            continue;
        }

        if ($section === 'settings') {
            if (!str_contains($line, '=')) continue;
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $key = strtolower($key);

            switch ($key) {
                case 'managed_domains':
                    $config['settings']['managed_domains'] = array_map(
                        'trim', explode(',', $value)
                    );
                    break;
                case 'allowed_redirects':
                    $config['settings']['allowed_redirects'] = max(1, (int)$value);
                    break;
                case 'logging':
                    $config['settings']['logging'] = in_array(
                        strtolower($value), ['true', '1', 'yes', 'enabled']
                    );
                    break;
                case 'logfile':
                    $config['settings']['logfile'] = $value;
                    break;
                case 'maxsize':
                    $config['settings']['maxsize'] = max(10, (int)$value);
                    break;
            }
        } elseif ($section === 'redirects') {
            if (!str_contains($line, '|')) continue;
            $parts = explode('|', $line);
            $parts = array_pad($parts, 5, '');

            $rule = [
                'pattern'        => trim($parts[0]),
                'target'         => trim($parts[1]),
                'type'           => in_array(trim($parts[2]), ['301', '302']) ? trim($parts[2]) : '302',
                'preserve_path'  => !in_array(strtolower(trim($parts[3])), ['false', '0', 'no']),
                'preserve_query' => !in_array(strtolower(trim($parts[4])), ['false', '0', 'no']),
            ];

            if ($rule['target'] !== '') {
                $config['redirects'][] = $rule;
            }
        }
    }

    return $config;
}

// ─── Logging ────────────────────────────────────────────────────────────────

function logEntry(array $cfg, string $message): void {
    if (!$cfg['settings']['logging']) return;

    $logfile = $cfg['settings']['logfile'];
    $maxLines = $cfg['settings']['maxsize'];
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] $message\n";

    if (file_exists($logfile)) {
        $lines = file($logfile, FILE_IGNORE_NEW_LINES);
        if (count($lines) >= $maxLines) {
            $keep = array_slice($lines, -(($maxLines - 1)));
            file_put_contents($logfile, implode("\n", $keep) . "\n" . $entry);
            return;
        }
    }

    file_put_contents($logfile, $entry, FILE_APPEND | LOCK_EX);
}

// ─── Pattern Matching ───────────────────────────────────────────────────────

function matchesPattern(string $pattern, string $host, string $path, array $managedDomains): bool {
    if ($pattern === '') return true; // catch-all

    // Pattern contains a slash → host/path match
    if (str_contains($pattern, '/')) {
        [$pHost, $pPath] = explode('/', $pattern, 2);
        if (!fnmatch($pHost, $host)) return false;
        return str_starts_with(ltrim($path, '/'), ltrim($pPath, '/'));
    }

    // Wildcard host (e.g. *.dev.t71.ch)
    if (str_starts_with($pattern, '*.')) {
        return fnmatch($pattern, $host);
    }

    // Exact host match (contains a dot → fully qualified)
    if (str_contains($pattern, '.')) {
        return strcasecmp($pattern, $host) === 0;
    }

    // Plain subdomain → try against all managed domains
    foreach ($managedDomains as $domain) {
        $domain = trim($domain);
        if ($domain === '') continue;
        if (strcasecmp("$pattern.$domain", $host) === 0) return true;
    }

    return false;
}

// ─── Build Redirect Target ──────────────────────────────────────────────────

function buildTarget(string $base, string $path, string $query, bool $preservePath, bool $preserveQuery): string {
    $url = rtrim($base, '/');

    if ($preservePath && $path !== '' && $path !== '/') {
        $url .= '/' . ltrim($path, '/');
    }

    if ($preserveQuery && $query !== '') {
        $url .= (str_contains($url, '?') ? '&' : '?') . $query;
    }

    return $url;
}

// ─── Serve Static File ──────────────────────────────────────────────────────

function tryServeFile(string $requestUri): bool {
    $path = parse_url($requestUri, PHP_URL_PATH) ?? '/';
    $localPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . ltrim($path, '/');

    $realLocal = realpath($localPath);
    $realRoot  = realpath($_SERVER['DOCUMENT_ROOT']);

    if ($realLocal === false || $realRoot === false) return false;
    if (!str_starts_with($realLocal, $realRoot)) return false;

    // Never serve the router itself
    if ($realLocal === realpath(__FILE__)) return false;

    if (is_file($realLocal)) {
        $ext = strtolower(pathinfo($realLocal, PATHINFO_EXTENSION));
        $mimeMap = [
            'html' => 'text/html', 'htm' => 'text/html',
            'css'  => 'text/css',  'js'  => 'application/javascript',
            'json' => 'application/json', 'xml' => 'application/xml',
            'png'  => 'image/png', 'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg','gif'  => 'image/gif',
            'svg'  => 'image/svg+xml', 'ico' => 'image/x-icon',
            'pdf'  => 'application/pdf', 'txt' => 'text/plain',
            'woff' => 'font/woff', 'woff2' => 'font/woff2',
            'ttf'  => 'font/ttf',  'otf'  => 'font/otf',
            'webp' => 'image/webp','mp4'  => 'video/mp4',
            'webm' => 'video/webm','mp3'  => 'audio/mpeg',
        ];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($realLocal));
        readfile($realLocal);
        return true;
    }

    if (is_dir($realLocal)) {
        // index.php in subdirectories is fine, just not the router itself (already caught above)
        foreach (['index.html', 'index.htm', 'index.php'] as $idx) {
            $idxPath = rtrim($realLocal, '/') . '/' . $idx;
            $realIdx = realpath($idxPath);
            if ($realIdx === false) continue;
            if ($realIdx === realpath(__FILE__)) continue; // skip router
            if (file_exists($idxPath)) {
                if ($idx === 'index.php') {
                    include $idxPath;
                } else {
                    header('Content-Type: text/html');
                    readfile($idxPath);
                }
                return true;
            }
        }
    }

    return false;
}

// ─── Main Router Logic ───────────────────────────────────────────────────────

$config = loadConfig();

$requestUri  = $_SERVER['REQUEST_URI'] ?? '/';
$host        = strtolower($_SERVER['HTTP_HOST'] ?? '');
$host        = preg_replace('/:\d+$/', '', $host); // strip port
$path        = parse_url($requestUri, PHP_URL_PATH) ?? '/';
$queryString = $_SERVER['QUERY_STRING'] ?? '';

$managedDomains = $config['settings']['managed_domains'];
$maxRedirects   = min($config['settings']['allowed_redirects'], MAX_REDIRECTS);

// ── Rule 1: Can the URL be served directly? ──────────────────────────────────
if (tryServeFile($requestUri)) {
    logEntry($config, "SERVE $host$path");
    exit;
}

// ── Determine if this host is in a managed domain ────────────────────────────
$isManagedHost = false;
foreach ($managedDomains as $domain) {
    $domain = trim($domain);
    if ($domain === '') continue;
    if (
        strcasecmp($host, $domain) === 0 ||
        str_ends_with(strtolower($host), '.' . strtolower($domain))
    ) {
        $isManagedHost = true;
        break;
    }
}

if (!$isManagedHost) {
    http_response_code(404);
    logEntry($config, "404 NOT_MANAGED $host$path");
    echo "404 – Not Found";
    exit;
}

// ── Rule 2: Subdomain / URL routing ─────────────────────────────────────────
$redirectCount = 0;
$currentHost   = $host;
$currentPath   = $path;
$currentQuery  = $queryString;

while ($redirectCount < $maxRedirects) {

    // 2a: Check config redirects
    foreach ($config['redirects'] as $rule) {
        if (matchesPattern($rule['pattern'], $currentHost, $currentPath, $managedDomains)) {
            $target = buildTarget(
                $rule['target'],
                $currentPath,
                $currentQuery,
                $rule['preserve_path'],
                $rule['preserve_query']
            );

            logEntry($config, "REDIRECT [{$rule['type']}] $currentHost$currentPath → $target (rule: {$rule['pattern']})");

            http_response_code((int)$rule['type']);
            header('Location: ' . $target);
            exit;
        }
    }

    // 2b: Subdomain fallback → sld.tld/subdomain/
    $parts = explode('.', $currentHost);
    if (count($parts) >= 3) {
        $subdomain  = implode('.', array_slice($parts, 0, -2));
        $baseDomain = implode('.', array_slice($parts, -2));
        $fallbackTarget = "https://$baseDomain/" . ltrim($subdomain, '.') . '/';

        if ($currentPath !== '/' && $currentPath !== '') {
            $fallbackTarget = buildTarget($fallbackTarget, $currentPath, $currentQuery, true, true);
        } elseif ($currentQuery !== '') {
            $fallbackTarget .= '?' . $currentQuery;
        }

        logEntry($config, "FALLBACK $currentHost$currentPath → $fallbackTarget");
        http_response_code(302);
        header('Location: ' . $fallbackTarget);
        exit;
    }

    break;
}

// ── Rule 3: 404 ───────────────────────────────────────────────────────────────
logEntry($config, "404 $currentHost$currentPath");
http_response_code(404);
echo "<!DOCTYPE html><html><head><title>404 Not Found</title></head>";
echo "<body><h1>404 – Not Found</h1>";
//-- echo "<p>The requested URL <code>" . htmlspecialchars($currentHost . $currentPath) . "</code> was not found.</p>";
echo "</body></html>";
exit;
