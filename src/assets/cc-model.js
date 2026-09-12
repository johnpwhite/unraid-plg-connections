/*
 * <module_context>
 *   <name>cc-model</name>
 *   <description>Turns one collector snapshot into a list of sessions (all protocols) and
 *   formatting helpers. Times show in the server's time zone. No DOM access.</description>
 * </module_context>
 */
(function () {
  'use strict';
  const PROTO = {
    web: { name: 'Web UI', port: ':443', unit: ['session', 'sessions'] },
    ssh: { name: 'SSH', port: ':22', unit: ['session', 'sessions'] },
    smb: { name: 'SMB', port: ':445', unit: ['session', 'sessions'] },
    nfs: { name: 'NFS', port: ':2049', unit: ['client', 'clients'] },
    vpn: { name: 'VPN', port: 'wg · ts', unit: ['peer', 'peers'] },
  };
  const ORDER = ['web', 'ssh', 'smb', 'nfs', 'vpn'];
  const KIND = { lan: 'LAN', docker: 'Docker', self: 'This server', tailscale: 'Tailscale', public: 'Internet', loopback: 'Loopback', unknown: 'Unknown' };
  const STATE = { active: 'Active', idle: 'Idle', stale: 'Stale', ended: 'Ended' };
  const RANK = { active: 0, idle: 1, stale: 2, ended: 3 };
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const plural = (n, w, ws) => `${n} ${n === 1 ? w : (ws || w + 's')}`;

  function create(D, opts) {
    const ownTag = (opts && opts.ownTag) || null;   // the viewer's own web session (ACTIONS.md)
    const TZ = (D.host && D.host.tz) || undefined;
    const NOW = D.generated_at;
    const tzOpt = TZ ? { timeZone: TZ } : {};
    const mk = (o, loc) => new Intl.DateTimeFormat(loc, Object.assign({}, tzOpt, o));
    const F_HM = mk({ hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
    const F_HMS = mk({ hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
    const F_DAY = mk({ weekday: 'short', day: 'numeric', month: 'short' });
    const F_PARTS = mk({ weekday: 'short', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }, 'en-GB');
    const date = (t) => new Date(t * 1000);
    const parts = (t) => { const o = {}; for (const p of F_PARTS.formatToParts(date(t))) o[p.type] = p.value; return o; };
    const hm = (t) => F_HM.format(date(t));
    const hms = (t) => F_HMS.format(date(t));
    const dayLabel = (t) => F_DAY.format(date(t)).replace(',', '');
    const sameDay = (a, b) => { const p = parts(a), q = parts(b); return p.year === q.year && p.month === q.month && p.day === q.day; };
    const dayhm = (t) => (sameDay(t, NOW) ? 'today ' : dayLabel(t) + ' ') + hm(t);
    const dur = (s) => {
      s = Math.max(0, Math.round(s));
      if (s < 60) return s + ' s';
      const m = Math.floor(s / 60); if (m < 60) return m + ' min';
      const h = Math.floor(m / 60); if (h < 48) return h + ' h ' + (m % 60) + ' min';
      return Math.floor(h / 24) + ' d ' + (h % 24) + ' h';
    };
    const ago = (t) => dur(NOW - t) + ' ago';
    // Samba's start time: read the wall-clock part in the server zone (its offset is not trusted).
    const wallToEpoch = (str) => {
      const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})/.exec(str || '');
      if (!m) return null;
      const guess = Date.UTC(+m[1], m[2] - 1, +m[3], +m[4], +m[5], +m[6]) / 1000;
      const p = parts(guess);
      return guess - (Date.UTC(+p.year, p.month - 1, +p.day, +p.hour, +p.minute, +p.second) / 1000 - guess);
    };
    const zone = (mk({ timeZoneName: 'short' }).formatToParts(date(NOW)).find((p) => p.type === 'timeZoneName') || {}).value || '';

    const CL = D.clients || {};
    const info = (ip) => CL[ip] || { kind: ip ? 'lan' : 'unknown', name: null, mac: null, container: null };
    const cname = (ip) => {
      if (!ip) return 'Unknown client';
      const c = info(ip);
      if (c.label) return c.label;   // a user label wins (CLIENT_IDENTITY.md)
      if (c.kind === 'self') return (D.host.name || 'Server') + ' (this server)';
      if (c.kind === 'docker') return c.container || 'Docker container';
      return c.name ? c.name.replace(/\.(localdomain|local|lan|home\.arpa)$/i, '') : ip;
    };
    const dnsName = (ip) => { const c = info(ip); return c.name ? c.name.replace(/\.(localdomain|local|lan|home\.arpa)$/i, '') : ''; };
    const isLocal = (ip) => ['self', 'docker', 'loopback'].includes(info(ip).kind);
    const kindTitle = (c) => ({ lan: 'Private LAN address', self: 'This Unraid server', tailscale: 'Tailscale address',
      public: 'Public internet address. Check this client.', loopback: 'Loopback',
      docker: c.container ? `Container ${c.container} on this server` : 'Docker bridge address. The container no longer runs.' })[c.kind] || '';
    const via = (iface) => {
      if (!iface) return null;
      if (/^(shim-)?(br|eth|bond)\d/.test(iface)) return 'LAN';
      if (/^tailscale/.test(iface)) return 'Tailscale';
      if (/^wg/.test(iface)) return 'WireGuard';
      if (/^(docker|br-)/.test(iface)) return 'Docker bridge';
      return iface === 'lo' ? 'loopback' : iface;
    };

    const S = [];
    for (const r of (D.web && D.web.sessions) || []) {
      S.push({ proto: 'web', ip: r.ip, state: r.state, start: r.login_at ?? r.unraid_login, unsure: !['exact', 'recorded'].includes(r.match), end: r.state === 'active' ? null : r.last_request, raw: r });
    }
    for (const r of (D.ssh && D.ssh.sessions) || []) {
      S.push({ proto: 'ssh', ip: r.ip, state: r.state === 'active' ? 'active' : 'ended', start: r.start, unsure: r.start == null, end: r.state === 'active' ? null : (r.end ?? r.start), raw: r });
    }
    for (const r of (D.smb && D.smb.sessions) || []) S.push({ proto: 'smb', ip: r.ip, state: 'active', start: wallToEpoch(r.created_raw), unsure: true, end: null, raw: r });
    for (const r of (D.nfs && D.nfs.clients) || []) S.push({ proto: 'nfs', ip: r.ip, state: r.status === 'confirmed' ? 'active' : 'idle', start: null, unsure: true, end: null, raw: r });
    for (const r of (D.wireguard && D.wireguard.peers) || []) {
      if (!r.endpoint || !r.last_handshake) continue;
      const fresh = NOW - r.last_handshake < 180;
      S.push({ proto: 'vpn', ip: r.endpoint, state: fresh ? 'active' : 'idle', start: null, unsure: true, end: fresh ? null : r.last_handshake, raw: Object.assign({ kind: 'wg' }, r) });
    }
    for (const r of (D.tailscale && D.tailscale.peers) || []) {
      if (r.active) S.push({ proto: 'vpn', ip: r.ips[0], state: 'active', start: null, unsure: true, end: null, raw: Object.assign({ kind: 'ts' }, r) });
    }
    // Ended sessions from the history database (docs/specs/HISTORY.md): the timeline survives a reboot.
    for (const h of (D.history && D.history.ended) || []) {
      const raw = Object.assign({}, h.raw || {}, { state: 'ended' });
      if (h.proto === 'vpn' && !raw.kind) raw.kind = String(h.skey).startsWith('ts:') ? 'ts' : 'wg';
      S.push({ proto: h.proto, ip: h.ip, state: 'ended', start: h.start, unsure: false, end: h.end, raw, fromHistory: true });
    }
    const clientName = (s) => (s.raw && s.raw.kind === 'ts' ? s.raw.name : cname(s.ip));

    const smbDialect = (d) => { const m = /^SMB(\d)_(\d)(\d)$/.exec(d || ''); if (!m) return d || '?'; return m[3] === '0' ? `${m[1]}.${m[2]}` : `${m[1]}.${m[2]}.${m[3]}`; };
    function describe(s) {
      const r = s.raw, bits = [], chips = [];
      if (s.proto === 'web') {
        bits.push(r.user);
        if (s.state === 'active') bits.push(`${plural(r.connections || 0, 'socket')} via ${via(r.via) || '?'}`);
        bits.push(`last request ${ago(r.last_request)}`, `session ${r.tag}`);
        if (r.match === 'inferred') chips.push(['IP inferred', 'The collector did not see this session when it was new. It matched the only session in use to the only live client.']);
        if (r.match === 'ambiguous') chips.push(['IP ambiguous', 'Two sign-ins for this user in the same second.']);
        if (r.match === 'ambiguous' && Array.isArray(r.candidates)) bits.push(`candidates ${r.candidates.join(', ')}`);
        if (r.match === 'none') chips.push(['IP unknown', 'No syslog sign-in line matched this session.']);
        if (ownTag && r.tag === ownTag) chips.push(['This browser', 'You use this session now.']);
      } else if (s.proto === 'ssh') {
        bits.push(r.user || 'user unknown');
        if (r.method) bits.push(`${r.method}${r.key_type ? ' ' + r.key_type : ''}${r.key ? ' ' + r.key + '…' : ''}`);
        if (r.shell) bits.push('interactive shell');
        if (r.commands) bits.push(plural(r.commands, 'command'));
        if (r.sftp) bits.push(plural(r.sftp, 'SFTP session'));
        if (r.via) bits.push(`via ${via(r.via)}`);
      } else if (s.proto === 'smb') {
        bits.push(`user ${r.user}`, r.shares.length ? `share ${r.shares.map((x) => x.share).join(', ')}` : 'no share open', `SMB ${smbDialect(r.dialect)}`, `signing ${r.signing}`);
        if (r.encryption === 'none') chips.push(['Not encrypted', 'SMB traffic to this client is not encrypted.']);
      } else if (s.proto === 'nfs') {
        bits.push(`“${r.name}”`, `NFS v${r.version}`, `lease renewed ${r.last_renew} s ago`);
      } else if (s.proto === 'vpn') {
        bits.push(r.kind === 'ts' ? `Tailscale ${r.os || ''}`.trim() : `WireGuard ${r.iface}`);
        if (r.kind === 'ts') bits.push(r.direct ? 'direct' : `relay ${r.relay || '?'}`);
      }
      return { bits, chips };
    }
    const since = (s) => {
      if (s.start == null || (s.proto === 'nfs' && !s.fromHistory)) return { text: 'start not recorded', sub: '' };
      const pre = s.proto === 'web' ? 'signed in ' : s.proto === 'smb' ? 'since ~' : 'started ';
      return { text: pre + dayhm(s.start), sub: dur((s.end ?? NOW) - s.start) };
    };
    // Actions (docs/specs/ACTIONS.md): the button for a session, or null. The server checks each request again.
    const ACT = {
      web: ['web_logout', 'Sign out', 'The browser must sign in again.'],
      ssh: ['ssh_end', 'End', 'The SSH connection closes. Unsaved work in that session is lost.'],
      smb: ['smb_close', 'Close', 'Files open on the client can lose data. The client can connect again.'],
    };
    const actionFor = (s) => {
      const a = ACT[s.proto];
      if (!a || s.fromHistory || (D.config || {}).actions === 'no') return null;
      const r = s.raw || {}, who = `${r.user || '?'} from ${s.ip || 'an unknown address'}`;
      let target, what;
      if (s.proto === 'web') {
        if (!['active', 'idle', 'stale'].includes(s.state) || !r.tag || r.tag === ownTag) return null;
        target = r.tag; what = `web UI session ${r.tag} of ${who}`;
      } else {
        if (!r.pid || (s.proto === 'ssh' && s.state !== 'active')) return null;
        target = String(r.pid); what = `${PROTO[s.proto].name} session of ${who} (PID ${r.pid})`;
      }
      return { act: a[0], label: a[1], warn: a[2], target, what };
    };
    const countByProto = (list) => ORDER.map((p) => { const n = list.filter((s) => s.proto === p).length; return n ? plural(n, `${PROTO[p].name} session`) : ''; }).filter(Boolean).join(', ');

    return { D, NOW, zone, S, sources: D.sources || {}, config: D.config || {}, dnsName, parts, hm, hms, dayLabel, dayhm, dur, info, cname, isLocal, kindTitle, clientName, describe, since, countByProto, actionFor, ownTag };
  }

  window.CCModel = { create, PROTO, ORDER, KIND, STATE, RANK, esc, plural };
}());
