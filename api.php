<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
    header("Access-Control-Max-Age: 3600");
    exit(0);
}

// Database connection details
$dbhost = 'localhost';
$dbuser = 'dbarney_webtools';
$dbpass = 'Ka5Wvw-8FeY5';
$dbname = 'dbarney_webtools';

// Connect to the database
$conn = new mysqli($dbhost, $dbuser, $dbpass, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Get the request body
$requestBody = file_get_contents('php://input');
$data = json_decode($requestBody, true);

if (!isset($data['query']) || !isset($data['params'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing query or params']);
    exit();
}

$query = $data['query'];
$params = $data['params'];

// Prepare and execute the query
$stmt = $conn->prepare($query);

if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Query preparation failed']);
    exit();
}

if (!empty($params)) {
    $types = str_repeat('s', count($params)); // Assume all parameters are strings
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$result = $stmt->get_result();

if ($result === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Query execution failed']);
    exit();
}

// Fetch all results
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

// Close the statement and connection
$stmt->close();
$conn->close();

// Return the results as JSON
echo json_encode($rows);