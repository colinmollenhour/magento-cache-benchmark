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
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension
 * to newer versions in the future.
 *
 * @category   Netzarbeiter
 * @package    Netzarbeiter_Cache
 * @copyright  Copyright (c) 2011 Vinai Kopp http://netzarbeiter.com
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Useage help: php app/code/local/Netzarbeiter/Cache/shell/benchmark.php -- -h
 * This script needs read/write permissions to the magento cache files.
 */

// use __FILE__ instead of __DIR__ because php 5.3 isn't available everywhere yet
// This strange require lets the script work from my modman location, too
$sentry = 0;
$abstract = '/../../../../../../shell/abstract.php';
while (! file_exists(dirname(__FILE__) . $abstract) && $sentry < 2) {
  $abstract = '/..' . $abstract;
  $sentry++;
}
$abstract = '/abstract.php';
require_once dirname(__FILE__) . $abstract;

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
   * Evaluate arguments and run benchmark.
   *
   * @return benchmark
   */
  public function run()
  {
    $this->_echoCacheConfig();

    $nEntries = (int) $this->getArg('init');
    $verbose  = $this->getArg('v') || $this->getArg('verbose');

    if ($nEntries)
    {

      if (0 === ($nTags = (int) $this->getArg('tag')))
      {
        $nTags = (int) $this->getArg('tags');
      }
      $minTags   = (int) $this->getArg('min');
      $maxTags   = (int) $this->getArg('max');

      if ($nEntries < 1)
      {
        throw new InvalidArgumentException('Number of records to create must be one or more');
      }
      if ($nTags < 1) $nTags = 30;
      if ($minTags < 1) $minTags = 5;
      if ($maxTags < 1) $maxTags = $minTags + 5;
      if ($maxTags > $nTags) $maxTags = $nTags;

      if ($minTags != $maxTags)
      {
        $rangeString = sprintf('%d-%d', $minTags, $maxTags);
      }
      else
      {
        $rangeString = $minTags;
      }

      $this->_println(sprintf('Clearing & Initialising cache with %d records using %s out of %d tags per record...',
        $nEntries, $rangeString, $nTags
      ));
      Mage::app()->cleanCache();
      $this->_createTagList($nTags);
      $this->_createCacheRecords($nEntries, $minTags, $maxTags);

    }
    else
    {
      printf('Analyzing current cache contents (please be patient)... ');
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
      $this->_println(sprintf('%d cache IDs and %d cache tags read in %ss', $nEntries, $nTags, $time));
    }

    $this->_println(sprintf('Benchmarking %d cache records with %d tags', $nEntries, $nTags));

    $times = $this->_benchmarkByTag($verbose);

    $this->_echoAverage($times);

    return $this;
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
    $this->_echoTime(sprintf('Average for %s tags:', $numTags), $totalTime / $numTags, $totalIdCount / $numTags);
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
      $this->_println(sprintf('Cache Configuration: single backend %s', $backend));
    }
    else
    {
      $this->_println(sprintf('Cache Configuration: fast backend: %s, slow backend: %s', $backend, $slowBackend));
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
   * Create the given number of cache records.
   * Each record only contains the sample data "X".
   * Each records is associated with a random number of cache tags between the
   * given min and max.
   *
   * @param int $nEntries The number of Cache records to create
   * @param int $minTags The minimum number of cache tags for each record
   * @param int $maxTags The maximum number of cache tags for each record
   */
  protected function _createCacheRecords($nEntries, $minTags, $maxTags)
  {
    $data = 'X';
    $progressBar = $this->_getProgressBar(0, $nEntries);

    $start = microtime(true);
    for ($i = 0; $i < $nEntries; $i++)
    {
      $id = sprintf('rec_%010d', $i);
      $tags = $this->_getRandomTags($minTags, $maxTags);
      Mage::app()->saveCache($data, $id, $tags);
      $progressBar->update($i+1);
    }
    $time = microtime(true) - $start;
    $progressBar->finish();
    $this->_println(sprintf('Initialization finished in %ss', $time));
  }

  /**
   * Since many operations can take quite a long time for large cache pools,
   * this might help ease the waiting time with a nice console progress bar.
   *
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
   * Display the given string with a trailing newline.
   *
   * @param string $msg The string to display
   */
  protected function _println($msg)
  {
    printf("%s\n", $msg);
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
      $progressBar = $this->_getProgressBar(0, count($this->_tags));
      $counter = 0;
    }

    foreach ($this->_tags as $tag)
    {
      $start = microtime(true);
      $ids = Mage::app()->getCache()->getIdsMatchingTags(array($tag));
      $end = microtime(true);
      $times[$tag] = array('time' => $end - $start, 'count' => count($ids));
      if ($verbose)
      {
        $this->_echoTime($tag, $times[$tag]['time'], $times[$tag]['count']);
      }
      else
      {
        $progressBar->update(++$counter);
      }
    }
    if (! $verbose)
    {
      $progressBar->finish();
    }
    return $times;
  }

  /**
   * Display the given timing statistics.
   *
   * @param string $tag The tag
   * @param float $time The time used to run getIdsMatchingTags() for the tag
   * @param int $count The number of IDs associated with the tag
   */
  protected function _echoTime($tag, $time, $count)
  {
    $length = $this->_getLongestTagLength();
    $pattern = '%-' . $length . 's  (%4s IDs) %ss';
    $this->_println(sprintf($pattern, $tag, $count, $time));
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
This scrit initialize all cache tag symlinks.
The script needs read/write permissions to access the magento cache files.

Usage:  php -f app/code/local/Netzarbeiter/Cache/shell/benchmark.php -- [options]

    --init <num> Clear existing cache records and create <num> entries
    --tag <num>  If init was used, specify the number of tags to create (default to 30)
  --min <num>  If init was used, the min number of tags to use for each record (default 5)
  --max <num>  If init was used, the max number of tags to use for each record (default min +5)
  -v           Display statistics for every cache tag
  --help       This help

USAGE;
  }
}


$init = new benchmark();
$init->run();
