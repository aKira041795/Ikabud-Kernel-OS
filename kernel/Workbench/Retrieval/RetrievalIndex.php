<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Retrieval;

/**
 * RetrievalIndex — the repository a lane's context is drawn from.
 *
 * WHY THIS EXISTS
 * Before this, retrieval was a directory walk and a term count, repeated on every call, owned by
 * whoever needed it. Measured 2026-09-19: the repository had per-feature file stores
 * (storage/private/comprehension, storage/private/workbench/issues, metrics.json) and a heuristic
 * scorer, but nothing reusable -- no persisted index, no incrementality, and nothing another
 * subsystem could call. So every caller re-walked the tree and scored from scratch, and an "index"
 * never existed to be kept current.
 *
 * THIS IS A CACHE OF THE REPOSITORY'S TEXT, and it is designed around three properties that make it
 * usable rather than merely present:
 *
 *   CURRENT      A document is reindexed only when its content hash changes. `isCurrent()` answers
 *                the question directly, so a caller can trust the index instead of re-reading files,
 *                and a deleted file is REMOVED rather than lingering as a stale hit.
 *   REUSABLE     index / search / forget / stats / isCurrent. No hidden state, no globals, and the
 *                root is injected, so the harness, a CLI and a test all drive the same object.
 *   EXPLAINED    Every hit carries the terms that matched it and the score they produced. A retrieval
 *                result a reader cannot interrogate is a result nobody will trust.
 *
 * WHY FILES AND NOT A TABLE
 * Every Workbench subsystem already persists under storage/private (comprehension, issues, metrics,
 * ai-cache). A table would need a migration, MySQL 5.7 compatibility care on the deployment target
 * (no CTEs, no window functions), and a transaction to stay consistent with a filesystem it is
 * already describing. At this size -- a repository's source, a few thousand documents -- a single
 * JSON index is faster to build, atomic to write, and impossible to get out of step with the code.
 * The API is the reusable part: the storage behind it is one class, so a table can replace this file
 * without a caller changing.
 *
 * WHAT BREAKS AS THE DATA ACCUMULATES, AND WHAT TO DO ABOUT EACH (the future-proofing, in order of
 * what will bite first):
 *
 *   1. HASHING EVERY FILE ON EVERY CALL. Already addressed -- index() stats before it reads, so an
 *      unchanged tree costs a stat per file rather than a read and a hash. This is the cost that
 *      grows with the repository, and it is the first one to check if indexing slows down.
 *   2. LOADING AND REWRITING ONE JSON DOCUMENT. Fine to a few thousand documents; past that, shard
 *      the index by top-level prefix (kernel/, modules/, docs/, ...) behind the same API, and keep
 *      index.json as a manifest of shards. Nothing outside this class needs to change.
 *   3. RANKING THAT CANNOT LEARN. Lexical scoring cannot tell that `star-swarm.js` is the right answer
 *      because it served the last three tasks. recordUse() gives it that: usage is a bounded boost, so
 *      a file that has actually been used outranks one that merely mentions the words.
 *   4. VOCABULARY THAT DOES NOT MATCH THE QUESTION. Lexical search fails when the caller asks in
 *      different words than the code uses. search() reports `confidence`, and that is the decision
 *      point for a hybrid: LOW means the local index found nothing useful and is the only honest time
 *      to reach for an online or embedding-backed retriever. Local stays the default because code is
 *      identifier-shaped -- exact names are what a searcher wants -- and because it is private,
 *      offline and free. Online is a fallback for concepts, never the first call.
 *
 * @phpstan-type Entry array{hash:string, mtime:int, size:int, lines:int, terms:array<string,int>, uses:int, indexed_at:string}
 */
final class RetrievalIndex
{
    /** Words too common to discriminate. Kept in one place so indexing and querying agree. */
    private const STOPWORDS = [
        'the' => 1, 'and' => 1, 'for' => 1, 'with' => 1, 'that' => 1, 'this' => 1, 'from' => 1,
        'into' => 1, 'when' => 1, 'than' => 1, 'then' => 1, 'them' => 1, 'they' => 1, 'there' => 1,
        'must' => 1, 'not' => 1, 'are' => 1, 'was' => 1, 'were' => 1, 'its' => 1, 'has' => 1,
        'have' => 1, 'been' => 1, 'will' => 1, 'would' => 1, 'should' => 1, 'could' => 1, 'each' => 1,
        'also' => 1, 'only' => 1, 'more' => 1, 'most' => 1, 'such' => 1, 'same' => 1, 'because' => 1,
        'about' => 1, 'which' => 1, 'where' => 1, 'what' => 1, 'does' => 1, 'done' => 1, 'done' => 1,
    ];

