<?php

/*
 * Copyright BibLibre, 2017
 * Copyright Daniel Berthereau, 2017-2020
 * Copyright Paul Sarrassat, 2018
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

namespace SearchSolr\Controller\Admin;

use Doctrine\DBAL\Connection;
use Omeka\Form\ConfirmForm;
use Omeka\Stdlib\Message;
use Search\Api\Representation\SearchIndexRepresentation;
use Search\Api\Representation\SearchPageRepresentation;
use SearchSolr\Api\Representation\SolrCoreRepresentation;
use SearchSolr\Form\Admin\SolrMapForm;
use SearchSolr\ValueExtractor\Manager as ValueExtractorManager;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use SearchSolr\Api\Representation\SolrMapRepresentation;

class MapController extends AbstractActionController
{
    /**
     * @var ValueExtractorManager
     */
    protected $valueExtractorManager;

    /**
     * @var Connection
     */
    protected $connection;

    public function setValueExtractorManager(ValueExtractorManager $valueExtractorManager)
    {
        $this->valueExtractorManager = $valueExtractorManager;
    }

    public function setConnection(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function browseAction()
    {
        $solrCoreId = $this->params('coreId');
        $solrCore = $this->api()->read('solr_cores', $solrCoreId)->getContent();

        $valueExtractors = [];
        foreach ($this->valueExtractorManager->getRegisteredNames() as $name) {
            $valueExtractors[$name] = $this->valueExtractorManager->get($name);
        }

        $view = new ViewModel;
        $view->setVariable('solrCore', $solrCore);
        $view->setVariable('valueExtractors', $valueExtractors);

        return $view;
    }

    public function browseResourceAction()
    {
        $solrCoreId = $this->params('coreId');
        $resourceName = $this->params('resourceName');

        $solrCore = $this->api()->read('solr_cores', $solrCoreId)->getContent();
        $maps = $this->api()->search('solr_maps', [
            'solr_core_id' => $solrCore->id(),
            'resource_name' => $resourceName,
        ])->getContent();

        $view = new ViewModel;
        $view->setVariable('solrCore', $solrCore);
        $view->setVariable('resourceName', $resourceName);
        $view->setVariable('maps', $maps);
        return $view;
    }

    public function completeAction()
    {
        $solrCoreId = $this->params('coreId');
        $resourceName = $this->params('resourceName');

        $solrCore = $this->api()->read('solr_cores', $solrCoreId)->getContent();

        $api = $this->api();

        // Get all existing indexed properties.
        /** @var \SearchSolr\Api\Representation\SolrMapRepresentation[] $maps */
        $maps = $api->search('solr_maps', [
            'solr_core_id' => $solrCore->id(),
            'resource_name' => $resourceName,
        ])->getContent();
        // Keep only the source.
        $maps = array_map(function ($v) {
            return $v->source();
        }, $maps);

        // Add all missing maps.
        $result = [];
        $properties = $api->search('properties')->getContent();
        $usedPropertyIds = $this->listUsedPropertyIds($resourceName);
        foreach ($properties as $property) {
            // Skip property that are not used.
            if (!in_array($property->id(), $usedPropertyIds)) {
                continue;
            }
            $term = $property->term();
            // Skip property that are already mapped.
            if (in_array($term, $maps)) {
                continue;
            }

            $data = [];
            $data['o:solr_core']['o:id'] = $solrCoreId;
            $data['o:resource_name'] = $resourceName;
            $data['o:field_name'] = str_replace(':', '_', $term) . '_t';
            $data['o:source'] = $term;
            $data['o:settings'] = ['formatter' => '', 'label' => $property->label()];
            $api->create('solr_maps', $data);

            $result[] = $term;
        }

        if ($result) {
            $this->messenger()->addSuccess(new Message('%d maps successfully created: "%s".', // @translate
                count($result), implode('", "', $result)));
            $this->messenger()->addWarning('Check all new maps and remove useless ones.'); // @translate
            $this->messenger()->addNotice('Don’t forget to run the indexation of the core.'); // @translate
        } else {
            $this->messenger()->addWarning('No new maps added.'); // @translate
        }

        return $this->redirect()->toRoute('admin/search/solr/core-id-map-resource', [
            'coreId' => $solrCoreId,
            'resourceName' => $resourceName,
        ]);
    }

    public function cleanAction()
    {
        $solrCoreId = $this->params('coreId');
        $resourceName = $this->params('resourceName');

        $solrCore = $this->api()->read('solr_cores', $solrCoreId)->getContent();

        $api = $this->api();

        // Get all existing indexed properties.
        /** @var \SearchSolr\Api\Representation\SolrMapRepresentation[] $maps */
        $maps = $api->search('solr_maps', [
            'solr_core_id' => $solrCore->id(),
            'resource_name' => $resourceName,
        ])->getContent();
        // Map as associative array by map id and keep only the source.
        $mapList = [];
        foreach ($maps as $map) {
            $mapList[$map->id()] = $map->source();
        }
        $maps = $mapList;

        // Add all missing maps.
        $result = [];
        $properties = $api->search('properties')->getContent();
        $usedPropertyIds = $this->listUsedPropertyIds($resourceName);
        foreach ($properties as $property) {
            // Skip property that are used.
            if (in_array($property->id(), $usedPropertyIds)) {
                continue;
            }
            $term = $property->term();
            // Skip property that are not mapped.
            if (!in_array($term, $maps)) {
                continue;
            }

            // There may be multiple maps with the same term.
            $ids = array_keys(array_filter($maps, function ($v) use ($term) {
                return $v === $term;
            }));
            $api->batchDelete('solr_maps', $ids);

            $result[] = $term;
        }

        if ($result) {
            $this->messenger()->addSuccess(new Message('%d maps successfully deleted: "%s".', // @translate
                count($result), implode('", "', $result)));
            $this->messenger()->addNotice('Don’t forget to run the indexation of the core.'); // @translate
        } else {
            $this->messenger()->addWarning('No maps deleted.'); // @translate
        }

        return $this->redirect()->toRoute('admin/search/solr/core-id-map-resource', [
            'coreId' => $solrCoreId,
            'resourceName' => $resourceName,
        ]);
    }

    public function addAction()
    {
        $solrCoreId = $this->params('coreId');
        $resourceName = $this->params('resourceName');

        $solrCore = $this->api()->read('solr_cores', $solrCoreId)->getContent();

        $form = $this->getForm(SolrMapForm::class, [
            'solr_core_id' => $solrCoreId,
            'resource_name' => $resourceName,
        ]);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            if ($form->isValid()) {
                $data = $form->getData();
                $data['o:source'] = $this->sourceArrayToString($data['o:source']);
                $data['o:solr_core']['o:id'] = $solrCoreId;
                $data['o:resource_name'] = $resourceName;
                $this->api()->create('solr_maps', $data);

                $this->messenger()->addSuccess(new Message('Solr map created: %s.', // @translate
                    $data['o:field_name']));

                return $this->redirect()->toRoute('admin/search/solr/core-id-map-resource', [
                    'coreId' => $solrCoreId,
                    'resourceName' => $resourceName,
                ]);
            } else {
                $messages = $form->getMessages();
                if (isset($messages['csrf'])) {
                    $this->messenger()->addError('Invalid or missing CSRF token'); // @translate
                } else {
                    $this->messenger()->addError('There was an error during validation'); // @translate
                }
            }
        }

        $view = new ViewModel;
        $view->setVariable('solrCore', $solrCore);
        $view->setVariable('form', $form);
        $view->setVariable('schema', $this->getSolrSchema($solrCoreId));
        $view->setVariable('sourceLabels', $this->getSourceLabels());
        return $view;
    }

    public function editAction()
    {
        $solrCoreId = $this->params('coreId');
        $resourceName = $this->params('resourceName');
        $id = $this->params('id');

        /** @var \SearchSolr\Api\Representation\SolrMapRepresentation $map */
        $map = $this->api()->read('solr_maps', $id)->getContent();

        $form = $this->getForm(SolrMapForm::class, [
            'solr_core_id' => $solrCoreId,
            'resource_name' => $resourceName,
        ]);
        $mapData = $map->jsonSerialize();
        $mapData['o:source'] = $this->sourceStringToArray($mapData['o:source']);
        $form->setData($mapData);

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            if ($form->isValid()) {
                $data = $form->getData();
                $data['o:source'] = $this->sourceArrayToString($data['o:source']);
                $data['o:solr_core']['o:id'] = $solrCoreId;
                $data['o:resource_name'] = $resourceName;
                $this->api()->update('solr_maps', $id, $data);

                $this->messenger()->addSuccess(new Message('Solr map modified: %s.', // @translate
                    $data['o:field_name']));

                $this->messenger()->addWarning('Don’t forget to check search pages that use this map.'); // @translate

                return $this->redirect()->toRoute('admin/search/solr/core-id-map-resource', [
                    'coreId' => $solrCoreId,
                    'resourceName' => $resourceName,
                ]);
            } else {
                $messages = $form->getMessages();
                if (isset($messages['csrf'])) {
                    $this->messenger()->addError('Invalid or missing CSRF token'); // @translate
                } else {
                    $this->messenger()->addError('There was an error during validation'); // @translate
                }
            }
        }

        $view = new ViewModel;
        $view->setVariable('map', $map);
        $view->setVariable('form', $form);
        $view->setVariable('schema', $this->getSolrSchema($solrCoreId));
        $view->setVariable('sourceLabels', $this->getSourceLabels());
        return $view;
    }

    public function deleteConfirmAction()
    {
        $id = $this->params('id');
        $response = $this->api()->read('solr_maps', $id);
        $map = $response->getContent();

        $searchPages = $this->searchSearchPages($map->solrCore());
        $searchPagesUsingMap = [];
        foreach ($searchPages as $searchPage) {
            if ($this->doesSearchPageUseMap($searchPage, $map)) {
                $searchPagesUsingMap[] = $searchPage;
            }
        }

        $view = new ViewModel;
        $view->setTerminal(true);
        $view->setTemplate('common/delete-confirm-details');
        $view->setVariable('resourceLabel', 'Solr map'); // @translate
        $view->setVariable('resource', $map);
        $view->setVariable('partialPath', 'common/solr-map-delete-confirm-details');
        $view->setVariable('totalSearchPages', count($searchPages));
        $view->setVariable('totalSearchPagesUsingMap', count($searchPagesUsingMap));
        return $view;
    }

    public function deleteAction()
    {
        $id = $this->params('id');
        $map = $this->api()->read('solr_maps', $id)->getContent();

        if ($this->getRequest()->isPost()) {
            $form = $this->getForm(ConfirmForm::class);
            $form->setData($this->getRequest()->getPost());
            if ($form->isValid()) {
                $this->api()->delete('solr_maps', $id);
                $this->messenger()->addSuccess('Solr map successfully deleted'); // @translate
                $this->messenger()->addWarning('Don’t forget to check search pages that used this map.'); // @translate
            } else {
                $this->messenger()->addError('Solr map could not be deleted'); // @translate
            }
        }

        return $this->redirect()->toRoute('admin/search/solr/core-id-map-resource', [
            'coreId' => $map->solrCore()->id(),
            'resourceName' => $map->resourceName(),
        ]);
    }

    protected function getSolrSchema($solrCoreId)
    {
        $solrCore = $this->api()->read('solr_cores', $solrCoreId)->getContent();
        return $solrCore->schema()->getSchema();
    }

    protected function getSourceLabels()
    {
        $sourceLabels = [
            'id' => 'Internal identifier',
            'is_public' => 'Public', // @translate
            'is_open' => 'Is open', // @translate
            'created' => 'Created', // @translate
            'modified' => 'Modified', // @translate
            'resource_class' => 'Resource class', // @translate
            'resource_template' => 'Resource template', // @translate
            'item_set' => 'Item set', // @translate
            'item' => 'Item', // @translate
            'media' => 'Media', // @translate
        ];

        $propertyLabels = [];
        $result = $this->api()->search('properties')->getContent();
        foreach ($result as $property) {
            $propertyLabels[$property->term()] = ucfirst($property->label());
        }

        $sourceLabels += $propertyLabels;
        return $sourceLabels;
    }

    /**
     * Find all search indexes related to a specific solr core.
     *
     * @todo Factorize with CoreController::searchSearchIndexes()
     * @param SolrCoreRepresentation $solrCore
     * @return SearchIndexRepresentation[] Result is indexed by id.
     */
    protected function searchSearchIndexes(SolrCoreRepresentation $solrCore)
    {
        $result = [];
        $api = $this->api();
        $searchIndexes = $api->search('search_indexes', ['adapter' => 'solarium'])->getContent();
        foreach ($searchIndexes as $searchIndex) {
            $searchIndexSettings = $searchIndex->settings();
            if ($solrCore->id() == $searchIndexSettings['adapter']['solr_core_id']) {
                $result[$searchIndex->id()] = $searchIndex;
            }
        }
        return $result;
    }

    /**
     * Find all search pages related to a specific solr core.
     *
     * @todo Factorize with CoreController::searchSearchPages()
     * @param SolrCoreRepresentation $solrCore
     * @return SearchPageRepresentation[] Result is indexed by id.
     */
    protected function searchSearchPages(SolrCoreRepresentation $solrCore)
    {
        // TODO Use entity manager to simplify search of pages from core.
        $result = [];
        $api = $this->api();
        $searchIndexes = $this->searchSearchIndexes($solrCore);
        foreach ($searchIndexes as $searchIndex) {
            $searchPages = $api->search('search_pages', ['index_id' => $searchIndex->id()])->getContent();
            foreach ($searchPages as $searchPage) {
                $result[$searchPage->id()] = $searchPage;
            }
        }
        return $result;
    }

    /**
     * Check if a search page use a map enabled as facet or sort field.
     *
     * @param SearchPageRepresentation $searchPage
     * @param SolrMapRepresentation $solrMap
     * @return bool
     */
    protected function doesSearchPageUseMap(
        SearchPageRepresentation $searchPage,
        SolrMapRepresentation $solrMap
    ) {
        $searchPageSettings = $searchPage->settings();
        $fieldName = $solrMap->fieldName();
        foreach ($searchPageSettings as $value) {
            if (is_array($value)) {
                if (!empty($value[$fieldName]['enabled'])
                    || !empty($value[$fieldName . ' asc']['enabled'])
                    || !empty($value[$fieldName . ' desc']['enabled'])
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Get all used properties.
     *
     * @param string $resourceName
     * @return \Omeka\Api\Representation\PropertyRepresentation[]
     */
    protected function listUsedPropertyIds($resourceName)
    {
        $resourceTypes = [
            'items' => \Omeka\Entity\Item::class,
            'item_sets' => \Omeka\Entity\ItemSet::class,
        ];

        $qb = $this->connection->createQueryBuilder()
            ->select('DISTINCT value.property_id')
            ->from('value', 'value')
            ->join('value', 'resource', 'resource', 'resource.id = value.resource_id')
            ->where('resource.resource_type = :resource_type')
            ->setParameter('resource_type', $resourceTypes[$resourceName])
            ->orderBy('value.property_id', 'ASC');

        return $this->connection
            ->executeQuery($qb, $qb->getParameters())
            ->fetchAll(\PDO::FETCH_COLUMN, 0);
    }

    /**
     * Convert an array of sources into a string of sources separated by "/".
     *
     * @example
     * Turns:
     * <code>
     * [
     *     0 => ['source' => "foo"],
     *     1 => ['source' => "bar"],
     * ]
     * </code>
     * into:
     * <code>
     * "foo/bar"
     * </code>
     *
     * @param array $source
     */
    protected function sourceArrayToString($source)
    {
        return implode(
            '/',
            array_map(
                function ($v) {
                    return $v['source'];
                },
                $source
            )
        );
    }

    /**
     * Convert a string of sources separated by "/" into an array of sources.
     *
     * @see self::sourceArrayToString()
     *
     * @param array $source
     */
    protected function sourceStringToArray($source)
    {
        return array_map(
            function ($v) {
                return ['source' => $v];
            },
            explode('/', $source)
        );
    }
}