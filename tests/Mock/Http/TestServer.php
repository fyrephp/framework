<?php
declare(strict_types=1);

namespace Tests\Mock\Http;

use RuntimeException;
use Throwable;

use function array_push;
use function fclose;
use function is_resource;
use function is_string;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function rewind;
use function stream_get_contents;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_server;
use function tmpfile;
use function usleep;

use const PHP_BINARY;

class TestServer
{
    /**
     * @var resource|null
     */
    protected $output;

    /**
     * @var resource|null
     */
    protected $process;

    protected string $url;

    /**
     * @param array<string, int|string> $ini
     */
    public function __construct(string $router, array $ini = [])
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if (!is_resource($socket)) {
            throw new RuntimeException('Test server socket could not be created.');
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        if (!is_string($address)) {
            throw new RuntimeException('Test server address could not be determined.');
        }

        $this->url = 'http://'.$address;
        $output = tmpfile();

        if (!is_resource($output)) {
            throw new RuntimeException('Test server output could not be opened.');
        }

        $this->output = $output;

        try {
            $command = [PHP_BINARY];

            foreach ($ini as $name => $value) {
                array_push($command, '-d', $name.'='.$value);
            }

            array_push($command, '-S', $address, $router);

            $process = proc_open(
                $command,
                [
                    0 => ['pipe', 'r'],
                    1 => $output,
                    2 => $output,
                ],
                $pipes
            );

            if (!is_resource($process)) {
                throw new RuntimeException('Test server could not be started.');
            }

            $this->process = $process;
            fclose($pipes[0]);

            for ($i = 0; $i < 500; $i++) {
                $socket = @stream_socket_client('tcp://'.$address, timeout: 0.1);
                $running = proc_get_status($process)['running'];

                if ($socket) {
                    fclose($socket);

                    if ($running) {
                        return;
                    }
                }

                if (!$running) {
                    break;
                }

                usleep(10_000);
            }

            rewind($output);
            $message = stream_get_contents($output);

            throw new RuntimeException('Test server did not become ready: '.$message);
        } catch (Throwable $e) {
            $this->stop();

            throw $e;
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }

        if (is_resource($this->output)) {
            fclose($this->output);
            $this->output = null;
        }
    }
}
