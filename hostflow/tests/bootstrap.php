<?php
// Plain PHP CLI test bootstrap: autoload lib/, stub the Zabbix translation helpers.
spl_autoload_register(static function (string $class): void {
	$prefix = 'Modules\\HostFlow\\Lib\\';
	if (strncmp($class, $prefix, strlen($prefix)) === 0) {
		$file = __DIR__.'/../lib/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
		if (is_file($file)) {
			require $file;
		}
	}
});
if (!function_exists('_')) { function _($s) { return $s; } }

$GLOBALS['__fails'] = 0; $GLOBALS['__pass'] = 0;
function ok($cond, string $name): void {
	if ($cond) { $GLOBALS['__pass']++; return; }
	$GLOBALS['__fails']++; fwrite(STDERR, "FAIL: $name\n");
}
function throws(callable $fn, string $name, string $contains = ''): void {
	try { $fn(); ok(false, "$name (no exception)"); }
	catch (Throwable $e) { ok($contains === '' || stripos($e->getMessage(), $contains) !== false, "$name: ".$e->getMessage()); }
}
function done(): void {
	printf("%d passed, %d failed\n", $GLOBALS['__pass'], $GLOBALS['__fails']);
	exit($GLOBALS['__fails'] ? 1 : 0);
}
