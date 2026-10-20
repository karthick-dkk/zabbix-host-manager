<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

/**
 * Component icons: a white glyph on a coloured rounded tile, as SVG.
 * Used in generated diagrams (draw.io label shapes) and in the shape library.
 */
class Icons {

	public const COLORS = [
		'ingest' => '#2f6db3', 'net' => '#0f8a8a', 'store' => '#1f8a5a', 'proc' => '#6a46c2',
		'front' => '#d1661b', 'asset' => '#c27a0e', 'zbx' => '#c8102e', 'neutral' => '#5b6977'
	];

	private const GLYPHS = [
		'fwd' => '<path d="M7 15h15M16 9l6 6-6 6"/>',
		'parser' => '<path d="M7 8h16l-6 8v7l-4-2v-5z"/>',
		'vpn' => '<rect x="9" y="14" width="12" height="10" rx="1.5"/><path d="M11 14v-3a4 4 0 0 1 8 0v3"/>',
		'es' => '<ellipse cx="15" cy="9" rx="7" ry="2.6"/><path d="M8 9v12a7 2.6 0 0 0 14 0V9M8 15a7 2.6 0 0 0 14 0"/>',
		'ui' => '<rect x="7" y="8" width="16" height="11" rx="1.5"/><path d="M11 23h8M15 19v4"/>',
		'engine' => '<circle cx="15" cy="15" r="4.5"/><path d="M15 5v3.5M15 21.5V25M5 15h3.5M21.5 15H25M8 8l2.5 2.5M19.5 19.5L22 22M22 8l-2.5 2.5M10.5 19.5L8 22"/>',
		'aiml' => '<path d="M15 5l3 7 7 3-7 3-3 7-3-7-7-3 7-3z" fill="#fff"/>',
		'soar' => '<path d="M18 5l-10 12h7l-3 8 10-12h-7z" fill="#fff"/>',
		's3' => '<path d="M7 9a8 3 0 0 0 16 0a8 3 0 0 0-16 0L9 24a6 2.4 0 0 0 12 0L23 9"/>',
		'cluster' => '<rect x="7" y="7" width="16" height="6" rx="1.5"/><rect x="7" y="17" width="16" height="6" rx="1.5"/>',
		'proxy' => '<circle cx="15" cy="15" r="3"/><path d="M15 5v7M15 18v7M5 15h7M18 15h7"/>',
		'server' => '<rect x="8" y="6" width="14" height="18" rx="1.5"/><path d="M11 10h8M11 14h8M11 18h3"/>',
		'client' => '<circle cx="15" cy="11" r="4"/><path d="M7 24a8 7 0 0 1 16 0"/>',
		'firewall' => '<path d="M6 8h18v14H6zM6 13h18M6 18h18M12 8v5M18 13v5M12 18v4"/>',
		'lb' => '<circle cx="8" cy="15" r="2.5"/><circle cx="22" cy="8" r="2.5"/><circle cx="22" cy="22" r="2.5"/><path d="M10.5 15h4l5-7M14.5 15l5 7"/>',
		'kafka' => '<circle cx="15" cy="8" r="2.5"/><circle cx="15" cy="22" r="2.5"/><circle cx="22" cy="15" r="2.5"/><circle cx="15" cy="15" r="2"/><path d="M15 10.5v2.5M15 17v2.5M17 15h2.5"/>',
		'ulm' => '<rect x="7" y="5" width="12" height="16" rx="1.5"/><path d="M10 9h6M10 13h6M10 17h2"/><circle cx="20" cy="20" r="3.2"/><path d="M22.3 22.3l2.7 2.7"/>',
		'rack' => '<rect x="7" y="6" width="16" height="5" rx="1"/><rect x="7" y="12.5" width="16" height="5" rx="1"/><rect x="7" y="19" width="16" height="5" rx="1"/><path d="M10 8.5h1M10 15h1M10 21.5h1"/>',
		'cloud' => '<path d="M10 21a4 4 0 0 1-.6-7.95A6 6 0 0 1 20.9 11.6 4.6 4.6 0 0 1 20.5 21z"/>',
		'generic' => '<rect x="8" y="8" width="14" height="14" rx="2"/>'
	];

	public static function svg(string $icon, string $color): string {
		$fill = self::COLORS[$color] ?? self::COLORS['neutral'];
		$glyph = self::GLYPHS[$icon] ?? self::GLYPHS['generic'];

		return '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30">'
			.'<rect width="30" height="30" rx="7" fill="'.$fill.'"/>'
			.'<g fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$glyph.'</g></svg>';
	}

	/** draw.io style value for an image: data:image/svg+xml,<base64> (no ";base64", which would end the style). */
	public static function styleImage(string $icon, string $color): string {
		return 'data:image/svg+xml,'.base64_encode(self::svg($icon, $color));
	}

	/** Glyph bodies by name, for drawing the same icons in the browser. */
	public static function glyphs(): array {
		return self::GLYPHS;
	}

	public static function names(): array {
		return array_keys(self::GLYPHS);
	}
}
