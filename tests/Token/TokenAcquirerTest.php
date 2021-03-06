<?php

namespace tests\Dsl\MyTarget\Token;

use Dsl\MyTarget\Context;
use Dsl\MyTarget\Token\Token;
use Dsl\MyTarget\Token\TokenAcquirer;
use GuzzleHttp\Psr7\Uri;
use Dsl\MyTarget\Token\ClientCredentials\Credentials;
use Dsl\MyTarget\Token\Exception\TokenDeletedException;
use Dsl\MyTarget\Token\Exception\TokenLimitReachedException;
use Dsl\MyTarget\Token\Exception\TokenRequestException;
use Dsl\MyTarget\Transport\HttpTransport;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use GuzzleHttp\Psr7;

class TokenAcquirerTest extends \PHPUnit_Framework_TestCase
{
    /** @var  UriInterface */
    private $baseAddress;
    /** @var  HttpTransport|\PHPUnit_Framework_MockObject_MockObject */
    private $http;
    /** @var  Credentials */
    private $credentials;

    /** @var  TokenAcquirer */
    private $acquirer;

    /** @var  RequestInterface|\PHPUnit_Framework_MockObject_MockObject */
    private $request;
    /** @var  ResponseInterface|\PHPUnit_Framework_MockObject_MockObject */
    private $response;

    public function setUp()
    {
        parent::setUp();

        $this->baseAddress = new Uri('http://socialkey.ru/');
        $this->http = $this->getMock(HttpTransport::class);
        $this->credentials = new Credentials('id-132', 'secret-t-t-t');

        $this->acquirer = new TokenAcquirer($this->baseAddress, $this->http, $this->credentials);

        $this->request = $this->getMock(RequestInterface::class);
        $this->response = $this->getMock(ResponseInterface::class);
    }

    /**
     * @return array
     */
    public function successfulAcquireDataProvider()
    {
        return [
            [new Context(), 200, '{"access_token": "secret", "token_type": "super type", "expires_in": 100, "refresh_token": "refresh secret"}'],
            [new Context('user@agency', null, ["test" => 123]), 200, '{"access_token": "secret", "token_type": "super type", "expires_in": 100, "refresh_token": "refresh secret"}'],
        ];
    }

    /**
     * @param Context|null  $context
     * @param int         $statusCode
     * @param string      $body
     *
     * @dataProvider successfulAcquireDataProvider
     */
    public function testSuccessfulAcquire($context, $statusCode, $body)
    {
        $now = new \DateTimeImmutable();

        $username = $context->getUsername();
        $payload = [
            'grant_type'    => $username === null ? 'client_credentials' : 'agency_client_credentials',
            'client_id'     => $this->credentials->getClientId(),
            'client_secret' => $this->credentials->getClientSecret(),
        ];
        if ($username) {
            $payload['agency_client_name'] = $username;
        }

        $this->response->method('getBody')->willReturn(Psr7\stream_for($body));

        $this->response->method('getStatusCode')->willReturn($statusCode);

        $this->http->method('request')->will(
            $this->returnCallback(function (RequestInterface $request) use ($payload) {
                $this->assertSame('POST', $request->getMethod());
                $this->assertSame(['Host' => ['socialkey.ru'], 'Content-Type' => ['application/x-www-form-urlencoded']], $request->getHeaders());
                $this->assertEquals($this->baseAddress->withPath(TokenAcquirer::TOKEN_URL), $request->getUri());
                $this->assertSame(Psr7\build_query($payload), (string) $request->getBody());

                return $this->response;
            })
        );

        $token = $this->acquirer->acquire($this->request, $now, $context);

        $this->assertEquals(new Token('secret', 'super type', $now->add(new \DateInterval('PT100S')), 'refresh secret'), $token);
    }

