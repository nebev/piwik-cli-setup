<?php
$config = json_decode(file_get_contents(dirname(__FILE__) . "/install.json"), true);
if (!is_array($config)) {
	die("[ERROR] Cannot parse JSON file at [". dirname(__FILE__) . "/install.json" ."]\n");
}

define('PIWIK_DOCUMENT_ROOT', $config['document_root']);
if (file_exists(PIWIK_DOCUMENT_ROOT . '/bootstrap.php')) {
    require_once PIWIK_DOCUMENT_ROOT . '/bootstrap.php';
}

if (!defined('PIWIK_INCLUDE_PATH')) {
    define('PIWIK_INCLUDE_PATH', PIWIK_DOCUMENT_ROOT);
}

require_once PIWIK_INCLUDE_PATH . '/core/bootstrap.php';

if (!Piwik\Common::isPhpCliMode()) {
    exit;
}

if (!defined('PIWIK_ENABLE_ERROR_HANDLER') || PIWIK_ENABLE_ERROR_HANDLER) {
    Piwik\ErrorHandler::registerErrorHandler();
    Piwik\ExceptionHandler::setUp();
}


use Piwik\ErrorHandler;
use Piwik\ExceptionHandler;
use Piwik\FrontController;
use Piwik\Access;
use Piwik\Common;
use Piwik\Plugins\UsersManager\API as APIUsersManager;
use Piwik\Plugins\SitesManager\API as APISitesManager;
use Piwik\Config;
use Piwik\Filesystem;
use Piwik\DbHelper;
use Piwik\Updater;
use Piwik\Plugin\Manager;
use Piwik\Container\StaticContainer;
use Piwik\Option;


if (!defined('PIWIK_ENABLE_ERROR_HANDLER') || PIWIK_ENABLE_ERROR_HANDLER) {
    ErrorHandler::registerErrorHandler();
    ExceptionHandler::setUp();
}

FrontController::setUpSafeMode();
$environment = new \Piwik\Application\Environment(null);
try {
	$environment->init();
} catch(\Exception $e) {}


$installer = new PiwikCliInstall($config);
$installer->install();

class PiwikCliInstall {
	protected $config;

	public function __construct($config) {
		$this->config = $config;
	}

	protected function log($text) {
		echo date("Y-m-d H:i:s") . " - $text\n";
	}

	public function install() {
		$this->log('Running Piwik Initial Install Script');
		$this->prepare();
		$this->initDBConnection();
		$this->tableCreation();
		$this->createUser();
		$this->addWebsite();
		$this->finish();
		$this->setGeo();
		$this->setPrivacy();
		$this->setupPlugins();
		$this->setConfigExtras();
		$this->setOptionExtras();
		$this->deactivatePlugins();
		$this->setBranding();
		$this->setPluginSettings();
	}

	protected function prepare() {
		$this->log('Preparing Cache and Diagnostics');
		Filesystem::deleteAllCacheOnUpdate();
        $diagnosticService = StaticContainer::get('Piwik\Plugins\Diagnostics\DiagnosticService');
		$diagnosticService->runDiagnostics();
	}

	/**
	 * Initialises and saves the database connection to Piwik
	 * [database] should be in config. Should be an array with keys [host], [adapter], [username], [password], [dbname] and [tables_prefix]
	 */
	protected function initDBConnection() {
		$this->log('Initialising Database Connections');
		$config = Config::getInstance();
		if (array_key_exists('session_save_handler', $this->config)) {
			$config->General['session_save_handler'] = $this->config['session_save_handler'];
		}

		$config->General['salt'] = Common::generateUniqId();
		$config->General['installation_in_progress'] = 1;
		$config->database = $this->config['database'];
		
		// Connect to the database with retry timeout so any provisioning scripts & DB setup scripts are given a chance
		$retries = array(10, 20, 30, 40, 50, 60, 70, 80);
		foreach( $retries as $retry_timeout_index => $retry_timeout ) {
			try {
				DbHelper::isDatabaseConnectionUTF8();
				break;
			} catch(\Exception $e) {
				$this->log("Database connection failed. Retrying in $retry_timeout seconds.");
				$this->log($e->getMessage());
				sleep($retry_timeout);
			}
		}

		if (!DbHelper::isDatabaseConnectionUTF8()) {	// Exception will be thrown if cannot connect
			$config->database['charset'] = 'utf8';
		}
		
		$config->forceSave();
	}

	/**
	 * Performs the initial table creation for Piwik
	 */
	protected function tableCreation() {
		$this->log('Ensuring Tables are Created');
		$tablesInstalled = DbHelper::getTablesInstalled();
		if (count($tablesInstalled) === 0) {
			DbHelper::createTables();
			DbHelper::createAnonymousUser();
			$this->updateComponents();
		}
	}

