<?php

declare(strict_types=1);

namespace application\welcome\controllers;

use orange\framework\attributes\AttachService;
use orange\framework\attributes\Route;
use orange\framework\controllers\BaseController;
use orange\framework\interfaces\DataInterface;
use orange\framework\interfaces\ViewInterface;

class MainController extends BaseController
{
    #[AttachService('data')]
    protected DataInterface $data;

    #[AttachService('view')]
    protected ViewInterface $view;

    #[Route('*','/','home')]
    public function index(): string
    {
        // many at once
        $this->data->merge([
            'css'=>'',
            'script'=>'',
            'js'=>'',
            'address' => '123 South Main Street<br />Somewhere, AZ 12345',
            'about'=>'',
            'aboutText' => '',
            'position' => $this->config['application']['position'],
            'h1' => $this->config['application']['h1'],
            'file' => $this->config['application']['this file'],
            'cash' => '19.95',
        ]);

        // or 1 at a time
        $this->data['around'] = 'AROUND THE WEB';
        $this->data['name'] = 'Johnny Appleseed';

        // render it!
        // auto detect view on therefore it loads /main/index.php
        // from the local view path
        return $this->view->render('main/index');
    }
}
