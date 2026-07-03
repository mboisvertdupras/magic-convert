<?php

namespace MagicConvert;

use \MagicConvert\ValidateException;

class Validate
{

    public static function postHasKey($key)
    {
        if (!isset($_POST[$key])) {
            throw new ValidateException('Expected parameter in POST missing: ' . $key);
        }
    }

}
