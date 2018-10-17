---
home: true
footer: MIT Licensed | Copyright © 2017-Present Will Kruse
title: Home
---
## Introduction ##

`laravel-scout-solr` provides a [Laravel Scout](https://github.com/laravel/scout) compatible engine for [Apache Solr](http://lucene.apache.org/solr/).

## Installation ##

`laravel-scout-solr` is provided as a composer package so installation is as simple as

```bash
composer require pxslip/laravel-scout-solr
```

For Laravel <= 5.4 register the Service provider in your `config/app.php`

```php
'providers' => [
    ...
    Scout\Solr\ScoutSolrServiceProvider::class,
]
```

## Usage ##

As some functionality is not compatible with the base Scout Builder class, use the `Scout\Solr\Searchable` trait in place of the `Scout\Searchable` trait on your models

```php
use Scout\Solr\Searchable;

class MyModel extends Model {
    use Searchable;
    ...
}
```

Searching is done the same as any other scout engine with some added functionality

```php
MyModel::where(...)
    ->orWhere(...)
    ->facetField(...)
```

For more details take a look at [usage](/usage.html)
