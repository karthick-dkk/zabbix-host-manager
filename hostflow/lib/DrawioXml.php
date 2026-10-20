<?php declare(strict_types = 0);

namespace Modules\HostFlow\Lib;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;

/**
 * Checks and cleans diagram XML before it is stored, and reads the Zabbix links out of it.
 *
 * Accepted: an <mxfile> (one or more <diagram> pages) or a bare <mxGraphModel>.
 * Compressed pages (deflate + base64, draw.io's older default) are expanded.
 * Rejected: DTDs and entities, anything over the size limit, any other root element.
 * Stripped: event-handler attributes, links whose scheme is not http(s), mailto or draw.io page links,
 * and, in HTML labels, every tag and attribute outside an allowlist (DOM-based, see cleanHtml).
 */
class DrawioXml {

	public const MAX_BYTES = 5242880;

	private const LINK_ATTRS = ['link', 'href', 'zbx_runbook'];
	/** Link schemes allowed on shapes: web, mail and draw.io's own page links. Anything else with a scheme is dropped. */
	private const LINK_OK = '/^(https?:|mailto:|data:page\/id,)/i';

	/** Validate and return the cleaned XML as an uncompressed <mxfile>. */
	public static function sanitize(string $xml): string {
		if (strlen($xml) > self::MAX_BYTES) {
			throw new InvalidArgumentException('The diagram is larger than 5 MB.');
		}
		if (trim($xml) === '') {
			throw new InvalidArgumentException('The diagram is empty.');
		}
		if (preg_match('/<!(DOCTYPE|ENTITY)/i', $xml)) {
			throw new InvalidArgumentException('Diagrams may not contain a DTD or entities.');
		}

		$doc = self::load($xml);
		$root = $doc->documentElement;

		if ($root->nodeName === 'mxGraphModel') {
			$file = new DOMDocument('1.0', 'UTF-8');
			$mx = $file->createElement('mxfile');
			$mx->setAttribute('host', 'hostflow');
			$page = $file->createElement('diagram');
			$page->setAttribute('id', 'page-1');
			$page->setAttribute('name', 'Page-1');
			$page->appendChild($file->importNode($root, true));
			$mx->appendChild($page);
			$file->appendChild($mx);
			$doc = $file;
			$root = $mx;
		}
		elseif ($root->nodeName !== 'mxfile') {
			throw new InvalidArgumentException('This is not a draw.io diagram.');
		}

		foreach (iterator_to_array($root->getElementsByTagName('diagram')) as $page) {
			self::expandPage($doc, $page);
		}

		foreach (iterator_to_array($root->getElementsByTagName('*')) as $el) {
			self::cleanElement($el);
		}

		return $doc->saveXML($doc->documentElement);
	}

