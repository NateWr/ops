<?php

/**
 * @file controllers/grid/submissions/ExportPublishedSubmissionsListGridHandler.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ExportPublishedSubmissionsListGridHandler
 * @ingroup controllers_grid_submissions
 *
 * @brief Handle exportable published submissions list grid requests.
 */

use PKP\controllers\grid\GridHandler;
use PKP\controllers\grid\GridColumn;
use PKP\security\authorization\PolicySet;
use PKP\security\authorization\RoleBasedHandlerOperationPolicy;

class ExportPublishedSubmissionsListGridHandler extends GridHandler
{
    /** @var ImportExportPlugin */
    public $_plugin;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->addRoleAssignment(
            [ROLE_ID_MANAGER],
            ['fetchGrid', 'fetchRow']
        );
    }

    //
    // Implement template methods from PKPHandler
    //
    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $rolePolicy = new PolicySet(PolicySet::COMBINING_PERMIT_OVERRIDES);

        foreach ($roleAssignments as $role => $operations) {
            $rolePolicy->addPolicy(new RoleBasedHandlerOperationPolicy($request, $role, $operations));
        }
        $this->addPolicy($rolePolicy);

        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * @copydoc GridHandler::initialize()
     *
     * @param null|mixed $args
     */
    public function initialize($request, $args = null)
    {
        parent::initialize($request, $args);
        $context = $request->getContext();

        // Basic grid configuration.
        $this->setTitle('plugins.importexport.common.export.preprints');

        // Load submission-specific translations.
        AppLocale::requireComponents(
            LOCALE_COMPONENT_APP_SUBMISSION, // title filter
            LOCALE_COMPONENT_PKP_SUBMISSION, // authors filter
            LOCALE_COMPONENT_APP_MANAGER
        );

        $pluginCategory = $request->getUserVar('category');
        $pluginPathName = $request->getUserVar('plugin');
        $this->_plugin = PluginRegistry::loadPlugin($pluginCategory, $pluginPathName);
        assert(isset($this->_plugin));

        // Grid columns.
        $cellProvider = $this->getGridCellProvider();
        $this->addColumn(
            new GridColumn(
                'id',
                null,
                __('common.id'),
                'controllers/grid/gridCell.tpl',
                $cellProvider,
                ['alignment' => GridColumn::COLUMN_ALIGNMENT_LEFT,
                    'width' => 10]
            )
        );
        $this->addColumn(
            new GridColumn(
                'title',
                'grid.submission.itemTitle',
                null,
                null,
                $cellProvider,
                ['html' => true,
                    'alignment' => GridColumn::COLUMN_ALIGNMENT_LEFT]
            )
        );
        if (method_exists($this, 'addAdditionalColumns')) {
            $this->addAdditionalColumns($cellProvider);
        }
        $this->addColumn(
            new GridColumn(
                'status',
                'common.status',
                null,
                null,
                $cellProvider,
                ['alignment' => GridColumn::COLUMN_ALIGNMENT_LEFT,
                    'width' => 10]
            )
        );
    }


    //
    // Implemented methods from GridHandler.
    //
    /**
     * @copydoc GridHandler::initFeatures()
     */
    public function initFeatures($request, $args)
    {
        import('lib.pkp.classes.controllers.grid.feature.selectableItems.SelectableItemsFeature');
        import('lib.pkp.classes.controllers.grid.feature.PagingFeature');
        return [new SelectableItemsFeature(), new PagingFeature()];
    }

    /**
     * @copydoc GridHandler::getRequestArgs()
     */
    public function getRequestArgs()
    {
        return array_merge(parent::getRequestArgs(), ['category' => $this->_plugin->getCategory(), 'plugin' => basename($this->_plugin->getPluginPath())]);
    }

    /**
     * @copydoc GridHandler::isDataElementSelected()
     */
    public function isDataElementSelected($gridDataElement)
    {
        return false; // Nothing is selected by default
    }

    /**
     * @copydoc GridHandler::getSelectName()
     */
    public function getSelectName()
    {
        return 'selectedSubmissions';
    }

    /**
     * @copydoc GridHandler::getFilterForm()
     */
    protected function getFilterForm()
    {
        return 'controllers/grid/submissions/exportPublishedSubmissionsGridFilter.tpl';
    }

    /**
     * @copydoc GridHandler::renderFilter()
     */
    public function renderFilter($request, $filterData = [])
    {
        $context = $request->getContext();
        $statusNames = $this->_plugin->getStatusNames();
        $filterColumns = $this->getFilterColumns();
        $allFilterData = array_merge(
            $filterData,
            [
                'columns' => $filterColumns,
                'status' => $statusNames,
                'gridId' => $this->getId(),
            ]
        );
        return parent::renderFilter($request, $allFilterData);
    }

    /**
     * @copydoc GridHandler::getFilterSelectionData()
     */
    public function getFilterSelectionData($request)
    {
        $search = (string) $request->getUserVar('search');
        $column = (string) $request->getUserVar('column');
        $statusId = (string) $request->getUserVar('statusId');
        return [
            'search' => $search,
            'column' => $column,
            'statusId' => $statusId,
        ];
    }

    /**
     * @copydoc GridHandler::loadData()
     */
    protected function loadData($request, $filter)
    {
        $context = $request->getContext();
        [$search, $column, $statusId] = $this->getFilterValues($filter);
        $title = $author = null;
        if ($column == 'title') {
            $title = $search;
        } elseif ($column == 'author') {
            $author = $search;
        }
        $pubIdStatusSettingName = null;
        if ($statusId) {
            $pubIdStatusSettingName = $this->_plugin->getDepositStatusSettingName();
        }
        $submissionDao = DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao SubmissionDAO */
        return $submissionDao->getExportable(
            $context->getId(),
            null,
            $title,
            $author,
            $pubIdStatusSettingName,
            $statusId,
            $this->getGridRangeInfo($request, $this->getId())
        );
    }


    //
    // Own protected methods
    //
    /**
     * Get which columns can be used by users to filter data.
     *
     * @return array
     */
    protected function getFilterColumns()
    {
        return [
            'title' => __('submission.title'),
            'author' => __('submission.authors')
        ];
    }

    /**
     * Process filter values, assigning default ones if
     * none was set.
     *
     * @return array
     */
    protected function getFilterValues($filter)
    {
        if (isset($filter['search']) && $filter['search']) {
            $search = $filter['search'];
        } else {
            $search = null;
        }
        if (isset($filter['column']) && $filter['column']) {
            $column = $filter['column'];
        } else {
            $column = null;
        }
        if (isset($filter['statusId']) && $filter['statusId'] != EXPORT_STATUS_ANY) {
            $statusId = $filter['statusId'];
        } else {
            $statusId = null;
        }
        return [$search, $column, $statusId];
    }

    /**
     * Get the grid cell provider instance
     *
     * @return DataObjectGridCellProvider
     */
    public function getGridCellProvider()
    {
        // Fetch the authorized roles.
        $authorizedRoles = $this->getAuthorizedContextObject(ASSOC_TYPE_USER_ROLES);
        import('controllers.grid.submissions.ExportPublishedSubmissionsListGridCellProvider');
        return new ExportPublishedSubmissionsListGridCellProvider($this->_plugin, $authorizedRoles);
    }
}
