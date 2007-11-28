<?

/*
 * This file is part of SimpleSAMLphp. See the file COPYING in the
 * root of the distribution for licence information.
 *
 * This file implements a storage class which stores data to one or more
 * groups memcache servers.
 *
 * The goals of this storage class is to provide failover, redudancy and load
 * balancing. This is accomplished by storing the data object to several
 * groups of memcache servers. Each data object is replicated to every group
 * of memcache servers, but it is only stored to one server in each group.
 *
 * For this code to work correctly, all web servers accessing the data must
 * have the same clock (as measured by the time()-function). Different clock
 * values will lead to incorrect behaviour.
 */

/* We need access to the configuration from config/config.php. */
require_once('SimpleSAML/Configuration.php');


class SimpleSAML_MemcacheStore {


	/* This variable contains the last commit time of this object.
	 * This is used to determine which of the memcache servers contain
	 * the newest version.
	 *
	 * This variable is serialized.
	 */
	private $lastCommitTime = NULL;


	/* This variable contains the id for this data.
	 *
	 * This variable is serialized.
	 */
	private $id = NULL;


	/* This variable contains an array with all key-value pairs stored
	 * in this object.
	 *
	 * This variable is serialized.
	 */
	private $data = NULL;


	/* This variable contains the serialized data which is currently
	 * stored on the memcache servers. By comparing the data which is
	 * stored against the current data, we can determine whether we
	 * should update the data.
	 *
	 * If this variable is NULL, then we need to store data to the
	 * memcache servers.
	 *
	 * This variable isn't serialized.
	 */
	private $savedData = NULL;


	/* Private cache of the memcache servers we are using. */
	private static $serverGroups = NULL;



	/* This function is used to find an existing storage object. It will
	 * return NULL if no storage object with the given id is found.
	 *
	 * Parameters:
	 *  $id        The id of the storage object we are looking for. A id
	 *             consists of lowercase alphanumeric characters.
	 *
	 * Returns:
	 *  The corresponding MemcacheStorage object if the data is found
	 *  or NULL if it isn't found.
	 */
	public static function find($id) {
		assert(self::isValidID($id));

		$mustUpdate = FALSE;
		$latest = NULL;
		$latestSerializedValue = NULL;

		/* Search all the servers for the given id. */
		foreach(self::getMemcacheServers() as $server) {
			$serializedValue = $server->get($id);
			if($serializedValue === FALSE) {
				/* Either the server is down, or we don't have
				 * the value stored on that server.
				 */
				$mustUpdate = TRUE;
				continue;
			}

			/* Deserialize the object. */
			$v = unserialize($serializedValue);

			/* Make sure that the deserialized object is of the
			 * correct type.
			 */
			if(!($v instanceof self)) {
				$e = 'We retrieved an object from the' .
				     ' memcache server which wasn\'t an' .
				     ' instance of a MemcacheStore object.' .
				     ' This should never happen, and is a ' .
				     ' sign that the MemcacheStore may be ' .
				     ' unreliable.';
				error_log($e);
				die($e);
			}

			if($latest === NULL) {
				$latest = $v;
				$latestSerializedValue = $serializedValue;
				continue;
			}

			if($latest->lastCommitTime == $v->lastCommitTime) {
				/* They were committed at the same time. Assume
				 * that they are equal.
				 */
				continue;
			}

			/* They are different. We need to update at least one
			 * of them to maintain synchronization.
			 */

			$mustUpdate = TRUE;

			/* Update $latest if $v is newer than $latest. */
			if($latest->lastCommitTime < $v->lastCommitTime) {
				$latest = $v;
				$latestSerializedValue = $serializedValue;
			}
		}

		/* Check if we found any stored object matching the id. */
		if($latest === NULL) {
			return NULL;
		}

		/* If we don't need to update this data object, then we can
		 * store the serialized value in the MemcacheStore object. If
		 * we need to update this object, then we don't store the
		 * serialized value.
		 */
		if($mustUpdate === FALSE) {
			$latest->savedData = $latestSerializedValue;
		}

		/* Add a call to save the data when we exit. */
		register_shutdown_function(array($latest, 'save'));

		return $latest;
	}


