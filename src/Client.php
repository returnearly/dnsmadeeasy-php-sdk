<?php
declare(strict_types=1);

namespace DnsMadeEasy;

use DnsMadeEasy\Exceptions\Client\Http\BadRequestException;
use DnsMadeEasy\Exceptions\Client\Http\HttpException;
use DnsMadeEasy\Exceptions\Client\Http\NotFoundException;
use DnsMadeEasy\Exceptions\Client\ManagerNotFoundException;
use DnsMadeEasy\Interfaces\ClientInterface;
use DnsMadeEasy\Interfaces\Managers\AbstractManagerInterface;
use DnsMadeEasy\Interfaces\PaginatorFactoryInterface;
use DnsMadeEasy\Managers\ContactListManager;
use DnsMadeEasy\Pagination\Factories\PaginatorFactory;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Psr7\Request;

class Client implements ClientInterface
{
	protected HttpClientInterface $client;
	protected string $apiKey;
	protected string $secretKey;
    protected string $endpoint = 'https://api.dnsmadeeasy.com/V2.0';

    protected PaginatorFactoryInterface $paginatorFactory;

	protected array $managers = [];
	protected array $managerMap = [
	    'contactlists' => ContactListManager::class,
    ];

	public function __construct(?HttpClientInterface $client = null, ?PaginatorFactoryInterface $paginatorFactory = null)
	{
		if ($client === null && class_exists('\GuzzleHttp\Client')) {
			$client = new \GuzzleHttp\Client;
		}

		if ($paginatorFactory === null) {
		    $this->paginatorFactory = new PaginatorFactory;
        }

		$this->setHttpClient($client);

	}

	public function setHttpClient(HttpClientInterface $client): self
	{
		$this->client = $client;
		return $this;
	}

	public function getHttpClient(): HttpClientInterface
	{
		return $this->client;
	}

	public function setEndpoint(string $endpoint): self
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

	public function setApiKey(string $key): self
	{
		$this->apiKey = $key;
		return $this;
	}

	public function getApiKey(): string
	{
		return $this->apiKey;
	}

	public function setSecretKey(string $key): self
	{
		$this->secretKey = $key;
		return $this;
	}

	public function getSecretKey(): string
	{
		return $this->secretKey;
	}

	public function setPaginatorFactory(PaginatorFactoryInterface $factory): self
    {
        $this->paginatorFactory = $factory;
        return $this;
    }

    public function getPaginatorFactory(): PaginatorFactoryInterface
    {
        return $this->paginatorFactory;
    }

	public function get(string $url, array $params = []): ResponseInterface
	{
		$queryString = '';
		if ($params) {
			$queryString = '?' . http_build_query($params);
		}
		$url .= $queryString;

		$request = new Request('GET', $this->endpoint . $url);
		return $this->send($request);
	}

	public function post(string $url, $payload): ResponseInterface
	{
		$request = new Request('POST', $this->endpoint . $url, [], 'php://temp');
		$request->withHeader('Content-Type', 'application/json');
		$request->getBody()->write(json_encode($payload));
		return $this->send($request);
	}

	public function put(string $url, $payload): ResponseInterface
	{
		$request = new Request('PUT', $this->endpoint . $url, [], 'php://temp');
		$request->withHeader('Content-Type', 'application/json');
		$request->getBody()->write(json_encode($payload));
		return $this->send($request);
	}

	public function delete(string $url): ResponseInterface
	{
		$request = new Request('DELETE', $this->endpoint . $url);
		return $this->send($request);
	}

	public function send(RequestInterface $request): ResponseInterface
	{
		$request = $request->withHeader('Accept', 'application/json');
		$request = $this->addAuthHeaders($request);
		$response = $this->client->sendRequest($request);
		$statusCode = $response->getStatusCode();
		if ((int) substr((string) $statusCode, 0, 1) <= 3) {
		    return $response;
        } else {
		    $lookup = [
		        400 => BadRequestException::class,
                404 => NotFoundException::class,
            ];
		    if (array_key_exists($statusCode, $lookup)) {
		        $exceptionClass = $lookup[$statusCode];
            } else {
		        $exceptionClass = HttpException::class;
            }
            $exception = new $exceptionClass($response->getReasonPhrase(), $statusCode);
            $exception->setRequest($request);
		    $exception->setResponse($response);
		    throw $exception;
        }
	}

	protected function addAuthHeaders(RequestInterface $request): RequestInterface
	{
		$now = new \DateTime('now', new \DateTimeZone('UTC'));
		$timestamp = $now->format('r');
		$hmac = hash_hmac('sha1', $timestamp, $this->getSecretKey());

		$request = $request->withHeader('x-dnsme-apiKey', $this->getApiKey());
		$request = $request->withHeader('x-dnsme-requestDate', $timestamp);
		$request = $request->withHeader('x-dnsme-hmac', $hmac);
		return $request;
	}

	protected function hasManager($name): bool
    {
        $name = strtolower($name);
        return array_key_exists($name, $this->managerMap);
    }

    protected function getManager($name): AbstractManagerInterface
    {
        if (!$this->hasManager($name)) {
            throw new ManagerNotFoundException;
        }

        $name = strtolower($name);

        if (!isset($this->managers[$name])) {
            $this->managers[$name] = new $this->managerMap[$name]($this);
        }

        return $this->managers[$name];
    }

	public function __get($name)
    {
        if ($this->hasManager($name)) {
            return $this->getManager($name);
        }
    }

    public function __call($name, $args)
    {
        if ($this->hasManager($name)) {
            return $this->getManager($name);
        }
    }
}