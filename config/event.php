<?php

declare(strict_types=1);

use orange\framework\interfaces\InputInterface;
use orange\framework\interfaces\OutputInterface;
use orange\framework\interfaces\RouterInterface;

return [
    // merge queued flash messages into every JSON response - controllers
    // just call container()->flash->msg(...) and the payload gains a
    // "flash_messages_array" key on its way out; pull() clears the queue so
    // a batch rides exactly one response (see vendor/orange/flashmsg/example.md)
    'before.output' => [
        // $router/$input are unused but positional - the framework triggers
        // this event with (router, input, output)
        [function (RouterInterface $router, InputInterface $input, OutputInterface $output): void {
            $flash = container()->flash;

            if (!$flash->hasMessages() || !str_contains($output->getContentType(), 'json')) {
                return;
            }

            $payload = json_decode($output->get(), true) ?? [];
            $payload['flash_messages_array'] = $flash->pull();

            $output->write(json_encode($payload), false);
        }],
    ],
];
