<?php

namespace MagicConvert;

include 'autoloader.php';

WebPOnDemand::preventDirectAccess('webp-on-demand.php');
WebPOnDemand::processRequest();
