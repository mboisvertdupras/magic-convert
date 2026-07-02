<?php

namespace MagicConvert\Avif;

use MagicConvert\Format\FormatEncodeException;

class AvifStackException extends FormatEncodeException
{
    /** @var array<string,string> */
    private $reasons;

    /**
     * @param string                $message
     * @param array<string,string>  $reasons
     */
    public function __construct($message, array $reasons = [])
    {
        parent::__construct($message, $reasons);
        $this->reasons = $reasons;
    }

    /**
     * @return array<string,string>
     */
    public function getReasons()
    {
        return $this->reasons;
    }
}
