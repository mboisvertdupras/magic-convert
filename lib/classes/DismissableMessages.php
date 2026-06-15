<?php

namespace MagicConvert;

use \MagicConvert\Option;
use \MagicConvert\State;
use \MagicConvert\Messenger;

class DismissableMessages
{

    /**
     *  Add dismissible message.
     *
     *  @param  string  $id  An identifier, ie "suggest_enable_pngs"
     */
    public static function addDismissableMessage($id)
    {
        $dismissableMessageIds = State::getState('dismissableMessageIds', []);

        // Ensure we do not add a message that is already there
        if (in_array($id, $dismissableMessageIds)) {
            return;
        }
        $dismissableMessageIds[] = $id;
        State::setState('dismissableMessageIds', $dismissableMessageIds);
    }

    public static function printDismissableMessage($level, $msg, $id, $gotItText = '')
    {
        if ($gotItText != '') {
            $javascript = "jQuery(this).closest('div.notice').slideUp();";
            //$javascript = "console.log(jQuery(this).closest('div.notice'));";
            $javascript .= "jQuery.post(ajaxurl, {'action': 'magicconvert_dismiss_message', 'id': '" . $id . "'});";

            $msg .= '<button type="button" class="button button-primary" onclick="' . $javascript . '" style="display:block; margin-top:20px">' . $gotItText . '</button>';
        }
        Messenger::printMessage($level, $msg);
    }

    public static function printMessages()
    {
        $ids = State::getState('dismissableMessageIds', []);
        foreach ($ids as $id) {
            if (str_contains($id, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $id)) {
                self::dismissMessage($id);
                continue;
            }
            $messageFile = __DIR__ . '/../dismissable-messages/' . $id . '.php';
            if (!file_exists($messageFile)) {
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
        $messages = State::getState('dismissableMessageIds', []);
        $newQueue = [];
        foreach ($messages as $mid) {
            if ($mid == $id) {

            } else {
                $newQueue[] = $mid;
            }
        }
        State::setState('dismissableMessageIds', $newQueue);
    }

    /**
     *  Dismiss message
     *
     *  @param  string  $id  An identifier, ie "suggest_enable_pngs"
     */
    public static function dismissAll() {
        State::setState('dismissableMessageIds', []);
    }

    public static function processAjaxDismissMessage() {
        /*
        We have no security nonce here
        Dismissing a message is not harmful and dismissMessage($id) do anything harmful, no matter what you send in the "id"
        */
        $id = sanitize_text_field($_POST['id']);
        self::dismissMessage($id);
    }


}
