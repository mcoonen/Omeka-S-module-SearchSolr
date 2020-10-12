<?php

/*
 * Copyright BibLibre, 2016-2017
 * Copyright Daniel Berthereau, 2017-2020
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

namespace SearchSolr\Indexer;

use Omeka\Entity\Resource;
use Omeka\Stdlib\Message;
use Search\Indexer\AbstractIndexer;
use Search\Query;
use SearchSolr\Api\Representation\SolrCoreRepresentation;
use Solarium\Client as SolariumClient;
use Solarium\Exception\HttpException as SolariumServerException;
use Solarium\QueryType\Update\Query\Document as SolariumInputDocument;

/**
 * @url https://solarium.readthedocs.io/en/stable/getting-started/
 * @url https://solarium.readthedocs.io/en/stable/documents/
 */
class SolariumIndexer extends AbstractIndexer
{
    /**
     * @var SolrCoreRepresentation
     */
    protected $solrCore;

    /**
     * @var SolariumClient
     */
    protected $solariumClient;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Omeka\Api\Adapter\Manager
     */
    protected $apiAdapters;

    /**
     * @var \Doctrine\ORM\EntityManager $entityManager
     */
    protected $entityManager;

    /**
     * @var \SearchSolr\ValueExtractor\Manager
     */
    protected $valueExtractorManager;

    /**
     * @var \SearchSolr\ValueFormatter\Manager
     */
    protected $valueFormatterManager;

    /**
     * @var array
     */
    protected $formatters = [];

    /**
     * @var int[]
     */
    protected $siteIds;

    /**
     * @var string
     */
    protected $serverId;

    /**
     * @var string|false
     */
    protected $indexField;

    /**
     * @var string
     */
    protected $indexName;

    /**
     * @var string|false
     */
    protected $support;

    /**
     * @var array
     */
    protected $supportFields;

    /**
     * @var array
     */
    protected $vars = [];

    /**
     * @var \Solarium\QueryType\Update\Query\Document[]
     */
    protected $solariumDocuments = [];

    public function canIndex($resourceName)
    {
        return $this->getServiceLocator()
            ->get('SearchSolr\ValueExtractorManager')
            ->has($resourceName);
    }

    public function clearIndex(Query $query = null)
    {
        // Solr does not use the same query format than the one used for select:
        // filter queries cannot be used directly. So use them as query part.

        // Limit deletion to current search index in case of a multi-index core.
        $this->getIndexField();
        $this->getIndexName();

        $isQuery = false;
        if ($query) {
            /** @var \Solarium\QueryType\Select\Query\Query|null $solariumQuery */
            $solariumQuery = $this->index->querier()
                ->setQuery($query)
                ->getPreparedQuery();
            $isQuery = !is_null($solariumQuery);
        }

        if ($isQuery) {
            $query = $solariumQuery->getQuery() ?: '*:*';
            $isDefaultQuery = $query === '*:*';
            $filterQueries = $solariumQuery->getFilterQueries();
            if (count($filterQueries)) {
                foreach ($filterQueries as $filterQuery) {
                    $query .= ' AND ' . $filterQuery->getQuery();
                }
                if ($isDefaultQuery) {
                    $query = mb_substr($query, 8);
                }
            }
        } else {
            $query = $this->indexField
                ? "$this->indexField:$this->indexName"
                : '*:*';
        }

        $client = $this->getClient();
        $update = $client
            ->createUpdate()
            ->addDeleteQuery($query)
            ->addCommit();
        $client->update($update);
        return $this;
    }

    public function indexResource(Resource $resource)
    {
        if (empty($this->api)) {
            $this->init();
        }
        $this->addResource($resource);
        $this->commit();
        return $this;
    }

    public function indexResources(array $resources)
    {
        if (empty($resources)) {
            return $this;
        }
        if (empty($this->api)) {
            $this->init();
        }
        foreach ($resources as $resource) {
            $this->addResource($resource);
        }
        $this->commit();
        return $this;
    }

