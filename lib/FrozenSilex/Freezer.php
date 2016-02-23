<?php

namespace FrozenSilex;

use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\HttpKernel;

/**
 * Decorates the Silex application and freezes all detected routes
 * to static files when `freeze()` is called.
 *
 * @author Christoph Hochstrasser <christoph.hochstrasser@gmail.com>
 */
class Freezer
{
    protected $application;
    protected $generators = array();
    protected $frozen = array();
    protected $frozenRoutes = array();

    public function __construct(\Silex\Application $app)
    {
        $this->application = $app;

        if (!isset($app['freezer.override_url_generator'])) {
            $app['freezer.override_url_generator'] = true;
        }

        if (!isset($app['freezer.destination'])) {
            $app['freezer.destination'] = 'build';
        }

        if (!isset($app['freezer.excluded_routes'])) {
            $app['freezer.excluded_routes'] = array();
        }

        if (!isset($app['url_generator'])) {
            $app->register(new \Silex\Provider\UrlGeneratorServiceProvider);
        }

        if ($app['freezer.override_url_generator']) {
            $self = $this;

            # Override the app's URL generator with a generator which freezes
            # every route automatically when the generator is called within
            # the app's controllers or views.
            $app['url_generator'] = $app->extend('url_generator', function ($generator) use ($self) {
                return new FreezingUrlGenerator($generator, $self);
            });
        }

        # Define the default Generator which yields the app's routes.
        $this->registerGenerator(function () use ($app) {
            $routes = array();

            foreach ($app['routes']->all() as $name => $route) {
                if (in_array($name, $app['freezer.excluded_routes'])) {
                    continue;
                }

                $route->compile();
                $routes[] = array($name);
            }

            return $routes;
        });
    }

    /**
     * Register a generator for routes.
     *
     * Generators are functions which return an array of routes which should
     * be requested when the site is frozen.
     *
     * Each item of the returned array can either be a plain string, which is
     * treated as a plain URL, or an array of two items (the route name and the params)
     * which is passed to the default URLGenerator of the Symfony Routing Component.
     *
     * Example:
     *
     *   $app->get('/users/{id}', function($id) {})->bind('show_user');
     *
     *   $freezer->registerGenerator(function() use ($app) {
     *     $users = array();
     *
     *     foreach ($app['mongo']->users->find() as $user) {
     *       $users[] = array('show_user', array('id' => (string) $user['_id']));
     *     }
     *
     *     return $users;
     *   }, 80);
     *
     * @param  callable $generator
     * @param  int      $priority   sets a priority for a generator
     * @return Freezer
     */
    public function registerGenerator($generator, $priority = 100)
    {
        $priority = abs($priority);
        if (isset($this->generators[$priority])) {
            $priority = max(array_keys($this->generators));
            $priority++;
        }

        $this->generators[$priority] = $generator;

        ksort($this->generators);

        return $this;
    }

    /**
     * Freezes the application and writes the generated files to the
     * destination directory.
     *
     * @return void
     */
    public function freeze()
    {
        $this->frozen = array();
        $this->frozenRoutes = array();

        $this->application->boot();
        $this->application->flush();

        $this->application['debug'] = true;
        $this->application['exception_handler']->disable();

        $output = $this->application['freezer.destination'];

        if (!is_dir($output)) {
            mkdir($output, 0755, true);
        }

        $routes = array();

        foreach ($this->generators as $g) {
            foreach ($g() as $route) {
                $routes[] = $route;
            }
        }

        $route = null;

        foreach ($routes as $route) {
            if (is_array($route)) {
                $routeName = $route[0];
                $params = @$route[1] ?: array();

                $this->freezeRoute($routeName, $params);
            } else {
                $this->freezeUrl((string) $route);
            }
        }
    }

    public function freezeRoute($route, $parameters = array())
    {
        if (in_array($route, $this->frozenRoutes)) {
            return;
        }

        $requestContext = new RequestContext;
        $generator = new UrlGenerator($this->application['routes'], $requestContext);

        try {
            $url = $generator->generate($route, $parameters);
        } catch (\Exception $e) {
        }

        if (isset($url)) {
            $this->frozenRoutes[] = $route;
            return $this->freezeUrl($url);
        }
    }

    /**
     * Freezes a given URL
     *
     * @todo Fix links so the static page can be viewed without server
     * @todo Add setting to rewrite links to a given base path.
     *
     * @param string $url
     */
    public function freezeUrl($url)
    {
        if (in_array($url, $this->frozen)) {
            return;
        }

        $client = new HttpKernel\Client($this->application);
        $client->request('GET', $url);

        $response = $client->getResponse();

        if (!$response->isOk()) {
            return;
        }

        $destination = $this->application['freezer.destination'] . $this->getFileName($url);

        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0755, true);
        }

        file_put_contents($destination, $response->getContent());

        $this->frozen[] = $url;
    }

    protected function getFileName($uri)
    {
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos - 1);
        }

        if (substr($uri, -1, 1) === '/') {
            $file = $uri . "index.html";
        } else {
            $file = "$uri.html";
        }

        if (substr($file, 0, 1) !== '/') {
            $file = "/$file";
        }

        return $file;
    }
}
