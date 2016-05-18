<?php

/*
 * Copyright BibLibre, 2016
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace Solr;

use SolrClient;
use SolrClientException;
use SolrQuery;
use Search\Querier\AbstractQuerier;
use Search\Querier\Exception\QuerierException;
use Search\Query;
use Search\Response;

class Querier extends AbstractQuerier
{
    public function query(Query $query)
    {
        $client = $this->getClient();

        $solrQuery = new SolrQuery;
        $q = $query->getQuery();
        if (!empty($q)) {
            $solrQuery->setQuery($q);
        }
        $solrQuery->addField('id');

        $facetFields = $query->getFacetFields();
        if (!empty($facetFields)) {
            $solrQuery->setFacet(true);
            foreach ($facetFields as $facetField) {
                $solrQuery->addFacetField($facetField);
            }
        }

        $filters = $query->getFilters();
        if (!empty($filters)) {
            foreach ($filters as $name => $values) {
                foreach ($values as $value) {
                    $solrQuery->addFilterQuery("$name:$value");
                }
            }
        }

        $sort = $query->getSort();
        if (isset($sort)) {
            list($sortField, $sortOrder) = explode(' ', $sort);
            $sortOrder = $sortOrder == 'asc' ? SolrQuery::ORDER_ASC : SolrQuery::ORDER_DESC;
            $solrQuery->addSortField($sortField, $sortOrder);
        }

        if ($limit = $query->getLimit())
            $solrQuery->setRows($limit);

        if ($offset = $query->getOffset())
            $solrQuery->setStart($offset);

        try {

            $solrQueryResponse = $client->query($solrQuery);
        } catch (SolrClientException $e) {
            throw new QuerierException($e->getMessage(), $e->getCode(), $e);
        }
        $solrResponse = $solrQueryResponse->getResponse();

        $response = new Response;
        $response->setTotalResults($solrResponse['response']['numFound']);
        foreach ($solrResponse['response']['docs'] as $doc) {
            $response->addResult(['id' => $doc['id']]);
        }

        foreach ($solrResponse['facet_counts']['facet_fields'] as $name => $values) {
            foreach ($values as $value => $count) {
                if ($count > 0) {
                    $response->addFacetCount($name, $value, $count);
                }
            }
        }

        return $response;
    }

    protected function getClient()
    {
        return new SolrClient([
            'hostname' => $this->getSetting('hostname'),
            'port' => $this->getSetting('port'),
            'path' => $this->getSetting('path'),
        ]);
    }
}
