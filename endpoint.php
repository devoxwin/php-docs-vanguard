<?php
// ------------------------------
// Optional: Clear OPcache
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

// If this is a preflight OPTIONS request, exit immediately.
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
// CA Certificate Validation
// ------------------------------
$ca_cert = __DIR__ . "/DigiCertGlobalRootCA.crt.pem"; // Updated file path

if (!file_exists($ca_cert)) {
    error_log("CA certificate file not found at: " . $ca_cert);
    echo json_encode([
        "success" => false,
        "error"   => "CA certificate file not found at: " . $ca_cert
    ]);
    exit;
}

if (!is_readable($ca_cert)) {
    error_log("CA certificate file is not readable at: " . $ca_cert);
    echo json_encode([
        "success" => false,
        "error"   => "CA certificate file is not readable at: " . $ca_cert
    ]);
    exit;
}

$certContent = file_get_contents($ca_cert);
if ($certContent === false) {
    error_log("Unable to read CA certificate file at: " . $ca_cert);
    echo json_encode([
        "success" => false,
        "error"   => "Unable to read CA certificate file at: " . $ca_cert
    ]);
    exit;
}

if (strpos($certContent, "-----BEGIN CERTIFICATE-----") === false) {
    error_log("The file at " . $ca_cert . " does not contain a valid certificate.");
    echo json_encode([
        "success" => false,
        "error"   => "The file at " . $ca_cert . " does not contain a valid certificate."
    ]);
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
// (Optional) Debug: Resolve the Hostname
// ------------------------------
$resolved_ip = gethostbyname($host);
error_log("Resolved IP for host '$host': $resolved_ip");

// ------------------------------
// Initialize and Establish the MySQLi Connection with SSL and Retry Logic
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
            sleep(3); // Wait 3 seconds before next attempt
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
// Prepare the INSERT Statement
// ------------------------------
$sql = "INSERT INTO nuke_data (
    defending_nation_id, defending_nation_name, defending_nation_ruler, defending_nation_alliance, defending_nation_team,
    attacking_nation_id, attacking_nation_name, attacking_nation_ruler, attacking_nation_alliance, attacking_nation_team,
    `timestamp`, result
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$stmt = mysqli_prepare($con, $sql);
if (!$stmt) {
    $err = mysqli_error($con);
    error_log("Statement preparation error: " . $err);
    echo json_encode(["success" => false, "error" => "Statement preparation error: " . $err]);
    exit;
}

// ------------------------------
// Begin Transaction for Atomic Inserts
// ------------------------------
mysqli_autocommit($con, false);
$allSuccess = true;
$errors = [];

// ------------------------------
// Loop Through the Battles and Insert Data
// ------------------------------
foreach ($data['battles'] as $index => $battle) {
    $def = $battle['defending_nation'];
    $att = $battle['attacking_nation'];
    $timestamp = $battle['timestamp'];
    $result = $battle['result'];

    mysqli_stmt_bind_param(
        $stmt,
        "ssssssssssss",
        $def['id'],
        $def['name'],
        $def['ruler'],
        $def['alliance'],
        $def['team'],
        $att['id'],
        $att['name'],
        $att['ruler'],
        $att['alliance'],
        $att['team'],
        $timestamp,
        $result
    );

    if (!mysqli_stmt_execute($stmt)) {
        $allSuccess = false;
        $errors[] = "Error on battle index $index: " . mysqli_stmt_error($stmt);
        error_log("Error on battle index $index: " . mysqli_stmt_error($stmt));
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
// Clean Up: Close the Statement and Connection
// ------------------------------
mysqli_stmt_close($stmt);
mysqli_close($con);
?>
