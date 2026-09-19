<?php

declare(strict_types=1);

/**
 * RetrievalIndex CLI.
 *
 * usage:
 *   php kernel/Workbench/Retrieval/run.php index [path...] [--force] [--root=<dir>]
 *   php kernel/Workbench/Retrieval/run.php search "<query>" [--limit=12] [--scope=path]
 *   php kernel/Workbench/Retrieval/run.php stats
 *   php kernel/Workbench/Retrieval/run.php forget <path...>
 *   php kernel/Workbench/Retrieval/run.php --self-test
 *
 * With no paths, `index` covers the repository's source trees. That default is intentional: an index
 * nobody remembers to feed is an index that goes stale, and the whole point of this one is that a
 * caller can ask `isCurrent()` and believe the answer.
 */

require_once __DIR__ . '/RetrievalIndex.php';

use Ikabud\Kernel\Workbench\Retrieval\RetrievalIndex;

const RETRIEVAL_ROOT = __DIR__ . '/../../../storage/private/retrieval';
const RETRIEVAL_DEFAULT_PATHS = ['kernel', 'src', 'public', 'templates', 'modules', 'docs', 'tools', 'tests'];

/** @return array{args:list<string>, options:array<string,string>, flags:list<string>} */
function retrievalCli(array $argv): array
{
    $args = [];
    $options = [];
    $flags = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
            [$name, $value] = explode('=', substr($argument, 2), 2);
            $options[$name] = $value;
        } elseif (str_starts_with($argument, '--')) {
            $flags[] = substr($argument, 2);
        } else {
            $args[] = $argument;
        }
    }

    return ['args' => $args, 'options' => $options, 'flags' => $flags];
}

function retrievalRoot(array $options): string
{
    return isset($options['root']) ? rtrim($options['root'], '/') : RETRIEVAL_ROOT;
}

/**
 * Controls for the index, in both directions: what it must find, and what it must not claim.
 *
 * The store is a cache of the filesystem, so every assertion here is about the two ways a cache
 * lies -- it serves something that changed, or it serves something that is gone.
 */
function retrievalSelfTest(): int
{
    $pass = 0;
    $fail = 0;
    $check = static function (string $label, bool $ok) use (&$pass, &$fail): void {
        if ($ok) {
            $pass++;
            fwrite(STDOUT, "  [PASS] {$label}\n");
            return;
        }
        $fail++;
        fwrite(STDOUT, "  [FAIL] {$label}\n");
    };

    $sandbox = sys_get_temp_dir() . '/retrieval-selftest-' . bin2hex(random_bytes(4));
    mkdir($sandbox . '/a', 0775, true);
    mkdir($sandbox . '/b', 0775, true);
    file_put_contents($sandbox . '/a/moon_renderer.php', "<?php\n// draws a cratered moon over black space\nfunction drawMoon() { return 'crater'; }\n");
    file_put_contents($sandbox . '/b/lance_pickup.php', "<?php\n// the plasma lance carrier drops a pickup\nfunction dropLance() { return 'carrier'; }\n");
    file_put_contents($sandbox . '/b/notes.md', "unrelated prose about nothing in particular\n");

    $index = new RetrievalIndex($sandbox . '/.index', $sandbox);

    fwrite(STDOUT, "indexing:\n");
    $first = $index->index([$sandbox . '/a', $sandbox . '/b']);
    $check('a first pass indexes the files', $first['indexed'] === 3);
    $second = $index->index([$sandbox . '/a', $sandbox . '/b']);
    $check('a second pass reindexes nothing (incremental by content hash)', $second['indexed'] === 0 && $second['unchanged'] === 3);
    $forced = $index->index([$sandbox . '/a'], true);
    $check('--force reindexes regardless of hash', $forced['indexed'] === 1 && $forced['unchanged'] === 0);

    fwrite(STDOUT, "\nsearching:\n");
    $moon = $index->search('cratered moon black space', 5);
    $check('a query finds the file that is about it', ($moon['hits'][0]['path'] ?? '') !== '' && str_contains($moon['hits'][0]['path'], 'moon_renderer.php'));
    $check('a hit explains itself with the terms that matched', ($moon['hits'][0]['matched'] ?? []) !== []);
    $check('an unrelated file is not returned for a specific query', count(array_filter($moon['hits'], static fn (array $h): bool => str_contains($h['path'], 'notes.md'))) === 0);

    $lance = $index->search('carrier pickup lance');
    $check('a second query finds a different file', str_contains($lance['hits'][0]['path'] ?? '', 'lance_pickup.php'));

    $missing = $index->search('zzzznotpresent');
    $check('a query with no indexed term reports it rather than pretending', $missing['missing'] === ['zzzznotpresent'] && $missing['hits'] === []);

    $stopworded = $index->search('the and for with');
    $check('stopwords alone retrieve nothing (they do not discriminate)', $stopworded['terms'] === []);

    fwrite(STDOUT, "\nscope:\n");
    $scoped = $index->search('moon', 5, ['a']);
    $check('scope bounds the search to its prefix', $scoped['hits'] === [] || str_contains($scoped['hits'][0]['path'], '/a/') === false || str_contains($scoped['hits'][0]['path'], 'moon'));
    $global = $index->search('moon', 5);
    $check('an unscoped search still sees everything', $global['hits'] !== []);

    fwrite(STDOUT, "\ncurrency — the two ways a cache lies:\n");
    $check('a fresh document is current', $index->isCurrent($sandbox . '/a/moon_renderer.php'));
    file_put_contents($sandbox . '/a/moon_renderer.php', "<?php\n// now it draws a ringed planet instead\n");
    $check('an edited document is NOT current', !$index->isCurrent($sandbox . '/a/moon_renderer.php'));
    $index->index([$sandbox . '/a']);
    $check('reindexing makes it current again', $index->isCurrent($sandbox . '/a/moon_renderer.php'));

    unlink($sandbox . '/b/notes.md');
    $removed = $index->index([$sandbox . '/a', $sandbox . '/b']);
    $check('a deleted file is removed from the index', $removed['removed'] === 1);
    $check('the deleted file can no longer be retrieved', $index->search('unrelated prose particular')['hits'] === []);

    fwrite(STDOUT, "\nforget and stats:\n");
    $dropped = $index->forget([$sandbox . '/b']);
    $check('forget removes a directory prefix', $dropped === 1);
    $stats = $index->stats();
    $check('stats reports what is actually indexed', $stats['documents'] === 1 && $stats['lines'] > 0);
    $check('stats reports nothing stale after a reindex', $stats['stale'] === 0);

    fwrite(STDOUT, "\nrobustness:\n");
    file_put_contents($sandbox . '/.index/index.json', 'not json at all');
    $recovered = $index->search('cratered moon');
    $check('a corrupt index degrades to empty rather than crashing', $recovered['hits'] === [] && $recovered['indexed'] === 0);

    // Clean up whatever is left.
    foreach ([$sandbox . '/a/moon_renderer.php', $sandbox . '/.index/index.json', $sandbox . '/.index/index.lock'] as $file) {
        @unlink($file);
    }
    @rmdir($sandbox . '/a');
    @rmdir($sandbox . '/b');
    @rmdir($sandbox . '/.index');
    @rmdir($sandbox);

    fwrite(STDOUT, sprintf("\n  => %d passed, %d failed\n", $pass, $fail));

    return $fail === 0 ? 0 : 1;
}

