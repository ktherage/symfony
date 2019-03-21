<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\BrowserKit\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\BrowserKit\CookieJar;
use Symfony\Component\BrowserKit\History;
use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\BrowserKit\Request;
use Symfony\Component\BrowserKit\Response;
use Symfony\Component\DomCrawler\Form as DomCrawlerForm;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SpecialHttpResponse extends Response
{
}

class TestHttpClient extends HttpBrowser
{
    protected $nextResponse = null;

    public function __construct(array $server = [], History $history = null, CookieJar $cookieJar = null)
    {
        $this->client = new MockHttpClient(function (string $method, string $url, array $options) {
            if (null === $this->nextResponse) {
                return new MockResponse();
            }

            $response = $this->nextResponse;
            $this->nextResponse = null;

            $response = new MockResponse($response->getContent(), [
                'http_code' => $response->getStatusCode(),
                'raw_headers' => $response->getHeaders(),
            ]);

            return $response;
        });
        parent::__construct($this->client);
    }

    public function setNextResponse(Response $response)
    {
        $this->nextResponse = $response;
    }

    protected function filterResponse($response)
    {
        if ($response instanceof SpecialResponse) {
            return new Response($response->getContent(), $response->getStatusCode(), $response->getHeaders());
        }

        return $response;
    }
}

class HttpBrowserTest extends TestCase
{
    public function testGetHistory()
    {
        $client = new TestHttpClient([], $history = new History());
        $this->assertSame($history, $client->getHistory(), '->getHistory() returns the History');
    }

    public function testGetCookieJar()
    {
        $client = new TestHttpClient([], null, $cookieJar = new CookieJar());
        $this->assertSame($cookieJar, $client->getCookieJar(), '->getCookieJar() returns the CookieJar');
    }

    public function testGetRequest()
    {
        $client = new TestHttpClient();
        $client->request('GET', 'http://example.com/');

        $this->assertEquals('http://example.com/', $client->getRequest()->getUri(), '->getCrawler() returns the Request of the last request');
    }

    /**
     * @group legacy
     * @expectedDeprecation Calling the "Symfony\Component\BrowserKit\AbstractBrowser::getRequest()" method before the "request()" one is deprecated since Symfony 4.1 and will throw an exception in 5.0.
     */
    public function testGetRequestNull()
    {
        $client = new TestHttpClient();
        $this->assertNull($client->getRequest());
    }

