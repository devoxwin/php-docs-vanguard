<?php
// Start output buffering so headers can be set.
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
// Error Reporting (turn off display in production)
// ------------------------------
ini_set('display_errors', 0);
error_reporting(E_ALL);

// ------------------------------
// Setup Debug Logging to a Text File
// ------------------------------
$debugFile = __DIR__ . "/debug_log.txt";
function log_debug($message) {
    global $debugFile;
    $formatted = "[" . date("Y-m-d H:i:s") . "] " . $message . "\n";
    file_put_contents($debugFile, $formatted, FILE_APPEND);
}
log_debug("Starting endpoint.php script.");

// ------------------------------
// Retrieve JSON Input
// ------------------------------
$input = file_get_contents('php://input');
log_debug("Raw input received: " . substr($input, 0, 200) . "...");
$data = json_decode($input, true);
if (!$data) {
    log_debug("JSON decoding failed.");
    echo json_encode([
        "success" => false,
        "error" => "Invalid or empty JSON received"
    ]);
    exit;
}
log_debug("JSON decoded successfully.");

// ------------------------------
// Database Connection Details
// ------------------------------
$host     = "cn-valyria-prod.mysql.database.azure.com";
$dbname   = 'cybernations_db';
$username = "base_admin";
$password = 'sTP5rE[>cw6q&Nv4';
$port     = 3306;
log_debug("Database connection details set.");

// ------------------------------
// SSL Certificate Settings
// ------------------------------
$ca_cert = __DIR__ . "/DigiCertGlobalRootCA.crt.pem";
if (!file_exists($ca_cert) || !is_readable($ca_cert)) {
    log_debug("CA certificate file not found or not readable at: " . $ca_cert);
    echo json_encode([
        "success" => false,
        "error" => "CA certificate file not found or not readable at: " . $ca_cert
    ]);
    exit;
}
$certContent = file_get_contents($ca_cert);
if ($certContent === false || strpos($certContent, "-----BEGIN CERTIFICATE-----") === false) {
    log_debug("Invalid CA certificate at: " . $ca_cert);
    echo json_encode([
        "success" => false,
        "error" => "Invalid CA certificate at: " . $ca_cert
    ]);
    exit;
}
log_debug("CA certificate validated successfully.");

// ------------------------------
// Retrieve Server Public IP (for debugging)
// ------------------------------
$publicIp = @file_get_contents('https://api.ipify.org');
if ($publicIp === false) { 
    $publicIp = "unknown"; 
}
log_debug("Server Public IP: " . $publicIp);

// ------------------------------
// Initialize MySQLi Connection with SSL (with retry loop)
// ------------------------------
$con = mysqli_init();
if (!$con) {
    log_debug("mysqli_init() failed.");
    echo json_encode([
        "success" => false,
        "error" => "mysqli_init() failed"
    ]);
    exit;
}
mysqli_ssl_set($con, NULL, NULL, $ca_cert, NULL, NULL);
$maxAttempts = 5;
$attempt = 0;
$connected = false;
while ($attempt < $maxAttempts && !$connected) {
    $attempt++;
    log_debug("Attempt $attempt to connect to database...");
    $connected = mysqli_real_connect($con, $host, $username, $password, $dbname, $port, NULL, MYSQLI_CLIENT_SSL);
    if (!$connected) {
        log_debug("Attempt $attempt failed: " . mysqli_connect_error());
        if ($attempt < $maxAttempts) sleep(3);
    }
}
if (!$connected) {
    $err = mysqli_connect_error();
    log_debug("Database connection error after $maxAttempts attempts: " . $err);
    echo json_encode([
        "success" => false,
        "error" => "Database connection error: " . $err,
        "public_ip" => $publicIp
    ]);
    exit;
}
mysqli_set_charset($con, "utf8mb4");
log_debug("Database connection established successfully.");

