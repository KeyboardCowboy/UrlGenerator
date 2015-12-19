<?php
/**
 * @file
 * Generate a file of random URLs from one or more data sources.
 */

// Load composer libraries.
require_once './vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

class UrlGenerator implements UrlGeneratorInterface {
  // Global properties.
  private $sources = [];
  private $hosts = [];
  private $data;

  // Profiles.
  private $profiles = [];
  private $profile;
  private $count;

  private $defaults = [];

  // The generated URLs.
  private $urls = [];

  // Default URL file.  Can be overridden in config.yml.
  private $urlFile = './urls.txt';

  /**
   * UrlGenerator constructor.
   *
   * @param array $args
   *   The name of the profile to load from config.yml.
   */
  public function __construct(array $args = []) {
    try {
      // Load the config.
      $this->config();

      // Process the arguments.
      $this->processArgs($args);

      // Validate the desired profile.
      if (empty($this->profiles)) {
        throw new Exception("No profiles were loaded.");
      }
      elseif (!isset($this->profiles[$this->profile])) {
        throw new Exception("Profile '{$this->profile}' not found.");
      }
      elseif (!$this->getProfile('host')) {
        throw new Exception("Profile '{$this->profile}' is missing the host param.");
      }
      elseif (!$this->getProfile('basePath')) {
        throw new Exception("Profile '{$this->profile}' is missing the basePath param.");
      }

      // Validate the count param.
      if (empty($this->count)) {
        throw new Exception("No URL count specified.");
      }

      // Load the data sources.
      $this->loadData();
    }
    catch (Exception $e) {
      $this->handleException($e);
    }
  }

  /**
   * Load the config.yml file.
   */
  private function config() {
    $file = "./config.yml";

    if (file_exists($file)) {
      $data = (object) YAML::parse(file_get_contents($file));

      // Overwrite the URL file location.
      if (isset($data->urlFile)) {
        $this->urlFile = $data->urlFile;
      }

      // Store the data sources.
      if (isset($data->sources)) {
        $this->sources = $data->sources;
      }

      // Store the hosts.
      if (isset($data->hosts)) {
        $this->hosts = $data->hosts;
      }

      // Store the profiles.
      if (isset($data->profiles)) {
        $this->profiles = $data->profiles;
      }

      // Store the default values.
      if (isset($data->defaults)) {
        $this->defaults = $data->defaults;
      }
    }
    else {
      throw new Exception('Missing config.yml.');
    }
  }

  /**
   * Load the profile and count args.
   *
   * @param array $args
   *   The array of argv from the CLI.
   */
  private function processArgs(array $args) {
    // Set a profile value.
    if (isset($args[1])) {
      $this->profile = $args[1];
    }
    elseif (!empty($this->defaults['profile'])) {
      $this->profile = $this->defaults['profile'];
    }

    // Set the count value.
    if (isset($args[2])) {
      $this->count = (int) $args[2];
    }
    elseif (!empty($this->defaults['count'])) {
      $this->count = (int) $this->defaults['count'];
    }
  }

  /**
   * Load data from the source files into the object.
   */
  private function loadData() {
    foreach ($this->sources as $name => $source) {
      $file = "./{$source}";
      $this->data[$name] = file_exists($file) ? explode(PHP_EOL, file_get_contents($file)) : [];
    }
  }

  /**
   * Get a set of random data from a file.
   *
   * @param string $source
   *   The name of the source to get the data from.
   * @param int $count
   *   The max number of results to get.
   *
   * @return array
   *   An array of data.
   */
  protected function getRandomData($source, $count) {
    $data = [];

    if (!empty($this->data[$source])) {
      // Get the highest possible allowed number.
      $num_to_get = max(1, min(count($this->data[$source]), $count));

      // Pull the random URLs from the data.
      foreach ((array) array_rand($this->data[$source], $num_to_get) as $key) {
        $data[] = $this->data[$source][$key];
      }
    }

    return $data;
  }

  /**
   * Get random data based on the percentage of the total requested.
   *
   * @param string $source
   *   The name of the source to get the data from.
   * @param float $pct
   *   A float between 0 and 1 representing the percentage of the total number
   *   of URLs requested to retrieve.
   *
   * @return array
   *   An array of random URLs from the specified source.
   */
  protected function getDataByPct($source, $pct) {
    $pct_count = max(1, min($this->count, ceil($this->count * $pct)));

    return $this->getRandomData($source, $pct_count);
  }

