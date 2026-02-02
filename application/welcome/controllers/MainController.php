<?php

declare(strict_types=1);

namespace application\welcome\controllers;

use orange\framework\controllers\BaseController;

class MainController extends BaseController
{
    # [route(*,/,home)]
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
