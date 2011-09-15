<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category   Netzarbeiter
 * @package    Netzarbeiter_Cache
 * @copyright  Copyright (c) 2011 Vinai Kopp http://netzarbeiter.com, Colin Mollenhour http://colin.mollenhour.com
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *
 * @author     Vinai Kopp http://netzarbeiter.com, Colin Mollenhour http://colin.mollenhour.com
 */

/**
 * Usage: php shell/cache-benchmark.php [command] [options]
 * This script needs read/write permissions to the magento cache files
 * if using a filesystem-based backend.
 */

require_once 'shell/abstract.php';

class benchmark extends Mage_Shell_Abstract
{
  /**
   * @var array $_tags Array of tags to benchmark
   */
  protected $_tags = array();

  /**
   * @var int $_maxTagLength The length of the longest cache tag
   * @see benchmark::_getLongestTagLength()
   */
  protected $_maxTagLength = 0;

  /**
   * @var string $_cachePrefix The cache tag and id prefix for this Magento instance
   * @see benchmark::_getCachePrefix()
   */
  protected $_cachePrefix = '';

  /**
   * Evaluate arguments and run or init benchmark.
   */
  public function run()
  {
    if($this->getArg('init')) {
      $this->_initDataset();
    }
    else if($this->getArg('clean')) {
      Mage::app()->getCache()->clean();
    }
    else if($this->getArg('load')) {
      $this->_echoCacheConfig();
      $this->_loadDataset();
    }
    else if($this->getArg('tags')) {
      $this->_runTagBenchmark();
    }
    else if($this->getArg('ops')) {
      $this->_runOpsBenchmark();
    }
    else {
      echo $this->usageHelp();
    }
  }

  protected function _getTestDir()
  {
    $name = $this->getArg('name') ?: 'default';
    $baseDir = Mage::getBaseDir('var').'/cachebench';
    if( ! is_dir($baseDir)) {
      mkdir($baseDir);
    }
    return $baseDir.'/'.$name;
  }

