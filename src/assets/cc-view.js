/*
 * <module_context>
 *   <name>cc-view</name>
 *   <description>Connected Clients page: takes each snapshot from the nchan channel
 *   connections (docs/specs/LIVE_UPDATES.md), or from state.php when nchan is silent, and renders the
 *   protocol strip, "right now", the timeline, the sign-in events, the event log
 *   (docs/specs/EVENTS_AND_SUBSCRIPTIONS.md) and the sources. Each viewer's toggles are kept
 *   in localStorage.</description>
 *   <dependencies>cc-model.js</dependencies>
 * </module_context>
 */
(function () {
  'use strict';
  const root = document.getElementById('cc-root');
  if (!root || !window.CCModel) return;
  const { PROTO, ORDER, KIND, STATE, RANK, esc, plural } = window.CCModel;
  const $ = (id) => document.getElementById(id);
  const STATE_URL = '/plugins/unraid-connections/state.php';
  const ACTION_URL = '/plugins/unraid-connections/action.php';
  const POLL_MS = 5000;
  const RC = '/usr/local/emhttp/plugins/unraid-connections/scripts/rc.unraid-connections';

  const loadPrefs = () => { try { return JSON.parse(localStorage.getItem('cc-prefs') || '{}') || {}; } catch (e) { return {}; } };
  const prefs = Object.assign({ win: 72, group: 'client', local: false, stale: false, ev: 'all', evAll: false, lg: 'all', lgAll: false }, loadPrefs());
  const savePrefs = () => { try { localStorage.setItem('cc-prefs', JSON.stringify(prefs)); } catch (e) { /* storage blocked */ } };

  let M = null;
  let ownTag = null;   // the viewer's own web session tag (ACTIONS.md)
  const openHistory = new Set();   // client IPs whose history panel is open
  const historyCache = new Map();  // ip -> { at, data | error }
  const vis = (s) => prefs.local || !M.isLocal(s.ip);
  const isNow = (s) => s.state === 'active' || s.state === 'idle';
  const lastOf = (s) => s.end ?? M.NOW;
  const byRank = (a, b) => RANK[a.state] - RANK[b.state] || ORDER.indexOf(a.proto) - ORDER.indexOf(b.proto) || lastOf(b) - lastOf(a);
  const stateHtml = (st) => `<span class="cc-state cc-st-${st}"><i aria-hidden="true"></i>${STATE[st]}</span>`;
  const protoHtml = (p) => `<span class="cc-proto"><i class="cc-sw cc-p-${p}" aria-hidden="true"></i><span>${PROTO[p].name}</span><code>${esc(PROTO[p].port)}</code></span>`;
  const chipHtml = (c, cls = '') => `<span class="cc-chip ${cls}" title="${esc(c[1])}">${esc(c[0])}</span>`;

  function renderHead() {
    const D = M.D, age = Math.round(Date.now() / 1000 - M.NOW);
    $('cc-eyebrow').textContent = `${D.host.name} · Unraid ${D.host.version} · up ${M.dur(D.host.uptime)}`;
    $('cc-live').innerHTML = age > 20
      ? `<span class="cc-dot cc-warn" aria-hidden="true"></span>No new data since ${esc(M.dayhm(M.NOW))}. The collector may have stopped.`
      : `<span class="cc-dot live" aria-hidden="true"></span>Live · updated ${M.hms(M.NOW)} ${esc(M.zone)} · every ${D.collector ? D.collector.interval : 5} s (${pushed() ? 'nchan push' : 'polling'})`;
    $('cc-h-tl').textContent = `Last ${prefs.win} hours`;
  }

  function renderStrip() {
    const D = M.D, tp = (D.tailscale && D.tailscale.peers) || [], wg = (D.wireguard && D.wireguard.peers) || [];
    $('cc-strip').innerHTML = ORDER.map((p) => {
      const all = M.S.filter((s) => s.proto === p), live = all.filter(isNow), shown = live.filter(vis);
      const hidden = live.length - shown.length, clients = new Set(shown.map((s) => s.ip)).size, sub = [];
      if (p === 'web') {
        const sockets = shown.reduce((a, s) => a + (s.raw.connections || 0), 0);
        sub.push(plural(clients, 'client') + (sockets ? ' · ' + plural(sockets, 'socket') : ''));
        const stale = all.filter((s) => s.state === 'stale' && vis(s)).length;
        if (stale) sub.push(plural(stale, 'stale session file'));
      } else if (p === 'ssh') {
        sub.push(`${all.filter((s) => s.state === 'ended' && vis(s) && lastOf(s) >= M.NOW - prefs.win * 3600).length} ended in ${prefs.win} h`);
      } else if (p === 'smb') {
        const shares = [...new Set(shown.flatMap((s) => s.raw.shares.map((x) => x.share)))];   // two clients on one share: name it once
        sub.push(shares.length ? shares.join(', ') : (D.smb.available ? 'no share open' : 'Samba is not running'));
      } else if (p === 'nfs') {
        sub.push(shown.length ? shown.map((s) => 'NFS v' + s.raw.version).join(', ') : 'no NFS v4 client');
      } else {
        sub.push(wg.length ? plural(wg.length, 'WireGuard peer') : 'no WireGuard tunnel');
        sub.push(`${plural(tp.length, 'Tailscale peer')}, ${tp.filter((x) => x.online).length} online`);
      }
      const src = M.sources[p];
      if (src && src.state !== 'ok') { sub.length = 0; sub.push(src.state === 'disabled' ? 'turned off in settings' : (src.reason || 'not available')); }
      if (hidden) sub.push(`${hidden} local, hidden`);
      const n = shown.length;
      return `<div class="cc-cell${n ? '' : ' zero'}"><div class="cc-cell-h"><i class="cc-sw cc-p-${p}" aria-hidden="true"></i><span>${PROTO[p].name}</span><code>${esc(PROTO[p].port)}</code></div>
        <div class="cc-big"><b>${n}</b><span>active ${n === 1 ? PROTO[p].unit[0] : PROTO[p].unit[1]}</span></div>
        <ul>${sub.map((x) => `<li>${esc(x)}</li>`).join('')}</ul></div>`;
    }).join('');
  }

  const actBtn = (a) => (a ? `<button type="button" class="cc-linkbtn cc-act" data-act="${esc(a.act)}" data-target="${esc(a.target)}" data-label="${esc(a.label)}" data-what="${esc(a.what)}" data-warn="${esc(a.warn)}">${esc(a.label)}</button>` : '');
  function srow(s, lead) {
    const d = M.describe(s), w = M.since(s);
    return `<div class="cc-srow"><div>${lead}</div><div>${stateHtml(s.state)}</div>
      <div class="cc-det">${d.bits.map(esc).join('<span class="sep"> · </span>')}${d.chips.map((c) => ' ' + chipHtml(c)).join('')}</div>
      <div class="cc-when"><span>${esc(w.text)}</span>${w.sub ? `<small>${esc(w.sub)}</small>` : ''}${actBtn(M.actionFor(s))}</div></div>`;
  }
  function renderRoster() {
    const rows = M.S.filter((s) => vis(s) && (isNow(s) || (prefs.stale && s.state === 'stale')));
    const el = $('cc-roster');
    el.className = prefs.group === 'protocol' ? 'cc-by-proto' : '';
    let html = '';
    if (prefs.group === 'client') {
      const g = new Map();
      for (const s of rows) { const k = s.ip || '?'; if (!g.has(k)) g.set(k, []); g.get(k).push(s); }
      html = [...g.entries()]
        .map(([ip, list]) => ({ ip, list: list.sort(byRank), best: Math.min(...list.map((s) => RANK[s.state])), last: Math.max(...list.map(lastOf)) }))
        .sort((a, b) => a.best - b.best || b.last - a.last)
        .map(({ ip, list }) => {
          const c = M.info(ip);
          const dns = c.label ? M.dnsName(ip) : '';
          const proxy = c.proxy ? chipHtml(['Via proxy', 'This address is in your proxy ranges. The real client is behind it.']) : '';
          const head = `<div class="cc-c-head"><b>${esc(M.clientName(list[0]))}</b>${dns ? `<small class="cc-dns">${esc(dns)}</small>` : ''}${chipHtml([KIND[c.kind] || 'Unknown', M.kindTitle(c)], c.kind === 'public' ? 'cc-warn' : '')}${proxy}<code>${esc(ip || '—')}</code>${c.mac ? `<code class="mac">${esc(c.mac)}</code>` : ''}</div>`;
          const hist = (M.D.history && M.D.history.ok && ip && ip !== '?') ? `<button type="button" class="cc-linkbtn cc-hist-btn" data-history="${esc(ip)}" aria-expanded="${openHistory.has(ip)}">${openHistory.has(ip) ? 'Hide history' : 'History'}</button>` : '';
          return `<section class="cc-client" data-client="${esc(ip)}">${head.replace(/<\/div>$/, hist + '</div>')}<div class="cc-srows">${list.map((s) => srow(s, protoHtml(s.proto))).join('')}</div></section>`;
        }).join('');
    } else {
      html = ORDER.map((p) => {
        const list = rows.filter((s) => s.proto === p).sort(byRank);
        if (!list.length) return '';
        return `<section class="cc-client"><div class="cc-c-head">${protoHtml(p)}<span class="count">${plural(list.length, PROTO[p].unit[0], PROTO[p].unit[1])}</span></div>
          <div class="cc-srows">${list.map((s) => srow(s, `<span class="cc-who"><b>${esc(M.clientName(s))}</b><code>${esc(s.ip)}</code></span>`)).join('')}</div></section>`;
      }).join('');
    }
    el.innerHTML = html || '<p class="cc-empty">No client holds a session right now.</p>';
    for (const ip of openHistory) {
      const sec = el.querySelector(`section[data-client="${CSS.escape(ip)}"]`);
      if (sec) sec.insertAdjacentHTML('beforeend', historyPanel(ip));
    }
    const live = M.S.filter((s) => vis(s) && isNow(s));
    $('cc-now-meta').textContent = `${plural(new Set(live.map((s) => s.ip)).size, 'client')} · ${plural(live.length, 'session')}`;
    const notes = [];
    const hidLocal = M.S.filter((s) => !prefs.local && M.isLocal(s.ip) && isNow(s));
    if (hidLocal.length) notes.push(`<span>Hidden: ${esc(M.countByProto(hidLocal))} from this server and its containers. <button type="button" class="cc-linkbtn" data-set="local">Show local traffic</button></span>`);
    const hidStale = M.S.filter((s) => !prefs.stale && s.state === 'stale' && vis(s)).length;
    if (hidStale) notes.push(`<span>Hidden: ${plural(hidStale, 'stale session file')}. PHP keeps them until a reboot. <button type="button" class="cc-linkbtn" data-set="stale">Show stale sessions</button></span>`);
    $('cc-hidden-note').innerHTML = notes.join('');
    $('cc-hidden-note').hidden = !notes.length;
  }

  // Client history panel (docs/specs/HISTORY.md): ended sessions and sign-in events from history.php.
  function historyPanel(ip) {
    const h = historyCache.get(ip);
    if (!h || (!h.data && !h.error)) return '<div class="cc-history">Reading the history…</div>';
    if (h.error) return `<div class="cc-history">The history could not be read (${esc(h.error)}).</div>`;
    const days = (M.D.history && M.D.history.days) || 30;
    const ses = (h.data.sessions || []).slice(0, 50).map((x) => {
      const s = { proto: x.proto, ip: x.ip, state: 'ended', start: x.start, end: x.end, raw: Object.assign({}, x.raw || {}, { state: 'ended' }), fromHistory: true };
      const d = M.describe(s);
      return `<div class="cc-hrow">${protoHtml(x.proto)}<span class="cc-hwhen">${esc(M.dayhm(x.start))} → ${esc(M.dayhm(x.end))} · ${esc(M.dur(x.end - x.start))}</span><span class="cc-det">${d.bits.slice(0, 3).map(esc).join(' · ')}</span></div>`;
    }).join('');
    const evs = (h.data.events || []).slice(0, 50).map((e) => `<div class="cc-hrow">${protoHtml(e.proto)}<span class="cc-hwhen">${esc(M.dayLabel(e.t))} ${M.hms(e.t)}</span><span class="cc-det">${e.type === 'login_failed' ? 'Failed sign-in' : (e.type === 'logout' ? (e.proto === 'ssh' ? 'Disconnected' : 'Signed out') : 'Signed in')} · ${esc(e.user || '')}</span></div>`).join('');
    return `<div class="cc-history"><div class="cc-hhead">History, last ${days} days</div>
      <div class="cc-hsub">Ended sessions (${(h.data.sessions || []).length})</div>${ses || '<div class="cc-empty">None.</div>'}
      <div class="cc-hsub">Sign-in events (${(h.data.events || []).length})</div>${evs || '<div class="cc-empty">None.</div>'}</div>`;
  }
  async function toggleHistory(ip) {
    if (openHistory.has(ip)) { openHistory.delete(ip); renderRoster(); return; }
    openHistory.add(ip);
    const c = historyCache.get(ip);
    if (!c || Date.now() - c.at > 30000) {
      historyCache.set(ip, { at: Date.now() });
      renderRoster();
      try {
        const r = await fetch('/plugins/unraid-connections/history.php?ip=' + encodeURIComponent(ip), { cache: 'no-store', credentials: 'same-origin' });
        const data = await r.json();
        historyCache.set(ip, r.ok && !data.error ? { at: Date.now(), data } : { at: Date.now(), error: data.error || `HTTP ${r.status}` });
      } catch (e) {
        historyCache.set(ip, { at: Date.now(), error: String(e.message || e) });
      }
    }
    renderRoster();
  }

  function renderSources() {
    const D = M.D, w = D.web, tp = (D.tailscale && D.tailscale.peers) || [], wg = (D.wireguard && D.wireguard.peers) || [];
    const rows = [
      [true, 'PHP session files', `${w.sessions.length} signed in, ${plural(w.anonymous_sessions, 'visitor file')}`],
      D.history && D.history.ok
        ? [true, 'History database', `${plural(D.events.length, 'event')}, ${D.history.days} days · ${plural(D.history.ended.length, 'ended session')}, 72 h${D.history.restored ? ' · restored from flash at start' : ''}`]
        : [false, 'History database', 'not available; events come from syslog (7 days)'],
      D.ledger && D.ledger.ok
        ? [true, 'Event log', `${D.ledger.count_24h} events in 24 h · seq ${D.ledger.head}`]
        : [false, 'Event log', 'not available'],
      D.notify && Array.isArray(D.notify.rules)
        ? [D.notify.rules.some((r) => r.on), 'Alerts and rules', `${D.notify.rules.filter((r) => r.on).length} of ${D.notify.rules.length} rules on · ${plural(D.notify.sent_24h, 'alert')} in 24 h`]
        : [false, 'Subscriptions', 'not available'],
      [true, 'Live sockets (ss)', `${(w.live || []).reduce((a, l) => a + l.connections, 0)} web, ${D.ssh.sessions.filter((s) => s.state === 'active').length} SSH, ${D.nfs.tcp_peers.length} NFS`],
      [w.nchan.subscribers != null, 'nchan status', `${w.nchan.subscribers ?? '?'} subscribers, ${w.nchan.channels ?? '?'} channels`],
      [D.smb.available, 'smbstatus', D.smb.available ? `Samba ${D.smb.version}, ${plural(D.smb.sessions.length, 'session')}` : 'not available'],
      [D.nfs.available, 'NFS server (/proc)', D.nfs.available ? plural(D.nfs.clients.length, 'v4 client') : 'not running'],
      [wg.length > 0, 'WireGuard', wg.length ? plural(wg.length, 'peer') : 'no tunnel up'],
      [tp.some((x) => x.online), 'Tailscale', D.tailscale.available ? `${plural(tp.length, 'peer')}, ${tp.filter((x) => x.online).length} online` : 'not installed'],
      [D.ftp.enabled, 'FTP', D.ftp.enabled ? 'enabled' : 'service disabled'],
    ];
    for (const p of ORDER) { const st = M.sources[p]; if (st && st.state === 'disabled') rows.push([false, `${PROTO[p].name} adapter`, 'turned off in settings']); }
    $('cc-sources').innerHTML = rows.map(([ok, name, what]) =>
      `<li><span class="${ok ? 'ok' : 'off'}" aria-label="${ok ? 'Data found' : 'Nothing to read'}">${ok ? '✓' : '–'}</span><span>${esc(name)}</span><em>${esc(what)}</em></li>`).join('');
    const cav = [];
    if (M.S.some((s) => s.proto === 'web' && s.raw.match === 'inferred')) cav.push('<b>IP inferred.</b> The collector did not see this session when it was new, so it matched the only session in use to the only live client.');
    if (M.S.some((s) => s.proto === 'smb')) cav.push('<b>SMB start.</b> Samba gives a start time with an unreliable UTC offset. The page uses its wall-clock time and fades the bar start.');
    if (M.S.some((s) => s.proto === 'nfs')) cav.push('<b>NFS start.</b> NFS v4 records no start time. A dotted line marks “present now, start not recorded”.');
    $('cc-caveats').innerHTML = cav.map((x) => `<li>${x}</li>`).join('');
  }

  let TL = [];
  function ticks(from, to, step) {
    const out = [];
    for (let t = Math.ceil(from / 3600) * 3600; t <= to; t += 3600) {
      const p = M.parts(t), h = +p.hour;
      if (+p.minute !== 0 || h % step) continue;
      out.push({ t, mid: h === 0, label: h === 0 ? `${p.weekday} ${+p.day}` : `${p.hour}:00` });
    }
    return out;
  }
  function renderTimeline() {
    const W = prefs.win * 3600, from = M.NOW - W;
    const pct = (t) => ((Math.min(M.NOW, Math.max(from, t)) - from) / W) * 100;
    const lanes = new Map();
    for (const s of M.S) {
      if (!vis(s) || lastOf(s) < from) continue;
      const k = s.ip || '?';
      if (!lanes.has(k)) lanes.set(k, { ip: k, tracks: {}, last: 0, live: false, s });
      const L = lanes.get(k);
      (L.tracks[s.proto] = L.tracks[s.proto] || []).push(s);
      L.last = Math.max(L.last, lastOf(s));
      if (s.state === 'active') L.live = true;
    }
    const order = [...lanes.values()].sort((a, b) => (b.live - a.live) || (b.last - a.last));
    TL = [];
    const bar = (s) => {
      const i = TL.push(s) - 1, w = M.since(s);
      const attrs = `data-i="${i}" tabindex="0" role="img" aria-label="${esc(`${PROTO[s.proto].name}, ${M.clientName(s)}, ${STATE[s.state]}, ${w.text}`)}"`;
      if (s.start == null && s.end == null) return `<span class="cc-leader cc-p-${s.proto}" ${attrs}></span>`;
      const l = pct(s.start ?? from), r = pct(lastOf(s)), cls = ['cc-bar', 'cc-p-' + s.proto];
      if (s.end == null) cls.push('open');
      if (s.start == null || s.start < from) cls.push('clip'); else if (s.unsure) cls.push('unsure');
      return `<span class="${cls.join(' ')}" ${attrs} style="left:${l.toFixed(3)}%;width:${Math.max(0, r - l).toFixed(3)}%"></span>`;
    };
    const tk = ticks(from, M.NOW, prefs.win > 24 ? 6 : 3);
    const axis = tk.filter((t) => { const x = pct(t.t); return x > 3 && x < 92; })
      .map((t) => `<span class="cc-tick${t.mid ? ' mid' : ''}" style="left:${pct(t.t).toFixed(3)}%">${esc(t.label)}</span>`).join('') + `<span class="cc-tick now">now ${M.hm(M.NOW)}</span>`;
    const grid = tk.map((t) => `<i class="cc-gl${t.mid ? ' mid' : ''}" style="left:${pct(t.t).toFixed(3)}%"></i>`).join('') + '<i class="cc-gl nowl" style="left:100%"></i>';
    const lanesHtml = order.map((L) => {
      const protos = ORDER.filter((p) => L.tracks[p]);
      return `<div class="cc-lane"><div class="cc-l-label" style="grid-row:span ${protos.length}"><b>${esc(M.clientName(L.s))}</b><code>${esc(L.ip)}</code><span>${esc(KIND[M.info(L.ip).kind] || '')}</span></div>`
        + protos.map((p) => `<code class="cc-l-port">${esc(PROTO[p].port)}</code><div class="cc-l-plot">${L.tracks[p].map(bar).join('')}</div>`).join('') + '</div>';
    }).join('');
    $('cc-timeline').innerHTML = `<div class="cc-tl-axis"><span></span><span></span><div class="cc-ticks">${axis}</div></div>
      <div class="cc-tl-body"><div class="cc-tl-grid" aria-hidden="true">${grid}</div>${lanesHtml || '<div class="cc-tl-empty">No sessions in this window.</div>'}</div>`;
    $('cc-legend').innerHTML = ORDER.filter((p) => order.some((L) => L.tracks[p])).map((p) => `<span><i class="cc-sw cc-p-${p}"></i>${PROTO[p].name}</span>`).join('')
      + '<span><i class="cc-lg-bar"></i>Session</span><span><i class="cc-lg-dot"></i>Still connected</span><span><i class="cc-lg-fade"></i>Start not certain</span><span><i class="cc-lg-dotted"></i>Start not recorded</span>';
  }

  function renderEvents() {
    const base = (M.D.events || []).filter((e) => prefs.local || !M.isLocal(e.ip));
    const n = { all: base.length, web: base.filter((e) => e.proto === 'web').length, ssh: base.filter((e) => e.proto === 'ssh').length, failed: base.filter((e) => e.type === 'login_failed').length };
    for (const k in n) $('cc-n-' + k).textContent = n[k];
    let ev = base;
    if (prefs.ev === 'failed') ev = ev.filter((e) => e.type === 'login_failed');
    else if (prefs.ev !== 'all') ev = ev.filter((e) => e.proto === prefs.ev);
    ev = ev.slice().sort((a, b) => b.t - a.t);
    const shown = prefs.evAll ? ev : ev.slice(0, 20);
    const label = (e) => e.type === 'login_failed'
      ? '<span class="cc-bad"><svg viewBox="0 0 12 12" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M2 2l8 8M10 2l-8 8"/></svg>Failed sign-in</span>'
      : (e.type === 'logout' ? (e.proto === 'ssh' ? 'Disconnected' : 'Signed out') : 'Signed in');
    const body = shown.map((e) => `<tr><td class="t">${esc(M.dayLabel(e.t))} ${M.hms(e.t)}</td><td>${protoHtml(e.proto)}</td><td>${label(e)}</td><td>${esc(e.user || '')}</td><td><b>${esc(M.cname(e.ip))}</b> <code>${esc(e.ip)}</code></td><td class="d">${esc(e.detail || '')}</td></tr>`).join('');
    $('cc-events').innerHTML = `<thead><tr><th>Time (${esc(M.zone)})</th><th>Protocol</th><th>Event</th><th>User</th><th>Client</th><th>Method</th></tr></thead>
      <tbody>${body || '<tr><td colspan="6" class="d">No events match this filter.</td></tr>'}</tbody>`;
    $('cc-ev-count').textContent = `Showing ${shown.length} of ${ev.length}${prefs.local ? '' : ' · local traffic hidden'}`;
    $('cc-ev-more').hidden = ev.length <= 20;
    $('cc-ev-more').textContent = prefs.evAll ? 'Show the latest 20' : `Show all ${ev.length}`;
  }

  // Event log (docs/specs/EVENTS_AND_SUBSCRIPTIONS.md): the ledger the collector writes, one
  // typed row per change. Kinds group into five filters; each kind has a fixed label.
  const LG_KEYS = ['all', 'session', 'signin', 'alert', 'source', 'action'];
  const LG_LABEL = {
    'session.started': 'Session started', 'session.ended': 'Session ended',
    'signin.ok': 'Signed in', 'signin.failed': 'Failed sign-in', signout: 'Signed out',
    'client.new': 'New client', 'signin.threshold': 'Failed sign-ins over the limit', 'signin.public': 'SSH from the internet',
    'source.unavailable': 'Source unavailable', 'source.available': 'Source available', 'action.taken': 'Action',
  };
  const LG_BAD = new Set(['signin.failed', 'client.new', 'signin.threshold', 'signin.public']);
  const lgGroup = (kind) => {
    if (kind.startsWith('session.')) return 'session';
    if (kind === 'signin.ok' || kind === 'signin.failed' || kind === 'signout') return 'signin';
    if (kind === 'client.new' || kind === 'signin.threshold' || kind === 'signin.public') return 'alert';
    if (kind.startsWith('source.')) return 'source';
    if (kind === 'action.taken') return 'action';
    return null;
  };
  const lgLabelHtml = (kind) => {
    const label = LG_LABEL[kind] || kind;
    return LG_BAD.has(kind)
      ? `<span class="cc-bad"><svg viewBox="0 0 12 12" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M2 2l8 8M10 2l-8 8"/></svg>${esc(label)}</span>`
      : esc(label);
  };
  const lgDetails = (e) => {
    if (e.kind === 'client.new' || e.kind === 'signin.threshold' || e.kind === 'signin.public') return e.summary || '';
    const d = e.data || {}, bits = [];
    if (d.via) bits.push(d.via);
    if (d.detail) bits.push(d.detail);
    if (d.shares) bits.push(Array.isArray(d.shares) ? d.shares.join(', ') : d.shares);
    if (d.duration != null) bits.push(M.dur(d.duration));
    if (d.user || e.user) bits.push(d.user || e.user);
    return bits.join(' · ');
  };
  function renderLedger() {
    const thead = `<thead><tr><th>Time (${esc(M.zone)})</th><th>Event</th><th>Protocol</th><th>Client</th><th>Details</th></tr></thead>`;
    const recent = (M.D.ledger && M.D.ledger.recent) || null;
    for (const k of LG_KEYS) { const el = $('cc-n-lg-' + k); if (el) el.textContent = ''; }
    if (!recent) {
      $('cc-ledger-table').innerHTML = `${thead}<tbody><tr><td colspan="5" class="d">The event log is not available (the history database is off).</td></tr></tbody>`;
      $('cc-lg-count').textContent = '';
      $('cc-lg-more').hidden = true;
      return;
    }
    const base = recent.filter((e) => prefs.local || !M.isLocal(e.ip));
    const counts = { all: base.length, session: 0, signin: 0, alert: 0, source: 0, action: 0 };
    for (const e of base) { const g = lgGroup(e.kind); if (g) counts[g] += 1; }
    for (const k of LG_KEYS) { const el = $('cc-n-lg-' + k); if (el) el.textContent = counts[k]; }
    const ev = prefs.lg === 'all' ? base : base.filter((e) => lgGroup(e.kind) === prefs.lg);
    const shown = prefs.lgAll ? ev : ev.slice(0, 20);
    const body = shown.map((e) => {
      const proto = ORDER.includes(e.proto) ? protoHtml(e.proto) : `<code>${esc(e.proto || '—')}</code>`;
      const d = e.data || {}, client = d.client || M.cname(e.ip);
      return `<tr><td class="t">${esc(M.dayLabel(e.t))} ${M.hms(e.t)}</td><td>${lgLabelHtml(e.kind)}</td><td>${proto}</td>`
        + `<td><b>${esc(client)}</b> <code>${esc(e.ip || '—')}</code></td><td class="d">${esc(lgDetails(e))}</td></tr>`;
    }).join('');
    $('cc-ledger-table').innerHTML = `${thead}<tbody>${body || '<tr><td colspan="5" class="d">No events match this filter.</td></tr>'}</tbody>`;
    $('cc-lg-count').textContent = `Showing ${shown.length} of ${ev.length}${prefs.local ? '' : ' · local traffic hidden'}`;
    $('cc-lg-more').hidden = ev.length <= 20;
    $('cc-lg-more').textContent = prefs.lgAll ? 'Show the latest 20' : `Show all ${ev.length}`;
  }

  // Real failures only; a service that is off or turned off in settings shows in its cell (SOURCE_STATUS.md).
  function renderSourceBanner() {
    const bad = Object.entries(M.sources).filter(([, v]) => v && v.state === 'unavailable');
    $('cc-src-banner').innerHTML = bad.map(([k, v]) => `<div><b>${esc(PROTO[k] ? PROTO[k].name : k)}:</b> ${esc(v.reason || 'not available')} The other sources still work.</div>`).join('');
    $('cc-src-banner').hidden = !bad.length;
  }

  function syncControls() {
    root.querySelectorAll('button[data-pref]').forEach((b) => {
      const v = b.dataset.pref === 'win' ? +b.dataset.val : b.dataset.val;
      b.setAttribute('aria-pressed', String(prefs[b.dataset.pref] === v));
    });
    root.querySelectorAll('input[data-pref]').forEach((i) => { i.checked = !!prefs[i.dataset.pref]; });
  }
  let tlBusy = false;   // do not redraw the timeline under the pointer or keyboard focus
  function render(force) {
    syncControls();
    if (!M) return;
    renderHead(); renderSourceBanner(); renderStrip(); renderRoster(); renderSources();
    if (force || !tlBusy) renderTimeline();
    renderEvents();
    renderLedger();
  }

  root.addEventListener('click', (e) => {
    const ab = e.target.closest('.cc-act');
    if (ab) { runAction(ab.dataset); return; }
    const hb = e.target.closest('[data-history]');
    if (hb) { toggleHistory(hb.dataset.history); return; }
    const b = e.target.closest('button[data-pref]');
    if (b) {
      const k = b.dataset.pref; prefs[k] = k === 'win' ? +b.dataset.val : b.dataset.val;
      if (k === 'ev') prefs.evAll = false; else if (k === 'lg') prefs.lgAll = false;
      savePrefs(); render(true); return;
    }
    const s = e.target.closest('[data-set]');
    if (s) { prefs[s.dataset.set] = true; savePrefs(); render(true); return; }
    if (e.target.closest('#cc-ev-more')) { prefs.evAll = !prefs.evAll; savePrefs(); if (M) renderEvents(); }
    if (e.target.closest('#cc-lg-more')) { prefs.lgAll = !prefs.lgAll; savePrefs(); if (M) renderLedger(); }
  });
  root.addEventListener('change', (e) => {
    const i = e.target.closest('input[data-pref]');
    if (i) { prefs[i.dataset.pref] = i.checked; savePrefs(); render(true); }
  });

  const tip = $('cc-tip'), tl = $('cc-timeline');
  function showTip(s, x, y) {
    const d = M.describe(s);
    const range = s.start == null ? 'Present now · start not recorded' : `${M.dayhm(s.start)} → ${s.end == null ? 'now' : M.dayhm(s.end)} · ${M.dur(lastOf(s) - s.start)}`;
    tip.innerHTML = `<div class="t-h"><i class="cc-sw cc-p-${s.proto}"></i>${PROTO[s.proto].name} · ${esc(M.clientName(s))}</div><div class="t-m">${esc(s.ip)} · ${STATE[s.state]}</div><div>${esc(range)}</div><div class="t-d">${d.bits.map(esc).join(' · ')}</div>${d.chips.length ? `<div class="t-d">${d.chips.map((c) => esc(c[0])).join(' · ')}</div>` : ''}`;
    tip.hidden = false;
    const r = tip.getBoundingClientRect();
    let L = x + 14, T = y + 14;
    if (L + r.width > innerWidth - 8) L = x - r.width - 14;
    if (T + r.height > innerHeight - 8) T = y - r.height - 14;
    tip.style.left = Math.max(8, L) + 'px'; tip.style.top = Math.max(8, T) + 'px';
  }
  tl.addEventListener('pointerenter', () => { tlBusy = true; });
  tl.addEventListener('pointerleave', () => { tlBusy = false; tip.hidden = true; });
  tl.addEventListener('pointermove', (e) => { const b = e.target.closest('[data-i]'); if (!b) { tip.hidden = true; return; } showTip(TL[+b.dataset.i], e.clientX, e.clientY); });
  tl.addEventListener('focusin', (e) => { tlBusy = true; const b = e.target.closest('[data-i]'); if (!b) return; const r = b.getBoundingClientRect(); showTip(TL[+b.dataset.i], r.right, r.top); });
  tl.addEventListener('focusout', () => { tlBusy = false; tip.hidden = true; });
  window.addEventListener('scroll', () => { tip.hidden = true; }, { passive: true });

  // Actions (docs/specs/ACTIONS.md): confirm, then POST to action.php with the csrf_token of the page.
  function confirmBox(title, text, label, onYes) {
    if (typeof swal === 'function') {
      swal({ title, text, type: 'warning', showCancelButton: true, confirmButtonText: label, cancelButtonText: 'Cancel', closeOnConfirm: true }, (yes) => { if (yes) onYes(); });
    } else if (window.confirm(`${title}\n\n${text}`)) {
      onYes();
    }
  }
  let actTimer = null;
  function actMessage(text, bad) {
    const el = $('cc-act-msg');
    el.textContent = text;
    el.classList.toggle('bad', !!bad);
    el.hidden = false;
    clearTimeout(actTimer);
    actTimer = setTimeout(() => { el.hidden = true; }, 12000);
  }
  function runAction(d) {
    confirmBox(`${d.label}: ${d.what}?`, d.warn, d.label, async () => {
      try {
        const body = new URLSearchParams({ action: d.act, target: d.target, csrf_token: window.csrf_token || '' });
        const r = await fetch(ACTION_URL, { method: 'POST', body, credentials: 'same-origin' });
        const txt = await r.text();
        let res;
        try { res = JSON.parse(txt); } catch (e) {
          res = { ok: false, message: txt ? `HTTP ${r.status}` : 'The webGUI refused the request (security token). Reload the page and try again.' };
        }
        actMessage(res.ok ? res.message : `Not done: ${res.message || res.error}`, !res.ok);
      } catch (e) {
        actMessage(`Not done: ${e.message || e}`, true);
      }
      lastPush = 0;
      setTimeout(poll, 1500);   // the collector sees the change in its next poll
    });
  }

  function showBanner(err) {
    const msg = String(err && err.message || err);
    $('cc-banner').innerHTML = /collector_not_running|HTTP 503/.test(msg)
      ? `The collector is not running, so there is no data. Start it on the server: <code>${RC} start</code>`
      : `The page could not read the collector data (${esc(msg)}). It tries again every 5 seconds.`;
    $('cc-banner').hidden = false;
  }
  // Live updates (docs/specs/LIVE_UPDATES.md): nchan pushes each snapshot; state.php is the fallback.
  let timer = null, lastPush = 0, pending = null;
  const intervalMs = () => ((M && M.D.collector && M.D.collector.interval) || POLL_MS / 1000) * 1000;
  const pushed = () => Date.now() - lastPush < 2.5 * intervalMs();
  function apply(data) {
    if (!data || data.error) throw new Error((data && data.error) || 'no data');
    M = window.CCModel.create(data, { ownTag });
    $('cc-banner').hidden = true;
    render(false);
  }
  async function poll() {
    clearTimeout(timer);
    try {
      if (!pushed()) {
        const r = await fetch(STATE_URL, { cache: 'no-store', credentials: 'same-origin' });
        const data = await r.json().catch(() => ({ error: `HTTP ${r.status}` }));
        if (!r.ok || data.error) throw new Error(data.error || `HTTP ${r.status}`);
        apply(data);
      }
    } catch (e) {
      showBanner(e);
    } finally {
      if (!document.hidden) timer = setTimeout(poll, intervalMs());
    }
  }
  if (typeof NchanSubscriber === 'function') {
    try {
      const nc = new NchanSubscriber('/sub/connections', { subscriber: 'websocket', reconnectTimeout: 5000 });
      nc.on('message', (msg) => {
        let data;
        try { data = JSON.parse(msg); } catch (e) { return; }   // a bad message: the poll fallback covers it
        lastPush = Date.now();
        if (document.hidden) { pending = data; return; }
        try { apply(data); } catch (e) { showBanner(e); }
      });
      nc.start();
    } catch (e) { /* no nchan: polling only */ }
  }
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { clearTimeout(timer); return; }
    if (pending) { const d = pending; pending = null; try { apply(d); } catch (e) { showBanner(e); } }
    poll();
  });
  // ACTIONS.md: the viewer's own session tag, so the page marks "This browser" and shows no sign-out for it.
  fetch(ACTION_URL, { cache: 'no-store', credentials: 'same-origin' }).then((r) => r.json()).then((j) => {
    ownTag = (j && j.own_tag) || null;
    if (M) { M = window.CCModel.create(M.D, { ownTag }); render(true); }
  }).catch(() => { /* no tag: the server still refuses the own session */ });
  syncControls();
  poll();
}());
