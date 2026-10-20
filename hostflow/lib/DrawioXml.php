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
		self::dedupe($doc);

		return $doc->saveXML($doc->documentElement);
	}

	/**
	 * The pages of a diagram as [page key => <root> element]. The key is the page's id, or its
	 * position when it has none.
	 */
	private static function pages(DOMDocument $doc): array {
		$out = [];
		foreach ((new DOMXPath($doc))->query('//mxGraphModel/root') as $i => $root) {
			$page = $root->parentNode->parentNode;
			$key = $page instanceof DOMElement && $page->nodeName === 'diagram' && $page->getAttribute('id') !== ''
				? 'id:'.$page->getAttribute('id') : '#'.$i;
			if (!isset($out[$key])) {
				$out[$key] = $root;
			}
		}

		return $out;
	}

	/**
	 * The cells of one page as [id => element], the way draw.io reads them: only the direct children
	 * of <root>. A later cell with an id already used on the page, or a cell without an id, is
	 * removed, so what is checked here is always what draw.io shows.
	 */
	private static function cells(DOMElement $root): array {
		$out = [];
		foreach (iterator_to_array($root->childNodes) as $n) {
			if (!$n instanceof DOMElement) {
				continue;
			}
			$id = $n->getAttribute('id');
			if ($id === '' || isset($out[$id])) {
				$root->removeChild($n);
				continue;
			}
			$out[$id] = $n;
		}

		return $out;
	}

	/** Remove duplicate and id-less cells on every page (see cells()). */
	private static function dedupe(DOMDocument $doc): void {
		foreach (self::pages($doc) as $root) {
			self::cells($root);
		}
	}

	/** The mxCell of a page cell (the cell itself, or the one inside an object wrapper). */
	private static function mxCell(DOMElement $n): ?DOMElement {
		if ($n->nodeName === 'mxCell') {
			return $n;
		}
		foreach ($n->childNodes as $c) {
			if ($c instanceof DOMElement && $c->nodeName === 'mxCell') {
				return $c;
			}
		}

		return null;
	}

	/**
	 * Everything that belongs to hosts a viewer may not read, per page: the hidden host shapes
	 * (wrapped objects or plain cells carrying zbx_hostid), every cell nested inside them at any
	 * depth, every line that starts or ends at any of those, and labels on those lines, repeated
	 * until nothing more is added. Removes duplicate cells first (see cells()).
	 * Returns [page key => [cell id => element]].
	 */
	private static function hiddenSet(DOMDocument $doc, array $visible): array {
		$visible = array_flip(array_map('strval', $visible));
		$out = [];
		foreach (self::pages($doc) as $key => $root) {
			$cells = self::cells($root);
			$info = [];
			$hidden = [];
			foreach ($cells as $id => $n) {
				$c = self::mxCell($n);
				$info[$id] = $c === null ? ['', false, '', ''] : [$c->getAttribute('parent'), $c->getAttribute('edge') === '1',
					$c->getAttribute('source'), $c->getAttribute('target')];
				$hid = $n->getAttribute('zbx_hostid') !== '' ? $n->getAttribute('zbx_hostid') : ($c ? $c->getAttribute('zbx_hostid') : '');
				if ($hid !== '' && !isset($visible[$hid])) {
					$hidden[$id] = true;
				}
			}
			if (!$hidden) {
				continue;
			}
			do {
				$more = false;
				foreach ($info as $id => [$parent, $edge, $src, $dst]) {
					if (!isset($hidden[$id]) && (isset($hidden[$parent]) || ($edge && (isset($hidden[$src]) || isset($hidden[$dst]))))) {
						$hidden[$id] = true;
						$more = true;
					}
				}
			} while ($more);
			foreach ($hidden as $id => $_) {
				$out[$key][(string) $id] = $cells[$id];
			}
		}

		return $out;
	}

	/**
	 * For people who may not see some linked hosts in Zabbix: everything belonging to those hosts
	 * (see hiddenSet) keeps only its id, host id and tier, and shows "no access" (lines: no label),
	 * so a diagram never reveals hosts beyond the viewer's permissions.
	 * @param string[] $visible host ids the viewer may read
	 */
	public static function redactHosts(string $xml, array $visible): string {
		$doc = self::load($xml);
		$set = self::hiddenSet($doc, $visible);
		if (!$set) {
			return $doc->saveXML($doc->documentElement);
		}
		$keep = ['id' => true, 'zbx_hostid' => true, 'zbx_kind' => true, 'zbx_component' => true];
		foreach (array_merge(...array_values(array_map('array_values', $set))) as $el) {
			$cell = $el->nodeName === 'mxCell' ? $el : $el->getElementsByTagName('mxCell')->item(0);
			$isEdge = $cell instanceof DOMElement && $cell->getAttribute('edge') === '1';
			if ($el->nodeName !== 'mxCell') {
				foreach (iterator_to_array($el->attributes) as $a) {
					if (!isset($keep[$a->nodeName])) {
						$el->removeAttribute($a->nodeName);
					}
				}
				$el->setAttribute('label', $isEdge || $el->getAttribute('zbx_hostid') === '' ? '' : 'no access');
			}
			foreach ($el->nodeName === 'mxCell' ? [$el] : iterator_to_array($el->getElementsByTagName('mxCell')) as $c) {
				$c->removeAttribute('tooltip');
				$c->removeAttribute('link');
				if ($c->hasAttribute('value')) {
					$c->setAttribute('value', $c->getAttribute('zbx_hostid') !== '' && !$isEdge ? 'no access' : '');
				}
			}
		}

		return $doc->saveXML($doc->documentElement);
	}

	/**
	 * Saving a diagram whose hidden hosts were redacted for the person saving it: put everything that
	 * belongs to those hosts (see hiddenSet) back exactly as stored, also when it was deleted, so a
	 * save by someone who sees fewer hosts never changes or loses it.
	 * @param string[] $visible host ids the person saving may read
	 */
	public static function restoreHidden(string $new, string $old, array $visible): string {
		$od = self::load($old);
		$set = self::hiddenSet($od, $visible);
		$nd = self::load($new);
		self::dedupe($nd);
		$pages = self::pages($nd);
		$file = $nd->documentElement;
		foreach ($set as $key => $hidden) {
			$oldRoot = self::pages($od)[$key];
			if (!isset($pages[$key])) {
				// A page with hosts this person cannot see was deleted: put the whole page back.
				$page = $oldRoot->parentNode->parentNode;
				if ($file->nodeName === 'mxfile' && $page instanceof DOMElement && $page->nodeName === 'diagram') {
					$file->appendChild($nd->importNode($page, true));
				}
				continue;
			}
			$root = $pages[$key];
			$cells = self::cells($root);
			foreach ($hidden as $id => $was) {
				$copy = $nd->importNode($was, true);
				if (isset($cells[$id])) {
					$root->replaceChild($copy, $cells[$id]);
				}
				else {
					// Deleted by someone who could not see it: put it back if its container still exists.
					$c = self::mxCell($was);
					$parent = $c ? $c->getAttribute('parent') : '';
					if ($parent === '' || isset($cells[$parent]) || isset($hidden[$parent])) {
						$root->appendChild($copy);
					}
				}
				$cells[$id] = $copy;
			}
		}

		return $nd->saveXML($nd->documentElement);
	}

	/**
	 * Remove host links (zbx_hostid) to hosts outside $allowed, on wrappers and plain cells, so
	 * someone cannot link a host they may not read by editing the XML.
	 * @param string[] $allowed
	 */
	public static function dropHostLinks(string $xml, array $allowed): string {
		$doc = self::load($xml);
		$allowed = array_flip(array_map('strval', $allowed));
		$changed = false;
		foreach ((new DOMXPath($doc))->query('//*[@zbx_hostid]') as $el) {
			/** @var DOMElement $el */
			if (!isset($allowed[$el->getAttribute('zbx_hostid')])) {
				$el->removeAttribute('zbx_hostid');
				$changed = true;
			}
		}

		return $changed ? $doc->saveXML($doc->documentElement) : $xml;
	}

	/** Host ids linked anywhere in the diagram (on <object>/<UserObject> wrappers or plain cells). */
	public static function linkedHostIds(string $xml): array {
		$ids = [];
		foreach ((new DOMXPath(self::load($xml)))->query('//*[@zbx_hostid]') as $el) {
			$v = $el->getAttribute('zbx_hostid');
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
