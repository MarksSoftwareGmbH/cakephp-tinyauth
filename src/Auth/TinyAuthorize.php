<?php
namespace TinyAuth\Auth;

use Cake\Auth\BaseAuthorize;
use Cake\Cache\Cache;
use Cake\Controller\ComponentRegistry;
use Cake\Core\Configure;
use Cake\Core\Exception\Exception;
use Cake\Database\Schema\Collection;
use Cake\Datasource\ConnectionManager;
use Cake\Network\Request;
use Cake\ORM\TableRegistry;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;

if (!defined('CLASS_USER')) {
	define('CLASS_USER', 'Users'); // override if you have it in a plugin: PluginName.Users etc
}
if (!defined('AUTH_CACHE')) {
	define('AUTH_CACHE', '_cake_core_'); // use the most persistent cache by default
}
if (!defined('ACL_FILE')) {
	define('ACL_FILE', 'acl.ini'); // stored in /app/Config/
}

/**
 * Probably the most simple and fastest Acl out there.
 * Only one config file `acl.ini` necessary
 * Doesn't even need a Role Model / roles table
 * Uses most persistent _cake_core_ cache by default
 * @link http://www.dereuromark.de/2011/12/18/tinyauth-the-fastest-and-easiest-authorization-for-cake2
 *
 * Usage:
 * Include it in your beforeFilter() method of the AppController
 * $this->Auth->authorize = array('Tools.Tiny');
 *
 * Or with admin prefix protection only
 * $this->Auth->authorize = array('Tools.Tiny' => array('allowUser' => true));
 *
 * @author Mark Scherer
 * @license MIT
 */
class TinyAuthorize extends BaseAuthorize {

	protected $_acl = null;

	protected $_defaultConfig = [
		'roleColumn' => 'role_id', // name of column in user table holding role id (used for single role/BT only)
		'rolesTable' => 'Roles', // name of (database) table class OR Configure key holding all available roles
		'useDatabaseRoles' => false, // true to use a database roles table instead of a Configure roles array
		'multiRole' => false, // true to enables multirole/HABTM authorization (requires valid rolesTable and join table)

		'adminRole' => null, // id of the admin role (used to give access to all /admin prefixed resources when allowAdmin is enabled)
		'superAdminRole' => null, // id of super admin role granted access to ALL resources
		'adminPrefix' => 'admin', // name of the admin prefix route (only used when allowAdmin is enabled)
		'allowAdmin' => false, // boolean, true to allow admin role access to all 'adminPrefix' prefixed urls
		'allowUser' => false, // enable to allow ALL roles access to all actions except prefixed with 'adminPrefix'

		'cache' => AUTH_CACHE,
		'cacheKey' => 'tiny_auth_acl',
		'autoClearCache' => false, // usually done by Cache automatically in debug mode,
	];

	/**
	 * TinyAuthorize::__construct()
	 *
	 * @param ComponentRegistry $registry
	 * @param array $config
	 * @throws Cake\Core\Exception\Exception
	 */
	public function __construct(ComponentRegistry $registry, array $config = []) {
		$config += $this->_defaultConfig;
		parent::__construct($registry, $config);

		if (Cache::config($config['cache']) === false) {
			throw new Exception(sprintf('TinyAuth could not find `%s` cache - expects at least a `default` cache', $config['cache']));
		}
	}

	/**
	 * Authorize a user using the AclComponent.
	 * allows single or multi role based authorization
	 *
	 * Examples:
	 * - User HABTM Roles (Role array in User array)
	 * - User belongsTo Roles (role_id in User array)
	 *
	 * @param array $user The user to authorize
	 * @param Cake\Network\Request $request The request needing authorization.
	 * @return bool Success
	 */
	public function authorize($user, Request $request) {
		return $this->validate($this->_getUserRoles($user), $request);
	}

