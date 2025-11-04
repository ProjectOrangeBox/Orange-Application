#!/usr/bin/env php
<?php

declare(strict_types=1);

use orange\framework\Application;

require '../bootstrapCli.php';

$container = Application::cli();

// start shellscript
$console = $container->console;

$console->detectVerboseLevel();
$console->verboseAdd('info');

$console->always('welcome');
$console->alert('alert');
$console->critical('critical');
$console->debug('debug');
$console->emergency('emergency');
$console->error('error');
$console->info('info');
$console->notice('notice');
$console->warning('warning');

$console->line();

$appConfig = $container->config->application;

$console->always('h1 is '.$appConfig['h1']);
$console->always('file is '.$appConfig['this file']);

$console->line();

$console->error('Danger, Will Robinson!');
$console->info('Important Information!');
$console->warning('Warning! System Overload!');

$console->line();

$console->minimumArguments(1, 'You have no provided a filename to open.');

$filename = $console->getArgument(1);

$console->info('Using File <bold>' . $filename);

$color = $console->getArgumentByOption('-color');

$console->info('Using Color <bold>' . $color);

$last = $console->getLastArgument();

$console->info('Last <bold>' . $last);

$arg1 = $console->getArgument(1);

$console->info('Arg 1 <bold>' . $arg1);

$table = [
    ['Colors', 'Names', 'Age'],
    ['Red', 'Johnny Apple', 23],
    ['Purple', 'Jenny Smith', 23],
    ['Yellow', 'Jake Louder', 23],
    ['Yellow', 'Jack Black', 857],
];

$console->table($table);

$name = $console->getLine('What is your name?');

$console->info('<bright blue>Hello <magenta>' . $name);

$console->list([1 => 'red', 2 => 'blue', 3 => 'green']);

$selection = $console->getOneOf(null, [1, 2, 3]);

$console->info('You selected <magenta>' . $selection);
