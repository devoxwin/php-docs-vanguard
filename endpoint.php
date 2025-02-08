<?php
// Start output buffering so headers can be sent.
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
// Error Reporting (disable display in production)
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
$host     = 'cn-valyria-prod.mysql.database.azure.com';    // Provided host name
$dbname   = 'cybernations_db';     // Your database name
$username = 'base_admin';          // Provided username
$password = 'sTP5rE[>cw6q&Nv4';     // Provided password
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
// ------------------------------
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
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
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
                foreach ($data['wardata'] as $index => $war) {
                    mysqli_stmt_bind_param($selectWarStmt, "i", $war['war_id']);
                    mysqli_stmt_execute($selectWarStmt);
                    mysqli_stmt_store_result($selectWarStmt);
                    $exists = mysqli_stmt_num_rows($selectWarStmt) > 0;
                    mysqli_stmt_free_result($selectWarStmt);
                    if ($exists) {
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
                            log_debug("Error updating war index $index: " . mysqli_stmt_error($updateWarStmt));
                        }
                    } else {
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
// ------------------------------
if (isset($data['nationData']) && is_array($data['nationData'])) {
    log_debug("Processing nation data.");
    // For convenience, assign the nation data array to a variable.
    $nd = $data['nationData'];
    // Prepare the INSERT statement. (The fields here match those from the CREATE TABLE statement.)
    $insertNationSql = "INSERT INTO cybernations_db.nation_data (
         nation_id, ruler, nation_name, last_donation, alliance_affiliation, alliance_role, alliance_seniority,
         government_type, government_decision, national_religion, religion_decision, nation_team, nation_created,
         technology, infrastructure, tax_rate, area_of_influence, purchased_land, land_modifiers, land_growth,
         war_peace_preference,
         resource1, resource2, resource3, resource4, resource5, resource6, resource7, resource8, resource9, resource10, resource11, resource12,
         native_resource_1, native_resource_2,
         trade_slots_filled, trade_slots_max,
         airports, banks, barracks, churches, clinics, drydocks, factories, foreign_ministries, forward_operating_bases, guerrilla_camps, harbors, hospitals, intelligence_agencies, labor_camps, missile_defenses, munitions_factories, naval_academies, naval_construction_yards, offices_of_propaganda, police_headquarters, prisons, rehabilitation_facilities, satellites, schools, shipyards, stadiums, universities,
         wonder_agriculture_dev, wonder_anti_air_defense, wonder_central_intel, wonder_disaster_relief, wonder_emp_weaponization,
         wonder_federal_aid, wonder_federal_reserve, wonder_foreign_air_base, wonder_foreign_army_base, wonder_foreign_naval_base,
         wonder_great_monument, wonder_great_temple, wonder_great_university, wonder_hidden_nuke_silo, wonder_interceptor_missile,
         wonder_internet, wonder_interstate_system, wonder_manhattan_project, wonder_mars_base, wonder_mars_colony, wonder_mars_mine, wonder_mining_consortium,
         wonder_movie_industry, wonder_national_cemetery, wonder_national_environment_office, wonder_national_research_lab, wonder_national_war_memorial,
         wonder_nuclear_power, wonder_pentagon, wonder_political_lobbyists, wonder_scientific_development, wonder_social_security,
         wonder_space_program, wonder_stock_market, wonder_strategic_defense, wonder_superior_logistical, wonder_universal_health, wonder_weapons_research,
         environment, nation_strength, defcon_level, threat_level,
         num_soldiers, effective_soldiers, defending_soldiers, deployed_soldiers,
         num_tanks, defending_tanks, deployed_tanks,
         aircraft, cruise_missiles, navy_vessels, nuclear_weapons, num_spies,
         soldiers_lost, attacking_casualties, defending_casualties,
         total_population, population_density,
         military_personnel, working_citizens, criminals, rehabbed_criminals, population_happiness, crime_index, crime_prevention_score,
         avg_gross_income, avg_income_taxes, avg_net_income,
         total_income_taxes_collected, total_expenses, bills_paid, purchases_over_time, current_dinars
    ) VALUES (
         ?, ?, ?, ?, ?, ?, ?,
         ?, ?, ?, ?, ?, ?,
         ?, ?, ?, ?, ?, ?, ?,
         ?,
         ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
         ?, ?,
         ?, ?,
         ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
         ?, ?, ?, ?, ?,
         ?, ?, ?, ?, ?,
         ?, ?, ?, ?, ?, ?, ?, ?,
         ?, ?, ?, ?, ?,
         ?, ?, ?, ?, ?,
         ?, ?, ?,
         ?, ?, ?, ?,
         ?, ?, ?, ?,
         ?, ?, ?, ?, ?
    )";
    $insertNationStmt = mysqli_prepare($con, $insertNationSql);
    if (!$insertNationStmt) {
        $err = mysqli_error($con);
        log_debug("nation_data INSERT preparation error: " . $err);
        $nationResponse = ["success" => false, "error" => "nation_data INSERT preparation error: " . $err];
    } else {
        // Bind parameters. The binding string is long. For clarity, here’s a sample binding string.
        // You must match each parameter type accordingly. Assume:
        // 'i' for integer, 's' for string, 'd' for double (decimal).
        // This is an example; you must adjust according to your schema.
        $bindString = "issssssssssssssssss" .  
                      "ssssssssssss" .           // 12 resource fields
                      "ss" .                     // native resources
                      "ii" .                     // trade slots
                      "iiiiiiiiiiiiiiiii" .      // improvements (17 fields)
                      "ssssssssssssssssssssssssssssssssssssssssssssssss" . // national wonders (40 fields)
                      "dd" .                    // environment, nation_strength (doubles)
                      "i" .                     // defcon_level
                      "s" .                     // threat_level
                      "iiii" .                  // num_soldiers, effective_soldiers, defending_soldiers, deployed_soldiers
                      "iii" .                   // num_tanks, defending_tanks, deployed_tanks
                      "iii" .                   // aircraft, cruise_missiles, navy_vessels
                      "ii" .                    // nuclear_weapons, num_spies
                      "ddd" .                   // soldiers_lost, attacking_casualties, defending_casualties (as doubles)
                      "i" .                     // total_population
                      "d" .                     // population_density
                      "iiiiiiiii" .             // military_personnel, working_citizens, criminals, rehabbed_criminals, population_happiness, crime_index, crime_prevention_score, avg_gross_income, avg_income_taxes (some as ints, some as doubles)
                      "d" .                     // avg_net_income
                      "ddddd";                  // total_income_taxes_collected, total_expenses, bills_paid, purchases_over_time, current_dinars
        // NOTE: The bind string above is just an illustrative example. In practice you must create a bind string that exactly matches the number and types of parameters in your INSERT.
        
        // For simplicity in this example, we'll assume your $nd array has all keys and we bind them in order.
        // In a real-world scenario, you might generate the bind parameters dynamically.
        mysqli_stmt_bind_param($insertNationStmt, $bindString,
            $nd['nation_id'],
            $nd['ruler'],
            $nd['nation_name'],
            $nd['last_donation'],
            $nd['alliance_affiliation'],
            $nd['alliance_role'],
            $nd['alliance_seniority'],
            $nd['government_type'],
            $nd['government_decision'],
            $nd['national_religion'],
            $nd['religion_decision'],
            $nd['nation_team'],
            $nd['nation_created'],
            $nd['technology'],
            $nd['infrastructure'],
            $nd['tax_rate'],
            $nd['area_of_influence'],
            $nd['purchased_land'],
            $nd['land_modifiers'],
            $nd['land_growth'],
            $nd['war_peace_preference'],
            $nd['resource1'],
            $nd['resource2'],
            $nd['resource3'],
            $nd['resource4'],
            $nd['resource5'],
            $nd['resource6'],
            $nd['resource7'],
            $nd['resource8'],
            $nd['resource9'],
            $nd['resource10'],
            $nd['resource11'],
            $nd['resource12'],
            $nd['native_resource_1'],
            $nd['native_resource_2'],
            $nd['trade_slots_filled'],
            $nd['trade_slots_max'],
            $nd['airports'],
            $nd['banks'],
            $nd['barracks'],
            $nd['churches'],
            $nd['clinics'],
            $nd['drydocks'],
            $nd['factories'],
            $nd['foreign_ministries'],
            $nd['forward_operating_bases'],
            $nd['guerrilla_camps'],
            $nd['harbors'],
            $nd['hospitals'],
            $nd['intelligence_agencies'],
            $nd['labor_camps'],
            $nd['missile_defenses'],
            $nd['munitions_factories'],
            $nd['naval_academies'],
            $nd['naval_construction_yards'],
            $nd['offices_of_propaganda'],
            $nd['police_headquarters'],
            $nd['prisons'],
            $nd['rehabilitation_facilities'],
            $nd['satellites'],
            $nd['schools'],
            $nd['shipyards'],
            $nd['stadiums'],
            $nd['universities'],
            $nd['wonder_agriculture_dev'],
            $nd['wonder_anti_air_defense'],
            $nd['wonder_central_intel'],
            $nd['wonder_disaster_relief'],
            $nd['wonder_emp_weaponization'],
            $nd['wonder_federal_aid'],
            $nd['wonder_federal_reserve'],
            $nd['wonder_foreign_air_base'],
            $nd['wonder_foreign_army_base'],
            $nd['wonder_foreign_naval_base'],
            $nd['wonder_great_monument'],
            $nd['wonder_great_temple'],
            $nd['wonder_great_university'],
            $nd['wonder_hidden_nuke_silo'],
            $nd['wonder_interceptor_missile'],
            $nd['wonder_internet'],
            $nd['wonder_interstate_system'],
            $nd['wonder_manhattan_project'],
            $nd['wonder_mars_base'],
            $nd['wonder_mars_colony'],
            $nd['wonder_mars_mine'],
            $nd['wonder_mining_consortium'],
            $nd['wonder_movie_industry'],
            $nd['wonder_national_cemetery'],
            $nd['wonder_national_environment_office'],
            $nd['wonder_national_research_lab'],
            $nd['wonder_national_war_memorial'],
            $nd['wonder_nuclear_power'],
            $nd['wonder_pentagon'],
            $nd['wonder_political_lobbyists'],
            $nd['wonder_scientific_development'],
            $nd['wonder_social_security'],
            $nd['wonder_space_program'],
            $nd['wonder_stock_market'],
            $nd['wonder_strategic_defense'],
            $nd['wonder_superior_logistical'],
            $nd['wonder_universal_health'],
            $nd['wonder_weapons_research'],
            $nd['environment'],
            $nd['nation_strength'],
            $nd['defcon_level'],
            $nd['threat_level'],
            $nd['num_soldiers'],
            $nd['effective_soldiers'],
            $nd['defending_soldiers'],
            $nd['deployed_soldiers'],
            $nd['num_tanks'],
            $nd['defending_tanks'],
            $nd['deployed_tanks'],
            $nd['aircraft'],
            $nd['cruise_missiles'],
            $nd['navy_vessels'],
            $nd['nuclear_weapons'],
            $nd['num_spies'],
            $nd['soldiers_lost'],
            $nd['attacking_casualties'],
            $nd['defending_casualties'],
            $nd['total_population'],
            $nd['population_density'],
            $nd['military_personnel'],
            $nd['working_citizens'],
            $nd['criminals'],
            $nd['rehabbed_criminals'],
            $nd['population_happiness'],
            $nd['crime_index'],
            $nd['crime_prevention_score'],
            $nd['avg_gross_income'],
            $nd['avg_income_taxes'],
            $nd['avg_net_income'],
            $nd['total_income_taxes_collected'],
            $nd['total_expenses'],
            $nd['bills_paid'],
            $nd['purchases_over_time'],
            $nd['current_dinars']
        );
        if (mysqli_stmt_execute($insertNationStmt)) {
            $nationResponse = ["success" => true];
            log_debug("Nation data inserted successfully.");
        } else {
            $nationResponse = ["success" => false, "error" => mysqli_stmt_error($insertNationStmt)];
            log_debug("Nation data insertion error: " . mysqli_stmt_error($insertNationStmt));
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

// Flush output buffering.
ob_end_flush();

// Return a JSON response with results from all modules.
echo json_encode([
    "success" => true,
    "modules" => [
        "nuke_data" => $nukeResponse,
        "war_results" => $warResponse,
        "nation_data" => $nationResponse
    ],
    "public_ip" => $publicIp,
    "debug" => $debugMessages
]);
?>
