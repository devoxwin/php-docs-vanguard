<?php
ob_start();
// ------------------------------
// CORS and Headers
// ------------------------------
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}
header("Content-Type: application/json");

// ------------------------------
// Error Reporting
// ------------------------------
ini_set('display_errors', 0);
error_reporting(E_ALL);

// ------------------------------
// Retrieve JSON Input
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
$ca_cert = __DIR__ . "/DigiCertGlobalRootCA.crt.pem";
if (!file_exists($ca_cert) || !is_readable($ca_cert)) {
    error_log("CA certificate file not found or not readable at: " . $ca_cert);
    echo json_encode(["success" => false, "error" => "CA certificate file not found or not readable at: " . $ca_cert]);
    exit;
}
$certContent = file_get_contents($ca_cert);
if ($certContent === false || strpos($certContent, "-----BEGIN CERTIFICATE-----") === false) {
    error_log("The file at " . $ca_cert . " does not contain a valid certificate.");
    echo json_encode(["success" => false, "error" => "Invalid CA certificate at: " . $ca_cert]);
    exit;
}
error_log("CA certificate validated successfully at: " . $ca_cert);

// ------------------------------
// Retrieve Server Public IP (for logging/debugging)
// ------------------------------
$publicIp = @file_get_contents('https://api.ipify.org');
if ($publicIp === false) { $publicIp = "unknown"; }
error_log("Server Public IP: " . $publicIp);