// ------------------------------
// Module 1: Nuclear Attack Data (nuke_data)
// ------------------------------
if (isset($data['battles']) && is_array($data['battles'])) {
    log_debug("Processing nuclear attack data.");
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
    log_debug("Grouped battles count: " . count($grouped));

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
        log_debug("nuke_data SELECT preparation error: " . $err);
        $nukeResponse = ["success" => false, "error" => "nuke_data SELECT preparation error: " . $err];
    } else {
        $insertSql = "INSERT INTO nuke_data (
            defending_nation_id, defending_nation_name, defending_nation_ruler, defending_nation_alliance, defending_nation_team,
            attacking_nation_id, attacking_nation_name, attacking_nation_ruler, attacking_nation_alliance, attacking_nation_team,
            `timestamp`, result
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insertStmt = mysqli_prepare($con, $insertSql);
        if (!$insertStmt) {
            $err = mysqli_error($con);
            log_debug("nuke_data INSERT preparation error: " . $err);
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
                    log_debug("Duplicate nuke data exists for key: " . $key . ". Skipping insertion.");
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
                        log_debug("Error inserting nuke data for key $key: " . mysqli_stmt_error($insertStmt));
                    }
                }
            }
            if ($allNukeSuccess) {
                mysqli_commit($con);
                $nukeResponse = ["success" => true];
                log_debug("Nuke data inserted successfully.");
            } else {
                mysqli_rollback($con);
                $nukeResponse = ["success" => false, "error" => implode("; ", $nukeErrors)];
                log_debug("Nuke data insertion errors: " . implode("; ", $nukeErrors));
            }
            mysqli_stmt_close($selectStmt);
            mysqli_stmt_close($insertStmt);
        }
    }
} else {
    $nukeResponse = ["success" => true, "message" => "No nuke data provided"];
    log_debug("No nuke data provided.");
}

// ------------------------------
// Module 2: War Damage Data (Upsert)
// Expect war data to be sent under the key "wardata".
if (isset($data['wardata']) && is_array($data['wardata'])) {
    log_debug("Processing war data.");
    $selectWarSql = "SELECT war_id FROM war_results WHERE war_id = ? LIMIT 1";
    $selectWarStmt = mysqli_prepare($con, $selectWarSql);
    if (!$selectWarStmt) {
        $err = mysqli_error($con);
        log_debug("war_results SELECT preparation error: " . $err);
        $warResponse = ["success" => false, "error" => "war_results SELECT preparation error: " . $err];
    } else {
        $insertWarSql = "INSERT INTO war_results (
            war_id, war_status, war_reason, war_declaration_date, war_end_date, total_attacks, xp_option,
            attacker_nation_name, attacker_ruler_name, attacker_alliance, attacker_soldiers_lost, attacker_tanks_lost, 
            attacker_cruise_missiles_lost, attacker_aircraft_lost, attacker_navy_lost, attacker_infrastructure_lost, 
            attacker_technology_lost, attacker_land_lost, attacker_strength_lost,
            defender_nation_name, defender_ruler_name, defender_alliance, defender_soldiers_lost, defender_tanks_lost, 
            defender_cruise_missiles_lost, defender_aircraft_lost, defender_navy_lost, defender_infrastructure_lost, 
            defender_technology_lost, defender_land_lost, defender_strength_lost
        ) VALUES (" . str_repeat("?,", 30) . "?)";  // 31 placeholders
        $insertWarStmt = mysqli_prepare($con, $insertWarSql);
        if (!$insertWarStmt) {
            $err = mysqli_error($con);
            log_debug("war_results INSERT preparation error: " . $err);
            $warResponse = ["success" => false, "error" => "war_results INSERT preparation error: " . $err];
        } else {
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
                log_debug("war_results UPDATE preparation error: " . $err);
                $warResponse = ["success" => false, "error" => "war_results UPDATE preparation error: " . $err];
            } else {
                mysqli_autocommit($con, false);
                $allWarSuccess = true;
                $warErrors = [];
                // The correct type string for 31 columns (see explanation above)
                $typeString = "issssissssiiiii" . "ssss" . "sss" . "iiiii" . "ssss"; 
                // Remove any spaces from $typeString if present:
                $typeString = str_replace(" ", "", $typeString);
                // Verify length is 31:
                if (strlen($typeString) !== 31) {
                    log_debug("War type string length is " . strlen($typeString) . ", expected 31.");
                }
                foreach ($data['wardata'] as $index => $war) {
                    mysqli_stmt_bind_param($selectWarStmt, "i", $war['war_id']);
                    mysqli_stmt_execute($selectWarStmt);
                    mysqli_stmt_store_result($selectWarStmt);
                    $exists = mysqli_stmt_num_rows($selectWarStmt) > 0;
                    mysqli_stmt_free_result($selectWarStmt);
                    if ($exists) {
                        mysqli_stmt_bind_param(
                            $updateWarStmt,
                            $typeString,
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
                            log_debug("Error updating war index $index: " . mysqli_stmt_error($updateWarStmt));
                        }
                    } else {
                        mysqli_stmt_bind_param(
                            $insertWarStmt,
                            $typeString,
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
                            log_debug("Error inserting war index $index: " . mysqli_stmt_error($insertWarStmt));
                        }
                    }
                }
                if ($allWarSuccess) {
                    mysqli_commit($con);
                    $warResponse = ["success" => true];
                    log_debug("War data processed successfully.");
                } else {
                    mysqli_rollback($con);
                    $warResponse = ["success" => false, "error" => implode("; ", $warErrors)];
                    log_debug("War data processing errors: " . implode("; ", $warErrors));
                }
                mysqli_stmt_close($updateWarStmt);
            }
            mysqli_stmt_close($insertWarStmt);
        }
        mysqli_stmt_close($selectWarStmt);
    }
} else {
    $warResponse = ["success" => true, "message" => "No war data provided"];
    log_debug("No war data provided.");
}

