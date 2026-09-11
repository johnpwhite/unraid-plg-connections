<?php
/**
 * <module_context>
 *   <name>cc-logs</name>
 *   <description>Reads webGUI and sshd lines from syslog (current, rotated, previous boot)
 *   and parses them into sign-in events and SSH sessions.</description>
 *   <dependencies>cc-common.php, grep</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once __DIR__ . '/cc-common.php';

/**
 * Syslog lines about webGUI sign-ins and sshd, oldest first.
 * The previous-boot log is on flash and never changes, so it is read once and kept in $state.
 */
function cc_syslog_lines(array &$state): array
{
    $pattern = "'webgui: |Successful logout|sshd(-session)?\\['";
    if (!isset($state['previous'])) {
        $prev = '/boot/logs/syslog-previous';
        $state['previous'] = is_readable($prev) ? cc_lines(cc_run("grep -hE $pattern " . escapeshellarg($prev))) : [];
    }
    $rotated = glob('/var/log/syslog.[0-9]') ?: [];
    rsort($rotated);
    $files = array_filter(array_merge($rotated, ['/var/log/syslog']), 'is_readable');
    $current = $files ? cc_lines(cc_run("grep -hE $pattern " . implode(' ', array_map('escapeshellarg', $files)))) : [];
    return array_merge($state['previous'], $current);
}

/** Parse syslog lines into [events, ssh sessions]. */
function cc_parse_syslog(array $lines, int $now): array
{
    $events = [];
    $ssh = [];
    $open = [];   // "ip:port" -> index in $ssh
    foreach ($lines as $line) {
        if (!preg_match('/^(\w{3}\s+\d+\s[\d:]{8})\s\S+\s(.*)$/', $line, $m)) {
            continue;
        }
        $t = cc_ts($m[1], $now);
        $msg = $m[2];
        // The failed line ends "from <ip>. <cooldown text>", so the IP pattern stops before a trailing dot.
        if (preg_match('/^webgui: (Successful|Unsuccessful) login user (.*?) from ([0-9A-Fa-f:.]+?)\.?(?=\s|$)/', $msg, $x)) {
            $events[] = ['t' => $t, 'proto' => 'web', 'type' => $x[1] === 'Successful' ? 'login' : 'login_failed', 'user' => $x[2], 'ip' => $x[3]];
        } elseif (preg_match('/Successful logout user (.*?) from ([0-9A-Fa-f:.]+?)\.?(?=\s|$)/', $msg, $x)) {
            $events[] = ['t' => $t, 'proto' => 'web', 'type' => 'logout', 'user' => $x[1], 'ip' => $x[2]];
        } elseif (preg_match('/^sshd(?:-session)?\[(\d+)\]: Accepted (\S+) for (\S+) from (\S+) port (\d+) ssh2(?:: (\S+) SHA256:(\S+))?/', $msg, $x)) {
            $ssh[] = [
                'ip' => $x[4], 'port' => (int) $x[5], 'user' => $x[3], 'method' => $x[2],
                'key_type' => $x[6] ?? null, 'key' => isset($x[7]) ? substr($x[7], 0, 10) : null,
                'start' => $t, 'end' => null, 'pid' => (int) $x[1],
                'shell' => 0, 'commands' => 0, 'sftp' => 0,
            ];
            $open["$x[4]:$x[5]"] = count($ssh) - 1;
            $events[] = ['t' => $t, 'proto' => 'ssh', 'type' => 'login', 'user' => $x[3], 'ip' => $x[4], 'detail' => $x[2]];
        } elseif (preg_match('/^sshd(?:-session)?\[\d+\]: Disconnected from user (\S+) (\S+) port (\d+)/', $msg, $x)) {
            $key = "$x[2]:$x[3]";
            if (isset($open[$key])) {
                $ssh[$open[$key]]['end'] = $t;
                unset($open[$key]);
            }
            $events[] = ['t' => $t, 'proto' => 'ssh', 'type' => 'logout', 'user' => $x[1], 'ip' => $x[2]];
        } elseif (preg_match("/^sshd(?:-session)?\\[\\d+\\]: Starting session: (shell|command|subsystem 'sftp') .*?for (\\S+) from (\\S+) port (\\d+)/", $msg, $x)) {
            $key = "$x[3]:$x[4]";
            if (isset($open[$key])) {
                $field = $x[1] === 'shell' ? 'shell' : ($x[1] === 'command' ? 'commands' : 'sftp');
                $ssh[$open[$key]][$field]++;
            }
        } elseif (preg_match('/^sshd(?:-session)?\[\d+\]: Failed (\S+) for (?:invalid user )?(\S+) from (\S+) port (\d+)/', $msg, $x)) {
            $events[] = ['t' => $t, 'proto' => 'ssh', 'type' => 'login_failed', 'user' => $x[2], 'ip' => $x[3], 'detail' => $x[1]];
        }
    }
    return [$events, $ssh];
}