// ------------------------------
// Initialize MySQLi Connection with SSL (with retry loop)
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
        if ($attempt < $maxAttempts) sleep(3);
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
// Module 1: Nuclear Attack Data (nuke_data)
// Duplicate Prevention Logic:
// - Group battles by a unique key built from all fields.
// - For each unique group, check if a record already exists.
// - If it exists (from previous scrapes), skip insertion.
// - If not, insert as many rows as the count in the current scrape.
// ------------------------------
if (isset($data['battles']) && is_array($data['battles'])) {
    $grouped = [];
    foreach ($data['battles'] as $battle) {
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

    // Prepare SELECT statement to check for duplicates.
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
        error_log("nuke_data SELECT preparation error: " . $err);
        $nukeResponse = ["success" => false, "error" => "nuke_data SELECT preparation error: " . $err];
    } else {
        // Prepare INSERT statement.
        $insertSql = "INSERT INTO nuke_data (
            defending_nation_id, defending_nation_name, defending_nation_ruler, defending_nation_alliance, defending_nation_team,
            attacking_nation_id, attacking_nation_name, attacking_nation_ruler, attacking_nation_alliance, attacking_nation_team,
            `timestamp`, result
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insertStmt = mysqli_prepare($con, $insertSql);
        if (!$insertStmt) {
            $err = mysqli_error($con);
            error_log("nuke_data INSERT preparation error: " . $err);
            $nukeResponse = ["success" => false, "error" => "nuke_data INSERT preparation error: " . $err];
        } else {
            mysqli_autocommit($con, false);
            $allNukeSuccess = true;
            $nukeErrors = [];
            foreach ($grouped as $key => $group) {
                $battle = $group['battle'];
                $countToInsert = $group['count'];

                mysqli_stmt_bind_param(
                    $selectStmt, "ssssssssssss",
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

                if ($exists) {
                    error_log("Duplicate nuke data exists for key: " . $key . ". Skipping insertion.");
                    continue;
                }

                for ($i = 0; $i < $countToInsert; $i++) {
                    mysqli_stmt_bind_param(
                        $insertStmt, "ssssssssssss",
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
                        $allNukeSuccess = false;
                        $nukeErrors[] = "Error inserting nuke data for key $key: " . mysqli_stmt_error($insertStmt);
                        error_log("Error inserting nuke data for key $key: " . mysqli_stmt_error($insertStmt));
                    }
                }
            }
            if ($allNukeSuccess) {
                mysqli_commit($con);
                $nukeResponse = ["success" => true];
            } else {
                mysqli_rollback($con);
                $nukeResponse = ["success" => false, "error" => implode("; ", $nukeErrors)];
            }
            mysqli_stmt_close($selectStmt);
            mysqli_stmt_close($insertStmt);
        }
    }
} else {
    $nukeResponse = ["success" => true, "message" => "No nuke data provided"];
}

// ------------------------------
// Module 2: War Damage Data (war_results Upsert)
// ------------------------------
if (isset($data['wars']) && is_array($data['wars'])) {
    // Prepare SELECT to check if a war record exists.
    $selectWarSql = "SELECT war_id FROM war_results WHERE war_id = ? LIMIT 1";
    $selectWarStmt = mysqli_prepare($con, $selectWarSql);
    if (!$selectWarStmt) {
        $err = mysqli_error($con);
        error_log("war_results SELECT preparation error: " . $err);
        $warResponse = ["success" => false, "error" => "war_results SELECT preparation error: " . $err];
    } else {
        // Prepare INSERT for new war records.
        $insertWarSql = "INSERT INTO war_results (
            war_id, war_status, war_reason, war_declaration_date, war_end_date, total_attacks, xp_option,
            attacker_nation_name, attacker_ruler_name, attacker_alliance, attacker_soldiers_lost, attacker_tanks_lost, 
            attacker_cruise_missiles_lost, attacker_aircraft_lost, attacker_navy_lost, attacker_infrastructure_lost, 
            attacker_technology_lost, attacker_land_lost, attacker_strength_lost,
            defender_nation_name, defender_ruler_name, defender_alliance, defender_soldiers_lost, defender_tanks_lost, 
            defender_cruise_missiles_lost, defender_aircraft_lost, defender_navy_lost, defender_infrastructure_lost, 
            defender_technology_lost, defender_land_lost, defender_strength_lost
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insertWarStmt = mysqli_prepare($con, $insertWarSql);
        if (!$insertWarStmt) {
            $err = mysqli_error($con);
            error_log("war_results INSERT preparation error: " . $err);
            $warResponse = ["success" => false, "error" => "war_results INSERT preparation error: " . $err];
        } else {
            // Prepare UPDATE for existing war records.
            $updateWarSql = "UPDATE war_results SET
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
            $updateWarStmt = mysqli_prepare($con, $updateWarSql);
            if (!$updateWarStmt) {
                $err = mysqli_error($con);
                error_log("war_results UPDATE preparation error: " . $err);
                $warResponse = ["success" => false, "error" => "war_results UPDATE preparation error: " . $err];
            } else {
                mysqli_autocommit($con, false);
                $allWarSuccess = true;
                $warErrors = [];
                foreach ($data['wars'] as $index => $war) {
                    // Check for existence.
                    mysqli_stmt_bind_param($selectWarStmt, "i", $war['war_id']);
                    mysqli_stmt_execute($selectWarStmt);
                    mysqli_stmt_store_result($selectWarStmt);
                    $exists = mysqli_stmt_num_rows($selectWarStmt) > 0;
                    mysqli_stmt_free_result($selectWarStmt);

                    if ($exists) {
                        // Update existing record.
                        mysqli_stmt_bind_param(
                            $updateWarStmt,
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
                        if (!mysqli_stmt_execute($updateWarStmt)) {
                            $allWarSuccess = false;
                            $warErrors[] = "Error updating war index $index: " . mysqli_stmt_error($updateWarStmt);
                            error_log("Error updating war index $index: " . mysqli_stmt_error($updateWarStmt));
                        }
                    } else {
                        // Insert new record.
                        mysqli_stmt_bind_param(
                            $insertWarStmt,
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
                        if (!mysqli_stmt_execute($insertWarStmt)) {
                            $allWarSuccess = false;
                            $warErrors[] = "Error inserting war index $index: " . mysqli_stmt_error($insertWarStmt);
                            error_log("Error inserting war index $index: " . mysqli_stmt_error($insertWarStmt));
                        }
                    }
                } // end foreach wars
                if ($allWarSuccess) {
                    mysqli_commit($con);
                    $warResponse = ["success" => true];
                } else {
                    mysqli_rollback($con);
                    $warResponse = ["success" => false, "error" => implode("; ", $warErrors)];
                }
                mysqli_stmt_close($updateWarStmt);
            }
            mysqli_stmt_close($insertWarStmt);
        }
        mysqli_stmt_close($selectWarStmt);
    }
} else {
    $warResponse = ["success" => true, "message" => "No war data provided"];
}

// ------------------------------
// Close the database connection.
mysqli_close($con);
ob_end_flush();
// Return a JSON response with both modules' responses.
echo json_encode([
    "success" => true,
    "modules" => [
        "nuke_data" => $nukeResponse,
        "war_results" => $warResponse
    ],
    "public_ip" => $publicIp
]);
?>