  /**
   * Initialize a dataset to files on disk (not loaded into cache)
   * 
    --keys <num>          Number of cache keys (default to 10000)
    --tags <num>          Number of cache tags (default to 2000)
    --min-tags <num>      The min number of tags to use for each record (default 0)
    --max-tags <num>      The max number of tags to use for each record (default 15)
    --min-rec-size <num>  The smallest size for a record (default 1)
    --max-rec-size <num>  The largest size for a record (default 1024)
    --clients <num>       The number of clients for multi-threaded testing (defaults to 4)
    --ops <num>           The number of operations per client (defaults to 10000)
    --write-chance        The chance-factor that a key will be overwritten (defaults to 1000)
    --clean-chance        The chance-factor that a tag will be cleaned (defaults to 5000)
    --seed <num>          The random number generator seed (default random)
   */
  protected function _initDataset()
  {
    $name = $this->getArg('name') ?: 'default';
    $testDir = $this->_getTestDir();
    if (is_dir($testDir)) {
      array_map('unlink', glob("$testDir/*.*"));
    }
    if ( ! is_dir($testDir)) {
      mkdir($testDir);
    }

    // Dump command-line
    file_put_contents("$testDir/cli.txt", 'php '.implode(' ',$_SERVER['argv']));

    $numKeys = $this->getArg('keys') ?: 10000;
    $numTags = $this->getArg('tags') ?: 2000;
    $minTags = $this->getArg('min-tags') ?: 0;
    $maxTags = $this->getArg('max-tags') ?: 15;
    $minSize = $this->getArg('min-rec-size') ?: 1;
    $maxSize = $this->getArg('max-rec-size') ?: 1024;
    $numClients = $this->getArg('clients') ?: 4;
    if($this->getArg('seed')) {
      mt_srand( (int) $this->getArg('seed'));
    }
    $numOps = $this->getArg('ops') ?: 100000;
    $writeFactor = $this->getArg('write-chance') ?: 1000;
    $cleanFactor = $this->getArg('clean-chance') ?: 5000;

    $data = array();
    $tags = array();
    $lengths = array();
    $reads = array();
    $writes = array();
    $expires = array(false,false,false,false,false,'rand'); // 1/6th of keys expire

    echo "Generating cache data...\n";
    $progressBar = $this->_getProgressBar(0, $numKeys / 100);

    // Generate tags
    $this->_createTagList($numTags);

    // Generate data
    for($i = 0; $i < $numKeys; $i++) {
      if($i % 100 == 0) {
        $progressBar->update(($i / 100)+1);
      }
      $key = md5($i);
      $expireAt = $expires[mt_rand(0,count($expires)-1)];
      if($expireAt == 'rand') {
        $expireAt = mt_rand(10, 14400);
      }
      $data[$key] = array(
        'data' => $this->_getRandomData($minSize, $maxSize),
        'tags' => $this->_getRandomTags($minTags, $maxTags),
        'expires' => $expireAt
      );

      // Store length since data length per key will usually be constant
      $lengths[$key] = strlen($data[$key]['data']);
      $tags[$key] = $data[$key]['tags'];

      // Some keys are read more frequently
      $popularity = mt_rand(1,100);
      for($j = 0; $j < $popularity; $j++) {
        $reads[] = $key;
      }

      // Some keys are written more frequently
      $volatility = mt_rand(1,100);
      for($j = 0; $j < $volatility; $j++) {
        $writes[] = $key;
      }
    }
    $progressBar->finish();

    // Dump data
    file_put_contents("$testDir/data.json", json_encode($data));
    $data = NULL;

    echo "Generating operations...\n";
    $progressBar = $this->_getProgressBar(0, ($numClients * $numOps) / 1000);

    // Create op lists for each client
    for($i = 0, $k = 0; $i < $numClients; $i++)
    {
      $ops = array();
      for($j = 0; $j < $numOps; $j++)
      {
        if($k++ % 1000 === 0) {
          $progressBar->update($k / 1000);
        }

        // Clean
        if(mt_rand(0, $cleanFactor) == 0) {
          $index = mt_rand(0,count($this->_tags)-1) ;
          $tag = $this->_tags[$index];
          $ops[] = array('clean', $tag);
        }

        // Write
        else if(mt_rand(0, $writeFactor) == 0) {
          $index = mt_rand(0,count($writes)-1);
          $key = $writes[$index];
          $ops[] = array('write', $key, $lengths[$key], $tags[$key]);
        }

        // Read
        else {
          $index = mt_rand(0,count($reads)-1);
          $key = $reads[$index];
          $ops[] = array('read', $key);
        }
      }
      file_put_contents("$testDir/ops-client_$i.json", json_encode($ops));
    }
    $progressBar->finish();

    $quiet = $numClients == 1 ? '':'--quiet';
    $script = <<<BASH
#!/bin/bash
if [ "$1" != "keep" ]; then
  php shell/cache-benchmark.php clean
  php shell/cache-benchmark.php load --name '$name'
fi
php shell/cache-benchmark.php tags
results=var/cachebench/$name/results.txt
rm -f \$results

clients=0
function runClient() {
  clients=$((clients+1))
  php shell/cache-benchmark.php ops --name '$name' --client \$1 $quiet >> \$results &
}
echo "Benchmarking $numClients concurrent clients, each with $numOps operations..."
start=$(date '+%s')
BASH;
    $script .= "\n";
    for($i = 0; $i < $numClients; $i++) {
      $script .="runClient $i\n";
    }
    $script .= <<<BASH
wait
finish=$(date '+%s')
elapsed=$((finish - start))
echo "\$clients concurrent clients completed in \$elapsed seconds"
echo ""
echo "         |   reads|  writes|  cleans"
echo "------------------------------------"
awk '
BEGIN { FS=OFS="|" }
      { print; for (i=2; i<=NF; ++i) sum[i] += \$i; j=NF }
END   {
        printf "------------------------------------\\n";
        printf "ops/sec  ";
        for (i=2; i <= j; ++i) printf "%s%8.2f", OFS, sum[i];
      }
' \$results
echo ""
BASH;
    file_put_contents("$testDir/run.sh", $script);

    echo "Completed generation of test data for test '$name'.\n";
    echo "Run your test like so:\n\n";
    echo "  \$ bash var/cachebench/$name/run.sh\n";
  }

