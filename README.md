# Solr Engine for Scout #

This engine provides the interface between Laravel Scout and a Solr instance.

## Installation ##

For the time being usage is limited to USHMM HE, this project may be separated and provided as a private composer package.

## Usage ##

As the engine uses some functionality that is not fully compatible with `Laravel\Scout\Builder` and `Laravel\Scout\Searchable` you will need to use the `Scout\Solr\Builder` and `Scout\Solr\Searchable` versions instead:

```php
use Scout\Solr\Searchable;

class MyModel extends Model {
    use Searchable;
    ...
}

// and then to perform a search

MyModel::where(...)
    ->orWhere(...)
    ->facetField(...)
```

## TO DO ##

- [] Add bindings instead of just passing the string for better escaping
- [x] Add nested querying to Builder
- [x] Add nested querying to ScoutEngine