	/**
	 * Validate the url to the role(s)
	 * allows single or multi role based authorization
	 *
	 * @param array $userRoles
	 * @param string $plugin
	 * @param string $controller
	 * @param string $action
	 * @return bool Success
	 */
	public function validate($userRoles, Request $request) {
		// Give any logged in user access to ALL actions when `allowUser` is
		// enabled except when the `adminPrefix` is being used.
		if (!empty($this->_config['allowUser'])) {
			if (empty($request->params['prefix'])) {
				return true;
			}
			if ($request->params['prefix'] != $this->_config['adminPrefix']) {
				return true;
			}
		}

		// allow access to all /admin prefixed actions for users belonging to
		// the specified adminRole id.
		if (!empty($this->_config['allowAdmin']) && !empty($this->_config['adminRole'])) {
			if (!empty($request->params['prefix']) && $request->params['prefix'] === $this->_config['adminPrefix']) {
				if (in_array($this->_config['adminRole'], $userRoles)) {
					return true;
				}
			}
		}

		// allow logged in super admins access to all resources
		if (!empty($this->_config['superAdminRole'])) {
			foreach ($userRoles as $userRole) {
				if ($userRole === $this->_config['superAdminRole']) {
					return true;
				}
			}
		}

		// generate ACL if not already set
		if ($this->_acl === null) {
			$this->_acl = $this->_getAcl();
		}

		// allow access if user has a role with wildcard access to the resource
		$iniKey = $this->_constructIniKey($request);
		if (isset($this->_acl[$iniKey]['actions']['*'])) {
			$matchArray = $this->_acl[$iniKey]['actions']['*'];
			foreach ($userRoles as $userRole) {
				if (in_array((string)$userRole, $matchArray)) {
					return true;
				}
			}
		}

		// allow access if user has been granted access to the specific resource
		if (isset($this->_acl[$iniKey]['actions'])) {
			if(array_key_exists($request->action, $this->_acl[$iniKey]['actions']) && !empty($this->_acl[$iniKey]['actions'][$request->action])) {
				$matchArray = $this->_acl[$iniKey]['actions'][$request->action];
				foreach ($userRoles as $userRole) {
					if (in_array((string)$userRole, $matchArray)) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * @return Cake\ORM\Table The User table
	 * @throws Cake\Core\Exception\Exception
	 */
	public function getUserTable() {
		$table = TableRegistry::get(CLASS_USER);
		if (!$table->associations()->has($this->_config['rolesTable'])) {
			throw new Exception('Missing TinyAuthorize relationship between Users and ' .
				$this->_config['rolesTable'] . '.');
		}
		return $table;
	}

	/**
	 * Parse ini file and returns the allowed roles per action
	 * - uses cache for maximum performance
	 * improved speed by several actions before caching:
	 * - resolves role slugs to their primary key / identifier
	 * - resolves wildcards to their verbose translation
	 *
	 * @param string $path
	 * @return array Roles
	 */
	protected function _getAcl($path = null) {
		if ($path === null) {
			$path = ROOT . DS . 'config' . DS;
		}

		if ($this->_config['autoClearCache'] && Configure::read('debug') > 0) {
			Cache::delete($this->_config['cacheKey'], $this->_config['cache']);
		}
		if (($roles = Cache::read($this->_config['cacheKey'], $this->_config['cache'])) !== false) {
			return $roles;
		}

		$iniArray = $this->_parseAclIni($path . ACL_FILE);
		$availableRoles = $this->_getAvailableRoles();

		$res = [];
		foreach ($iniArray as $key => $array) {
			$res[$key] = $this->_deconstructIniKey($key);

			foreach ($array as $actions => $roles) {
				// get all roles used in the current ini section
				$roles = explode(',', $roles);
				$actions = explode(',', $actions);

				foreach ($roles as $roleId => $role) {
					if (!($role = trim($role))) {
						continue;
					}
					// prevent undefined roles appearing in the iniMap
					if (!array_key_exists($role, $availableRoles) && $role !== '*') {
						unset($roles[$roleId]);
						continue;
					}
					if ($role === '*') {
						unset($roles[$roleId]);
						$roles = array_merge($roles, array_keys($availableRoles));
					}
				}

				// process actions
				foreach ($actions as $action) {
					if (!($action = trim($action))) {
						continue;
					}
					foreach ($roles as $role) {
						if (!($role = trim($role)) || $role === '*') {
							continue;
						}
						// lookup role id by name in roles array
						$newRole = $availableRoles[strtolower($role)];
						$res[$key]['actions'][$action][] = $newRole;
					}
				}
			}
		}
		Cache::write($this->_config['cacheKey'], $res, $this->_config['cache']);
		return $res;
	}

	/**
	 * Returns a list of all roles belonging to the authenticated user
	 *
	 * @todo discuss trigger_error + caching (?)
	 *
	 * @param array $user The user to get the roles for
	 * @return array List with all role ids belonging to the user
	 * @throws Cake\Core\Exception\Exception
	 */
	protected function _getUserRoles($user) {
		if (!$this->_config['multiRole']) {
			if (isset($user[$this->_config['roleColumn']])) {
				return [$user[$this->_config['roleColumn']]];
			}
			throw new Exception (sprintf('Missing TinyAuthorize role id (%s) in user session', $this->_config['roleColumn']));
		}

		// multi-role: fetch user data and associated roles from database
		$usersTable = $this->getUserTable();
		$userData = $usersTable->get($user['id'], [
			'contain' => [$this->_config['rolesTable']]
		]);
		return Hash::extract($userData->toArray(), Inflector::tableize($this->_config['rolesTable']) . '.{n}.id');
	}

	/**
	 * Returns the acl.ini file as an array.
	 *
	 * @return array List with all available roles
	 * @throws Cake\Core\Exception\Exception
	 */
	protected function _parseAclIni($ini) {
		if (!file_exists($ini)) {
			throw new Exception(sprintf('Missing TinyAuthorize ACL file (%s)', $ini));
		}

		if (function_exists('parse_ini_file')) {
			$iniArray = parse_ini_file($ini, true);
		} else {
			$iniArray = parse_ini_string(file_get_contents($ini), true);
		}
		if (!count($iniArray)) {
			throw new Exception('Invalid TinyAuthorize ACL file');
		}
		return $iniArray;
	}

	/**
	 * Returns a list of all available roles from either Configure or the database.
	 *
	 * @return array List with all available roles
	 * @throws Cake\Core\Exception\Exception
	 */
	protected function _getAvailableRoles() {
		// get roles from Configure
		if (!$this->_config['useDatabaseRoles']) {
			$roles = Configure::read($this->_config['rolesTable']);
			if (!$roles) {
				throw new Exception('Invalid TinyAuthorize Role Setup (no Configure roles found)');
			}
			return $roles;
		}

		// get roles from database
		$userTable = $this->getUserTable();
		$roles = $userTable->{$this->_config['rolesTable']}->find('all')->formatResults(function ($results) {
			return $results->combine('alias', 'id');
		})->toArray();
		if (!count($roles)) {
			throw new Exception('Invalid TinyAuthorize Role Setup (no database roles found)');
		}
		return $roles;
	}

	/**
	 * Deconstructs an ACL ini section key into a named array with ACL parts
	 *
	 * @param string INI section key as found in acl.ini
	 * @return array Hash with named keys for controller, plugin and prefix
	 */
	protected function _deconstructIniKey($key) {
		$res = [
			'plugin' => null,
			'prefix' => null
		];

		if (strpos($key, '.') !== false) {
			list($res['plugin'], $key) = explode('.', $key);
		}
		if (strpos($key, '/') !== false) {
			list($res['prefix'], $key) = explode('/', $key);
		}
		$res['controller'] = $key;
		return $res;
	}

	/**
	 * Constructs an ACL ini section key from a given CakeRequest
	 *
	 * @param Cake\Network\Request $request The request needing authorization.
	 * @return array Hash with named keys for controller, plugin and prefix
	 */
	protected function _constructIniKey(Request $request) {
		$res = $request->params['controller'];
		if (!empty($request->params['prefix'])) {
			$res = $request->params['prefix'] . "/$res";
		}
		if (!empty($request->params['plugin'])) {
			$res = $request->params['plugin'] . ".$res";
		}
		return $res;
	}

}
