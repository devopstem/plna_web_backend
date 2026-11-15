<?php

/**
 * Re-apply PLNA compatibility fixes to ozankurt/laravel-security after composer
 * install/update:
 * 1) UUID/ULID user ids (upstream types user_id as ?int)
 * 2) ACL cache must not store Eloquent models (Incomplete_Class in Redis)
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$helper = $root.'/vendor/ozankurt/laravel-security/src/Firewall/Traits/MiddlewareHelper.php';
$abstract = $root.'/vendor/ozankurt/laravel-security/src/Firewall/AbstractMiddleware.php';
$acl = $root.'/vendor/ozankurt/laravel-security/src/Services/Acl/AclEvaluator.php';

if (! is_file($helper) || ! is_file($abstract) || ! is_file($acl)) {
    fwrite(STDERR, "[shield-compat] package files missing — skip\n");

    exit(0);
}

$helperContents = file_get_contents($helper);
$abstractContents = file_get_contents($abstract);
$aclContents = file_get_contents($acl);

if ($helperContents === false || $abstractContents === false || $aclContents === false) {
    fwrite(STDERR, "[shield-compat] cannot read package files\n");
    exit(1);
}

$changed = false;

if (str_contains($helperContents, 'public ?int $user_id = null;')) {
    $helperContents = str_replace(
        'public ?int $user_id = null;',
        "// PLNA uses UUID/ULID user ids — package upstream types this as ?int.\n    public int|string|null \$user_id = null;",
        $helperContents,
    );
    $changed = true;
}

if (preg_match('/\?int\s+\$user_id\s*=\s*null/', $helperContents)
    && str_contains($helperContents, 'public function log(')) {
    $replaced = preg_replace(
        '/public function log\(\s*\?string\s+\$middleware = null,\s*\?int\s+\$user_id = null,\s*LogLevel \$level = LogLevel::MEDIUM\s*\): Log\s*\{/s',
        "public function log(\n        ?string \$middleware = null,\n        int|string|null \$user_id = null,\n        LogLevel \$level = LogLevel::MEDIUM\n    ): Log {",
        $helperContents,
        1,
    );
    if (is_string($replaced) && $replaced !== $helperContents) {
        $helperContents = $replaced;
        $changed = true;
    }
}

if (str_contains($abstractContents, '$this->user_id = auth()->id() ?: 0;')) {
    $abstractContents = str_replace(
        '$this->user_id = auth()->id() ?: 0;',
        "// Keep 0 for guests; allow string UUID/ULID for authenticated users.\n        \$this->user_id = auth()->id() ?? 0;",
        $abstractContents,
    );
    $changed = true;
}

$aclNeedle = <<<'PHP'
    public function evaluate(Request $request): Decision
    {
        $cacheKey = $this->decisionCacheKey($request);
        $ttl = config('shield.acl.decision_cache_ttl', 60);

        return Cache::remember($cacheKey, $ttl, function () use ($request) {
            return $this->evaluateUncached($request);
        });
    }
PHP;

$aclReplace = <<<'PHP'
    public function evaluate(Request $request): Decision
    {
        // Do not cache Decision objects: they embed Eloquent models and break
        // Redis/file unserialize as __PHP_Incomplete_Class after deploys.
        return $this->evaluateUncached($request);
    }
PHP;

if (str_contains($aclContents, 'Cache::remember($cacheKey, $ttl')) {
    $aclContents = str_replace($aclNeedle, $aclReplace, $aclContents);
    $changed = true;
}

$liveNeedle = <<<'PHP'
    private function liveEntries()
    {
        return Cache::rememberForever('shield.acl.live', function () {
            return Acl::query()
                ->active()
                ->orderBy('id')
                ->get();
        });
    }
PHP;

$liveReplace = <<<'PHP'
    private function liveEntries()
    {
        // Do not Cache::rememberForever Eloquent Collections — Redis unserialize
        // yields Incomplete_Class and crashes every authenticated request.
        return Acl::query()
            ->active()
            ->orderBy('id')
            ->get();
    }
PHP;

if (str_contains($aclContents, "Cache::rememberForever('shield.acl.live'")) {
    $aclContents = str_replace($liveNeedle, $liveReplace, $aclContents);
    $changed = true;
}

if ($changed) {
    file_put_contents($helper, $helperContents);
    file_put_contents($abstract, $abstractContents);
    file_put_contents($acl, $aclContents);
    fwrite(STDOUT, "[shield-compat] applied UUID + ACL cache compatibility patch\n");
} else {
    fwrite(STDOUT, "[shield-compat] already applied\n");
}
