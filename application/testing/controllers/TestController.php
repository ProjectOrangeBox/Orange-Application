<?php

declare(strict_types=1);

namespace application\testing\controllers;

use application\testing\request\User;
use orange\files\Files;
use orange\framework\attributes\AttachService;
use orange\framework\attributes\Route;
use orange\framework\controllers\BaseController;
use orange\framework\interfaces\DataInterface;
use orange\framework\interfaces\ViewInterface;

class TestController extends BaseController
{
    #[AttachService('data')]
    protected DataInterface $data;

    #[AttachService('view')]
    protected ViewInterface $view;

    #[AttachService('files')]
    protected Files $files;

    #[Route('GET', '/test')]
    public function index(): string
    {
        return $this->view->render('test/index');
    }

    #[Route('POST', '/test')]
    public function post(): string
    {
        $uploadFiles = $this->files->get();

        echo '<pre>';

        foreach ($uploadFiles as $fieldkey => $uf) {
            echo $fieldkey . ' ' . $uf->errorMessage() . PHP_EOL;

            $uf->move(__ROOT__ . '/var/uploads', 'icon.png', true);
        }

        return '';
    }

    #[Route('GET', '/request')]
    public function request(): string
    {
        $input = [
            'name' => 'Johnny Appleseed',
            'age' => '23',
            'clr' => 'Orange',
        ];

        $request = new User($input);

        if ($request->isValid()) {
            var_dump($request->name);
            var_dump($request->age);
            var_dump($request->color);

            var_dump($request->asColumns());
        } else {
            var_dump($request->errors());
        }

        return '';
    }
}
