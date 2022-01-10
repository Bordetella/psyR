<?php

/**
 * Simple MySQL Install (smysqlin)
 *
 * Install databases for your PHP application with ease and manage schemas
 * Requires at least an empty existing database
 *
 * PHP version 5
 *
 * @category  PHP
 * @package   smysqlin
 * @author    Cyril Tata <cyril.tata@gmail.com>
 */
class smysqlin {

	/**
	 * @var PDO
	 */
	private $pdo;
	private $schema;
	private $bookmark;
	private $affected_rows;
	private $warning_count;
	private $_querystats = array();

	private static $instances = array();
	protected static $schemas = array();
	protected static $schemafile;

	public $dsn;

	protected function __construct($schema, array $params) {
		$options = array(
			'host' => $params['host'],
			'port' => $params['port'],
			'dbname' => $params['dbname'],
			'charset' => strtolower($params['charset']),
		);

		$this->schema = $schema;
		$this->bookmark = SMYSQLIN_BOOKMARK_DIR . DIRECTORY_SEPARATOR . $this->schema . '.json';
		$this->dsn = 'mysql:' . http_build_query($options, null, ';');

		$this->pdo = new PDO($this->dsn, $params['user'], $params['pass'], array(
			PDO::ATTR_EMULATE_PREPARES => true,
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		));
	}

	public function __destruct() {
		$this->pdo = null;
		unset(self::$instances[$this->schema]);
	}

	/**
	 * @return PDO
	 */
	public function pdo() {
		return $this->pdo;
	}

	/**
	 * Execute sql query
	 *
	 * @param string $query
	 * @return PDOStatement
	 */
	public function query($query) {
		$sth = $this->pdo->query($query);
		$this->querystats_add();
		return $sth;
	}

	/**
	 * Execute sql file
	 *
	 * Return array of results (affected_rows, id-s, query time etc)
	 *
	 * If file does not exist or isn't readable then Exception is thrown.
	 *
	 * @param string $input_file
	 * @return     array
	 * @throws Exception
	 */
	public function exec_file($input_file) {
		if (!file_exists($input_file) && !is_readable($input_file)) {
			throw self::exception("Can't read file: $input_file");
		}

		$this->querystats_new();
		$time_start = microtime(true);

		if (substr($input_file, -4) == '.php') {
			$queries = array();
			// export $dbh for script as db object
			$dbh = $this->pdo();
			require $input_file;
			if (!is_array($queries)) {
				throw self::exception('$queries should be an array');
			}
		} else {
			$queries = self::parse_sql_file($input_file);
		}

		$this->exec_queries($queries);

		$this->_querystats['time'] = (microtime(true) - $time_start);

		$res = $this->_querystats;
		$this->querystats_clear();

		return $res;
	}

	/**
	 * Execute SQL queries
	 * Return array of results (affected_rows, id-s, query time etc)
	 *
	 * @param     array|string $queries
	 * @return     array
	 */
	public function exec_query($queries) {
		$this->querystats_new();
		$time_start = microtime(true);

		$this->exec_queries($queries);

		$this->_querystats['time'] = (microtime(true) - $time_start);

		$res = $this->_querystats;
		$this->querystats_clear();

		return $res;
	}

	public function use_patches(array $sqlfiles, $init_schema) {
		$patches = self::applied_patches($this->bookmark);

		if ($patches === null && $init_schema) {
			echo "Creating initial schema using $init_schema\n";
			self::apply_patches(array(array('number' => 0, 'name' => basename($init_schema), 'timestamp' => time())), $this->bookmark);
			$res = $this->exec_file($init_schema);
			$patches = self::applied_patches($this->bookmark);
		}

		if (empty($patches)) {
			throw self::exception("Can't detect applied patches! Check '{$this->bookmark}' file!");
		}

		$addCount = 0;
		$keys = array_keys($sqlfiles);
		$maxpatch = end($keys);
		$failed = array();

		foreach ($sqlfiles as $number => $file) {
			if (!isset($patches[$number])) {
				echo "* Applying patch: {$number}/{$maxpatch} [", basename($file), "] \n";
				$res = $this->exec_file($file);
				print_res($res);
				if ($res['errors']) {
					$failed[] = basename($file);
					continue;
				}

				$patches[$number] = array(
					'number' => $number,
					'name' => basename($file),
					'timestamp' => time(),
				);
				self::apply_patches($patches, $this->bookmark);
				$addCount++;
			}
		}

		if (!$maxpatch) {
			$maxpatch = '0';
		}

		if ($addCount == 0) {
			echo "* Your database is already up-to-date. Version: ", $maxpatch, "\n";
		} elseif ($failed) {
			echo "* Your database was updated but the following patches failed: ", implode(', ', $failed), "\n";
		} else {
			echo "* Your database is now up-to-date. Version:, ", $maxpatch, "\n";
		}
	}

