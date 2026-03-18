<?php

declare(strict_types=1);

use orange\files\Files;
use orange\framework\interfaces\ContainerInterface;

return [
    'files' => function (ContainerInterface $container) {
        $config = $container->config->files;
        $config['mimes'] = $container->{'$mimes'};

        return Files::getInstance($config, $container->input);
    },
];
