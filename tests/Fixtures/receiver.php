<?php

// Loopback-only test receiver: real sockets, synthetic credentials, no notifications.
$state = getenv('SM_TEST_REQUESTS');
$request = ['body' => file_get_contents('php://input'), 'headers' => array_change_key_case(getallheaders(), CASE_LOWER)];
file_put_contents($state, json_encode($request).PHP_EOL, FILE_APPEND | LOCK_EX);
$attempt = count(file($state, FILE_IGNORE_NEW_LINES));
if ($attempt === 1) {
    $first = getenv('SM_TEST_FIRST_RESPONSE');
    if ($first === 'timeout') {
        usleep(300000);
    } else {
        http_response_code((int) $first);
        header('Retry-After: 1');
        if ($first === '301') {
            header('Location: /redirected');
        }
        echo '{"error":"password=synthetic-response-secret"}';

        return;
    }
}
http_response_code(202);
echo '{"success":true}';