	/**
	 * Creates the default superuser
	 * [login], [password] and [email] should all be set in the config
	 */
	protected function createUser() {
		$this->log('Ensuring Users get Created');
		$config_arr = $this->config;
		Access::doAsSuperUser(function () use ($config_arr) {
			$api = APIUsersManager::getInstance();
			if (!$api->userExists($config_arr['login']) and !$api->userEmailExists($config_arr['email'])) {
				$api->addUser($config_arr['login'], $config_arr['password'], $config_arr['email']);
				$api->setSuperUserAccess($config_arr['login'], true);
			}
		});
	}

	/**
	 * Sets up the initial website (site ID 1) to track
	 * [site_name], [site_url] and [base_domain] should all be set in config
	 */
	protected function addWebsite() {
		$this->log('Adding Primary Website');
		$config_arr = $this->config;

		Access::doAsSuperUser(function () use ($config_arr) {
			$api = APISitesManager::getInstance();

			$exists = false;
			foreach ($api->getAllSites() as $site) {
				if ($site['name'] == $config_arr['site_name'])
				{
					$this->log("Primary website found existing, not adding it again");
					$exists = true;
					break;
				}
			}
			if (!$exists) {
				$api->addSite($config_arr['site_name'], $config_arr['site_url'], 0);
			}
		});

		$trustedHosts = array(
			$config_arr['base_domain']
		);
		if (($host = $this->extractHost(urldecode($config_arr['site_url']))) !== false) {
			$trustedHosts[] = $host;
		}
		$general = Config::getInstance()->General;
		$general['trusted_hosts'] = $trustedHosts;
		Config::getInstance()->General = $general;
		Config::getInstance()->forceSave();
	}

	/**
	 * Finishes the fake installation. Removes 'installation_in_progress' in the config file and updates core.
	 */
	protected function finish() {
		$this->log('Finalising primary install procedure');
		Manager::getInstance()->loadPluginTranslations();
		Manager::getInstance()->loadActivatedPlugins();
		Manager::getInstance()->installLoadedPlugins();

		$config = Config::getInstance();
		unset($config->General['installation_in_progress']);
		unset($config->database['adapter']);
		$config->forceSave();

		// Put in Activated plugins
		Manager::getInstance()->loadActivatedPlugins();

		exec("php " . PIWIK_DOCUMENT_ROOT . "/console core:update --yes");	// Ugh. NFI why I can't do this...
	}

	/**
	 * Sets the Geolocation
	 * [geo_provider] is mandatory. Only correct value implemented is [geoip_pecl]
	 */
	protected function setGeo() {
		$this->log('Setting Geolocation');
		Option::set('usercountry.location_provider', $this->config['geo_provider']);
		if ( $this->config['geo_provider'] === 'geoip_pecl' ) {
			Option::set('geoip.isp_db_url', '');
			Option::set('geoip.loc_db_url', 'http://geolite.maxmind.com/download/geoip/database/GeoLiteCity.dat.gz');
			Option::set('geoip.org_db_url', '');
			Option::set('geoip.updater_period', 'month');
		}
	}

	/**
	 * Sets privacy settings
	 * [privacy] can exist in config. Sub settings are [anonymize_ip] and [honor_do_not_track]
	 */
	protected function setPrivacy() {
		$this->log('Setting up Privacy Rules');
		if ( array_key_exists('privacy', $this->config) && is_array($this->config['privacy']) ) {
			if ( array_key_exists('anonymize_ip', $this->config['privacy']) ) {
				if ($this->config['privacy']['anonymize_ip'] === true) {
					Option::set('PrivacyManager.ipAnonymizerEnabled', 1);
					Option::set('PrivacyManager.ipAddressMaskLength', 2);
				} else {
					Option::set('PrivacyManager.ipAnonymizerEnabled', 0);
				}
			}

			if ( array_key_exists('honor_do_not_track', $this->config['privacy']) ) {
				if ($this->config['privacy']['honor_do_not_track'] === true) {
					Option::set('PrivacyManager.doNotTrackEnabled', 1);
				} else {
					Option::set('PrivacyManager.doNotTrackEnabled', 0);
				}
			}

		}
	}

	/**
	 * Sets extra configuration that is to go directly into the config.ini.php
	 * [config] can exist. Each Key represents a section in the config file ( value should also be array)
	 *   Each sub-array item in key->value represents the config key and associated setting
	 */
	protected function setConfigExtras() {
		$this->log('Setting up extra configuration');
		if (array_key_exists('extras', $this->config) && is_array($this->config['extras'])) {

			// 2 level array - section then setting
			$config = Config::getInstance();
			foreach( $this->config['extras'] as $config_section => $config_settings ) {
				foreach($config_settings as $setting_key => $setting_value) {
					$config->{$config_section}[$setting_key] = $setting_value;
				}
			}
			$config->forceSave();
		}
	}

