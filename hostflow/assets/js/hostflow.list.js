/* Main page, left panel: find a host or IP on any flow, new flow, import a Zabbix map, links. */
(function () {
	'use strict';
	const HF = window.HF, esc = HF.esc;
	const $ = (s) => document.querySelector(s);

	const LAYOUT_NAMES = {client: 'Client flow (pipeline + client hierarchy)', hierarchy: 'Client hierarchy (client → host groups → hosts)', dataflow: 'Data flow (your pipeline)',
		monitoring: 'Monitoring path (server → proxies → hosts)', combined: 'Combined (data flow + monitoring path)'};

	const find = HF.debounce(async (q) => {
		const out = $('#hf-find-results');
		if (q.trim().length < 2) { out.innerHTML = ''; return; }
		try {
			const d = await HF.get('hostflow.find', {q});
			out.innerHTML = d.hosts.length
				? d.hosts.slice(0, 30).map((h) => '<div class="hf-hit"><b>' + esc(h.name) + '</b>' + (h.disabled ? ' <span class="hf-badge mute">disabled</span>' : '')
					+ ' <span class="hf-mono hf-muted">' + esc(h.ip) + '</span><div>'
					+ (h.diagrams.length ? h.diagrams.map((g) => '<a href="' + esc(HF.url('hostflow.view', {id: g.id, focus: h.hostid})) + '" data-flow="' + esc(g.id)
						+ '" data-focus="' + esc(h.hostid) + '">' + esc(g.name) + '</a>').join(' · ') : '<span class="hf-muted">not on any flow</span>')
					+ '</div></div>').join('')
				: '<p class="hf-muted">No host matches “' + esc(q) + '”.</p>';
			out.querySelectorAll('a[data-flow]').forEach((a) => a.addEventListener('click', (e) => {
				if (!HF.selectFlow) return;
				e.preventDefault();
				HF.selectFlow(a.dataset.flow, a.dataset.focus);
			}));
		}
		catch (e) { out.innerHTML = '<p class="hf-status err">' + esc(e.message) + '</p>'; }
	}, 300);

	function newFlow() {
		const dlg = HF.dialog('New flow',
			'<div class="hf-field"><label for="nd-client">Client group</label><select id="nd-client"><option value="">Loading…</option></select></div>'
			+ '<div class="hf-field"><label for="nd-layout">Layout</label><select id="nd-layout">'
			+ HF.layouts.map((l) => '<option value="' + l + '">' + esc(LAYOUT_NAMES[l] || l) + '</option>').join('') + '</select></div>'
			+ '<p class="hf-muted">The flow is generated from Zabbix and opened in the editor.</p>'
			+ '<p class="hf-muted">Or <a href="' + esc(HF.url('hostflow.edit')) + '">start with an empty diagram</a>.</p>',
			(root) => {
				const client = root.querySelector('#nd-client').value;
				if (!client) throw new Error('Choose a client group. If the list is empty, check Settings → Client groups.');
				window.location.href = HF.url('hostflow.edit', {client, layout: root.querySelector('#nd-layout').value});
				return true;
			}, 'Generate');
		HF.get('hostflow.clients').then((d) => {
			dlg.querySelector('#nd-client').innerHTML = d.clients.length
				? d.clients.map((c) => '<option>' + esc(c.name) + '</option>').join('')
				: '<option value="">No client groups found</option>';
		}).catch((e) => { dlg.querySelector('.hf-status').textContent = e.message; });
	}

	function importMap() {
		const dlg = HF.dialog('Import a Zabbix map',
			'<div class="hf-field"><label for="im-map">Map</label><select id="im-map"><option value="">Loading…</option></select></div>'
			+ '<p class="hf-muted">Hosts on the map stay linked and live. Images become plain shapes you can restyle.</p>',
			(root) => {
				const id = root.querySelector('#im-map').value;
				if (!id) throw new Error('Choose a map.');
				window.location.href = HF.url('hostflow.edit', {sysmapid: id});
				return true;
			}, 'Open in editor');
		HF.post('hostflow.mapimport', {}).then((d) => {
			dlg.querySelector('#im-map').innerHTML = d.maps.length
				? d.maps.map((m) => '<option value="' + esc(m.sysmapid) + '">' + esc(m.name) + '</option>').join('')
				: '<option value="">No maps you can read</option>';
		}).catch((e) => { dlg.querySelector('.hf-status').textContent = e.message; });
	}

	const on = (id, fn) => { const el = document.getElementById(id); if (el) el.addEventListener('click', fn); };
	on('hf-new', newFlow);
	on('hf-import', importMap);
	on('hf-wall', () => { window.location.href = HF.url('hostflow.wall'); });
	on('hf-cost', () => { window.location.href = HF.url('hostflow.cost'); });
	on('hf-settings', () => { window.location.href = HF.url('hostflow.settings'); });
	const q = $('#hf-find');
	if (q) {
		q.addEventListener('input', (e) => find(e.target.value));
		if (q.value) find(q.value);
	}
	if (HF.hostid && $('#hf-find-results')) {
		$('#hf-find-results').innerHTML = '<p class="hf-muted">This host is on more than one flow, or on none. Search for it above.</p>';
	}
})();