// ------------------------------
// Module 3: Nation Drill Display Data (Insert)
// Expect nation data to be sent under the key "nationData".
if (isset($data['nationData']) && is_array($data['nationData'])) {
    log_debug("Processing nation drill display data (Module 3).");
    $nationData = $data['nationData'];
    $insertNationSql = "INSERT INTO nation_data (
      nation_id,
      ruler,
      nation_name,
      last_donation,
      alliance_affiliation,
      alliance_role,
      alliance_seniority,
      government_type,
      government_decision,
      national_religion,
      religion_decision,
      nation_team,
      nation_created,
      technology,
      infrastructure,
      tax_rate,
      area_of_influence,
      purchased_land,
      land_modifiers,
      land_growth,
      war_peace_preference,
      resource1,
      resource2,
      resource3,
      resource4,
      resource5,
      resource6,
      resource7,
      resource8,
      resource9,
      resource10,
      resource11,
      resource12,
      native_resource_1,
      native_resource_2,
      trade_slots_filled,
      trade_slots_max,
      airports,
      banks,
      barracks,
      churches,
      clinics,
      drydocks,
      factories,
      foreign_ministries,
      forward_operating_bases,
      guerrilla_camps,
      harbors,
      hospitals,
      intelligence_agencies,
      labor_camps,
      missile_defenses,
      munitions_factories,
      naval_academies,
      naval_construction_yards,
      offices_of_propaganda,
      police_headquarters,
      prisons,
      rehabilitation_facilities,
      satellites,
      schools,
      shipyards,
      stadiums,
      universities,
      wonder_agriculture_dev,
      wonder_anti_air_defense,
      wonder_central_intel,
      wonder_disaster_relief,
      wonder_emp_weaponization,
      wonder_federal_aid,
      wonder_federal_reserve,
      wonder_foreign_air_base,
      wonder_foreign_army_base,
      wonder_foreign_naval_base,
      wonder_great_monument,
      wonder_great_temple,
      wonder_great_university,
      wonder_hidden_nuke_silo,
      wonder_interceptor_missile,
      wonder_internet,
      wonder_interstate_system,
      wonder_manhattan_project,
      wonder_mars_base,
      wonder_mars_colony,
      wonder_mars_mine,
      wonder_mining_consortium,
      wonder_movie_industry,
      wonder_national_cemetery,
      wonder_national_environment_office,
      wonder_national_research_lab,
      wonder_national_war_memorial,
      wonder_nuclear_power,
      wonder_pentagon,
      wonder_political_lobbyists,
      wonder_scientific_development,
      wonder_social_security,
      wonder_space_program,
      wonder_stock_market,
      wonder_strategic_defense,
      wonder_superior_logistical,
      wonder_universal_health,
      wonder_weapons_research,
      environment,
      nation_strength,
      defcon_level,
      threat_level,
      num_soldiers,
      effective_soldiers,
      defending_soldiers,
      deployed_soldiers,
      num_tanks,
      defending_tanks,
      deployed_tanks,
      aircraft,
      cruise_missiles,
      navy_vessels,
      nuclear_weapons,
      num_spies,
      soldiers_lost,
      attacking_casualties,
      defending_casualties,
      total_population,
      population_density,
      military_personnel,
      working_citizens,
      criminals,
      rehabbed_criminals,
      population_happiness,
      crime_index,
      crime_prevention_score,
      avg_gross_income,
      avg_income_taxes,
      avg_net_income,
      total_income_taxes_collected,
      total_expenses,
      bills_paid,
      purchases_over_time,
      current_dinars
    ) VALUES (" . str_repeat("?,", 137) . "?)";  // 138 placeholders
    $insertNationStmt = mysqli_prepare($con, $insertNationSql);
    if (!$insertNationStmt) {
        $err = mysqli_error($con);
        log_debug("nation_data INSERT preparation error: " . $err);
        $nationResponse = ["success" => false, "error" => "nation_data INSERT preparation error: " . $err];
    } else {
        // Build the array of values in the same order as the columns above.
        $values = [
          $nationData['nation_id'] ?? null,
          $nationData['ruler'] ?? null,
          $nationData['nation_name'] ?? null,
          $nationData['last_donation'] ?? null,
          $nationData['alliance_affiliation'] ?? null,
          $nationData['alliance_role'] ?? '',  // default empty string if not provided
          $nationData['alliance_seniority'] ?? null,
          $nationData['government_type'] ?? null,
          $nationData['government_decision'] ?? null,
          $nationData['national_religion'] ?? null,
          $nationData['religion_decision'] ?? null,
          $nationData['nation_team'] ?? null,
          $nationData['nation_created'] ?? null,
          $nationData['technology'] ?? null,
          $nationData['infrastructure'] ?? null,
          $nationData['tax_rate'] ?? null,
          $nationData['area_of_influence'] ?? null,
          $nationData['purchased_land'] ?? null,
          $nationData['land_modifiers'] ?? null,
          $nationData['land_growth'] ?? null,
          $nationData['war_peace_preference'] ?? null,
          $nationData['resource1'] ?? null,
          $nationData['resource2'] ?? null,
          $nationData['resource3'] ?? null,
          $nationData['resource4'] ?? null,
          $nationData['resource5'] ?? null,
          $nationData['resource6'] ?? null,
          $nationData['resource7'] ?? null,
          $nationData['resource8'] ?? null,
          $nationData['resource9'] ?? null,
          $nationData['resource10'] ?? null,
          $nationData['resource11'] ?? null,
          $nationData['resource12'] ?? null,
          $nationData['native_resource_1'] ?? null,
          $nationData['native_resource_2'] ?? null,
          $nationData['trade_slots_filled'] ?? null,
          $nationData['trade_slots_max'] ?? null,
          $nationData['airports'] ?? null,
          $nationData['banks'] ?? null,
          $nationData['barracks'] ?? null,
          $nationData['churches'] ?? null,
          $nationData['clinics'] ?? null,
          $nationData['drydocks'] ?? null,
          $nationData['factories'] ?? null,
          $nationData['foreign_ministries'] ?? null,
          $nationData['forward_operating_bases'] ?? null,
          $nationData['guerrilla_camps'] ?? null,
          $nationData['harbors'] ?? null,
          $nationData['hospitals'] ?? null,
          $nationData['intelligence_agencies'] ?? null,
          $nationData['labor_camps'] ?? null,
          $nationData['missile_defenses'] ?? null,
          $nationData['munitions_factories'] ?? null,
          $nationData['naval_academies'] ?? null,
          $nationData['naval_construction_yards'] ?? null,
          $nationData['offices_of_propaganda'] ?? null,
          $nationData['police_headquarters'] ?? null,
          $nationData['prisons'] ?? null,
          $nationData['rehabilitation_facilities'] ?? null,
          $nationData['satellites'] ?? null,
          $nationData['schools'] ?? null,
          $nationData['shipyards'] ?? null,
          $nationData['stadiums'] ?? null,
          $nationData['universities'] ?? null,
          $nationData['wonder_agriculture_dev'] ?? "no",
          $nationData['wonder_anti_air_defense'] ?? "no",
          $nationData['wonder_central_intel'] ?? "no",
          $nationData['wonder_disaster_relief'] ?? "no",
          $nationData['wonder_emp_weaponization'] ?? "no",
          $nationData['wonder_federal_aid'] ?? "no",
          $nationData['wonder_federal_reserve'] ?? "no",
          $nationData['wonder_foreign_air_base'] ?? "no",
          $nationData['wonder_foreign_army_base'] ?? "no",
          $nationData['wonder_foreign_naval_base'] ?? "no",
          $nationData['wonder_great_monument'] ?? "no",
          $nationData['wonder_great_temple'] ?? "no",
          $nationData['wonder_great_university'] ?? "no",
          $nationData['wonder_hidden_nuke_silo'] ?? "no",
          $nationData['wonder_interceptor_missile'] ?? "no",
          $nationData['wonder_internet'] ?? "no",
          $nationData['wonder_interstate_system'] ?? "no",
          $nationData['wonder_manhattan_project'] ?? "no",
          $nationData['wonder_mars_base'] ?? "no",
          $nationData['wonder_mars_colony'] ?? "no",
          $nationData['wonder_mars_mine'] ?? "no",
          $nationData['wonder_mining_consortium'] ?? "no",
          $nationData['wonder_movie_industry'] ?? "no",
          $nationData['wonder_national_cemetery'] ?? "no",
          $nationData['wonder_national_environment_office'] ?? "no",
          $nationData['wonder_national_research_lab'] ?? "no",
          $nationData['wonder_national_war_memorial'] ?? "no",
          $nationData['wonder_nuclear_power'] ?? "no",
          $nationData['wonder_pentagon'] ?? "no",
          $nationData['wonder_political_lobbyists'] ?? "no",
          $nationData['wonder_scientific_development'] ?? "no",
          $nationData['wonder_social_security'] ?? "no",
          $nationData['wonder_space_program'] ?? "no",
          $nationData['wonder_stock_market'] ?? "no",
          $nationData['wonder_strategic_defense'] ?? "no",
          $nationData['wonder_superior_logistical'] ?? "no",
          $nationData['wonder_universal_health'] ?? "no",
          $nationData['wonder_weapons_research'] ?? "no",
          $nationData['environment'] ?? null,
          $nationData['nation_strength'] ?? null,
          $nationData['defcon_level'] ?? null,
          $nationData['threat_level'] ?? null,
          $nationData['num_soldiers'] ?? null,
          $nationData['effective_soldiers'] ?? null,
          $nationData['defending_soldiers'] ?? null,
          $nationData['deployed_soldiers'] ?? null,
          $nationData['num_tanks'] ?? null,
          $nationData['defending_tanks'] ?? null,
          $nationData['deployed_tanks'] ?? null,
          $nationData['aircraft'] ?? null,
          $nationData['cruise_missiles'] ?? null,
          $nationData['navy_vessels'] ?? null,
          $nationData['nuclear_weapons'] ?? null,
          $nationData['num_spies'] ?? null,
          $nationData['soldiers_lost'] ?? null,
          $nationData['attacking_casualties'] ?? null,
          $nationData['defending_casualties'] ?? null,
          $nationData['total_population'] ?? null,
          $nationData['population_density'] ?? null,
          $nationData['military_personnel'] ?? null,
          $nationData['working_citizens'] ?? null,
          $nationData['criminals'] ?? null,
          $nationData['rehabbed_criminals'] ?? null,
          $nationData['population_happiness'] ?? null,
          $nationData['crime_index'] ?? null,
          $nationData['crime_prevention_score'] ?? null,
          $nationData['avg_gross_income'] ?? null,
          $nationData['avg_income_taxes'] ?? null,
          $nationData['avg_net_income'] ?? null,
          $nationData['total_income_taxes_collected'] ?? null,
          $nationData['total_expenses'] ?? null,
          $nationData['bills_paid'] ?? null,
          $nationData['purchases_over_time'] ?? null,
          $nationData['current_dinars'] ?? null
       ];
       if(count($values) !== 138) {
         log_debug("Value count mismatch: " . count($values) . " values provided, expected 138.");
       }
       $types = str_repeat("s", 138);
       mysqli_stmt_bind_param($insertNationStmt, $types, ...$values);
       if (!mysqli_stmt_execute($insertNationStmt)) {
         $err = mysqli_stmt_error($insertNationStmt);
         log_debug("Error inserting nation data: " . $err);
         $nationResponse = ["success" => false, "error" => "Error inserting nation data: " . $err];
       } else {
         mysqli_commit($con);
         $nationResponse = ["success" => true];
         log_debug("Nation data inserted successfully.");
       }
       mysqli_stmt_close($insertNationStmt);
    }
} else {
    $nationResponse = ["success" => true, "message" => "No nation data provided"];
    log_debug("No nation data provided.");
}

// ------------------------------
// Close the database connection.
mysqli_close($con);
ob_end_flush();

// Return a JSON response with results from all modules.
echo json_encode([
    "success" => true,
    "modules" => [
        "nuke_data" => $nukeResponse,
        "war_results" => $warResponse,
        "nation_data" => $nationResponse
    ],
    "public_ip" => $publicIp
]);
?>
