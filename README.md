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

Command Controller
------------------

The supplied command controller can perform some basic CRUD actions on TYPO3CR nodes, like creating, deleting
and basic search.

Call `flow help|grep node` to get a list of currently implemented commands. 

NodeQueryService and NodeIterator Classes
-----------------------------------------

### NodeIterator Class

The Utility Class `NodeIterator` can be used to loop over a node subset using the PHP Iterator Interface, e.g.
to loop over all nodes:

```
foreach(new NodeIterator($context, $this->nodeQueryService->findQuery()) as $node) {
    // do something with $node
}
```

The memory usage is quite constant, the Node's being created on the fly on each iteration and not cached
in any way.

### NodeQueryService Class

Use the NodeQueryService Class to create Doctrine ORM Queries, which can be used for the NodeIterator class or
stand alone. Use the source code of this package as an inspiration source for more usage examples..

Known Limitations
-----------------

Currently not all possible search criteria are supported, e.g. there is no support for dealing with 
content dimensions or hidden/removed nodes etc.