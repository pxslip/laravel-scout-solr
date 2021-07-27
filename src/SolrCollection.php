<?php

namespace Scout\Solr;

use Illuminate\Database\Eloquent\Collection;
use Scout\Solr\HasSolrResults;

class SolrCollection extends Collection
{
  use HasSolrResults;
}
