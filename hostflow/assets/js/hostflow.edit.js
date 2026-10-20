/* Editor page: bundled draw.io in embed mode + Zabbix side panel. */
(function () {
	'use strict';
	const HF = window.HF, esc = HF.esc;
	const $ = (s) => document.querySelector(s);
	const frame = $('#hf-frame');

	let meta = HF.meta;            // null for a new diagram
	let xmlNow = '';               // latest XML from draw.io (autosave events)
	let dirty = false;
	let ui = null;                 // draw.io EditorUi (same origin, via its plugin hook)
	let graph = null;
	let loaded = false;
	let original = null;           // what the editor opened with, for Discard
	let savedHere = false;         // something was saved since the editor opened
	let saving = false, again = false, changes = 0, autoTimer = null, quiet = false, autoBlocked = false, savedAt = 0;
	let autosave = true;
	let refreshFlow = false;       // Generate/Update ran: the next save takes the client's current flow template
	try { autosave = localStorage.getItem('hostflow.autosave') !== '0'; } catch (x) { /* default on */ }

	const status = (id, msg, ok) => { const el = document.getElementById(id); if (el) { el.className = 'hf-status' + (ok === true ? ' ok' : ok === false ? ' err' : ''); el.textContent = msg || ''; } };
	const post = (msg) => frame.contentWindow.postMessage(JSON.stringify(msg), window.location.origin);

	// ------------------------------------------------------------------ draw.io embed protocol

	window.addEventListener('message', async (e) => {
		if (e.source !== frame.contentWindow || e.origin !== window.location.origin || typeof e.data !== 'string') return;
		let msg;
		try { msg = JSON.parse(e.data); } catch (x) { return; }

		if (msg.event === 'configure') {
			post({action: 'configure', config: {compressXml: false, defaultLibraries: 'general;basic;arrows2;networking;aws4', sidebarTitles: true}});
		}
		else if (msg.event === 'init') {
			try {
				const xml = await initialXml();
				original = {xml, existed: !!meta, auto: !!(meta && meta.auto), name: $('#hf-name').value, client: $('#hf-client').value,
					acl: Array.from($('#hf-acl').selectedOptions).map((o) => o.value)};
				quiet = true;   // loading the diagram is not a change; released once the panel is attached
				post({action: 'load', xml, autosave: 1, title: meta ? meta.name : ($('#hf-name').value || 'New diagram'), noExitBtn: 1});
				xmlNow = xml;
				loaded = true;
				if (dirty) queueAuto();
				attachPlugin();
			}
			catch (err) {
				post({action: 'load', xml: emptyXml(), autosave: 1});
				HF.say(err.message, false);
			}
		}
		else if (msg.event === 'autosave') {
			xmlNow = msg.xml;
			if (loaded) setDirty(true);
		}
		else if (msg.event === 'save') {
			xmlNow = msg.xml;
			save(false);
		}
	});

	function emptyXml() {
		return '<mxfile host="hostflow"><diagram id="page-1" name="Page-1"><mxGraphModel grid="1" gridSize="10" page="0"><root>'
			+ '<mxCell id="0"/><mxCell id="1" parent="0"/></root></mxGraphModel></diagram></mxfile>';
	}

	async function initialXml() {
		if (meta) return (await HF.get('hostflow.load', {id: meta.id})).xml || emptyXml();
		if (HF.sysmapid) {
			const r = await HF.post('hostflow.mapimport', {sysmapid: HF.sysmapid});
			if (!$('#hf-name').value) $('#hf-name').value = r.name;
			setDirty(true);
			return r.xml;
		}
		if (HF.client) {
			const r = await HF.post('hostflow.generate', {client: HF.client, layout: $('#hf-layout').value});
			if (!$('#hf-name').value) $('#hf-name').value = HF.client + ' · ' + $('#hf-layout').selectedOptions[0].textContent;
			setDirty(true);
			return r.xml;
		}
		return emptyXml();
	}

	function setDirty(d) {
		if (d && quiet) return;
		dirty = d;
		if (d) { changes++; queueAuto(); }
		showStatus();
	}

	function showStatus(text, bad) {
		const el = $('#hf-save-status');
		el.classList.toggle('hf-status-err', !!bad);
		el.textContent = text != null ? text
			: dirty ? (autosave && !autoBlocked ? 'Changes not saved yet' : 'Unsaved changes')
				: meta ? 'All changes saved' + (savedAt ? ' · ' + new Date(savedAt).toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'}) : '')
					: 'Not saved yet';
		$('#hf-save').disabled = saving || (!dirty && !!meta);
		$('#hf-discard').disabled = !dirty && !savedHere;
	}

	function queueAuto() {
		clearTimeout(autoTimer);
		if (!autosave || autoBlocked || !loaded) return;
		autoTimer = setTimeout(() => { if (dirty) save(false, true); }, 2500);
	}

	window.addEventListener('beforeunload', (e) => { if (dirty || saving) { e.preventDefault(); e.returnValue = ''; } });

	// ------------------------------------------------------------------ save

	function currentXml() {
		if (ui) {
			try { return ui.getFileData(true, null, null, null, true, false, null, null, null, true); }
			catch (x) { /* fall back to the last autosave */ }
		}
		return xmlNow;
	}

	/**
	 * Save the diagram (one file, overwritten). auto: started by auto-save, so no pop-up messages.
	 * over: values to save instead of the editor's current ones (Discard puts the original back).
	 */
	async function save(asCopy, auto, over) {
		if (saving) { again = true; return false; }
		clearTimeout(autoTimer);
		const name = (over ? over.name : $('#hf-name').value).trim();
		if (!name) {
			if (auto) { showStatus('Name the diagram to save it', true); return false; }
			HF.say('Give the diagram a name first.', false); $('#hf-name').focus(); return false;
		}
		const body = {xml: over ? over.xml : currentXml(), name, client: (over ? over.client : $('#hf-client').value).trim(), layout: $('#hf-layout').value,
			acl: over ? over.acl : Array.from($('#hf-acl').selectedOptions).map((o) => o.value)};
		if (meta && !asCopy) { body.id = meta.id; body.revision = meta.revision; }
		if (asCopy) body.as_copy = '1';
		if (over && over.auto) body.keep_auto = '1';
		if (refreshFlow && !over) body.refresh_flow = '1';
		const seq = changes;
		let failed = false;
		saving = true;
		showStatus('Saving…');
		try {
			const r = await HF.post('hostflow.save', body);
			if (asCopy) { dirty = false; window.location.href = HF.url('hostflow.edit', {id: r.meta.id}); return true; }
			meta = r.meta;
			if (!over) refreshFlow = false;
			savedHere = true;
			savedAt = Date.now();
			dirty = changes !== seq;
			const u = new URL(window.location.href);
			['client', 'layout', 'sysmapid'].forEach((k) => u.searchParams.delete(k));
			u.searchParams.set('id', meta.id);
			window.history.replaceState(null, '', u.toString());
			if (!auto && !over) HF.say('Saved.', true);
			return true;
		}
		catch (err) {
			dirty = true;
			if (err.data && err.data.conflict) {
				autoBlocked = true;
				HF.dialog('Someone else saved first', '<p>' + esc(err.message) + '</p><p class="hf-muted">Auto-save is paused. Reload to see their version (your changes are lost), or keep yours as a separate copy.</p>',
					() => { save(true); return true; }, 'Save mine as a copy')
					.querySelector('.acts').insertAdjacentHTML('afterbegin', '<button type="button" class="btn-alt" onclick="location.reload()">Reload theirs</button>');
			}
			else if (auto) {
				failed = true;
				showStatus('Auto-save failed: ' + err.message, true);
			}
			else {
				HF.say(err.message, false);
			}
			return false;
		}
		finally {
			saving = false;
			if (!failed) showStatus();
			if (again) { again = false; queueAuto(); }
			else if (dirty) queueAuto();
		}
	}

	// Discard: back to what the editor opened with. If auto-save already wrote changes, the original is saved again.
	function discard() {
		if (!original || (!dirty && !savedHere)) return;
		const fresh = !original.existed;
		HF.dialog(fresh ? 'Discard this new diagram?' : 'Discard your changes?',
			fresh ? '<p>The diagram is not kept' + (savedHere ? ' (auto-save had already stored it; it is removed)' : '') + '.</p>'
				: '<p>Everything you changed since you opened the editor is undone' + (savedHere ? ', including changes auto-save already stored' : '') + '.</p>',
			async () => {
				clearTimeout(autoTimer);
				if (fresh) {
					if (savedHere && meta) await HF.post('hostflow.delete', {id: meta.id});
					dirty = false;
					window.location.href = HF.url('hostflow.list');
					return true;
				}
				quiet = true;
				$('#hf-name').value = original.name;
				$('#hf-client').value = original.client;
				Array.from($('#hf-acl').options).forEach((o) => { o.selected = original.acl.includes(o.value); });
				post({action: 'load', xml: original.xml, autosave: 1});
				xmlNow = original.xml;
				setTimeout(() => attachPlugin(), 300);
				if (savedHere && !(await save(false, true, original))) { quiet = false; throw new Error('Could not put the original back. Try again.'); }
				setTimeout(() => { quiet = false; dirty = false; savedHere = false; showStatus(); }, 900);
				HF.say('Changes discarded.', true);
				return true;
			}, 'Discard');
	}

	async function close() {
		const target = meta ? HF.url('hostflow.view', {id: meta.id}) : HF.url('hostflow.list');
		if (!dirty) { window.location.href = target; return; }
		if (autosave && !autoBlocked) {
			if (await save(false, true)) { window.location.href = target; }
			return;
		}
		HF.dialog('Save your changes?', '<p>You have changes that are not saved.</p>', async () => {
			if (await save(false)) window.location.href = target;
			return true;
		}, 'Save and close').querySelector('.acts').insertAdjacentHTML('afterbegin', '<button type="button" class="btn-alt" data-leave>Close without saving</button>');
		document.querySelector('[data-leave]').addEventListener('click', () => { dirty = false; window.location.href = target; });
	}

	$('#hf-save').addEventListener('click', () => save(false));
	$('#hf-discard').addEventListener('click', discard);
	$('#hf-close').addEventListener('click', close);
	$('#hf-autosave').checked = autosave;
	$('#hf-autosave').addEventListener('change', (e) => {
		autosave = e.target.checked;
		try { localStorage.setItem('hostflow.autosave', autosave ? '1' : '0'); } catch (x) { /* per browser only */ }
		if (autosave) { autoBlocked = false; if (dirty) queueAuto(); }
		else clearTimeout(autoTimer);
		showStatus();
	});
	if ($('#hf-copy')) $('#hf-copy').addEventListener('click', () => save(true));
	['#hf-name', '#hf-client', '#hf-acl'].forEach((s) => $(s).addEventListener('change', () => setDirty(true)));

	// The editor fills the window under the toolbar.
	function fitHeight() {
		const ed = document.querySelector('.hf-editor');
		const foot = document.querySelector('footer');
		if (ed && window.innerWidth > 1000) ed.style.height = Math.max(480, window.innerHeight - ed.getBoundingClientRect().top - (foot ? foot.offsetHeight : 0) - 8) + 'px';
	}
	fitHeight();
	window.addEventListener('resize', HF.debounce(fitHeight, 100));

	// ------------------------------------------------------------------ tabs

	document.querySelectorAll('.hf-tabs button').forEach((b) => b.addEventListener('click', () => {
		document.querySelectorAll('.hf-tabs button').forEach((x) => x.setAttribute('aria-selected', String(x === b)));
		document.querySelectorAll('.hf-pane').forEach((p) => p.classList.toggle('on', p.dataset.pane === b.dataset.tab));
	}));

	// ------------------------------------------------------------------ draw.io plugin hook (same origin)

	function attachPlugin(tries) {
		tries = tries || 0;
		const w = frame.contentWindow;
		if (w && w.Draw && typeof w.Draw.loadPlugin === 'function') {
			w.Draw.loadPlugin((editorUi) => {
				ui = editorUi;
				graph = ui.editor.graph;
				graph.getSelectionModel().addListener(w.mxEvent.CHANGE, () => showSelection());
				graph.getModel().addListener(w.mxEvent.CHANGE, () => setDirty(true));
				showSelection();
				if (HF.focus) focusHost(HF.focus);
				setTimeout(() => { quiet = false; }, 1200);
			});
			return;
		}
		if (tries < 50) setTimeout(() => attachPlugin(tries + 1), 200);
		else {
			quiet = false;
			$('#hf-sel').textContent = 'The Zabbix panel could not attach to the editor. You can still draw and save.';
		}
	}

	const selected = () => (graph ? graph.getSelectionCell() : null);
	const attr = (cell, k) => (cell && frame.contentWindow.mxUtils.isNode(cell.value) ? cell.value.getAttribute(k) : null);

	function setAttrs(cell, attrs) {
		const w = frame.contentWindow;
		let v = cell.value;
		if (!w.mxUtils.isNode(v)) {
			const obj = w.mxUtils.createXmlDocument().createElement('object');
			obj.setAttribute('label', v == null ? '' : String(v));
			v = obj;
		}
		else {
			v = v.cloneNode(true);
		}
		Object.entries(attrs).forEach(([k, val]) => { if (val === null || val === undefined) v.removeAttribute(k); else v.setAttribute(k, String(val)); });
		graph.getModel().setValue(cell, v);
	}

	function showSelection() {
		const cell = selected();
		const box = $('#hf-sel');
		$('#hf-link-host').hidden = !cell || cell.edge;
		$('#hf-link-note').hidden = !cell;
		$('#hf-link-item').hidden = !cell || !cell.edge;
		status('hf-link-status', '');
		if (!cell) { box.textContent = 'Select a shape or an arrow in the diagram.'; return; }
		if (cell.edge) {
			const it = attr(cell, 'zbx_itemid');
			box.innerHTML = '<b>Arrow</b><br>' + (it ? 'Shows item #' + esc(it) : '<span class="hf-muted">No value linked.</span>');
			$('#hf-drop').value = attr(cell, 'zbx_drop_pct') || 20;
		}
		else {
			const hid = attr(cell, 'zbx_hostid');
			box.innerHTML = '<b>' + esc(attr(cell, 'name') || graph.convertValueToString(cell).replace(/<[^>]+>/g, ' ').slice(0, 60) || 'Shape') + '</b><br>'
				+ (hid ? 'Linked to host #' + esc(hid) + (attr(cell, 'ip') ? ' · <span class="hf-mono">' + esc(attr(cell, 'ip')) + '</span>' : '')
					: '<span class="hf-muted">Not linked to a host.</span>');
			$('#hf-unlink').disabled = !hid;
		}
		$('#hf-note').value = attr(cell, 'zbx_note') || '';
		$('#hf-runbook').value = attr(cell, 'zbx_runbook') || '';
	}

	function focusHost(hostid) {
		const cells = graph.getModel().getDescendants(graph.getDefaultParent()).filter((c) => attr(c, 'zbx_hostid') === String(hostid));
		if (cells.length) { graph.setSelectionCells(cells); graph.scrollCellToVisible(cells[0], true); }
	}

	// Link to host
	const searchHosts = HF.debounce(async (q, target, pick) => {
		const box = $(target);
		if (q.trim().length < 2) { box.innerHTML = ''; return; }
		try {
			const d = await HF.get('hostflow.hosts', {q});
			box.innerHTML = d.hosts.length ? d.hosts.map((h, i) => '<div class="r" data-i="' + i + '" tabindex="0"><b>' + esc(h.name) + '</b>'
				+ (h.disabled ? ' <span class="hf-badge mute">disabled</span>' : '')
				+ '<small class="hf-mono">' + esc(h.ip) + (h.component ? ' · ' + esc(h.component) : '') + ' · ' + esc(h.groups.slice(0, 3).join(', ')) + '</small></div>').join('')
				: '<div class="r hf-muted">No match.</div>';
			box.querySelectorAll('.r[data-i]').forEach((r) => {
				const go = () => pick(d.hosts[+r.dataset.i]);
				r.addEventListener('click', go);
				r.addEventListener('keydown', (e) => { if (e.key === 'Enter') go(); });
			});
		}
		catch (e) { box.innerHTML = '<div class="r hf-status err">' + esc(e.message) + '</div>'; }
	}, 250);

	$('#hf-hostq').addEventListener('input', (e) => searchHosts(e.target.value, '#hf-hosts', (h) => {
		const cell = selected();
		if (!cell || cell.edge) { status('hf-link-status', 'Select a shape first.', false); return; }
		const label = graph.convertValueToString(cell);
		setAttrs(cell, {zbx_hostid: h.hostid, zbx_kind: 'host', zbx_component: h.component || '', name: esc(h.name), ip: esc(h.ip),
			placeholders: '1', label: !label || /^(Shape|Text)?$/.test(label.trim()) || attr(cell, 'zbx_hostid') ? HF.hostLabel : label});
		showSelection();
		status('hf-link-status', 'Linked to ' + h.name + '. Its IP and problems show in the live view.', true);
	}));
	$('#hf-unlink').addEventListener('click', () => {
		const cell = selected();
		if (!cell) return;
		setAttrs(cell, {zbx_hostid: null, zbx_kind: null, zbx_component: null, ip: null});
		showSelection();
		status('hf-link-status', 'Host unlinked.', true);
	});

	// Note & runbook
	$('#hf-note-save').addEventListener('click', () => {
		const cell = selected();
		if (!cell) { status('hf-link-status', 'Select a shape first.', false); return; }
		const rb = $('#hf-runbook').value.trim();
		if (rb && !/^https?:\/\//i.test(rb)) { status('hf-link-status', 'The runbook link must start with http:// or https://.', false); return; }
		setAttrs(cell, {zbx_note: $('#hf-note').value.trim() || null, zbx_runbook: rb || null});
		status('hf-link-status', 'Saved on the shape. It shows in the live view tooltip and click menu.', true);
	});

	// Item on an arrow
	$('#hf-itemhost').addEventListener('input', (e) => searchHosts(e.target.value, '#hf-itemhosts', async (h) => {
		$('#hf-itemhosts').innerHTML = '<div class="r hf-muted">Items of ' + esc(h.name) + '…</div>';
		try {
			const d = await HF.get('hostflow.items', {hostid: h.hostid});
			const opts = '<option value="">—</option>' + d.items.map((i) => '<option value="' + esc(i.itemid) + '">' + esc(h.name + ': ' + i.name)
				+ ' (' + esc(i.lastvalue) + ' ' + esc(i.units) + ')</option>').join('');
			$('#hf-item').innerHTML = opts;
			$('#hf-item2').innerHTML = opts;
			$('#hf-itemhosts').innerHTML = '<div class="r hf-muted">' + d.items.length + ' numeric items of ' + esc(h.name) + '. Choose below.</div>';
		}
		catch (err) { $('#hf-itemhosts').innerHTML = '<div class="r hf-status err">' + esc(err.message) + '</div>'; }
	}));
	$('#hf-item-save').addEventListener('click', () => {
		const cell = selected();
		if (!cell || !cell.edge) { status('hf-link-status', 'Select an arrow first.', false); return; }
		setAttrs(cell, {zbx_itemid: $('#hf-item').value || null, zbx_compare_itemid: $('#hf-item2').value || null,
			zbx_drop_pct: $('#hf-item2').value ? $('#hf-drop').value : null, zbx_kind: 'flow'});
		showSelection();
		status('hf-link-status', 'The arrow shows the value in the live view.', true);
	});

	// Shapes palette
	document.querySelectorAll('#hf-palette button').forEach((b) => b.addEventListener('click', () => {
		if (!graph) { HF.say('The editor is still loading.', false); return; }
		const w = frame.contentWindow;
		const comp = b.dataset.comp ? HF.components.find((c) => c.id === b.dataset.comp) : null;
		const doc = w.mxUtils.createXmlDocument();
		const obj = doc.createElement('object');
		let style, wd, ht;
		if (comp) {
			obj.setAttribute('label', comp.label);
			obj.setAttribute('zbx_kind', 'component');
			obj.setAttribute('zbx_component', comp.id);
			style = 'swimlane;rounded=1;arcSize=6;html=1;startSize=36;fontStyle=1;fontSize=13;align=left;spacingLeft=44;fillColor=' + HF.colors[comp.color]
				+ ';fontColor=#ffffff;swimlaneFillColor=#ffffff;strokeColor=#b9c4cf;image=' + HF.icons[comp.id]
				+ ';imageWidth=26;imageHeight=26;imageAlign=left;imageVerticalAlign=top;';
			wd = 180; ht = 160;
		}
		else {
			obj.setAttribute('label', b.textContent.trim());
			obj.setAttribute('zbx_kind', b.dataset.icon);
			style = 'shape=label;rounded=1;whiteSpace=wrap;html=1;arcSize=12;fillColor=#ffffff;strokeColor=#b9c4cf;fontSize=12;align=left;spacingLeft=46;shadow=1;image='
				+ HF.iconImages[b.dataset.icon] + ';imageWidth=30;imageHeight=30;imageAlign=left;imageVerticalAlign=middle;';
			wd = 170; ht = 54;
		}
		const pt = graph.getCenterInsertPoint(new w.mxRectangle(0, 0, wd, ht));
		graph.getModel().beginUpdate();
		let cell;
		try { cell = graph.insertVertex(graph.getDefaultParent(), null, obj, pt.x, pt.y, wd, ht, style); }
		finally { graph.getModel().endUpdate(); }
		graph.setSelectionCell(cell);
	}));

	// Generate / update
	async function generate(update) {
		const client = $('#hf-client').value.trim();
		if (!client) { status('hf-gen-status', 'Enter the client group at the top first.', false); return; }
		status('hf-gen-status', update ? 'Comparing with Zabbix…' : 'Generating…');
		try {
			const body = {client, layout: $('#hf-layout').value, collapse_over: $('#hf-collapse').value, include_disabled: $('#hf-disabled').checked ? '1' : '0'};
			if (update) body.xml = currentXml();
			const r = await HF.post('hostflow.generate', body);
			refreshFlow = true;
			post({action: 'load', xml: r.xml, autosave: 1});
			setDirty(true);
			status('hf-gen-status', update ? r.added.length + ' host(s) added, ' + r.removed.length + ' marked as removed. Save to keep it.'
				: 'Generated with ' + r.hosts + ' host(s). Save to keep it.', true);
			setTimeout(() => attachPlugin(), 300);
		}
		catch (e) { status('hf-gen-status', e.message, false); }
	}
	$('#hf-generate').addEventListener('click', () => {
		if (dirty || meta) {
			HF.dialog('Replace the diagram?', '<p>This replaces everything on the page with a freshly generated layout. Use “Update from Zabbix” to keep your edits.</p>',
				() => { generate(false); return true; }, 'Replace');
		}
		else generate(false);
	});
	$('#hf-update').addEventListener('click', () => generate(true));

	// Clients for the datalist
	HF.get('hostflow.clients').then((d) => {
		$('#hf-clients').innerHTML = d.clients.map((c) => '<option value="' + esc(c.name) + '">').join('');
	}).catch(() => {});

	// Dependencies
	if ($('#hf-deps')) $('#hf-deps').addEventListener('click', async () => {
		status('hf-more-status', 'Looking at triggers…');
		try {
			const d = await HF.get('hostflow.deps', {id: meta.id});
			status('hf-more-status', '');
			if (!d.suggestions.length) { HF.say('No new dependencies to suggest. Check the trigger name pattern in Settings.', true); return; }
			HF.dialog('Suggested trigger dependencies', '<p class="hf-muted">Each downstream trigger is suppressed while the upstream one is a problem. Untick any you don\'t want.</p>'
				+ '<div class="hf-results" style="max-height:50vh">' + d.suggestions.map((s, i) => '<label class="r" style="display:block"><input type="checkbox" checked data-i="' + i + '"> '
					+ '<b>' + esc(s.host) + '</b>: ' + esc(s.trigger) + '<small>depends on ' + esc(s.parent_host) + ': ' + esc(s.parent_trigger) + ' · ' + esc(s.arrow) + '</small></label>').join('')
				+ '</div>', async (root) => {
					const pairs = Array.from(root.querySelectorAll('input[data-i]:checked')).map((c) => ({triggerid: d.suggestions[+c.dataset.i].triggerid, depends_on: d.suggestions[+c.dataset.i].depends_on}));
					const r = await HF.post('hostflow.deps.apply', {pairs});
					HF.say(r.updated + ' trigger(s) updated.' + (r.errors.length ? ' Not changed: ' + r.errors.join('; ') : ''), !r.errors.length);
				}, 'Add dependencies');
		}
		catch (e) { status('hf-more-status', e.message, false); }
	});

	// Services
	if ($('#hf-services')) $('#hf-services').addEventListener('click', async () => {
		status('hf-more-status', 'Preparing…');
		try {
			const d = (await HF.post('hostflow.services', {id: meta.id, apply: '0'})).preview;
			status('hf-more-status', '');
			HF.dialog('Create services and SLA', '<p>Creates <b>' + esc(d.root) + '</b> with ' + d.tiers.length + ' tier service(s) and an SLA of 99.9 % per month.</p>'
				+ '<p class="hf-muted">' + d.host_tags + ' host(s) get two tags (<code>hostflow</code>, <code>hostflow-host</code>) so their problems map to the services.</p>'
				+ '<ul>' + d.tiers.map((t) => '<li>' + esc(t.name) + ' · ' + t.hosts.length + ' host(s)</li>').join('') + '</ul>',
				async () => { await HF.post('hostflow.services', {id: meta.id, apply: '1'}); HF.say('Services and SLA saved. See Services → Services.', true); }, 'Create');
		}
		catch (e) { status('hf-more-status', e.message, false); }
	});

	// Delete
	if ($('#hf-delete')) $('#hf-delete').addEventListener('click', () => {
		HF.dialog('Delete “' + meta.name + '”?', '<p>The diagram is removed for everyone. Hosts in Zabbix are not changed.</p>', async () => {
			await HF.post('hostflow.delete', {id: meta.id});
			dirty = false;
			window.location.href = HF.url('hostflow.list');
		}, 'Delete');
	});

	frame.src = HF.drawioUrl;
	setDirty(false);
})();