    public function deleteResource($resourceName, $resourceId)
    {
        // Some values should be init to get the document id.
        $this->getServerId();
        $this->getIndexField();
        $this->getIndexName();

        $documentId = $this->getDocumentId($resourceName, $resourceId);
        $client = $this->getClient();
        $update = $client
            ->createUpdate()
            ->addDeleteById($documentId)
            ->addCommit();
        $client->update($update);
        return $this;
    }

    /**
     * Initialize the indexer.
     *
     * @todo Create/use a full service manager factory.
     */
    protected function init()
    {
        // Some values should be init to get the document id.
        $this->getServerId();
        $this->getIndexField();
        $this->getIndexName();
        $this->getSupportFields();

        $services = $this->getServiceLocator();
        $this->api = $services->get('Omeka\ApiManager');
        $this->apiAdapters = $services->get('Omeka\ApiAdapterManager');
        $this->entityManager = $services->get('Omeka\EntityManager');
        $this->valueExtractorManager = $services->get('SearchSolr\ValueExtractorManager');
        $this->valueFormatterManager = $services->get('SearchSolr\ValueFormatterManager');
        $this->siteIds = $this->api->search('sites', [], ['returnScalar' => 'id'])->getContent();
        return $this;
    }

    protected function getDocumentId($resourceName, $resourceId)
    {
        // Adapted Drupal convention to be used for any single or multi-index.
        // @link https://git.drupalcode.org/project/search_api_solr/-/blob/4.x/solr-conf-templates/8.x/schema.xml#L131-141
        // The 0-formatted id allows to sort quickly on id.
        return $this->indexField
            ? sprintf('%s-%s-%s/%07s', $this->serverId, $this->indexName, $resourceName, $resourceId)
            : sprintf('%s-%s/%07s', $this->serverId, $resourceName, $resourceId);
    }

