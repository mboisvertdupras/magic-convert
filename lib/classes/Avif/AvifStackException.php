<?php

namespace MagicConvert\Avif;

class AvifStackException extends \Exception
{
    /** @var array<string,string> */
    private $reasons;

    /**
     * @param array<string,string> $reasons
     */
    public function __construct($message, array $reasons = [])
    {
        parent::__construct($message);
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
