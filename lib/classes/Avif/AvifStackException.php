<?php

namespace MagicConvert\Avif;

/**
 * Thrown by AvifStack when no converter in the stack could encode the image.
 *
 * Carries the per-converter failure reasons so the caller (and the self-test page)
 * can render them however they like, in addition to the human-readable aggregate
 * message.
 */
class AvifStackException extends \Exception
{
    /** @var array<string,string>  converter-id => failure reason. */
    private $reasons;

    /**
     * @param string                $message  aggregate human-readable message.
     * @param array<string,string>  $reasons  per-converter reasons.
     */
    public function __construct($message, array $reasons = [])
    {
        parent::__construct($message);
        $this->reasons = $reasons;
    }

    /**
     * @return array<string,string>  converter-id => failure reason.
     */
    public function getReasons()
    {
        return $this->reasons;
    }
}
