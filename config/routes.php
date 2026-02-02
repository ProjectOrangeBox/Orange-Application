<?php

declare(strict_types=1);

// a route name is used with the getUrl(...) method
// all routes can have a name but it not required
// Names make it so you can use getUrl(...) to return a url to that route
// if the route changes here you don't need to update all of your code because getUrl(...) handles that automatically
// if you redirect to the path named "foobar" and the "foobar" url changes the route automatically changes

// '*' matchers on all http methods
// 'get' matches only on the get method
// ['get','post'] matches on both get and post method
return [
    // all of the routes need to be in this array
    'routes' => [
        /* home page */
        ['method' => '*', 'url' => '/', 'callback' => [\application\welcome\controllers\MainController::class, 'index'], 'name' => 'home'],

        // these are used to get paths router::getUrl(...)
        // then if you need to change a path you simply need to change it here and not in mutiple files
        ['url' => '/assets', 'name' => 'assets'],
        ['url' => '/assets/js', 'name' => 'javascript'],
        ['url' => '/assets/css', 'name' => 'css'],
        ['url' => '/images', 'name' => 'images'],
    ],
];
