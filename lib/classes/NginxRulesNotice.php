<?php

namespace MagicConvert;

/**
 * NginxRulesNotice — the "your nginx rules need updating" admin notice (Phase 3.2 item 3).
 *
 * MECHANICS (reuses the existing DismissableGlobalMessages machinery):
 *   - The notice is a global dismissable message with the STABLE id below. Its template lives at
 *     lib/dismissable-global-messages/<id>.php and is rendered by the existing
 *     DismissableGlobalMessages::printMessages() hook on admin_notices.
 *   - arm(): when a config save changes the rule-affecting fingerprint AND the platform is nginx,
 *     we (re)add the id to the global-messages queue. Because addDismissableMessage() re-queues an
 *     id that was previously dismissed, the notice REAPPEARS on every fingerprint change — exactly
 *     the required behaviour. On htaccess-capable servers we never arm it, so it never shows.
 *
 * Keeping a stable id (rather than a per-fingerprint id) means the user only ever sees ONE such
 * notice, and dismissing it clears it until the NEXT change re-arms the same id.
 */
class NginxRulesNotice
{
    /** Stable global-message id (also the template path under dismissable-global-messages/). */
    const MESSAGE_ID = 'nginx/rules-need-updating';

    /**
     * Arm the notice when appropriate: nginx platform + the fingerprint actually changed.
     *
     * Called from Config::saveConfigurationAndHTAccess() with the PREVIOUS persisted state record
     * and the freshly computed one (the change decision is NginxPanel::fingerprintChanged, which
     * is pure and unit-tested). Wrapped defensively by the caller so it can never block a save.
     *
     * @param  array|null  $oldRecord  State::getState('nginx-rules') from before this save.
     * @param  array       $newRecord  the record about to be persisted for this save.
     * @param  bool|null   $isNginx    platform override (for tests); defaults to PlatformInfo.
     * @return bool  whether the notice was armed.
     */
    public static function arm($oldRecord, $newRecord, $isNginx = null)
    {
        if ($isNginx === null) {
            $isNginx = PlatformInfo::isNginx();
        }
        if (!$isNginx) {
            return false;
        }
        if (!NginxPanel::fingerprintChanged($oldRecord, $newRecord)) {
            return false;
        }
        DismissableGlobalMessages::addDismissableMessage(self::MESSAGE_ID);
        return true;
    }

    /**
     * Clear the notice (e.g. once the user has regenerated/installed the rules). Currently the
     * notice is self-dismissing via its template button; provided for completeness/symmetry.
     */
    public static function clear()
    {
        DismissableGlobalMessages::dismissMessage(self::MESSAGE_ID);
    }
}
