<?php

namespace App;

use App\Controllers\ConfigController;
use App\Controllers\DashboardController;
use App\Controllers\GroupController;
use App\Controllers\MonitorController;
use Doctrine\Persistence\Mapping\Driver\SymfonyFileLocator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

class Application
{
    private RouteCollection $routes;
    private Environment $twig;
    private $container;

    public function __construct()
    {
        $this->loadDependencies();
        $this->initializeTwig();
        $this->setupRoutes();
    }

    private function loadDependencies(): void
    {
        $this->container = require __DIR__ . '/../config/services.php';
    }


    private function initializeTwig(): void
    {
        $loader = new FilesystemLoader(dirname(__DIR__) . '/templates');
        $this->twig = new Environment($loader, [
            'cache' => false,
            'debug' => true,
            'auto_reload' => true
        ]);

        $this->twig->addGlobal('app_url', $_ENV['APP_URL']);

        // Add url() function to Twig
        $this->twig->addFunction(new TwigFunction('url', function (string $routeName, array $parameters = []) {
            return $this->generateUrl($routeName, $parameters);
        }));

        // Add json_decode filter to Twig
        $this->twig->addFilter(new TwigFilter('json_decode', function ($json, $assoc = true) {
            return json_decode($json, $assoc);
        }));
    }

    private function setupRoutes(): void
    {
        $this->routes = new RouteCollection();

        // Dashboard routes
        $this->routes->add('dashboard', new Route('/', [
            '_controller' => [new DashboardController($this->twig, $this, $this->container), 'index']
        ]));
        $this->routes->add('sync', new Route('/sync', [
            '_controller' => [new DashboardController($this->twig, $this, $this->container), 'sync']
        ]));

        // Monitor routes
        $this->routes->add('monitor_show', new Route('/monitors/{id}', [
            '_controller' => [new MonitorController($this->twig, $this, $this->container), 'show']
        ], ['id' => '\d+']));
        $this->routes->add('monitor_edit', new Route('/monitors/{id}/edit', [
            '_controller' => [new MonitorController($this->twig, $this, $this->container), 'edit']
        ], ['id' => '\d+']));
        $this->routes->add('monitor_latest_activity', new Route('/monitors/{id}/latest-activity', [
            '_controller' => [new MonitorController($this->twig, $this, $this->container), 'latestActivity']
        ], ['id' => '\d+']));
        $this->routes->add('monitor_latest_issues', new Route('/monitors/{id}/latest-issues', [
            '_controller' => [new MonitorController($this->twig, $this, $this->container), 'latestIssues']
        ], ['id' => '\d+']));

        // Config routes
        $this->routes->add('monitor_config', new Route('/monitors/{id}/config', [
            '_controller' => [new ConfigController($this->twig, $this, $this->container), 'editMonitorConfig']
        ], ['id' => '\d+']));
        $this->routes->add('monitor_notifications', new Route('/monitors/{id}/notifications', [
            '_controller' => [new ConfigController($this->twig, $this, $this->container), 'monitorNotifications']
        ], ['id' => '\d+']));
        $this->routes->add('overdue_history', new Route('/monitors/{id}/overdue-history', [
            '_controller' => [new ConfigController($this->twig, $this, $this->container), 'overdueHistory']
        ], ['id' => '\d+']));

        // Group routes
        $this->routes->add('groups', new Route('/groups', [
            '_controller' => [new GroupController($this->twig, $this, $this->container), 'index']
        ]));
        $this->routes->add('group_create', new Route('/groups/create', [
            '_controller' => [new GroupController($this->twig, $this, $this->container), 'create']
        ]));
        $this->routes->add('group_edit', new Route('/groups/{id}/edit', [
            '_controller' => [new GroupController($this->twig, $this, $this->container), 'edit']
        ], ['id' => '\d+']));
        $this->routes->add('group_delete', new Route('/groups/{id}/delete', [
            '_controller' => [new GroupController($this->twig, $this, $this->container), 'delete']
        ], ['id' => '\d+']));
        $this->routes->add('group_show', new Route('/groups/{id}', [
            '_controller' => [new GroupController($this->twig, $this, $this->container), 'show']
        ], ['id' => '\d+']));
        $this->routes->add('group_notifications', new Route('/groups/{id}/notifications', [
            '_controller' => [new GroupController($this->twig, $this, $this->container), 'manageNotifications']
        ], ['id' => '\d+']));

        // Notification channels routes
        $this->routes->add('notification_channels', new Route('/config/notification-channels', [
            '_controller' => [new ConfigController($this->twig, $this, $this->container), 'notificationChannels']
        ]));
        $this->routes->add('add_channel', new Route('/config/notification-channels/add', [
            '_controller' => [new ConfigController($this->twig, $this, $this->container), 'addChannel']
        ]));
        $this->routes->add('edit_channel', new Route('/config/notification-channels/{id}/edit', [
            '_controller' => [new ConfigController($this->twig, $this, $this->container), 'editChannel']
        ], ['id' => '\d+']));
    }

    public function handle(Request $request): Response
    {
        $context = new RequestContext();
        $context->fromRequest($request);

        $matcher = new UrlMatcher($this->routes, $context);

        try {
            $attributes = $matcher->match($request->getPathInfo());
            $controller = $attributes['_controller'];
            unset($attributes['_controller'], $attributes['_route']);

            return call_user_func_array($controller, [$request, ...$attributes]);
        } catch (ResourceNotFoundException $e) {
            return new Response('Page not found', 404);
        } catch (\Exception $e) {
            return new Response('An error occurred: ' . $e->getMessage(), 500);
        }
    }

    public function generateUrl(string $routeName, array $parameters = []): string
    {
        $routes = $this->routes->all();
        if (!isset($routes[$routeName])) {
            throw new \InvalidArgumentException(sprintf('Route "%s" does not exist.', $routeName));
        }

        $route = $routes[$routeName];
        $path = $route->getPath();

        // Replace placeholders with parameters
        foreach ($parameters as $name => $value) {
            $path = str_replace(sprintf('{%s}', $name), $value, $path);
        }

        return $_ENV['APP_URL'] . $path;
    }

    public function getContainer()
    {
        return $this->container;
    }
}