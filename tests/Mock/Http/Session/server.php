<?php
declare(strict_types=1);

use Fyre\Core\Config;
use Fyre\Core\Container;
use Fyre\Http\Session\Exceptions\SessionException;
use Fyre\Http\Session\Session;

require __DIR__.'/../../../bootstrap.php';

$container = new Container();
$container->singleton(Config::class);
$container->use(Config::class)->set('Session', [
    'path' => ini_get('session.save_path'),
    'expires' => 60,
    'allowReadOnly' => true,
    'cookie' => [
        'secure' => false,
    ],
]);

$session = $container->use(Session::class);

try {
    switch ($_GET['action'] ?? 'start') {
        case 'close':
            $session->set('test', 'value');
            $session->close();
            $_SESSION['test'] = 'changed after close';
            $data = [
                'active' => $session->isActive(),
                'started' => $session->isStarted(),
            ];
            break;
        case 'expire':
            $session->set('test', 'value');
            $_SESSION['_last_activity'] = time() - 61;
            $data = $session->get('test');
            break;
        case 'read':
            $data = $session->get('test');
            break;
        case 'read-only':
            $session->startReadOnly();
            $data = $session->get('test');
            break;
        case 'read-only-state':
            $session->startReadOnly();
            $data = [
                'active' => $session->isActive(),
                'started' => $session->isStarted(),
            ];
            break;
        case 'read-only-write':
            $session->startReadOnly();

            switch ($_GET['operation'] ?? '') {
                case 'clear':
                    $session->clear();
                    break;
                case 'delete':
                    $session->delete('test');
                    break;
                case 'set':
                    $session->set('test', 'changed');
                    break;
            }

            $data = null;
            break;
        case 'refresh':
            $session->refresh(true);
            $data = $session->id();
            break;
        default:
            $session->start();
            $data = [
                'active' => $session->isActive(),
                'started' => $session->isStarted(),
            ];
            break;
    }
} catch (SessionException $e) {
    http_response_code(409);
    $data = $e->getMessage();
}

$session->close();
header('Content-Type: application/json');
echo json_encode($data);
