<?php

namespace App;

use App\Controllers\DashboardController;
use Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class Application
{
    private RouteCollection $routes;
    private Environment $twig;
    
    public function __construct()
    {
        $this->loadEnvironment();
        $this->initializeTwig();
        $this->setupRoutes();
    }
    
    private function loadEnvironment(): void
    {
        $dotenv = Dotenv::createImmutable(dirname(__DIR__));
        $dotenv->load();
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
    }
    
    private function setupRoutes(): void
    {
        $this->routes = new RouteCollection();
        
        // Dashboard routes
        $this->routes->add('dashboard', new Route('/', [
            '_controller' => [new DashboardController($this->twig), 'index']
        ]));
        
        $this->routes->add('sync', new Route('/sync', [
            '_controller' => [new DashboardController($this->twig), 'sync']
        ]));
        
        // Monitor routes
        $this->routes->add('monitor_show', new Route('/monitors/{id}', [
            '_controller' => [new MonitorController($this->twig), 'show']
        ], ['id' => '\d+']));
        
        $this->routes->add('monitor_latest_activity', new Route('/monitors/{id}/latest-activity', [
            '_controller' => [new MonitorController($this->twig), 'latestActivity']
        ], ['id' => '\d+']));
        
        $this->routes->add('monitor_latest_issues', new Route('/monitors/{id}/latest-issues', [
            '_controller' => [new MonitorController($this->twig), 'latestIssues']
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
}