<?php

namespace Fuzz\Felk\Middleware;

use Closure;
use Fuzz\Felk\Contracts\Logger;
use Fuzz\Felk\Facades\DBProfiler;
use Fuzz\Felk\Logging\APIRequestEvent;
use Fuzz\Felk\Logging\QueryProfiler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class FelkMiddleware
 *
 * FelkMiddleware dumps information about the request to the log.
 *
 * @package Fuzz\Felk\Middleware
 */
class FelkMiddleware
{
	/**
	 * String identifying the AWS ELB health checker user agent.
	 */
	const ELB_HEALTH_CHECKER_AGENT = 'ELB-HealthChecker/1.0';
	const REQUEST_ID_HEADER        = 'X-Request-Id';

	/**
	 * Logger storage
	 *
	 * @var \Fuzz\Felk\Contracts\Logger
	 */
	private $logger;

	/**
	 * FelkMiddleware constructor.
	 *
	 * @param Logger $logger
	 */
	public function __construct(Logger $logger)
	{
		$this->logger = $logger;
	}

	/**
	 * Handle an incoming request.
	 *
	 * @param  \Illuminate\Http\Request $request
	 * @param  \Closure                 $next
	 *
	 * @return mixed
	 */
	public function handle(Request $request, Closure $next)
	{
		if ($this->dbLogEnabled()) {
			app()->singleton(DBProfiler::class, function () use ($request) {
				return new QueryProfiler($request);
			});
		}

		return $next($request);
	}

	/**
	 * Handle some logic after the response has been sent to the browser
	 *
	 * @param \Illuminate\Http\Request                   $request
	 * @param \Symfony\Component\HttpFoundation\Response $response
	 *
	 * @return bool
	 */
	public function terminate(Request $request, Response $response): bool
	{
		$config = config('felk');

		$this->flushRequestLog($request, $response, $config);

		if ($this->dbLogEnabled()) {
			$this->flushDBLog($config);
		}

		return true;
	}

	/**
	 * Is the DB Log enabled?
	 *
	 * @return bool
	 */
	public function dbLogEnabled(): bool
	{
		return app()->environment(config('felk.db_profiler.enabled_environments'));
	}

	/**
	 * Flush the APIRequest event
	 *
	 * This response time calculation has a couple of caveats:
	 * 1. If run with Apache, middleware terminate methods are not called before the request is dumped to the
	 *        client. As a result, there's still the terminate processing occuring as part of the response timeline.
	 * 2. This is dependent on where in the middleware stack this terminate method is called. Closer to the first
	 *        middleware called will result in a more accurate response time estimate. By necessity this terminate
	 *        method is called AFTER the response is written and will always be slightly longer than the actual
	 *        response time.
	 *
	 * @param \Illuminate\Http\Request                   $request
	 * @param \Symfony\Component\HttpFoundation\Response $response
	 * @param array                                      $config
	 *
	 * @return bool
	 */
	protected function flushRequestLog(Request $request, Response $response, array $config): bool
	{
		$response_time_ms = 0;

		if (defined('LARAVEL_START')) {
			$response_time_ms = round((microtime(true) - LARAVEL_START) * 1000);
		}

		$request_id = $response->headers->has(self::REQUEST_ID_HEADER) ?
			$response->headers->get(self::REQUEST_ID_HEADER) : null;

		try {
			if (! App::environment($config['enabled_environments']) || $request->header('User-Agent') === self::ELB_HEALTH_CHECKER_AGENT) {
				return false;
			}

			$event = APIRequestEvent::factory($request, $response, $response_time_ms, time(), $request_id);

			$this->logger->write($event, $config['force_safe'] ?? true);

			return true;
		} catch (\Exception $err) {
			return false;
		}
	}

	/**
	 * Flush the DB log to the logger
	 *
	 * @param array $config
	 */
	protected function flushDBLog(array $config)
	{
		$this->logger->write(DBProfiler::getLoggableEvent(), $config['force_safe'] ?? true);
	}
}
