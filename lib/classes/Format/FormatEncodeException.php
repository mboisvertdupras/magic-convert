<?php

namespace MagicConvert\Format;

class FormatEncodeException extends \Exception
{
    /** @var array<string,string> */
    private $perConverterReasons;

    /**
     * @param string                $message
     * @param array<string,string>  $perConverterReasons
     * @param \Throwable|null        $previous
     */
    public function __construct($message = '', array $perConverterReasons = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->perConverterReasons = $perConverterReasons;
    }

    /**
     * @return array<string,string>
     */
    public function perConverterReasons(): array
    {
        return $this->perConverterReasons;
    }
}