	/**
	 * For people who may not see some linked hosts in Zabbix: drop those hosts' stored name, IP and
	 * label text from the XML they are sent, so a diagram never reveals hosts beyond their permissions.
	 * @param string[] $visible host ids the viewer may read
	 */
	public static function redactHosts(string $xml, array $visible): string {
		$doc = self::load($xml);
		$visible = array_flip(array_map('strval', $visible));
		// Only these survive on a hidden host's shape; everything else (names, IPs, notes, links,
		// runbooks, custom fields) is dropped, and the label shows "no access".
		$keep = ['id' => true, 'zbx_hostid' => true, 'zbx_kind' => true, 'zbx_component' => true];
		$changed = false;
		$hiddenIds = [];
		foreach ((new DOMXPath($doc))->query('//object[@zbx_hostid]|//UserObject[@zbx_hostid]') as $o) {
			/** @var DOMElement $o */
			if (isset($visible[$o->getAttribute('zbx_hostid')])) {
				continue;
			}
			foreach (iterator_to_array($o->attributes) as $a) {
				if (!isset($keep[$a->nodeName])) {
					$o->removeAttribute($a->nodeName);
				}
			}
			$o->setAttribute('label', 'no access');
			foreach ($o->getElementsByTagName('mxCell') as $cell) {
				$cell->removeAttribute('value');
				$cell->removeAttribute('tooltip');
			}
			$hiddenIds[$o->getAttribute('id')] = true;
			$changed = true;
		}
		// Lines to or from a hidden host lose their labels too (they may name it or its data).
		if (!empty($hiddenIds)) {
			foreach ((new DOMXPath($doc))->query('//mxCell[@edge="1"]') as $cell) {
				/** @var DOMElement $cell */
				if (!isset($hiddenIds[$cell->getAttribute('source')]) && !isset($hiddenIds[$cell->getAttribute('target')])) {
					continue;
				}
				$cell->removeAttribute('value');
				$wrap = $cell->parentNode;
				if ($wrap instanceof DOMElement && ($wrap->nodeName === 'object' || $wrap->nodeName === 'UserObject')) {
					foreach (iterator_to_array($wrap->attributes) as $a) {
						if (!in_array($a->nodeName, ['id', 'zbx_kind'], true)) {
							$wrap->removeAttribute($a->nodeName);
						}
					}
					$wrap->setAttribute('label', '');
				}
			}
		}

		return $changed ? $doc->saveXML($doc->documentElement) : $xml;
	}

	/**
	 * Saving a diagram whose hidden hosts were redacted for the person saving it: put those hosts'
	 * shapes, and the lines to and from them, back exactly as stored, so a save by someone who sees
	 * fewer hosts never changes or loses them.
	 * @param string[] $visible host ids the person saving may read
	 */
	public static function restoreHidden(string $new, string $old, array $visible): string {
		$visible = array_flip(array_map('strval', $visible));
		$nd = self::load($new);
		$od = self::load($old);
		$oxp = new DOMXPath($od);
		$nxp = new DOMXPath($nd);
		$byId = static function (DOMXPath $xp, string $id): ?DOMElement {
			$n = $xp->query('//*[@id="'.str_replace('"', '', $id).'"]')->item(0);
			return $n instanceof DOMElement ? $n : null;
		};
		$hidden = [];
		foreach ($oxp->query('//object[@zbx_hostid]|//UserObject[@zbx_hostid]') as $o) {
			if (!isset($visible[$o->getAttribute('zbx_hostid')])) {
				$hidden[] = $o->getAttribute('id');
			}
		}
		if (!$hidden) {
			return $new;
		}
		$ids = array_flip($hidden);
		// The lines that touch them.
		foreach ($oxp->query('//mxCell[@edge="1"]') as $cell) {
			if (isset($ids[$cell->getAttribute('source')]) || isset($ids[$cell->getAttribute('target')])) {
				$wrap = $cell->parentNode;
				$hidden[] = $wrap instanceof DOMElement && ($wrap->nodeName === 'object' || $wrap->nodeName === 'UserObject')
					? $wrap->getAttribute('id') : $cell->getAttribute('id');
			}
		}
		$root = $nxp->query('//mxGraphModel/root')->item(0);
		foreach (array_unique($hidden) as $id) {
			$was = $byId($oxp, $id);
			if ($was === null) {
				continue;
			}
			$now = $byId($nxp, $id);
			if ($now !== null) {
				$now->parentNode->replaceChild($nd->importNode($was, true), $now);
				continue;
			}
			// Deleted by someone who could not see it: put it back if its container still exists.
			$cell = $was->nodeName === 'mxCell' ? $was : $was->getElementsByTagName('mxCell')->item(0);
			$parent = $cell instanceof DOMElement ? $cell->getAttribute('parent') : '';
			if ($root instanceof DOMElement && ($parent === '' || $byId($nxp, $parent) !== null)) {
				$root->appendChild($nd->importNode($was, true));
			}
		}

		return $nd->saveXML($nd->documentElement);
	}

