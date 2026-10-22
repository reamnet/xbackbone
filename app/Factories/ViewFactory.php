<?php

namespace App\Factories;

use App\Web\View;
use Psr\Container\ContainerInterface as Container;
use Slim\Factory\ServerRequestCreatorFactory;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class ViewFactory
{
    public static function createAppInstance(Container $container)
    {
        $config = $container->get('config');
        $loader = new FilesystemLoader(BASE_DIR.'resources/templates');

        $twig = new Environment($loader, [
            'cache'       => BASE_DIR.'resources/cache/twig',
            'autoescape'  => 'html',
            'debug'       => $config['debug'],
            'auto_reload' => $config['debug'],
        ]);

        $request = ServerRequestCreatorFactory::determineServerRequestCreator()->createServerRequestFromGlobals();

        $twig->addGlobal('config', $config);
        $twig->addGlobal('request', $request);
        $twig->addGlobal('session', $container->get('session'));
        $twig->addGlobal('current_lang', $container->get('lang')->getLang());
        $session = $container->get('session');
        if (!$session->has('csrf')) {
            $session->set('csrf', bin2hex(random_bytes(32)));
        }
        $twig->addGlobal('csrf', $session->get('csrf'));
        $twig->addGlobal('maxUploadSize', (int) (getenv('UPLOAD_MAX_BYTES') ?: 20 * 1024 ** 3));
        $twig->addGlobal('PLATFORM_VERSION', PLATFORM_VERSION);

        $twig->addFunction(new TwigFunction('route', 'route'));
        $twig->addFunction(new TwigFunction('lang', 'lang'));
        $twig->addFunction(new TwigFunction('urlFor', 'urlFor'));
        $twig->addFunction(new TwigFunction('asset', 'asset'));
        $twig->addFunction(new TwigFunction('mime2font', 'mime2font'));
        $twig->addFunction(new TwigFunction('queryParams', 'queryParams'));
        $twig->addFunction(new TwigFunction('isDisplayableImage', 'isDisplayableImage'));
        $twig->addFunction(new TwigFunction('inPath', 'inPath'));
        $twig->addFunction(new TwigFunction('humanFileSize', 'humanFileSize'));
        $twig->addFunction(new TwigFunction('param', 'param'));
        $twig->addFunction(new TwigFunction('glue', 'glue'));

        return new View($twig);
    }

    public static function createInstallerInstance(Container $container)
    {
        $config = $container->get('config');
        $loader = new FilesystemLoader([BASE_DIR.'install/templates', BASE_DIR.'resources/templates']);

        $twig = new Environment($loader, [
            'cache'       => false,
            'autoescape'  => 'html',
            'debug'       => $config['debug'],
            'auto_reload' => $config['debug'],
        ]);

        $request = ServerRequestCreatorFactory::determineServerRequestCreator()->createServerRequestFromGlobals();

        $twig->addGlobal('config', $config);
        $twig->addGlobal('request', $request);
        $twig->addGlobal('session', $container->get('session'));
        $twig->addGlobal('PLATFORM_VERSION', PLATFORM_VERSION);

        return new View($twig);
    }
}
