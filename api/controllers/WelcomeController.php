<?php

declare(strict_types=1);

namespace api\controllers;

use orange\framework\attributes\Route;
use orange\framework\controllers\JsonController;

class WelcomeController extends JsonController
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