	/** Host ids linked anywhere in the diagram (on <object>/<UserObject> wrappers). */
	public static function linkedHostIds(string $xml): array {
		$ids = [];
		foreach (self::objects($xml) as $o) {
			$v = $o['attrs']['zbx_hostid'] ?? '';
			if (ctype_digit($v)) {
				$ids[$v] = true;
			}
		}

		return array_map('strval', array_keys($ids));
	}

	/**
	 * Every <object>/<UserObject> with its attributes, cell id and (for edges) source/target.
	 * Used by the generator's "Update from Zabbix" and by the live endpoint.
	 */
	public static function objects(string $xml): array {
		$doc = self::load($xml);
		$out = [];
		$xp = new DOMXPath($doc);
		foreach ($xp->query('//object|//UserObject') as $o) {
			/** @var DOMElement $o */
			$attrs = [];
			foreach ($o->attributes as $a) {
				$attrs[$a->nodeName] = $a->nodeValue;
			}
			$cell = null;
			foreach ($o->childNodes as $c) {
				if ($c instanceof DOMElement && $c->nodeName === 'mxCell') {
					$cell = $c;
					break;
				}
			}
			$out[] = [
				'id' => $attrs['id'] ?? '',
				'attrs' => $attrs,
				'edge' => $cell !== null && $cell->getAttribute('edge') === '1',
				'source' => $cell !== null ? $cell->getAttribute('source') : '',
				'target' => $cell !== null ? $cell->getAttribute('target') : ''
			];
		}

		return $out;
	}

	public static function load(string $xml): DOMDocument {
		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		// LIBXML_NONET: no network; no LIBXML_NOENT: entities are never substituted.
		$ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		if (!$ok || $doc->documentElement === null) {
			throw new InvalidArgumentException('The diagram is not valid XML.');
		}
		if ($doc->doctype !== null) {
			throw new InvalidArgumentException('Diagrams may not contain a DTD or entities.');
		}

		return $doc;
	}

	/** draw.io pages may hold their model as compressed text; turn it back into elements. */
	private static function expandPage(DOMDocument $doc, DOMElement $page): void {
		foreach ($page->childNodes as $c) {
			if ($c instanceof DOMElement) {
				return;
			}
		}
		$text = trim($page->textContent);
		if ($text === '') {
			return;
		}
		$bin = base64_decode($text, true);
		$inflated = $bin !== false ? @gzinflate($bin) : false;
		if ($inflated === false) {
			throw new InvalidArgumentException('A diagram page could not be read.');
		}
		$model = self::load(rawurldecode($inflated))->documentElement;
		if ($model->nodeName !== 'mxGraphModel') {
			throw new InvalidArgumentException('A diagram page could not be read.');
		}
		while ($page->firstChild) {
			$page->removeChild($page->firstChild);
		}
		$page->appendChild($doc->importNode($model, true));
	}

	private static function cleanElement(DOMElement $el): void {
		foreach (iterator_to_array($el->attributes) as $a) {
			$name = strtolower($a->nodeName);
			if (strncmp($name, 'on', 2) === 0) {
				$el->removeAttribute($a->nodeName);
				continue;
			}
			$value = $a->nodeValue;
			if (in_array($name, self::LINK_ATTRS, true) && !self::safeLink($value)) {
				$el->removeAttribute($a->nodeName);
				continue;
			}
			// Labels may be HTML, and with placeholders="1" any other attribute of an object is put
			// into the label as %name%: clean every attribute that can carry text.
			$isObject = $el->nodeName === 'object' || $el->nodeName === 'UserObject';
			if ($name === 'label' || $name === 'value' || $name === 'tooltip' || $name === 'zbx_note'
					|| ($isObject && !in_array($name, ['id', 'style', 'placeholders'], true))) {
				$clean = self::cleanHtml($value);
				if ($clean !== $value) {
					$el->setAttribute($a->nodeName, $clean);
				}
			}
		}
	}

