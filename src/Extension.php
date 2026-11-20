<?php

declare(strict_types=1);

namespace mostlyserious\railway;

use yii\base\BootstrapInterface;
use yii\console\Application as ConsoleApplication;

/**
 * Registers the `railway` console command.
 */
class Extension implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        if ($app instanceof ConsoleApplication) {
            $app->controllerMap['railway'] = RailwayController::class;
        }
    }
}