  /**
   * Load the specified dataset
   */
  protected function _loadDataset()
  {
    $name = $this->getArg('name') ?: 'default';
    $testDir = $this->_getTestDir();
    $dataFile = "$testDir/data.json";
    if( ! file_exists($dataFile)) {
      throw new RuntimeException("The '$name' test data does not exist. Please run the 'init' command.");
    }
    echo "Loading '$name' test data...\n";
    $data = json_decode(file_get_contents($dataFile), true);
    $progressBar = $this->_getProgressBar(0, count($data) / 100);
    $i = 0;
    $start = microtime(true);
    $size = 0;
    foreach($data as $key => $cache) {
      if($i++ % 100 == 0) {
        $progressBar->update($i / 100);
      }
      $size += strlen($cache['data']);
      Mage::app()->saveCache($cache['data'], $key, $cache['tags'], $cache['expires']);
    }
    $elapsed = microtime(true)-$start;
    $progressBar->finish();
    printf("Loaded %d cache records in %.4f seconds. Data size is %.1fK\n", $i, $elapsed, $size / 1024);
  }

  /**
   * Run the tags benchmark on the existing dataset
   */
  protected function _runTagBenchmark()
  {

    $verbose  = $this->getArg('v') || $this->getArg('verbose');
    echo "Analyzing current cache contents...\n";
    $start = microtime(true);

    $nTags = $this->_readTags();
    if (1 > $nTags)
    {
      throw new OutOfRangeException('No cache tags found in cache');
    }
    $nEntries = $this->_countCacheRecords();
    if (1 > $nEntries)
    {
      throw new OutOfRangeException('No cache records found in cache');
    }

    $time = microtime(true) - $start;
    printf("Counted %d cache IDs and %d cache tags in %.4f seconds\n", $nEntries, $nTags, $time);

    printf("Benchmarking getIdsMatchingTags...\n");
    $times = $this->_benchmarkByTag($verbose);

    $this->_echoAverage($times);
  }

  /**
   * Run the ops benchmark from the specified dataset
   */
  protected function _runOpsBenchmark()
  {
    $quiet  = $this->getArg('q') || $this->getArg('quiet');

    $name = $this->getArg('name') ?: 'default';
    $testDir = $this->_getTestDir();
    $client = $this->getArg('client') ?: 0;
    $dataFile = "$testDir/ops-client_$client.json";
    if( ! file_exists($dataFile)) {
      throw new RuntimeException("The '$name' test data does not exist. Please run the 'init' command.");
    }
    if( ! $quiet) echo "Loading operations...\n";
    $data = json_decode(file_get_contents($dataFile), true);
    if( ! $quiet) {
      echo "Executing operations...\n";
      $progressBar = $this->_getProgressBar(0, count($data) / 100);
    }
    $times = array(
      'read_time' => 0,
      'reads' => 0,
      'write_time' => 0,
      'writes' => 0,
      'clean_time' => 0,
      'cleans' => 0,
    );
    foreach($data as $i => $op)
    {
      switch($op[0])
      {
        case 'read':
          $start = microtime(TRUE);
          Mage::app()->loadCache($op[1]);
          $elapsed = microtime(TRUE) - $start;
          break;
        case 'write':
          $string = $this->_getRandomData($op[2]);
          $start = microtime(TRUE);
          Mage::app()->saveCache($string, $op[1], $op[3]);
          $elapsed = microtime(TRUE) - $start;
          break;
        case 'clean':
          $start = microtime(TRUE);
          Mage::app()->cleanCache($op[1]);
          $elapsed = microtime(TRUE) - $start;
          break;
        default:
          throw new RuntimeException('Invalid op: '.$op[0]);
      }
      $times[$op[0].'_time'] += $elapsed;
      $times[$op[0].'s'] += 1;
      if( ! $quiet && ($i % 100 == 0)) {
        $progressBar->update($i / 100);
      }
    }
    if( ! $quiet) {
      $progressBar->finish();
    }

    printf("Client %2d", $client);
    foreach(array('read','write','clean') as $op) {
      printf("|%8.2f", $times[$op.'s'] / $times[$op.'_time']);
    }
    echo "\n";
  }

