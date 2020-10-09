<?php

require_once __DIR__ . '/vendor/autoload.php';

$redisClientUri = 'redis://127.0.0.1:6000';
$socketServerUri = '127.0.0.1:8085'; // host, port, etc

$loop = \React\EventLoop\Factory::create();
$connections = [];

// http server
$server = new \React\Http\Server($loop, function (\Psr\Http\Message\ServerRequestInterface $request) use (&$connections, $socketServerUri) {
    $path = $request->getUri()->getPath();

    if ($path === '/') {
        return new \React\Http\Message\Response(
            200,
            [
                'Content-Type' => 'text/html',
                'Set-Cookie' => 'poll_id' . '=' . md5(time())
            ],
            str_replace('__SERVER_URL__', $socketServerUri, file_get_contents('poll.html'))
        );
    }

    if ($path === '/poll') {
        if (!isset($request->getCookieParams()['poll_id'])) {
            return new \React\Http\Message\Response(
                400,
                ['Content-Type' => 'text/html'],
                'The request does not contain poll id'
            );
        }

        $pollId = $request->getCookieParams()['poll_id'];

        $stream = new \React\Stream\ThroughStream();
        $connections[$pollId] = $stream;

        return new \React\Http\Message\Response(
            200,
            ['Content-Type' => 'text/plain'],
            $stream
        );
    }

    return new \React\Http\Message\Response(
        404,
        ['Content-Type' => 'text/html'],
        'Not Found'
    );
});

// socket server
$socket = new \React\Socket\Server($socketServerUri, $loop);
$server->listen($socket);

echo sprintf("Server running at %s\n", $socket->getAddress());

/**
 * Redis Client
 */
$factory = new \Clue\React\Redis\Factory($loop);
$client = $factory->createLazyClient($redisClientUri);

$client
    ->psubscribe('test')
    ->then(function () {
        echo "New Redis subscription" . PHP_EOL;
    }, function (\Exception $exception) use ($client) {
        $client->close();
    });

$client->on('pmessage', function ($pattern, $channel, $message) use (&$connections) {
    echo 'pmessage' . $message . count($connections) . PHP_EOL;
    foreach ($connections as $connection) {
        /** @var \React\Stream\ThroughStream $connection */
        $connection->end($message);
    }
});

/**
 * Start Loop
 */
$loop->run();