	/** Browsers ignore control characters and spaces inside a URL scheme, so check the URL without them. */
	private static function safeLink(string $url): bool {
		$norm = preg_replace('/[\x00-\x20\x7f]+/', '', $url);

		return !preg_match('/^[a-z][a-z0-9+.\-]*:/i', $norm) || preg_match(self::LINK_OK, $norm) === 1;
	}

	/** Formatting tags draw.io writes into HTML labels; anything else is unwrapped or dropped. */
	private const HTML_TAGS = ['a', 'b', 'blockquote', 'br', 'code', 'div', 'em', 'font', 'h1', 'h2', 'h3', 'h4',
		'h5', 'h6', 'hr', 'i', 'img', 'li', 'ol', 'p', 'pre', 's', 'span', 'strike', 'strong', 'sub', 'sup', 'table',
		'tbody', 'td', 'th', 'thead', 'tr', 'u', 'ul'];
	/** Dropped together with their content. */
	private const HTML_DROP = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form',
		'input', 'button', 'select', 'textarea', 'link', 'meta', 'base', 'svg', 'math', 'template', 'noscript', 'title'];
	private const HTML_ATTRS = ['align', 'alt', 'color', 'colspan', 'face', 'height', 'href', 'rowspan', 'size',
		'src', 'style', 'target', 'title', 'width'];
	private const SAFE_URL = '/^\s*(https?:|mailto:|#|\/|\.{0,2}\/|[^:\/?#]*([\/?#]|$)|data:image\/(png|gif|jpe?g|webp);)/i';

	/**
	 * Labels may be HTML (html=1). Keep formatting, drop anything active.
	 * Parsed with DOMDocument and rebuilt from an allowlist of tags and attributes; labels that
	 * need no change are returned untouched so plain-text labels keep their exact characters.
	 */
	public static function cleanHtml(string $html): string {
		if (strpbrk($html, '<&') === false) {
			return $html;
		}

		$doc = new DOMDocument();
		$prev = libxml_use_internal_errors(true);
		$ok = $doc->loadHTML('<html><head><meta charset="utf-8"></head><body><div>'.$html.'</div></body></html>',
			LIBXML_NONET | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);
		$body = $ok ? $doc->getElementsByTagName('body')->item(0) : null;
		$wrap = $body !== null ? $body->firstChild : null;
		if (!$wrap instanceof DOMElement) {
			return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		}

		$changed = $wrap->nextSibling !== null;
		self::cleanNode($wrap, $changed);
		if (!$changed) {
			return $html;
		}

		$out = '';
		foreach ($wrap->childNodes as $c) {
			$out .= $doc->saveHTML($c);
		}

		return $out;
	}

	private static function cleanNode(DOMElement $parent, bool &$changed): void {
		foreach (iterator_to_array($parent->childNodes) as $node) {
			if ($node instanceof DOMElement) {
				$tag = strtolower($node->nodeName);
				if (!in_array($tag, self::HTML_TAGS, true)) {
					$changed = true;
					if (!in_array($tag, self::HTML_DROP, true)) {
						self::cleanNode($node, $changed);
						while ($node->firstChild) {
							$parent->insertBefore($node->firstChild, $node);
						}
					}
					$parent->removeChild($node);
					continue;
				}
				foreach (iterator_to_array($node->attributes) as $a) {
					$name = strtolower($a->nodeName);
					$value = $a->nodeValue;
					$bad = !in_array($name, self::HTML_ATTRS, true)
						|| (($name === 'href' || $name === 'src') && !preg_match(self::SAFE_URL, $value))
						|| ($name === 'style' && preg_match('/expression\s*\(|url\s*\(|javascript:|behavior\s*:|@import/i',
							$value
						));
					if ($bad) {
						$node->removeAttribute($a->nodeName);
						$changed = true;
					}
				}
				self::cleanNode($node, $changed);
			}
			elseif ($node->nodeType === XML_COMMENT_NODE || $node->nodeType === XML_PI_NODE
					|| $node->nodeType === XML_CDATA_SECTION_NODE) {
				$parent->removeChild($node);
				$changed = true;
			}
		}
	}
}