  /**
   * Display average values from given tag benchmark times.
   *
   * @param array $times
   */
  protected function _echoAverage(array $times)
  {
    $totalTime = $totalIdCount = 0;
    $numTags = count($times);
    foreach ($times as $time)
    {
      $totalTime     += $time['time'];
      $totalIdCount  += $time['count'];
    }
    printf("Average: %.5f seconds (%5.2f ids per tag)\n", $totalTime / $numTags, $totalIdCount / $numTags);
  }

  /**
   * Display the configured cache backend(s).
   */
  protected function _echoCacheConfig()
  {
    $backend = (string) Mage::getConfig()->getNode('global/cache/backend');
    $realBackend = Mage::app()->getCache()->getBackend();
    $slowBackend = (string) Mage::getConfig()->getNode('global/cache/slow_backend');

    if ('' === $backend)
    {
      $backend = get_class($realBackend);
    }
    if ($realBackend instanceof Zend_Cache_Backend_TwoLevels && '' === $slowBackend)
    {
      $slowBackend = 'Zend_Cache_Backend_File';
    }

    if ('' === $slowBackend)
    {
      printf("Cache Backend: %s\n", $backend);
    }
    else
    {
      printf("Cache Backend: %s + %s\n", $backend, $slowBackend);
    }
  }

  /**
   * Create internal list of cache tags.
   *
   * @param int $nTags The number of tags to create
   */
  protected function _createTagList($nTags)
  {
    $length = strlen('' . $nTags);
    for ($i = 1; $i <= $nTags; $i++)
    {
      $this->_tags[] = sprintf('TAG_%0' . $length . 'd', $i);
    }
  }

  /**
   * Read in list of cache tags. Remove the cache prefix to get the tags
   * specified to Mage_Core_Model_App::saveCache()
   *
   * @return int The number of tags
   */
  protected function _readTags()
  {
    $this->_tags = array();
    $prefix = $this->_getCachePrefix();
    $tags = (array) Mage::app()->getCache()->getTags();
    $prefixLen = strlen($prefix);
    foreach ($tags as $tag)
    {
      $tag = substr($tag, $prefixLen);

      // since all records saved through Magento are associated with the
      // MAGE cache tag it is not representative for benchmarking.
      if ('MAGE' === $tag) continue;

      $this->_tags[] = $tag;
    }
    sort($this->_tags);
    return count($this->_tags);
  }

  /**
   * Return the configured cache prefix according to the logic in core/cache.
   *
   * @return string The used cache prefix
   * @see Mage_Core_Model_Cache::__construct()
   */
  protected function _getCachePrefix()
  {
    if (! $this->_cachePrefix)
    {
      $options = Mage::getConfig()->getNode('global/cache');
      $prefix = '';
      if ($options)
      {
        $options = $options->asArray();
        if (isset($options['id_prefix']))
        {
          $prefix = $options['id_prefix'];
        }
        elseif (isset($options['prefix']))
        {
          $prefix = $options['prefix'];
        }
      }
      if ('' === $prefix)
      {
        $prefix = substr(md5(Mage::getConfig()->getOptions()->getEtcDir()), 0, 3).'_';;
      }
      $this->_cachePrefix = $prefix;
    }
    return $this->_cachePrefix;
  }

  /**
   * Return the current number of cache records.
   *
   * @return int The current number of cache records
   */
  protected function _countCacheRecords()
  {
    $ids = (array) Mage::app()->getCache()->getIds();
    return count($ids);
  }

