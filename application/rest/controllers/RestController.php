<?php

declare(strict_types=1);

namespace application\rest\controllers;

use orange\framework\attributes\Route;
use orange\framework\controllers\JsonController;

class RestController extends JsonController
{
    #[Route('*', '/api/welcome', 'rest_home')]
    public function index(): string
    {
        // many at once
        $this->data->merge([
            'msg' => 'Welcome to My Vue App',
        ]);

        return $this->response();
    }
}