$cli = retrievalCli($argv);

if (in_array('self-test', $cli['flags'], true)) {
    exit(retrievalSelfTest());
}

$index = new RetrievalIndex(retrievalRoot($cli['options']), dirname(__DIR__, 3));
$command = $cli['args'][0] ?? '';
$rest = array_slice($cli['args'], 1);

switch ($command) {
    case 'index':
        $paths = $rest !== [] ? $rest : RETRIEVAL_DEFAULT_PATHS;
        $result = $index->index($paths, in_array('force', $cli['flags'], true));
        printf(
            "indexed %d, unchanged %d, removed %d, skipped %d\n",
            $result['indexed'],
            $result['unchanged'],
            $result['removed'],
            $result['skipped']
        );
        exit(0);

    case 'search':
        $query = implode(' ', $rest);
        if ($query === '') {
            fwrite(STDERR, "usage: run.php search \"<query>\" [--limit=N] [--scope=path]\n");
            exit(2);
        }
        $scope = isset($cli['options']['scope']) ? explode(',', $cli['options']['scope']) : [];
        $result = $index->search($query, (int) ($cli['options']['limit'] ?? 12), $scope);
        printf("%d hit(s) from %d indexed\n", count($result['hits']), $result['indexed']);
        foreach ($result['hits'] as $hit) {
            printf("  %4d  %-60s (%d lines)  [%s]\n", $hit['score'], $hit['path'], $hit['lines'], implode(' ', $hit['matched']));
        }
        if ($result['missing'] !== []) {
            printf("  not indexed anywhere: %s\n", implode(' ', $result['missing']));
        }
        exit($result['hits'] === [] ? 1 : 0);

    case 'stats':
        $stats = $index->stats();
        printf(
            "documents %d, lines %d, index %d bytes, updated %s, stale %d\nroot: %s\n",
            $stats['documents'],
            $stats['lines'],
            $stats['bytes'],
            $stats['updated_at'] ?? 'never',
            $stats['stale'],
            $stats['root']
        );
        exit(0);

    case 'forget':
        if ($rest === []) {
            fwrite(STDERR, "usage: run.php forget <path...>\n");
            exit(2);
        }
        printf("forgot %d document(s)\n", $index->forget($rest));
        exit(0);
}

fwrite(STDERR, "usage: run.php <index|search|stats|forget> [...] | --self-test\n");
exit(2);
