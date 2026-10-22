<?php

namespace App\Services;

class OidcClient extends \Jumbojett\OpenIDConnectClient
{
    public ?string $authorizationUrl = null;

    public function redirect(string $url)
    {
        $this->authorizationUrl = $url;
    }
}