	/* This constructor is used to create a new storage object. The storage
	 * object will be created with the specified id and the initial
	 * content passed in the data argument.
	 *
	 * If there exists a storage object with the specified id, then it will
	 * be overwritten.
	 *
	 * Parameters:
	 *  $id          The id of the storage object.
	 *  $data        An array containing the initial data of the storage
	 *               object.
	 */
	public function __construct($id, $data = array()) {
		/* Validate arguments. */
		assert(self::isValidID($id));
		assert(is_array($data));

		$this->id = $id;
		$this->data = $data;

		/* Add a call to save the data when we exit. */
		register_shutdown_function(array($this, 'save'));
	}


	/* This magic function is called on serialization of this class.
	 * It returns a list of the names of the variables which should be
	 * serialized.
	 */
	private function __sleep() {
		return array('lastCommitTime', 'id', 'data');
	}


	/* This function retrieves the specified key from this storage object.
	 *
	 * Parameters:
	 *  $key    The key we should retrieve the value of.
	 *
	 * Returns:
	 *  The value of the specified key, or NULL of the key wasn't found.
	 */
	public function get($key) {
		if(!array_key_exists($key, $this->data)) {
			return NULL;
		}

		return $this->data[$key];
	}


	/* This function sets the specified key to the specified value in this
	 * storage object.
	 *
	 * Parameters:
	 *  $key    The key we should set.
	 *  $value  The value we should set the key to.
	 */
	public function set($key, $value) {
		$this->data[$key] = $value;

		/* Set savedData to NULL. This will save time when
		 * we are going to decide whether we need to update this
		 * object on the memcache servers.
		 */
		$this->savedData = NULL;
	}


	/* This function determines whether we need to update the data which
	 * is stored on the memcache servers.
	 *
	 * If we are unable to detect a change, then we will serialize the
	 * class and compare this to the data we have cached. We do this to
	 * determine if any of the references have changed.
	 *
	 * Returns:
	 *  TRUE if this object needs an update, FALSE if not.
	 */
	private function needUpdate() {
		/* If $savedData is NULL, then we don't have any data stored
		 * on any servers. Therefore, we need to update the data.
		 */
		if($this->savedData === NULL) {
			return TRUE;
		}

		/* Calculate the serialized value of this object. */
		$serialized = serialize($this);

		/* If the serialized value of this object matches the previous
		 * serialized value, then we don't need to update the data on
		 * the servers.
		 */
		if($serialized === $this->savedData) {
			return FALSE;
		}

		/* We need to store the updated value to the servers. */
		return TRUE;
	}


	/* This function stores this storage object to the memcache servers.
	 */
	public function save() {
		/* First, chech whether we need to store new data. */
		if(!$this->needUpdate()) {
			/* This object is unchanged - we don't need to
			 * commit.
			 */
			return;
		}

		/* Update the last-commit timestamp. */
		$this->lastCommitTime = time();

		/* Calculate the value we should store on the servers. */
		$this->savedData = serialize($this);

		/* Store this object to all groups of memcache servers. */
		foreach(self::getMemcacheServers() as $server) {
			$server->set($this->id, $this->savedData, 0,
			             self::getExpireTime());
		}
	}