	/**
	 * Sets the branding
	 * [branding] can exist in config, and can have keys [header_url] representing image for header
	 */
	protected function setBranding() {
		$this->log('Setting Branding');
		if (array_key_exists('branding', $this->config) && is_array($this->config['branding'])) {
			if (array_key_exists('header_url', $this->config['branding'])) {
				$image_contents = $this->getURL( $this->config['branding']['header_url'] );
				file_put_contents(PIWIK_DOCUMENT_ROOT . '/misc/user/logo-header.png', $image_contents);
				Option::set('branding_use_custom_logo', 1);
			}
		}
	}

	/**
	 * Setup plugins
	 * [plugins] in config should be text based and already extracted in the plugins piwik directory
	 */
	protected function setupPlugins() {
		$this->log('Setting up Extra Plugins');
		echo exec("php " . PIWIK_DOCUMENT_ROOT . "/console core:clear-caches") . "\n";

		if (array_key_exists('plugins', $this->config) && is_array($this->config['plugins'])) {
			$config = Config::getInstance();
			foreach($config->PluginsInstalled as $pi_arr) {
				foreach($pi_arr as $pi) {
					$config->Plugins[] = $pi;
				}
			}

			// Now go and activate them
			foreach( $this->config['plugins'] as $plugin_txt ) {
				echo exec("php " . PIWIK_DOCUMENT_ROOT . "/console plugin:activate " . $plugin_txt) . "\n";
				$config->PluginsInstalled[] = $plugin_txt;
				$config->Plugins[] = $plugin_txt;
			}

			$config->forceSave();

			// And Update Core
			exec("php " . PIWIK_DOCUMENT_ROOT . "/console core:update --yes");
		}
	}

	/**
	 * Deactivates any default plugins specified
	 * [deactivate_plugins] in config should be set
	 */
	protected function deactivatePlugins() {
		$this->log('Deactivating unwanted plugins');
		if (array_key_exists('deactivate_plugins', $this->config) && is_array($this->config['deactivate_plugins'])) {
			foreach ($this->config['deactivate_plugins'] as $plugin_to_deactivate) {
				echo exec("php " . PIWIK_DOCUMENT_ROOT . "/console plugin:deactivate " . $plugin_to_deactivate) . "\n";
			}
		}
	}

	/**
	 * This function sets optional extras
	 * [options] should be set and be key-value in order to use
	 */
	protected function setOptionExtras() {
		$this->log('Setting custom options');
		if (array_key_exists('options', $this->config) && is_array($this->config['options'])) {
			foreach( $this->config['options'] as $option_key => $option_val ) {
				Option::set($option_key, $option_val);
			}
		}
	}

	/**
	 * Gets a URL from the web and returns the contents as a string
	 */
	protected function getURL($url) {
		$this->log('Fetching URL: ' . $url);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_VERBOSE, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_AUTOREFERER, false);
		curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		$result = curl_exec($ch);
		curl_close($ch);
		return $result;
	}


	/**
	 * Extract host from URL
	 *
	 * @param string $url URL
	 *
	 * @return string|false
	 */
	protected function extractHost($url) {
		$urlParts = parse_url($url);
		if (isset($urlParts['host']) && strlen($host = $urlParts['host'])) {
			return $host;
		}
		return false;
	}


	protected function updateComponents() {
		$this->log('Updating Components');
		Access::getInstance();

		return Access::doAsSuperUser(function () {
		$updater = new Updater();
		$componentsWithUpdateFile = $updater->getComponentUpdates();

		if (empty($componentsWithUpdateFile)) {
			return false;
		}
		$result = $updater->updateComponents($componentsWithUpdateFile);
			return $result;
		});
	}

	/**
	 * Updates Piwik V3 Plugin Settings.
	 * Looks for a [plugin_settings] key in config. Config then in key-value form nested under plugin_name
	 */
	protected function setPluginSettings() {
		$this->log('Updating Plugin Settings');
		if (array_key_exists('plugin_settings', $this->config)) {
			if (class_exists("\\Piwik\\Plugins\\CorePluginsAdmin\\SettingsMetadata")) {
				foreach($this->config['plugin_settings'] as $plugin_name => $plugin_settings) {
					$this->log("Adding plugin settings for $plugin_name");
					$settings = new \Piwik\Settings\Storage\Backend\PluginSettingsTable($plugin_name, "");
					$settings->save( $plugin_settings );
				}
			} else {
				$this->log("Cannot update plugin settings - Are you running Piwik 3?");
			}
		}
	}

}
