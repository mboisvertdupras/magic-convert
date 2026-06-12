<?php

namespace MagicConvert;

include 'autoloader.php';

WebPRealizer::preventDirectAccess('webp-realizer.php');
WebPRealizer::processRequest();
