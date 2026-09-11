<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Governance;

use RuntimeException;

/** Static route-to-capability census. Module PHP is tokenized, never loaded. */
final class GovernanceCensus
{
    public function __construct(private readonly string $root)
    {
    }

    /** @return array<string, mixed> */
    public function scanAll(): array
    {
        $files = glob($this->root . '/modules/*/module.json') ?: [];
        $files = array_merge($files, glob($this->root . '/modules/*/*/module.json') ?: []);
        $reports = [];
        foreach ($files as $file) {
            $reports[] = $this->scanManifest($file);
        }
        usort($reports, static fn ($a, $b) => strcmp($a['module'], $b['module']));
        return $this->result($reports);
    }

    /** @return array<string, mixed> */
    public function scan(string $module): array
    {
        foreach (array_merge(glob($this->root . '/modules/*/module.json') ?: [], glob($this->root . '/modules/*/*/module.json') ?: []) as $file) {
            $json = json_decode((string) file_get_contents($file), true);
            if (($json['id'] ?? '') === $module) {
                return $this->result([$this->scanManifest($file)]);
            }
        }
        throw new RuntimeException("Module not found: {$module}");
    }

    /**
     * @param list<array<string, mixed>> $modules
     * @return array<string, mixed>
     */
    private function result(array $modules): array
    {
        $summary = array_map(static fn ($m) => $m['summary'], $modules);
        return [
            'rule' => 'Ratio denominator: routed business operations (POST/PUT/PATCH/DELETE); auth/session infrastructure is excluded. GET/HEAD/OPTIONS presentation and query routes are evidenced but excluded.',
            'detection' => 'PHP token analysis follows calls between named functions. A bus call requires executable app()->cap()->call(...) tokens; comments, strings and docblocks are ignored.',
            'metric' => 'dispatch_enforced counts operations whose authority is declared in capabilities.routes and established before the handler body. bus_reachable counts handlers that themselves reach the bus and is reported separately: a bus call inside a handler is not request authority, so it does not make the route governed.',
            'modules' => $modules,
            'summary' => $summary,
        ];
    }