    /**
     * @return array
     */
    public function failedAcquireDataProvider()
    {
        return [
            [new Context(null, null, ["test" => 123]), 200, '', TokenRequestException::class],
            [new Context(null, null, ["test" => 123]), 200, '{}', TokenRequestException::class],
            [new Context(null, null, ["test" => 123]), 200, '{"access_token": "secret"}', TokenRequestException::class],
            [new Context(), 403, '', TokenRequestException::class],
            [new Context(), 403, 'limit reached', TokenLimitReachedException::class],
            [new Context(), -1, '', TokenRequestException::class],
        ];
    }

    /**
     * @param string|null $username
     * @param Context|null  $context
     * @param int         $statusCode
     * @param string      $body
     * @param string      $exception
     *
     * @dataProvider failedAcquireDataProvider
     */
    public function testFailedAcquire($context, $statusCode, $body, $exception)
    {
        $now = new \DateTimeImmutable();

        $this->setExpectedException($exception);

        $this->response->method('getBody')->willReturn(Psr7\stream_for($body));

        $this->response->method('getStatusCode')->willReturn($statusCode);

        $this->http->method('request')->willReturn($this->response);

        $this->acquirer->acquire($this->request, $now, $context);
    }

    /**
     * @return array
     */
    public function successfulRefreshDataProvider()
    {
        return [
            [new Context(), 200, '{"access_token": "secret", "token_type": "super type", "expires_in": 100, "refresh_token": "refresh secret"}'],
            [new Context(null, null, ["test" => 123]), 200, '{"access_token": "secret", "token_type": "super type", "expires_in": 100, "refresh_token": "refresh secret"}'],
        ];
    }

    /**
     * @param $context
     * @param $statusCode
     * @param $body
     *
     * @dataProvider successfulRefreshDataProvider
     */
    public function testSuccessfulRefresh($context, $statusCode, $body)
    {
        $now = new \DateTimeImmutable();

        $payload = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => 'refresh secret lol',
            'client_id'     => $this->credentials->getClientId(),
            'client_secret' => $this->credentials->getClientSecret()
        ];

        $this->response->method('getBody')->willReturn(Psr7\stream_for($body));

        $this->response->method('getStatusCode')->willReturn($statusCode);

        $this->http->method('request')->will(
            $this->returnCallback(function (RequestInterface $request) use ($payload) {
                $this->assertSame('POST', $request->getMethod());
                $this->assertSame(['Host' => ['socialkey.ru'], 'Content-Type' => ['application/x-www-form-urlencoded']], $request->getHeaders());
                $this->assertEquals($this->baseAddress->withPath(TokenAcquirer::TOKEN_URL), $request->getUri());
                $this->assertSame(Psr7\build_query($payload), (string) $request->getBody());

                return $this->response;
            })
        );

        $token = $this->acquirer->refresh($this->request, $now, 'refresh secret lol', $context);

        $this->assertEquals(new Token('secret', 'super type', $now->add(new \DateInterval('PT100S')), 'refresh secret'), $token);
    }

    /**
     * @return array
     */
    public function failedRefreshDataProvider()
    {
        return [
            [new Context(null, null, ["test" => 123]), 200, '', TokenRequestException::class],
            [new Context(null, null, ["test" => 123]), 200, '{}', TokenRequestException::class],
            [new Context(null, null, ["test" => 123]), 200, '{"access_token": "secret"}', TokenRequestException::class],
            [new Context(), 401, '', TokenRequestException::class],
            [new Context(), 401, 'deleted', TokenDeletedException::class],
            [new Context(), -1, '', TokenRequestException::class],
        ];
    }

    /**
     * @param $context
     * @param $statusCode
     * @param $body
     * @param $exception
     *
     * @dataProvider failedRefreshDataProvider
     */
    public function testFailedRefresh($context, $statusCode, $body, $exception)
    {
        $now = new \DateTimeImmutable();

        $this->setExpectedException($exception);

        $this->response->method('getBody')->willReturn(Psr7\stream_for($body));

        $this->response->method('getStatusCode')->willReturn($statusCode);

        $this->http->method('request')->willReturn($this->response);

        $this->acquirer->refresh($this->request, $now, 'refresh secret lol', $context);
    }

}
