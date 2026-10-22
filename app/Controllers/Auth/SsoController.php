<?php

namespace App\Controllers\Auth;

use App\Controllers\Controller;
use App\Services\OidcClient;
use App\Services\SsoAccounts;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use RuntimeException;
use Jumbojett\OpenIDConnectClientException;

class SsoController extends Controller
{
    private function client(Request $request): OidcClient
    {
        $config = $this->config['sso'];
        if (!$config['enabled'] || !$config['client_id'] || !$config['client_secret'] || !filter_var($config['issuer'], FILTER_VALIDATE_URL) || !str_starts_with($config['issuer'], 'https://')) {
            throw new HttpNotFoundException($request);
        }
        $client = new OidcClient($config['issuer'], $config['client_id'], $config['client_secret']);
        $client->setRedirectURL(route('sso.callback'));
        $client->setCodeChallengeMethod('S256');
        $client->addScope(['email', 'profile']);
        $client->setTimeout(15);
        return $client;
    }

    public function start(Request $request, Response $response): Response
    {
        $this->session->set('sso_link_user', null);
        return $this->authorize($request, $response);
    }

    public function link(Request $request, Response $response): Response
    {
        $user = $this->database->query('SELECT * FROM users WHERE id=?', $this->session->get('user_id'))->fetch();
        if (!$this->session->get('csrf') || !hash_equals($this->session->get('csrf'), (string) param($request, 'csrf')) ||
            !$user || !$user->active || !password_verify((string) param($request, 'password'), $user->password)) {
            $this->session->alert('Account linking requires your current password and a valid session.', 'danger');
            return redirect($response, route('profile'));
        }
        $this->session->set('sso_link_user', $user->id);
        return $this->authorize($request, $response);
    }

    private function authorize(Request $request, Response $response): Response
    {
        $client = $this->client($request);
        $previous = $_REQUEST;
        $_REQUEST = [];
        try {
            $this->session->set('sso_started', time());
            $client->authenticate();
            return redirect($response, $client->authorizationUrl);
        } catch (OpenIDConnectClientException) {
            $this->session->alert('Unable to reach Authentik. Check the OIDC configuration.', 'danger');
            return redirect($response, route('login.show'));
        } finally {
            $_REQUEST = $previous;
        }
    }

    public function callback(Request $request, Response $response): Response
    {
        $client = $this->client($request);
        $query = $request->getQueryParams();
        $state = $this->session->get('openid_connect_state');
        if (!isset($query['code'], $query['state']) || !is_string($query['code']) || !is_string($query['state']) ||
            !is_string($state) || !hash_equals($state, $query['state']) || time() - $this->session->get('sso_started', 0) > 600) {
            throw new HttpBadRequestException($request, 'Invalid or expired single sign-on response.');
        }
        $previous = $_REQUEST;
        $_REQUEST = ['code' => $query['code'], 'state' => $query['state']];
        $linkId = $this->session->get('sso_link_user');
        $this->session->set('sso_link_user', null);
        try {
            if (!$client->authenticate()) {
                throw new RuntimeException('Authentication failed.');
            }
            if ($linkId !== null && $linkId !== $this->session->get('user_id')) {
                throw new RuntimeException('Account linking session changed.');
            }
            $pdo = $this->database->getPdo();
            $pdo->beginTransaction();
            try {
                $user = (new SsoAccounts($this->database))->resolve($this->config['sso']['issuer'], (array) $client->getVerifiedClaims(), $linkId, $this->config['sso']['auto_register']);
                if ($this->config['maintenance'] && !$user->is_admin) {
                    throw new RuntimeException('The application is under maintenance.');
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            session_regenerate_id(true);
            $this->session->set('logged', true)->set('user_id', $user->id)->set('username', $user->username)
                ->set('admin', $user->is_admin)->set('copy_raw', $user->copy_raw);
            $this->setSessionQuotaInfo($user->current_disk_quota, $user->max_disk_quota);
            return redirect($response, route('home'));
        } catch (OpenIDConnectClientException | RuntimeException $e) {
            // Provider token/HTTP error text can contain credentials; never expose it.
            $this->session->alert($e instanceof OpenIDConnectClientException ? 'Single sign-on failed. Check the Authentik provider settings.' : $e->getMessage(), 'danger');
            return redirect($response, route('login.show'));
        } finally {
            $_REQUEST = $previous;
            $this->session->set('openid_connect_state', null)->set('openid_connect_nonce', null)->set('sso_started', null);
        }
    }
}
