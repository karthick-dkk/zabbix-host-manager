/* Settings page (Super admin): edits one settings object and saves it whole. */
(function () {
	'use strict';
	const HF = window.HF, esc = HF.esc;
	const $ = (s) => document.querySelector(s);
	const S = JSON.parse(JSON.stringify(HF.settings));
	// PHP sends an empty map as [], which would drop note keys; make them objects.
	S.flow_templates.forEach((t) => {
		if (Array.isArray(t.notes) || !t.notes) t.notes = {};
		if (Array.isArray(t.queues) || !t.queues) t.queues = {};
	});
	const QUEUES = {redis: 'Redis', kafka: 'Kafka', both: 'Redis · Kafka'};
	let dirty = false;
	let tsel = Math.max(0, S.flow_templates.findIndex((t) => t.id === S.default_template));
	let showLabels = true;
	try { showLabels = localStorage.getItem('hostflow.tplLabels') !== '0'; } catch (x) { /* default: shown */ }
	const tpl = () => S.flow_templates[tsel];

	const list = (a) => (a || []).join(', ');
	const split = (s) => String(s || '').split(',').map((x) => x.trim()).filter(Boolean);
	const tagsToText = (t) => (t || []).map((x) => x.value ? x.tag + '=' + x.value : x.tag).join(', ');
	const textToTags = (s) => split(s).map((x) => { const i = x.indexOf('='); return i < 0 ? {tag: x, value: ''} : {tag: x.slice(0, i).trim(), value: x.slice(i + 1).trim()}; });
	const opt = (v, cur, label) => '<option value="' + esc(v) + '"' + (String(v) === String(cur) ? ' selected' : '') + '>' + esc(label || v) + '</option>';
	const markDirty = () => { dirty = true; $('#hf-dirty').textContent = 'Unsaved changes'; };

	/**
	 * Live preview of a flow template: tiers in columns left to right along the arrows (back arrows
	 * ignored when ranking, a side feed sits just before what it feeds, a two-way partner shares the
	 * column), the same rules the generated client flows use.
	 */
	// The same tier icons as the flows: a coloured tile with a white glyph.
	const iconSvg = (icon, color) => '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30"><rect width="30" height="30" rx="7" fill="'
		+ (HF.colors[color] || HF.colors.neutral) + '"/><g fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
		+ ((HF.glyphs || {})[icon] || (HF.glyphs || {}).generic || '') + '</g></svg>';
	const iconUri = (icon, color) => 'data:image/svg+xml;base64,' + btoa(iconSvg(icon, color));

	function tplPreview(t) {
		const comp = (id) => S.components.find((c) => c.id === id) || {id, label: id, color: 'neutral', icon: 'generic'};
		const ids = (t.tiers.length ? t.tiers : [...new Set(t.edges.flatMap((e) => [e.from, e.to]))]).filter((id) => S.components.some((c) => c.id === id));
		if (!ids.length) return '<p class="hf-muted">Tick some tiers and add arrows to see the flow.</p>';
		const edges = t.edges.filter((e) => ids.includes(e.from) && ids.includes(e.to));
		// Columns: the same rules as the generated flows (back arrows ignored, side feeds before their target, two-way partners share a column).
		const adj = {}, state = {}, keep = [];
		ids.forEach((i) => { adj[i] = []; });
		edges.forEach((e) => adj[e.from].push(e.to));
		const visit = (u) => { state[u] = 1; adj[u].forEach((v) => { if (state[v] === 1) return; keep.push([u, v]); if (!state[v]) visit(v); }); state[u] = 2; };
		ids.forEach((i) => { if (!state[i]) visit(i); });
		const indeg = {}, next = {}, rank = {};
		ids.forEach((i) => { indeg[i] = 0; next[i] = []; });
		keep.forEach(([a, b]) => { next[a].push(b); indeg[b]++; });
		const q = ids.filter((i) => indeg[i] === 0);
		q.forEach((i) => { rank[i] = 0; });
		while (q.length) { const u = q.shift(); next[u].forEach((b) => { rank[b] = Math.max(rank[b] || 0, rank[u] + 1); if (--indeg[b] === 0) q.push(b); }); }
		ids.forEach((i) => { if (rank[i] === 0 && next[i].length) rank[i] = Math.max(0, Math.min(...next[i].map((b) => rank[b] || 1)) - 1); });
		const twoWay = (e) => edges.some((f) => f.from === e.to && f.to === e.from);
		edges.forEach((e) => { if (twoWay(e) && rank[e.to] > rank[e.from]) rank[e.to] = rank[e.from]; });

		const W = 132, H = 46, CW = 196, RH = 80, PAD = 20, SM = 40;
		// Rows: a two-way partner sits next to its partner; other tiers line up with what feeds them, else take the next free row.
		const order = ids.slice().sort((a, b) => (rank[a] || 0) - (rank[b] || 0) || ids.indexOf(a) - ids.indexOf(b));
		const rowOf = {}, used = {};
		order.forEach((id) => {
			const c = rank[id] || 0;
			const taken = used[c] = used[c] || new Set();
			const partner = order.find((o) => rowOf[o] !== undefined && (rank[o] || 0) === c && edges.some((e) => e.from === o && e.to === id) && edges.some((e) => e.from === id && e.to === o));
			let r;
			if (partner !== undefined) {
				for (let k = 1; r === undefined; k++) {
					if (!taken.has(rowOf[partner] + k)) r = rowOf[partner] + k;
					else if (!taken.has(rowOf[partner] - k)) r = rowOf[partner] - k;
				}
			}
			else {
				const feed = edges.find((e) => e.to === id && rowOf[e.from] !== undefined && (rank[e.from] || 0) < c);
				r = feed ? rowOf[feed.from] : 0;
				while (taken.has(r)) r++;
			}
			taken.add(r);
			rowOf[id] = r;
		});
		const minRow = Math.min(...Object.values(rowOf)), maxRow = Math.max(...Object.values(rowOf));
		// Each tier's box; a pass-through (icon only) is a small circle centred in its cell.
		const pos = {};
		ids.forEach((id) => {
			const cx = PAD + (rank[id] || 0) * CW, cy = PAD + 14 + (rowOf[id] - minRow) * RH, small = !!comp(id).small;
			pos[id] = small ? {x: cx + (W - SM) / 2, y: cy + (H - SM) / 2, w: SM, h: SM, small} : {x: cx, y: cy, w: W, h: H, small};
		});
		const width = PAD * 2 + (Math.max(...Object.values(rank)) + 1) * CW - (CW - W);
		const height = PAD * 2 + 14 + (maxRow - minRow + 1) * RH - (RH - H);
		const MX = (p) => p.x + p.w / 2, MY = (p) => p.y + p.h / 2;
		const colOf = (p) => Math.round((MX(p) - PAD - W / 2) / CW);
		let paths = '', labels = '';
		edges.forEach((e, i) => {
			// Two tiers sending to each other: one line, a head at each end, both labels.
			const back = edges.findIndex((f) => f.from === e.to && f.to === e.from);
			if (back !== -1 && back < i) return;
			const a = pos[e.from], b = pos[e.to];
			const intoSmall = b.small, fromSmall = a.small;
			let label = back !== -1 ? [e.label, edges[back].label].filter(Boolean).join(' / ') : (e.label || '');
			if (intoSmall) label = '';
			else if (fromSmall && label) label += ' via ' + comp(e.from).label;
			const cols = colOf(b) - colOf(a);
			let d, lx, ly, anchor = 'middle';
			if (cols === 0) {
				const down = b.y > a.y;
				if (Math.abs(b.y - a.y) <= RH + 1) {
					const x = MX(a), y1 = down ? a.y + a.h : a.y, y2 = down ? b.y : b.y + b.h;
					d = 'M' + x + ',' + y1 + ' V' + y2;
					anchor = 'start'; lx = x + 6; ly = (y1 + y2) / 2 + 4;
				}
				else {
					const x = down ? a.x + a.w : a.x, k = down ? 22 : -22, xe = down ? b.x + b.w : b.x;
					d = 'M' + x + ',' + MY(a) + ' H' + (x + k) + ' V' + MY(b) + ' H' + xe;
					anchor = down ? 'start' : 'end'; lx = x + k + (down ? 5 : -5); ly = (MY(a) + MY(b)) / 2 + 4;
				}
			}
			else if (rowOf[e.from] === rowOf[e.to] && Math.abs(cols) === 1) {
				const fwd = cols > 0, x1 = fwd ? a.x + a.w : a.x, x2 = fwd ? b.x : b.x + b.w;
				d = 'M' + x1 + ',' + MY(a) + ' H' + x2;
				lx = (x1 + x2) / 2; ly = MY(a) - 6;
			}
			else if (rowOf[e.from] === rowOf[e.to]) {
				const fwd = cols > 0, x1 = fwd ? a.x + a.w - 18 : a.x + 18, x2 = fwd ? b.x + 18 : b.x + b.w - 18, top = Math.min(a.y, b.y) - 14;
				d = 'M' + x1 + ',' + a.y + ' V' + top + ' H' + x2 + ' V' + b.y;
				lx = (x1 + x2) / 2; ly = top - 4;
			}
			else if (b.y > a.y) {
				const fwd = cols > 0, x = MX(a), xe = fwd ? b.x : b.x + b.w;
				d = 'M' + x + ',' + (a.y + a.h) + ' V' + MY(b) + ' H' + xe;
				anchor = fwd ? 'end' : 'start'; lx = x + (fwd ? -6 : 6); ly = (a.y + a.h + MY(b)) / 2 + 4;
			}
			else {
				const fwd = cols > 0, xs = fwd ? a.x + a.w : a.x;
				const blocked = ids.some((x) => x !== e.to && (rank[x] || 0) === (rank[e.to] || 0) && rowOf[x] === rowOf[e.from]);
				if (!blocked) {
					const x = MX(b);
					d = 'M' + xs + ',' + MY(a) + ' H' + x + ' V' + (b.y + b.h);
					anchor = fwd ? 'start' : 'end'; lx = x + (fwd ? 6 : -6); ly = (MY(a) + b.y + b.h) / 2 + 4;
				}
				else {
					const gx = fwd ? b.x - 16 : b.x + b.w + 16, xe = fwd ? b.x : b.x + b.w, ye = MY(b) + 10;
					d = 'M' + xs + ',' + MY(a) + ' H' + gx + ' V' + ye + ' H' + xe;
					anchor = fwd ? 'end' : 'start'; lx = gx + (fwd ? -6 : 6); ly = (MY(a) + ye) / 2 + 4;
				}
			}
			const start = back !== -1 || e.both ? ' marker-start="url(#hf-pv-arrow)"' : '';
			const end = intoSmall ? '' : ' marker-end="url(#hf-pv-arrow)"';
			paths += '<path d="' + d + '" class="hf-pv-edge"' + start + end + '/>';
			if (showLabels && label) labels += '<text x="' + lx + '" y="' + ly + '" class="hf-pv-label" text-anchor="' + anchor + '">' + esc(label) + '</text>';
		});
		const glyph = (c) => (HF.glyphs || {})[c.icon] || (HF.glyphs || {}).generic || '';
		const boxes = ids.map((id) => {
			const c = comp(id), p = pos[id];
			const fill = HF.colors[c.color] || HF.colors.neutral;
			if (p.small) {
				return '<g><text x="' + MX(p) + '" y="' + (p.y - 6) + '" text-anchor="middle" class="hf-pv-small">' + esc(c.label) + '</text>'
					+ '<circle cx="' + MX(p) + '" cy="' + MY(p) + '" r="' + (SM / 2) + '" fill="' + esc(fill) + '" stroke="#ffffff" stroke-width="2"/>'
					+ '<g transform="translate(' + (MX(p) - 13) + ',' + (MY(p) - 13) + ') scale(.8667)" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + glyph(c) + '</g></g>';
			}
			const note = [QUEUES[t.queues[id]] || '', t.notes[id] || ''].filter(Boolean).join(' · ');
			const tx = p.x + 34 + (W - 34) / 2;
			return '<g><rect x="' + p.x + '" y="' + p.y + '" width="' + W + '" height="' + H + '" rx="7" fill="' + esc(fill) + '"'
				+ (c.kind === 'cost' ? ' stroke-dasharray="4 3" stroke="#ffffff" stroke-opacity=".7"' : '') + '/>'
				+ '<rect x="' + (p.x + 7) + '" y="' + (p.y + 10) + '" width="26" height="26" rx="6" fill="#ffffff" fill-opacity=".18"/>'
				+ '<g transform="translate(' + (p.x + 7) + ',' + (p.y + 10) + ') scale(.8667)" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + glyph(c) + '</g>'
				+ '<text x="' + tx + '" y="' + (p.y + (note ? 20 : 28)) + '" text-anchor="middle" class="hf-pv-name">' + esc(c.label.length > 13 ? c.label.slice(0, 12) + '…' : c.label) + '</text>'
				+ (note ? '<text x="' + tx + '" y="' + (p.y + 36) + '" text-anchor="middle" class="hf-pv-note">' + esc(note.length > 16 ? note.slice(0, 15) + '…' : note) + '</text>' : '')
				+ '</g>';
		}).join('');
		return '<svg class="hf-pv" viewBox="0 0 ' + width + ' ' + height + '" width="' + width + '" height="' + height + '" role="img" aria-label="Preview of ' + esc(t.name) + '">'
			+ '<defs><marker id="hf-pv-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" class="hf-pv-head"/></marker></defs>'
			+ paths + boxes + labels + '</svg>';
	}

	function tplEditor() {
		const t = tpl();
		const label = (id) => (S.components.find((c) => c.id === id) || {label: id}).label;
		const lines = t.edges.map((e) => label(e.from) + (t.notes[e.from] ? ' (' + t.notes[e.from] + ')' : '') + ' → ' + label(e.to) + (t.notes[e.to] ? ' (' + t.notes[e.to] + ')' : ''));
		return '<div class="hf-tpl">'
			+ '<div class="hf-tpl-head">' + field('Id (value of the macro)', '<input type="text" data-t="id" value="' + esc(t.id) + '" style="width:140px">')
			+ field('Name', '<input type="text" data-t="name" value="' + esc(t.name) + '" style="width:220px">')
			+ (t.id === S.default_template ? '<span class="hf-muted">Used by clients without the macro.</span>' : '<button type="button" class="btn-alt" id="hf-tpl-default">Make default</button>')
			+ (S.flow_templates.length > 1 ? '<button type="button" class="btn-link" id="hf-tpl-del">Delete template</button>' : '') + '</div>'
			+ '<div class="hf-tpl-preview"><div class="hf-tpl-preview-head"><h4>Preview</h4>'
			+ '<label><input type="checkbox" id="hf-tpl-labels"' + (showLabels ? ' checked' : '') + '> Show arrow labels</label></div>'
			+ '<div class="hf-tpl-preview-box">' + tplPreview(t) + '</div></div>'
			+ '<div class="hf-tpl-body"><div><h4>Tiers</h4><table class="hf-table hf-grid-table"><thead><tr><th></th><th>Component</th><th>Queue</th><th>Note on the tier</th></tr></thead><tbody>'
			+ S.components.map((c) => '<tr><td><input type="checkbox" data-tier="' + esc(c.id) + '"' + (t.tiers.includes(c.id) ? ' checked' : '') + ' aria-label="Use ' + esc(c.label) + '"></td>'
				+ '<td>' + esc(c.label) + ' <span class="hf-muted hf-mono">' + esc(c.id) + '</span></td>'
				+ '<td><select data-queue="' + esc(c.id) + '" aria-label="Queue for ' + esc(c.label) + '">' + opt('', t.queues[c.id] || '', '–') + opt('redis', t.queues[c.id] || '', 'Redis')
					+ opt('kafka', t.queues[c.id] || '', 'Kafka') + opt('both', t.queues[c.id] || '', 'Redis + Kafka') + '</select></td>'
				+ '<td><input type="text" data-note="' + esc(c.id) + '" value="' + esc(t.notes[c.id] || '') + '" maxlength="40"></td></tr>').join('')
			+ '</tbody></table></div>'
			+ '<div><h4>Arrows</h4><table class="hf-table hf-grid-table"><thead><tr><th>From</th><th>To</th><th>Label</th><th>Both directions</th><th>Item key (optional)</th><th>Drop %</th><th></th></tr></thead><tbody>'
			+ t.edges.map((e, i) => '<tr data-tedge="' + i + '"><td><select data-f="from">' + S.components.map((x) => opt(x.id, e.from, x.label)).join('') + '</select></td>'
				+ '<td><select data-f="to">' + S.components.map((x) => opt(x.id, e.to, x.label)).join('') + '</select></td>'
				+ '<td><input type="text" data-f="label" value="' + esc(e.label) + '"></td>'
				+ '<td style="text-align:center"><input type="checkbox" data-f="both"' + (e.both ? ' checked' : '') + ' aria-label="Both directions"></td>'
				+ '<td><input type="text" data-f="item_key" value="' + esc(e.item_key || '') + '"></td>'
				+ '<td><input type="number" data-f="drop_pct" min="0" max="100" value="' + (e.drop_pct || 0) + '" style="width:64px"></td>'
				+ '<td><button type="button" class="btn-link" data-del-tedge="' + i + '">Remove</button></td></tr>').join('')
			+ '</tbody></table><button type="button" class="btn-alt" id="hf-add-tedge">Add arrow</button>'
			+ (lines.length ? '<h4>Flow</h4><ul class="hf-tpl-lines">' + lines.map((l) => '<li>' + esc(l) + '</li>').join('') + '</ul>' : '') + '</div></div></div>';
	}

	function render() {
		const c = S.cost, a = S.aws;
		$('#hf-settings').innerHTML = '<div class="hf-cards">'
			+ card('Status', '<table class="hf-table">'
				+ '<tr><td>Data directory</td><td class="hf-mono">' + esc(HF.dataDir) + ' ' + (HF.dirOk ? '<span class="hf-badge ok">writable</span>' : '<span class="hf-badge" style="background:#fdecec;color:#c62828">' + esc(HF.dirMsg) + '</span>') + '</td></tr>'
				+ '<tr><td>draw.io</td><td>' + (HF.drawioVersion ? 'v' + esc(HF.drawioVersion) + ' bundled' : '<b>Not installed.</b> Run tools/install-drawio.sh in the module folder.') + '</td></tr>'
				+ '<tr><td>Host menu</td><td>' + (HF.scriptInstalled ? '<span class="hf-badge ok">“Open in Host Flow” installed</span> ' : '')
				+ '<button type="button" class="btn-alt" id="hf-script">' + (HF.scriptInstalled ? 'Reinstall' : 'Add “Open in Host Flow” to host menus') + '</button></td></tr>'
				+ '</table>')
			+ card('Client groups', '<p class="hf-muted">A client is a host group. It is found automatically when any host has the client macro set to the group name. A host with the macro and one of the node templates is drawn as the client\'s cluster or master node.</p>'
				+ field('Cluster / master node templates (comma separated)', '<input type="text" data-k="client.cluster_templates" data-list value="' + esc(list(S.client.cluster_templates)) + '">')
				+ field('Client macro on the cluster host', '<input type="text" data-k="client.grp_macro" value="' + esc(S.client.grp_macro) + '">')
				+ field('Also treat these host groups as clients (comma separated)', '<input type="text" data-k="client.groups" data-list value="' + esc(list(S.client.groups)) + '">')
				+ '<label><input type="checkbox" data-k="client.autodetect"' + (S.client.autodetect ? ' checked' : '') + '> Detect clients from the macro</label>')
			+ card('Client flows', '<p class="hf-muted">Host Flow creates a flow for every client from Zabbix. It follows new and removed hosts until someone edits it; after that it is left as edited.</p>'
				+ '<label><input type="checkbox" data-k="auto_flows.enabled"' + (S.auto_flows.enabled ? ' checked' : '') + '> Create a flow for each client automatically</label>'
				+ field('Client flows to create', '<select data-k="auto_flows.layout">' + [['both', 'Pipeline and client hierarchy, as two separate flows'], ['dataflow', 'Pipeline only'], ['hierarchy', 'Client hierarchy only'], ['client', 'Pipeline and hierarchy together in one flow'],
					['combined', 'Combined'], ['monitoring', 'Monitoring path']].map(([v, l]) => opt(v, S.auto_flows.layout, l)).join('') + '</select>')
				+ field('Check for new or removed hosts every (minutes)', '<input type="number" min="1" max="1440" data-k="auto_flows.sync_min" value="' + S.auto_flows.sync_min + '">'))
			+ card('Live view', field('Refresh every (seconds, 10–300)', '<input type="number" min="10" max="300" data-k="refresh_sec" value="' + S.refresh_sec + '">')
				+ field('Host label (placeholders %name% %ip% %host% %port% %status%)', '<input type="text" data-k="labels.host" value="' + esc(S.labels.host) + '">')
				+ field('Trigger names that mean "down" (for dependency suggestions, regular expression)', '<input type="text" data-k="dependency_trigger_regex" value="' + esc(S.dependency_trigger_regex) + '">'))
			+ '</div>'

			+ card('Components', '<p class="hf-muted">Each component is a tier. A host belongs to the first component (top to bottom) whose host groups, tags or templates match it.</p>'
				+ '<div style="overflow:auto"><table class="hf-table hf-grid-table"><thead><tr><th>Id</th><th>Label</th><th>Icon</th><th>Icon only</th><th>Colour</th><th>Kind</th><th>Col</th>'
				+ '<th>Host groups</th><th>Tags (tag=value)</th><th>Templates</th><th></th></tr></thead><tbody>'
				+ S.components.map((x, i) => '<tr data-comp="' + i + '">'
					+ '<td><input type="text" data-f="id" value="' + esc(x.id) + '" style="width:80px"></td>'
					+ '<td><input type="text" data-f="label" value="' + esc(x.label) + '" style="width:90px"></td>'
					+ '<td class="hf-icon-cell"><img class="hf-icon-pv" alt="" src="' + iconUri(x.icon, x.color) + '"><select data-f="icon" aria-label="Icon for ' + esc(x.label) + '">' + HF.icons.map((ic) => opt(ic, x.icon)).join('') + '</select></td>'
					+ '<td style="text-align:center"><input type="checkbox" data-f="small"' + (x.small ? ' checked' : '') + ' title="Draw as a small icon that data passes through (like VPN)"></td>'
					+ '<td><select data-f="color">' + Object.keys(HF.colors).map((cl) => opt(cl, x.color)).join('') + '</select></td>'
					+ '<td><select data-f="kind">' + opt('hosts', x.kind, 'hosts') + opt('static', x.kind, 'static (no hosts)') + opt('cost', x.kind, 'cost only') + '</select></td>'
					+ '<td><input type="number" data-f="column" min="0" max="20" value="' + x.column + '" style="width:54px"></td>'
					+ '<td><input type="text" data-f="groups" value="' + esc(list(x.match.groups)) + '" placeholder="set the host groups"></td>'
					+ '<td><input type="text" data-f="tags" value="' + esc(tagsToText(x.match.tags)) + '"></td>'
					+ '<td><input type="text" data-f="templates" value="' + esc(list(x.match.templates)) + '"></td>'
					+ '<td class="hf-row-actions"><button type="button" class="btn-link" data-up="' + i + '" title="Move up">↑</button> '
					+ '<button type="button" class="btn-link" data-del-comp="' + i + '">Remove</button></td></tr>').join('')
				+ '</tbody></table></div><button type="button" class="btn-alt" id="hf-add-comp">Add component</button>')

			+ card('Flow templates', '<p class="hf-muted">Each client\'s flow is drawn from a template: its tiers, a note per tier and its arrows. '
				+ 'A client uses the template named by the macro on its cluster host (e.g. <code>' + esc(S.template_macro) + '=s3-ulm</code>); clients without it use the default. '
				+ 'Client flows nobody has edited follow template changes; edited diagrams keep the template they were drawn with.</p>'
				+ field('Macro on the cluster host that picks the template', '<input type="text" data-k="template_macro" value="' + esc(S.template_macro) + '" style="max-width:280px">')
				+ '<div class="hf-tpl-tabs" role="tablist">' + S.flow_templates.map((t, i) => '<button type="button" role="tab" aria-selected="' + (i === tsel) + '" data-tsel="' + i + '">'
					+ esc(t.name) + (t.id === S.default_template ? ' <span class="hf-badge ok">default</span>' : '') + '</button>').join('')
				+ '<button type="button" class="btn-link" id="hf-tpl-add">+ New template</button><button type="button" class="btn-link" id="hf-tpl-dup">Duplicate</button></div>'
				+ tplEditor())

			+ card('Cost', '<label><input type="checkbox" data-k="cost.enabled"' + (c.enabled ? ' checked' : '') + '> Show cost in Host Flow</label>'
				+ '<div class="hf-cards" style="margin-top:10px">'
				+ '<div>' + field('Visible to', '<select data-k="cost.visible_to">' + opt('everyone', c.visible_to, 'Everyone with access') + opt('admins', c.visible_to, 'Admin and Super admin') + opt('superadmins', c.visible_to, 'Super admin only') + '</select>')
				+ field('Pricing basis', '<select data-k="cost.pricing_basis">' + opt('on_demand', c.pricing_basis, 'On-Demand') + opt('reserved', c.pricing_basis, 'Reserved (1 yr, no upfront)') + opt('savings_plan', c.pricing_basis, 'Savings Plan') + '</select>')
				+ field('Default region', '<input type="text" data-k="cost.default_region" value="' + esc(c.default_region) + '">')
				+ field('Hours per month', '<input type="number" data-k="cost.hours_per_month" value="' + c.hours_per_month + '">')
				+ field('Public IPv4, $ per hour', '<input type="number" step="0.001" data-k="cost.ipv4_hourly" value="' + c.ipv4_hourly + '">')
				+ '<label><input type="checkbox" data-k="cost.count_disabled"' + (c.count_disabled ? ' checked' : '') + '> Count disabled hosts</label><br>'
				+ '<label><input type="checkbox" data-k="cost.pricing_api"' + (c.pricing_api ? ' checked' : '') + '> Fetch prices from the AWS Pricing API daily</label></div>'
				+ '<div>' + field('Exclude hosts (names, comma separated)', '<input type="text" data-k="cost.excludes.hosts" data-list value="' + esc(list(c.excludes.hosts)) + '">')
				+ field('Exclude host groups', '<input type="text" data-k="cost.excludes.groups" data-list value="' + esc(list(c.excludes.groups)) + '">')
				+ field('Exclude tags (tag=value)', '<input type="text" data-k="cost.excludes.tags" data-tags value="' + esc(tagsToText(c.excludes.tags)) + '">')
				+ field('Network-out item key (for data transfer)', '<input type="text" data-k="cost.transfer.item_key" value="' + esc(c.transfer.item_key) + '">')
				+ field('Traffic share: internet / cross-AZ / cross-region', ['internet_share', 'cross_az_share', 'cross_region_share'].map((k) => '<input type="number" step="0.05" min="0" max="1" data-k="cost.transfer.' + k + '" value="' + c.transfer[k] + '" style="width:30%">').join(' '))
				+ field('Rate $/GB: internet / cross-AZ / cross-region', ['internet_rate', 'cross_az_rate', 'cross_region_rate'].map((k) => '<input type="number" step="0.001" min="0" data-k="cost.transfer.' + k + '" value="' + c.transfer[k] + '" style="width:30%">').join(' ')) + '</div>'
				+ '</div>'
				+ '<h4 style="margin:12px 0 6px">Price rules</h4><p class="hf-muted">Component rules apply first, then host group, then host. A more specific rule wins field by field. Leave a field empty to keep the price from the AWS Pricing API.</p>'
				+ '<div style="overflow:auto"><table class="hf-table hf-grid-table"><thead><tr><th>Scope</th><th>Target</th><th>Region</th><th>Instance type</th><th>Basis</th><th>$/hour</th><th>$/month</th>'
				+ '<th>EBS GB</th><th>EBS type</th><th>$/GB-mo</th><th>IOPS</th><th>Snapshot GB</th><th>Public IPs</th><th>Extras $/mo</th><th></th></tr></thead><tbody>'
				+ c.rules.map((r, i) => '<tr data-rule="' + i + '"><td><select data-f="scope">' + ['component', 'group', 'host'].map((s) => opt(s, r.scope)).join('') + '</select></td>'
					+ ['target', 'region', 'instance_type'].map((k) => '<td><input type="text" data-f="' + k + '" value="' + esc(r[k] || '') + '"></td>').join('')
					+ '<td><select data-f="basis">' + opt('', r.basis, 'default') + opt('on_demand', r.basis, 'On-Demand') + opt('reserved', r.basis, 'Reserved') + opt('savings_plan', r.basis, 'Savings Plan') + '</select></td>'
					+ ['hourly', 'monthly', 'ebs_gb'].map((k) => '<td><input type="number" step="any" min="0" data-f="' + k + '" value="' + (r[k] == null ? '' : r[k]) + '"></td>').join('')
					+ '<td><select data-f="ebs_type">' + ['gp3', 'gp2', 'io2', 'io1', 'st1', 'sc1'].map((t) => opt(t, r.ebs_type)).join('') + '</select></td>'
					+ ['ebs_rate', 'iops', 'snapshot_gb', 'public_ipv4', 'extras'].map((k) => '<td><input type="number" step="any" min="0" data-f="' + k + '" value="' + (r[k] == null ? '' : r[k]) + '"></td>').join('')
					+ '<td><button type="button" class="btn-link" data-del-rule="' + i + '">Remove</button></td></tr>').join('')
				+ '</tbody></table></div><button type="button" class="btn-alt" id="hf-add-rule">Add rule</button>'
				+ '<h4 style="margin:12px 0 6px">Cost-only items</h4><p class="hf-muted">AWS resources that are not Zabbix hosts: VPN connections, gateways, S3 buckets. They appear as dashed shapes and count towards their tier.</p>'
				+ '<table class="hf-table hf-grid-table"><thead><tr><th>Type</th><th>Name</th><th>Client</th><th>Component</th><th>AWS resource id</th><th>$/month (empty = from AWS)</th><th></th></tr></thead><tbody>'
				+ c.cost_only.map((x, i) => '<tr data-only="' + i + '"><td><select data-f="type">' + ['vpn_connection', 'vpn_gateway', 'tgw_attachment', 'client_vpn', 's3_bucket', 'other'].map((t) => opt(t, x.type, t.replace(/_/g, ' '))).join('') + '</select></td>'
					+ ['name', 'client'].map((k) => '<td><input type="text" data-f="' + k + '" value="' + esc(x[k]) + '"></td>').join('')
					+ '<td><select data-f="component">' + S.components.map((cc) => opt(cc.id, x.component, cc.label)).join('') + '</select></td>'
					+ '<td><input type="text" data-f="resource_id" value="' + esc(x.resource_id) + '"></td>'
					+ '<td><input type="number" step="any" min="0" data-f="monthly" value="' + (x.monthly == null ? '' : x.monthly) + '"></td>'
					+ '<td><button type="button" class="btn-link" data-del-only="' + i + '">Remove</button></td></tr>').join('')
				+ '</tbody></table><button type="button" class="btn-alt" id="hf-add-only">Add cost-only item</button>')

			+ card('AWS', '<label><input type="checkbox" data-k="aws.enabled"' + (a.enabled ? ' checked' : '') + '> Match hosts to EC2 instances and read billed cost (once a day)</label>'
				+ '<div class="hf-cards" style="margin-top:10px"><div>'
				+ field('Billed cost source', '<select data-k="aws.source">' + opt('cur', a.source, 'Cost and Usage Report (CSV.gz in S3)') + opt('cost_explorer', a.source, 'Cost Explorer (last 14 days, resource level)') + '</select>')
				+ field('CUR bucket', '<input type="text" data-k="aws.cur.bucket" value="' + esc(a.cur.bucket) + '">')
				+ field('CUR prefix', '<input type="text" data-k="aws.cur.prefix" value="' + esc(a.cur.prefix) + '">')
				+ field('CUR bucket region', '<input type="text" data-k="aws.cur.region" value="' + esc(a.cur.region) + '">')
				+ field('Account that can read the CUR bucket (empty = first account)', '<input type="text" data-k="aws.cur.account" value="' + esc(a.cur.account) + '">')
				+ field('S3 bucket tag that names the client', '<input type="text" data-k="aws.bucket_client_tag" value="' + esc(a.bucket_client_tag) + '">')
				+ '</div><div><p class="hf-muted">Matching is by host IP within the accounts, regions and VPCs below. An IP that matches several instances is listed for you, never guessed. A host tag <code>aws-instance-id</code> overrides the IP match.</p>'
				+ '<p class="hf-muted">Read-only permissions needed: ec2:DescribeInstances, ec2:DescribeVolumes, pricing:GetProducts, s3:ListAllMyBuckets, s3:GetBucketLocation, s3:GetBucketTagging, s3:ListBucket and s3:GetObject (CUR bucket), cloudwatch:GetMetricStatistics, and ce:GetCostAndUsageWithResources for Cost Explorer.</p>'
				+ '<button type="button" class="btn-alt" id="hf-sync">Run cost refresh now</button><div class="hf-status" id="hf-sync-status" role="status"></div></div></div>'
				+ '<h4 style="margin:12px 0 6px">Accounts</h4>'
				+ '<div style="overflow:auto"><table class="hf-table hf-grid-table"><thead><tr><th>Account id</th><th>Name</th><th>Access</th><th>Role ARN</th><th>External id</th><th>Access key id</th><th>Secret key</th><th>Regions</th><th>VPCs (optional)</th><th></th></tr></thead><tbody>'
				+ a.accounts.map((x, i) => '<tr data-acc="' + i + '"><td><input type="text" data-f="id" value="' + esc(x.id) + '" style="width:120px"></td>'
					+ '<td><input type="text" data-f="name" value="' + esc(x.name) + '"></td>'
					+ '<td><select data-f="auth">' + opt('instance', x.auth, 'Instance role') + opt('assume', x.auth, 'AssumeRole') + opt('key', x.auth, 'Access key') + '</select></td>'
					+ '<td><input type="text" data-f="role_arn" value="' + esc(x.role_arn) + '"></td>'
					+ '<td><input type="text" data-f="external_id" value="' + esc(x.external_id) + '"></td>'
					+ '<td><input type="text" data-f="key_id" value="' + esc(x.key_id) + '"></td>'
					+ '<td><input type="password" data-f="secret_key" autocomplete="new-password" placeholder="' + (x.has_secret ? 'stored (leave empty to keep)' : '') + '"></td>'
					+ '<td><input type="text" data-f="regions" value="' + esc(list(x.regions)) + '"></td>'
					+ '<td><input type="text" data-f="vpcs" value="' + esc(list(x.vpcs)) + '"></td>'
					+ '<td class="hf-row-actions"><button type="button" class="btn-link" data-test="' + esc(x.id) + '">Test</button> <button type="button" class="btn-link" data-del-acc="' + i + '">Remove</button></td></tr>').join('')
				+ '</tbody></table></div><button type="button" class="btn-alt" id="hf-add-acc">Add account</button><div class="hf-status" id="hf-acc-status" role="status"></div>'
				+ '<p class="hf-muted">Access keys are stored encrypted (AES-256-GCM, key in the data directory), never shown again, and never sent to the browser.</p>');
		bind();
	}

	const card = (title, body) => '<div class="hf-card" style="margin-bottom:12px"><h3>' + esc(title) + '</h3>' + body + '</div>';
	const field = (label, input) => '<div class="hf-field"><label>' + esc(label) + '</label>' + input + '</div>';

	function setPath(obj, path, val) {
		const ks = path.split('.');
		let o = obj;
		ks.slice(0, -1).forEach((k) => { o = o[k]; });
		o[ks[ks.length - 1]] = val;
	}

	function bind() {
		document.querySelectorAll('[data-k]').forEach((el) => el.addEventListener('change', () => {
			let v = el.type === 'checkbox' ? el.checked : el.value;
			if (el.hasAttribute('data-list')) v = split(v);
			if (el.hasAttribute('data-tags')) v = textToTags(v);
			if (el.type === 'number') v = el.value === '' ? null : +el.value;
			setPath(S, el.dataset.k, v);
			markDirty();
		}));
		const rows = (sel, arr, fix) => document.querySelectorAll(sel).forEach((tr) => tr.querySelectorAll('[data-f]').forEach((el) => el.addEventListener('change', () => {
			const i = +Object.values(tr.dataset)[0];
			fix(arr()[i], el.dataset.f, el.type === 'checkbox' ? el.checked : el.type === 'number' ? (el.value === '' ? null : +el.value) : el.value);
			markDirty();
		})));
		rows('[data-comp]', () => S.components, (x, f, v) => {
			if (f === 'groups' || f === 'templates') x.match[f] = split(v);
			else if (f === 'tags') x.match.tags = textToTags(v);
			else x[f] = v;
			if (['icon', 'color', 'small', 'kind', 'label'].includes(f)) render();
		});
		rows('[data-tedge]', () => tpl().edges, (x, f, v) => { x[f] = v; render(); });
		document.querySelectorAll('[data-t]').forEach((el) => el.addEventListener('change', () => {
			const t = tpl(), v = el.value.trim();
			if (el.dataset.t === 'id' && S.default_template === t.id) S.default_template = v;
			t[el.dataset.t] = v;
			markDirty(); render();
		}));
		document.querySelectorAll('[data-tier]').forEach((el) => el.addEventListener('change', () => {
			const t = tpl(), id = el.dataset.tier;
			t.tiers = el.checked ? S.components.map((c) => c.id).filter((c) => c === id || t.tiers.includes(c)) : t.tiers.filter((c) => c !== id);
			if (!el.checked) t.edges = t.edges.filter((e) => e.from !== id && e.to !== id);
			markDirty(); render();
		}));
		document.querySelectorAll('[data-queue]').forEach((el) => el.addEventListener('change', () => {
			const t = tpl();
			if (el.value) t.queues[el.dataset.queue] = el.value; else delete t.queues[el.dataset.queue];
			markDirty(); render();
		}));
		document.querySelectorAll('[data-note]').forEach((el) => el.addEventListener('change', () => {
			const t = tpl(), v = el.value.trim();
			if (v) t.notes[el.dataset.note] = v; else delete t.notes[el.dataset.note];
			markDirty(); render();
		}));
		rows('[data-rule]', () => S.cost.rules, (x, f, v) => { x[f] = v; });
		rows('[data-only]', () => S.cost.cost_only, (x, f, v) => { x[f] = v; });
		rows('[data-acc]', () => S.aws.accounts, (x, f, v) => { x[f] = (f === 'regions' || f === 'vpcs') ? split(v) : v; });

		const click = (sel, fn) => document.querySelectorAll(sel).forEach((b) => b.addEventListener('click', () => { fn(b); markDirty(); render(); }));
		click('[data-del-comp]', (b) => S.components.splice(+b.dataset.delComp, 1));
		click('[data-up]', (b) => { const i = +b.dataset.up; if (i > 0) S.components.splice(i - 1, 0, S.components.splice(i, 1)[0]); });
		click('[data-del-tedge]', (b) => tpl().edges.splice(+b.dataset.delTedge, 1));
		document.querySelectorAll('[data-tsel]').forEach((b) => b.addEventListener('click', () => { tsel = +b.dataset.tsel; render(); }));
		const lbl = $('#hf-tpl-labels');
		if (lbl) lbl.addEventListener('change', () => {
			showLabels = lbl.checked;
			try { localStorage.setItem('hostflow.tplLabels', showLabels ? '1' : '0'); } catch (x) { /* per browser only */ }
			$('.hf-tpl-preview-box').innerHTML = tplPreview(tpl());
		});
		click('#hf-tpl-add', () => { S.flow_templates.push({id: 'template' + (S.flow_templates.length + 1), name: 'New template', tiers: [], notes: {}, edges: []}); tsel = S.flow_templates.length - 1; });
		click('#hf-tpl-dup', () => { const c = JSON.parse(JSON.stringify(tpl())); c.id += '-copy'; c.name += ' (copy)'; S.flow_templates.push(c); tsel = S.flow_templates.length - 1; });
		click('#hf-tpl-default', () => { S.default_template = tpl().id; });
		click('#hf-tpl-del', () => {
			const gone = S.flow_templates.splice(tsel, 1)[0];
			if (S.default_template === gone.id) S.default_template = S.flow_templates[0].id;
			tsel = 0;
		});
		click('[data-del-rule]', (b) => S.cost.rules.splice(+b.dataset.delRule, 1));
		click('[data-del-only]', (b) => S.cost.cost_only.splice(+b.dataset.delOnly, 1));
		click('[data-del-acc]', (b) => S.aws.accounts.splice(+b.dataset.delAcc, 1));
		click('#hf-add-comp', () => S.components.push({id: 'tier' + (S.components.length + 1), label: 'New tier', icon: 'generic', color: 'neutral', kind: 'hosts', column: 0, match: {groups: [], tags: [], templates: []}}));
		click('#hf-add-tedge', () => {
			const t = tpl(), first = t.tiers[0] || S.components[0].id;
			t.edges.push({from: first, to: t.tiers[1] || first, label: '', item_key: '', drop_pct: 0});
		});
		click('#hf-add-rule', () => S.cost.rules.push({scope: 'component', target: '', region: '', instance_type: '', basis: '', hourly: null, monthly: null, ebs_gb: 0, ebs_type: 'gp3', ebs_rate: null, iops: 0, snapshot_gb: 0, public_ipv4: 0, extras: 0}));
		click('#hf-add-only', () => S.cost.cost_only.push({id: '', type: 'vpn_connection', name: '', client: '', component: 'vpn', resource_id: '', monthly: null}));
		click('#hf-add-acc', () => S.aws.accounts.push({id: '', name: '', auth: 'instance', role_arn: '', external_id: '', key_id: '', regions: [S.cost.default_region], vpcs: []}));

		document.querySelectorAll('[data-test]').forEach((b) => b.addEventListener('click', async () => {
			const st = $('#hf-acc-status');
			if (dirty) { st.className = 'hf-status err'; st.textContent = 'Save settings first, then test.'; return; }
			st.className = 'hf-status'; st.textContent = 'Testing ' + b.dataset.test + '…';
			try { const r = await HF.post('hostflow.aws.test', {account: b.dataset.test}); st.className = 'hf-status ok'; st.textContent = r.message; }
			catch (e) { st.className = 'hf-status err'; st.textContent = e.message; }
		}));
		const script = $('#hf-script');
		if (script) script.addEventListener('click', async () => {
			try { await HF.post('hostflow.script.install', {}); HF.scriptInstalled = true; HF.say('“Open in Host Flow” is now in the host menu.', true); render(); }
			catch (e) { HF.say(e.message, false); }
		});
		$('#hf-sync').addEventListener('click', async () => {
			const st = $('#hf-sync-status');
			st.className = 'hf-status'; st.textContent = 'Refreshing (this can take a minute)…';
			try {
				const r = (await HF.post('hostflow.cost.refresh', {})).report;
				st.className = 'hf-status ' + (r.errors.length ? 'err' : 'ok');
				st.innerHTML = Object.entries(r.steps).map(([k, v]) => '<div>' + (v.ok ? '✓' : '✗') + ' ' + esc(k) + ': ' + esc(String(v.detail)) + '</div>').join('');
			}
			catch (e) { st.className = 'hf-status err'; st.textContent = e.message; }
		});
	}

	$('#hf-save-settings').addEventListener('click', async () => {
		try {
			const r = await HF.post('hostflow.settings.update', {settings: S});
			Object.assign(S, r.settings);
			dirty = false;
			$('#hf-dirty').textContent = 'Saved';
			const f = r.flows || {updated: [], created: []};
			HF.say('Settings saved.' + (f.updated.length ? ' Client flows redrawn: ' + f.updated.join(', ') + '.' : '')
				+ (f.created.length ? ' New client flows: ' + f.created.join(', ') + '.' : ''), true);
			render();
		}
		catch (e) { HF.say(e.message, false); }
	});
	window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
	render();
})();