	private function querystats_new() {
		$this->_querystats = array(
			'count' => 0,
			'affected_rows' => 0,
			'errors' => 0,
			'time' => 0,
		);
	}

	private function querystats_add() {
		// skip collecting stats if we're not in batch mode
		if (empty($this->_querystats)) {
			return;
		}
		$this->_querystats['count'] ++;

		$affected = $this->affected_rows;
		if ($affected > 0) {
			// avoid flling -1 here from union selects
			$this->_querystats['affected_rows'] += $affected;
		}
		$this->_querystats['errors'] += $this->warning_count;
	}

	private function querystats_clear() {
		$this->_querystats = array();
	}

	/**
	 * Execute SQL queries.
	 * Takes care of processing results and warning counts.
	 * Warnings are printed out if in VERBOSE mode.
	 *
	 * @param array|string $queries
	 * @throws smysqlin_exception
	 */
	private function exec_queries($queries) {
		$this->warning_count = $i = 0;
		$this->pdo->beginTransaction();
		try {
			foreach ((array) $queries as $query) {
				if (VERBOSE) {
					echo sprintf("%s[%d]> ", PROGRAM, ++$i), $query, "\n";
				} else {
					// print dots only in normal mode
					echo ".";
					flush();
				}

				$this->affected_rows = $this->pdo->exec($query);
				if ($this->affected_rows === false) {
					throw self::exception("Unable to execute query '$query'");
				}

				$this->querystats_add();
				echo "\n";
			}
			$this->pdo->commit();
		} catch (Exception $e) {
			$this->pdo->rollBack();
			$this->_querystats['errors'] = 1;
			echo $e->getMessage();
		}
	}

	/**
	 * set schema file to load schemas from
	 * if path is relative, the path is set to be from SQLPOOL_DEFAULT_INIDIR
	 *
	 * @param string $file
	 * @param bool $keepcache set to false to reset parsed schemas
	 * @return string
	 */
	public static function set_default_schemafile($file, $keepcache = true) {
		if ($file == basename($file)) {
			$file = SMYSQLIN_CONFIG_DIR . DIRECTORY_SEPARATOR . $file;
		}

		if (!$keepcache) {
			// reset cached schemas
			self::$schemas = null;
		}

		return self::$schemafile = $file;
	}

	public static function get_schema($schema) {
		if (isset(self::$schemas[$schema])) {
			return self::$schemas[$schema];
		}
		return null;
	}

	public static function load_schema_file($file, $schema = null) {
		return self::parse_ini_file($file, $schema);
	}

	public static function parse_ini_file($file, $schema = null) {
		if (!is_readable($file)) {
			throw new RuntimeException('Schema file is not readable: ' . $file);
		}

		self::$schemas = parse_ini_file($file, true);
		if (self::$schemas === false) {
			throw new RuntimeException('Unable to parse schema file: ' . $file);
		}

		if ($schema !== null) {
			return self::get_schema($schema);
		}

		return self::$schemas;
	}

	public static function validate_schemafile($schemafile, $project = null) {
		if (!is_readable($schemafile)) {
			throw self::exception("Can't auto-update $project: Can't open '$schemafile'");
		}

		$data = smysqlin::parse_ini_file($schemafile);
		if (isset($data['smysqlin'])) {
			$smysqlin = $data['smysqlin'];
			// If required info is not found, give fatal
			if (empty($smysqlin['schema']) || empty($smysqlin['patches_dir'])) {
				throw self::exception("Can't auto-update $project: Required 'schema' or 'patches_dir' or 'init_schema' option missing");
			}

			// If specified schema is not found, give fatal
			if (empty($data[$smysqlin['schema']])) {
				throw self::exception("Could not find section '[{$smysqlin['schema']}]' in schema file '$schemafile'");
			}
		} else {
			throw self::exception("Could not find required section '[smysqlin]' in schema file '$schemafile'");
		}
		return $smysqlin;
	}

