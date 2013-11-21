<?php

$app = new \Silex\Application;
$app->register(new \Silex\Provider\UrlGeneratorServiceProvider);
$app->register(new \Silex\Provider\TwigServiceProvider, array(
    'twig.path' => __DIR__ . '/views'
));

$app['debug'] = true;

$app->get('/', function() use ($app) {
    return $app['twig']->render('index.html');
})->bind('home');

$app->get('/hello', function() use ($app) {
    return $app['twig']->render('hello.html');
})->bind('hello');

return $app;
