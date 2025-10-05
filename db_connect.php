<?php
// db_connect.php
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';           // change if you set a password
$DB_NAME = 'sra_db';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
  http_response_code(500);
  die('DB connection failed: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