    protected function addResource(Resource $resource)
    {
        $resourceName = $resource->getResourceName();
        $resourceId = $resource->getId();
        $this->getLogger()->info(sprintf('Indexing resource #%1$s (%2$s)', $resourceId, $resourceName));

        $solrCore = $this->getSolrCore();
        $solrCoreSettings = $solrCore->settings();
        $schema = $solrCore->schema();
        /** @var \SearchSolr\ValueExtractor\ValueExtractorInterface $valueExtractor */
        $valueExtractor = $this->valueExtractorManager->get($resourceName);

        /** @var \Omeka\Api\Representation\AbstractResourceRepresentation $representation */
        $adapter = $this->apiAdapters->get($resourceName);
        $representation = $adapter->getRepresentation($resource);

        $document = new SolariumInputDocument;

        $documentId = $this->getDocumentId($resourceName, $resourceId);
        $document->addField('id', $documentId);

        if ($this->indexField) {
            $document->addField($this->indexField, $this->indexName);
        }

        // Force the indexation of visibility, resource type and sites, even if
        // not selected in mapping, because they are the base of Omeka.

        $isPublicField = $solrCoreSettings['is_public_field'];
        $document->addField($isPublicField, $resource->isPublic());

        $resourceNameField = $solrCoreSettings['resource_name_field'];
        $document->addField($resourceNameField, $resourceName);

        $sitesField = $solrCoreSettings['sites_field'];
        switch ($resourceName) {
            case 'items':
                // There is no method to get the list of sites of an item.
                // TODO Get sites of an items in Omeka 2.2/3.
                foreach ($this->siteIds as $siteId) {
                    $query = ['id' => $resourceId, 'site_id' => $siteId];
                    $res = $this->api->search('items', $query, ['returnScalar' => 'id'])->getContent();
                    if (!empty($res)) {
                        $document->addField($sitesField, $siteId);
                    }
                }
                break;

            case 'item_sets':
                // There is no method to get the list of sites of an item set.
                $qb = $this->entityManager->createQueryBuilder();
                $qb->select('siteItemSet')
                    ->from(\Omeka\Entity\SiteItemSet::class, 'siteItemSet')
                    ->innerJoin('siteItemSet.itemSet', 'itemSet')
                    ->where($qb->expr()->eq('itemSet.id', $resourceId));
                $siteItemSets = $qb->getQuery()->getResult();
                foreach ($siteItemSets as $siteItemSet) {
                    $document->addField($sitesField, $siteItemSet->getSite()->getId());
                }
                break;
            default:
                return;
        }

        foreach ($solrCore->mapsByResourceName($resourceName) as $solrMap) {
            $solrField = $solrMap->fieldName();
            $source = $solrMap->source();

            // Index the required fields one time only except if the admin wants
            // to store it in a different field too.
            if ($source === 'is_public' && $solrField === $isPublicField) {
                continue;
            }
            // The admin can’t modify this parameter via the standard interface.
            if ($source === 'resource_name' && $solrField === $sitesField) {
                continue;
            }
            // The admin can’t modify this parameter via the standard interface.
            if ($source === 'site/o:id' && $solrField === $sitesField) {
                continue;
            }

            $values = $valueExtractor->extractValue($representation, $source);

            // Simplify the loop process for single or multiple values.
            if (!is_array($values)) {
                $values = [$values];
            }

            // Skip null (no resource class...) and empty strings (error).
            $values = array_filter($values, [$this, 'isNotNullAndNotEmptyString']);
            if (empty($values)) {
                continue;
            }

            $schemaField = $schema->getField($solrField);
            if ($schemaField && !$schemaField->isMultivalued()) {
                $values = array_slice($values, 0, 1);
            }

            $formatter = $solrMap->setting('formatter');
            $valueFormatter = $formatter
                // Avoid to load all formatters each time.
                ? ($this->formatters[$formatter] ?? $this->formatters[$formatter] = $this->valueFormatterManager->get($formatter))
                : null;

            $first = $this->support === 'drupal';
            if ($valueFormatter) {
                foreach ($values as $value) {
                    $value = $valueFormatter->format($value);
                    $document->addField($solrField, $value);
                    if ($first) {
                        $first = false;
                        $this->appendDrupalValues($resource, $document, $solrField, $value);
                    }
                }
            } else {
                foreach ($values as $value) {
                    $document->addField($solrField, $value);
                    if ($first) {
                        $first = false;
                        $this->appendDrupalValues($resource, $document, $solrField, $value);
                    }
                }
            }
        }

        $this->appendSupportedFields($resource, $document);

        $this->solariumDocuments[$documentId] = $document;
    }

    protected function appendSupportedFields(Resource $resource, SolariumInputDocument $document)
    {
        foreach ($this->supportFields as $solrField => $value) switch ($solrField) {
            // Drupal.
            case 'index_id':
                // Already set for multi-index, and it is a single value.
                break;
            case 'site':
            case 'hash':
            case 'timestamp':
            case 'boost_document':
            case 'ss_search_api_language':
            case 'sm_context_tags':
                $document->addField($solrField, $value);
                break;
            case 'ss_search_api_datasource':
                $document->addField($solrField, $resource->getResourceName());
                break;
            case 'ss_search_api_id':
                $document->addField($solrField, $resource->getResourceName() . '/' . $resource->getId());
                break;
            default:
                // Nothing to do.
                break;
        }
    }

    /**
     * Check if a value is not null neither an empty string.
     *
     * @param mixed $value
     * @return bool
     */
    protected function isNotNullAndNotEmptyString($value)
    {
        return !is_null($value) && $value !== '';
    }