    public function testXmlHttpRequest()
    {
        $client = new TestHttpClient();
        $client->xmlHttpRequest('GET', 'http://example.com/', [], [], [], null, true);
        $this->assertEquals($client->getRequest()->getServer()['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest');
        $this->assertFalse($client->getServerParameter('HTTP_X_REQUESTED_WITH', false));
    }

    public function testGetRequestWithIpAsHttpHost()
    {
        $client = new TestHttpClient();
        $client->request('GET', 'https://example.com/foo', [], [], ['HTTP_HOST' => '127.0.0.1']);

        $this->assertEquals('https://example.com/foo', $client->getRequest()->getUri());
        $headers = $client->getRequest()->getServer();
        $this->assertEquals('127.0.0.1', $headers['HTTP_HOST']);
    }

    public function testGetResponse()
    {
        $client = new TestHttpClient();
        $client->setNextResponse(new Response('foo'));
        $client->request('GET', 'http://example.com/');

        $this->assertEquals('foo', $client->getResponse()->getContent(), '->getCrawler() returns the Response of the last request');
        $this->assertInstanceOf(Response::class, $client->getResponse(), '->getCrawler() returns the Response of the last request');
    }

    /**
     * @group legacy
     * @expectedDeprecation Calling the "Symfony\Component\BrowserKit\AbstractBrowser::getResponse()" method before the "request()" one is deprecated since Symfony 4.1 and will throw an exception in 5.0.
     */
    public function testGetResponseNull()
    {
        $client = new TestHttpClient();
        $this->assertNull($client->getResponse());
    }

    public function testGetInternalResponse()
    {
        $client = new TestHttpClient();
        $client->setNextResponse(new SpecialHttpResponse('foo'));
        $client->request('GET', 'http://example.com/');

        $this->assertInstanceOf(Response::class, $client->getInternalResponse());
        $this->assertNotInstanceOf(SpecialHttpResponse::class, $client->getInternalResponse());
        $this->assertInstanceOf(SpecialHttpResponse::class, $client->getResponse());
    }

    /**
     * @group legacy
     * @expectedDeprecation Calling the "Symfony\Component\BrowserKit\AbstractBrowser::getInternalResponse()" method before the "request()" one is deprecated since Symfony 4.1 and will throw an exception in 5.0.
     */
    public function testGetInternalResponseNull()
    {
        $client = new TestHttpClient();
        $this->assertNull($client->getInternalResponse());
    }

    public function testGetContent()
    {
        $json = '{"jsonrpc":"2.0","method":"echo","id":7,"params":["Hello World"]}';

        $client = new TestHttpClient();
        $client->request('POST', 'http://example.com/jsonrpc', [], [], [], $json);
        $this->assertEquals($json, $client->getRequest()->getContent());
    }

    public function testGetCrawler()
    {
        $client = new TestHttpClient();
        $client->setNextResponse(new Response('foo'));
        $crawler = $client->request('GET', 'http://example.com/');

        $this->assertSame($crawler, $client->getCrawler(), '->getCrawler() returns the Crawler of the last request');
    }

    /**
     * @group legacy
     * @expectedDeprecation Calling the "Symfony\Component\BrowserKit\AbstractBrowser::getCrawler()" method before the "request()" one is deprecated since Symfony 4.1 and will throw an exception in 5.0.
     */
    public function testGetCrawlerNull()
    {
        $client = new TestHttpClient();
        $this->assertNull($client->getCrawler());
    }

    public function testRequestHttpHeaders()
    {
        $client = new TestHttpClient();
        $client->request('GET', '/');
        $headers = $client->getRequest()->getServer();
        $this->assertEquals('localhost', $headers['HTTP_HOST'], '->request() sets the HTTP_HOST header');

        $client = new TestHttpClient();
        $client->request('GET', 'http://www.example.com');
        $headers = $client->getRequest()->getServer();
        $this->assertEquals('www.example.com', $headers['HTTP_HOST'], '->request() sets the HTTP_HOST header');

        $client->request('GET', 'https://www.example.com');
        $headers = $client->getRequest()->getServer();
        $this->assertTrue($headers['HTTPS'], '->request() sets the HTTPS header');

        $client = new TestHttpClient();
        $client->request('GET', 'http://www.example.com:8080');
        $headers = $client->getRequest()->getServer();
        $this->assertEquals('www.example.com:8080', $headers['HTTP_HOST'], '->request() sets the HTTP_HOST header with port');
    }

    public function testRequestURIConversion()
    {
        $client = new TestHttpClient();
        $client->request('GET', '/foo');
        $this->assertEquals('http://localhost/foo', $client->getRequest()->getUri(), '->request() converts the URI to an absolute one');

        $client = new TestHttpClient();
        $client->request('GET', 'http://www.example.com');
        $this->assertEquals('http://www.example.com', $client->getRequest()->getUri(), '->request() does not change absolute URIs');

        $client = new TestHttpClient();
        $client->request('GET', 'http://www.example.com/');
        $client->request('GET', '/foo');
        $this->assertEquals('http://www.example.com/foo', $client->getRequest()->getUri(), '->request() uses the previous request for relative URLs');

        $client = new TestHttpClient();
        $client->request('GET', 'http://www.example.com/foo');
        $client->request('GET', '#');
        $this->assertEquals('http://www.example.com/foo#', $client->getRequest()->getUri(), '->request() uses the previous request for #');
        $client->request('GET', '#');
        $this->assertEquals('http://www.example.com/foo#', $client->getRequest()->getUri(), '->request() uses the previous request for #');
        $client->request('GET', '#foo');
        $this->assertEquals('http://www.example.com/foo#foo', $client->getRequest()->getUri(), '->request() uses the previous request for #');

        $client = new TestHttpClient();
        $client->request('GET', 'http://www.example.com/foo/');
        $client->request('GET', 'bar');
        $this->assertEquals('http://www.example.com/foo/bar', $client->getRequest()->getUri(), '->request() uses the previous request for relative URLs');

        $client = new TestHttpClient();
        $client->request('GET', 'http://www.example.com/foo/foobar');
        $client->request('GET', 'bar');
        $this->assertEquals('http://www.example.com/foo/bar', $client->getRequest()->getUri(), '->request() uses the previous request for relative URLs');

        $client = new TestHttpClient();
        $client->request('GET', 'http://www.example.com/foo/');
        $client->request('GET', 'http');
        $this->assertEquals('http://www.example.com/foo/http', $client->getRequest()->getUri(), '->request() uses the previous request for relative URLs');

        $client = new TestHttpClient();
        $client->request('GET', 'http://www.example.com/foo');
        $client->request('GET', 'http/bar');
        $this->assertEquals('http://www.example.com/http/bar', $client->getRequest()->getUri(), '->request() uses the previous request for relative URLs');

        $client = new TestHttpClient();
        $client->request('GET', 'http://www.example.com/');
        $client->request('GET', 'http');
        $this->assertEquals('http://www.example.com/http', $client->getRequest()->getUri(), '->request() uses the previous request for relative URLs');

        $client = new TestHttpClient();
        $client->request('GET', 'http://www.example.com/foo');
        $client->request('GET', '?');
        $this->assertEquals('http://www.example.com/foo?', $client->getRequest()->getUri(), '->request() uses the previous request for ?');
        $client->request('GET', '?');
        $this->assertEquals('http://www.example.com/foo?', $client->getRequest()->getUri(), '->request() uses the previous request for ?');
        $client->request('GET', '?foo=bar');
        $this->assertEquals('http://www.example.com/foo?foo=bar', $client->getRequest()->getUri(), '->request() uses the previous request for ?');
    }

    public function testRequestReferer()
    {
        $client = new TestHttpClient();
        $client->request('GET', 'http://www.example.com/foo/foobar');
        $client->request('GET', 'bar');
        $server = $client->getRequest()->getServer();
        $this->assertEquals('http://www.example.com/foo/foobar', $server['HTTP_REFERER'], '->request() sets the referer');
    }

    public function testRequestHistory()
    {
        $client = new TestHttpClient();
        $client->request('GET', 'http://www.example.com/foo/foobar');
        $client->request('GET', 'bar');

        $this->assertEquals('http://www.example.com/foo/bar', $client->getHistory()->current()->getUri(), '->request() updates the History');
        $this->assertEquals('http://www.example.com/foo/foobar', $client->getHistory()->back()->getUri(), '->request() updates the History');
    }

    public function testRequestCookies()
    {
        $client = new TestHttpClient();
        $client->setNextResponse(new Response('<html><a href="/foo">foo</a></html>', 200, ['Set-Cookie' => 'foo=bar']));
        $client->request('GET', 'http://www.example.com/foo/foobar');
        $this->assertEquals(['foo' => 'bar'], $client->getCookieJar()->allValues('http://www.example.com/foo/foobar'), '->request() updates the CookieJar');

        $client->request('GET', 'bar');
        $this->assertEquals(['foo' => 'bar'], $client->getCookieJar()->allValues('http://www.example.com/foo/foobar'), '->request() updates the CookieJar');
    }

    public function testRequestSecureCookies()
    {
        $client = new TestHttpClient();
        $client->setNextResponse(new Response('<html><a href="/foo">foo</a></html>', 200, ['Set-Cookie' => 'foo=bar; path=/; secure']));
        $client->request('GET', 'https://www.example.com/foo/foobar');

        $this->assertTrue($client->getCookieJar()->get('foo', '/', 'www.example.com')->isSecure());
    }

    public function testClick()
    {
        $client = new TestHttpClient();
        $client->setNextResponse(new Response('<html><a href="/foo">foo</a></html>'));
        $crawler = $client->request('GET', 'http://www.example.com/foo/foobar');

        $client->click($crawler->filter('a')->link());

        $this->assertEquals('http://www.example.com/foo', $client->getRequest()->getUri(), '->click() clicks on links');
    }

    public function testClickLink()
    {
        $client = new TestHttpClient();
        $client->setNextResponse(new Response('<html><a href="/foo">foo</a></html>'));
        $client->request('GET', 'http://www.example.com/foo/foobar');
        $client->clickLink('foo');

        $this->assertEquals('http://www.example.com/foo', $client->getRequest()->getUri(), '->click() clicks on links');
    }

    public function testClickLinkNotFound()
    {
        $client = new TestHttpClient();
        $client->setNextResponse(new Response('<html><a href="/foo">foobar</a></html>'));
        $client->request('GET', 'http://www.example.com/foo/foobar');

        try {
            $client->clickLink('foo');
            $this->fail('->clickLink() throws a \InvalidArgumentException if the link could not be found');
        } catch (\Exception $e) {
            $this->assertInstanceOf('InvalidArgumentException', $e, '->clickLink() throws a \InvalidArgumentException if the link could not be found');
        }
    }

    public function testClickForm()
    {
        $client = new TestHttpClient();
        $client->setNextResponse(new Response('<html><form action="/foo"><input type="submit" /></form></html>'));
        $crawler = $client->request('GET', 'http://www.example.com/foo/foobar');

        $client->click($crawler->filter('input')->form());

        $this->assertEquals('http://www.example.com/foo', $client->getRequest()->getUri(), '->click() Form submit forms');
    }

    public function testSubmit()
    {
        $client = new TestHttpClient();
        $client->setNextResponse(new Response('<html><form action="/foo"><input type="submit" /></form></html>'));
        $crawler = $client->request('GET', 'http://www.example.com/foo/foobar');

        $client->submit($crawler->filter('input')->form());

        $this->assertEquals('http://www.example.com/foo', $client->getRequest()->getUri(), '->submit() submit forms');
    }

    public function testSubmitForm()
    {
        $client = new TestHttpClient();
        $client->setNextResponse(new Response('<html><form name="signup" action="/foo"><input type="text" name="username" value="the username" /><input type="password" name="password" value="the password" /><input type="submit" value="Register" /></form></html>'));
        $client->request('GET', 'http://www.example.com/foo/foobar');

        $client->submitForm('Register', [
            'username' => 'new username',
            'password' => 'new password',
        ], 'PUT', [
            'HTTP_USER_AGENT' => 'Symfony User Agent',
        ]);

        $this->assertEquals('http://www.example.com/foo', $client->getRequest()->getUri(), '->submitForm() submit forms');
        $this->assertEquals('PUT', $client->getRequest()->getMethod(), '->submitForm() allows to change the method');
        $this->assertEquals('new username', $client->getRequest()->getParameters()['username'], '->submitForm() allows to override the form values');
        $this->assertEquals('new password', $client->getRequest()->getParameters()['password'], '->submitForm() allows to override the form values');
        $this->assertEquals('Symfony User Agent', $client->getRequest()->getServer()['HTTP_USER_AGENT'], '->submitForm() allows to change the $_SERVER parameters');
    }

    public function testSubmitFormNotFound()
    {
        $client = new TestHttpClient();
        $client->setNextResponse(new Response('<html><form action="/foo"><input type="submit" /></form></html>'));
        $client->request('GET', 'http://www.example.com/foo/foobar');

        try {
            $client->submitForm('Register', [
                'username' => 'username',
                'password' => 'password',
            ], 'POST');
            $this->fail('->submitForm() throws a \InvalidArgumentException if the form could not be found');
        } catch (\Exception $e) {
            $this->assertInstanceOf('InvalidArgumentException', $e, '->submitForm() throws a \InvalidArgumentException if the form could not be found');
        }
    }

    public function testSubmitPreserveAuth()
    {
        $client = new TestHttpClient(['PHP_AUTH_USER' => 'foo', 'PHP_AUTH_PW' => 'bar']);
        $client->setNextResponse(new Response('<html><form action="/foo"><input type="submit" /></form></html>'));
        $crawler = $client->request('GET', 'http://www.example.com/foo/foobar');

        $server = $client->getRequest()->getServer();
        $this->assertArrayHasKey('PHP_AUTH_USER', $server);
        $this->assertEquals('foo', $server['PHP_AUTH_USER']);
        $this->assertArrayHasKey('PHP_AUTH_PW', $server);
        $this->assertEquals('bar', $server['PHP_AUTH_PW']);

        $client->submit($crawler->filter('input')->form());

        $this->assertEquals('http://www.example.com/foo', $client->getRequest()->getUri(), '->submit() submit forms');

        $server = $client->getRequest()->getServer();
        $this->assertArrayHasKey('PHP_AUTH_USER', $server);
        $this->assertEquals('foo', $server['PHP_AUTH_USER']);
        $this->assertArrayHasKey('PHP_AUTH_PW', $server);
        $this->assertEquals('bar', $server['PHP_AUTH_PW']);
    }

    public function testSubmitPassthrewHeaders()
    {
        $client = new TestHttpClient();
        $client->setNextResponse(new Response('<html><form action="/foo"><input type="submit" /></form></html>'));
        $crawler = $client->request('GET', 'http://www.example.com/foo/foobar');
        $headers = ['Accept-Language' => 'de'];

        $client->submit($crawler->filter('input')->form(), [], $headers);

        $server = $client->getRequest()->getServer();
        $this->assertArrayHasKey('Accept-Language', $server);
        $this->assertEquals('de', $server['Accept-Language']);
    }

    public function testFollowRedirect()
    {
        $client = new TestHttpClient();
        $client->followRedirects(false);
        $client->request('GET', 'http://www.example.com/foo/foobar');

        try {
            $client->followRedirect();
            $this->fail('->followRedirect() throws a \LogicException if the request was not redirected');
        } catch (\Exception $e) {
            $this->assertInstanceOf('LogicException', $e, '->followRedirect() throws a \LogicException if the request was not redirected');
        }

        $client->setNextResponse(new Response('', 302, ['Location' => 'http://www.example.com/redirected']));
        $client->request('GET', 'http://www.example.com/foo/foobar');
        $client->followRedirect();

        $this->assertEquals('http://www.example.com/redirected', $client->getRequest()->getUri(), '->followRedirect() follows a redirect if any');

        $client = new TestHttpClient();
        $client->setNextResponse(new Response('', 302, ['Location' => 'http://www.example.com/redirected']));
        $client->request('GET', 'http://www.example.com/foo/foobar');

        $this->assertEquals('http://www.example.com/redirected', $client->getRequest()->getUri(), '->followRedirect() automatically follows redirects if followRedirects is true');

        $client = new TestHttpClient();
        $client->setNextResponse(new Response('', 201, ['Location' => 'http://www.example.com/redirected']));
        $client->request('GET', 'http://www.example.com/foo/foobar');

        $this->assertEquals('http://www.example.com/foo/foobar', $client->getRequest()->getUri(), '->followRedirect() does not follow redirect if HTTP Code is not 30x');

        $client = new TestHttpClient();
        $client->setNextResponse(new Response('', 201, ['Location' => 'http://www.example.com/redirected']));
        $client->followRedirects(false);
        $client->request('GET', 'http://www.example.com/foo/foobar');

        try {
            $client->followRedirect();
            $this->fail('->followRedirect() throws a \LogicException if the request did not respond with 30x HTTP Code');
        } catch (\Exception $e) {
            $this->assertInstanceOf('LogicException', $e, '->followRedirect() throws a \LogicException if the request did not respond with 30x HTTP Code');
        }
    }

    public function testFollowRelativeRedirect()
    {
        $client = new TestHttpClient();
        $client->setNextResponse(new Response('', 302, ['Location' => '/redirected']));
        $client->request('GET', 'http://www.example.com/foo/foobar');
        $this->assertEquals('http://www.example.com/redirected', $client->getRequest()->getUri(), '->followRedirect() follows a redirect if any');

        $client = new TestHttpClient();
        $client->setNextResponse(new Response('', 302, ['Location' => '/redirected:1234']));
        $client->request('GET', 'http://www.example.com/foo/foobar');
        $this->assertEquals('http://www.example.com/redirected:1234', $client->getRequest()->getUri(), '->followRedirect() follows relative urls');
    }

    public function testFollowRedirectWithMaxRedirects()
    {
        $client = new TestHttpClient();
        $client->setMaxRedirects(1);
        $client->setNextResponse(new Response('', 302, ['Location' => 'http://www.example.com/redirected']));
        $client->request('GET', 'http://www.example.com/foo/foobar');
        $this->assertEquals('http://www.example.com/redirected', $client->getRequest()->getUri(), '->followRedirect() follows a redirect if any');

        $client->setNextResponse(new Response('', 302, ['Location' => 'http://www.example.com/redirected2']));
        try {
            $client->followRedirect();
            $this->fail('->followRedirect() throws a \LogicException if the request was redirected and limit of redirections was reached');
        } catch (\Exception $e) {
            $this->assertInstanceOf('LogicException', $e, '->followRedirect() throws a \LogicException if the request was redirected and limit of redirections was reached');
        }

        $client->setNextResponse(new Response('', 302, ['Location' => 'http://www.example.com/redirected']));
        $client->request('GET', 'http://www.example.com/foo/foobar');
        $this->assertEquals('http://www.example.com/redirected', $client->getRequest()->getUri(), '->followRedirect() follows a redirect if any');

        $client->setNextResponse(new Response('', 302, ['Location' => '/redirected']));
        $client->request('GET', 'http://www.example.com/foo/foobar');

        $this->assertEquals('http://www.example.com/redirected', $client->getRequest()->getUri(), '->followRedirect() follows relative URLs');

        $client = new TestHttpClient();
        $client->setNextResponse(new Response('', 302, ['Location' => '//www.example.org/']));
        $client->request('GET', 'https://www.example.com/');

        $this->assertEquals('https://www.example.org/', $client->getRequest()->getUri(), '->followRedirect() follows protocol-relative URLs');

        $client = new TestHttpClient();
        $client->setNextResponse(new Response('', 302, ['Location' => 'http://www.example.com/redirected']));
        $client->request('POST', 'http://www.example.com/foo/foobar', ['name' => 'bar']);

        $this->assertEquals('GET', $client->getRequest()->getMethod(), '->followRedirect() uses a GET for 302');
        $this->assertEquals([], $client->getRequest()->getParameters(), '->followRedirect() does not submit parameters when changing the method');
    }

    public function testFollowRedirectWithCookies()
    {
        $client = new TestHttpClient();
        $client->followRedirects(false);
        $client->setNextResponse(new Response('', 302, [
            'Location' => 'http://www.example.com/redirected',
            'Set-Cookie' => 'foo=bar',
        ]));
        $client->request('GET', 'http://www.example.com/');
        $this->assertEquals([], $client->getRequest()->getCookies());
        $client->followRedirect();
        $this->assertEquals(['foo' => 'bar'], $client->getRequest()->getCookies());
    }

    public function testFollowRedirectWithHeaders()
    {
        $headers = [
            'HTTP_HOST' => 'www.example.com',
            'HTTP_USER_AGENT' => 'Symfony BrowserKit',
            'CONTENT_TYPE' => 'application/vnd.custom+xml',
            'HTTPS' => false,
        ];

        $client = new TestHttpClient();
        $client->followRedirects(false);
        $client->setNextResponse(new Response('', 302, [
            'Location' => 'http://www.example.com/redirected',
        ]));
        $client->request('GET', 'http://www.example.com/', [], [], [
            'CONTENT_TYPE' => 'application/vnd.custom+xml',
        ]);

        $this->assertEquals($headers, $client->getRequest()->getServer());

        $client->followRedirect();

        $headers['HTTP_REFERER'] = 'http://www.example.com/';

        $this->assertEquals($headers, $client->getRequest()->getServer());
    }

    public function testFollowRedirectWithPort()
    {
        $headers = [
            'HTTP_HOST' => 'www.example.com:8080',
            'HTTP_USER_AGENT' => 'Symfony BrowserKit',
            'HTTPS' => false,
            'HTTP_REFERER' => 'http://www.example.com:8080/',
        ];

        $client = new TestHttpClient();
        $client->setNextResponse(new Response('', 302, [
            'Location' => 'http://www.example.com:8080/redirected',
        ]));
        $client->request('GET', 'http://www.example.com:8080/');

        $this->assertEquals($headers, $client->getRequest()->getServer());
    }

    public function testIsFollowingRedirects()
    {
        $client = new TestHttpClient();
        $this->assertTrue($client->isFollowingRedirects(), '->getFollowRedirects() returns default value');
        $client->followRedirects(false);
        $this->assertFalse($client->isFollowingRedirects(), '->getFollowRedirects() returns assigned value');
    }

    public function testGetMaxRedirects()
    {
        $client = new TestHttpClient();
        $this->assertEquals(-1, $client->getMaxRedirects(), '->getMaxRedirects() returns default value');
        $client->setMaxRedirects(3);
        $this->assertEquals(3, $client->getMaxRedirects(), '->getMaxRedirects() returns assigned value');
    }

    public function testFollowRedirectWithPostMethod()
    {
        $parameters = ['foo' => 'bar'];
        $files = ['myfile.foo' => 'baz'];
        $server = ['X_TEST_FOO' => 'bazbar'];
        $content = 'foobarbaz';

        $client = new TestHttpClient();

        $client->setNextResponse(new Response('', 307, ['Location' => 'http://www.example.com/redirected']));
        $client->request('POST', 'http://www.example.com/foo/foobar', $parameters, $files, $server, $content);

        $this->assertEquals('http://www.example.com/redirected', $client->getRequest()->getUri(), '->followRedirect() follows a redirect with POST method');
        $this->assertArrayHasKey('foo', $client->getRequest()->getParameters(), '->followRedirect() keeps parameters with POST method');
        $this->assertArrayHasKey('myfile.foo', $client->getRequest()->getFiles(), '->followRedirect() keeps files with POST method');
        $this->assertArrayHasKey('X_TEST_FOO', $client->getRequest()->getServer(), '->followRedirect() keeps $_SERVER with POST method');
        $this->assertEquals($content, $client->getRequest()->getContent(), '->followRedirect() keeps content with POST method');
        $this->assertEquals('POST', $client->getRequest()->getMethod(), '->followRedirect() keeps request method');
    }

    public function testFollowRedirectDropPostMethod()
    {
        $parameters = ['foo' => 'bar'];
        $files = ['myfile.foo' => 'baz'];
        $server = ['X_TEST_FOO' => 'bazbar'];
        $content = 'foobarbaz';

        $client = new TestHttpClient();

        foreach ([301, 302, 303] as $code) {
            $client->setNextResponse(new Response('', $code, ['Location' => 'http://www.example.com/redirected']));
            $client->request('POST', 'http://www.example.com/foo/foobar', $parameters, $files, $server, $content);

            $this->assertEquals('http://www.example.com/redirected', $client->getRequest()->getUri(), '->followRedirect() follows a redirect with POST method on response code: '.$code.'.');
            $this->assertEmpty($client->getRequest()->getParameters(), '->followRedirect() drops parameters with POST method on response code: '.$code.'.');
            $this->assertEmpty($client->getRequest()->getFiles(), '->followRedirect() drops files with POST method on response code: '.$code.'.');
            $this->assertArrayHasKey('X_TEST_FOO', $client->getRequest()->getServer(), '->followRedirect() keeps $_SERVER with POST method on response code: '.$code.'.');
            $this->assertEmpty($client->getRequest()->getContent(), '->followRedirect() drops content with POST method on response code: '.$code.'.');
            $this->assertEquals('GET', $client->getRequest()->getMethod(), '->followRedirect() drops request method to GET on response code: '.$code.'.');
        }
    }

    /**
     * @dataProvider getTestsForMetaRefresh
     */
    public function testFollowMetaRefresh(string $content, string $expectedEndingUrl, bool $followMetaRefresh = true)
    {
        $client = new TestHttpClient();
        $client->followMetaRefresh($followMetaRefresh);
        $client->setNextResponse(new Response($content));
        $client->request('GET', 'http://www.example.com/foo/foobar');
        $this->assertEquals($expectedEndingUrl, $client->getRequest()->getUri());
    }

    public function getTestsForMetaRefresh()
    {
        return [
            ['<html><head><meta http-equiv="Refresh" content="4" /><meta http-equiv="refresh" content="0; URL=http://www.example.com/redirected"/></head></html>', 'http://www.example.com/redirected'],
            ['<html><head><meta http-equiv="refresh" content="0;URL=http://www.example.com/redirected"/></head></html>', 'http://www.example.com/redirected'],
            ['<html><head><meta http-equiv="refresh" content="0;URL=\'http://www.example.com/redirected\'"/></head></html>', 'http://www.example.com/redirected'],
            ['<html><head><meta http-equiv="refresh" content=\'0;URL="http://www.example.com/redirected"\'/></head></html>', 'http://www.example.com/redirected'],
            ['<html><head><meta http-equiv="refresh" content="0; URL = http://www.example.com/redirected"/></head></html>', 'http://www.example.com/redirected'],
            ['<html><head><meta http-equiv="refresh" content="0;URL= http://www.example.com/redirected  "/></head></html>', 'http://www.example.com/redirected'],
            ['<html><head><meta http-equiv="refresh" content="0;url=http://www.example.com/redirected  "/></head></html>', 'http://www.example.com/redirected'],
            ['<html><head><noscript><meta http-equiv="refresh" content="0;URL=http://www.example.com/redirected"/></noscript></head></head></html>', 'http://www.example.com/redirected'],
            // Non-zero timeout should not result in a redirect.
            ['<html><head><meta http-equiv="refresh" content="4; URL=http://www.example.com/redirected"/></head></html>', 'http://www.example.com/foo/foobar'],
            ['<html><body></body></html>', 'http://www.example.com/foo/foobar'],
            // Invalid meta tag placement should not result in a redirect.
            ['<html><body><meta http-equiv="refresh" content="0;url=http://www.example.com/redirected"/></body></html>', 'http://www.example.com/foo/foobar'],
            // Valid meta refresh should not be followed if disabled.
            ['<html><head><meta http-equiv="refresh" content="0;URL=http://www.example.com/redirected"/></head></html>', 'http://www.example.com/foo/foobar', false],
        ];
    }

    public function testBack()
    {
        $client = new TestHttpClient();

        $parameters = ['foo' => 'bar'];
        $files = ['myfile.foo' => 'baz'];
        $server = ['X_TEST_FOO' => 'bazbar'];
        $content = 'foobarbaz';

        $client->request('GET', 'http://www.example.com/foo/foobar', $parameters, $files, $server, $content);
        $client->request('GET', 'http://www.example.com/foo');
        $client->back();

        $this->assertEquals('http://www.example.com/foo/foobar', $client->getRequest()->getUri(), '->back() goes back in the history');
        $this->assertArrayHasKey('foo', $client->getRequest()->getParameters(), '->back() keeps parameters');
        $this->assertArrayHasKey('myfile.foo', $client->getRequest()->getFiles(), '->back() keeps files');
        $this->assertArrayHasKey('X_TEST_FOO', $client->getRequest()->getServer(), '->back() keeps $_SERVER');
        $this->assertEquals($content, $client->getRequest()->getContent(), '->back() keeps content');
    }

    public function testForward()
    {
        $client = new TestHttpClient();

        $parameters = ['foo' => 'bar'];
        $files = ['myfile.foo' => 'baz'];
        $server = ['X_TEST_FOO' => 'bazbar'];
        $content = 'foobarbaz';

        $client->request('GET', 'http://www.example.com/foo/foobar');
        $client->request('GET', 'http://www.example.com/foo', $parameters, $files, $server, $content);
        $client->back();
        $client->forward();

        $this->assertEquals('http://www.example.com/foo', $client->getRequest()->getUri(), '->forward() goes forward in the history');
        $this->assertArrayHasKey('foo', $client->getRequest()->getParameters(), '->forward() keeps parameters');
        $this->assertArrayHasKey('myfile.foo', $client->getRequest()->getFiles(), '->forward() keeps files');
        $this->assertArrayHasKey('X_TEST_FOO', $client->getRequest()->getServer(), '->forward() keeps $_SERVER');
        $this->assertEquals($content, $client->getRequest()->getContent(), '->forward() keeps content');
    }

    public function testBackAndFrowardWithRedirects()
    {
        $client = new TestHttpClient();

        $client->request('GET', 'http://www.example.com/foo');
        $client->setNextResponse(new Response('', 301, ['Location' => 'http://www.example.com/redirected']));
        $client->request('GET', 'http://www.example.com/bar');

        $this->assertEquals('http://www.example.com/redirected', $client->getRequest()->getUri(), 'client followed redirect');

        $client->back();

        $this->assertEquals('http://www.example.com/foo', $client->getRequest()->getUri(), '->back() goes back in the history skipping redirects');

        $client->forward();

        $this->assertEquals('http://www.example.com/redirected', $client->getRequest()->getUri(), '->forward() goes forward in the history skipping redirects');
    }

    public function testReload()
    {
        $client = new TestHttpClient();

        $parameters = ['foo' => 'bar'];
        $files = ['myfile.foo' => 'baz'];
        $server = ['X_TEST_FOO' => 'bazbar'];
        $content = 'foobarbaz';

        $client->request('GET', 'http://www.example.com/foo/foobar', $parameters, $files, $server, $content);
        $client->reload();

        $this->assertEquals('http://www.example.com/foo/foobar', $client->getRequest()->getUri(), '->reload() reloads the current page');
        $this->assertArrayHasKey('foo', $client->getRequest()->getParameters(), '->reload() keeps parameters');
        $this->assertArrayHasKey('myfile.foo', $client->getRequest()->getFiles(), '->reload() keeps files');
        $this->assertArrayHasKey('X_TEST_FOO', $client->getRequest()->getServer(), '->reload() keeps $_SERVER');
        $this->assertEquals($content, $client->getRequest()->getContent(), '->reload() keeps content');
    }

    public function testRestart()
    {
        $client = new TestHttpClient();
        $client->request('GET', 'http://www.example.com/foo/foobar');
        $client->restart();

        $this->assertTrue($client->getHistory()->isEmpty(), '->restart() clears the history');
        $this->assertEquals([], $client->getCookieJar()->all(), '->restart() clears the cookies');
    }

    public function testGetServerParameter()
    {
        $client = new TestHttpClient();
        $this->assertEquals('', $client->getServerParameter('HTTP_HOST'));
        $this->assertEquals('Symfony BrowserKit', $client->getServerParameter('HTTP_USER_AGENT'));
        $this->assertEquals('testvalue', $client->getServerParameter('testkey', 'testvalue'));
    }

    public function testSetServerParameter()
    {
        $client = new TestHttpClient();

        $this->assertEquals('', $client->getServerParameter('HTTP_HOST'));
        $this->assertEquals('Symfony BrowserKit', $client->getServerParameter('HTTP_USER_AGENT'));

        $client->setServerParameter('HTTP_HOST', 'testhost');
        $this->assertEquals('testhost', $client->getServerParameter('HTTP_HOST'));

        $client->setServerParameter('HTTP_USER_AGENT', 'testua');
        $this->assertEquals('testua', $client->getServerParameter('HTTP_USER_AGENT'));
    }

    public function testSetServerParameterInRequest()
    {
        $client = new TestHttpClient();

        $this->assertEquals('', $client->getServerParameter('HTTP_HOST'));
        $this->assertEquals('Symfony BrowserKit', $client->getServerParameter('HTTP_USER_AGENT'));

        $client->request('GET', 'https://www.example.com/https/www.example.com', [], [], [
            'HTTP_HOST' => 'testhost',
            'HTTP_USER_AGENT' => 'testua',
            'HTTPS' => false,
            'NEW_SERVER_KEY' => 'new-server-key-value',
        ]);

        $this->assertEquals('', $client->getServerParameter('HTTP_HOST'));
        $this->assertEquals('Symfony BrowserKit', $client->getServerParameter('HTTP_USER_AGENT'));

        $this->assertEquals('https://www.example.com/https/www.example.com', $client->getRequest()->getUri());

        $server = $client->getRequest()->getServer();

        $this->assertArrayHasKey('HTTP_USER_AGENT', $server);
        $this->assertEquals('testua', $server['HTTP_USER_AGENT']);

        $this->assertArrayHasKey('HTTP_HOST', $server);
        $this->assertEquals('testhost', $server['HTTP_HOST']);

        $this->assertArrayHasKey('NEW_SERVER_KEY', $server);
        $this->assertEquals('new-server-key-value', $server['NEW_SERVER_KEY']);

        $this->assertArrayHasKey('HTTPS', $server);
        $this->assertTrue($server['HTTPS']);
    }

    public function testRequestWithRelativeUri()
    {
        $client = new TestHttpClient();

        $client->request('GET', '/', [], [], [
            'HTTP_HOST' => 'testhost',
            'HTTPS' => true,
        ]);
        $this->assertEquals('https://testhost/', $client->getRequest()->getUri());

        $client->request('GET', 'https://www.example.com/', [], [], [
            'HTTP_HOST' => 'testhost',
            'HTTPS' => false,
        ]);
        $this->assertEquals('https://www.example.com/', $client->getRequest()->getUri());
    }

    public function testInternalRequest()
    {
        $client = new TestHttpClient();

        $client->request('GET', 'https://www.example.com/https/www.example.com', [], [], [
            'HTTP_HOST' => 'testhost',
            'HTTP_USER_AGENT' => 'testua',
            'HTTPS' => false,
            'NEW_SERVER_KEY' => 'new-server-key-value',
        ]);

        $this->assertInstanceOf(Request::class, $client->getInternalRequest());
    }
}
