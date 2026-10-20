/* Cost page: totals, 12-month trend, per-client breakdown, AWS matching status. */
(function () {
	'use strict';
	const HF = window.HF, esc = HF.esc;
	const $ = (s) => document.querySelector(s);
	const SRC = {api: 'Pricing API', override: 'your rate', actual: 'AWS bill', partial: 'partial', none: 'no price', estimate: 'estimate'};

	async function load() {
		const box = $('#hf-cost');
		try {
			const d = await HF.get('hostflow.cost.data', {client: $('#hf-cost-client').value});
			const sel = $('#hf-cost-client');
			if (sel.options.length === 1) d.clients.forEach((c) => sel.insertAdjacentHTML('beforeend', '<option>' + esc(c.client) + '</option>'));
			const total = d.clients.reduce((s, c) => s + c.total, 0);
			$('#hf-cost-status').textContent = (d.pricing_at ? 'Prices ' + HF.ago(d.pricing_at) : 'No AWS prices yet') + (d.aws_at ? ' · AWS ' + HF.ago(d.aws_at) : '');
			let html = '<div class="hf-kpis"><div class="hf-kpi"><span class="hf-muted">Estimated per month</span><b>' + esc(HF.money(total)) + '</b></div>'
				+ d.clients.map((c) => '<div class="hf-kpi"><span class="hf-muted">' + esc(c.client) + '</span><b>' + esc(HF.money(c.total)) + '</b></div>').join('') + '</div>';
			html += '<div class="hf-card" style="margin-bottom:12px"><h3>Last 12 months</h3>' + trend(d.trend) + '</div>';
			d.clients.forEach((c) => {
				html += '<div class="hf-card" style="margin-bottom:12px"><h3>' + esc(c.client) + ' · ' + esc(HF.money(c.total)) + '/month</h3>'
					+ '<p class="hf-muted">' + Object.entries(c.components).map(([k, v]) => esc(k) + ' ' + esc(HF.money(v))).join(' · ') + '</p>'
					+ '<div style="overflow:auto"><table class="hf-table"><thead><tr><th>Host</th><th>Tier</th><th>IP</th><th>Instance</th><th>Price from</th>'
					+ '<th class="num">EC2</th><th class="num">EBS</th><th class="num">Snapshots</th><th class="num">Transfer</th><th class="num">IPv4</th><th class="num">Extras</th><th class="num">Month</th></tr></thead><tbody>'
					+ c.hosts.map((h) => h.excluded
						? '<tr class="hf-muted"><td>' + esc(h.name) + '</td><td>' + esc(h.component) + '</td><td class="hf-mono">' + esc(h.ip) + '</td><td colspan="9">excluded</td></tr>'
						: '<tr><td>' + esc(h.name) + '</td><td>' + esc(h.component) + '</td><td class="hf-mono">' + esc(h.ip) + '</td>'
							+ '<td class="hf-mono">' + esc(h.instance || '') + (h.type ? ' <span class="hf-muted">' + esc(h.type) + '</span>' : '') + '</td>'
							+ '<td class="hf-src-' + esc(h.source) + '">' + esc(SRC[h.source] || h.source) + '</td>'
							+ ['ec2', 'ebs', 'snapshots', 'transfer', 'ipv4', 'extras'].map((k) => '<td class="num">' + esc(HF.money(h.parts[k])) + '</td>').join('')
							+ '<td class="num"><b>' + esc(HF.money(h.monthly)) + '</b></td></tr>').join('')
					+ c.cost_only.map((x) => '<tr><td>' + esc(x.name) + ' <span class="hf-badge mute">cost only</span></td><td colspan="3"></td><td class="hf-src-' + esc(x.source) + '">'
						+ esc(SRC[x.source] || x.source) + '</td><td colspan="6"></td><td class="num"><b>' + esc(HF.money(x.monthly)) + '</b></td></tr>').join('')
					+ '</tbody></table></div></div>';
			});
			if (d.aws) {
				const amb = Object.entries(d.aws.ambiguous || {}), mov = Object.entries(d.aws.moved || {});
				html += '<div class="hf-card"><h3>AWS matching</h3><p class="hf-muted">' + d.aws.instances + ' instances seen' + (d.aws.synced_at ? ', ' + esc(HF.ago(d.aws.synced_at)) : ', never synced') + '.</p>'
					+ (amb.length ? '<h4>One IP, several instances (not matched)</h4><ul>' + amb.map(([hid, a]) => '<li>Host #' + esc(hid) + ' <span class="hf-mono">' + esc(a.ip) + '</span>: '
						+ esc(a.candidates.join(', ')) + '. Add the tag <code>aws-instance-id</code> to the host to choose.</li>').join('') + '</ul>' : '')
					+ (mov.length ? '<h4>IP moved to another instance</h4><ul>' + mov.map(([hid, m]) => '<li>Host #' + esc(hid) + ' <span class="hf-mono">' + esc(m.ip) + '</span>: still pinned to '
						+ esc(m.pinned) + '; the IP now belongs to ' + esc(m.now) + '.</li>').join('') + '</ul>' : '')
					+ ((d.aws.unmatched || []).length ? '<h4>AWS spend not on any diagram</h4><table class="hf-table"><tbody>' + d.aws.unmatched.map((u) => '<tr><td class="hf-mono">' + esc(u.resource_id)
						+ '</td><td>' + esc(u.kind) + '</td><td class="num">' + esc(HF.money(u.monthly)) + '</td></tr>').join('') + '</tbody></table>' : '')
					+ ((d.aws.errors || []).length ? '<h4>Warnings</h4><ul>' + d.aws.errors.map((e) => '<li>' + esc(e) + '</li>').join('') + '</ul>' : '')
					+ (!amb.length && !mov.length && !(d.aws.unmatched || []).length && !(d.aws.errors || []).length ? '<p>Everything matched.</p>' : '')
					+ '</div>';
			}
			box.innerHTML = html;
		}
		catch (e) {
			box.innerHTML = '<div class="hf-empty"><b>Could not load cost</b>' + esc(e.message) + '</div>';
		}
	}

	/** Bar chart of the monthly average, drawn to scale with labelled values. */
	function trend(t) {
		if (!t || !t.length) return '<p class="hf-muted">The trend starts after the first daily refresh.</p>';
		const W = 760, H = 200, L = 60, B = 26, T = 18;
		const max = Math.max(...t.map((x) => x.total), 1);
		const step = niceStep(max / 4);
		const top = Math.ceil(max / step) * step;
		const bw = (W - L - 10) / t.length;
		const y = (v) => T + (H - T - B) * (1 - v / top);
		let s = '<svg class="hf-trend" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Monthly cost for the last ' + t.length + ' months">';
		for (let v = 0; v <= top + 1e-9; v += step) {
			s += '<line x1="' + L + '" x2="' + (W - 10) + '" y1="' + y(v) + '" y2="' + y(v) + '" stroke="currentColor" stroke-opacity=".12"/>'
				+ '<text x="' + (L - 6) + '" y="' + (y(v) + 4) + '" text-anchor="end" font-size="11" fill="currentColor" fill-opacity=".7">' + esc(HF.money(v)) + '</text>';
		}
		t.forEach((m, i) => {
			const x = L + i * bw + bw * 0.15, w = bw * 0.7, h = y(0) - y(m.total);
			s += '<rect x="' + x + '" y="' + y(m.total) + '" width="' + w + '" height="' + Math.max(0, h) + '" rx="3" fill="#1f65f4"/>'
				+ '<text x="' + (x + w / 2) + '" y="' + (H - 8) + '" text-anchor="middle" font-size="11" fill="currentColor" fill-opacity=".7">' + esc(m.month.slice(2)) + '</text>'
				+ (i === t.length - 1 ? '<text x="' + (x + w / 2) + '" y="' + (y(m.total) - 5) + '" text-anchor="middle" font-size="11" font-weight="bold" fill="currentColor">' + esc(HF.money(m.total)) + '</text>' : '');
		});
		return s + '</svg>';
	}

	function niceStep(raw) {
		const p = Math.pow(10, Math.floor(Math.log10(raw || 1)));
		const n = raw / p;
		return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p;
	}

	$('#hf-cost-client').addEventListener('change', load);
	const rb = $('#hf-cost-refresh');
	if (rb) rb.addEventListener('click', async () => {
		$('#hf-cost-status').textContent = 'Refreshing from AWS…';
		try {
			const r = (await HF.post('hostflow.cost.refresh', {})).report;
			HF.say(r.errors.length ? 'Refreshed with problems: ' + r.errors.join('; ') : 'Cost refreshed.', !r.errors.length);
		}
		catch (e) { HF.say(e.message, false); }
		load();
	});
	if (HF.isSuper && HF.stale) HF.post('hostflow.cost.refresh', {only_if_stale: '1'}).then(load).catch(() => {});
	load();
})();