	/* This function adds a server from the 'memcache_store.servers'
	 * configuration option to a Memcache object.
	 *
	 * Parameters:
	 *  $memcache The Memcache object we should add this server to.
	 *  $server   The server we should parse. This is an array with
	 *            the following keys:
	 *            - hostname
	 *              Hostname or ip address to the memcache server.
	 *            - port (optional)
	 *              port number the memcache server is running on. This
	 *              defaults to memcache.default_port if no value is given.
	 *              The default value of memcache.default_port is 11211.
	 *            - weight (optional)
	 *              The weight of this server in the load balancing
	 *              cluster.
	 *            - timeout (optional)
	 *              The timeout for contacting this server, in seconds.
	 *              The default value is 3 seconds.
	 */
	private static function addMemcacheServer($memcache, $server) {

		/* The hostname option is required. */
		if(!array_key_exists('hostname', $server)) {
			$e = 'hostname setting missing from server in the' .
			     ' \'memcache_store.servers\' configuration' .
			     ' option.';
			error_log($e);
			die($e);
		}

		$hostname = $server['hostname'];

		/* The hostname must be a valid string. */
		if(!is_string($hostname)) {
			$e = 'Invalid hostname for server in the' .
		             ' \'memcache_store.servers\' configuration' .
			     ' option. The hostname is supposed to be a' .
			     ' string.';
			error_log($e);
			die($e);
		}

		/* Check if the user has specified a port number. */
		if(array_key_exists('port', $server)) {
			/* Get the port number from the array, and validate
			 * it.
			 */
			$port = (int)$server['port'];
			if(($port < 0) || ($port > 65535)) {
				$e = 'Invalid port for server in the' .
				     ' \'memcache_store.servers\'' .
				     ' configuration option. The port number' .
				     ' is supposed to be an integer between' .
				     ' 0 and 65535.';
				error_log($e);
				die($e);
			}
		} else {
			/* Use the default port number from the ini-file. */
			$port = (int)ini_get('memcache.default_port');
			if($port <= 0 || $port > 65535) {
				/* Invalid port number from the ini-file.
				 * fall back to the default.
				 */
				$port = 11211;
			}
		}

		/* Check if the user has specified a weight for this server. */
		if(array_key_exists('weight', $server)) {
			/* Get the weight and validate it. */
			$weight = (int)$server['weight'];
			if($weight <= 0) {
				$e = 'Invalid weight for server in the' .
				     ' \'memcache_store.servers\'' .
				     ' configuration option. The weight is' .
				     ' supposed to be a positive integer.';
				error_log($e);
				die($e);
			}
		} else {
			/* Use a default weight of 1.  */
			$weight = 1;
		}

		/* Check if the user has specified a timeout for this
		 * server.
		 */
		if(array_key_exists('timeout', $server)) {
			/* Get the timeout and validate it. */
			$timeout = (int)$server['timeout'];
			if($timeout <= 0) {
				$e = 'Invalid timeout for server in the' .
				     ' \'memcache_store.servers\'' .
				     ' configuration option. The timeout is' .
				     ' supposed to be a positive integer.';
				error_log($e);
				die($e);
			}
		} else {
			/* Use a default timeout of 3 seconds. */
			$timeout = 3;
		}

		/* Add this server to the Memcache object. */
		$memcache->addServer($hostname, $port, TRUE, $weight, $timeout);
	}


	/* This function takes in a list of servers belonging to a group and
	 * creates a Memcache object from the servers in the group.
	 *
	 * Parameters:
	 *  $group   Array of servers. Each server is represented by one array
	 *           with the hostname (and optionally port number, timeout,
	 *           ...). See the addMemcacheServer function for more
	 *           information.
	 *
	 * Returns:
	 *  A Memcache object of the servers in the group.
	 */
	private static function loadMemcacheServerGroup($group) {
		/* Create the Memcache object. */
		$memcache = new Memcache();
		if($memcache == NULL) {
			$e = 'Unable to create an instance of a Memcache' .
			     ' object. Is the memcache extension' .
			     ' installed?';
			error_log($e);
			die($e);
		}

		/* Iterate over all the servers in the group and add them to
		 * the Memcache object.
		 */
		foreach($group as $index => $server) {
			/* Make sure that we don't have an index. An index
			 * would be a sign of invalid configuration.
			 */
			if(!is_int($index)) {
				$e = 'Invalid index on element in the' .
				     ' \'memcache_store.servers\'' .
				     ' configuration option. Perhaps you' .
				     ' have forgotten to add an array(...)' .
				     ' around one of the server groups? The' .
				     ' invalid index was: ' . $index;
				error_log($e);
				die($e);
			}

			/* Make sure that the server object is an array. Each
			 * server is an array with name-value pairs.
			 */
			if(!is_array($server)) {
				$e = 'Invalid value for the server with' .
				     ' index ' . $index . '. Remeber that' .
				     ' the \'memcache_store.servers\'' .
				     ' configuration option contains an' .
				     ' array of arrays of arrays.';
				error_log($e);
				die($e);
			}

			self::addMemcacheServer($memcache, $server);
		}

		return $memcache;
	}