    /** @return array<string, mixed> */
    private function scanManifest(string $manifestFile): array
    {
        $manifest = json_decode((string) file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
        $id = (string) ($manifest['id'] ?? '');
        $dir = dirname($manifestFile);
        $routeRef = $manifest['routes'] ?? 'routes.php';
        if ($routeRef === true) {
            $routeRef = 'routes.php';
        }
        if ($routeRef === false) {
            $routeRef = '';
        }
        if (!is_string($routeRef)) {
            throw new RuntimeException("{$id}: routes must reference a static PHP route file");
        }
        $routesFile = $routeRef === '' ? '' : $dir . '/' . $routeRef;
        $routes = $routesFile !== '' && is_file($routesFile) ? $this->parseRoutes($routesFile) : [];
        $functions = $this->parseFunctions($dir);
        $exemptions = [];
        foreach (($manifest['governance']['exemptions'] ?? []) as $i => $item) {
            if (!is_array($item) || trim((string) ($item['reason'] ?? '')) === '') {
                throw new RuntimeException("{$id}: governance exemption #{$i} requires a non-empty reason");
            }
            $method = strtoupper((string) ($item['method'] ?? ''));
            $route = (string) ($item['route'] ?? '');
            if ($method === '' || $route === '') {
                throw new RuntimeException("{$id}: governance exemption #{$i} requires method and route");
            }
            $exemptions[$method . ' ' . $route] = trim((string) $item['reason']);
        }
        // Declared route authority (P2). The declaration lives in the manifest,
        // so the instrument stays domain-neutral: it reads a declaration, it does
        // not know what the capability means.
        $declarations = [];
        foreach (($manifest['capabilities']['routes'] ?? []) as $routeKey => $capabilityId) {
            $routeKey = trim((string) $routeKey);
            if ($routeKey === '' || !str_contains($routeKey, ' ')) {
                continue;
            }
            [$method, $route] = explode(' ', $routeKey, 2);
            $method = strtoupper(trim($method));
            $route = trim($route);
            if ($method === '' || $route === '' || !is_string($capabilityId)) {
                continue;
            }
            $declarations[$method . ' ' . $route] = $capabilityId;
        }
        $operations = [];
        foreach ($routes as $route) {
            $handler = str_contains($route['handler'], ':') ? explode(':', $route['handler'], 2)[1] : $route['handler'];
            $source = $functions[$handler] ?? null;
            $bus = $source ? $this->reachesBus($handler, $functions) : null;
            $key = $route['method'] . ' ' . $route['route'];

            // Two independent facts, never merged into one score:
            //   dispatch — is declared authority established BEFORE the handler body?
            //   reach    — does the handler body itself reach the capability bus?
            // Reporting only `reach` as "governed" measures the metric: a bus call
            // inside a handler proves nothing about the request's authority.
            if (isset($declarations[$key])) {
                $dispatch = 'enforced';
                $how = 'declared in module.json: ' . $declarations[$key] . ' (checked before the handler body)';
            } elseif (isset($exemptions[$key])) {
                $dispatch = 'exempt';
                $how = 'declared exempt in module.json: ' . $exemptions[$key];
            } else {
                $dispatch = 'undeclared';
                $how = 'no authority declared and no exemption declared';
            }

            if ($bus) {
                $reach = 'bus-reachable';
                $how .= '; executable capability bus call reached via ' . implode(' -> ', $bus);
            } elseif ($source) {
                $reach = 'no-bus-call';
                $how .= '; no executable capability bus call reachable from the handler';
            } else {
                $reach = 'unresolved';
                $how .= '; handler source not resolved';
            }

            $operations[] = array_merge($route, ['dispatch' => $dispatch, 'reach' => $reach, 'source' => $source ? $this->relative($source['file']) . ':' . $source['line'] : 'unresolved', 'how' => $how]);
        }
        $business = array_values(array_filter($operations, fn ($o) => $this->isBusiness($o)));
        $dispatchCounts = array_count_values(array_column($business, 'dispatch'));
        $reachCounts = array_count_values(array_column($business, 'reach'));
        $total = count($business);
        $enforced = $dispatchCounts['enforced'] ?? 0;
        $reachable = $reachCounts['bus-reachable'] ?? 0;
        return ['module' => $id, 'operations' => $operations, 'summary' => [
            'module' => $id,
            'dispatch_enforced' => $enforced,
            'bus_reachable' => $reachable,
            'exempt' => $dispatchCounts['exempt'] ?? 0,
            'undeclared' => $dispatchCounts['undeclared'] ?? 0,
            'total' => $total,
            'ratio' => $total ? round(100 * $enforced / $total, 1) : 0.0,
            'bus_ratio' => $total ? round(100 * $reachable / $total, 1) : 0.0,
        ]];
    }

    /** @param array<string, mixed> $o */
    private function isBusiness(array $o): bool
    {
        if (!in_array($o['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }
        // Infrastructure exclusion affects only the ratio, never classification.
        return preg_match('~(?:^|[/_-])(login|logout|refresh|forgot-password|reset-password|session)(?:$|[/_-])~i', $o['route']) !== 1;
    }

    /** @return list<array{route: string, method: string, handler: string}> */
    private function parseRoutes(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $depth = 0;
        $method = null;
        $pending = null;
        $out = [];
        foreach ($tokens as $token) {
            if ($token === '[') {
                $depth++;
                continue;
            } if ($token === ']') {
                $depth--;
                if ($depth < 2) {
                    $method = null;
                } continue;
            }
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $value = stripcslashes(substr($token[1], 1, -1));
            if ($depth === 1 && in_array(strtoupper($value), ['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'], true)) {
                $method = strtoupper($value);
                continue;
            }
            if ($depth === 2 && $method !== null) {
                if ($pending === null) {
                    $pending = $value;
                } else {
                    $out[] = ['route' => $pending, 'method' => $method, 'handler' => $value];
                    $pending = null;
                }
            }
        }
        return $out;
    }

    /** @return array<string, array{file: string, line: int, tokens: list<mixed>}> */
    private function parseFunctions(string $dir): array
    {
        $map = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php' || str_contains($f->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $t = token_get_all((string) file_get_contents($f->getPathname()));
            for ($i = 0,$n = count($t); $i < $n; $i++) {
                if (is_array($t[$i]) && $t[$i][0] === T_FUNCTION) {
                    for ($j = $i + 1; $j < $n && (!is_array($t[$j]) || $t[$j][0] !== T_STRING); $j++);
                    if ($j >= $n) {
                        continue;
                    }
                    $name = $t[$j][1];
                    for (; $j < $n && $t[$j] !== '{'; $j++);
                    if ($j >= $n) {
                        continue;
                    }
                    $body = [];
                    $d = 1;
                    for ($k = $j + 1; $k < $n && $d; $k++) {
                        if ($t[$k] === '{') {
                            $d++;
                        } elseif ($t[$k] === '}') {
                            $d--;
                        } if ($d) {
                            $body[] = $t[$k];
                        }
                    }
                    $map[$name] = ['file' => $f->getPathname(),'line' => $t[$i][2],'tokens' => $body];
                    $i = $k - 1;
                }
            }
        }
        return $map;
    }

    /**
     * @param array<string, array{file: string, line: int, tokens: list<mixed>}> $functions
     * @param array<string, bool> $seen
     * @return list<string>|null
     */
    private function reachesBus(string $name, array $functions, array $seen = []): ?array
    {
        if (isset($seen[$name]) || !isset($functions[$name])) {
            return null;
        } $seen[$name] = true;
        $tokens = $functions[$name]['tokens'];
        $sig = [];
        foreach ($tokens as $t) {
            if (!is_array($t) || !in_array($t[0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,T_CONSTANT_ENCAPSED_STRING], true)) {
                $sig[] = is_array($t) ? $t[1] : $t;
            }
        }
        if (str_contains(implode('', $sig), 'app()->cap()->call(')) {
            return [$name];
        }
        for ($i = 0,$n = count($sig);$i < $n - 1;$i++) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $sig[$i]) && $sig[$i + 1] === '(' && isset($functions[$sig[$i]])) {
                $path = $this->reachesBus($sig[$i], $functions, $seen);
                if ($path) {
                    return array_merge([$name], $path);
                }
            }
        }
        return null;
    }

    private function relative(string $p): string
    {
        return str_replace($this->root . '/', '', $p);
    }
}
