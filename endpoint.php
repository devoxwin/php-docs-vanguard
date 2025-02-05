<?php
// ------------------------------
// Optional: Clear OPcache (if enabled)
// ------------------------------
if (function_exists('opcache_reset')) {
    opcache_reset();
}

// ------------------------------
// Prevent Client-side Caching
// ------------------------------
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// ------------------------------
// CORS & Preflight Handling
// ------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");

// Exit if preflight request.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ------------------------------
// Set Content-Type to JSON
// ------------------------------
header("Content-Type: application/json");

// ------------------------------
// Error Reporting & Logging
// ------------------------------
ini_set('display_errors', 0);
error_reporting(E_ALL);

// ------------------------------
// Retrieve and Validate JSON Input
// ------------------------------
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['battles']) || !is_array($data['battles'])) {
    echo json_encode(["success" => false, "error" => "No battles data received"]);
    exit;
}

// ------------------------------
// Database Connection Details
// ------------------------------
$host     = 'cn-valyria-prod.mysql.database.azure.com';    // Provided host name
$dbname   = 'cybernations_db';     // Your database name
$username = 'base_admin';          // Provided username
$password = 'sTP5rE[>cw6q&Nv4';     // Provided password
$port     = 3306;                 // Port number (usually 3306)

// ------------------------------
// SSL Certificate Settings
// ------------------------------
$ca_cert = __DIR__ . "/DigiCertGlobalRootCA.crt.pem";  // CA certificate file in same folder

if (!file_exists($ca_cert)) {
    error_log("CA certificate file not found at: " . $ca_cert);
    echo json_encode(["success" => false, "error" => "CA certificate file not found at: " . $ca_cert]);
    exit;
}
if (!is_readable($ca_cert)) {
    error_log("CA certificate file is not readable at: " . $ca_cert);
    echo json_encode(["success" => false, "error" => "CA certificate file is not readable at: " . $ca_cert]);
    exit;
}
$certContent = file_get_contents($ca_cert);
if ($certContent === false || strpos($certContent, "-----BEGIN CERTIFICATE-----") === false) {
    error_log("The file at " . $ca_cert . " does not contain a valid certificate.");
    echo json_encode(["success" => false, "error" => "The file at " . $ca_cert . " does not contain a valid certificate."]);
    exit;
}
error_log("CA certificate file validated successfully at: " . $ca_cert);

// ------------------------------
// Retrieve and Log the Server's Public IP Address
// ------------------------------
$publicIp = @file_get_contents('https://api.ipify.org');
if ($publicIp === false) {
    error_log("Unable to determine public IP address.");
    $publicIp = "unknown";
} else {
    error_log("Server Public IP: " . $publicIp);
}

// ------------------------------
// Initialize MySQLi and Retry Connection Loop
// ------------------------------
$con = mysqli_init();
if (!$con) {
    error_log("mysqli_init() failed");
    echo json_encode(["success" => false, "error" => "mysqli_init() failed"]);
    exit;
}
mysqli_ssl_set($con, NULL, NULL, $ca_cert, NULL, NULL);

$maxAttempts = 5;
$attempt = 0;
$connected = false;
while ($attempt < $maxAttempts && !$connected) {
    $attempt++;
    error_log("Attempt $attempt to connect to database...");
    $connected = mysqli_real_connect($con, $host, $username, $password, $dbname, $port, NULL, MYSQLI_CLIENT_SSL);
    if (!$connected) {
        error_log("Attempt $attempt failed: " . mysqli_connect_error());
        if ($attempt < $maxAttempts) {
            sleep(3);
        }
    }
}
if (!$connected) {
    $err = mysqli_connect_error();
    error_log("Database connection error after $maxAttempts attempts: " . $err);
    echo json_encode(["success" => false, "error" => "Database connection error: " . $err, "public_ip" => $publicIp]);
    exit;
}
mysqli_set_charset($con, "utf8mb4");

// ------------------------------
// Group Battles by Unique Signature
// ------------------------------
$grouped = []; // associative array: key => ['battle' => battleObject, 'count' => number]
foreach ($data['battles'] as $battle) {
    // Create a unique key based on all relevant fields.
    // Order: defending: id, name, ruler, alliance, team; attacking: id, name, ruler, alliance, team; timestamp; result.
    $key = $battle['defending_nation']['id'] . '|' .
           $battle['defending_nation']['name'] . '|' .
           $battle['defending_nation']['ruler'] . '|' .
           $battle['defending_nation']['alliance'] . '|' .
           $battle['defending_nation']['team'] . '|' .
           $battle['attacking_nation']['id'] . '|' .
           $battle['attacking_nation']['name'] . '|' .
           $battle['attacking_nation']['ruler'] . '|' .
           $battle['attacking_nation']['alliance'] . '|' .
           $battle['attacking_nation']['team'] . '|' .
           $battle['timestamp'] . '|' .
           $battle['result'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = ['battle' => $battle, 'count' => 0];
    }
    $grouped[$key]['count']++;
}
error_log("Grouped battles count: " . count($grouped));

