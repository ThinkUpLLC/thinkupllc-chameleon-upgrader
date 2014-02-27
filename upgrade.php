<?php
// Call with JSON parameter like so
//'{"installation_name":"mwilkie", "timezone":"America/Los_Angeles", "db_host":"localhost", "db_name":"thinkup_20120911", "db_socket":"/tmp/mysql.sock",  "db_port":""}';
$passed_in = $argv[1];

require_once 'config.inc.php';
chdir(dirname(__FILE__) . '/../../..');
require_once 'init.php';

// Upgrade results array which will be JSON encoded and returned at the end of the script
$results = array('migration_attempt_commit'=>null, 'migration_success'=>false, 'migration_message'=>null);

try {
    //Get commit hash and add to $results array
    exec('git rev-parse --verify HEAD 2> /dev/null', $output);
    $commit_hash = $output[0];
    $results['migration_attempt_commit'] = $commit_hash;

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

    // do we need a migration?
    $db_version = UpgradeDatabaseController::getCurrentDBVersion($cached = false);
    $filename = false;

    // run updates...
    // get migrations we need to run...
    $upgrade_start_time = microtime(true);
    putenv('CLI_BACKUP=true');
    $upgrade_ctl = new UpgradeDatabaseController();

    $migrations = $upgrade_ctl->getMigrationList($db_version, true);
    $install_dao = DAOFactory::getDAO('InstallerDAO');
    if (count($migrations) > 0) {
        $s = count($migrations) > 1 ? 's' : '';
        $results['migration_message'] = $results['migration_message'].
        "\nFound " . count($migrations) . " migration" . $s . " to process...\n";
    }
    foreach($migrations as $migration) {
        $results['migration_message'] = $results['migration_message'].
        "\n  Running migration with file " . $migration['filename'] . "\n";
        $sql = preg_replace('/\-\-.*/','', $migration['sql']);
        $install_dao->runMigrationSQL($sql, $migration['new_migration'], $migration['filename']);
    }
    if (count($migrations) == 0) {
        $results['migration_message'] = $results['migration_message']."\n  No migrations to run...\n\n";
    }

    $option_dao = DAOFactory::getDAO('OptionDAO');
    $option = $option_dao->getOptionByName(OptionDAO::APP_OPTIONS, 'database_version');
    require THINKUP_WEBAPP_PATH . 'install/version.php';
    if ($option) {
        $option_dao->updateOptionByName(OptionDAO::APP_OPTIONS, 'database_version', $THINKUP_VERSION);
    } else {
        $option_dao->insertOption(OptionDAO::APP_OPTIONS, 'database_version', $THINKUP_VERSION);
    }

    // delete upgrade token if it exists
    $upgrade_ctl->deleteTokenFile();

    $upgrade_end_time = microtime(true);
    $total_time = $upgrade_end_time - $upgrade_start_time;
    $results['migration_message'] = $results['migration_message'].
    "\nUpgrade complete. Total time elapsed: ".round($total_time, 2)." seconds\n\n";
    $results['migration_success'] = true;
} catch (Exception $e) {
    $results['migration_message'] = $results['migration_message']. get_class($e).": ".$e->getMessage() . "\n";
    $results['migration_success'] = false;
}

echo json_encode($results);

