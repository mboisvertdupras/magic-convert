<?php

namespace MagicConvert;

use \MagicConvert\Option;
use \MagicConvert\State;
use \MagicConvert\Messenger;

class DismissableGlobalMessages
{

    /**
     *  Add dismissible message.
     *
     *  @param  string  $id  An identifier, ie "suggest_enable_pngs"
     */
    public static function addDismissableMessage($id)
    {
        $dismissableGlobalMessageIds = State::getState('dismissableGlobalMessageIds', []);

        // Ensure we do not add a message that is already there
        if (in_array($id, $dismissableGlobalMessageIds)) {
            return;
        }
        $dismissableGlobalMessageIds[] = $id;
        State::setState('dismissableGlobalMessageIds', $dismissableGlobalMessageIds);
    }

    public static function printDismissableMessage($level, $msg, $id, $buttons)
    {
        $msg .= '<br><br>';
        foreach ($buttons as $i => $button) {
            $javascript = "jQuery(this).closest('div.notice').slideUp();";
            //$javascript = "console.log(jQuery(this).closest('div.notice'));";
            $javascript .= "jQuery.post(ajaxurl, " .
                "{'action': 'magicconvert_dismiss_global_message', " .
                "'id': '" . $id . "'})";
            if (isset($button['javascript'])) {
                $javascript .= ".done(function() {" . $button['javascript'] . "});";
            }
            if (isset($button['redirect-to-settings'])) {
                $javascript .= ".done(function() {location.href='" . Paths::getSettingsUrl() . "'});";
            }

            $msg .= '<button type="button" class="button ' .
                (($i == 0) ? 'button-primary' : '') .
                '" onclick="' . $javascript . '" ' .
                'style="display:inline-block; margin-top:20px; margin-right:20px; ' . (($i > 0) ? 'float:right;' : '') .
                '">' . $button['text'] . '</button>';

        }
        Messenger::printMessage($level, $msg);
    }

    public static function printMessages()
    {
        $ids = State::getState('dismissableGlobalMessageIds', []);
        foreach ($ids as $id) {
            // $id comes from stored state - guard against path traversal before building a path from it
            if (str_contains($id, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $id)) {
                self::dismissMessage($id);
                continue;
            }
            $messageFile = __DIR__ . '/../dismissable-global-messages/' . $id . '.php';
            if (!file_exists($messageFile)) {
                // Stale id (the message file has been removed) - silently drop it from state
                self::dismissMessage($id);
                continue;
            }
            include_once $messageFile;
        }
    }

    /**
     *  Dismiss message
     *
     *  @param  string  $id  An identifier, ie "suggest_enable_pngs"
     */
    public static function dismissMessage($id) {
        $messages = State::getState('dismissableGlobalMessageIds', []);
        $newQueue = [];
        foreach ($messages as $mid) {
            if ($mid == $id) {

            } else {
                $newQueue[] = $mid;
            }
        }
        State::setState('dismissableGlobalMessageIds', $newQueue);
    }

    /**
     *  Dismiss message
     *
     *  @param  string  $id  An identifier, ie "suggest_enable_pngs"
     */
    public static function dismissAll() {
        State::setState('dismissableGlobalMessageIds', []);
    }

    public static function processAjaxDismissGlobalMessage() {
        /*
        We have no security nonce here
        Dismissing a message is not harmful and dismissMessage($id) do anything harmful, no matter what you send in the "id"
        */
        $id = sanitize_text_field($_POST['id']);
        self::dismissMessage($id);
    }


}
