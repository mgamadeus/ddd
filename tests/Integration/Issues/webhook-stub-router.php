<?php

declare(strict_types=1);

// Router of the webhook stub (php -S) of LokiIssuesRegistryTest: appends every request body as one line to the file
// named by DDD_TEST_WEBHOOK_STUB_FILE and answers like Slack ("ok").
file_put_contents((string)getenv('DDD_TEST_WEBHOOK_STUB_FILE'), file_get_contents('php://input') . "\n", FILE_APPEND | LOCK_EX);
header('Content-Type: text/plain');
echo 'ok';