// ------------------------------
// Prepare the SELECT Statement to Check for Existing Records
// ------------------------------
$selectSql = "SELECT 1 FROM nuke_data WHERE 
    defending_nation_id = ? AND 
    defending_nation_name = ? AND 
    defending_nation_ruler = ? AND 
    defending_nation_alliance = ? AND 
    defending_nation_team = ? AND 
    attacking_nation_id = ? AND 
    attacking_nation_name = ? AND 
    attacking_nation_ruler = ? AND 
    attacking_nation_alliance = ? AND 
    attacking_nation_team = ? AND 
    `timestamp` = ? AND 
    result = ? LIMIT 1";
$selectStmt = mysqli_prepare($con, $selectSql);
if (!$selectStmt) {
    $err = mysqli_error($con);
    error_log("SELECT statement preparation error: " . $err);
    echo json_encode(["success" => false, "error" => "SELECT statement preparation error: " . $err]);
    exit;
}

// ------------------------------
// Prepare the INSERT Statement
// ------------------------------
$insertSql = "INSERT INTO nuke_data (
    defending_nation_id, defending_nation_name, defending_nation_ruler, defending_nation_alliance, defending_nation_team,
    attacking_nation_id, attacking_nation_name, attacking_nation_ruler, attacking_nation_alliance, attacking_nation_team,
    `timestamp`, result
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$insertStmt = mysqli_prepare($con, $insertSql);
if (!$insertStmt) {
    $err = mysqli_error($con);
    error_log("INSERT statement preparation error: " . $err);
    echo json_encode(["success" => false, "error" => "INSERT statement preparation error: " . $err]);
    exit;
}

// ------------------------------
// Begin Transaction for Atomic Inserts
// ------------------------------
mysqli_autocommit($con, false);
$allSuccess = true;
$errors = [];

// Loop over each unique battle group.
foreach ($grouped as $key => $group) {
    $battle = $group['battle'];
    $countToInsert = $group['count'];

    // Bind parameters to the SELECT statement to check if the record exists.
    mysqli_stmt_bind_param(
        $selectStmt,
        "ssssssssssss",
        $battle['defending_nation']['id'],
        $battle['defending_nation']['name'],
        $battle['defending_nation']['ruler'],
        $battle['defending_nation']['alliance'],
        $battle['defending_nation']['team'],
        $battle['attacking_nation']['id'],
        $battle['attacking_nation']['name'],
        $battle['attacking_nation']['ruler'],
        $battle['attacking_nation']['alliance'],
        $battle['attacking_nation']['team'],
        $battle['timestamp'],
        $battle['result']
    );
    mysqli_stmt_execute($selectStmt);
    mysqli_stmt_store_result($selectStmt);
    $exists = mysqli_stmt_num_rows($selectStmt) > 0;
    mysqli_stmt_free_result($selectStmt);

    // If a record already exists, skip insertion.
    if ($exists) {
        error_log("Record already exists for key: " . $key . ". Skipping insertion.");
        continue;
    }

    // Otherwise, insert the record as many times as it appears in this scrape.
    for ($i = 0; $i < $countToInsert; $i++) {
        mysqli_stmt_bind_param(
            $insertStmt,
            "ssssssssssss",
            $battle['defending_nation']['id'],
            $battle['defending_nation']['name'],
            $battle['defending_nation']['ruler'],
            $battle['defending_nation']['alliance'],
            $battle['defending_nation']['team'],
            $battle['attacking_nation']['id'],
            $battle['attacking_nation']['name'],
            $battle['attacking_nation']['ruler'],
            $battle['attacking_nation']['alliance'],
            $battle['attacking_nation']['team'],
            $battle['timestamp'],
            $battle['result']
        );
        if (!mysqli_stmt_execute($insertStmt)) {
            $allSuccess = false;
            $errors[] = "Error inserting battle with key $key: " . mysqli_stmt_error($insertStmt);
            error_log("Error inserting battle with key $key: " . mysqli_stmt_error($insertStmt));
        }
    }
}

// ------------------------------
// Commit or Roll Back the Transaction
// ------------------------------
if ($allSuccess) {
    mysqli_commit($con);
    echo json_encode(["success" => true, "public_ip" => $publicIp]);
} else {
    mysqli_rollback($con);
    echo json_encode(["success" => false, "error" => implode("; ", $errors), "public_ip" => $publicIp]);
}

// ------------------------------
// Clean Up: Close the Statements and Connection
// ------------------------------
mysqli_stmt_close($selectStmt);
mysqli_stmt_close($insertStmt);
mysqli_close($con);
?>
