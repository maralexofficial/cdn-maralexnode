<?php

declare(strict_types=1);

$rootDirectory = __DIR__;

$excludedDirectories = [
    '.git',
    'node_modules',
    'vendor',
    'storage',
    'logs',
    'files',
    'pages',
];

$excludedFiles = [
    '.env',
    '.htaccess',
    'composer.lock',
    'package-lock.json',
];

$excludedExtensions = [
    'php',
    'sql',
    'sqlite',
    'sqlite3',
    'log',
    'bak',
    'sh',
    'html',
];

$hideHiddenFiles = true;

function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function formatBytes(int|false $bytes): string
{
    if ($bytes === false) {
        return 'Unknown';
    }

    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    $units = ['KB', 'MB', 'GB', 'TB'];
    $size = $bytes / 1024;

    foreach ($units as $unit) {
        if ($size < 1024 || $unit === 'TB') {
            return number_format($size, 2, '.', ',') . ' ' . $unit;
        }

        $size /= 1024;
    }

    return 'Unknown';
}

function isHidden(string $name): bool
{
    return str_starts_with($name, '.');
}

$rootRealPath = realpath($rootDirectory);

if ($rootRealPath === false) {
    http_response_code(500);
    exit('Root directory is unavailable.');
}

$relativePath = $_GET['dir'] ?? '';

if (!is_string($relativePath)) {
    http_response_code(400);
    exit('Invalid path.');
}

if (
    str_contains($relativePath, "\0")
    || str_starts_with($relativePath, '/')
    || preg_match('/^[A-Za-z]:/', $relativePath)
    || preg_match('~(^|[\\\\/])\.\.([\\\\/]|$)~', $relativePath)
) {
    http_response_code(400);
    exit('Invalid path.');
}

$targetPath = $relativePath === ''
    ? $rootRealPath
    : $rootRealPath . DIRECTORY_SEPARATOR . $relativePath;

$currentRealPath = realpath($targetPath);

if (
    $currentRealPath === false
    || !is_dir($currentRealPath)
    || (
        $currentRealPath !== $rootRealPath
        && !str_starts_with(
            $currentRealPath,
            $rootRealPath . DIRECTORY_SEPARATOR
        )
    )
) {
    http_response_code(403);
    exit('Access denied.');
}

$currentRelativePath = ltrim(
    substr($currentRealPath, strlen($rootRealPath)),
    DIRECTORY_SEPARATOR
);

$items = [];

try {
    $iterator = new FilesystemIterator(
        $currentRealPath,
        FilesystemIterator::SKIP_DOTS
    );

    foreach ($iterator as $item) {
        $name = $item->getFilename();

        if ($hideHiddenFiles && isHidden($name)) {
            continue;
        }

        if (
            in_array($name, $excludedDirectories, true)
            || in_array($name, $excludedFiles, true)
        ) {
            continue;
        }

        if ($item->isLink()) {
            continue;
        }

        $isDirectory = $item->isDir();

        if (!$isDirectory) {
            $extension = strtolower(
                pathinfo($name, PATHINFO_EXTENSION)
            );

            if (in_array($extension, $excludedExtensions, true)) {
                continue;
            }
        }

        $relativeItemPath = $currentRelativePath === ''
            ? $name
            : $currentRelativePath . '/' . $name;

        $items[] = [
            'name' => $name,
            'path' => $relativeItemPath,
            'directory' => $isDirectory,
            'size' => $isDirectory ? false : $item->getSize(),
            'modified' => $item->getMTime(),
        ];
    }
} catch (UnexpectedValueException $exception) {
    http_response_code(403);
    exit('Unable to read directory.');
}

usort($items, static function (array $a, array $b): int {
    if ($a['directory'] !== $b['directory']) {
        return $a['directory'] ? -1 : 1;
    }

    return strnatcasecmp($a['name'], $b['name']);
});

$parentPath = dirname($currentRelativePath);
$hasParent = $currentRelativePath !== '' && $parentPath !== '.';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#020617">
    <title>Directory Listing</title>
    <script src="https://cdn.maralexno.de/tailwindcss/v4/tailwind.js"></script>
</head>
<body class="min-h-screen bg-slate-950 text-slate-100 antialiased">
    <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
        <header class="mb-8">
            <p class="mb-2 text-sm font-medium text-violet-400">
                FILE BROWSER
            </p>

            <h1 class="text-3xl font-bold tracking-tight">
                Directory Listing
            </h1>

            <p class="mt-3 break-all text-sm text-slate-400">
                /<?= e($currentRelativePath) ?>
            </p>
        </header>

        <section class="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-800 px-5 py-4">
                <span class="text-sm text-slate-400">
                    <?= count($items) ?> items
                </span>

                <?php if ($hasParent): ?>
                    <a
                        href="?dir=<?= rawurlencode($parentPath === '.' ? '' : $parentPath) ?>"
                        class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium hover:bg-slate-700"
                    >
                        ← Parent Directory
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($items === []): ?>
                <div class="px-5 py-16 text-center text-slate-400">
                    This directory is empty.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-950/60 text-xs uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-5 py-4">Name</th>
                                <th class="px-5 py-4">Size</th>
                                <th class="px-5 py-4">Last Modified</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-800">
                            <?php foreach ($items as $item): ?>
                                <tr class="transition hover:bg-slate-800/60">
                                    <td class="px-5 py-4">
                                        <?php if ($item['directory']): ?>
                                            <a
                                                href="?dir=<?= rawurlencode($item['path']) ?>"
                                                class="flex items-center gap-3 font-medium text-violet-300 hover:text-violet-200"
                                            >
                                                <span aria-hidden="true">📁</span>
                                                <?= e($item['name']) ?>
                                            </a>
                                        <?php else: ?>
                                            <div class="flex items-center gap-3 text-slate-200">
                                                <span aria-hidden="true">📄</span>
                                                <?= e($item['name']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td class="whitespace-nowrap px-5 py-4 text-slate-400">
                                        <?= $item['directory']
                                            ? '—'
                                            : e(formatBytes($item['size'])) ?>
                                    </td>

                                    <td class="whitespace-nowrap px-5 py-4 text-slate-400">
                                        <?= date('Y-m-d H:i', $item['modified']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <footer class="mt-6 text-xs text-slate-500">
            <?= e($_SERVER['HTTP_HOST'] ?? 'File Browser') ?>
            · <?= count($items) ?> items
        </footer>
    </div>
</body>
</html>