<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\BrowserKit;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\HttpCache\HttpCache;
use Symfony\Component\HttpKernel\HttpCache\Store;
use Symfony\Component\HttpKernel\HttpKernelBrowser;
use Symfony\Component\HttpKernel\RealHttpKernel;
use Symfony\Component\Mime\Part\AbstractPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Component\Mime\Part\TextPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * An implementation of a browser using the HttpClient component
 * to make real HTTP requests.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
final class HttpBrowser extends AbstractBrowser
{
    private $client;
    private $store;
    private $logger;
    private $cache;
    private $httpKernelBrowser;

    public function __construct(HttpClientInterface $client = null, Store $store = null, LoggerInterface $logger = null)
    {
        if (!class_exists(HttpClient::class)) {
            throw new \LogicException(sprintf('You cannot use "%s" as the HttpClient component is not installed. Try running "composer require symfony/http-client".', __CLASS__));
        }

        $this->client = $client ?? HttpClient::create();
        $this->store = $store;
        $this->logger = $logger ?? new NullLogger();

        parent::__construct();
    }

    protected function doRequest($request)
    {
        if ($this->store) {
            return $this->doCachedHttpRequest($request);
        }

        $this->logger->debug(sprintf('Request: %s %s', strtoupper($request->getMethod()), $request->getUri()));

        $headers = $this->getHeaders($request);
        $body = '';
        if (null !== $part = $this->getBody($request)) {
            $headers = array_merge($headers, $part->getPreparedHeaders()->toArray());
            $body = $part->bodyToIterable();
        }
        $response = $this->client->request($request->getMethod(), $request->getUri(), [
            'headers' => $headers,
            'body' => $body,
            'max_redirects' => 0,
        ]);

        $this->logger->debug(sprintf('Response: %s %s', $response->getStatusCode(), $request->getUri()));

        return new Response($response->getContent(false), $response->getStatusCode(), $response->getHeaders(false));
    }

    private function doCachedHttpRequest(Request $request): Response
    {
        if (null === $this->cache) {
            if (!class_exists(RealHttpKernel::class)) {
                throw new \LogicException('You cannot use a cached HTTP browser as the HttpKernel component is not installed. Try running "composer require symfony/http-kernel".');
            }

            $kernel = new RealHttpKernel($this->client, $this->logger);
            $this->cache = new HttpCache($kernel, $this->store, null, ['debug' => !$this->logger instanceof NullLogger]);
            $this->httpKernelBrowser = new HttpKernelBrowser($kernel);
        }

        $response = $this->cache->handle($this->httpKernelBrowser->filterRequest($request));
        $this->logger->debug('Cache: '.$response->headers->get('X-Symfony-Cache'));

        return $this->httpKernelBrowser->filterResponse($response);
    }

    private function getBody(Request $request): ?AbstractPart
    {
        if (\in_array($request->getMethod(), ['GET', 'HEAD'])) {
            return null;
        }

        if (!class_exists(AbstractPart::class)) {
            throw new \LogicException('You cannot pass non-empty bodies as the Mime component is not installed. Try running "composer require symfony/mime".');
        }

        if (null !== $content = $request->getContent()) {
            return new TextPart($content, 'utf-8', 'plain', '8bit');
        }

        $fields = $request->getParameters();
        foreach ($request->getFiles() as $name => $file) {
            if (!isset($file['tmp_name'])) {
                continue;
            }

            $fields[$name] = DataPart::fromPath($file['tmp_name'], $file['name']);
        }

        return new FormDataPart($fields);
    }

    private function getHeaders(Request $request): array
    {
        $headers = [];
        foreach ($request->getServer() as $key => $value) {
            $key = strtolower(str_replace('_', '-', $key));
            $contentHeaders = ['content-length' => true, 'content-md5' => true, 'content-type' => true];
            if (0 === strpos($key, 'http-')) {
                $headers[substr($key, 5)] = $value;
            } elseif (isset($contentHeaders[$key])) {
                // CONTENT_* are not prefixed with HTTP_
                $headers[$key] = $value;
            }
        }
        $cookies = [];
        foreach ($this->getCookieJar()->allRawValues($request->getUri()) as $name => $value) {
            $cookies[] = $name.'='.$value;
        }
        if ($cookies) {
            $headers['cookie'] = implode('; ', $cookies);
        }

        return $headers;
    }
}
