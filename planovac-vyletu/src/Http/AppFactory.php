<?php

declare(strict_types=1);

namespace Planovac\Http;

use GuzzleHttp\Promise\Utils;
use Planovac\ApiException;
use Planovac\ProviderRegistry;
use Planovac\Service\OfferAggregator;
use Planovac\Service\OrderRepository;
use Planovac\Service\ProviderGateway;
use Planovac\Service\TripPlanner;
use Planovac\Validator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory as SlimAppFactory;

final class AppFactory
{
    public const SERVICE = 'Plánovač výletů API';
    public const VERSION = '1.0';

    /**
     * @param \Closure(): string $today vrací dnešní datum YYYY-MM-DD (v testech lze podstrčit)
     */
    public static function create(
        ProviderRegistry $registry,
        ProviderGateway $gateway,
        OfferAggregator $aggregator,
        TripPlanner $planner,
        OrderRepository $orders,
        \Closure $today,
        float $statusTimeout = 1.5,
    ): App {
        $app = SlimAppFactory::create();

        $app->get('/', function (Request $request, Response $response) use ($registry, $gateway, $statusTimeout) {
            // Živá kontrola všech poskytovatelů – paralelně, s krátkým timeoutem.
            $promises = [];
            foreach ($registry->all() as $p) {
                $promises[$p->id] = $gateway->describeAsync($p, min($statusTimeout, $p->timeout));
            }
            $results = Utils::settle($promises)->wait();

            $providers = [];
            foreach ($registry->all() as $p) {
                $r = $results[$p->id];
                $row = $p->toArray();
                if ($r['state'] === 'fulfilled') {
                    $row += ['status' => 'available', 'remote_service' => $r['value']['service'], 'localities' => $r['value']['localities']];
                } else {
                    $row += ['status' => 'unavailable', 'localities' => [], 'reason' => OfferAggregator::reason($r['reason'])];
                }
                $providers[] = $row;
            }
            $localities = array_values(array_unique(array_merge(...array_map(fn ($p) => $p['localities'], $providers)) ?: []));
            sort($localities, SORT_LOCALE_STRING);

            return self::json($response, [
                'service' => self::SERVICE,
                'version' => self::VERSION,
                'description' => 'Orchestrace napojených API: souhrnná nabídka ubytování, atrakcí a počasí pro lokalitu a termín, objednávka výletu.',
                'endpoints' => [
                    ['method' => 'GET', 'path' => '/', 'description' => 'Popis služby a živý stav napojených API'],
                    ['method' => 'GET', 'path' => '/locality/{city}/{date_from}/{date_to}?people=N', 'description' => 'Souhrnná nabídka pro lokalitu a termín'],
                    ['method' => 'POST', 'path' => '/plan-trip', 'description' => 'Vytvoření objednávky výletu a rezervace ubytování'],
                    ['method' => 'GET', 'path' => '/orders/{order_id}', 'description' => 'Detail objednávky'],
                ],
                'localities' => $localities,
                'providers' => $providers,
            ]);
        });

        $app->get('/locality/{city}/{date_from}/{date_to}', function (Request $request, Response $response, array $args) use ($aggregator, $today) {
            $todayDate = $today();
            $v = new Validator($todayDate);
            $people = $request->getQueryParams()['people'] ?? '1';
            $q = $v->stay($args['city'], $args['date_from'], $args['date_to'], $people);
            $v->throwIfInvalid();
            $q['city'] = trim($q['city']);
            return self::json($response, $aggregator->offer($q, $todayDate));
        });

        $app->post('/plan-trip', function (Request $request, Response $response) use ($planner, $today) {
            $order = $planner->plan(self::parseJsonBody($request), $today());
            return self::json($response, $order, 201);
        });

        $app->get('/orders/{order_id}', function (Request $request, Response $response, array $args) use ($orders) {
            $order = $orders->find($args['order_id']);
            if ($order === null) {
                throw new ApiException(404, 'ORDER_NOT_FOUND', "Objednávka '{$args['order_id']}' neexistuje.");
            }
            return self::json($response, $order);
        });

        $app->addRoutingMiddleware();
        $errorMiddleware = $app->addErrorMiddleware(false, false, false);
        $errorMiddleware->setDefaultErrorHandler(function (Request $request, \Throwable $e) use ($app) {
            $response = $app->getResponseFactory()->createResponse();
            if ($e instanceof ApiException) {
                return self::json($response, $e->toBody(), $e->status);
            }
            if ($e instanceof HttpNotFoundException) {
                return self::json($response, self::error('NOT_FOUND', "Endpoint {$request->getMethod()} {$request->getUri()->getPath()} neexistuje."), 404);
            }
            if ($e instanceof HttpMethodNotAllowedException) {
                return self::json($response, self::error('METHOD_NOT_ALLOWED', "Metoda {$request->getMethod()} není pro tento endpoint povolena."), 405)
                    ->withHeader('Allow', implode(', ', $e->getAllowedMethods()));
            }
            error_log((string) $e);
            return self::json($response, self::error('INTERNAL_ERROR', 'Interní chyba serveru.'), 500);
        });

        // CORS hlavičky na všech odpovědích včetně chyb (middleware přidaný jako poslední je nejvíc vně).
        // Preflight (OPTIONS) se odbaví rovnou, bez routování.
        $app->add(function (Request $request, RequestHandlerInterface $handler) use ($app): Response {
            $response = $request->getMethod() === 'OPTIONS'
                ? $app->getResponseFactory()->createResponse(204)
                : $handler->handle($request);
            return $response
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Accept');
        });

        return $app;
    }

    private static function parseJsonBody(Request $request): mixed
    {
        $raw = (string) $request->getBody();
        if (trim($raw) === '') {
            throw new ApiException(400, 'VALIDATION_ERROR', 'Tělo požadavku je prázdné, očekává se JSON objekt.');
        }
        try {
            return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(400, 'INVALID_JSON', 'Tělo požadavku není platný JSON.');
        }
    }

    /** @return array{error: array{code: string, message: string}} */
    private static function error(string $code, string $message): array
    {
        return ['error' => ['code' => $code, 'message' => $message]];
    }

    public static function json(Response $response, mixed $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
