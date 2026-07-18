<?php

declare(strict_types=1);

namespace application\rest\controllers;

use orange\framework\attributes\Route;

class RestController extends CrudController
{
    #[Route('*', '/api/welcome', 'rest_home')]
    public function index(): string
    {
        // many at once
        $this->data->merge([
            'msg' => 'Welcome to My Vue App',
        ]);

        return $this->restResponse();
    }
}