    /**
     * Commit the prepared documents.
     */
    protected function commit()
    {
        static $warnMsg;

        if (!count($this->solariumDocuments)) {
            $this->getLogger()->notice('No document to commit in Solr.'); // @translate
            return;
        }

        $this->getLogger()->info('Commit index in Solr.'); // @translate
        //  TODO use BufferedAdd plugin?
        $client = $this->getClient();
        try {
            $update = $client
                ->createUpdate()
                ->addDocuments($this->solariumDocuments)
                ->addCommit();
            $client->update($update);
        } catch (SolariumServerException $e) {
            $error = json_decode($e->getBody(), true);
            if (is_array($error) && isset($error['error']['msg'])) {
                $isSingle = count($this->solariumDocuments) <= 1;
                $message = $isSingle
                    ? 'Indexing of resource failed: %s' // @translate
                    : 'Indexing of resources failed: %s'; // @translate
                $message = new Message($message, $error['error']['msg']);
                $this->getLogger()->err($message);
                if (!$isSingle && !$warnMsg) {
                    $warnMsg = true;
                    $message = new Message('Warning: When an error occurs, all documents of the current set are skipped.'); // @translate
                    $this->getLogger()->warn($message);
                }
            } else {
                $this->getLogger()->err($e->getMessage());
            }
        } catch (\Exception $e) {
            $this->getLogger()->err($e->getMessage());
        }

        $this->solariumDocuments = [];
    }

    /**
     * Get the unique server id of the Omeka install.
     *
     * @return string
     */
    protected function getServerId()
    {
        // Inspired from module Drupal search api solr.
        // See search_api_solr\Utility\Utility::getSiteHash()).
        if (is_null($this->serverId)) {
            $settings = $this->getServiceLocator()->get('Omeka\Settings');
            $this->serverId = $settings->get('searchsolr_server_id');
            if (empty($this->serverId)) {
                $this->serverId = strtolower(substr(str_replace(['+', '/'], '', base64_encode(random_bytes(20))), 0, 6));
                $settings->set('searchsolr_server_id', $this->serverId);
            }
        }
        return $this->serverId;
    }

    /**
     * Get the index field name for the multi-index, if set.
     *
     * @return string|false
     */
    protected function getIndexField()
    {
        if (is_null($this->indexField)) {
            $this->indexField = $this->getSolrCore()->setting('index_field', false) ?: false;
        }
        return $this->indexField;
    }

    /**
     * Get the index name of the search index page for multi-index.
     *
     * @return string
     */
    protected function getIndexName()
    {
        if (is_null($this->indexName)) {
            $this->indexName = $this->index->shortName();
        }
        return $this->indexName;
    }

    /**
     * Get the specific supported fields.
     *
     * @return array
     */
    protected function getSupportFields()
    {
        if (is_null($this->supportFields)) {
            $this->support = $this->solrCore->setting('support') ?: false;
            $this->supportFields = array_filter($this->solrCore->schemaSupport($this->support));
            // Manage some static values.
            foreach ($this->supportFields as $solrField => &$value) switch ($solrField) {
                // Drupal.
                // @link https://git.drupalcode.org/project/search_api_solr/-/blob/4.x/solr-conf-templates/8.x/schema.xml
                // @link https://git.drupalcode.org/project/search_api_solr/-/blob/4.x/src/Plugin/search_api/backend/SearchApiSolrBackend.php#L1060-1184
                case 'index_id':
                    $value = $this->indexName;
                    break;
                case 'site':
                    // The site should be the language site one, but urls don't
                    // include locale in Omeka.
                    // TODO Check if the site url should be the default site one or the root of Omeka.
                    $value = $this->solrCore->setting('site_url') ?: 'http://localhost/';
                    break;
                case 'hash':
                    $value = $this->serverId;
                    break;
                case 'timestamp':
                    // If this is It should be the same timestamp for all documents that are being indexed.
                    $value = gmdate('Y-m-d\TH:i:s\Z');
                    break;
                case 'boost_document':
                    // Boost at index time is not supported any more, but Drupal
                    // stores a value in each item and uses it during request.
                    $value = 1.0;
                    break;
                case 'sm_context_tags':
                    // No need to escape already cleaned index values.
                    $value = [
                        'drupal_X2f_langcode_X3a_' . (strtok($this->solrCore->setting('resource_languages'), ' ') ?: 'und'),
                        'search_api_X2f_index_X3a_' . $this->indexName,
                        'search_api_solr_X2f_site_hash_X3a_' . $this->serverId,
                    ];
                    break;
                case 'ss_search_api_language':
                    $value = strtok($this->solrCore->setting('resource_languages'), ' ') ?: 'und';
                    break;
                case 'ss_search_api_datasource':
                case 'ss_search_api_id':
                case 'boost_term':
                default:
                    // Nothing to do.
                    break;
            }
            unset($value);
        }
        if ($this->support === 'drupal') {
            $this->vars['locales'] = explode(' ', $this->solrCore->setting('resource_languages')) ?: ['und'];
            // In Drupal, dynamic fields are in schema.xml, except text ones, in
            // schema_extra_fields.xml.
            $dynamicFields = $this->solrCore->schema()->getDynamicFieldsMapByMainPart('prefix');
            foreach ($this->solrCore->mapsByResourceName() as $resourceName => $solrMaps) {
                $this->vars['solr_maps'][$resourceName] = [];
                foreach ($solrMaps as $solrMap) {
                    $source = $solrMap->source();
                    if (in_array($source, ['is_public', 'resource_name', 'site/o:id'])) {
                        continue;
                    }
                    $solrField = $solrMap->fieldName();
                    // Check if it is a dynamic field (prefix only.
                    $prefix = strtok($solrField, '_') . '_';
                    if (!isset($dynamicFields[$prefix])) {
                        continue;
                    }
                    $this->vars['solr_maps'][$resourceName][$solrField] = [
                        'prefix' => mb_substr($prefix, 0, -1),
                        'name' => mb_substr($solrField, mb_strlen($prefix)),
                    ];
                }
            }
        }
        return $this->supportFields;
    }

