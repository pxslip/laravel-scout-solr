# Solr Engine for Scout #

This engine provides the interface between Laravel Scout and a Solr instance.

## Installation ##

For the time being usage is limited to USHMM HE, this project may be separated and provided as a private composer package.

## Usage ##

As the engine uses some functionality that is not fully compatible with `Laravel\Scout\Builder` and `Laravel\Scout\Searchable` you will need to use the `App\Scout\Builder` and `App\Scout\Searchable` versions instead:

```php
use App\Scout\Searchable;

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
- [] Add nested querying to Builder
- [] Add nested querying to ScoutEngine