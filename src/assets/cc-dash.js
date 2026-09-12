/*
 * <module_context>
 *   <name>cc-dash</name>
 *   <description>Dashboard tile renderer (docs/specs/DASHBOARD_TILE.md). Renders a summary
 *   from cc_summary(): a count for each protocol, the clients with the most sessions, and
 *   the failed sign-ins. Takes updates from the nchan channel connections_dash; reads
 *   summary.php when no update arrives for 15 s. CCDash.render is the only renderer.</description>
 * </module_context>
 */
(function () {
  'use strict';
  const PROTOS = [['web', 'Web UI'], ['ssh', 'SSH'], ['smb', 'SMB'], ['nfs', 'NFS'], ['vpn', 'VPN']];
  const OFF = { disabled: 'turned off in settings', unavailable: 'not available', off: 'not running' };
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const plural = (n, w, ws) => `${n} ${n === 1 ? w : (ws || w + 's')}`;

  function render(el, sub, S) {
    if (!S || S.error) {
      el.innerHTML = '<p class="cc-d-empty">No data: the Connected Clients collector is not running.</p>';
      if (sub) sub.textContent = 'Collector not running';
      return;
    }
    const cells = PROTOS.map(([p, name]) => {
      const x = (S.protos && S.protos[p]) || { n: 0, local: 0, state: 'ok' };
      const off = OFF[x.state] || '';
      const title = `${name}: ${plural(x.n, 'session')} now${x.local ? `, ${x.local} local not counted` : ''}${off ? ` (${off})` : ''}`;
      return `<div class="cc-d-cell${x.n ? '' : ' zero'}" title="${esc(title)}"><b>${x.n}</b><span><i class="cc-d-sw cc-d-${p}" aria-hidden="true"></i>${esc(name)}</span></div>`;
    }).join('');
    const shown = S.clients || [];
    const list = shown.map((c) => `<li><span class="cc-d-name${c.kind === 'public' ? ' cc-warn' : ''}" title="${esc(c.kind === 'public' ? 'Public internet address. Check this client.' : c.name)}">${esc(c.name)}</span>`
      + `<span class="cc-d-protos">${(c.protos || []).map((p) => `<i class="cc-d-sw cc-d-${esc(p)}" title="${esc(p)}"></i>`).join('')}</span><code>${esc(c.ip)}</code></li>`).join('');
    const more = S.client_count > shown.length ? `<li class="cc-d-more">and ${S.client_count - shown.length} more</li>` : '';
    const notes = [];
    if (S.failed_24h) notes.push(`<span class="cc-warn">${plural(S.failed_24h, 'failed sign-in')} in 24 h</span>`);
    if (S.public) notes.push(`<span class="cc-warn">${plural(S.public, 'client')} from the internet</span>`);
    if (S.local_count) notes.push(`<span>${plural(S.local_count, 'local session')} not counted</span>`);
    el.innerHTML = `<div class="cc-d-grid">${cells}</div>`
      + (list ? `<ul class="cc-d-list">${list}${more}</ul>` : '<p class="cc-d-empty">No client is connected now.</p>')
      + (notes.length ? `<p class="cc-d-notes">${notes.join(' · ')}</p>` : '');
    if (sub) sub.textContent = `${plural(S.client_count, 'client')} · ${plural(S.session_count, 'session')}`;
  }

  window.CCDash = { render };

  const el = document.getElementById('cc-dash');
  if (!el) return;
  const sub = document.getElementById('cc-dash-sub');
  let last = Date.now();
  const apply = (S) => { render(el, sub, S); last = Date.now(); };
  try { render(el, sub, JSON.parse(el.dataset.initial || 'null')); } catch (e) { render(el, sub, null); }

  if (typeof NchanSubscriber === 'function') {
    try {
      const nc = new NchanSubscriber('/sub/connections_dash', { subscriber: 'websocket', reconnectTimeout: 5000 });
      nc.on('message', (msg) => { try { apply(JSON.parse(msg)); } catch (e) { /* a bad message: the fallback covers it */ } });
      nc.start();
    } catch (e) { /* no nchan: the fallback reads summary.php */ }
  }
  setInterval(async () => {
    if (document.hidden || Date.now() - last < 15000) return;
    try {
      const r = await fetch('/plugins/unraid-connections/summary.php', { cache: 'no-store', credentials: 'same-origin' });
      apply(await r.json());
    } catch (e) { /* the next tick tries again */ }
  }, 5000);
}());
