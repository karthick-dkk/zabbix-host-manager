<?php declare(strict_types = 0);
/**
 * Minimal page for embedding a live diagram (dashboard widget, kiosk): no Zabbix menu,
 * same-origin framing only, current user's theme.
 *
 * @var CView $this
 * @var array $data
 */

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

$theme = getUserTheme(CWebUser::$data);
echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
	.'<title>'.htmlspecialchars($data['page']['title'] ?? 'Host Flow').'</title>'
	.'<link rel="stylesheet" href="assets/styles/'.htmlspecialchars($theme).'.css">'
	.'<style>body{margin:0;padding:8px;background:transparent}.hf-embed .header-title{display:none}</style>'
	.'</head><body class="hf-embed">'
	.$data['main_block']
	.'</body></html>';
