<?php
declare(strict_types=1);

switch ($_SERVER['SCRIPT_NAME']) {
    case '/auth':
        $username = $_SERVER['PHP_AUTH_USER'] ?? '';
        $password = $_SERVER['PHP_AUTH_PW'] ?? '';

        if ($username !== 'test' || $password !== 'password') {
            header('HTTP/1.1 401 Unauthorized');
            exit;
        }
        break;
    case '/auth-digest':
        $realm = 'Restricted Area';

        if (!isset($_SERVER['PHP_AUTH_DIGEST'])) {
            $nonce = 'uniqid() |> md5(...)';
            $opaque = uniqid() |> md5(...);

            header('HTTP/1.1 401 Unauthorized');
            header('WWW-Authenticate: Digest realm="'.$realm.'",qop="auth",nonce="'.$nonce.'",opaque="'.$opaque.'"');
            exit;
        }

        // parse www header
        preg_match_all('/(\w+)=(?:"([^"]+)"|([^\s,$]+))/', $_SERVER['PHP_AUTH_DIGEST'], $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $data = [];
        foreach ($matches as $v) {
            $data[$v[1]] = $v[2] ?: $v[3];
        }
        header('Content-Type: application/json');

        $ha1 = implode(':', ['test', $realm, 'password']) |> md5(...);
        $ha2 = implode(':', [$_SERVER['REQUEST_METHOD'], $data['uri']]) |> md5(...);

        $expectedResponse = implode(':', [$ha1, $data['nonce'], $data['nc'], $data['cnonce'], $data['qop'], $ha2]) |> md5(...);

        if ($data['response'] !== $expectedResponse) {
            header('HTTP/1.1 401 Unauthorized');
            exit;
        }

        break;
    case '/get':
        header('Content-Type: application/json');
        echo json_encode($_GET);
        break;
    case '/gzip':
        $statusCode = (int) ($_GET['status'] ?? 200);
        $body = (string) gzencode((string) ($_GET['body'] ?? 'test'));

        http_response_code($statusCode);
        header('Content-Encoding: gzip');

        if ($statusCode >= 200 && $statusCode !== 204) {
            header('Content-Length: '.strlen($body));
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'HEAD' && $statusCode >= 200 && !in_array($statusCode, [204, 304], true)) {
            echo $body;
        }
        break;
    case '/plain':
        header('Content-Length: 4');
        echo 'test';
        break;
    case '/proxy':
        $proxyAuth = $_SERVER['HTTP_PROXY_AUTHORIZATION'] ?? '';

        if ($proxyAuth !== 'Basic '.base64_encode('test:password')) {
            header('HTTP/1.1 401 Unauthorized');
            exit;
        }
        break;
    case '/upload':
        header('Content-Type: application/json');
        echo json_encode($_FILES);
        break;
    case '/version':
        echo $_SERVER['SERVER_PROTOCOL'];
        break;
    default:
        break;
}