	/**
	 * 
	 * @param string $instance
	 * @return smysqlin
	 * @throws smysqlin_exception
	 */
	public static function get_instance($instance) {
		if (!isset(self::$schemas[$instance])) {
			throw self::exception("Unable to load instance '$instance' from loaded schemas");
		}

		if (!isset(self::$instances[$instance])) {
			self::$instances[$instance] = new self($instance, self::$schemas[$instance]);
		}

		return self::$instances[$instance];
	}

	/**
	 * Returns a smysqlin_exception exception
	 *
	 * @param string $message
	 * @param int $code
	 * @return smysqlin_exception
	 */
	private static function exception($message, $code = null) {
		return new smysqlin_exception($message, $code);
	}

	/**
	 * Parse $input_file into separate queries if verbose mode
	 * Returns file contents in normal mode.
	 */
	private static function parse_sql_file($input_file) {
		$input = file_get_contents($input_file);
		if ($input === false) {
			throw self::exception("Can't read file: '$input_file'");
		}
		$input = trim($input);
		if (empty($input)) {
			return array();
		}

		// if not verbose, return original query
		// in normal mode (production) we do not want to see errors caused by our buggy parsing
		//if (!VERBOSE) {
		//	return array($input);
		//}

		return self::split_queries($input);
	}

	/**
	 * Split queries
	 * @link http://www.dev-explorer.com/articles/multiple-mysql-queries
	 *
	 * @param string $input
	 * @return array An array containing query strins
	 */
	private static function split_queries($input) {
		$input = preg_replace("!
			# C++ block comments
			# need to enable /s modifier only here
			(/\*(?s:.*?)\*/)

			# shell line comments, they end when line ends
			|(\#(?:.*?)$)

			# SQL line comments
			# well, somewhy can't use \s, even syntax should say so, so just
			# list most common, the space in []'s
			# what we need here, is lookahead pattern
			|(--[ ]?.*?$)
		!mx", "", $input);

		// split queries
		$ret = array();
		// regexp: not apostrophe or not quoted apostrophe
		$rnq = "(?:[^']|(?:\\[^']))";
		// regexp: apostrophe or quoted apostrophe
		$rq = "(?:'|(?:\\'))";

		$parts = preg_split("/;+(?= ( $rnq{0,1000} $rq $rnq{0,1000} $rq )* $rnq{0,100} $rnq)/x", $input);
		if ($parts === false) {
			throw new RuntimeException("preg_split() failed");
		}
		foreach ($parts as $query) {
			$q = trim($query);
			if (!empty($q)) {
				$ret[] = $q;
			}
		}
		return $ret;
	}

	/**
	 * Get applied patches from bookmark file
	 *
	 * @param string $bookmark_file
	 * @return null|array
	 * @throws smysqlin_exception
	 */
	private static function applied_patches($bookmark_file) {
		if (!file_exists($bookmark_file)) {
			return null;
		}

		$json = file_get_contents($bookmark_file);
		if ($json === false || !is_array($contents = json_decode($json))) {
			throw self::exception("Unable to read bookmark file {$bookmark_file}");
		}

		if (!$contents) {
			return null;
		}

		$patches = array();
		foreach ($contents as $patch) {
			$patches[$patch->number] = $patch;
		}
		return $patches;
	}

	/**
	 * Write applied patches to bookmark file
	 *
	 * @param array $patches
	 * @param string $bookmark_file
	 * @return boolean
	 * @throws smysqlin_exception
	 */
	private static function apply_patches($patches, $bookmark_file) {
		$dir = dirname($bookmark_file);
		if (!is_dir($dir) && !mkdir($dir, 0644, true)) {
			throw self::exception("Unable to save applied patches");
		}

		$patches = array_values($patches);
		if (!($contents = json_encode($patches, JSON_PRETTY_PRINT)) || !file_put_contents($bookmark_file, $contents)) {
			throw self::exception("Unable to save applied patches");
		}

		return true;
	}

}

class smysqlin_exception extends Exception {
	public function __construct($message, $code, $previous = null) {
		parent::__construct($message, $code, $previous);
	}
}
