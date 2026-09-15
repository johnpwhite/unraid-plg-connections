<?php
/**
 * <module_context>
 *   <name>cc-settings</name>
 *   <description>One save path for the settings page and save.php (docs/specs/SETTINGS_PAGE_DESIGN.md):
 *   the settings from the form go to settings.ini, and the rules go to subscriptions.json only
 *   when the form carries them, so a POST from another form can never wipe the rules.</description>
 *   <dependencies>cc-config.php, cc-subs.php</dependencies>
 * </module_context>
 */

declare(strict_types=1);

require_once __DIR__ . '/cc-config.php';
require_once __DIR__ . '/cc-subs.php';

/**
 * Save the settings form. Returns ['ok', 'saved', 'rules_saved', 'stale', 'message'].
 * 'rules_saved' is null when the form carried no rules. 'stale' is true when the form carried a
 * rules_version that is not the version of the file now: another page (or an update) changed the
 * rules since this page was loaded, so the rules part is refused and the settings part still saves
 * (Forgejo #14: a stale form once wiped the seeded defaults).
 */
function cc_settings_apply(array $post): array
{
    $saved = cc_config_write(cc_settings_from_post($post));
    $rulesSaved = null;
    $stale = false;
    if (isset($post['rule']) && is_array($post['rule'])) {
        $version = (string) ($post['rules_version'] ?? '');
        if ($version !== '' && $version !== cc_subs_version()) {
            $stale = true;
        } else {
            $rulesSaved = cc_subs_write(cc_subs_from_post($post));
        }
    }
    $ok = $saved && $rulesSaved !== false && !$stale;
    if ($stale) {
        $message = 'The settings were saved, but not the rules: they changed since you opened this page (another tab, or an update). Reload the page, then apply again.';
    } elseif ($ok) {
        $message = 'Saved. The collector uses the new settings within a few seconds.';
    } else {
        $message = !$saved ? 'The settings could not be saved. Try again.' : 'The rules could not be saved. Try again.';
    }
    return ['ok' => $ok, 'saved' => $saved, 'rules_saved' => $rulesSaved, 'stale' => $stale, 'message' => $message];
}