	/* This function gets a list of all configured memcache servers. This
	 * list is initialized based on the content of
	 * 'memcache_store.servers' in the configuration.
	 *
	 * Returns:
	 *  Array with Memcache objects.
	 */
	private static function getMemcacheServers() {

		/* Check if we have loaded the servers already. */
		if(self::$serverGroups != NULL) {
			return self::$serverGroups;
		}

		/* Initialize the servers-array. */
		self::$serverGroups = array();

		/* Load the configuration. */
		$config = SimpleSAML_Configuration::getInstance();
		assert($config instanceof SimpleSAML_Configuration);


		$groups = $config->getValue('memcache_store.servers');

		/* Validate the 'memcache_store.servers' configuration
		 * option.
		 */
		if(is_null($groups)) {
			$e = 'Unable to get value of the' .
			     ' \'memcache_store.servers\' configuration' .
			     ' option.';
			error_log($e);
			die($e);
		}
		if(!is_array($groups)) {
			$e = 'The value of the \'memcache_store.servers\'' .
			     ' configuration option isn\'t an array.';
			error_log($e);
			die($e);
		}

		/* Iterate over all the groups in the
		 * 'memcache_store.servers' configuration option.
		 */
		foreach($groups as $index => $group) {
			/* Make sure that the group doesn't have an index.
			 * An index would be a sign of invalid configuration.
			 */
			if(!is_int($index)) {
				$e = 'Invalid index on element in the' .
				     ' \'memcache_store.servers\'' .
				     ' configuration option. Perhaps you' .
				     ' have forgotten to add an array(...)' .
				     ' around one of the server groups? The' .
				     ' invalid index was: ' . $index;
				error_log($e);
				die($e);
			}

			/* Make sure that the group is an array. Each group
			 * is an array of servers. Each server is an array of
			 * name => value pairs for that server.
			 */
			if(!is_array($group)) {
				$e = 'Invalid value for the server with' .
				     ' index ' . $index . '. Remeber that' .
				     ' the \'memcache_store.servers\'' .
				     ' configuration option contains an' .
				     ' array of arrays of arrays.';
				error_log($e);
				die($e);
			}

			/* Parse and add this group to the server group list.
			 */
			self::$serverGroups[] =
				self::loadMemcacheServerGroup($group);
		}

		return self::$serverGroups;		
	}


	/* This function determines whether the argument is a valid id.
	 * A valid id is a string containing lowercase alphanumeric
	 * characters.
	 *
	 * Parameters:
	 *  $id     The id we should validate.
	 *
	 * Returns:
	 *  TRUE if the id is valid, FALSE otherwise.
	 */
	private static function isValidID($id) {
		if(!is_string($id)) {
			return FALSE;
		}

		if(strlen($id) < 1) {
			return FALSE;
		}

		if(preg_match('/[^0-9a-z]/', $id)) {
			return FALSE;
		}

		return TRUE;
	}


	/* This is a helper-function which returns the expire value of data
	 * we should store to the memcache servers.
	 *
	 * The value is set depending on the configuration. If no value is
	 * set in the configuration, then we will use a default value of 0.
	 * 0 means that the item will never expire.
	 *
	 * Returns:
	 *  The value which should be passed in the set(...) calls to the
	 *  memcache objects.
	 */
	private static function getExpireTime()
	{
		/* Get the configuration instance. */
		$config = SimpleSAML_Configuration::getInstance();
		assert($config instanceof SimpleSAML_Configuration);

		/* Get the expire-value from the configuration. */
		$expire = $config->getValue('memcache_store.expires');

		/* If 'memcache_store.expires' isn't defined in the
		 * configuration, then we will use 0 as the expire parameter.
		 */
		if($expire === NULL) {
			return 0;
		}

		/* The 'memcache_store.expires' option must be an integer. */
		if(!is_integer($expire)) {
			$e = 'The value of \'memcache_store.expires\' in the' .
			     ' configuration must be a valid integer.';
			error_log($e);
			die($e);
		}

		/* It must be a positive integer. */
		if($expire < 0) {
			$e = 'The value of \'memcache_store.expires\' in the' .
			     ' configuration can\'t be a negative integer.';
			error_log($e);
			die($e);
		}

		/* If the configuration option is 0, then we should
		 * return 0. This allows the user to specify that the data
		 * shouldn't expire.
		 */
		if($expire == 0) {
			return 0;
		}

		/* The expire option is given as the number of seconds into the
		 * future an item should expire. We convert this to an actual
		 * timestamp.
		 */
		$expireTime = time() + $expire;

		return $expireTime;
	}
}
?>
