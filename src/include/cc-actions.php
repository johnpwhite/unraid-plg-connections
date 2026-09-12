<?php
/**
 * <module_context>
 *   <name>cc-actions</name>
 *   <description>Actions (docs/specs/ACTIONS.md): sign out a web UI session, end an SSH
 *   session, close an SMB session. cc_action_plan() checks each request against the latest
 *   snapshot and the process table before anything happens (pure: the process reader is a
 *   parameter). cc_action_run() does the action and logs it to syslog. The viewer's own
 *   web session is never a target, and a PID must belong to a per-connection process
 *   (never the SSH listener or the Samba master).</description>
 *   <dependencies>cc-common.php (cc_tag, CC_SESS_DIR, cc_run)</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once __DIR__ . '/cc-common.php';

defined('CC_ACTIONS') || define('CC_ACTIONS', ['web_logout', 'ssh_end', 'smb_close']);

/** The tag of the viewer's own web session (from the webGUI session cookie), or null. */
function cc_action_own_tag(array $cookies, string $sessionName): ?string
{
    $id = (string) ($cookies[$sessionName] ?? '');
    return preg_match('/^[A-Za-z0-9,-]{1,128}$/', $id) ? cc_tag('sess_' . $id) : null;
}

/** The process name of a PID and of its parent, from /proc ('' when there is no such process). */
function cc_action_proc(int $pid): array
{
    $read = static function (int $p): array {
        $comm = trim((string) @file_get_contents("/proc/$p/comm"));
        $stat = (string) @file_get_contents("/proc/$p/stat");
        $ppid = preg_match('/^\d+ \(.*\) \S (\d+)/s', $stat, $m) ? (int) $m[1] : 0;
        return [$comm, $ppid];
    };
    [$comm, $ppid] = $read($pid);
    return ['comm' => $comm, 'ppid_comm' => $ppid > 0 ? $read($ppid)[0] : ''];
}

/**
 * Check one request. Returns ['ok' => true, 'action', 'target', 'tag' or 'pid', 'what'] or
 * ['ok' => false, 'error', 'message']. $proc(int $pid) returns ['comm' => .., 'ppid_comm' => ..].
 */
function cc_action_plan(string $action, string $target, array $snap, ?string $ownTag, callable $proc): array
{
    $no = static fn(string $e, string $m): array => ['ok' => false, 'error' => $e, 'message' => $m];
    if (!in_array($action, CC_ACTIONS, true)) {
        return $no('bad_action', 'Unknown action.');
    }
    if ($action === 'web_logout') {
        if (!preg_match('/^[0-9a-f]{8}$/', $target)) {
            return $no('bad_target', 'The session tag is not valid.');
        }
        if ($ownTag !== null && hash_equals($ownTag, $target)) {
            return $no('own_session', 'This is your own session. Use the sign-out item of the webGUI instead.');
        }
        foreach ($snap['web']['sessions'] ?? [] as $s) {
            if (($s['tag'] ?? '') === $target) {
                return ['ok' => true, 'action' => $action, 'target' => $target, 'tag' => $target,
                    'what' => sprintf('web UI session %s of %s from %s', $target, $s['user'] ?? '?', ($s['ip'] ?? '') ?: 'an unknown address')];
            }
        }
        return $no('not_found', 'No web UI session has this tag now.');
    }
    if (!preg_match('/^[1-9]\d{0,9}$/', $target)) {
        return $no('bad_target', 'The process ID is not valid.');
    }
    $pid = (int) $target;
    $ssh = $action === 'ssh_end';
    $list = $ssh
        ? array_filter($snap['ssh']['sessions'] ?? [], static fn($s) => ($s['state'] ?? '') === 'active')
        : ($snap['smb']['sessions'] ?? []);
    foreach ($list as $s) {
        if ((int) ($s['pid'] ?? 0) !== $pid) {
            continue;
        }
        $p = $proc($pid);
        $comm = (string) ($p['comm'] ?? '');
        $parent = (string) ($p['ppid_comm'] ?? '');
        // A per-connection process only: sshd-session, or an sshd/smbd child of the daemon.
        $right = $ssh
            ? ($comm === 'sshd-session' || ($comm === 'sshd' && $parent === 'sshd'))
            : (str_starts_with($comm, 'smbd[') || ($comm === 'smbd' && $parent === 'smbd'));
        if (!$right) {
            return $no('wrong_process', "PID $pid is not the " . ($ssh ? 'SSH' : 'SMB') . ' process of this session.');
        }
        return ['ok' => true, 'action' => $action, 'target' => $target, 'pid' => $pid,
            'what' => sprintf('%s session of %s from %s (PID %d)', $ssh ? 'SSH' : 'SMB', $s['user'] ?? '?', ($s['ip'] ?? '') ?: 'an unknown address', $pid)];
    }
    return $no('not_found', 'No ' . ($ssh ? 'active SSH' : 'SMB') . ' session has this process ID now.');
}

/** Do a checked action. $actor names who asked (for the syslog line). */
function cc_action_run(array $plan, string $actor, string $sessDir = CC_SESS_DIR, bool $log = true): array
{
    $done = null;
    if ($plan['action'] === 'web_logout') {
        foreach (glob($sessDir . '/sess_*') ?: [] as $f) {
            if (hash_equals((string) $plan['tag'], cc_tag(basename($f)))) {
                $done = @unlink($f) ? 'Signed out' : false;
                break;
            }
        }
        if ($done === null) {
            return ['ok' => false, 'error' => 'gone', 'message' => 'The session ended before the sign-out.'];
        }
    } else {
        $done = @posix_kill((int) $plan['pid'], 15) ? ($plan['action'] === 'ssh_end' ? 'Ended' : 'Closed') : false;   // 15 = SIGTERM
    }
    if ($done === false) {
        return ['ok' => false, 'error' => 'failed', 'message' => 'The server could not do the action on the ' . $plan['what'] . '.'];
    }
    if ($log) {
        cc_run('logger -t unraid-connections ' . escapeshellarg("action: $done {$plan['what']} (asked by $actor)"));
    }
    return ['ok' => true, 'message' => "$done the {$plan['what']}."];
}
