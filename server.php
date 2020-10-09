<?php

include "vendor/autoload.php";

use Inbenta\MicrosoftTeamsConnector\MicrosoftTeamsConnector;

// Instance new connector
$appPath = __DIR__ . '/';
$app = new MicrosoftTeamsConnector($appPath);



//Handle the incoming request
$app->handleRequest();
