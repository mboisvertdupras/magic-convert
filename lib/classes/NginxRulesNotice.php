<?php

namespace MagicConvert;

class NginxRulesNotice
{
    const MESSAGE_ID = 'nginx/rules-need-updating';

    /**
     * @param  array|null  $oldRecord
     * @param  array       $newRecord
     * @param  bool|null   $isNginx
     * @return bool
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

    public static function clear()
    {
        DismissableGlobalMessages::dismissMessage(self::MESSAGE_ID);
    }
}
