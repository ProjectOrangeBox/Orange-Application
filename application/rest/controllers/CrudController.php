<?php

declare(strict_types=1);

namespace application\rest\controllers;

use orange\framework\attributes\AttachService;
use orange\framework\controllers\BaseController;
use orange\framework\interfaces\DataInterface;

/**
 * this is a user controller that others can extend it is not nessesary but it's nice to put commonly used code here
 */
abstract class CrudController extends BaseController
{
    #[AttachService('data')]
    protected DataInterface $data;

    // method to responds code
    protected array $restSuccessMap = [
        'ok' => 200,
        'getNew' => 200,
        'getAll' => 200,
        'getById' => 200,
        'create' => 201,
        'update' => 202,
        'delete' => 202,
        'failed' => 406,
    ];

    protected int $jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;

    protected function restResponse(string $status = 'ok'): string
    {
        $this->output->responseCode($this->restSuccessMap[$status] ?? 500)->contentType('json');

        return json_encode($this->data, $this->jsonFlags);
    }
}
