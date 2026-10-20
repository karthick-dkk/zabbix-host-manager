/* Host Flow shared helpers. window.HF is set by each view (urls, CSRF tokens, flags). */
(function () {
	'use strict';

	const HF = window.HF = window.HF || {};

	HF.esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

	HF.url = (action, args) => {
		const u = new URL('zabbix.php', window.location.href);
		u.searchParams.set('action', action);
		Object.entries(args || {}).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') u.searchParams.set(k, v); });
		return u.toString();
	};

	/** GET a JSON action. Throws Error(message) on {error}. */
	HF.get = async (action, args) => {
		const r = await fetch(HF.url(action, args), {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
		return HF.parse(r);
	};

	/** POST JSON to an action; the CSRF token for that action goes in the query string. */
	HF.post = async (action, body) => {
		const token = (HF.csrf || {})[action];
		const args = {};
		args[HF.csrfName || '_csrf_token'] = token;
		const r = await fetch(HF.url(action, args), {
			method: 'POST', credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
			body: JSON.stringify(body || {})
		});
		return HF.parse(r);
	};

	HF.parse = async (r) => {
		let j;
		const text = await r.text();
		try { j = JSON.parse(text); }
		catch (e) {
			if (r.status === 403 || /login/i.test(text.slice(0, 2000))) throw new Error('Your Zabbix session ended. Reload the page and sign in again.');
			throw new Error('Unexpected answer from Zabbix (HTTP ' + r.status + ').');
		}
		if (j && j.error) {
			const e = new Error(typeof j.error === 'string' ? j.error : (j.error.messages || []).join(' ') || 'Request failed.');
			e.data = j;
			throw e;
		}
		return j;
	};

	HF.say = (msg, ok) => {
		if (typeof clearMessages === 'function') clearMessages();
		if (typeof makeMessageBox === 'function' && typeof addMessage === 'function') {
			addMessage(makeMessageBox(ok ? 'good' : 'bad', [], msg, true, !ok));
			return;
		}
		const box = document.querySelector('.hf-flash-msg') || document.body.appendChild(Object.assign(document.createElement('div'), {className: 'hf-flash-msg'}));
		box.textContent = msg;
	};

	HF.money = (v) => v == null ? '—' : '$' + Number(v).toLocaleString(undefined, {minimumFractionDigits: v < 100 ? 2 : 0, maximumFractionDigits: 2});

	HF.ago = (ts) => {
		if (!ts) return '';
		const s = Math.max(0, Math.round(Date.now() / 1000 - ts));
		if (s < 60) return s + ' s ago';
		if (s < 3600) return Math.round(s / 60) + ' min ago';
		if (s < 86400) return Math.round(s / 3600) + ' h ago';
		return new Date(ts * 1000).toLocaleDateString();
	};

	HF.sevColor = (s) => s < 0 ? null : (HF.severityColors || [])[s] || '#97aab3';
	HF.sevName = (s) => (HF.severityNames || [])[s] || '';
	HF.sevBadge = (s, text) => s < 0 ? '<span class="hf-badge ok">OK</span>'
		: '<span class="hf-sev" style="background:' + HF.esc(HF.sevColor(s)) + '">' + HF.esc(text != null ? text : HF.sevName(s)) + '</span>';

	/** Minimal modal. fields: html string. onOk(root) returns false to keep open (may be async). */
	HF.dialog = (title, html, onOk, okLabel) => {
		const bg = document.createElement('div');
		bg.className = 'hf-dialog';
		bg.innerHTML = '<div role="dialog" aria-modal="true"><h3>' + HF.esc(title) + '</h3>' + html
			+ '<div class="hf-status" role="status"></div><div class="acts"><button type="button" class="btn-alt" data-x>Cancel</button>'
			+ (onOk ? '<button type="button" data-ok>' + HF.esc(okLabel || 'Apply') + '</button>' : '') + '</div></div>';
		document.body.appendChild(bg);
		const close = () => bg.remove();
		bg.addEventListener('click', (e) => { if (e.target === bg || e.target.hasAttribute('data-x')) close(); });
		bg.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
		if (onOk) {
			bg.querySelector('[data-ok]').addEventListener('click', async () => {
				const st = bg.querySelector('.hf-status');
				try {
					st.className = 'hf-status';
					st.textContent = 'Working…';
					if ((await onOk(bg)) !== false) close();
					else st.textContent = '';
				}
				catch (e) { st.className = 'hf-status err'; st.textContent = e.message; }
			});
		}
		const f = bg.querySelector('input,select,textarea,button[data-ok]');
		if (f) setTimeout(() => f.focus(), 10);
		return bg;
	};

	HF.debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
})();
