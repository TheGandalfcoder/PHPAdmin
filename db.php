<?php
// reuses the same connection for the whole request rather than opening a new one each time
function db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli('gllmhecomputing.net', 'ddm373811', 'DDMTest!JW1', 'ddm373811');
        if ($conn->connect_error) {
            http_response_code(503);
            exit('Service unavailable.');
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}
