<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

/** Page helpers for the module's views: inline CSS/JS (Zabbix's addJsFile only serves core files). */
class View {

	public const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

	/** CSRF tokens for the POST actions a page calls. */
	public static function tokens(array $actions): array {
		$out = [];
		foreach ($actions as $a) {
			$out[$a] = \CCsrfTokenHelper::get($a);
		}

		return $out;
	}

	public static function assets(array $config, array $js): string {
		$base = dirname(__DIR__).'/assets';
		$out = '<style>'.file_get_contents($base.'/css/hostflow.css').'</style>';
		$config['csrfName'] = CSRF_TOKEN_NAME;
		$config['moduleUrl'] = 'modules/'.basename(dirname(__DIR__));
		$out .= '<script>window.HF = Object.assign(window.HF || {}, '.json_encode($config, self::JSON_FLAGS).');</script>';
		foreach (array_merge(['hostflow.common.js'], $js) as $f) {
			if (strpos($f, 'drawio/') === 0) {
				// The bundled draw.io viewer is large; load it as a normal (cacheable) file.
				$d = $config['moduleUrl'].'/assets/drawio';
				$out .= '<script>window.STENCIL_PATH='.json_encode($d.'/stencils').';window.IMAGE_PATH='.json_encode($d.'/images')
					.';window.STYLE_PATH='.json_encode($d.'/styles').';window.SHAPES_PATH='.json_encode($d.'/shapes')
					.';window.RESOURCES_PATH='.json_encode($d.'/resources').';window.DRAW_MATH_URL='.json_encode($d.'/math4/es5')
					.';window.DRAWIO_BASE_URL='.json_encode($d).';window.mxLoadResources=false;</script>'
					.'<script src="'.htmlspecialchars($config['moduleUrl'].'/assets/'.$f).'"></script>';
				continue;
			}
			$out .= '<script>'.file_get_contents($base.'/js/'.basename($f)).'</script>';
		}

		return $out;
	}

	/** $title '' leaves out Zabbix's page header, for full-height pages that show their own title. */
	public static function page(string $title, string $html, array $config, array $js, string $class = ''): void {
		(new \CHtmlPage())
			->setTitle($title)
			->addItem(new \CObject('<div class="hf'.($class !== '' ? ' '.$class : '').'">'.$html.'</div>'.self::assets($config, $js)))
			->show();
	}
}
