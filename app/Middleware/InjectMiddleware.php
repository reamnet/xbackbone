<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class InjectMiddleware extends Middleware
{
    /**
     * @param Request        $request
     * @param RequestHandler $handler
     *
     * @return Response
     */
    public function __invoke(Request $request, RequestHandler $handler)
    {
        $brandingConfig = $this->config;
        $brandingConfig['app_name'] = $this->getSetting('brand_name') ?: $brandingConfig['app_name'];
        $this->view->getTwig()->addGlobal('config', $brandingConfig);
        $this->view->getTwig()->addGlobal('brandLogo', $this->getSetting('brand_logo', ''));
        $this->view->getTwig()->addGlobal('brandFooter', $this->getSetting('brand_footer', ''));
        $this->view->getTwig()->addGlobal('customHead', $this->getSetting('custom_head'));

        return $handler->handle($request);
    }
}