  /**
   * Add URLs to the array by a percentage of the total requested.
   *
   * @param string $source
   *   The name of the source to get the data from.
   * @param float $pct
   *   A float between 0 and 1 representing the percentage of the total number
   *   of URLs requested to retrieve.
   */
  protected function addUrlsByPct($source, $pct) {
    $pct_count = max(1, min($this->count, ceil($this->count * $pct)));

    foreach ($this->getRandomData($source, $pct_count) as $url) {
      $this->addUrl($url);
    }
  }

  /**
   * Process the data to build a list of URLs.
   */
  public function generate() {
    // Process any source percentage URLs.
    if ($source_pct = $this->getProfile('sourcePct')) {
      foreach ($source_pct as $source => $pct) {
        $this->addUrlsByPct($source, $pct);
      }
    }

    $this->createFile();
  }

  /**
   * Format and add a url to the list.
   *
   * @param $url
   *   The url to be added.
   */
  protected function addUrl($url) {
    $urls = (array) $url;

    if (!empty($url)
      && ($host = $this->getProfile('host'))
      && ($base = $this->getProfile('basePath'))
      && !empty($this->hosts[$host])) {

      foreach ($urls as $url) {
        $this->urls[] = "{$this->hosts[$host]}{$base}{$url}";
      }
    }
  }

  /**
   * Return the profile definition or a value of it.
   *
   * @param string $key
   *   An optional key to fetch a single value of the profile.
   * @param mixed $default
   *   The value to return if the key is not set.
   *
   * @return bool|null
   *   A value of the desired profile, the whole profile or FALSE if not found.
   */
  protected function getProfile($key = '', $default = NULL) {
    $profile = isset($this->profiles[$this->profile]) ? $this->profiles[$this->profile] : FALSE;

    if ($profile && $key) {
      return isset($profile[$key]) ? $profile[$key] : $default;
    }
    else {
      return $profile;
    }
  }

  /**
   * Create the file containing the URLs.
   */
  protected function createFile() {
    // Write the URL file.
    if (file_put_contents($this->urlFile, implode(PHP_EOL, $this->urls))) {
      // Print the list of URLs.
      foreach ($this->urls as $url) {
        print "{$url}" . PHP_EOL;
      }

      print PHP_EOL . "Created '{$this->urlFile}' with " . count($this->urls) . " urls.";
    }
  }

  /**
   * Handle thrown exceptions.
   *
   * @param \Exception $e
   *   The thrown exception.
   */
  protected function handleException(Exception $e) {
    print $e->getMessage();
  }
}

interface UrlGeneratorInterface {
  /**
   * Process the data and populate the url array.
   *
   * Use UrlGenerator::addUrl() to add urls to the array to ensure they are
   * properly formatted.
   *
   * @return mixed
   */
  public function generate();
}

class GeoCat {
  // File locations.

  // select lower(state), top_geo_url from almodule_market_top_city where !isnull(top_geo_url) order by state, city;
  public $geoFile;

  // select field_url_name_value from field_data_field_url_name where bundle = 'category';
  public $catFile;
  public $spsFile;
  public $urlFile = './urls.txt';

  public $urlBase;

  // These must sum to 1.
  public $pctState;
  public $pctCity;
  public $pctGeoCat;
  public $pctSps;

  // Percentage of topcity URLs to use the full geo components.
  public $pctFullGeo;

  public $addBaseUrls = TRUE;

  // Store the geo and cat data.
  public $states = [];
  public $geo = [];
  public $cats = [];
  public $sps = [];

  public function __construct($profile, $server) {
    // Load in the profile.
    $this->loadProfile($profile);

    switch ($server) {
      case 'dev':
        $this->urlBase = 'http://angiesmr2dev.prod.acquia-sites.com/companylist';
        break;

      case 'dev2':
        $this->urlBase = 'http://angiesmr2dev2.prod.acquia-sites.com/companylist';
        break;

      case 'stage':
        $this->urlBase = 'http://angiesmr2stg.prod.acquia-sites.com/companylist';
        break;

      case 'prod':
        $this->urlBase = 'http://angiesmr2.prod.acquia-sites.com/companylist';
        break;

      case 'local':
      default:
        $this->urlBase = 'http://al.dev/companylist';
        break;
    }
  }