    /**
     * Specific fields for Drupal.
     *
     * @param SolariumInputDocument $document
     * @param string $solrField
     * @param mixed $value
     * @return self
     */
    protected function appendDrupalValues(Resource $resource, SolariumInputDocument $document, $solrField, $value)
    {
        $resourceName = $resource->getResourceName();
        if (!isset($this->vars['solr_maps'][$resourceName][$solrField])) {
            return $this;
        }

        // FIXME Use the translated values in Drupal sort fields.
        // In Drupal, the same value is copied in each language field for sort…
        // @link https://git.drupalcode.org/project/search_api_solr/-/blob/4.x/src/Plugin/search_api/backend/SearchApiSolrBackend.php#L1130-1172
        // For example, field is "ss_title", so sort field is "sort_X3b_fr_title".
        // This is slighly different from Drupal process.
        $prefix = $this->vars['solr_maps'][$resourceName][$solrField]['prefix'];
        $matches = [];
        if (in_array(mb_substr($prefix . '_', 0, 3), ['tm_', 'ts_', 'sm_', 'ss_'])) {
            foreach ($this->vars['locales'] as $locale) {
                $field = 'sort_X3b_' . $locale . '_' . $this->vars['solr_maps'][$resourceName][$solrField]['name'];
                if (!$document->{$field}) {
                    $value = mb_substr($value, 0, 128);
                    $document->addField($field, $value);
                }
            }
        } elseif (strpos($solrField, 'random_') !== 0
            && preg_match('/^([a-z]+)m(_.*)/', $solrField, $matches)
        ) {
            $field = $matches[1] . 's' . $matches[2];
            if (!$document->{$field}) {
                $document->addField($field, $value);
            }
        }
        return $this;
    }

    /**
     * @return \SearchSolr\Api\Representation\SolrCoreRepresentation
     */
    protected function getSolrCore()
    {
        if (!isset($this->solrCore)) {
            $solrCoreId = $this->getAdapterSetting('solr_core_id');
            if ($solrCoreId) {
                $api = $this->getServiceLocator()->get('Omeka\ApiManager');
                // Automatically throw an exception when empty.
                $this->solrCore = $api->read('solr_cores', $solrCoreId)->getContent();
                $this->solariumClient = $this->solrCore->solariumClient();
            }
        }
        return $this->solrCore;
    }

    /**
     * @return SolariumClient
     */
    protected function getClient()
    {
        if (!isset($this->solariumClient)) {
            $this->solariumClient = $this->getSolrCore()->solariumClient();
        }
        return $this->solariumClient;
    }
}
