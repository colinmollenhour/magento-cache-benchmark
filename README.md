# Magento cache backend benchmark

This script was forked from the benchmark.php in [Vinai's Symlink-Cache](http://github.com/Vinai/Symlink-Cache) module.
Thanks Vinai!

## INSTALLATION

If you've never used modman before, download and place [modman](http://code.google.com/p/module-manager/)
in your PATH, and then from the root of your Magento installation run:

    modman init # Only if this is the first time you've used modman
    
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

