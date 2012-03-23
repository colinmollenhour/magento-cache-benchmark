# Magento cache backend benchmark

This script was forked from the benchmark.php in [Vinai's Symlink-Cache](http://github.com/Vinai/Symlink-Cache) module.
Thanks Vinai!

## INSTALLATION

If you've never used modman before, download and place [modman](http://code.google.com/p/module-manager/)
in your PATH, and then from the root of your Magento installation run:

    modman init
    
Then:

    modman clone git://github.com/colinmollenhour/magento-cache-benchmark.git

## USAGE

    php shell/cache-benchmark.php init
    bash var/cachebench/default/run.sh

## FEATURES

* Flexible dataset generation via options to init command
* Repeatable tests. Dataset is written to static files so the same test can be repeated, even with different backends.
* Test datasets can easily be zipped up and copied to different environments or shared.
* Can easily test multiple pre-generated datasets.
* Supports multi-process benchmarking, each process with a different set of random operations.
* Cache record data size, number of tags, expiration, popularity and volatility are all randomized.

## EXAMPLE RUN

    Cache Backend: Zend_Cache_Backend_Redis
    Loading 'default' test data...
    Loaded 10000 cache records in 16.9125 seconds. Data size is 5009.0K
    Analyzing current cache contents...
    Counted 10021 cache IDs and 2005 cache tags in 0.2560 seconds
    Benchmarking getIdsMatchingTags...
    Average: 0.00039 seconds (36.82 ids per tag)
    Benchmarking 4 concurrent clients, each with 100000 operations.
    4 concurrent clients completed in 64 seconds
    
             |   reads|  writes|  cleans
    ------------------------------------
    Client  2| 1680.80|  313.59|  380.58
    Client  1| 1681.22|  318.17|  292.41
    Client  3| 1664.77|  316.60|  311.62
    Client  0| 1650.93|  259.28|  361.04
    ------------------------------------
    ops/sec  | 6677.72| 1207.64| 1345.65

## CLI HELP

    Usage:  php -f shell/cache-benchmark.php [command] [options]

    Commands:
      init [options]        Initialize a new dataset.
      load --name <string>  Load an existing dataset.
      clean                 Flush the cache backend.
      tags                  Benchmark getIdsMatchingTags method.
      ops [options]         Execute a pre-generated set of operations on the existing cache.

    'init' options:
      --name <string>       A unique name for this dataset (default to "default")
      --keys <num>          Number of cache keys (default to 10000)
      --tags <num>          Number of cache tags (default to 2000)
      --min-tags <num>      The min number of tags to use for each record (default 0)
      --max-tags <num>      The max number of tags to use for each record (default 15)
      --min-rec-size <num>  The smallest size for a record (default 1)
      --max-rec-size <num>  The largest size for a record (default 1024)
      --clients <num>       The number of clients for multi-threaded testing (defaults to 4)
      --seed <num>          The random number generator seed (default random)

    'ops' options:
      --name <string>       The dataset to use (from the --name option from init command)
      --client <num>        Client number (0-n where n is --clients option from init command)
      -q|--quiet            Be less verbose.
