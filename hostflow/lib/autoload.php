<?php declare(strict_types = 0);

// Classes under Modules\HostFlow\Lib live in lib/ (Zabbix only autoloads Actions).
spl_autoload_register(static function (string $class): void {
	$prefix = 'Modules\\HostFlow\\Lib\\';
	if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
		return;
	}
	$file = __DIR__.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
	if (is_file($file)) {
		require $file;
	}
});
