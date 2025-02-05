<?php
// ------------------------------
// Optional: Clear OPcache if enabled
// ------------------------------
if (function_exists('opcache_reset')) {
    opcache_reset();
}

// ------------------------------
// Prevent Client-Side Caching
// ------------------------------
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// ------------------------------
// CORS & Preflight Handling
// ------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");
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
// Retrieve and Decode JSON Input
// ------------------------------
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(["success" => false, "error" => "Invalid or empty JSON received"]);
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
$ca_cert = __DIR__ . "/DigiCertGlobalRootCA.crt.pem";  // CA certificate file in the same folder

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

// Initialize a response array to capture module-specific responses.
$response = [];

// ==============================
// Module 1: Nuclear Attack Data (nuke_data)
// ==============================
if (isset($data['battles']) && is_array($data['battles'])) {
    // Prepare the INSERT statement for nuke_data.
    // (Assuming the nuke_data table has columns matching these keys.)
    $nukeSql = "INSERT INTO nuke_data (
        defending_nation_id, defending_nation_name, defending_nation_ruler, defending_nation_alliance, defending_nation_team,
        attacking_nation_id, attacking_nation_name, attacking_nation_ruler, attacking_nation_alliance, attacking_nation_team,
        `timestamp`, result
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $nukeStmt = mysqli_prepare($con, $nukeSql);
    if (!$nukeStmt) {
        $err = mysqli_error($con);
        error_log("nuke_data INSERT statement preparation error: " . $err);
        $response['nuke_data'] = ["success" => false, "error" => "nuke_data INSERT statement preparation error: " . $err];
    } else {
        mysqli_autocommit($con, false);
        $allNukeSuccess = true;
        $nukeErrors = [];

        // Loop through each battle record.
        foreach ($data['battles'] as $index => $battle) {
            mysqli_stmt_bind_param(
                $nukeStmt,
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
            if (!mysqli_stmt_execute($nukeStmt)) {
                $allNukeSuccess = false;
                $nukeErrors[] = "Error on battle index $index: " . mysqli_stmt_error($nukeStmt);
                error_log("Error on battle index $index: " . mysqli_stmt_error($nukeStmt));
            }
        }
        if ($allNukeSuccess) {
            mysqli_commit($con);
            $response['nuke_data'] = ["success" => true];
        } else {
            mysqli_rollback($con);
            $response['nuke_data'] = ["success" => false, "error" => implode("; ", $nukeErrors)];
        }
        mysqli_stmt_close($nukeStmt);
    }
}

// ==============================
// Module 2: War Damage Data (war_results)
// ==============================
if (isset($data['wars']) && is_array($data['wars'])) {
    // Prepare statements for UPSERT logic.
    // First, a SELECT statement to check for an existing war record by war_id.
    $selectSql = "SELECT war_id FROM war_results WHERE war_id = ? LIMIT 1";
    $selectStmt = mysqli_prepare($con, $selectSql);
    if (!$selectStmt) {
        $err = mysqli_error($con);
        error_log("war_results SELECT statement preparation error: " . $err);
        $response['war_results'] = ["success" => false, "error" => "SELECT statement preparation error: " . $err];
    } else {
        // Prepare the INSERT statement.
        $insertSql = "INSERT INTO war_results (
            war_id, war_status, war_reason, war_declaration_date, war_end_date, total_attacks, xp_option,
            attacker_nation_name, attacker_ruler_name, attacker_alliance, attacker_soldiers_lost, attacker_tanks_lost, 
            attacker_cruise_missiles_lost, attacker_aircraft_lost, attacker_navy_lost, attacker_infrastructure_lost, 
            attacker_technology_lost, attacker_land_lost, attacker_strength_lost,
            defender_nation_name, defender_ruler_name, defender_alliance, defender_soldiers_lost, defender_tanks_lost, 
            defender_cruise_missiles_lost, defender_aircraft_lost, defender_navy_lost, defender_infrastructure_lost, 
            defender_technology_lost, defender_land_lost, defender_strength_lost
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insertStmt = mysqli_prepare($con, $insertSql);
        if (!$insertStmt) {
            $err = mysqli_error($con);
            error_log("war_results INSERT statement preparation error: " . $err);
            $response['war_results'] = ["success" => false, "error" => "INSERT statement preparation error: " . $err];
        } else {
            // Prepare the UPDATE statement.
            $updateSql = "UPDATE war_results SET
                war_status = ?,
                war_reason = ?,
                war_declaration_date = ?,
                war_end_date = ?,
                total_attacks = ?,
                xp_option = ?,
                attacker_nation_name = ?,
                attacker_ruler_name = ?,
                attacker_alliance = ?,
                attacker_soldiers_lost = ?,
                attacker_tanks_lost = ?,
                attacker_cruise_missiles_lost = ?,
                attacker_aircraft_lost = ?,
                attacker_navy_lost = ?,
                attacker_infrastructure_lost = ?,
                attacker_technology_lost = ?,
                attacker_land_lost = ?,
                attacker_strength_lost = ?,
                defender_nation_name = ?,
                defender_ruler_name = ?,
                defender_alliance = ?,
                defender_soldiers_lost = ?,
                defender_tanks_lost = ?,
                defender_cruise_missiles_lost = ?,
                defender_aircraft_lost = ?,
                defender_navy_lost = ?,
                defender_infrastructure_lost = ?,
                defender_technology_lost = ?,
                defender_land_lost = ?,
                defender_strength_lost = ?
                WHERE war_id = ?";
            $updateStmt = mysqli_prepare($con, $updateSql);
            if (!$updateStmt) {
                $err = mysqli_error($con);
                error_log("war_results UPDATE statement preparation error: " . $err);
                $response['war_results'] = ["success" => false, "error" => "UPDATE statement preparation error: " . $err];
            } else {
                mysqli_autocommit($con, false);
                $allWarSuccess = true;
                $warErrors = [];
                foreach ($data['wars'] as $index => $war) {
                    // Check for an existing record by war_id.
                    mysqli_stmt_bind_param($selectStmt, "i", $war['war_id']);
                    mysqli_stmt_execute($selectStmt);
                    mysqli_stmt_store_result($selectStmt);
                    $exists = mysqli_stmt_num_rows($selectStmt) > 0;
                    mysqli_stmt_free_result($selectStmt);

                    if ($exists) {
                        // Update existing record.
                        mysqli_stmt_bind_param(
                            $updateStmt,
                            "ssssisssiiiiiiidddiisssiiiiiiidi",
                            $war['war_status'],
                            $war['war_reason'],
                            $war['war_declaration_date'],
                            $war['war_end_date'],
                            $war['total_attacks'],
                            $war['xp_option'],
                            $war['attacker_nation_name'],
                            $war['attacker_ruler_name'],
                            $war['attacker_alliance'],
                            $war['attacker_soldiers_lost'],
                            $war['attacker_tanks_lost'],
                            $war['attacker_cruise_missiles_lost'],
                            $war['attacker_aircraft_lost'],
                            $war['attacker_navy_lost'],
                            $war['attacker_infrastructure_lost'],
                            $war['attacker_technology_lost'],
                            $war['attacker_land_lost'],
                            $war['attacker_strength_lost'],
                            $war['defender_nation_name'],
                            $war['defender_ruler_name'],
                            $war['defender_alliance'],
                            $war['defender_soldiers_lost'],
                            $war['defender_tanks_lost'],
                            $war['defender_cruise_missiles_lost'],
                            $war['defender_aircraft_lost'],
                            $war['defender_navy_lost'],
                            $war['defender_infrastructure_lost'],
                            $war['defender_technology_lost'],
                            $war['defender_land_lost'],
                            $war['defender_strength_lost'],
                            $war['war_id']
                        );
                        if (!mysqli_stmt_execute($updateStmt)) {
                            $allWarSuccess = false;
                            $warErrors[] = "Error updating war index $index: " . mysqli_stmt_error($updateStmt);
                            error_log("Error updating war index $index: " . mysqli_stmt_error($updateStmt));
                        }
                    } else {
                        // Insert new record.
                        mysqli_stmt_bind_param(
                            $insertStmt,
                            "issssisssiiiiiiidddiisssiiiiiiid",
                            $war['war_id'],
                            $war['war_status'],
                            $war['war_reason'],
                            $war['war_declaration_date'],
                            $war['war_end_date'],
                            $war['total_attacks'],
                            $war['xp_option'],
                            $war['attacker_nation_name'],
                            $war['attacker_ruler_name'],
                            $war['attacker_alliance'],
                            $war['attacker_soldiers_lost'],
                            $war['attacker_tanks_lost'],
                            $war['attacker_cruise_missiles_lost'],
                            $war['attacker_aircraft_lost'],
                            $war['attacker_navy_lost'],
                            $war['attacker_infrastructure_lost'],
                            $war['attacker_technology_lost'],
                            $war['attacker_land_lost'],
                            $war['attacker_strength_lost'],
                            $war['defender_nation_name'],
                            $war['defender_ruler_name'],
                            $war['defender_alliance'],
                            $war['defender_soldiers_lost'],
                            $war['defender_tanks_lost'],
                            $war['defender_cruise_missiles_lost'],
                            $war['defender_aircraft_lost'],
                            $war['defender_navy_lost'],
                            $war['defender_infrastructure_lost'],
                            $war['defender_technology_lost'],
                            $war['defender_land_lost'],
                            $war['defender_strength_lost']
                        );
                        if (!mysqli_stmt_execute($insertStmt)) {
                            $allWarSuccess = false;
                            $warErrors[] = "Error inserting war index $index: " . mysqli_stmt_error($insertStmt);
                            error_log("Error inserting war index $index: " . mysqli_stmt_error($insertStmt));
                        }
                    }
                } // end foreach wars

                if ($allWarSuccess) {
                    mysqli_commit($con);
                    $response['war_results'] = ["success" => true];
                } else {
                    mysqli_rollback($con);
                    $response['war_results'] = ["success" => false, "error" => implode("; ", $warErrors)];
                }
                mysqli_stmt_close($updateStmt);
            } // end updateStmt prepared
            mysqli_stmt_close($insertStmt);
        } // end selectStmt prepared
    }
}

// ------------------------------
// Close the SELECT Statement and Connection
// ------------------------------
mysqli_stmt_close($selectStmt);
mysqli_close($con);

// Return a JSON response with results from both modules.
echo json_encode(["success" => true, "modules" => $response, "public_ip" => $publicIp]);
?>
