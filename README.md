# Magento cache backend benchmark

This script was forked from the benchmark.php in [Vinai's Symlink-Cache](http://github.com/Vinai/Symlink-Cache) module.
Thanks Vinai!

## INSTALLATION

If you've never used modman before, download and place [modman](http://code.google.com/p/module-manager/)
in your PATH, and then from the root of your Magento installation run:

    modman init
    
Then:

    modman cachebench clone git://github.com/colinmollenhour/magento-cache-benchmark.git

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
    Loaded 10000 cache records in 29.1080 seconds. Data size is 5008.9K
    Analyzing current cache contents...
    Counted 10023 cache IDs and 2005 cache tags in 0.2062 seconds
    Benchmarking getIdsMatchingTags...
    Average: 0.00036 seconds (36.82 ids per tag)
    Benchmarking 4 concurrent clients, each with 100000 operations...
    4 concurrent clients completed in 62 seconds
    
             |   reads|  writes|  cleans
    ------------------------------------
    Client  1| 1811.83|  184.66|    6.81
    Client  2| 1799.84|  165.29|    6.91
    Client  3| 1818.90|  165.17|    6.79
    Client  0| 1790.91|  153.56|    7.40
    ------------------------------------
    ops/sec  | 7221.48|  668.68|   27.91

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
