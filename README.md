CRON.CRLib
==========

Abstract
--------

This is an Utility-Package for Neos 2.x to allow traversing/iterating over the TYPO3CR tree in a more efficient
manner than using the high-level API (e.g. NodeDataRepository or NodeInterface::childNodes()).

The main issue using the high level API is that it doesn't scale well, especially when dealing with large
result sets (e.g. more than 500.000 nodes), the main reason being the fact that temporary PHP Arrays are being
used to collect and filter data.

Setup
-----

Because this package is currently not available via Packagist, setup the github url in `composer.json`:

```
	"repositories": [
		{
			"type": "git",
			"url": "git@github.com:cron-eu/neos-crlib.git"
		}
	]
```

Then:

```
composer require --update-no-dev cron/neos-crlib:dev-master
```

Node Command Controller
-----------------------

The supplied command controller can perform some basic CRUD actions on TYPO3CR nodes, like creating, deleting
and basic search.

Call `flow help|grep node` to get a list of currently implemented commands. 

Page Command Controller
-----------------------

There is also a separate `PageCommandController` which will do similar things but using the high level Neos Node API.
This ensures that all hooks will be called correctly.

Call `flow help|grep page:` to get a list of currently implemented commands. 

#### Workspace

The Page Command Controller will use the current workspace by default. There is a `publish` command to publish
all changes to the live workspace.

### Examples

#### Bulk Delete nodes

To bulk delete all nodes under a specified path, using a batch size of 100:

```bash
#!/bin/bash -ex

while true ; do
  ./flow page:remove --url /news --limit 100 && ./flow page:publish || exit 1
done
``` 

Because the remove command will bail out with a retval != 0 on errors, the loop will break (because the bash `-e` option).


NodeQuery and NodeIterator Classes
-----------------------------------------

### NodeQuery

Can be used to construct ORM Queries for NodeData, has some public methods to add Constraints like
Path or NodeType and also a convenience Initializer.

### NodeIterator Class

The Utility Class `NodeIterator` can be used to loop over a node subset using the PHP Iterator Interface, e.g.
to loop over all nodes:

```
foreach(new NodeIterator((new NodeQuery())->getQuery()) as $node) {
    // do something with $node
}
```

The memory usage is quite constant, the Node's being created on the fly on each iteration and not cached
in any way. The NodeIterator do also perform periodically (after a hardcoded batch size) a `clearState()` call,
to free up memory and speedup things. 

### Benchmark

This Benchmark iterates over 138719 nodes, calculates an md5 sum from the node's title
property and reports the memory usage and some performance data:

```
	public function getAllNodesCommand() {
		$nodeQuery = new NodeQuery();
		$iterator = new NodeIterator($nodeQuery->getQuery());
		$time = microtime(true);
		$count = 0;
		$md5 = '';
		$batchSize = 10000;
		foreach ($iterator as $node) {
			// do some pseudo calculations to unwrap the objects (and don't let the PHP optimizer to
			// mess up our benchmark
			$md5 = md5($md5 . $node->getProperty('title'));

			if ($iterator->key() % $batchSize === 0) {
				$this->reportMemoryUsage();
				$oldTime = $time; $time = microtime(true);
				$seconds = $time - $oldTime;
				if ($count) $this->outputLine('%.1f records/s', [(float)$batchSize/$seconds]);
			}
			$count++;
		}
		$this->outputLine('%d records processed, md5: %s', [$count, $md5]);
	}
```

#### The Results

using my iMac (middle 2010):

```
[www@8e2f4947eaf1 : ~/typo3-app]$ ./flow nodecruncher:getallnodes
 > mem: 243.9 MB
 > mem: 258.1 MB
2126.3 records/s
 > mem: 258.2 MB
2120.2 records/s
 > mem: 258.4 MB
2093.2 records/s
 > mem: 258.5 MB
2091.6 records/s
 > mem: 258.7 MB
1999.4 records/s
 > mem: 258.8 MB
2034.0 records/s
 > mem: 259.0 MB
2059.0 records/s
 > mem: 259.1 MB
1950.7 records/s
 > mem: 259.3 MB
1900.1 records/s
 > mem: 259.4 MB
1966.9 records/s
 > mem: 259.6 MB
1947.0 records/s
 > mem: 259.7 MB
1999.1 records/s
 > mem: 259.9 MB
1940.5 records/s
138719 records processed, md5: 289d8c4613dd39c61a04839cc536907d
```

### NodeQueryService Class

Use the NodeQueryService Class to create Doctrine ORM Queries, which can be used for the NodeIterator class or
stand alone. Use the source code of this package as an inspiration source for more usage examples..


Import/Export Feature
---------------------

There are 2 commands for importing/exporting nodes available. The Data is exported using the JSONL Format
(one JSON record per line) and can be used directly for feeding e.g. mongoimport, to do some data mining
afterwards. The resources are exported in the same format as the `site:export` command is generating and saved
in a folder called `res` in the cwd.

Currently the data can only be imported onto the same location, but some path manipulation features
(map source/destination paths) will be available soon.

Technically, the whole process is implemented using the PHP Iterable Interface and it does scale pretty well
while using very large sites.

### Benchmark

A (non-representative) benchmark using my iMac (mid2010) in a docker environment:

```
$ time ./flow site:export --filename site-export-dir/Sites.xml
All sites have been exported to "site-export-dir/Sites.xml".

real	3m20.679s
user	1m3.250s
sys	0m2.120s

$ time ./flow node:export --filename allnodes.jsonl
 138720/138720 [============================] 100%

real	0m25.977s
user	0m20.970s
sys	0m2.210s
```


Known Limitations
-----------------

Currently not all possible search criteria are supported, e.g. there is no support for dealing with 
content dimensions or hidden/removed nodes etc.
