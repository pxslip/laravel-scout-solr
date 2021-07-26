<?php

namespace Scout\Solr;

use Illuminate\Database\Eloquent\Collection;
use Solarium\Component\Result\Spellcheck\Suggestion;

class SolrCollection extends Collection
{

  /**
   * The raw solr results.
   *
   * @var \Solarium\QueryType\Select\Result\Result
   */
  private $results;

  /**
   * The spellcheck component or null if not present.
   *
   * @var \Solarium\Component\Result\Spellcheck\Result|null
   */
  private $spellcheck;

  /**
   * The facet component or null if not present.
   *
   * @var \Solarium\Component\Result\FacetSet|null
   */
  private $facetSet;

  /**
   * Make a new collection
   *
   * @param \Solarium\QueryType\Select\Result\Result $results
   * @param array $items
   */
  public function __construct($results, $items = [])
  {
    parent::__construct($items);
    $this->results = $results;
  }

  /**
   * Get the facet set of the results.
   *
   * @return \Solarium\Component\Result\FacetSet|null
   */
  public function getFacetSet()
  {
    $this->facetSet = $this->facetSet ?? $this->results->getFacetSet();
    return $this->facetSet;
  }

  /**
   * Get the facets from the facet set.
   *
   * @return FacetInterface[]|null
   */
  public function getFacets()
  {
    $facetSet = $this->getFacetSet();
    if ($facetSet->count() > 0) {
      return $facetSet->getFacets();
    } else {
      return null;
    }
  }

  /**
   * Get the spellcheck results.
   *
   * @return \Solarium\Component\Result\Spellcheck\Result
   */
  public function getSpellcheck()
  {
    $this->spellcheck = $this->spellcheck ?? $this->results->getSpellcheck();
    return $this->spellcheck;
  }

  /**
   * Get the suggestions from the spellcheck results.
   *
   * @return Suggestion[]
   */
  public function getSpellcheckSuggestions()
  {
    $spellcheck = $this->getSpellcheck();
    if ($spellcheck) {
      return $spellcheck->getSuggestions();
    }
  }

  /**
   * Get the collated spellcheck suggestions.
   *
   * @return Colllation[]
   */
  public function getSpellcheckCollations()
  {
    $spellcheck = $this->getSpellcheck();
    if ($spellcheck) {
      return $spellcheck->getCollations();
    }
  }

}