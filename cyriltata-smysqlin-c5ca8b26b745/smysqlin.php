#!/usr/bin/php
<?php
/**
 * Simple MySQL Install (smysqlin)
 *
 * Install databases for your PHP application with ease and manage schemas
 * Requires at least an empty existing database
 *
 * @author Cyril Tata <cyril.tata@gmail.com>
 * 
 */
if (getenv('SMYSQLIN_CONFIG_DIR') === false) {
	putenv("SMYSQLIN_CONFIG_DIR=/etc/smysqlin");
}
if (getenv('SMYSQLIN_LOG_DIR') === false) {
	putenv("SMYSQLIN_LOG_DIR=/var/log/mysqlin");
}

define('PROGRAM', basename($argv[0], ".php"));
define('SMYSQLIN_DIR', dirname(__FILE__));
define('SMYSQLIN_BOOKMARK_DIR', SMYSQLIN_DIR . '/bookmarks');
define('SMYSQLIN_CONFIG_DIR', getenv('SMYSQLIN_CONFIG_DIR'));
define('SMYSQLIN_LOG_DIR', getenv('SMYSQLIN_LOG_DIR'));

if (!class_exists('PDO', false)) {
	error_log(PROGRAM . ': PHP PDO extension is required. (use apt-get install php5-pdo to install)');
	exit(1);
}

if ('cli' != php_sapi_name()) {
	error_log(PROGRAM . ': Command line usage only');
	exit(1);
}

function usage() {
	echo "Usage: ", PROGRAM, " [OPTION] PROJECT

Optional options:
  -c FILE      Use FILE as SCHEMAFILE and the string after
  -s SCHEMA    Use SCHEMA from SCHEMAFILE
  -i FILE      Path to initial schema file
  -n PATHCES   Directory to find DB UPATES
  -e SQL       Execute SQL query via ", PROGRAM, "
  -f FILE      Execute SQL commands from FILE
  -h HELP      This help screen
  -v           Increase verbosity
  [not implemented] -o INIT      Initialize ", PROGRAM, " by setting somethings as already done, e.g schema.sql, 001_patch1.sql, 002_patch.
";
}

function getopts($parameters) {
	global $argv, $argc;

	$options = getopt($parameters);
	$pruneargv = array();
	foreach ($options as $option => $value) {
		foreach ($argv as $key => $chunk) {
			$regex = '/^' . (isset($option[1]) ? '--' : '-') . $option . '/';
			if ($chunk == $value && $argv[$key - 1][0] == '-' || preg_match($regex, $chunk)) {
				array_push($pruneargv, $key);
			}
		}
	}
	while ($key = array_pop($pruneargv)) {
		unset($argv[$key]);
	}

	// renumber $argv to be continuous
	$argv = array_values($argv);
	// reset $argc to be correct
	$argc = count($argv);

	return $options;
}

/**
 * sprintf compatible die(), also sets exit code as 1
 */
function fatal() {
	$args = func_get_args();
	$fmt = array_shift($args);
	$message = vsprintf($fmt, $args);

	echo PROGRAM, ": ERROR: ", $message;
	exit(1);
}

function print_res($res) {
	$time = $res['time'];
	printf("Executed %s statements, %s errors. %s rows affected (%s).\n", $res['count'], $res['errors'], $res['affected_rows'], $time);

	if (!empty($res['errors'])) {
		printf("Because an error occured, all executed statements were rolled back");
	}
}

require_once SMYSQLIN_DIR . '/smysqlin.class.php';

$default_opt = array(
	'c' => '', // schemafile
	's' => '', // SCHEMA from SCHEMAFILE
	'i' => null, // Path to initial schema file
	'n' => '', // Directory to patches
	'e' => '', // execute SQL
	'f' => '', // execute SQL from FILE
	'h' => null, // --help
	'v' => null, // --verbose
	'o' => null, // Forge update of schema file and patches file
);

$opt = getopts('c:s:n:e:f:hvi:o:h');

if (isset($opt['h'])) {
	usage();
	exit(0);
}

try {

	// $project is in $argv[1] even after options parsing
	$project = $argc >= 2 ? $argv[1] : (!empty($opt['s']) && empty($opt['c']) ? $opt['s'] : null);

	// Read commands and set other default options
	if ($project) {
		$_schemafile = !empty($opt['c']) ? $opt['c'] : "$project.ini";
		$schemafile = smysqlin::set_default_schemafile($_schemafile);

		$smysqlin = smysqlin::validate_schemafile($schemafile, $project);

		$default_opt['c'] = $schemafile;
		$default_opt['s'] = $smysqlin['schema'];
		$default_opt['n'] = trim($smysqlin['patches_dir']);
		$default_opt['i'] = !empty($smysqlin['init_schema']) ? $smysqlin['init_schema'] : null;
	}

	$opt = array_merge($default_opt, $opt);
	$schema = $opt['s'];
	$schemafile = $opt['c'];
	$init_schema = $opt['i'];
	$update_path = $opt['n'];
	$sqlquery = $opt['e'];
	$sqlfile = $opt['f'];
	$forge = $opt['o'];

	// we need global use of them
	define('VERBOSE', $opt['v'] !== null);

	// fallback to default use
	if (empty($schemafile)) {
		$schemafile = "$schema.ini";
	}

	if (!$schema || !$schemafile || !($sqlfile || $update_path || $sqlquery)) {
		usage();
		exit(1);
	}


	smysqlin::load_schema_file($schemafile);

	$myin = smysqlin::get_instance($schema);

	echo PROGRAM, " Initialize: Schema: {$schema}, DSN: {$myin->dsn}\n ........ \n";

	if ($sqlquery) {
		$res = $myin->exec_query($sqlquery);
		print_res($res);
	}

	if ($sqlfile) {
		$res = $myin->exec_file($sqlfile);
		print_res($res);
	}

	if ($update_path) {
		$files = array();

		if (($handle = @opendir($update_path))) {
			while (false !== ($file = readdir($handle))) {
				$number = substr($file, 0, strpos($file, '_'));
				if (in_array(substr($file, -4), array('.sql', '.php')) && is_numeric($number)) {
					$files[(int) $number] = $update_path . '/' . $file;
				}
			}
			closedir($handle);
			ksort($files);
			$myin->use_patches($files, $init_schema);
		} else {
			throw new Exception("Can't read patches directory: " . $update_path);
		}
	}
} catch (Exception $e) {
	fatal("%s\n", $e->getMessage());
	exit(1);
}

exit(0);
