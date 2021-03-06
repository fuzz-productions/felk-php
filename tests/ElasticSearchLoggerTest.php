<?php

namespace Fuzz\Felk\Tests;

use Elasticsearch\Client;
use Fuzz\Felk\Logging\APIRequestEvent;
use Fuzz\Felk\Logging\ElasticSearchLogger;
use Mockery;
use PHPUnit_Framework_TestCase;

class ElasticSearchLoggerTest extends TestCase
{
	public function testItCanWriteToLogger()
	{
		$client = Mockery::mock(Client::class);

		$logger = new ElasticSearchLogger($client, 'FooApp');

		$event = Mockery::mock(APIRequestEvent::class);
		$body = [
			'timestamp'        => time(),
			'method'           => 'GET',
			'host'             => 'https://felk.com',
			'route'            => 'foo/bar?baz=bat',
			'status_code'      => 200,
			'request_headers'  => json_encode([
				'req_foo' => ['bar'],
				'req_baz' => ['bat'],
			]),
			'request_body'     => 'foo=bar&baz=foo',
			'response_headers' => json_encode([
				'res_foo' => ['bar'],
				'res_baz' => ['bat'],
			]),
			'response_body'    => 'baz=foo&foo=bar',
			'ip'               => '52.63.25.56',
			'scheme'           => 'https',
			'port'             => '80',
			'environment'      => 'some_cool_test_env',
		];
		$event->shouldReceive('toArray')->once()->andReturn($body);
		$event->shouldReceive('getUniqueId')->once()->andReturn('someUniqueId');

		$client->shouldReceive('index')->once()->with([
			'index' => 'fooapp_felk',
			'type'  => 'felk_log',
			'id'    => 'someUniqueId',
			'body'  => $body,
		])->andReturn(['success' => true]);

		$this->assertSame(['success' => true], $logger->write($event));
	}
}