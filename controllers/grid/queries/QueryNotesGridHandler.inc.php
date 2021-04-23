<?php

/**
 * @file controllers/grid/queries/QueryNotesGridHandler.inc.php
 *
 * Copyright (c) 2016-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class QueryNotesGridHandler
 * @ingroup controllers_grid_query
 *
 * @brief base PKP class to handle query grid requests.
 */

// import grid base classes
import('lib.pkp.classes.controllers.grid.GridHandler');
import('lib.pkp.classes.controllers.grid.DataObjectGridCellProvider');

// Link action & modal classes
import('lib.pkp.classes.linkAction.request.AjaxModal');

use PKP\core\JSONMessage;
use PKP\note\NoteDAO;

class QueryNotesGridHandler extends GridHandler
{
    /** @var User */
    public $_user;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->addRoleAssignment(
            [ROLE_ID_MANAGER, ROLE_ID_REVIEWER, ROLE_ID_AUTHOR, ROLE_ID_SUB_EDITOR, ROLE_ID_ASSISTANT],
            ['fetchGrid', 'fetchRow', 'addNote', 'insertNote', 'deleteNote']
        );
    }


    //
    // Getters/Setters
    //
    /**
     * Get the authorized submission.
     *
     * @return Submission
     */
    public function getSubmission()
    {
        return $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION);
    }

    /**
     * Get the query.
     *
     * @return Query
     */
    public function getQuery()
    {
        return $this->getAuthorizedContextObject(ASSOC_TYPE_QUERY);
    }

    /**
     * Get the stage id.
     *
     * @return integer
     */
    public function getStageId()
    {
        return $this->getAuthorizedContextObject(ASSOC_TYPE_WORKFLOW_STAGE);
    }

    //
    // Overridden methods from PKPHandler.
    // Note: this is subclassed in application-specific grids.
    //
    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $stageId = $request->getUserVar('stageId'); // This is being validated in WorkflowStageAccessPolicy

        // Get the access policy
        import('lib.pkp.classes.security.authorization.QueryAccessPolicy');
        $this->addPolicy(new QueryAccessPolicy($request, $args, $roleAssignments, $stageId));
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
        $this->setTitle('submission.query.messages');

        // Load pkp-lib translations
        AppLocale::requireComponents(
            LOCALE_COMPONENT_PKP_SUBMISSION,
            LOCALE_COMPONENT_PKP_USER,
            LOCALE_COMPONENT_PKP_EDITOR
        );

        import('lib.pkp.controllers.grid.queries.QueryNotesGridCellProvider');
        $cellProvider = new QueryNotesGridCellProvider($this->getSubmission());

        // Columns
        $this->addColumn(
            new GridColumn(
                'contents',
                'common.note',
                null,
                null,
                $cellProvider,
                ['width' => 80, 'html' => true]
            )
        );
        $this->addColumn(
            new GridColumn(
                'from',
                'submission.query.from',
                null,
                null,
                $cellProvider,
                ['html' => true]
            )
        );

        $this->_user = $request->getUser();
    }


    //
    // Overridden methods from GridHandler
    //
    /**
     * @copydoc GridHandler::getRowInstance()
     *
     * @return QueryNotesGridRow
     */
    public function getRowInstance()
    {
        import('lib.pkp.controllers.grid.queries.QueryNotesGridRow');
        return new QueryNotesGridRow($this->getRequestArgs(), $this->getQuery(), $this);
    }

    /**
     * Get the arguments that will identify the data in the grid.
     * Overridden by child grids.
     *
     * @return array
     */
    public function getRequestArgs()
    {
        return [
            'submissionId' => $this->getSubmission()->getId(),
            'stageId' => $this->getStageId(),
            'queryId' => $this->getQuery()->getId(),
        ];
    }

    /**
     * @copydoc GridHandler::loadData()
     *
     * @param null|mixed $filter
     */
    public function loadData($request, $filter = null)
    {
        return $this->getQuery()->getReplies(null, NoteDAO::NOTE_ORDER_DATE_CREATED, SORT_DIRECTION_ASC, $this->getCanManage(null));
    }

    //
    // Public Query Notes Grid Actions
    //
    /**
     * Present the form to add a new note.
     *
     * @param $args array
     * @param $request PKPRequest
     */
    public function addNote($args, $request)
    {
        import('lib.pkp.controllers.grid.queries.form.QueryNoteForm');
        $queryNoteForm = new QueryNoteForm($this->getRequestArgs(), $this->getQuery(), $request->getUser());
        $queryNoteForm->initData();
        return new JSONMessage(true, $queryNoteForm->fetch($request));
    }

    /**
     * Insert a new note.
     *
     * @param $args array
     * @param $request PKPRequest
     */
    public function insertNote($args, $request)
    {
        import('lib.pkp.controllers.grid.queries.form.QueryNoteForm');
        $queryNoteForm = new QueryNoteForm($this->getRequestArgs(), $this->getQuery(), $request->getUser(), $request->getUserVar('noteId'));
        $queryNoteForm->readInputData();
        if ($queryNoteForm->validate()) {
            $note = $queryNoteForm->execute();
            return \PKP\db\DAO::getDataChangedEvent($this->getQuery()->getId());
        } else {
            return new JSONMessage(true, $queryNoteForm->fetch($request));
        }
    }

    /**
     * Determine whether the current user can manage (delete) a note.
     *
     * @param $note Note optional
     *
     * @return boolean
     */
    public function getCanManage($note)
    {
        $isAdmin = (0 != count(array_intersect(
            $this->getAuthorizedContextObject(ASSOC_TYPE_USER_ROLES),
            [ROLE_ID_MANAGER, ROLE_ID_ASSISTANT, ROLE_ID_SUB_EDITOR]
        )));

        if ($note === null) {
            return $isAdmin;
        } else {
            return ($note->getUserId() == $this->_user->getId() || $isAdmin);
        }
    }

    /**
     * Delete a query note.
     *
     * @param $args array
     * @param $request PKPRequest
     *
     * @return JSONMessage JSON object
     */
    public function deleteNote($args, $request)
    {
        $query = $this->getQuery();
        $noteDao = DAORegistry::getDAO('NoteDAO'); /** @var NoteDAO $noteDao */
        $note = $noteDao->getById($request->getUserVar('noteId'));
        $user = $request->getUser();

        if (!$request->checkCSRF() || !$note || $note->getAssocType() != ASSOC_TYPE_QUERY || $note->getAssocId() != $query->getId()) {
            // The note didn't exist or has the wrong assoc info.
            return new JSONMessage(false);
        }

        if (!$this->getCanManage($note)) {
            // The user doesn't own the note and isn't priveleged enough to delete it.
            return new JSONMessage(false);
        }

        $noteDao->deleteObject($note);
        return \PKP\db\DAO::getDataChangedEvent($note->getId());
    }
}
