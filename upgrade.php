<?php
// Call with JSON parameter like so
//'{"installation_name":"mwilkie", "timezone":"America/Los_Angeles", "db_host":"localhost", "db_name":"thinkup_20120911", "db_socket":"/tmp/mysql.sock",  "db_port":""}';
$passed_in = $argv[1];

require_once 'config.inc.php';

chdir(dirname(__FILE__) . '/../../..');
require_once 'init.php';

$params = JSONDecoder::decode($passed_in);

//Avoid "Error: DateTime::__construct(): It is not safe to rely on the system's timezone settings" error
date_default_timezone_set($params->timezone);

$CHAM_CONFIG['timezone'] = $params->timezone;
$CHAM_CONFIG['db_host'] = $params->db_host;
$CHAM_CONFIG['db_name'] = $params->db_name;
$CHAM_CONFIG['db_socket'] = $params->db_socket;
$CHAM_CONFIG['db_port'] = $params->db_port;
$CHAM_CONFIG['site_root_path'] = "/user/".$params->installation_name."/";
$CHAM_CONFIG['source_root_path'] = getcwd();
$CHAM_CONFIG['data_dir_path'] = $CHAM_CONFIG['data_dir_root'] . $params->installation_name.'/';

//echo Utils::varDumpToString($params);
//echo Utils::varDumpToString($CHAM_CONFIG);

$cfg = Config::getInstance($CHAM_CONFIG);



// don't run via the web...
if (isset($_SERVER['SERVER_NAME'])) {
    die("This script should only be run via the command line.");
}

$no_version = true;
try {
    // do we need a migration?
    $db_version = UpgradeDatabaseController::getCurrentDBVersion($cached = false);
    $config = Config::getInstance();
    $thinkup_db_version = $config->getValue('THINKUP_VERSION');
    $filename = false;
    if ($db_version == $thinkup_db_version && ! $no_version) {
        error_log("\nYour ThinkUp database structure is up to date.\n");
        exit;
    } else {
        if (!$no_version) {
            print "\nThinkup needs to be upgraded to version $thinkup_db_version, proceed => [y|n] ";
            $handle = fopen ("php://stdin","r");
            $line = fgets($handle);
            if (trim($line) != 'y'){
                exit;
            }
        }
    }

    // run updates...
    // get migrations we need to run...
    if (!$no_version) {
        print "\nUpgrading Thinkup to version $thinkup_db_version...\n\n";
    }

    $upgrade_start_time = microtime(true);
    putenv('CLI_BACKUP=true');
    $upgrade_ctl = new UpgradeDatabaseController();

    $migrations = $upgrade_ctl->getMigrationList($db_version, $no_version);
    $install_dao = DAOFactory::getDAO('InstallerDAO');
    if ($no_version && count($migrations) > 0) {
        $s = count($migrations) > 1 ? 's' : '';
        print "\nFound " . count($migrations) . " migration" . $s . " to process...\n";
    }
    foreach($migrations as $migration) {
        if ($no_version) {
            print("\n  Running migration with file " . $migration['filename'] . "\n");
        } else {
            print("\n  Running migration " . $migration['version'] . "\n");
        }
        $sql = preg_replace('/\-\-.*/','', $migration['sql']);
        $install_dao->runMigrationSQL($sql, $migration['new_migration'], $migration['filename']);
    }
    if (count($migrations) == 0) {
        print("\n  No migrations to run...\n\n");
        exit;
    }

    $option_dao = DAOFactory::getDAO('OptionDAO');
    $option = $option_dao->getOptionByName(OptionDAO::APP_OPTIONS, 'database_version');
    if ($option) {
        $option_dao->updateOptionByName(OptionDAO::APP_OPTIONS, 'database_version',$thinkup_db_version);
    } else {
        $option_dao->insertOption(OptionDAO::APP_OPTIONS, 'database_version', $thinkup_db_version);
    }

    // delete upgrade token if it exists
    $upgrade_ctl->deleteTokenFile();

    $upgrade_end_time = microtime(true);
    $total_time = $upgrade_end_time - $upgrade_start_time;
    print "\nUpgrade complete. Total time elapsed: ".round($total_time, 2)." seconds\n\n";
} catch(Exception $e) {
    error_log("  Error: " . $e->getMessage() . "\n");
}