  /**
   * Since many operations can take quite a long time for large cache pools,
   * this might help ease the waiting time with a nice console progress bar.
   *
   * @param $min
   * @param $max
   * @return Zend_ProgressBar A fresh Zend_ProgressBar instance
   */
  protected function _getProgressBar($min, $max)
  {
    $progressBar = new Zend_ProgressBar(new Zend_ProgressBar_Adapter_Console(), $min, $max);
    $progressBar->getAdapter()->setFinishAction(Zend_ProgressBar_Adapter_Console::FINISH_ACTION_CLEAR_LINE);
    return $progressBar;
  }

  /**
   * Return a random number of cache tags between the given range.
   *
   * @param int $min
   * @param int $max
   * @return array
   */
  protected function _getRandomTags($min, $max)
  {
    $tags = array();
    $num = mt_rand($min, $max);
    $keys = array_rand($this->_tags, $num);
    foreach ($keys as $i) {
      $tags[] = $this->_tags[$i];
    }
    return $tags;
  }

  /**
   * Get random data for cache
   * 
   * @param $min
   * @param $max
   * @return string
   */
  protected function _getRandomData($min, $max = NULL)
  {
    if($max === NULL) {
      $length = $min;
    } else {
      $length = mt_rand($min, $max);
    }
    $string = md5(mt_rand());
    while(strlen($string) < $length) {
      $string = base64_encode($string);
    }
    return substr($string, 0, $length);
  }

  /**
   * Get the time used for calling getIdsMatchingTags() for every cache tag in
   * the property $_tags.
   * If $verbose is set to true, display detailed statistics for each tag,
   * otherwise display a progress bar.
   *
   * @param bool $verbose If true output statistics for every cache tag
   * @return array Return an array of timing statistics
   */
  protected function _benchmarkByTag($verbose = false)
  {
    $times = array();

    if (! $verbose)
    {
      $progressBar = $this->_getProgressBar(0, count($this->_tags) / 10);
      $counter = 0;
    }

    foreach ($this->_tags as $tag)
    {
      $start = microtime(true);
      $ids = Mage::app()->getCache()->getIdsMatchingTags(array($tag));
      $end = microtime(true);
      $times[$tag] = array('time' => $end - $start, 'count' => count($ids));
      if (! $verbose && $counter++ % 10 == 0) {
        $progressBar->update($counter / 10);
      }
    }
    if (! $verbose)
    {
      $progressBar->finish();
    }
    return $times;
  }

  /**
   * Return the length of the longest tag in the $_tags property.
   *
   * @param bool $force If true don't use the cached value
   * @return int The length of the longest tag
   */
  protected function _getLongestTagLength($force = false)
  {
    if (0 === $this->_maxTagLength || $force)
    {
      $len = 0;
      foreach ($this->_tags as $tag)
      {
        $tagLen = strlen($tag);
        if ($tagLen > $len)
        {
          $len = $tagLen;
        }
      }
      $this->_maxTagLength = $len;
    }
    return $this->_maxTagLength;
  }

  /**
   * Return the usage help.
   *
   * @return string
   */
  public function usageHelp()
  {
    return <<<USAGE
This script will either initialize a new benchmark dataset or run a benchmark.

Usage:  php -f shell/cache-benchmark.php [command] [options]

Commands:
  init (See "Initialization options")
  clean
  load --name <string>
  tags
  ops --name <string> --client <num> [-q|--quiet]

Initialization options:
  --name <string>       A unique name for this dataset (default to "default")
  --keys <num>          Number of cache keys (default to 10000)
  --tags <num>          Number of cache tags (default to 2000)
  --min-tags <num>      The min number of tags to use for each record (default 0)
  --max-tags <num>      The max number of tags to use for each record (default 15)
  --min-rec-size <num>  The smallest size for a record (default 1)
  --max-rec-size <num>  The largest size for a record (default 1024)
  --clients <num>       The number of clients for multi-threaded testing (defaults to 4)
  --seed <num>          The random number generator seed (default random)

USAGE;
  }
}


$init = new benchmark();
$init->run();
