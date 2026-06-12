<?php

namespace MagicConvert;

class DestinationOptions
{

    public $mingled;
    public $useDocRoot;
    public $replaceExt;
    public $scope;

    /** @var OutputFormat  Target output format. Defaults to webp for backward compat. */
    public $format;

    /**
     * Constructor.
     *
     * @param  boolean                   $mingled
     * @param  boolean                   $useDocRoot
     * @param  boolean                   $replaceExt
     * @param  mixed                     $scope
     * @param  OutputFormat|string|null  $format      Output format (defaults to webp).
     */
    public function __construct($mingled, $useDocRoot, $replaceExt, $scope, $format = null)
    {
        $this->mingled = $mingled;
        $this->useDocRoot = $useDocRoot;
        $this->replaceExt = $replaceExt;
        $this->scope = $scope;
        $this->format = OutputFormat::coerce($format);
    }

    /**
     * Set properties from config file
     *
     * @param  array                     $config   Magic Convert configuration object
     * @param  OutputFormat|string|null  $format   Output format (defaults to webp).
     */
    public static function createFromConfig(&$config, $format = null)
    {
        return new DestinationOptions(
            $config['destination-folder'] == 'mingled',       // "mingled" or "separate"
            $config['destination-structure'] == 'doc-root',   // "doc-root" or "image-roots"
            $config['destination-extension'] == 'set',        // "set" or "append"
            $config['scope'],
            $format
        );
    }


}
