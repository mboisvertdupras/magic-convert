<?php

namespace MagicConvert\Avif;

use MagicConvert\Format\FormatEncodeException;

class AvifStackException extends FormatEncodeException
{
    /**
     * @param string                $message
     * @param array<string,string>  $reasons
     */
    public function __construct($message, array $reasons = [])
    {
        parent::__construct($message, $reasons);
    }
}
