<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Es ist am besten, die folgende Zeile zu vermeiden, da sie das Problem verursachen kann.
// if (file_exists(dirname(__DIR__).'/config/bootstrap.php')) {
//     require dirname(__DIR__).'/config/bootstrap.php';
// }

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}