    /** Extensions worth indexing. A retrieval index that eats binaries is a retrieval index that lies. */
    private const INDEXABLE = ['php', 'js', 'ts', 'tsx', 'jsx', 'css', 'disyl', 'json', 'md', 'sql', 'sh', 'html'];

    private const VERSION = 1;

    private string $indexPath;
    private string $lockPath;

    /**
     * @param string      $root            where the index itself lives
     * @param string|null $repositoryRoot  the tree the indexed paths are relative to. INJECTED, not
     *                                     inferred: the first version counted three parent directories
     *                                     from the index root, which worked in exactly one location and
     *                                     made the class unusable from anywhere else -- including its own
     *                                     test, where seven controls failed on path arithmetic alone.
     */
    public function __construct(
        private readonly string $root,
        ?string $repositoryRoot = null
    ) {
        $this->repositoryRoot = $repositoryRoot !== null
            ? rtrim($repositoryRoot, '/')
            : (realpath($root . '/../../..') ?: rtrim($root, '/'));
        $this->indexPath = rtrim($root, '/') . '/index.json';
        $this->lockPath = rtrim($root, '/') . '/index.lock';
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException("Unable to create retrieval index root: {$root}");
        }
    }

    private readonly string $repositoryRoot;

    public function root(): string
    {
        return $this->root;
    }

    public function repositoryRoot(): string
    {
        return $this->repositoryRoot;
    }

    // ── Indexing ────────────────────────────────────────────────────────────────────────────────

    /**
     * Index the given files and directories, skipping any document whose content has not changed.
     *
     * Incremental by content hash, and DIRECTIONAL: a file that vanished from the walk is removed, so
     * the index cannot answer with a path that no longer exists.
     *
     * @param list<string> $paths absolute or root-relative files and directories
     * @return array{indexed:int, unchanged:int, removed:int, skipped:int}
     */
    public function index(array $paths, bool $force = false): array
    {
        $state = $this->load();
        $documents = $state['documents'];
        $seen = [];
        $indexed = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($this->expand($paths) as $file) {
            $relative = $this->relative($file);
            $seen[$relative] = true;

            // Stat before reading. As a repository accumulates, reading and hashing every file on every
            // call is the cost that grows, and it grows with the part that did not change. The known
            // race -- a write inside one second with an unchanged size -- is what --force is for.
            $stat = @stat($file);
            if ($stat === false) {
                $skipped++;
                continue;
            }
            $entry = $documents[$relative] ?? null;
            if (!$force && $entry !== null
                && ($entry['mtime'] ?? -1) === $stat['mtime']
                && ($entry['size'] ?? -1) === $stat['size']) {
                $unchanged++;
                continue;
            }

            $contents = @file_get_contents($file);
            if ($contents === false) {
                $skipped++;
                continue;
            }

            $documents[$relative] = [
                'hash' => hash('sha256', $contents),
                'mtime' => (int) $stat['mtime'],
                'size' => (int) $stat['size'],
                'lines' => substr_count($contents, "\n") + 1,
                'terms' => $this->terms($contents, $relative),
                // Usage survives a reindex: a file that has served tasks stays useful when its content
                // changes slightly, and losing that on every edit would make the signal worthless.
                'uses' => (int) ($entry['uses'] ?? 0),
                'indexed_at' => gmdate(DATE_ATOM),
            ];
            $indexed++;
        }

        // Only prune when the walk covered something: a scoped index() call must not delete the rest
        // of the repository from the index. Removals are driven by the paths this call actually saw.
        $removed = 0;
        if ($seen !== []) {
            $prefixes = array_map(fn (string $file): string => $this->relative($file), $this->expand($paths));
            foreach (array_keys($documents) as $document) {
                if (isset($seen[$document])) {
                    continue;
                }
                $covered = false;
                foreach ($prefixes as $prefix) {
                    if ($document === $prefix) {
                        $covered = true;
                        break;
                    }
                }
                if ($covered || !is_file($this->absolute($document))) {
                    unset($documents[$document]);
                    $removed++;
                }
            }
        }

        $this->store(['version' => self::VERSION, 'updated_at' => gmdate(DATE_ATOM), 'documents' => $documents]);

        return ['indexed' => $indexed, 'unchanged' => $unchanged, 'removed' => $removed, 'skipped' => $skipped];
    }

    /** Is this document's indexed content still what is on disk? */
    public function isCurrent(string $path): bool
    {
        $relative = $this->relative($this->absolute($path));
        $entry = $this->load()['documents'][$relative] ?? null;
        if ($entry === null) {
            return false;
        }
        $contents = @file_get_contents($this->absolute($relative));

        return $contents !== false && hash('sha256', $contents) === $entry['hash'];
    }

    /**
     * Drop documents from the index.
     *
     * @param list<string> $paths files or directory prefixes
     * @return int the number removed
     */
    public function forget(array $paths): int
    {
        $state = $this->load();
        $prefixes = array_map(fn (string $path): string => rtrim($this->relative($this->absolute($path))), $paths);
        $removed = 0;
        foreach (array_keys($state['documents']) as $document) {
            foreach ($prefixes as $prefix) {
                if ($document === $prefix || str_starts_with($document, $prefix . '/')) {
                    unset($state['documents'][$document]);
                    $removed++;
                    break;
                }
            }
        }
        $state['updated_at'] = gmdate(DATE_ATOM);
        $this->store($state);

        return $removed;
    }

    // ── Searching ───────────────────────────────────────────────────────────────────────────────

    /**
     * Rank indexed documents against a query.
     *
     * Ranking, and why in this order:
     *   - a term in the PATH is worth far more than a term in the body: a file called `moon.php` is
     *     about the moon in a way that a file mentioning it once is not;
     *   - body frequency saturates, so a file that repeats one word cannot outrank one that covers
     *     the query;
     *   - a query term that appears nowhere does not silently vanish: coverage is reported, so a
     *     caller can tell "nothing matched" from "the index is empty".
     *
     * @param list<string> $scope optional path prefixes the result must fall under
     * @return array{query:string, terms:list<string>, hits:list<array{path:string, score:int, lines:int, matched:list<string>}>, indexed:int, missing:list<string>}
     */
    public function search(string $query, int $limit = 12, array $scope = []): array
    {
        $state = $this->load();
        $documents = $state['documents'];
        $terms = array_keys($this->terms($query, ''));

        $hits = [];
        $missing = $terms;
        foreach ($documents as $path => $entry) {
            if ($scope !== [] && !$this->underScope($path, $scope)) {
                continue;
            }
            $score = 0;
            $matched = [];
            foreach ($terms as $term) {
                $inPath = str_contains(strtolower($path), $term);
                $inBody = (int) ($entry['terms'][$term] ?? 0);
                if (!$inPath && $inBody === 0) {
                    continue;
                }
                $matched[] = $term;
                $score += ($inPath ? 8 : 0) + min(4, $inBody);
                $missing = array_values(array_diff($missing, [$term]));
            }
            if ($score > 0) {
                // Bounded, so a file that has been used a hundred times cannot outrank a clearly better
                // lexical match. Usage breaks ties and nudges; it does not decide.
                $score += min(6, (int) ($entry['uses'] ?? 0));
                $hits[] = ['path' => $path, 'score' => $score, 'lines' => (int) $entry['lines'], 'matched' => $matched, 'uses' => (int) ($entry['uses'] ?? 0)];
            }
        }

        usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['path'], $b['path']));

        $sliced = array_slice($hits, 0, max(0, $limit));
        $best = (int) ($sliced[0]['score'] ?? 0);

        // The decision point for a hybrid retriever. LOW is the only honest signal that local lexical
        // search has failed this question -- either nothing is indexed, the query has no searchable
        // words, nothing matched at all, or most of the query's words are absent from the repository.
        // A caller may then reach for an online or embedding-backed retriever; it should not do so when
        // this says `good`, because code is identifier-shaped and exact names beat semantic proximity.
        $confidence = 'good';
        if ($documents === []) {
            $confidence = 'empty';
        } elseif ($terms === [] || $hits === [] || count($missing) > count($terms) / 2 || $best < 4) {
            $confidence = 'low';
        }

        return [
            'query' => $query,
            'terms' => $terms,
            'hits' => $sliced,
            'indexed' => count($documents),
            'missing' => array_values($missing),
            'confidence' => $confidence,
        ];
    }

    /**
     * Record that these documents were actually used.
     *
     * The one signal a pure lexical index cannot derive: which files turned out to matter. The harness
     * knows, because it sees what a lane changed and which context preceded the change, so it reports
     * back and the next search ranks that file higher. This is what makes the index get more effective
     * with use rather than only larger.
     *
     * @param list<string> $paths
     * @return int how many were known to the index
     */
    public function recordUse(array $paths): int
    {
        $state = $this->load();
        $count = 0;
        foreach ($paths as $path) {
            $relative = $this->relative($this->absolute($path));
            if (!isset($state['documents'][$relative])) {
                continue;
            }
            $state['documents'][$relative]['uses'] = (int) ($state['documents'][$relative]['uses'] ?? 0) + 1;
            $count++;
        }
        if ($count > 0) {
            $state['updated_at'] = gmdate(DATE_ATOM);
            $this->store($state);
        }

        return $count;
    }

    /** @return array{documents:int, lines:int, bytes:int, updated_at:?string, stale:int, used:int, root:string} */
    public function stats(): array
    {
        $state = $this->load();
        $lines = 0;
        $stale = 0;
        $used = 0;
        foreach ($state['documents'] as $path => $entry) {
            $lines += (int) $entry['lines'];
            $used += (int) ($entry['uses'] ?? 0);
            $contents = @file_get_contents($this->absolute($path));
            if ($contents === false || hash('sha256', $contents) !== $entry['hash']) {
                $stale++;
            }
        }

        return [
            'documents' => count($state['documents']),
            'lines' => $lines,
            'bytes' => (int) (@filesize($this->indexPath) ?: 0),
            'updated_at' => $state['updated_at'] ?? null,
            'stale' => $stale,
            'used' => $used,
            'root' => $this->root,
        ];
    }

    // ── Internals ───────────────────────────────────────────────────────────────────────────────

    /**
     * Terms of a document: words of four or more characters, with stopwords removed.
     *
     * A term appearing only once is kept: in source code, a distinctive identifier used once is often
     * exactly the signal a searcher wants, and frequency filtering would delete the finding.
     *
     * @return array<string,int>
     */
    private function terms(string $text, string $path): array
    {
        $terms = [];
        foreach (preg_split('/[^a-z0-9_]+/i', strtolower($path . ' ' . $text)) ?: [] as $word) {
            if (strlen($word) < 4 || isset(self::STOPWORDS[$word])) {
                continue;
            }
            $terms[$word] = ($terms[$word] ?? 0) + 1;
        }

        return $terms;
    }

    /**
     * Resolve paths to indexable files.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    private function expand(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            $absolute = $this->absolute($path);
            if (is_file($absolute)) {
                if ($this->indexable($absolute)) {
                    $files[] = $absolute;
                }
                continue;
            }
            if (!is_dir($absolute)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $this->indexable($file->getPathname())) {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return array_values(array_unique($files));
    }

    private function indexable(string $file): bool
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($extension, self::INDEXABLE, true)) {
            return false;
        }
        // Vendored and generated trees describe other people's code, not this repository's. Indexing
        // them buries every real hit under framework noise.
        foreach (['/vendor/', '/node_modules/', '/.git/', '/storage/private/', '/test-results/'] as $skip) {
            if (str_contains(str_replace('\\', '/', $file), $skip)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $scope */
    private function underScope(string $document, array $scope): bool
    {
        foreach ($scope as $entry) {
            $prefix = trim((string) $entry, '/');
            $prefix = rtrim($prefix, '*');
            if ($prefix === '' || str_starts_with($document, rtrim($prefix, '/')) || str_starts_with($prefix, $document)) {
                return true;
            }
            if (str_contains($prefix, '*')) {
                $pattern = '#^' . str_replace('\*', '.*', preg_quote($prefix, '#')) . '#';
                if (preg_match($pattern, $document) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function absolute(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->repositoryRoot . '/' . ltrim($path, '/');
    }

    private function relative(string $file): string
    {
        $real = realpath($file) ?: $file;

        return ltrim(str_replace('\\', '/', str_replace($this->repositoryRoot, '', $real)), '/');
    }

    /** @return array{version:int, updated_at?:string, documents:array<string,Entry>} */
    private function load(): array
    {
        if (!is_file($this->indexPath)) {
            return ['version' => self::VERSION, 'documents' => []];
        }
        $decoded = json_decode((string) @file_get_contents($this->indexPath), true);
        if (!is_array($decoded) || !isset($decoded['documents']) || !is_array($decoded['documents'])) {
            // A corrupt index is a rebuild, not a crash: the data is derivable from the filesystem.
            return ['version' => self::VERSION, 'documents' => []];
        }

        return $decoded;
    }

    /** @param array<string,mixed> $state */
    private function store(array $state): void
    {
        $temporary = $this->indexPath . '.tmp';
        // LOCK_EX plus a rename: a reader never sees a half-written index, which is what makes the
        // index trustworthy enough to replace reading the files.
        file_put_contents($temporary, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        if (!rename($temporary, $this->indexPath)) {
            throw new \RuntimeException("Unable to write retrieval index: {$this->indexPath}");
        }
    }
}
