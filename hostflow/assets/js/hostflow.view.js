/* Live view (also used by the NOC wall): draw.io static viewer + live Zabbix overlay. */
(function () {
	'use strict';
	const HF = window.HF, esc = HF.esc;
	const $ = (s) => document.querySelector(s);
	const OK = '#3aa76d', DOWN = '#e45959', NOACCESS = '#b9c4cf';
	const HIGH = 4;
	const APP = !!document.getElementById('hf-app');   // main page: flow list on the left

	let viewer = null, graph = null, data = null, diagramId = HF.meta ? HF.meta.id : null;
	let flows = [], current = HF.meta || null, opening = 0;
	let pairs = {};   // diagram id → [{id, kind}]: a client's automated Pipeline and Client hierarchy flows
	let showCost = false, atMinutes = 0, timer = null, menu = null;
	let capMode = false;          // Capacity tab is open instead of the diagram
	let exportMode = null;        // 'ip' or 'plain' while a download is being drawn
	let hidden = {t: [], h: []};  // tiers and hosts hidden on this client's flows (per browser)
	const orig = new Map();   // cell id → {style, label}

	// ------------------------------------------------------------------ render

	async function open(id) {
		diagramId = id;
		const d = await HF.get('hostflow.load', {id});
		const box = $('#hf-viewer');
		box.innerHTML = '';
		orig.clear();
		const el = document.createElement('div');
		el.className = 'mxgraph';
		el.setAttribute('data-mxgraph', JSON.stringify({xml: d.xml, highlight: '#1f65f4', nav: true, resize: !APP, toolbar: 'zoom layers',
			'toolbar-position': 'top', lightbox: false, center: true, 'auto-fit': true, tooltips: true}));
		box.appendChild(el);
		return new Promise((resolve) => {
			window.GraphViewer.createViewerForElement(el, (v) => {
				viewer = v;
				graph = v.graph;
				graph.setTooltips(true);
				if (APP) fitView();
				graph.getTooltipForCell = tooltip;
				graph.addListener(window.mxEvent.CLICK, (sender, evt) => {
					const cell = evt.getProperty('cell');
					const me = evt.getProperty('event');
					if (cell && attr(cell, 'zbx_hostid')) { showMenu(cell, me); evt.consume(); }
					else if (cell && attr(cell, 'zbx_note')) { showMenu(cell, me); evt.consume(); }
					else hideMenu();
				});
				resolve(d.meta);
			});
		});
	}

	// Zoom so the whole flow fits the space, but never above 100 %.
	function fitView() {
		if (!graph || !graph.container) return;
		const c = $('#hf-viewer') || graph.container, b = graph.getGraphBounds(), s = graph.view.scale;
		if (!b.width || !b.height || !c.clientWidth) return;
		const w = b.width / s, h = b.height / s;
		// The whole flow on one screen, no scrolling (never above 100 %).
		const scale = Math.max(0.15, Math.min(1, (c.clientWidth - 32) / w, (c.clientHeight - 32) / h));
		graph.view.scaleAndTranslate(scale, (c.clientWidth / scale - w) / 2 - b.x / s, (c.clientHeight / scale - h) / 2 - b.y / s);
	}

	const isNode = (v) => v != null && typeof v === 'object' && v.nodeType === 1;
	const attr = (cell, k) => (cell && isNode(cell.value) ? cell.value.getAttribute(k) : null);
	const cells = () => graph.getModel().getDescendants(graph.getModel().getRoot()).filter((c) => c.vertex || c.edge);

	// Values from Zabbix (host names, IPs) go into HTML labels through placeholders, so they are escaped.
	const RAW = new Set(['label']);
	function setAttrs(cell, attrs) {
		const v = cell.value.cloneNode(true);
		const html = /(^|;)html=1(;|$)/.test(cell.style || '');
		Object.entries(attrs).forEach(([k, val]) => {
			const s = val == null ? '' : String(val);
			v.setAttribute(k, html && !RAW.has(k) ? esc(s) : s);
		});
		graph.getModel().setValue(cell, v);
	}

	function remember(cell) {
		if (!orig.has(cell.id)) orig.set(cell.id, {style: cell.style, label: attr(cell, 'label')});
		return orig.get(cell.id);
	}

	function styled(base, set) {
		const parts = {};
		(base || '').split(';').filter(Boolean).forEach((p) => { const i = p.indexOf('='); if (i < 0) parts[p] = null; else parts[p.slice(0, i)] = p.slice(i + 1); });
		Object.entries(set).forEach(([k, v]) => { if (v === null) delete parts[k]; else parts[k] = v; });
		return Object.entries(parts).map(([k, v]) => v === null ? k : k + '=' + v).join(';') + ';';
	}

	// ------------------------------------------------------------------ data

	async function refresh() {
		if (!diagramId || !graph) return;
		try {
			const args = {id: diagramId};
			if (atMinutes > 0) args.at = Math.round(Date.now() / 1000 - atMinutes * 60);
			const id = diagramId;
			const d = await HF.get('hostflow.data', args);
			if (id !== diagramId) return;
			data = d;
			apply();
			details();
			const r = $('#hf-refreshed');
			if (r) r.textContent = data.at ? 'State at ' + new Date(data.at * 1000).toLocaleString() : '';
			if (capMode) loadCapacity();
			changes();
		}
		catch (e) {
			const r = $('#hf-refreshed');
			if (r) r.textContent = e.message;
		}
	}

	function schedule() {
		clearInterval(timer);
		if (atMinutes === 0) timer = setInterval(() => { if (!document.hidden) refresh(); }, Math.max(10, HF.refresh || 30) * 1000);
	}

	function hostStyle(h) {
		if (!h) return {strokeColor: NOACCESS, strokeWidth: '1', dashed: '1', opacity: '60', fillColor: '#f4f6f8'};
		if (h.disabled) return {strokeColor: NOACCESS, strokeWidth: '1', dashed: '1', opacity: '55'};
		const s = {strokeColor: h.worst >= 0 ? HF.sevColor(h.worst) : OK, strokeWidth: h.worst >= HIGH ? '3' : '2', dashed: null, opacity: null};
		if (h.available === 2) { s.strokeColor = h.worst >= 0 ? s.strokeColor : DOWN; s.dashed = '1'; }
		return s;
	}

	function apply() {
		const model = graph.getModel();
		const hosts = data.hosts || {};
		const comps = {};
		const counted = new Set();   // a host can be drawn twice (pipeline + hierarchy); count it once
		const flow = data.flow || {components: [], edges: []};

		model.beginUpdate();
		try {
			cells().forEach((cell) => {
				graph.removeCellOverlays(cell);
				const kind = attr(cell, 'zbx_kind');
				const hid = attr(cell, 'zbx_hostid');
				if (hid) {
					const o = remember(cell);
					const h = hosts[hid];
					const costOn = exportMode === 'ip' ? !!data.cost : exportMode === 'plain' ? false : showCost;
					setAttrs(cell, {name: h ? h.name : 'no access', ip: h && exportMode !== 'plain' ? h.ip : '',
						cost: data.cost && data.cost.hosts[hid] ? (data.cost.hosts[hid].excluded ? 'excluded' : HF.money(data.cost.hosts[hid].monthly) + '/mo') : '',
						label: costOn && data.cost && o.label ? o.label + '<br><span style="font-family:monospace;color:#1f5fa8">%cost%</span>' : o.label});
					model.setStyle(cell, styled(o.style, hostStyle(h)));
					if (h) {
						const comp = attr(cell, 'zbx_component') || h.component || '';
						comps[comp] = comps[comp] || {worst: -1, up: 0, total: 0, unacked: false};
						comps[comp].worst = Math.max(comps[comp].worst, h.worst);
						if (!counted.has(comp + '|' + hid)) {
							counted.add(comp + '|' + hid);
							comps[comp].total++;
							if (!h.disabled && h.available !== 2) comps[comp].up++;
						}
						if (h.unacked && h.worst >= HIGH) comps[comp].unacked = true;
						if (h.count) badge(cell, h.count, HF.sevColor(h.worst));
						if (h.maintenance) badge(cell, '🔧', '#ffffff', 'left');
					}
				}
				else if (kind === 'cost' && data.cost) {
					const c = data.cost.cost_only[attr(cell, 'zbx_cost_id')];
					setAttrs(cell, {cost: c ? HF.money(c.monthly) + '/mo' : ''});
				}
			});

			// tiers, client, arrows
			const impacted = new Set();
			Object.entries(comps).forEach(([id, c]) => { if (c.worst >= HIGH) downstream(id, flow.edges).forEach((x) => impacted.add(x)); });
			let allWorst = -1, allHosts = 0;
			Object.values(comps).forEach((c) => { allWorst = Math.max(allWorst, c.worst); allHosts += c.total; });

			cells().forEach((cell) => {
				const kind = attr(cell, 'zbx_kind');
				const comp = attr(cell, 'zbx_component');
				if (kind === 'component' && comp) {
					const o = remember(cell);
					const c = comps[comp];
					const s = {strokeColor: c && c.worst >= 0 ? HF.sevColor(c.worst) : (c ? OK : '#b9c4cf'), strokeWidth: c && c.worst >= HIGH ? '3' : '2', dashed: null};
					if (impacted.has(comp)) { s.dashed = '1'; s.strokeColor = DOWN; }
					model.setStyle(cell, styled(o.style, s));
					if (c) textBadge(cell, c.up + '/' + c.total + ' up' + (impacted.has(comp) ? ' · impacted' : '')
						+ (showCost && data.cost && data.cost.components[comp] != null ? ' · ' + HF.money(data.cost.components[comp]) : ''));
					else if (impacted.has(comp)) textBadge(cell, 'impacted');
				}
				else if (kind === 'client') {
					const o = remember(cell);
					setAttrs(cell, {hostcount: allHosts});
					model.setStyle(cell, styled(o.style, {strokeColor: allWorst >= 0 ? HF.sevColor(allWorst) : OK, strokeWidth: '2.5'}));
					if (showCost && data.cost) textBadge(cell, HF.money(data.cost.total) + '/mo total');
				}
				else if (cell.edge && attr(cell, 'zbx_itemid')) {
					const o = remember(cell);
					const a = data.items[attr(cell, 'zbx_itemid')];
					const b = data.items[attr(cell, 'zbx_compare_itemid') || ''];
					let label = o.label || '';
					let s = {strokeColor: null, strokeWidth: null};
					// Item values come from monitored hosts: escape them when the label is HTML.
					const safe = /(^|;)html=1(;|$)/.test(o.style || '') ? esc : String;
					if (a) {
						label = (label ? label + ': ' : '') + safe(fmtValue(a));
						if (b && a.numeric && b.numeric && +a.value > 0) {
							const drop = (1 - (+b.value) / (+a.value)) * 100;
							const limit = parseFloat(attr(cell, 'zbx_drop_pct') || '20');
							if (drop > limit) { s = {strokeColor: DOWN, strokeWidth: '3'}; label += safe(' · ↓ ' + drop.toFixed(0) + '%'); }
						}
					}
					setAttrs(cell, {label});
					model.setStyle(cell, styled(o.style, s));
				}
			});
		}
		finally {
			model.endUpdate();
		}
		applyHidden();
		pulse();
		legend();
		if (HF.focus) { focus(HF.focus); HF.focus = null; }
	}

	function downstream(from, edges) {
		const seen = new Set(), stack = [from];
		while (stack.length) {
			const id = stack.pop();
			(edges || []).forEach((e) => { if (e.from === id && e.to !== from && !seen.has(e.to)) { seen.add(e.to); stack.push(e.to); } });
		}
		return Array.from(seen);
	}

	function fmtValue(i) {
		const v = parseFloat(i.value);
		if (!i.numeric || isNaN(v)) return i.value + (i.units ? ' ' + i.units : '');
		const abs = Math.abs(v);
		const [d, sfx] = abs >= 1e9 ? [1e9, 'G'] : abs >= 1e6 ? [1e6, 'M'] : abs >= 1e3 ? [1e3, 'K'] : [1, ''];
		return (v / d).toFixed(d === 1 ? (abs < 10 ? 2 : 0) : 1) + ' ' + sfx + (i.units || '');
	}

	function svgData(svg) { return 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svg))); }

	function badge(cell, text, color, side) {
		const t = esc(String(text));
		const w = Math.max(20, 8 + t.length * 7);
		const img = new window.mxImage(svgData('<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="20"><rect x="1" y="1" width="' + (w - 2) + '" height="18" rx="9" fill="' + color
			+ '" stroke="#fff" stroke-width="2"/><text x="' + (w / 2) + '" y="14" font-family="Arial" font-size="11" font-weight="bold" text-anchor="middle" fill="#1b2230">' + t + '</text></svg>'), w, 20);
		const o = new window.mxCellOverlay(img, null, side === 'left' ? window.mxConstants.ALIGN_LEFT : window.mxConstants.ALIGN_RIGHT, window.mxConstants.ALIGN_TOP,
			new window.mxPoint(side === 'left' ? 0 : -2, 2));
		graph.addCellOverlay(cell, o);
	}

	function textBadge(cell, text) {
		const t = esc(text);
		const w = 12 + t.length * 6.2;
		const img = new window.mxImage(svgData('<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="18"><rect width="' + w + '" height="18" rx="4" fill="#ffffff" stroke="#b9c4cf"/>'
			+ '<text x="6" y="13" font-family="Arial" font-size="11" fill="#4f5d6b">' + t + '</text></svg>'), w, 18);
		graph.addCellOverlay(cell, new window.mxCellOverlay(img, null, window.mxConstants.ALIGN_RIGHT, window.mxConstants.ALIGN_BOTTOM, new window.mxPoint(-w / 2 - 4, 4)));
	}

	function pulse() {
		cells().forEach((cell) => {
			const hid = attr(cell, 'zbx_hostid');
			const h = hid && data.hosts ? data.hosts[hid] : null;
			const st = graph.view.getState(cell);
			if (!st || !st.shape || !st.shape.node) return;
			st.shape.node.classList.toggle('hf-pulse', !!(h && h.unacked && h.worst >= HIGH && !data.at));
		});
	}

	// ------------------------------------------------------------------ tooltip + menu

	function hostInfo(cell) {
		const hid = attr(cell, 'zbx_hostid');
		return hid ? (data && data.hosts[hid]) || null : null;
	}

	function tooltip(cell) {
		if (!data) return '';
		const h = hostInfo(cell);
		const note = attr(cell, 'zbx_note');
		if (!h && !note) return '';
		let s = '<div class="hf-tip">';
		if (h) {
			s += '<b>' + esc(h.name) + '</b>' + esc(h.ip) + (h.port ? ':' + esc(h.port) : '')
				+ (h.proxyid && data.proxies[h.proxyid] ? '<br>via proxy ' + esc(data.proxies[h.proxyid]) : '')
				+ '<br>Agent: ' + (['unknown', 'available', 'unavailable'][h.available] || 'unknown') + (h.maintenance ? ' · in maintenance' : '')
				+ (h.disabled ? ' · disabled' : '');
			if (data.cost && data.cost.hosts[h.hostid]) s += '<br>Cost: ' + esc(HF.money(data.cost.hosts[h.hostid].monthly)) + '/mo (' + esc(data.cost.hosts[h.hostid].source) + ')';
			s += h.problems.length ? '<br><br>' + h.problems.map((p) => '● ' + esc(HF.sevName(p.severity)) + ': ' + esc(p.name)).join('<br>') : '<br>No problems';
		}
		if (note) s += '<br><br>' + esc(note);
		return s + '</div>';
	}

	function showMenu(cell, evt) {
		hideMenu();
		const h = hostInfo(cell);
		const hid = attr(cell, 'zbx_hostid');
		const note = attr(cell, 'zbx_note');
		const rb = attr(cell, 'zbx_runbook');
		const z = (action, args) => HF.url(action, args);
		let html = '<h5>' + esc(h ? h.name : (attr(cell, 'name') || 'Shape')) + '</h5>';
		if (h) {
			html += '<div class="meta hf-mono">' + esc(h.ip) + (h.port ? ':' + esc(h.port) : '') + '</div>';
			html += h.problems.map((p) => '<div class="p">' + HF.sevBadge(p.severity, HF.sevName(p.severity)) + ' <span>' + esc(p.name)
				+ (p.acknowledged ? ' <span class="hf-muted">(ack)</span>' : '') + '</span></div>').join('');
			if (h.count > h.problems.length) html += '<div class="p hf-muted">+' + (h.count - h.problems.length) + ' more</div>';
		}
		else if (hid) html += '<div class="meta">You have no access to this host.</div>';
		if (note) html += '<div class="note">' + esc(note) + '</div>';
		if (h) {
			const q = {filter_set: 1};
			html += '<a href="' + esc(z('problem.view', Object.assign({'hostids[]': hid}, q))) + '">Problems</a>'
				+ '<a href="' + esc(z('latest.view', Object.assign({'hostids[]': hid}, q))) + '">Latest data</a>'
				+ '<a href="' + esc(z('charts.view', Object.assign({'filter_hostids[]': hid}, q))) + '">Graphs</a>'
				+ '<a href="' + esc(z('host.dashboard.view', {hostid: hid})) + '">Host dashboards</a>';
		}
		if (rb && /^https?:\/\//i.test(rb)) html += '<a href="' + esc(rb) + '" target="_blank" rel="noopener noreferrer">Runbook ↗</a>';
		menu = document.createElement('div');
		menu.className = 'hf-menu';
		menu.innerHTML = html;
		document.body.appendChild(menu);
		const x = evt && evt.clientX != null ? evt.clientX : 100, y = evt && evt.clientY != null ? evt.clientY : 100;
		const r = menu.getBoundingClientRect();
		menu.style.left = Math.min(window.innerWidth - r.width - 8, x + window.scrollX + 6) + 'px';
		menu.style.top = Math.min(window.scrollY + window.innerHeight - r.height - 8, y + window.scrollY + 6) + 'px';
	}

	function hideMenu() { if (menu) { menu.remove(); menu = null; } }
	document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideMenu(); });
	document.addEventListener('click', (e) => { if (menu && !menu.contains(e.target) && !e.target.closest('.hf-viewer')) hideMenu(); });

	// ------------------------------------------------------------------ changes since last visit

	function changes() {
		const box = $('#hf-changes');
		if (!box) return;
		const c = data.changes;
		if (!c || !c.changes || !c.changes.length) { box.innerHTML = ''; return; }
		const word = {added: 'added', removed: 'removed', readdressed: 'new IP', trouble: 'new problem'};
		box.innerHTML = '<div class="hf-changes"><b>' + c.changes.length + ' change(s) since your last visit</b> <span class="hf-muted">(' + esc(HF.ago(c.since)) + ')</span> '
			+ (APP ? '<button type="button" class="btn-link" id="hf-changes-toggle" aria-expanded="false">Show</button> · ' : '')
			+ '<button type="button" class="btn-link" id="hf-seen">Mark as seen</button><ul' + (APP ? ' hidden' : '') + '>'
			+ c.changes.slice(0, 20).map((x) => '<li><a href="#" data-host="' + esc(x.hostid) + '">' + esc(x.name) + '</a>: ' + esc(word[x.kind] || x.kind)
				+ (x.kind === 'readdressed' ? ' <span class="hf-mono">' + esc(x.from) + ' → ' + esc(x.to) + '</span>' : '')
				+ (x.kind === 'trouble' ? ' (' + esc(HF.sevName(x.severity)) + ')' : '') + '</li>').join('')
			+ (c.changes.length > 20 ? '<li class="hf-muted">and ' + (c.changes.length - 20) + ' more</li>' : '') + '</ul></div>';
		box.querySelectorAll('[data-host]').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); focus(a.dataset.host); }));
		bind('hf-changes-toggle', 'click', (e) => {
			const ul = box.querySelector('ul');
			ul.hidden = !ul.hidden;
			e.currentTarget.textContent = ul.hidden ? 'Show' : 'Hide';
			e.currentTarget.setAttribute('aria-expanded', String(!ul.hidden));
		});
		$('#hf-seen').addEventListener('click', async () => {
			try { await HF.post('hostflow.seen', {id: diagramId}); box.innerHTML = ''; }
			catch (e) { HF.say(e.message, false); }
		});
		c.changes.forEach((x) => flash(x.hostid));
	}

	function cellsFor(hostid) { return cells().filter((c) => attr(c, 'zbx_hostid') === String(hostid)); }

	function flash(hostid) {
		cellsFor(hostid).forEach((c) => {
			const st = graph.view.getState(c);
			if (st && st.shape && st.shape.node) { st.shape.node.classList.remove('hf-flash'); void st.shape.node.getBBox; st.shape.node.classList.add('hf-flash'); }
		});
	}

	function focus(hostid) {
		const cs = cellsFor(hostid);
		if (!cs.length) return false;
		graph.scrollCellToVisible(cs[0], true);
		flash(hostid);
		return true;
	}

	// ------------------------------------------------------------------ legend

	function legend() {
		const el = $('#hf-legend');
		if (!el) return;
		let s = '<span><i style="background:' + OK + '"></i>OK</span>'
			+ [2, 3, 4, 5].map((n) => '<span><i style="background:' + esc(HF.sevColor(n)) + '"></i>' + esc(HF.sevName(n)) + '</span>').join('')
			+ '<span><i style="background:' + NOACCESS + '"></i>Disabled / no access</span>'
			+ '<span>Dashed red tier = impacted by an upstream High or Disaster</span>';
		if (showCost && data && data.cost) {
			s += '<span style="flex-basis:100%"></span><b>Estimated cost: ' + esc(HF.money(data.cost.total)) + '/month</b>'
				+ Object.entries(data.cost.components).map(([c, v]) => '<span>' + esc(c || 'other') + ' ' + esc(HF.money(v)) + '</span>').join('')
				+ (data.cost.pricing_at ? '<span class="hf-muted">prices ' + esc(HF.ago(data.cost.pricing_at)) + '</span>' : '');
		}
		el.innerHTML = s;
	}

	// ------------------------------------------------------------------ export

	function exportSvg() {
		const bg = '#ffffff';
		const svg = graph.getSvg(bg, 1, 10, true, null, true, null, null, null, false);
		return '<?xml version="1.0" encoding="UTF-8"?>\n' + window.mxUtils.getXml(svg);
	}

	function download(name, blob) {
		const a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = name;
		document.body.appendChild(a);
		a.click();
		setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
	}

	// ------------------------------------------------------------------ download (with IP and cost, or plain)

	async function downloadView(mode, fmt) {
		const base = (current ? (current.client || current.name) : 'host-flow') + '-' + (capMode ? 'capacity' : (current && current.kind) || 'flow')
			+ '-' + (mode === 'plain' ? 'plain' : 'ip-cost');
		const name = (ext) => base.replace(/[^\w.-]+/g, '-') + '.' + ext;
		if (fmt === 'csv') {
			const rows = [mode === 'plain' ? ['Tier', 'Host'] : ['Tier', 'Host', 'IP', 'Monthly cost (USD)']];
			const label = {};
			((data && data.flow && data.flow.components) || []).forEach((c) => { label[c.id] = c.label; });
			Object.values((data && data.hosts) || {}).filter((h) => !isHidden(h.component || '', h.hostid))
				.sort((a, b) => (a.component || '~').localeCompare(b.component || '~') || a.name.localeCompare(b.name))
				.forEach((h) => {
					const tier = label[h.component] || h.component || 'Other';
					const cost = data.cost && data.cost.hosts[h.hostid] && !data.cost.hosts[h.hostid].excluded ? data.cost.hosts[h.hostid].monthly.toFixed(2) : '';
					rows.push(mode === 'plain' ? [tier, h.name] : [tier, h.name, h.ip, cost]);
				});
			const csv = rows.map((r) => r.map((v) => '"' + String(v).replace(/"/g, '""') + '"').join(',')).join('\r\n');
			download(name('csv'), new Blob([csv], {type: 'text/csv'}));
			return;
		}
		if (fmt === 'drawio') {
			const d = await HF.get('hostflow.load', {id: diagramId});
			download(name('drawio'), new Blob([d.xml], {type: 'application/xml'}));
			return;
		}
		if (!graph || !data) throw new Error('The flow is still loading.');
		exportMode = mode;
		apply();
		try {
			const svg = exportSvg();
			if (fmt === 'svg') download(name('svg'), new Blob([svg], {type: 'image/svg+xml'}));
			else await pngFrom(svg, name('png'));
		}
		finally {
			exportMode = null;
			apply();
		}
	}

	function pngFrom(svgText, fname) {
		return new Promise((resolve, reject) => {
			const img = new Image();
			img.onload = () => {
				const c = document.createElement('canvas');
				c.width = img.width * 2; c.height = img.height * 2;
				const ctx = c.getContext('2d');
				ctx.scale(2, 2);
				ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, img.width, img.height);
				ctx.drawImage(img, 0, 0);
				c.toBlob((b) => { download(fname, b); resolve(); }, 'image/png');
			};
			img.onerror = () => reject(new Error('Could not draw the PNG. Try SVG.'));
			img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svgText);
		});
	}

	// ------------------------------------------------------------------ show / hide tiers and hosts

	const hideKey = () => 'hostflow.hidden.' + ((current && current.client) || diagramId || '');
	function loadHidden() {
		hidden = {t: [], h: []};
		try { const v = JSON.parse(localStorage.getItem(hideKey()) || 'null'); if (v && Array.isArray(v.t) && Array.isArray(v.h)) hidden = v; } catch (x) { /* none */ }
	}
	function saveHidden() {
		try { localStorage.setItem(hideKey(), JSON.stringify(hidden)); } catch (x) { /* per browser only */ }
	}
	const isHidden = (comp, hostid) => (comp && hidden.t.includes(comp)) || (hostid && hidden.h.includes(String(hostid)));

	// Hide the chosen tiers (with their hosts) and hosts; arrows to anything hidden go too. Drawing only.
	function applyHidden() {
		if (!graph) return;
		const model = graph.getModel();
		const all = cells();
		model.beginUpdate();
		try {
			all.filter((c) => c.vertex).forEach((c) => {
				const comp = attr(c, 'zbx_component') || '', kind = attr(c, 'zbx_kind'), hid = attr(c, 'zbx_hostid');
				const off = (comp && (kind === 'component' || hid) && hidden.t.includes(comp)) || (hid && hidden.h.includes(hid));
				if (model.isVisible(c) === !!off) model.setVisible(c, !off);
			});
			const shown = (c) => { for (let x = c; x && x !== model.getRoot(); x = model.getParent(x)) { if (!model.isVisible(x)) return false; } return true; };
			all.filter((c) => c.edge).forEach((e) => {
				const on = !!(e.source && e.target && shown(e.source) && shown(e.target));
				if (model.isVisible(e) !== on) model.setVisible(e, on);
			});
		}
		finally {
			model.endUpdate();
		}
		const n = hidden.t.length + hidden.h.length;
		const btn = $('#hf-hide-btn');
		if (btn) btn.textContent = n ? 'Show / hide (' + n + ' hidden)' : 'Show / hide';
	}

	function renderHidePanel() {
		const pop = $('#hf-hide-pop');
		if (!pop || !data) return;
		const comps = ((data.flow || {}).components || []);
		const present = new Set(cells().map((c) => attr(c, 'zbx_component')).filter(Boolean));
		const hosts = Object.values(data.hosts || {});
		hosts.forEach((h) => { if (h.component) present.add(h.component); });
		const group = (id, label) => {
			const hs = hosts.filter((h) => (h.component || '') === id).sort((a, b) => a.name.localeCompare(b.name));
			return '<div class="hf-hgrp">' + (id ? '<label><input type="checkbox" data-tier="' + esc(id) + '"' + (hidden.t.includes(id) ? '' : ' checked') + '> <b>' + esc(label) + '</b></label>' : '<b>' + esc(label) + '</b>')
				+ hs.map((h) => '<label class="hf-hhost"><input type="checkbox" data-host="' + esc(h.hostid) + '"' + (hidden.h.includes(String(h.hostid)) ? '' : ' checked') + '> '
					+ esc(h.name) + ' <span class="hf-mono hf-muted">' + esc(h.ip) + '</span></label>').join('') + '</div>';
		};
		pop.innerHTML = '<div class="hf-pop-head"><b>Show on this flow</b><button type="button" class="btn-link" id="hf-hide-all">Show all</button></div>'
			+ comps.filter((c) => present.has(c.id)).map((c) => group(c.id, c.label)).join('')
			+ (hosts.some((h) => !h.component) ? group('', 'Other hosts (master, cluster, no tier)') : '')
			+ '<p class="hf-muted">Hiding changes only this drawing, in this browser. Zabbix monitoring is not affected.</p>';
		pop.querySelectorAll('[data-tier]').forEach((el) => el.addEventListener('change', () => {
			hidden.t = el.checked ? hidden.t.filter((x) => x !== el.dataset.tier) : hidden.t.concat(el.dataset.tier);
			saveHidden(); applyHidden(); fitView(); if (capMode) loadCapacity();
		}));
		pop.querySelectorAll('[data-host]').forEach((el) => el.addEventListener('change', () => {
			hidden.h = el.checked ? hidden.h.filter((x) => x !== el.dataset.host) : hidden.h.concat(el.dataset.host);
			saveHidden(); applyHidden(); fitView(); if (capMode) loadCapacity();
		}));
		bind('hf-hide-all', 'click', () => { hidden = {t: [], h: []}; saveHidden(); applyHidden(); fitView(); renderHidePanel(); if (capMode) loadCapacity(); });
	}

	// Small pop-up panels under a toolbar button; Escape or a click elsewhere closes them.
	function popover(btnId, popId, onOpen) {
		const btn = $('#' + btnId), pop = $('#' + popId);
		if (!btn || !pop) return;
		const close = () => { pop.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
		btn.addEventListener('click', (e) => {
			e.stopPropagation();
			const open = pop.hidden;
			document.querySelectorAll('.hf-pop').forEach((p) => { p.hidden = true; });
			if (open) { if (onOpen) onOpen(); pop.hidden = false; btn.setAttribute('aria-expanded', 'true'); }
			else close();
		});
		pop.addEventListener('click', (e) => e.stopPropagation());
		document.addEventListener('click', close);
		document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
	}

	// ------------------------------------------------------------------ capacity (CPU, memory, disk per tier)

	const gb = (b) => b == null ? '–' : b >= 2 ** 40 ? (b / 2 ** 40).toFixed(1) + ' TB' : Math.round(b / 2 ** 30) + ' GB';
	const capCell = (val, pct) => '<td><div class="hf-capv"><span>' + esc(val) + '</span>'
		+ (pct == null ? '<span class="hf-muted">no data</span>' : '<span class="hf-capbar"><i class="' + (pct >= 80 ? 'hot' : '') + '" style="width:' + Math.min(100, pct) + '%"></i></span><span class="hf-capp">' + pct + '%</span>')
		+ '</div></td>';

	async function loadCapacity() {
		const box = $('#hf-capacity');
		if (!box) return;
		const set = current && pairs[current.id];
		const id = set ? (set.find((x) => x.kind === 'hierarchy') || set[0]).id : diagramId;
		try {
			const d = await HF.get('hostflow.capacity', {id});
			if (!capMode) return;
			const comps = ((data && data.flow && data.flow.components) || []);
			const hosts = d.hosts.filter((h) => !isHidden(h.component, h.hostid));
			const sum = (list, k) => list.reduce((n, h) => n + (h[k] || 0), 0);
			const card = (label, color, list) => '<div class="hf-capcard"><div class="hf-caph" style="background:' + esc(color) + '"><b>' + esc(label) + '</b><span>' + list.length + (list.length === 1 ? ' host' : ' hosts') + '</span></div>'
				+ '<div class="hf-captot"><span><b>' + sum(list, 'cpu_num') + '</b> vCPU</span><span><b>' + gb(sum(list, 'mem_total')) + '</b> memory</span><span><b>' + gb(sum(list, 'disk_total')) + '</b> disk</span></div>'
				+ '<table class="hf-table hf-capt"><thead><tr><th>Host</th><th>CPU</th><th>Memory</th><th>Disk</th></tr></thead><tbody>'
				+ list.map((h) => '<tr><td><b>' + esc(h.name) + '</b><div class="hf-mono hf-muted">' + esc(h.ip) + '</div></td>'
					+ capCell(h.cpu_num != null ? h.cpu_num + ' vCPU' : '–', h.cpu_pct) + capCell(gb(h.mem_total), h.mem_pct) + capCell(gb(h.disk_total), h.disk_pct) + '</tr>').join('')
				+ '</tbody></table></div>';
			const groups = comps.map((c) => [c, hosts.filter((h) => h.component === c.id)]).filter(([, l]) => l.length);
			const other = hosts.filter((h) => !h.component || !comps.some((c) => c.id === h.component));
			box.innerHTML = '<div class="hf-capsum"><span><b>' + hosts.length + '</b> hosts</span><span><b>' + sum(hosts, 'cpu_num') + '</b> vCPU</span><span><b>'
				+ gb(sum(hosts, 'mem_total')) + '</b> memory</span><span><b>' + gb(sum(hosts, 'disk_total')) + '</b> disk</span><span class="hf-muted">Bars show current use; 80 % and above in red.</span></div>'
				+ '<div class="hf-capgrid">' + groups.map(([c, l]) => card(c.label, (HF.tierColors || {})[c.color] || '#5b6977', l)).join('')
				+ (other.length ? card('Master, cluster and other hosts', '#5b6977', other) : '') + '</div>'
				+ (d.hosts.length >= d.limit ? '<p class="hf-muted">Only the first ' + d.limit + ' hosts are shown.</p>' : '');
		}
		catch (e) { box.innerHTML = '<div class="hf-empty"><b>Could not load capacity</b>' + esc(e.message) + '</div>'; }
	}

	function setCapMode(on) {
		capMode = on;
		const wrap = document.querySelector('.hf-viewwrap'), legendEl = $('#hf-legend'), box = $('#hf-capacity');
		if (wrap) wrap.hidden = on;
		if (legendEl) legendEl.hidden = on;
		if (box) { box.hidden = !on; if (on) { box.innerHTML = '<div class="hf-empty">Loading capacity…</div>'; loadCapacity(); } }
		renderViews();
		if (!on) setTimeout(fitView, 30);
	}

	// ------------------------------------------------------------------ controls

	function bind(id, ev, fn) { const el = document.getElementById(id); if (el) el.addEventListener(ev, fn); }

	bind('hf-refresh', 'click', refresh);
	bind('hf-edit', 'click', () => { window.location.href = HF.url('hostflow.edit', {id: diagramId}); });
	popover('hf-hide-btn', 'hf-hide-pop', renderHidePanel);
	popover('hf-dl-btn', 'hf-dl-pop');
	bind('hf-dl-cancel', 'click', () => { $('#hf-dl-pop').hidden = true; });
	bind('hf-dl-go', 'click', async () => {
		const mode = (document.querySelector('input[name=hf-dl-c]:checked') || {}).value || 'ip';
		$('#hf-dl-pop').hidden = true;
		try { await downloadView(mode, $('#hf-dl-fmt').value); }
		catch (e) { HF.say(e.message, false); }
	});
	// Capacity has no diagram to draw: offer the host list there.
	bind('hf-dl-btn', 'click', () => {
		const fmt = $('#hf-dl-fmt');
		Array.from(fmt.options).forEach((o) => { o.disabled = capMode && o.value !== 'csv'; });
		if (capMode) fmt.value = 'csv';
	});
	bind('hf-kiosk', 'click', () => {
		const el = document.querySelector('.hf-viewwrap');
		if (document.fullscreenElement) document.exitFullscreen();
		else if (el.requestFullscreen) el.requestFullscreen().catch(() => {});
	});
	bind('hf-costbtn', 'click', (e) => {
		showCost = !showCost;
		e.target.setAttribute('aria-pressed', String(showCost));
		e.target.textContent = showCost ? 'Hide cost' : 'Show cost';
		if (data) apply();
	});
	const updateAt = () => {
		const span = +($('#hf-span') ? $('#hf-span').value : 1440);
		$('#hf-time').min = -span;
		$('#hf-time').step = span > 1440 ? 60 : 5;
		atMinutes = -(+$('#hf-time').value);
		$('#hf-at').textContent = atMinutes === 0 ? 'now' : new Date(Date.now() - atMinutes * 60000).toLocaleString();
	};
	bind('hf-time', 'input', updateAt);
	bind('hf-time', 'change', () => { updateAt(); schedule(); refresh(); });
	bind('hf-span', 'change', () => { updateAt(); schedule(); refresh(); });
	bind('hf-find', 'keydown', async (e) => {
		if (e.key !== 'Enter') return;
		const q = e.target.value.trim().toLowerCase();
		if (!q || !data) return;
		const hit = Object.values(data.hosts).find((h) => h.name.toLowerCase().includes(q) || h.host.toLowerCase().includes(q) || h.ip === q || h.ip.startsWith(q));
		if (hit && focus(hit.hostid)) return;
		try {
			const r = await HF.get('hostflow.find', {q});
			const elsewhere = r.hosts.filter((h) => h.diagrams.length);
			HF.say(elsewhere.length ? 'Not on this diagram. ' + elsewhere[0].name + ' is on: ' + elsewhere[0].diagrams.map((d) => d.name).join(', ') + '.'
				: 'No host matching “' + q + '” is on any diagram.', !!elsewhere.length);
		}
		catch (err) { HF.say(err.message, false); }
	});

	// ------------------------------------------------------------------ main page: flow list + details

	function lastFlow(set) {
		try {
			if (set) localStorage.setItem('hostflow.flow', set);
			return localStorage.getItem('hostflow.flow');
		}
		catch (e) { return null; }
	}

	async function loadFlows() {
		try {
			const d = await HF.get('hostflow.summary');
			flows = d.diagrams;
			renderFlows(d.cost);
			if (current) {
				const fresh = flows.find((f) => f.id === current.id);
				if (fresh) { current = fresh; renderKind(); }
			}
		}
		catch (e) {
			$('#hf-flows').innerHTML = '<div class="hf-empty"><b>Could not load flows</b>' + esc(e.message) + '</div>';
		}
	}

	function renderFlows(withCost) {
		const box = $('#hf-flows');
		if (!flows.length) {
			box.innerHTML = '<div class="hf-empty"><b>No flows yet</b>' + (HF.canEdit
				? (HF.autoFlows ? 'No client groups were found. Check Settings → Client groups, or choose New.' : 'Choose New to generate one from a client.')
				: 'An administrator opening Host Flow creates a flow for each client.') + '</div>';
			return;
		}
		const groups = new Map();
		flows.forEach((f) => { const k = f.client || ''; if (!groups.has(k)) groups.set(k, []); groups.get(k).push(f); });
		const keys = Array.from(groups.keys()).sort((a, b) => (a === '') - (b === '') || a.localeCompare(b, undefined, {numeric: true, sensitivity: 'base'}));
		pairs = {};
		let html = '';
		keys.forEach((k) => {
			let list = groups.get(k);
			// A client's automated Pipeline and Client hierarchy flows share one row; tabs switch between them.
			const pipe = list.find((f) => f.origin === 'auto' && f.kind === 'pipeline');
			const hier = list.find((f) => f.origin === 'auto' && f.kind === 'hierarchy');
			if (k !== '' && pipe && hier) {
				const set = [{id: pipe.id, kind: 'pipeline'}, {id: hier.id, kind: 'hierarchy'}];
				set.forEach((x) => { pairs[x.id] = set; });
				const row = Object.assign({}, pipe, {name: k, ids: [pipe.id, hier.id], auto: pipe.auto || hier.auto,
					worst: Math.max(pipe.worst, hier.worst), problems: Math.max(pipe.problems, hier.problems), hosts: Math.max(pipe.hosts, hier.hosts)});
				list = [row].concat(list.filter((f) => f !== pipe && f !== hier));
			}
			if (k === '' || list.length > 1) html += '<div class="hf-group">' + esc(k || 'Other diagrams') + '</div>';
			html += list.map((f) => { const ids = f.ids || [f.id], on = ids.includes(diagramId); return '<button type="button" role="listitem" class="hf-flow' + (on ? ' sel' : '') + '" data-id="' + esc(f.id) + '" data-ids="' + esc(ids.join(' ')) + '"'
				+ (on ? ' aria-current="true"' : '') + '>'
				+ '<span class="hf-dot" style="background:' + esc(f.worst >= 0 ? HF.sevColor(f.worst) : OK) + '"></span>'
				+ '<span class="nm"><b>' + esc(f.name) + '</b><small>' + (f.client && f.client !== f.name ? esc(f.client) + ' · ' : '')
				+ (f.template_name ? esc(f.template_name) + ' · ' : '') + f.hosts + ' hosts'
				+ (withCost && f.monthly != null ? ' · ' + esc(HF.money(f.monthly)) : '') + (f.auto ? ' · auto' : '') + '</small></span>'
				+ '<span class="hf-prob">' + (f.problems ? HF.sevBadge(f.worst, String(f.problems)) : '<span class="hf-badge ok">OK</span>') + '</span>'
				+ '</button>'; }).join('');
		});
		box.innerHTML = html;
		box.querySelectorAll('.hf-flow').forEach((b) => b.addEventListener('click', () => {
			// Clicking the client's row again keeps the tab that is open.
			if (b.dataset.ids.split(' ').includes(diagramId)) return;
			selectFlow(b.dataset.id);
		}));
		renderViews();
	}

	const titleOf = (f) => (pairs[f.id] && f.client ? f.client : f.name);

	// Pipeline / Client hierarchy tabs for a client's automated flows.
	function renderViews() {
		const el = $('#hf-views');
		if (!el) return;
		const set = current && pairs[current.id];
		el.hidden = !set;
		if (!set) { el.innerHTML = ''; return; }
		el.innerHTML = set.map((x) => '<button type="button" role="tab" aria-selected="' + (!capMode && x.id === current.id) + '" data-view="' + esc(x.id) + '">'
			+ (x.kind === 'pipeline' ? 'Pipeline' : 'Client hierarchy') + '</button>').join('')
			+ '<button type="button" role="tab" aria-selected="' + capMode + '" data-cap="1">Capacity</button>';
		el.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', () => {
			if (capMode) setCapMode(false);
			selectFlow(b.dataset.view);
		}));
		el.querySelectorAll('[data-cap]').forEach((b) => b.addEventListener('click', () => setCapMode(true)));
		if (current.client) $('#hf-title').textContent = current.client;
	}

	// Template name and how the flow is kept up to date, next to the title (not inside the diagram).
	function renderKind() {
		const el = $('#hf-kind');
		if (!el || !current) return;
		const tpl = current.template_name || (current.flow && current.flow.name) || '';
		el.innerHTML = (tpl ? '<span class="hf-tplchip" title="Flow template">' + esc(tpl) + '</span>' : '')
			+ (current.auto ? '<span class="hf-badge mod" title="Drawn from Zabbix. Follows the template and new or removed hosts.">auto</span>'
				: current.origin === 'auto' ? '<span class="hf-badge mute" title="Edited by hand, so it no longer follows the template.">edited</span>' : '');
	}

	async function selectFlow(id, focusHost) {
		if (!id) return;
		if (id === diagramId && graph) { if (focusHost) focus(focusHost); return; }
		const mine = ++opening;
		hideMenu();
		const prevClient = current && current.client;
		diagramId = id;
		current = flows.find((f) => f.id === id) || {id, name: ''};
		if (capMode && (!pairs[id] || prevClient !== current.client)) setCapMode(false);
		loadHidden();
		HF.focus = focusHost || null;
		data = null;
		lastFlow(id);
		const u = new URL(window.location.href);
		u.searchParams.set('action', 'hostflow.view');
		u.searchParams.set('id', id);
		['focus', 'hostid', 'q'].forEach((k) => u.searchParams.delete(k));
		window.history.replaceState(null, '', u.toString());
		$('#hf-title').textContent = titleOf(current);
		document.title = current.name + ' · Host Flow';
		renderKind();
		renderViews();
		$('#hf-changes').innerHTML = '';
		$('#hf-details').innerHTML = '';
		document.querySelectorAll('.hf-flow').forEach((b) => { const on = (b.dataset.ids || b.dataset.id).split(' ').includes(id); b.classList.toggle('sel', on); b.toggleAttribute('aria-current', on); });
		try {
			const meta = await open(id);
			if (mine !== opening) return;
			current = Object.assign({}, current, {name: meta.name, client: meta.client, auto: !!meta.auto, updated_by: meta.updated_by,
				updated_at: meta.updated_at, synced_at: meta.synced_at, layout: meta.layout, origin: meta.origin || (meta.auto ? 'auto' : ''),
				template_name: (meta.flow && meta.flow.name) || current.template_name || ''});
			$('#hf-title').textContent = titleOf(Object.assign({}, current, {id}));
			renderKind();
			renderViews();
			await refresh();
			setTimeout(fitView, 30);
			schedule();
		}
		catch (e) {
			if (mine === opening) $('#hf-viewer').innerHTML = '<div class="hf-empty"><b>Could not open the flow</b>' + esc(e.message) + '</div>';
		}
	}
	HF.selectFlow = selectFlow;

	const LAYOUT = {client: 'Client flow', hierarchy: 'Client hierarchy', dataflow: 'Data flow', monitoring: 'Monitoring path', combined: 'Combined'};

	function details() {
		const box = $('#hf-details');
		if (!box || !data || !current) return;
		const hosts = Object.values(data.hosts || {});
		const down = hosts.filter((h) => !h.disabled && h.available === 2).length;
		const disabled = hosts.filter((h) => h.disabled).length;
		const problems = [];
		hosts.forEach((h) => h.problems.forEach((p) => problems.push(Object.assign({host: h.name, hostid: h.hostid}, p))));
		problems.sort((a, b) => b.severity - a.severity);
		const count = hosts.reduce((n, h) => n + h.count, 0);
		const labels = {};
		((data.flow || {}).components || []).forEach((c) => { labels[c.id] = c.label; });
		const tiers = {};
		hosts.forEach((h) => {
			const t = tiers[h.component || ''] = tiers[h.component || ''] || {up: 0, total: 0, worst: -1};
			t.total++;
			if (!h.disabled && h.available !== 2) t.up++;
			t.worst = Math.max(t.worst, h.worst);
		});
		const order = ((data.flow || {}).components || []).map((c) => c.id).concat(['']);
		const stat = (n, label, cls) => '<div class="hf-stat' + (cls ? ' ' + cls : '') + '"><b>' + n + '</b><span>' + label + '</span></div>';

		box.innerHTML = '<h3>' + esc(current.name) + '</h3>'
			+ '<div class="hf-muted">' + (current.client ? 'Client ' + esc(current.client) + ' · ' : '') + esc(LAYOUT[current.layout] || 'Diagram')
				+ ((data.flow || {}).name ? ' · ' + esc(data.flow.name) : '') + '</div>'
			+ '<div class="hf-stats">' + stat(hosts.length, 'hosts') + stat(hosts.length - down - disabled, 'up')
			+ stat(down, 'unreachable', down ? 'bad' : '') + stat(count, 'problems', count ? 'bad' : '') + '</div>'
			+ (Object.keys(tiers).length ? '<h4>Tiers</h4><table class="hf-table hf-tiers"><tbody>'
				+ order.filter((c) => tiers[c]).map((c) => '<tr><td>' + esc(labels[c] || (c || 'Cluster / other')) + '</td><td class="num">' + tiers[c].up + '/' + tiers[c].total + ' up</td>'
					+ '<td class="num">' + HF.sevBadge(tiers[c].worst) + '</td></tr>').join('') + '</tbody></table>' : '')
			+ '<div class="hf-prob"><h4>Problems</h4>' + (problems.length
				? '<ul class="hf-plist">' + problems.slice(0, 12).map((p) => '<li><a href="#" data-host="' + esc(p.hostid) + '">' + HF.sevBadge(p.severity, HF.sevName(p.severity))
					+ ' <b>' + esc(p.host) + '</b> ' + esc(p.name) + '</a></li>').join('')
					+ (count > 12 ? '<li class="hf-muted">and ' + (count - 12) + ' more</li>' : '') + '</ul>'
				: '<p class="hf-muted">No problems.</p>') + '</div>'
			+ (data.cost ? '<h4>Cost</h4><p><b>' + esc(HF.money(data.cost.total)) + '</b> per month (estimate)</p>' : '')
			+ '<div class="hf-who">' + (current.auto
				? '<p class="hf-muted">Drawn from Zabbix with the <b>' + esc(current.template_name || 'default') + '</b> template' + (current.synced_at ? ', checked ' + esc(HF.ago(current.synced_at)) : '')
					+ '. Template changes show at once; new or removed hosts at the next check.</p>'
				: current.origin === 'auto'
					? '<p class="hf-muted">Edited by ' + esc(current.updated_by || 'someone') + ' · ' + esc(HF.ago(current.updated_at)) + ', so it no longer follows the template.</p>'
						+ (HF.canEdit ? '<button type="button" class="btn-alt" id="hf-reset">Follow template again</button><div class="hf-status" id="hf-reset-status" role="status"></div>' : '')
					: '<p class="hf-muted">Drawn by hand' + (current.updated_by ? ' · edited by ' + esc(current.updated_by) + ' ' + esc(HF.ago(current.updated_at)) : '')
						+ '. It keeps its own layout; in Edit → Generate you can redraw it from the client\'s template.</p>') + '</div>';
		bind('hf-reset', 'click', resetFlow);
		box.querySelectorAll('[data-host]').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); focus(a.dataset.host); }));
	}

	// An automated flow edited by hand: redraw it from the client's template and let it follow Zabbix again.
	function resetFlow() {
		HF.dialog('Follow the template again?', '<p>“' + esc(current.name) + '” is redrawn from the <b>' + esc(current.template_name || 'client\'s')
			+ '</b> template. Changes made by hand in the editor are replaced.</p>', async () => {
			const r = await HF.post('hostflow.autoflows', {reset: current.id});
			const id = current.id;
			diagramId = null;
			await loadFlows();
			await selectFlow(id);
			HF.say('“' + r.meta.name + '” follows its template again.', true);
		}, 'Redraw from template');
	}

	// The app fills the window under the Zabbix header, so the flow gets all the space there is.
	function fitHeight() {
		const app = $('#hf-app');
		if (!app) return;
		const foot = document.querySelector('footer');
		const h = window.innerHeight - app.getBoundingClientRect().top - (foot ? foot.offsetHeight : 0) - 8;
		app.style.height = Math.max(420, h) + 'px';
	}

	async function startApp() {
		fitHeight();
		// Problems in the list and details can be hidden; the choice is kept per browser.
		const prob = $('#hf-showprob');
		if (prob) {
			try { prob.checked = localStorage.getItem('hostflow.showProblems') !== '0'; } catch (x) { /* default: shown */ }
			$('#hf-app').classList.toggle('hf-noprob', !prob.checked);
			prob.addEventListener('change', () => {
				$('#hf-app').classList.toggle('hf-noprob', !prob.checked);
				try { localStorage.setItem('hostflow.showProblems', prob.checked ? '1' : '0'); } catch (x) { /* per browser only */ }
			});
		}
		window.addEventListener('resize', HF.debounce(() => { fitHeight(); fitView(); }, 150));
		bind('hf-navtoggle', 'click', (e) => {
			const app = $('#hf-app');
			const hidden = app.classList.toggle('nav-hidden');
			e.currentTarget.setAttribute('aria-expanded', String(!hidden));
			e.currentTarget.textContent = hidden ? '⟩' : '⟨';
			e.currentTarget.title = hidden ? 'Show the flow list' : 'Hide the flow list';
			try { localStorage.setItem('hostflow.nav', hidden ? '0' : '1'); } catch (x) { /* optional */ }
			setTimeout(fitView, 50);
		});
		try { if (localStorage.getItem('hostflow.nav') === '0') $('#hf-navtoggle').click(); } catch (x) { /* optional */ }

		if (HF.canEdit && HF.autoFlows) {
			$('#hf-flows').innerHTML = '<div class="hf-empty">Checking client flows…</div>';
			try {
				const r = await HF.post('hostflow.autoflows', {});
				const note = [r.created.length ? 'New client flow: ' + r.created.join(', ') : '', r.updated.length ? 'Updated from Zabbix: ' + r.updated.join(', ') : '']
					.filter(Boolean).join(' · ');
				if (note) { $('#hf-navnote').textContent = note; setTimeout(() => { $('#hf-navnote').textContent = ''; }, 15000); }
			}
			catch (e) { /* the list still loads; Settings shows storage problems */ }
		}
		await loadFlows();
		loadHidden();
		const want = [HF.meta && HF.meta.id, lastFlow(), flows[0] && flows[0].id].find((id) => id && flows.some((f) => f.id === id));
		if (want) selectFlow(want, HF.focus);
		else $('#hf-viewer').innerHTML = '<div class="hf-empty"><b>No flow to show</b>Flows appear on the left.</div>';
		setInterval(() => { if (!document.hidden) loadFlows(); }, Math.max(30, HF.refresh || 30) * 1000);
		if (HF.costStale) HF.post('hostflow.cost.refresh', {only_if_stale: '1'}).then(refresh).catch(() => {});
	}

	// ------------------------------------------------------------------ start

	async function start() {
		if (HF.wall) return wall();
		if (APP) return startApp();
		try {
			await open(diagramId);
			await refresh();
			schedule();
			if (HF.costStale) {
				HF.post('hostflow.cost.refresh', {only_if_stale: '1'}).then(refresh).catch(() => {});
			}
		}
		catch (e) {
			$('#hf-viewer').innerHTML = '<div class="hf-empty"><b>Could not open the diagram</b>' + esc(e.message) + '</div>';
		}
	}

	// NOC wall: cycle through diagrams; tiers with unacknowledged Disasters are brought into view.
	async function wall() {
		let list = HF.wall.slice(), i = 0, every = HF.every;
		const title = $('#hf-wall-title'), next = $('#hf-wall-next');
		if (!list.length) { $('#hf-viewer').innerHTML = '<div class="hf-empty"><b>No diagrams to show</b>Create a diagram first.</div>'; return; }
		const pick = $('#hf-wall-pick');
		if (pick) pick.addEventListener('change', () => {
			const ids = Array.from(pick.selectedOptions).map((o) => o.value);
			const u = new URL(window.location.href);
			u.searchParams.set('ids', ids.join(','));
			window.location.href = u.toString();
		});
		bind('hf-wall-every', 'change', (e) => { every = Math.max(10, +e.target.value || 30); });
		bind('hf-wall-full', 'click', () => { const el = document.documentElement; if (el.requestFullscreen) el.requestFullscreen().catch(() => {}); });
		const show = async () => {
			const d = list[i % list.length];
			title.textContent = d.name;
			try {
				await open(d.id);
				await refresh();
				const worst = Object.values(data.hosts).filter((h) => h.unacked && h.worst === 5);
				if (worst.length) focus(worst[0].hostid);
			}
			catch (e) { $('#hf-viewer').innerHTML = '<div class="hf-empty"><b>' + esc(d.name) + '</b>' + esc(e.message) + '</div>'; }
			let left = every;
			clearInterval(timer);
			timer = setInterval(() => {
				left -= 1;
				next.textContent = list.length > 1 ? 'next in ' + left + ' s' : '';
				if (left % Math.max(10, HF.refresh || 30) === 0 && left > 0) refresh();
				if (left <= 0) { clearInterval(timer); i++; show(); }
			}, 1000);
		};
		show();
	}

	if (HF.kiosk && !HF.wall) document.querySelector('.hf-viewwrap').parentElement.classList.add('hf-kiosk');
	start();
})();