  private function loadProfile($profile) {
    $profile_file = "./profiles/{$profile}.yml";

    if (file_exists($profile_file)) {
      $data = YAML::parse(file_get_contents($profile_file));

      if (isset($data['pct'])) {
        foreach ($data['pct'] as $var => $val) {
          $this->{$var} = $val;
        }
      }

      if (isset($data['files'])) {
        foreach ($data['files'] as $var => $val) {
          $this->{$var} = $val;
        }
      }

      if (isset($data['addBaseUrls']) && $data['addBaseUrls'] === FALSE) {
        $this->addBaseUrls = FALSE;
      }

      // Load geo data.
      if (isset($this->geoFile) && file_exists($this->geoFile)) {
        $this->loadGeo();
      }

      // Load cat data.
      if (isset($this->catFile) && file_exists($this->catFile)) {
        $this->loadCat();
      }

      // Load SPs.
      if (isset($this->spsFile) && file_exists($this->spsFile)) {
        $this->loadSps();
      }
    }
    else {
      throw new Exception('Profile not found.');
    }
  }

  private function loadGeo() {
    $state_city = explode(PHP_EOL, file_get_contents($this->geoFile));

    // Remove header row.
    array_shift($state_city);

    foreach ($state_city as $record) {
      if (!empty($record)) {
        list($state, $city) = explode(',', $record);
        $this->geo[] = [
          'country' => 'us',
          'state' => $state,
          'city' => $city,
        ];

        $this->states[$state] = $state;
      }
    }
  }

  private function loadCat() {
    $this->cats = explode(PHP_EOL, file_get_contents($this->catFile));

    // Remove header row.
    array_shift($this->cats);
  }

  private function loadSps() {
    $this->sps = explode(PHP_EOL, file_get_contents($this->spsFile));
  }

  public function generateUrls($count) {
    $urls = [];

    // Add the base URLs.
    if ($this->addBaseUrls) {
      $urls[] = $this->urlBase;
      $urls[] = "{$this->urlBase}/us";
    }

    // Adjust the count.
    $count = $count - count($urls);

    // Add the SPs.
    if (!empty($this->sps)) {
      foreach ((array) array_rand($this->sps, $this->_countRecords($count, $this->pctSps, $this->sps)) as $key) {
        if (!empty($this->sps[$key])) {
          $urls[] = "{$this->urlBase}/{$this->sps[$key]}";
        }
      }
    }

    // State URLs.
    if (!empty($this->states)) {
      foreach ((array) array_rand($this->states, $this->_countRecords($count, $this->pctState, $this->states)) as $geo_key) {
        $urls[] = "{$this->urlBase}/us/{$geo_key}";
      }
    }

    // City URLs.
    if (!empty($this->geo)) {
      foreach ((array) array_rand($this->geo, $this->_countRecords($count, $this->pctCity, $this->geo)) as $geo_key) {
        $geo = $this->geo[$geo_key];

        $urls[] = $this->_isFullGeoUrl() ? "{$this->urlBase}/{$geo['country']}/{$geo['state']}/{$geo['city']}" : "{$this->urlBase}/{$geo['city']}";
      }

      // GeoCat Urls.
      foreach ((array) array_rand($this->geo, $this->_countRecords($count, $this->pctGeoCat, $this->geo)) as $geo_key) {
        $geo = $this->geo[$geo_key];

        $cat_key = array_rand($this->cats, 1);
        $cat = $this->cats[$cat_key] . '.htm';

        $urls[] = $this->_isFullGeoUrl() ? "{$this->urlBase}/{$geo['country']}/{$geo['state']}/{$geo['city']}/{$cat}" : "{$this->urlBase}/{$geo['city']}/{$cat}";
      }
    }

    // Write the URL file.
    file_put_contents($this->urlFile, implode(PHP_EOL, $urls));

    // Print the list of URLs.
    foreach ($urls as $url) {
      print "{$url}" . PHP_EOL;
    }
  }

  private function _countRecords($total, $pct, array $records) {
    $pct_count = max(1, min($total, ceil($total * $pct)));
    return min(count($records), $pct_count);
  }

  private function _isFullGeoUrl() {
    return (rand(1, round(1 / $this->pctFullGeo)) === 1);
  }

  private function _dump() {
    var_dump($this);
  }
}
