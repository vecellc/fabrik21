<?php
/**
 * @package Joomla
 * @subpackage Fabrik
 * @copyright Copyright (C) 2005 Rob Clayburn. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 */

// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die();

jimport('joomla.application.component.model');

class FabrikModelForm extends JModel
{

	/* not used in database (need to be prefixed with "_")*/
	/** @var array form's group elements*/
	var $_elements = null;

	/** @var object table model assocated with form*/
	var $_listModel = null;

	/** @var array of group ids that are actually tablejoins [groupid->joinid]*/
	var $_aJoinGroupIds = array();

	/** @var bol true if editable if 0 then show view only verion of form */
	var $_editable = 1;

	/** @var string encoding type */
	var $_enctype = "application/x-www-form-urlencoded";

	/** @var array validation rule classes */
	var $_validationRuleClasses = null;

	/**@var bol is the form running as a mambot or module(true)*/
	var $_isMambot = false;

	/** @var array of join objects for the form */
	var $_aJoinObjs = array();

	var $_joinTableElementStep = '___';

	/** @var object parameters */
	var $_params = null;

	/** @var string form groups */
	var $_formGroupStr = null;

	/** @var int row id to submit */
	var $_rowId = null;

	/** @var string method to use when submitting form data // post or ajax*/
	var $_postMethod = null;

	/** @var int id */
	var $_id = null;

	/** @var object form **/
	var $_form = null;

	/** @var object last current element found in hasElement()*/
	var $_currentElement = null;

	/** @var bol if true encase table and element names with "`" when getting elemenet list */
	var $_addDbQuote = false;

	var $_formData = null;

	/** @var array form errors */
	var $_arErrors = array();

	/** @var object uploader helper */
	var $_oUploader = null;

	/** @var array pages (array containing group ids for each page in the form **/
	var $pages = null;

	/** @var object session model deals with storing incomplete pages **/
	var $sessionModel = null;

	/** @var array modified data by any validation rule that uses replace functionality */
	var $_modifiedValidationData = null;

	var $groups = null;

	/** store the form's previous data when processing */
	var $_origData = null;

	/** @var array stores elements not shown in table **/
	var $_elementsNotInTable = null;

	var $_data = null;

	var $_formDataWithTableName = null;

	/** @var int set to -1 if form in ajax module, set to 1+ if in package */
	var $_packageId = 0;

	/** @var bool should the form store the main row? Set to false in juser plugin if fabrik table is also jos_users */
	var $_storeMainRow = true;

	/** @var string query used to load form record */
	var $query = null;

	/** #var array specifies element name that have been overridden from a form plugin, so encrypted RO data should be ignored */
	var $_pluginUpdatedElements = array();

	/**
	 * Constructor
	 *
	 * @since 1.5
	 */

	function __construct()
	{
		parent::__construct();
		$usersConfig = &JComponentHelper::getParams('com_fabrik');
		$id = JRequest::getInt('fabrik', $usersConfig->get('fabrik'));
		$this->setId($id);
	}

	/**
	 * Method to set the form id
	 *
	 * @access	public
	 * @param	int	table ID number
	 */

	function setId($id)
	{
		// Set new form ID
		$this->_id = $id;
	}

	/**
	 * Method to get the form id
	 *
	 * @access	public
	 */
	function getId()
	{
		return (int)$this->_id;
	}

	/**
	 * get form table (alias to getTable())
	 *
	 * @return object form table
	 */

	function &getForm()
	{
		return $this->getTable();
	}

	/**
	 * checks if the params object has been created and if not creates and returns it
	 * @return object params
	 */

	function &getParams()
	{
		if (!isset($this->_params)) {
			$form =& $this->getForm();
			$this->_params = new fabrikParams($form->attribs, JPATH_SITE . '/administrator/components/com_fabrik/xml/form.xml', 'component');
		}
		return $this->_params;
	}

	/**
	 * makes sure that the form is not viewable based on the table's access settings
	 * @return int 0 = no access, 1 = view only , 2 = full form view, 3 = add record only
	 */

	function checkAccessFromTableSettings()
	{
		$form =& $this->getForm();
		if ($form->record_in_database == 0) {
			return 2;
		}
		$listModel =& $this->getListModel();
		if (!is_object($listModel)) {
			return 2;
		}
		$ret = 0;
		if ($listModel->canViewDetails()) {
			$ret = 1;
		}
		/* new form can we add?*/
		if ($this->_rowId == 0 || JRequest::getVar('rowid') == '-1') {
			/*if they can edit can they also add?*/
			if ($listModel->canAdd()) {
				$ret = 3;
			}
			// $$$ hugh - corner case for rowid=-1, where they DON'T have add perms, but DO have edit perms
			else if (JRequest::getVar('rowid') == '-1' && $listModel->canEdit($this->_data)) {
				$ret = 2;
			}
		} else {
			/*editing from - can we edit?*/
			if ($listModel->canEdit($this->_data)) {
				$ret = 2;
			}
		}
		//$$$rob refractored from view
		$this->_editable = ($ret == 1 && $this->_editable == '1') ? false : true;
		if (JRequest::getVar('view', 'form') == 'details') {
			$this->_editable = false;
		}
		return $ret;
	}

	/**
	 * loads form's css files
	 * Checks : custom css file, template css file. Including them if found
	 */

	function getFormCss()
	{
		$tmpl = $this->getForm()->form_template;
		/* check for a custom css file */
		if (JRequest::getVar('incformcss', 1) == 1) {
			FabrikHelperHTML::stylesheet('form.css', 'media/com_fabrik/css/');
		}
		//check and add a general fabrik custom css file overrides generic form css
		FabrikHelperHTML::stylesheetFromPath("media".DS."com_fabrik".DS."css".DS."custom.css");
		FabrikHelperHTML::stylesheetFromPath("components".DS."com_fabrik".DS."css".DS.$this->getId().".css");

		/* check for a form template file (code moved from view) */
		if ($tmpl != '') {
			FabrikHelperHTML::stylesheetFromPath("components".DS."com_fabrik".DS.'views'.DS.'form'.DS.'tmpl'.DS.$tmpl.DS."template.css");
			//check and add a custom form template css
			FabrikHelperHTML::stylesheetFromPath("components".DS."com_fabrik".DS.'views'.DS.'form'.DS.'tmpl'.DS.$tmpl.DS."custom.css");
		}
		$app =& JFactory::getApplication();
		if ($app->isAdmin() && JRequest::getVar('tmpl') === 'components') {
			FabrikHelperHTML::stylesheet('system.css', 'administrator/templates/system/css/');
		}
	}

	/**
	 * load the JS files into the document
	 * @return null
	 */

	function getCustomJsAction()
	{
		if (file_exists(COM_FABRIK_FRONTEND.DS.'js'.DS.$this->getId().".js")) {
			FabrikHelperHTML::script($this->getId() . ".js", 'components/com_fabrik/js/');
		}
		if (file_exists(COM_FABRIK_FRONTEND.DS.'js'.DS.'form_'.$this->getId().".js")) {
			FabrikHelperHTML::script('form_' . $this->getId() . ".js", 'components/com_fabrik/js/');
		}
	}

	/**
	 * set the page title for form
	 * @return string page title
	 */

	function getPageTitle($title = '')
	{
		$params =& $this->getParams();
		$label = $this->getLabel();
		if (JRequest::getVar('view') == 'details') {
			if (!$params->get('show-title-in-detail-view', true)) {
				$title = '';
			} else {
				$title = ($title == "") ? $label : $title . " ";
			}
		} else {
			$title = ($title == "") ? $label : $title . " ";
		}
		$groups =& $this->getGroupsHiarachy();
		foreach ($groups as $groupModel) {
			$elementModels =& $groupModel->getPublishedElements();
			foreach ($elementModels as $elementModel) {
				$element =& $elementModel->getElement();
				if ($element->use_in_page_title == '1') {
					$default = $elementModel->getTitlePart($this->_data);
					$s = is_array($default) ? implode(', ', $default) . " " : $default . " ";
					$title .= ' ' . $s;
				}
			}
		}
		return $title;
	}

	/**
	 * compares the forms table with its groups to see if any of the groups are in fact table joins
	 * @param array tables joins
	 * @return array array(group_id =>join_id)
	 */

	function getJoinGroupIds($joins)
	{
		$arJoinGroupIds = array();
		$groups =& $this->getGroupsHiarachy();
		foreach ($groups as $groupModel) {
			foreach ($joins as $join) {
				if ($join->element_id == 0 && $groupModel->getGroup()->id == $join->group_id) {
					$arJoinGroupIds[$groupModel->_id] = $join->id;
				}
			}
		}
		$this->_aJoinGroupIds = $arJoinGroupIds;
		return $arJoinGroupIds;
	}

	/**
	 * gets the javascript actions the forms elements
	 * @return array of javascript actions
	 */

	function getJsActions()
	{
		$db =& JFactory::getDBO();
		$j = new JRegistry();
		$aJsActions = array();
		$aElIds = array();
		$groups =& $this->getGroupsHiarachy();
		foreach ($groups as $groupModel) {
			$elementModels =& $groupModel->getPublishedElements();
			foreach ($elementModels as $elementModel) {
				// $$$ hugh - only needed getParent when we weren't saving changes to parent params to child
				// which we should now be doing ... and getParent() causes an extra table lookup for every child
				// element on the form.
				$aJsActions[$elementModel->getElement()->id] = array();
				$aElIds[] = (int)$elementModel->getElement()->id;
			}
		}
		if (!empty($aElIds)) {
			$sql = 'SELECT * FROM #__fabrik_jsactions WHERE element_id IN (' . implode(',', $aElIds) . ')';
			$db->setQuery($sql);
			$res = $db->loadObjectList();
		} else {
			$res = array();
		}
		if (is_array($res)) {
			foreach ($res as $r) {

				//merge the js attribs back into the array
				$j->loadINI($r->attribs);
				$a = $j->toArray();
				foreach ($a as $k=>$v) {
					$r->$k = $v;
				}
				unset($r->attribs);
				$aJsActions[$r->element_id][] = $r;
			}
		}
		return $aJsActions;
	}

	/**
	 * test to try to load all group data in one query and then bind that data to group table objects
	 * in getGroups()
	 */

	function getPublishedGroups()
	{
		if (!isset($this->_publishedformGroups) || empty($this->_publishedformGroups)) {
			$db =& JFactory::getDBO();
			$params =& $this->getParams();
			$sql = "SELECT *, fg.group_id AS group_id, RAND() AS rand_order FROM #__fabrik_formgroup AS fg
INNER JOIN #__fabrik_groups as g ON g.id = fg.group_id
 WHERE fg.form_id = ".(int)$this->getId()." AND state = 1";
			if ($params->get('randomise_groups') == 1) {
				$sql .= " ORDER BY rand_order";
			} else {
				$sql .= " ORDER BY fg.ordering";
			}
			$db->setQuery($sql);
			$groups = $db->loadObjectList('group_id');
			$this->_publishedformGroups = $this->mergeGroupsWithJoins($groups);
		}
		return $this->_publishedformGroups;
	}

	/** get the ids of all the groups in the form
	 * @return array of group ids
	 */

	function getGroupIds()
	{
		$groups =& $this->getPublishedGroups();
		return array_keys($groups);
	}

	/**
	 * force load in the group ids
	 * separate from getGroupIds as you need to force load these
	 * when saving the table
	 */

	function _loadGroupIds()
	{
		unset($this->_publishedformGroups);
		return $this->getGroupIds();
	}

	private function mergeGroupsWithJoins($groups)
	{
		$db =& FabrikWorker::getDbo();
		$form =& $this->getForm();
		if ($form->record_in_database) {
			$listModel = $this->getListModel();
			$listid = (int)$listModel->getId();
			if (is_object($listModel) && $listid !== 0) {
				$db->setQuery("SELECT g.id, j.id AS joinid FROM #__fabrik_joins AS j INNER JOIN #__fabrik_groups AS g ON g.id = j.group_id WHERE table_id = ".(int)$listModel->getId()." AND g.state = 1");
				$joinGroups = $db->loadObjectList('id');
				foreach($joinGroups as $k=>$o) {
					if (array_key_exists($k, $groups)) {
						$groups[$k]->join_id = $o->joinid;
					}
				}
			}
		}
		return $groups;
	}

	/**
	 * get the forms published group objects
	 *
	 * @return array group model objects with table row loaded
	 */

	function getGroups()
	{
		if (!isset($this->groups)) {
			$listModel =& $this->getListModel();
			$groupModel =& JModel::getInstance('Group', 'FabrikModel');
			$groupdata =& $this->getPublishedGroups();
			foreach ($groupdata as $id => $groupd) {
				$thisGroup = clone($groupModel);
				$thisGroup->setId($id);
				$thisGroup->setContext($this, $listModel);
				// $$ rob 25/02/2011 this was doing a query per group - pointless as we bind $groupd to $row afterwards
				//$row =& $thisGroup->getGroup();
				//needed for fabble
				JTable::addIncludePath(JPATH_ADMINISTRATOR.DS.'components'.DS.'com_fabrik'.DS.'tables');
				$row = & JTable::getInstance('Group', 'FabrikTable');
				$row->bind($groupd);
				$thisGroup->_group = $row;
				if ($row->state == 1) {
					$this->groups[$id] = $thisGroup; //dont use &=!
				}
			}
		}
		return $this->groups;
	}

	/**
	 * gets each element in the form along with its group info
	 * @param bol included unpublished elements in the result
	 * @return array element objects
	 */

	function getFormGroups($excludeUnpublished = true)
	{
		$params =& $this->getParams();
		$db =& FabrikWorker::getDbo();
		$sql = "SELECT *, #__fabrik_groups.attribs AS gattribs, #__fabrik_elements.id as element_id
		, #__fabrik_groups.name as group_name, RAND() AS rand_order FROM #__fabrik_formgroup
		LEFT JOIN #__fabrik_groups
		ON #__fabrik_formgroup.group_id = #__fabrik_groups.id
		LEFT JOIN #__fabrik_elements
		ON #__fabrik_groups.id = #__fabrik_elements.group_id
		WHERE #__fabrik_formgroup.form_id = ".(int)$this->_id;
		if ($excludeUnpublished) {
			$sql .= " AND #__fabrik_elements.state = '1'";
		}
		if ($params->get('randomise_groups') == 1) {
			$sql .= " ORDER BY rand_order, #__fabrik_elements.ordering";
		} else {
			$sql .= " ORDER BY #__fabrik_formgroup.ordering, #__fabrik_formgroup.group_id, #__fabrik_elements.ordering";
		}
		$db->setQuery($sql);
		$groups = $db->loadObjectList();
		$this->_elements = $groups;
		return $groups;
	}

	/**
	 * similar to getFormGroups() except that this returns a data structure of
	 * form
	 * --->group
	 * -------->element
	 * -------->element
	 * --->group
	 * if run before then existing data returned
	 * @return array element objects
	 */

	function &getGroupsHiarachy()
	{
		if (!isset($this->groups)) {
			$this->getGroups();
			$pluginManager =& $this->getPluginManager();
			$this->groups =& $pluginManager->getFormPlugins($this);
		}
		return $this->groups;
	}

	/**
	 * get an list of elements that aren't shown in the table view
	 *
	 * @return array of element table objects
	 */
	function getElementsNotInTable()
	{
		if (!isset($this->_elementsNotInTable)) {
			$this->_elementsNotInTable = array();
			$groups =& $this->getGroupsHiarachy();
			foreach ($groups as $group) {
				$elements =& $group->getPublishedElements();
				foreach ($elements as $elementModel) {
					if ($elementModel->canView() || $elementModel->canUse()) {
						$element =& $elementModel->getElement();
						if (!isset($element->show_in_table_summary) || !$element->show_in_table_summary) {
							$this->_elementsNotInTable[] = $element;
						}
					}
				}

			}
		}
		return $this->_elementsNotInTable;

	}

	/**
	 * this checks to see if the form has a file upload element
	 * and returns the correct
	 * encoding type for the form
	 * @param int form id
	 * @param object forms elements
	 * @return string form encoding type
	 */

	function getFormEncType()
	{
		$groups =& $this->getGroupsHiarachy();
		foreach ($groups as $groupModel) {
			$elementModels =& $groupModel->getPublishedElements();
			foreach ($elementModels as $elementModel) {
				if ($elementModel->_is_upload == '1') {
					return "multipart/form-data";
				}
			}
		}
		return "application/x-www-form-urlencoded";
	}

	/**
	 * run a method on all the element plugins in the form
	 *
	 * @param string method to call
	 * @param array posted form data
	 */

	function runElementPlugins($method, $data)
	{
		$groups =& $this->getGroupsHiarachy();
		foreach ($groups as $groupModel) {
			$elementModels =& $groupModel->getPublishedElements();
			foreach ($elementModels as $elementModel) {
				$params =& $elementModel->getParams();
				if (method_exists($elementModel, $method)) {
					$elementModel->$method($params, $data);
				}
			}
		}
	}

	/**
	 * get the plugin manager
	 *
	 * @return object plugin manager
	 */

	function getPluginManager()
	{
		if (!isset($this->_pluginManager)) {
			$this->_pluginManager =& JModel::getInstance('Pluginmanager', 'FabrikModel');
		}
		return $this->_pluginManager;
	}

	/**
	 * when the form is submitted we want to get the orginal record it
	 * is updating - this is used in things like the fileupload element
	 * to check for changes in uploaded files and process the difference
	 * @return object
	 */

	function setOrigData()
	{
		if (JRequest::getInt('rowid') == 0) {
			$this->_origData = array(new stdClass());
		}else{
			$origrowid = $this->_rowId;
			$this->_rowid = $this->getRowId(true);
			$listModel	=& $this->getListModel();
			$fabrikDb   =& $listModel->getDb();
			$sql 				= $this->_buildQuery();
			$fabrikDb->setQuery($sql);
			$this->_origData = $fabrikDb->loadObjectList();
			$this->_rowId = $origrowid;
		}
	}

	function getOrigData()
	{
		if (!isset($this->_origData)) {
			$this->setOrigData();
		}
		return $this->_origData;
	}

	/**
	 * processes the form data and decides what action to take
	 * @return bool false if one of the plugins reuturns an error otherwise true
	 */

	function process()
	{
		if (JRequest::getCmd('format') == 'raw') {
			ini_set('display_errors', 0);
		}
		@set_time_limit(300);
		require_once(COM_FABRIK_FRONTEND.DS.'helpers'.DS.'uploader.php');
		$form						=& $this->getForm();
		$pluginManager 	=& $this->getPluginManager();
		$params 				=& $this->getParams();

		$sessionModel =& JModel::getInstance('Formsession', 'FabrikModel');
		$sessionModel->setFormId($this->getId());
		$sessionModel->setRowId($this->_rowId);
		// $$$ rob _rowId can be updated by juser plugin so plugin can use check (for new/edit)
		// now looks at _origRowId
		$this->_origRowId = $this->_rowId;
		$this->getGroupsHiarachy();

		if ($form->record_in_database == '1') {
			$this->setOrigData();
		}

		if (in_array(false, $pluginManager->runPlugins('onBeforeProcess', $this))) {
			return;
		}
		$this->removeEmptyNoneJoinedGroupData($this->_formData);

		if (!$this->_doUpload()) {
			return false;
		}

		$this->setFormData();

		if (in_array(false, $pluginManager->runPlugins('onBeforeStore', $this))) {
			return false;
		}

		$this->_formDataWithTableName = $this->_formData;

		if ($form->record_in_database == '1') {
			$this->processToDB();
		}

		// Clean the cache.
		$cache = JFactory::getCache(JRequest::getCmd('option'));
		$cache->clean();

		//$$$rob run this before as well as after onAfterProcess (ONLY for redirect plugin)
		// so that any redirect urls are available for the plugin (e.g twitter)
		$pluginManager->runPlugins('onLastProcess', $this);

		if (in_array(false, $pluginManager->runPlugins('onAfterProcess', $this))) {
			// $$$ rob this no longer stops default redirect (not needed any more)
			//returning false here stops the default redirect occuring
			return false;
		}
		//need to remove the form session before redirect plugins occur
		$sessionModel->remove();

		//$$$rob used ONLY for redirect plugins
		if (in_array(false, $pluginManager->runPlugins('onLastProcess', $this))) {
			// $$$ rob this no longer stops default redirect (not needed any more)
			//returning false here stops the default redirect occuring
			return false;
		}
		return true;
	}

	protected function _doUpload()
	{
		$oUploader =& $this->getUploader();
		$oUploader->upload();
		if ($oUploader->moveError) {
			return false;
		}
		return true;
	}

	/**
	 * update the data that gets posted via the form and stored by the form
	 * model. Used in elements to modify posted data see fabrikfileupload
	 * @param string $key (in key.dot.format to set a recursive array
	 * @param string $val va;ue to set to
	 * @param bool $update_raw automatically update _raw key as well
	 * @param bool $override_ro update data even if element is RO
	 * @return null
	 */

	function updateFormData($key, $val, $update_raw = false, $override_ro = false)
	{

		if (strstr($key, '.')) {

			$nodes = explode('.', $key);
			$count = count($nodes);
			$pathNodes = $count - 1;
			if ($pathNodes < 0) {
				$pathNodes = 0;
			}
			$ns =& $this->_formData;
			for ($i = 0; $i <= $pathNodes; $i ++) {
				// If any node along the registry path does not exist, create it
				//if (!isset($this->_formData[$nodes[$i]])) { //this messed up for joined data
				if (!isset($ns[$nodes[$i]])) {
					$ns[$nodes[$i]] = array();
				}
				$ns =& $ns[$nodes[$i]];
			}
			$ns = $val;

			$ns =& $this->_fullFormData;
			for ($i = 0; $i <= $pathNodes; $i ++) {
				// If any node along the registry path does not exist, create it
				//if (!isset($this->_formData[$nodes[$i]])) { //this messed up for joined data
				if (!isset($ns[$nodes[$i]])) {
					$ns[$nodes[$i]] = array();
				}
				$ns =& $ns[$nodes[$i]];
			}
			$ns = $val;

			// $$$ hugh - FIXME - nope, this won't work!  We don't know which path node is the element name.
			if ($update_raw) {
				$key .= '_raw';
				$nodes = explode('.', $key);
				$count = count($nodes);
				$pathNodes = $count - 1;
				if ($pathNodes < 0) {
					$pathNodes = 0;
				}
				$ns =& $this->_formData;
				for ($i = 0; $i <= $pathNodes; $i ++)
				{
					// If any node along the registry path does not exist, create it
					//if (!isset($this->_formData[$nodes[$i]])) { //this messed up for joined data
					if (!isset($ns[$nodes[$i]])) {
						$ns[$nodes[$i]] = array();
					}
					$ns =& $ns[$nodes[$i]];
				}
				$ns = $val;

				$ns =& $this->_fullFormData;
				for ($i = 0; $i <= $pathNodes; $i ++)
				{
					// If any node along the registry path does not exist, create it
					//if (!isset($this->_formData[$nodes[$i]])) { //this messed up for joined data
					if (!isset($ns[$nodes[$i]])) {
						$ns[$nodes[$i]] = array();
					}
					$ns =& $ns[$nodes[$i]];
				}
				$ns = $val;
			}
		} else {
			$this->_formData[$key] = $val;
			$this->_fullFormData[$key] = $val;
			/*
			 * Need to allow RO (encrypted) elements to be updated.  Consensus is that
			 * we should actually modify the actual encrypted element in the $_REQUEST,
			 * but turns out this is a major pain in the butt (see _cryptViewOnlyElements() in the
			 * form view for details!).  Main problem is we need to know if it's a join and/or repeat group,
			 * which means loading up the element model.  So for now, just going to add the element name to a
			 * class array, $this->_pluginUpdatedElements[], which we'll check in _addDefaultDataFromRO()
			 * in the table model, or wherever else we need it.
			 */
			/*
			 if (array_key_exists('fabrik_vars', $_REQUEST)
			 && array_key_exists('querystring', $_REQUEST['fabrik_vars'])
			 && array_key_exists($key, $_REQUEST['fabrik_vars']['querystring'])) {
				$crypt = new JSimpleCrypt();
				// turns out it isn't this simple, of course!  see above
				$_REQUEST['fabrik_vars']['querystring'][$key] = $crypt->encrypt($val);
				}
				*/
			// add element name to this array, which will then cause this element to be skipped
			// during the RO data phase of writing the row.  Don't think it really matter what we set it to,
			// might as well be the value.  Note that we need the new $override_ro arg, as some elements
			// use updateFormData() as part of normal operation, which should default to NOT overriding RO.
			if ($override_ro) {
				$this->_pluginUpdatedElements[$key] = $val;
			}
			if ($update_raw) {
				$key .= '_raw';
				$this->_formData[$key] = $val;
				$this->_fullFormData[$key] = $val;
				if ($override_ro) {
					$this->_pluginUpdatedElements[$key] = $val;
				}
			}
		}
	}

	/*
	 * this will strip the html from the form data according to the
	 * filter settings applied from article manager->parameters
	 * see here - http://forum.joomla.org/index.php/topic,259690.msg1182219.html#msg1182219
	 * still not working in J1.5.2 :(
	 */

	function &setFormData()
	{
		if (isset($this->_formData)) {
			return $this->_formData;
		}
		list($dofilter, $filter) = FabrikWorker::getContentFilter();

		$ajaxPost = JRequest::getVar('fabrik_postMethod') == 'ajax' ? true : false;
		foreach ($_REQUEST as $key=>$val) {
			$val = JRequest::getVar($key, '', 'request', 'string', JREQUEST_ALLOWRAW); // JREQUEST_ALLOWHTML doesnt work!

			$aData[$key] = $val;
			if (!is_array($aData[$key])) {
				if ($dofilter) {
					$aData[$key] = $filter->clean($aData[$key]);
				}
				if ($ajaxPost) {
					$aData[$key] = rawurldecode($aData[$key]);
				}
				//$aData[$key] = html_entity_decode((string) $filter->_remove($filter->_decode((string) $aData[$key])));
				//_decode doesnt deal with uppercase letter in the encoded string generated by javascripts encodeURIComponent function
				$aData[$key] = preg_replace('/%([0-9A-F]{2})/mei', "chr(hexdec('\\1'))", $aData[$key]);
			} else {
				foreach ($aData[$key] as $k2 => $val2) {
					// filter element for XSS and other 'bad' code etc.
					if (is_string($val2)) {
						if ($dofilter) {
							//$aData[$key][$k2] = html_entity_decode($filter->_remove( $filter->_decode($val2)));
							$aData[$key][$k2] = $filter->clean($val2);
							//_decode doesnt deal with uppercase letter in the encoded string generated by javascripts encodeURIComponent function
							$aData[$key][$k2] = preg_replace('/%([0-9A-F]{2})/mei', "chr(hexdec('\\1'))", $aData[$key][$k2]);
						} else {
							$aData[$key][$k2] = $val2;
						}
						if ($ajaxPost) {
							$aData[$key][$k2] = rawurldecode($aData[$key][$k2]);
						}
					}
				}
			}
		}
		//set here so element can call formModel::updateFormData()
		$this->_formData = $aData;

		$this->_fullFormData = $this->_formData;

		$session =& JFactory::getSession();
		$session->set('com_fabrik.form.data', $this->_formData);

		return $this->_formData;
	}

	private function callElementPreprocess()
	{
		$repeatTotals = JRequest::getVar('fabrik_repeat_group', array(0), 'post', 'array');
		// $$$ hugh - if we assign by reference, the foreach loop goes loopy if there is a joined
		// group.  For some reason, the array pointer gets stuck and it keeps igterating through
		// the first group forever.  This seems to happen only with getGroupsHierachy, we've
		// run across this before.  Still no idea what is going on.
		//$groups =& $this->getGroupsHiarachy();
		$groups = $this->getGroupsHiarachy();
		//curerntly this is just used by calculation elements
		foreach ($groups as $groupModel) {
			$group =& $groupModel->getGroup();
			$repeatedGroupCount = JArrayHelper::getValue($repeatTotals, $group->id, 0, 'int');
			$elementModels =& $groupModel->getPublishedElements();
			for ($c = 0; $c < $repeatedGroupCount; $c ++) {
				foreach ($elementModels as $elementModel) {
					$elementModel->preProcess($c);
				}
			}
		}
	}

	/**
	 * without this the first groups repeat data was always being saved (as it was posted but hidden
	 * on the form.
	 * @param array $data (ref)
	 */
	protected function removeEmptyNoneJoinedGroupData(&$data)
	{
		$repeats = JArrayHelper::getValue($data, 'fabrik_repeat_group', array());
		$groups =& $this->getGroups();
		foreach ($repeats as $groupid => $c) {
			if ($c == 0) {
				$group = $groups[$groupid];
				if ($group->isJoin()) {
					continue;
				}
				$elements =& $group->getPublishedElements();
				foreach ($elements as $elementModel) {
					$pair[1] = $elementModel->getElement()->name;
					$data[$name] = '';
					$data[$name.'_raw'] = '';
				}
			}
		}
	}

	/**
	 * process the data to the database
	 *
	 * @return null
	 */

	function processToDB()
	{
		$listModel =& $this->getListModel();
		$listModel->setBigSelects();
		$table =& $listModel->getTable();
		$origTableName = $table->db_table_name;
		$origTableKey = $table->db_primary_key;
		$pluginManager =& $this->getPluginManager();

		// COPY function should create new records
		if (array_key_exists('Copy', $this->_formData)) {
			$this->_rowId = '';
			//$$$rob dont pass in $table->db_primary_key directly into safeColName as its then
			//modified permanently by this function
			$k = $table->db_primary_key;
			$k = FabrikString::safeColNameToArrayKey($k);
			$origid = $this->_formData[$k];
			$this->_formData[$k] = '';
			$this->_formData['rowid'] = '';
		}
		/* get an array of the joins to process
		 note this was processJoin() but now preProcessJoin() does the same except
		 no longer stores the results - do this after the main form data has been
		 saved and u have an id to use
		 for the foreign key value*/
		$aPreProcessedJoins =& $listModel->preProcessJoin();

		$joinKeys = array();
		//needed for plugins that are run after the data is submitted to the db
		// $$$ rob moved to outside processToDB() as this data is needed regardless of
		// whether we store in the db or not (for email data)
		//$this->_formDataWithTableName = $this->_formData;
		$this->_formData = $listModel->removeTableNameFromSaveData($this->_formData, '___');
		$insertId = $this->_storeMainRow ? $this->submitToDatabase($this->_rowId) : $this->_rowId;

		//set the redirect page to the form's url if making a copy and set the id
		//to the new insertid
		if (array_key_exists('Copy', $this->_formData)) {
			$u = str_replace("rowid=$origid", "rowid=$insertId", $_SERVER['HTTP_REFERER']);
			JRequest::setVar('fabrik_referrer', $u);
		}
		$tmpKey = str_replace("`", "", $table->db_primary_key);
		$joinKeys[$tmpKey] = $insertId;
		$tmpKey = str_replace(".", "___", $tmpKey);
		$this->_formData[$tmpKey] 	= $insertId;
		$this->_formData[FabrikString::shortColName($table->db_primary_key)] = $insertId;
		$this->_fullFormData[$tmpKey] = $insertId; //need for things like the redirect plugin
		$this->_fullFormData['rowid'] = $insertId;
		$this->_formData['rowid'] = $insertId;
		$this->_formDataWithTableName['rowid'] = $insertId;
		$_REQUEST[$tmpKey] 	= $insertId;
		$_POST[$tmpKey] 	= $insertId;
		$_POST['rowid'] 	= $insertId;
		$_REQUEST['rowid'] 	= $insertId;
		// $$$ hugh - pretty sure we need to unset 'usekey' now, as it is not relavent to joined data,
		// and it messing with storeRow of joins
		JRequest::setVar('usekey', '');
		$_POST['usekey'] = '';
		$_REQUEST['usekey'] = '';
		//save join data
		$this->_removeIgnoredData($this->_formData);
		$aDeleteRecordId = '';
		//if (array_key_exists('join', $this->_formData)) {
			foreach ($aPreProcessedJoins as $aPreProcessedJoin) {
				if (!isset($this->_formData['join'])) {
					$this->_formData['join'] = array();
				}
				$oJoin = $aPreProcessedJoin['join'];
				if (array_key_exists('Copy', $this->_formData)) {
					$this->_rowId = '';
					$this->_formData['join'][$oJoin->id][$oJoin->table_join.'___'.$oJoin->table_key] = '';
					$this->_formData['rowid'] = '';
				}

				// $$$ rob 22/02/2011 could be a mutlfileupload with no images selected?
				// $$$ hugh - removed so repeat elements with no selection can remove rows
				/*
				if (!array_key_exists($oJoin->id, $this->_formData['join'])) {
					continue;
				}
				*/
				$data = FArrayHelper::getValue($this->_formData['join'], $oJoin->id, array(), 'array');

				// $$$ rob ensure that the joined data is keyed starting at 0 (could be greated if first group deleted)
				foreach ($data as &$dv) {
					if (is_array($dv)) {
						$dv = array_values($dv);
					}
				}
				//$$$rob moved till just before join table data saved
				//$data = $oTable->removeTableNameFromSaveData($data, $split='___');
				$groups =& $this->getGroupsHiarachy();

				$repeatTotals = JRequest::getVar('fabrik_repeat_group', array(0), 'post', 'array');
				if ((int)$oJoin->group_id !== 0) {
					$joinGroup = $groups[$oJoin->group_id];

					//find the primary key for the join table
					// $$$ rob - looks like  $table isn't a reference to $listModel->_table -go figure?? (php5.2.5 lax) Also reason why Hugh thought we
					// needed to pass in the table name to the storeRow() function.
					//$table->db_table_name 	= $oJoin->table_join;
					$listModel->getTable()->db_table_name = $oJoin->table_join;
					$elementModel = null;
				} else {
					//repeat element join
					$elementModel = $this->getElement($oJoin->element_id, true);
					$joinGroup = JModel::getInstance('Group', 'FabrikModel');
					$joinGroup->getGroup()->id = -1;
					$joinGroup->getGroup()->is_join = 1;

					//set join groups repeat to that of the elements options
					if ($elementModel->isJoin()) {
						$joinGroup->getParams()->set('repeat_group_button', 1);
						//set repeat count
						if ($elementModel->getGroup()->isJoin()) {
							//repeat element in a repeat group :S
							$groupJoin = $elementModel->getGroup()->getJoinModel();
							$groupKeyVals = $this->_formData['join'][$groupJoin->getId()][$groupJoin->getPrimaryKey().'_raw'];
							for ($r = 0; $r < count($data[$oJoin->table_join.'___id']); $r ++) {
								$repeatTotals['el'.$elementModel->getId()][$r] =  count($data[$oJoin->table_join.'___id'][$r]);
							}
						} else {
							$repeatTotals['el'.$elementModel->getId()] = count(JArrayHelper::getValue($data, $oJoin->table_join . '___id', array()));
						}
					}else{
						echo "Not a repeat element (el id = $oJoin->element_id)<br>";
					}
					//copy the repeating element into the join group
					$this->fakeRepeatElementsInGroup($elementModel, $joinGroup, $oJoin);
					$parentKey = $oJoin->table_join.'___'.$oJoin->table_join_key;
					if ($joinGroup->canRepeat()) {
						$data[$parentKey] = array();
						if (!$elementModel->isJoin()) {
							// $$$ rob not sure that we need this is rpeat elements in repeat group - was causing warnings without the above test anyway
							$repeatedGroupCount = JArrayHelper::getValue($repeatTotals, $elementModel->getGroup()->getId(), 0, 'int');
							for($rt = 0; $rt < $repeatedGroupCount; $rt++) {
								$data[$parentKey][] = array_fill(0, $repeatTotals['el'.$elementModel->getId()][$rt], $insertId);
							}
						} else {
							if ($repeatTotals['el'.$elementModel->getId()] == 0)
							{
								// no repeat data so dont set foreign key which should then take us down to the code
								// at the bottom where we delete the row.
								unset($data[$parentKey]);
							}
							else
							{
								$data[$parentKey] = array_fill(0, $repeatTotals['el'.$elementModel->getId()], $insertId);
							}
						}
					} else {
						if ($repeatTotals['el'.$elementModel->getId()] != 0)
						{
							// some repeat data so set foreign key which should then take us down to the code
							// which inserts the rows
							$data[$parentKey] = array_fill(0, $repeatTotals['el'.$elementModel->getId()], $insertId);
						}
					}
					$this->groups[] = $joinGroup;

					$listModel->getTable()->db_table_name = $oJoin->table_join;
				}

				$joinGroupTable =& $joinGroup->getGroup();

				// $$$ rob - erm is $fields needed?
				//$fields = $listModel->getDBFields($listModel->getTable()->db_table_name);
				$aKey = $listModel->getPrimaryKeyAndExtra();
				$aKey = $aKey[0];
				$listModel->getTable()->db_primary_key = $aKey['colname'];
				$joinDb =& $listModel->getDb();
				//back on track
				$fullforeginKey = $oJoin->table_join.'___'.$oJoin->table_join_key;
				if (is_array($data) && array_key_exists($fullforeginKey, $data)) {
					//$$$rob get the join tables ful primary key
					$joinDb->setQuery("DESCRIBE $oJoin->table_join");
					$this->oJoinPk = $oJoin->table_join . "___";
					$cols = $joinDb->loadObjectList();
					foreach ($cols as $col) {
						if ($col->Key == "PRI") {
							$this->oJoinPk .= $col->Field;
						}
					}

					//$repeatTotals = JRequest::getVar('fabrik_repeat_group', array(0), 'post', 'array');
					if ($joinGroup->canRepeat()) {
						//find out how many repeated groups were entered

						$rKey = (!is_null($elementModel) && $elementModel->isJoin()) ? $elementModel->getGroup()->getId() : $oJoin->group_id;
						$repeatedGroupCount = JArrayHelper::getValue($repeatTotals, $rKey, 0, 'int');
						$elementModels =& $joinGroup->getPublishedElements();

						$aUpdatedRecordIds = array();
						$joinCnn =& $listModel->getConnection();
						$joinDb =& $joinCnn->getDb();
						$paramKey = $listModel->getTable()->db_table_name.'___params';
						$repeatParams = JArrayHelper::getValue($data, $paramKey, array());
						for ($c = 0; $c < $repeatedGroupCount; $c++) {
							//get the data for each group and record it seperately
							$repData = array();
							if (!is_null($elementModel) && $elementModel->isJoin()) {
								//repeat element in repeat group.
								$elRepeats = $repeatTotals['el' . $elementModel->getId()];
								$elRepeatCount = $elementModel->getGroup()->isJoin() ? $elRepeats[$c] : $elRepeats;
								for ($er = 0; $er < $elRepeatCount; $er ++)
								{
									foreach ($data as $kk => $vv)
									{
										$shortKey = array_pop(explode('___', $kk));
										$repData[$shortKey] =  $elementModel->getGroup()->isJoin() ? JArrayHelper::getValue($vv[$c], $er) : JArrayHelper::getValue($vv, $er);
									}
									$repData['repeatnum'] = JArrayHelper::getValue($groupKeyVals, $c, 0);
									$repData['parent_id'] = $insertId;
									// @TODO not sure if params should get inserted into $repData (or if it is already)?
									$joinRowId = $this->prepareRepeatData($repData, $table, $oJoin, $joinKeys, $insertId, $c, $joinGroupTable, $aUpdatedRecordIds);
									$aDeleteRecordId = $joinDb->Quote($repData[$oJoin->table_join_key]);
								}
							} else {
								// normal repeat group
								foreach ($elementModels as $tmpElementModel) {
									$element = $tmpElementModel->getElement();
									$n = $tmpElementModel->getFullName(false, true, false);
									$v = (is_array($data[$n]) && array_key_exists($c, $data[$n])) ? $data[$n][$c] : '';
									$repData[$element->name] = $v;
									//store any params set in the individual plug-in (see fabrikfileupload::processUpload()->crop()
									if ($tmpElementModel->isJoin()){
										$repData['params'] = JArrayHelper::getValue($repeatParams, $c);
									}
								}
								$joinRowId = $this->prepareRepeatData($repData, $table, $oJoin, $joinKeys, $insertId, $c, $joinGroupTable, $aUpdatedRecordIds);
								$aDeleteRecordId = $joinDb->Quote($repData[$oJoin->table_join_key]);
							}

							$tmpKey = $oJoin->table_join . '.' . $oJoin->table_key;
							$joinKeys[$tmpKey] = $listModel->_lastInsertId;
						}
						if ($repeatedGroupCount === 0) { //all repeat group data was removed
							$sql = "DELETE FROM " . $joinDb->nameQuote($oJoin->table_join) . " WHERE (" . $joinDb->nameQuote($oJoin->table_join_key) . " = $insertId)";
						} else {
							//remove any joins that have been deleted with the groups "delete" button
							if (!$data) {
								$sql = "DELETE FROM ".$joinDb->nameQuote($oJoin->table_join)."  "
								. "\nWHERE (".$joinDb->nameQuote($oJoin->table_join_key)." = $aDeleteRecordId)";
							} else {
								$sql = "DELETE FROM ".$joinDb->nameQuote($oJoin->table_join)." "
								. "\nWHERE !(".$joinDb->nameQuote($table->db_primary_key)." IN (" . implode(',', $aUpdatedRecordIds ) . ")) AND (".$joinDb->nameQuote($oJoin->table_join_key)." = $aDeleteRecordId)";
							}
						}
						$joinDb->setQuery($sql);
						//echo $joinDb->getQuery();exit;
						$joinDb->query();

					} else {
						// $$$ hugh - trying to get one-to-one joins working where parent.fk = child.pk (ie where parent points to child)
						// So ... if we have that situation, what we will see next is
						// if (($fullforeginKey != $oJoinPk || (int)$data['rowid'] === 0) && ($fullforeginKey != "{$oJoin->table_join}___{$oJoin->table_key}" || $oJoin->table_key === $oJoin->table_join_key)) {
						// which we need NOT to be true, otherwise (as per Rob's comment) we'll actually be overwriting the PK.
						// Then, after that we are going to see ...
						// if ($fullforeginKey == $oJoinPk) {
						// which needs to be true in order for the code to go back and write the new joined rows PK
						// into the parenjt's FK element.
						// So ... although it doesn't really make sense, in the one-to-one, parent.fk = child.pk scenario,
						// we need $fullforeginKey to be the same as $oJoinPk.  So we need to work out if the FK is on parent or child ...
						// Which I think means testing to see if the $oJoinPk == $oJoin->table_join + $oJoin->table_join_key.
						// if it does, then element the user selected on the joined (child) table is NOT the FK.  Which means
						// the FK is actually the element they selected on the main table (parent).  In which case, we need to set
						// $fullforeginKey = $oJoinPk, which although it isn't, will satisfy the following code!!
						if ($this->oJoinPk == $oJoin->table_join . '___' . $oJoin->table_join_key) {
							$fullforeginKey = $this->oJoinPk;
						}

						// $$$rob test if the joined to table's key (as part of the join) is the same as its primary key
						// if it is then we dont want to overwrite the foreginkey as we will in fact be overwriting the pk

						// $$$ rob - 1) altered now so that this test only returns true if we are editing an existing record

						//2) also test if the foreign key isnt the same as the joins key - hard to explain cos its v confusing but
						//when you had 2 joins with both of them key'd to the main table things went horribly wrong

						//if (($fullforeginKey != $oJoinPk || (int)$data['rowid'] === 0) && $fullforeginKey != "{$oJoin->table_join}___{$oJoin->table_key}") {

						// $$$ rob - 3) hmm (2) was incorrect if your table had a pk called the same as the joined table's fk - eg.
						// tbl, venture pk venture_id, tbl access, fk venture_id
						// $$$ hugh - FIXME - something in this is hosing up when creating new one-to-one record where
						// parent.fk points to child.pk
						// OK, tried but couldn't understand why the rowid==0 test, which seems to make it impossible to do.  Trying without this.
						// Seems to work (with my change above) without the rowid test, for edit/new
						//if (($fullforeginKey != $oJoinPk || (int)$data['rowid'] === 0) && ($fullforeginKey != "{$oJoin->table_join}___{$oJoin->table_key}" || $oJoin->table_key === $oJoin->table_join_key)) {
						if (($fullforeginKey != $this->oJoinPk) && ($fullforeginKey != "{$oJoin->table_join}___{$oJoin->table_key}" || $oJoin->table_key === $oJoin->table_join_key)) {
							// $$$ hugh - at this point we are assuming that we have a situation where the FK is on the joined table,
							// pointing to PK on main table.  BUT ... we may have a situation where neither of the selected keys are
							// a PK, i.e. two records are joined by some other field.  In which case we do not want to set the FK val!
							// So, we need some logic here to handle that!
							$fkVal = JArrayHelper::getValue($joinKeys, $oJoin->join_from_table.'.'.$oJoin->table_key, $insertId);
							$data[$fullforeginKey] = $fkVal;
							$data[$fullforeginKey . "_raw"] = $fkVal;
						}
						if ($table->db_primary_key == '') {
							return JError::raiseWarning(500, JText::_("MUST SELECT PRIMARY KEY"));
						}
						$joinRowId = $data[$table->db_table_name . '___' . $table->db_primary_key];

						$data = $listModel->removeTableNameFromSaveData($data);

						//try to catch an pk val when the db_primary_key is in the short format
						// $$$ rob - think the primary key will always been in the short format as we got the
						//JOIN tables pk (ie $table->db_primary_key) direct from the db description
						//if (is_null($joinRowId)) {
						//	$joinRowId 				= $data[$table->db_primary_key];
						//}
						//$$$ hugh - need to give it the table name!!
						// $$$ rob no no no this is not the issue, on SOME setups $table is NOT a reference to $listModel->_table - this is where the issue is
						// not passing in the correct table name - see notes line 720 for explaination
						// $listModel->storeRow($repData, $joinRowId, true, $table->db_table_name);
						$listModel->storeRow($data, $joinRowId, true, $joinGroupTable);

						// $$$ Les: shouldn't we store the row id of the newly stored row back in data?????
						// Copied the following lines from the equivalent code for repeated groups
						// ...removing the $c group counter
						if ($joinRowId == '') {
							$joinRowId = $listModel->_lastInsertId;
							$this->_formData['join'][$oJoin->id][$this->oJoinPk] = $joinRowId;
							$this->_formDataWithTableName['join'][$oJoin->id][$this->oJoinPk] = $joinRowId;
							$this->_fullFormData['join'][$oJoin->id][$this->oJoinPk] = $joinRowId;
							$this->_formData['join'][$oJoin->id][$this->oJoinPk . '_raw'] = $joinRowId;
							$this->_formDataWithTableName['join'][$oJoin->id][$this->oJoinPk . '_raw'] = $joinRowId;
						}

						//$$$rob if the fk was the same as the pk then go back to the main table and
						// update its fk to match the
						// pk of the inserted table

						// $$$ hugh - FIXME another point where things aren't right for one-to-one
						// where parent.fk = child.pk
						$listModel->getTable()->db_table_name = $origTableName;
						$listModel->getTable()->db_primary_key = $origTableKey;
						if ($fullforeginKey == $this->oJoinPk) {
							$pkVal = $listModel->_lastInsertId;
							$fk = $oJoin->table_key;
							$this->_formData[$fk] = $pkVal;
							$this->_formData[$fk . '_raw'] = $pkVal; // because storeRow takes _raw if the key exists, which it does

							//reset the table's values to the main table
							// $$$ rob same issues as above with $table not being a reference to $listModel->_table
							//$table->db_table_name = $origTableName;
							//$table->db_primary_key = $origTableKey;
							$listModel->getTable()->db_table_name = $origTableName;
							$listModel->getTable()->db_primary_key = $origTableKey;
							$listModel->storeRow($this->_formData, $insertId);
							$insertId = $listModel->_lastInsertId;

							// $$$ hugh - I think this needs to be $insertId, not $rowId, otherwise
							// if it's new row (so $rowId was null) we insert a duplicate row in
							// the main table?
							// NOTE TO SELF - test on row edit as well as new row!!
							//$insertId 		= $this->submitToDatabase($insertId);

						}
						$tmpKey = $oJoin->table_join.'.'.$oJoin->table_key;
						$joinKeys[$tmpKey] = $listModel->_lastInsertId;
					}
				} else {
					// no join data found so delete all joined records
					$k = $oJoin->join_from_table.'___'.$oJoin->table_key;
					if (!is_null($elementModel) && $elementModel->isJoin()) {
						$k = 'rowid';
					}
					if (array_key_exists($k, $this->_formData)) {
						$sql = "DELETE FROM ".$joinDb->nameQuote($oJoin->table_join)."  "
						. "\nWHERE ($oJoin->table_join_key = ".$joinDb->Quote($this->_formData[$k]).")";
						$joinDb->setQuery($sql);
						$joinDb->query();
					}
				}
			}
		//}

		$listModel->getTable()->db_table_name = $origTableName;
		$listModel->getTable()->db_primary_key = $origTableKey;
		//testing for saving pages/
		JRequest::setVar('rowid', $insertId);
		if (in_array(false, $pluginManager->runPlugins('onBeforeCalculations', $this))) {
			return;
		}
		$this->getListModel()->doCalculations();
	}

	/**
	 * @since 2.1.1
	 * utility method taken outside of processToDb() as we need to store rows in a couple of locations now
	 * (e.g. repeat element in repeaat group calls this a couple of times, where as repeat group only calls it once)
	 * @param array $repData data to store
	 * @param object $table
	 * @param object $oJoin
	 * @param array $joinKeys
	 * @param int $insertId
	 * @param int $c repeat group count?
	 * @param object $joinGroupTable
	 */

	protected function prepareRepeatData(&$repData, &$table, $oJoin, $joinKeys, $insertId, $c, $joinGroupTable, &$aUpdatedRecordIds)
	{
		$listModel =& $this->getListModel();
		// $$$ rob didn't work for 2nd joined data set
		//$repData[$oJoin->table_join_key] = $insertId;
		$repData[$oJoin->table_join_key] = JArrayHelper::getValue($joinKeys, $oJoin->join_from_table.'.'.$oJoin->table_key, $insertId);

		// $$$ rob test for issue with importing joined csv data
		if (is_array($repData[$oJoin->table_join_key])) {
			$repData[$oJoin->table_join_key] = $repData[$oJoin->table_join_key][$c];
		}

		$table->db_table_name = $oJoin->table_join;
		// $$$ rob - erm is $fields needed -perhaps just pass $table->db_table_name into getPrimaryKeyAndExtra?
		//$fields = $listModel->getDBFields($table->db_table_name);
		$aKey = $listModel->getPrimaryKeyAndExtra($table->db_table_name);
		$aKey = $aKey[0];
		$table->db_primary_key = $aKey['colname'];

		$joinRowId = $repData[$table->db_primary_key];
		$listModel->storeRow($repData, $joinRowId, true, $joinGroupTable);

		if ((int)$joinRowId === 0) {
			$joinRowId = $listModel->_lastInsertId;
			// $$$ hugh - need to set PK element value for things like email plugin
			$this->_formData['join'][$oJoin->id][$this->oJoinPk][$c] = $joinRowId;
			$this->_formDataWithTableName['join'][$oJoin->id][$this->oJoinPk][$c] = $joinRowId;
			$this->_fullFormData['join'][$oJoin->id][$this->oJoinPk][$c] = $joinRowId;
			$this->_formData['join'][$oJoin->id][$this->oJoinPk . '_raw'][$c] = $joinRowId;
			$this->_formDataWithTableName['join'][$oJoin->id][$this->oJoinPk . '_raw'][$c] = $joinRowId;
		}
		$aUpdatedRecordIds[] = $joinRowId;

	}

	/**
	 * @since 2.1.1
	 * when inserting into a repeat element's table, we need to fake/create the element models
	 * as they are not stored/shown in fabrik's admin interface
	 * @param object $elementModel
	 * @param object $joinGroup
	 * @param object $oJoin
	 */

	protected function fakeRepeatElementsInGroup($elementModel, &$joinGroup, $oJoin)
	{
		$joinGroup->publishedElements[] = $elementModel;
		$idElementModel = JModel::getInstance('Element', 'FabrikModel');
		$idElementModel->getElement()->name = 'id';
		$idElementModel->getElement()->group_id = $elementModel->getGroup()->getId();
		$idElementModel->_group =& $elementModel->getGroup();
		$idElementModel->_aFullNames['id1_1__1_'] = $oJoin->table_join.'___id';
		$joinGroup->publishedElements[] = $idElementModel;

		$parentElement = JModel::getInstance('Element', 'FabrikModel');
		$parentElement->getElement()->name = 'parent_id';
		$parentElement->getElement()->group_id = $elementModel->getGroup()->getId();
		$parentElement->_group =& $elementModel->getGroup();
		$parentElement->_aFullNames['parent_id1_1__1_'] = $oJoin->table_join.'___parent_id';
		$joinGroup->publishedElements[] = $parentElement;

		$repeatElement = JModel::getInstance('Element', 'FabrikModel');
		$repeatElement->getElement()->name = 'repeatnum';
		$repeatElement->getElement()->group_id = $elementModel->getGroup()->getId();
		$repeatElement->_group =& $elementModel->getGroup();
		$repeatElement->_aFullNames['parent_id1_1__1_'] = $oJoin->table_join.'___repeatnum';
		$joinGroup->publishedElements[] = $repeatElement;

		$paramsElement = JModel::getInstance('Element', 'FabrikModel');
		$paramsElement->getElement()->name = 'params';
		$paramsElement->getElement()->group_id = $elementModel->getGroup()->getId();
		$paramsElement->_group =& $elementModel->getGroup();
		$paramsElement->_aFullNames['parent_id1_1__1_'] = $oJoin->table_join.'___params';
		$joinGroup->publishedElements[] = $paramsElement;
	}

	/**
	 * removes any element which s set to ignore
	 * @param array form data
	 */

	function _removeIgnoredData(&$data)
	{
		$groups =& $this->getGroupsHiarachy();
		foreach ($groups as $groupModel) {
			$groupTable = $groupModel->getGroup();
			$elementModels =& $groupModel->getPublishedElements();
			foreach ($elementModels as $elementModel) {
				$element =& $elementModel->getElement();
				$element->label = strip_tags($element->label);
				$params =& $elementModel->getParams();

				// @TODO - sort this out.  Calling getValue() from here is screwing things up,
				// partly because of some badly written code in the calc element that unsets rows in $data
				// and partly because it doesn't provide a repeatCount.
				// As far as I can tell, nothing that currently implements ignoreOnUpdate(), which is the only thing
				// we're using the $v from here in, actually looks at the $v we pass.
				// So for the moment, I'm commenting out the getValue call.
				//check if the data gets inserted on update
				//$v = $elementModel->getValue($data);
				$v = '';
				//currently only field password elements return true and file uploads when no file selected
				// $$$ hugh - actually, only upload element seems to implement this.
				if ($elementModel->ignoreOnUpdate( $v)) {
					$fullName = $elementModel->getFullName(false, true, true);
					unset($data['join'][$groupTable->join_id][$fullName]);
					if (array_key_exists($element->name, $data)) {
						unset($data[$element->name]);
					}
				}
			}
		}
	}

	/**
	 * saves the form data to the database
	 * @param int rowid - if 0 then insert a new row - otherwise update this row id
	 * @return mixed insert id (or rowid if updating existing row) if ok , else string error message
	 */

	function submitToDatabase($rowId = '0')
	{
		$this->getGroupsHiarachy();
		$pluginManager =& $this->getPluginManager();
		/*
		 *check if there is table data that is not posted by the form
		 * (ie if no checkboxes were selected)
		 */
		$groups =& $this->getGroupsHiarachy();
		$listModel = $this->getListModel();
		$listModel->encrypt = array();
		foreach ($groups as $groupModel) {
			$group = $groupModel->getGroup();
			$elementModels =& $groupModel->getPublishedElements();
			foreach ($elementModels as $elementModel) {
				$element = $elementModel->getElement();

				// $$$ hugh - why are we doing this?
				//
				$element->label = strip_tags($element->label);
				$params = $elementModel->getParams();
				$elementModel->getEmptyDataValue($this->_formData);

				//check if the data gets inserted on update
				// $$$hugh @FIXME - at this point we've removed tablename from _formdata keys (in processTodb()),
				// but element getValue() methods assume full name in _formData
				$v = $elementModel->getValue($this->_formData);
				if ($elementModel->ignoreOnUpdate($v)) {
					//currently only field password elements return true
					$fullName = $elementModel->getFullName(false, true, true);
					unset($this->_formData['join'][$group->join_id][$fullName]);
					if (array_key_exists($element->name, $this->_formData)) {
						unset($this->_formData[$element->name]);
					}
				}
				if ($elementModel->encryptMe()) {
					$listModel->encrypt[] = $element->name;
				}
				/*$plugin =& $pluginManager->getPlugIn($element->plugin, 'element');
				 $plugin->_element =& $element;*/

				if ($groupModel->isJoin()) {
					$tmpdata =& $this->_formData['join'][$group->join_id];
					//maybe no joined data added so test before doing onstorerow
					if (is_array($tmpdata)) {
						$elementModel->onStoreRow($tmpdata);
					}
				} else {
					$elementModel->onStoreRow($this->_formData);
				}
			}
		}
		$listModel->_oForm =& $this;
		$table =& $listModel->getTable();
		$listModel->storeRow($this->_formData, $rowId);
		$usekey = JRequest::getVar('usekey', '');
		if (!empty($usekey)) {
			return $listModel->_lastInsertId;
		}
		else {
			return ($rowId == 0) ? $listModel->_lastInsertId : $rowId;
		}
	}

	/**
	 * ease the compare use between 2.0 and 3.0
	 */

	function &getListModel(){
		return $this->getTableModel();
	}

	/**
	 * get the form's table model
	 * (was getTable but that clashed with J1.5 func)
	 *
	 * @return object fabrik table model
	 */

	function &getTableModel()
	{
		if (!isset($this->_listModel)) {
			$this->_listModel =& JModel::getInstance('Table', 'FabrikModel');
			$this->_listModel->loadFromFormId($this->_id);
			$this->_listModel->_oForm =& $this;
		}
		return $this->_listModel;
	}

	/**
	 * get the class names for each of the validation rules
	 * @return array (validaionruleid => classname )
	 */

	function loadValidationRuleClasses()
	{
		if (is_null($this->_validationRuleClasses)) {
			$pluginManager =& $this->getPluginManager();
			$oValidationRules =& $pluginManager->getPlugInGroup('validationrule');
			$classes = array();
			foreach ($oValidationRules as $rule) {
				$classes[$rule->_pluginName] = $rule->_className;
			}
			$this->_validationRuleClasses = $classes;
		}
		return $this->_validationRuleClasses;
	}

	/**
	 * 	$$$ hugh - add in any encrypted stuff, in case we fail validation ...
	 * otherwise it won't be in $data when we rebuild the page.
	 * Need to do it here, so _raw fields get added in the next chunk 'o' code.
	 * @param array posted form data passed by reference
	 * @return null
	 */

	function addEncrytedVarsToArray(&$post)
	{

		if (array_key_exists('fabrik_vars', $_REQUEST) && array_key_exists('querystring', $_REQUEST['fabrik_vars'])) {
			$groups =& $this->getGroupsHiarachy();
			$gkeys = array_keys($groups);
			jimport('joomla.utilities.simplecrypt');
			$crypt = new JSimpleCrypt();
			$w = new FabrikWorker();
			foreach ($gkeys as $g) {
				$groupModel = $groups[$g];
				$elementModels =& $groupModel->getPublishedElements();
				foreach ($elementModels as $elementModel) {
					$element =& $elementModel->getElement();
					foreach ($_REQUEST['fabrik_vars']['querystring'] as $key => $encrypted) {
						if ($elementModel->getFullName(false, true, false) == $key) {
							// 	$$$ rob - dont test for !canUse() as confirmation plugin dynamically sets this
							if ($elementModel->canView()) {
								//if (!$elementModel->canUse() && $elementModel->canView()) {
								if (is_array($encrypted)) {
									//repeat groups no join
									$v = array();
									foreach ($encrypted as $e) {
										//$$$ rob urldecode when posting from ajax form
										$e = urldecode($e);
										$e = empty($e) ? '' : $crypt->decrypt($e);
										$v[] = $w->parseMessageForPlaceHolder($e, $post);
									}
								} else {
									// $$$ rob urldecode when posting from ajax form
									$encrypted = urldecode($encrypted);
									$v = empty($encrypted) ? '' : $crypt->decrypt($encrypted);
									$v = $w->parseMessageForPlaceHolder($v, $post);
								}

								$elementModel->_group = $groupModel;
								$elementModel->setValuesFromEncryt($post, $key, $v);
								// $$ rob set both normal and rawvalues to encrypted - otherwise validate mehtod doenst
								//pick up decrypted value
								$elementModel->setValuesFromEncryt($post, $key.'_raw', $v);
							}
						}
					}
				}
			}
		}
	}

	/**
	 * when submitting data copy values to _raw equivalent
	 * @param array $post data (passed by ref)
	 * @return null
	 */
	function copyToRaw(&$post)
	{
		$groups =& $this->getGroupsHiarachy();
		foreach ($groups as $groupModel)
		{
			$elementModels =& $groupModel->getPublishedElements();
			foreach ($elementModels as $elementModel)
			{
				$elName2 = $elementModel->getFullName(false, true, false);
				if ($groupModel->isJoin())
				{
					$joinModel =& $groupModel->getJoinModel();
					if (array_key_exists('join', $post) && array_key_exists($joinModel->_id, $post['join']))
					{
						if ($groupModel->canRepeat())
						{
							$v = JArrayHelper::getValue($post['join'][$joinModel->_id], $elName2, array());
						}
						else
						{
							$v = JArrayHelper::getValue($post['join'][$joinModel->_id], $elName2, '');
						}
						$joindata[$joinModel->_id][$elName2] = $v;
						$joindata[$joinModel->_id][$elName2 . '_raw'] = $v;
						$post['join'][$joinModel->_id][$elName2] = $v;
						$post['join'][$joinModel->_id][$elName2 . '_raw'] = $v;
						$_POST['join'][$joinModel->_id][$elName2] = $v;
						$_POST['join'][$joinModel->_id][$elName2 . '_raw'] = $v;
					}
				}
				else
				{
					if (!array_key_exists($elName2 . '_raw', $post))
					{
						JRequest::setVar($elName2 . '_raw', @$post[$elName2]); //post required getValue() later on
						$post[$elName2 . '_raw'] = @$post[$elName2];
					}
				}
			}
		}
	}

	/**
	 * validate the form
	 * modifies post data to include validation replace data
	 * @return bol true if form validated ok
	 */

	function validate()
	{
		require_once(COM_FABRIK_FRONTEND.DS.'helpers'.DS.'uploader.php');
		$pluginManager 		=& JModel::getInstance('Pluginmanager', 'FabrikModel');
		$oValidationRules =& $pluginManager->getPlugInGroup('validationrule');
		$db =& JFactory::getDBO();
		//$post	=& JRequest::get('post', 4); //4 allows html
		// $$$ rob added coptToRow here so that calcs run in setFormData, element preProcess()
		//can access raw values
		//$this->copyToRaw( $_REQUEST);
		$post =& $this->setFormData();
		//contains any data modified by the validations
		$this->_modifiedValidationData = array();
		$w = new FabrikWorker();
		$joindata = array();
		$ok = true;

		// $$$ rob 01/07/2011 fileupload needs to examine records previous data for validations on edting records
		$this->setOrigData();

		// $$$ rob copy before addEncrytedVarsToArray as well as after
		// so that any placedholders(.._raw) contained in the encrypted vars are correctly replaced
		$this->copyToRaw($post);

		// $$$ rob for PHP 5.2.1 (and potential up to before 5.2.6) $post is not fully associated with _formData -
		// so the above copToRaw does not update $this->_formData.
		$this->_formData =& $post;
		$this->callElementPreprocess();

		// $$$ hugh - add in any encrypted stuff, in case we fail validation ...
		// otherwise it won't be in $data when we rebuild the page.
		// Need to do it here, so _raw fields get added in the next chunk 'o' code.
		$this->addEncrytedVarsToArray($post);

		//add in raw fields - the data is already in raw format so just copy the values
		$this->copyToRaw($post);

		$groups = $this->getGroupsHiarachy();
		$repeatTotals = JRequest::getVar('fabrik_repeat_group', array(0), 'request', 'array');
		$ajaxPost = JRequest::getVar('fabrik_postMethod') == 'ajax' ? true : false;
		$joindata = isset($post['join']) ? $post['join'] : array();

		foreach ($groups as $groupModel) {
			$groupCounter = $groupModel->getGroup()->id;
			$elementModels = $groupModel->getPublishedElements();
			if ($groupModel->isJoin())
			{
				$joinModel =& $groupModel->getJoinModel();
			}
			foreach ($elementModels as $elementModel)
			{
				// if the user can't view or edit the element, then don't validate it. Otherwise user sees failed validation but no idication of what failed
				if (!$elementModel->canUse() && !$elementModel->canView())
				{
					continue;
				}
				$elDbVals = array();
				$element =& $elementModel->getElement();

				$validation_rules =& $elementModel->getValidations();
				// $$ rob incorrect for ajax validation on joined elements
				$elName = JRequest::getVar('fabrik_postMethod') == 'ajax' ? $elementModel->getHTMLId(0) : $elementModel->getFullName(true, true, false);
				$this->_arErrors[$elName] = array();
				$elName2 = $elementModel->getFullName(false, true, false);
				// $$$rob fix notice on validation of multipage forms
				if (!array_key_exists($groupCounter, $repeatTotals))
				{
					$repeatTotals[$groupCounter] = 1;
				}
				for ($c = 0; $c < $repeatTotals[$groupCounter]; $c++)
				{
					$this->_arErrors[$elName][$c] = array();
					// $$$ rob $this->_formData was $_POST, but failed to get anything for calculation elements
					//in php 5.2.1
					$form_data = $elementModel->getValue($this->_formData, $c, array('runplugins' => 0, 'use_default' => false));
					if (get_magic_quotes_gpc())
					{
						if (is_array($form_data))
						{
							foreach ($form_data as &$d)
							{
								if (is_string($d))
								{
									$d = stripslashes($d);
									if ($ajaxPost)
									{
										$d = rawurldecode($d);
									}
								}
							}
						}
						else
						{
							$form_data = stripslashes($form_data);
							if ($ajaxPost)
							{
								$form_data = rawurldecode($form_data);
							}
						}
					}
					//internal element plugin validations
					if (!$elementModel->validate(@$form_data, $c))
					{
						$ok = false;
						$this->_arErrors[$elName][$c][] = $elementModel->getValidationErr();
					}

					// $$$ rob 11/04/2012 was stopping multiselect/chx dbjoin elements from saving in normal group.
					//if ($groupModel->canRepeat() || $elementModel->isJoin())
					if ($groupModel->canRepeat())
					{
						// $$$ rob for repeat groups no join setting to array() menat that $_POST only contained the last repeat group data
						//$elDbVals = array();
						$elDbVals[$c] = $elementModel->toDbVal($form_data, $c);
					}
					else
					{
						$elDbVals = $elementModel->toDbVal($form_data, $c);
					}

					//validations plugins attached to elemenets
					$pluginc = 0;
					if (!$elementModel->mustValidate())
					{
						continue;
					}
					foreach ($validation_rules as $plugin) {
						$plugin->_formModel =& $this;
						$plugin->_tableModel =& $this->getListModel();
						if ($plugin->shouldValidate($form_data, $pluginc)) {
							if (!$plugin->validate($form_data, $elementModel, $pluginc, $c)) {
								$this->_arErrors[$elName][$c][] = $w->parseMessageForPlaceHolder($plugin->getMessage($pluginc));
								$ok = false;
							}
							if (method_exists($plugin, 'replace'))
							{
								if ($groupModel->canRepeat())
								{
									$elDbVals[$c] = $elementModel->toDbVal($form_data, $c);
									$testreplace = $plugin->replace($elDbVals[$c], $elementModel, $pluginc, $c);
									if ($testreplace != $elDbVals[$c])
									{
										$elDbVals[$c] = $testreplace;
										$this->_modifiedValidationData[$elName][$c] = $testreplace;
										$joindata[$joinModel->_id][$elName2 . '_raw'][$c] = $testreplace;

									}
								}
								else
								{
									$testreplace = $plugin->replace($elDbVals, $elementModel, $pluginc, $c);
									if ($testreplace != $elDbVals)
									{
										$elDbVals = $testreplace;
										$this->_modifiedValidationData[$elName] = $testreplace;
										JRequest::setVar($elName . '_raw', $elDbVals);
										$post[$elName . '_raw'] = $elDbVals;
									}
								}
							}
						}
						$pluginc ++;
					}
				}

				if ($groupModel->isJoin())
				{
					$joindata[$joinModel->_id][$elName2] = $elDbVals;
				}
				else
				{
					if ($elementModel->isJoin())
					{
						$joinModel = $elementModel->getJoinModel();
						$join = $joinModel->getJoin();
						$joindata[$join->id][$elName2] = $elDbVals;
					}
					else
					{
						JRequest::setVar($elName, $elDbVals);
						$post[$elName] = $elDbVals;
					}
				}

				//unset the deafults or the orig submitted form data will be used (see date plugin mysql vs form format)
				$elementModel->defaults = null;

			}
		}
		//insert join data into request array
		JRequest::setVar('join', $joindata, 'post');

		if (!empty($this->_arErrors)) {
			$pluginManager 	=& $this->getPluginManager();
			$pluginManager->runPlugins('onError', $this);
		}
		FabrikHelperHTML::debug($this->_arErrors, 'form:errors');
		return $ok;
	}

	/**
	 * get an instance of the uploader object
	 *
	 * @return object uploader
	 */

	function &getUploader()
	{
		if (is_null($this->_oUploader)) {
			$this->_oUploader = new uploader($this);
		}
		return $this->_oUploader;
	}

	/**
	 * get the forms table name
	 *
	 * @return string table name
	 */

	function getTableName()
	{
		$this->getListModel();
		return $this->getListModel()->getTable()->db_table_name;
	}

	/**
	 * get the form row
	 *
	 * @return object form row
	 */

	function &getTable()
	{
		if (is_null($this->_form)) {
			$this->_form = parent::getTable();
			$this->_form->load($this->getId());
		}
		return $this->_form;
	}

	/**
	 * when saving a table that links to a database for the first time we
	 * need to link together the created form and the created group
	 * @param int group id
	 */

	function createFormGroup($groupId)
	{
		/*load in existing groups*/
		$str = $this->_getFromGroupsStr() . ",$groupId";
		$this->_setGroupString($str);
		$this->saveFormGroups();
	}

	function _getFromGroupsStr()
	{
		if (is_null($this->_formGroupStr)) {
			$this->_formGroupStr = $this->_loadFromGroupsStr();
		}
		return $this->_formGroupStr;
	}

	function _loadFromGroupsStr() {
		$db =& JFactory::getDBO();
		$sql = "SELECT group_id FROM #__fabrik_formgroup WHERE form_id = ".(int)$this->_id;
		$db->setQuery($sql);
		$aFormGroups = $db->loadResultArray();

		//check if table has joins - they should be already in aFormGoups but better to be safe
		$aJoinGroupIds = array();
		$listModel =& $this->getListModel();
		if (is_object($listModel) && $listModel->_id != 0) {
			$joins = $listModel->getJoins();
			foreach ($joins as $join) {
				$aJoinGroupIds[]  = $join->group_id;
			}
		}
		$merged = array_merge($aJoinGroupIds, $aFormGroups);
		// do double flip for merging values in an array
		$merged = array_flip( $merged);
		$merged = array_flip( $merged);

		$sFromGroups = implode(',', $merged);
		return $sFromGroups;
	}

	function _setGroupString($str )
	{
		$this->_formGroupStr = $str;
	}

	/**
	 * sets the variable of each of the form's group's elements to the value
	 * specified
	 * @param string variable name
	 * @param string variable value
	 * @return bol false if update error occurs
	 */

	function setElementVars($varName, $varVal)
	{
		$db =& JFactory::getDBO();
		if ($this->_elements == null) {
			$this->getFormGroups();
		}
		foreach ($this->_elements as $el) {
			$element =& JTable::getInstance('Element', 'Table');
			$element->load($el->id);
			if (!$element->setVar($varName, $varVal)) {
				return false;
			}
			$element->store();
		}
		return true;
	}

	/**
	 * determines if the form can be published
	 * @return bol true if publish dates are ok
	 */

	function canPublish()
	{
		$db =& FabrikWorker::getDbo();
		$form =& $this->getForm();
		$nullDate = (method_exists($db, 'getNullDate')) ? $db->getNullDate() : $this->getNullDate();
		$publishup =& JFactory::getDate($form->publish_up)->toUnix();
		$publishdown =& JFactory::getDate($form->publish_down)->toUnix();
		$now		=& JFactory::getDate()->toUnix();
		if ($form->state == '1') {
			if ($now >= $publishup || $form->publish_up == '' || $form->publish_up == $nullDate) {
				if ($now <= $publishdown || $form->publish_down == '' || $form->publish_down == $nullDate) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * creates a html dropdown off all forms
	 * @param int selected group id
	 * @param string default label
	 * @return string group list
	 */

	function makeDropDown( $selectedId = 0, $defaultlabel = '')
	{
		if ($defaultlabel == '') {
			$defaultlabel = JText::_('COM_FABRIK_PLEASE_SELECT');
		}
		$db =& JFactory::getDBO();
		$db->setQuery("SELECT id AS value, label AS text FROM #__fabrik_forms ORDER BY label");
		$aTmp[] = JHTML::_('select.option', "-1", $defaultlabel);
		$forms = $db->loadObjectList();
		$forms = array_merge($aTmp, $forms);
		return JHTML::_('select.genericlist', $forms, 'filter_formId', 'class="inputbox"  onchange="document.adminForm.submit();"', 'value', 'text', $selectedId);
	}

	/**
	 * create a drop down list of all the elements in the form
	 * @param string drop down name
	 * @param string current value
	 * @param bol add elements that are unpublished
	 * @param bol concat table name and el name with "___" (true) or "." (false)
	 * @param bol include raw labels default = true
	 * @return string html list
	 */

	function getElementList($name = 'order_by', $default = '', $excludeUnpublished = false, $useStep = false, $incRaw = true )
	{
		$aEls = array();
		$aEls = $this->getElementOptions($useStep, 'name', false, $incRaw);
		$aEls[] = JHTML::_('select.option', '', '-');
		asort($aEls);
		return JHTML::_('select.genericlist', $aEls, $name, 'class="inputbox" size="1" ', 'value', 'text', $default);
	}

	/**
	 * get an array of the form's element's ids
	 * @param $ignore array of classNames to ignore e.g. array('FabrikModelFabrikCascadingdropdown')
	 * @return array ints ids
	 */

	function getElementIds($ignore = array())
	{
		$aEls = array();
		$groups =& $this->getGroupsHiarachy();
		foreach ($groups as $groupModel) {
			$elementModels =& $groupModel->getPublishedElements();
			foreach ($elementModels as $elementModel) {
				$class = get_class($elementModel);
				if (!in_array($class, $ignore)) {
					$aEls[] = (int)$elementModel->getElement()->id;
				}
			}
		}
		return $aEls;
	}

	/**
	 * creates options array to be then used by getElementList to create a drop down of elements in the form
	 * sperated as elements need to collate this options from muliple forms
	 * @param bool concat table name and el name with "___" (true) or "." (false)
	 * @param string name of key to use (default "name")
	 * @param bool only show those elements shown in table summary
	 * @param bool include raw labels in list (default = false) Only works if $key = name
	 * @param array list of plugin names that should be included in the list - if empty include all plugin types
	 * @return array html options
	 */

	function getElementOptions($useStep = false, $key = 'name', $show_in_list_summary = false, $incRaw = false, $filter = array())
	{
		$groups =& $this->getGroupsHiarachy();
		$aEls = array();
		$step =( $useStep ) ? "___" : ".";
		$gkeys = array_keys($groups);
		foreach ($gkeys as $gid) {
			$groupModel =& $groups[$gid];
			$elementModels =& $groupModel->getMyElements();
			if ($groupModel->isJoin()) {
				$prefix = $groupModel->getJoinModel()->getJoin()->table_join . ".";
			} else {
				$prefix = '';
			}

			foreach ($elementModels as $elementModel) {
				$el =& $elementModel->getElement();
				if (!empty($filter) && !in_array($el->plugin, $filter)) {
					continue;
				}
				//$$$ testing
				if ($show_in_list_summary == true && $el->show_in_list_summary != 1) {
					continue;
				}
				$val = $el->$key;

				$label = strip_tags($prefix.$el->label);

				if ($key != "id") {

					$val = $elementModel->getFullName(false, $useStep, false);
					if ($this->_addDbQuote) {
						$val = FabrikString::safeColName($val);
					}

					if ($incRaw && is_a($elementModel, 'FabrikModelFabrikDatabasejoin')) {
						// FIXME - next line had been commented out, causing undefined warning for $rawval
						// on following line.  Not sure if getrawColumn is right thing to use here tho,
						// like, it adds filed quotes, not sure if we need them.
						$rawval = $elementModel->getRawColumn($useStep);
						$aEls[] = JHTML::_('select.option', $rawval, $label . "(raw)");
					}
				}
				$aEls[] = JHTML::_('select.option', $val, $label);
			}
		}
		asort($aEls);
		return $aEls;
	}

	/**
	 * called via ajax nav
	 * @param int $dir (1 - move foward, 0 move back)
	 * @return bol new row id loaded.
	 */

	function paginateRowId($dir)
	{
		$c = $dir == 1 ? '>=' : '<=';
		$limit = $dir == 1 ? 'LIMIT 2' : '';
		$listModel =& $this->getListModel();
		$order = $listModel->_buildQueryOrder();
		$table =& $listModel->getTable();
		$db =& $listModel->getDb();
		$rowid = JRequest::getInt('rowid');
		$db->setQuery(" SELECT $table->db_primary_key AS ". FabrikString::safeColNameToArrayKey($table->db_primary_key)
		." FROM $table->db_table_name
WHERE $table->db_primary_key $c $rowid $order $limit");

		$ids = $db->loadResultArray();
		if ($dir == 1) {
			if (count($ids) >= 2) {
				JRequest::setVar('rowid', $ids[$dir]);
				return true;
			} else {
				return false;
			}
		}
		if (count($ids)-2 >= 0) {
			JRequest::setVar('rowid', $ids[count($ids)-2]);
			return true;
		}
		return false;
	}

	/**
		* get the current records row id
		*  setting a rowid of -1 will load in the current users record (used in
		*  conjunction wth usekey variable
		*
		*  setting a rowid of -2 will load in the last created record
		*
		* @return string rowid
	 */

	function getRowId($useMenu = false)
	{
		if (!$useMenu && isset($this->_rowId)) {
			return $this->_rowId;
		}
		$usersConfig 	=& JComponentHelper::getParams('com_fabrik');
		$user 				=& JFactory::getUser();
		$pluginManager =& $this->getPluginManager();
		// $$$rob if we show a form module when in a fabrik form component view - we shouldn't use
		// the request rowid for the mambot as that value is destinded for the component
		if ($this->_isMambot && JRequest::getCmd('option') == 'com_fabrik') {
			$this->_rowId = $usersConfig->get('rowid');
		} else {
			if ($useMenu) {
				$this->_rowId = FabrikWorker::getMenuOrRequestVar('rowid', '', $this->_isMambot, 'menu');
			}
			else {
				$this->_rowId = JRequest::getVar('rowid', $usersConfig->get('rowid'));
			}
		}
		if ($this->getListModel()->getParams()->get('sef-slug') !== '') {
			$this->_rowId = explode(":", $this->_rowId);
			$this->_rowId = array_shift($this->_rowId);
		}
		// $$$ hugh - for some screwed up reason, when using SEF, rowid=-1 ends up as :1
		// $$$ rob === compare as otherwise 0 == ":1" which menat that the users record was  loaded
		if ((string)$this->_rowId === ":1") {
			$this->_rowId = "-1";
		}
		// set rowid to -1 to load in the current users record
		switch ($this->_rowId) {
			case '-1':
				$this->_rowId = $user->get('id');
				break;
			case '-2':
				//set rowid to -2 to load in the last recorded record
				$this->_rowId = $this->getMaxRowId();
				break;
		}
		$pluginManager->runPlugins('onSetRowId', $this);
		return $this->_rowId;
	}

	/**
	 * collates data to write out the form
	 * @return mixed . bol
	 */

	function render()
	{
		global $_PROFILER;
		JDEBUG ? $_PROFILER->mark('formmodel render: start') : null;
		// $$$rob required in paolo's site when rendering modules with ajax option turned on
		$this->_listModel = null;
		@set_time_limit(300);

		$pluginManager =& $this->getPluginManager();

		$this->_rowId = $this->getRowId();

		$res = $pluginManager->runPlugins('onBeforeLoad', $this);
		if (in_array(false, $res)) {
			return false;
		}
		JDEBUG ? $_PROFILER->mark('formmodel render: getData start') : null;
		$data = $this->getData();
		JDEBUG ? $_PROFILER->mark('formmodel render: getData end') : null;
		$res = $pluginManager->runPlugins('onLoad', $this);
		if (in_array(false, $res)) {
			return false;
		}

		$this->_reduceDataForXRepeatedJoins();

		JDEBUG ? $_PROFILER->mark('formmodel render end') : null;
		// $$$ rob return res - if its false the the form will not load
		return $res;
	}

	/**
	 * get the max row id - used when requesting rowid=-2 to return the last recorded detailed view
	 * @return int max row id
	 */

	protected function getMaxRowId()
	{
		if (!$this->getForm()->record_in_database) {
			return $this->_rowId;
		}
		$listModel 	=& $this->getListModel();
		$fabrikDb   	=& $listModel->getDb();
		$table = $listModel->getTable();
		$k = $fabrikDb->nameQuote($table->db_primary_key);
		$fabrikDb->setQuery("SELECT MAX($k) FROM $table->db_table_name " . $listModel->_buildQueryWhere());
		return $fabrikDb->loadResult();
	}

	/**
	 * main method to get the data to insert into the form
	 * @return array form's data
	 */

	function getData()
	{
		global $_PROFILER;
		$this->_data = array();
		$data = array(FArrayHelper::toObject(JRequest::get('request')));
		$form =& $this->getForm();

		$aGroups =& $this->getGroupsHiarachy();
		JDEBUG ? $_PROFILER->mark('formmodel getData: groups loaded') : null;
		if (!$form->record_in_database) {
			FabrikHelperHTML::debug($data, 'form:getData from $_REQUEST');
			$data = JRequest::get('request');
		} else {

			$listModel 	=& $this->getListModel();
			JDEBUG ? $_PROFILER->mark('formmodel getData: table model loaded') : null;
			$fabrikDb   	=& $listModel->getDb();
			JDEBUG ? $_PROFILER->mark('formmodel getData: db created') : null;
			$table 				=& $listModel->getTable();
			JDEBUG ? $_PROFILER->mark('formmodel getData: table row loaded') : null;
			$this->_aJoinObjs 	=& $listModel->getJoins();
			JDEBUG ? $_PROFILER->mark('formmodel getData: joins loaded') : null;
			if (!empty($this->_arErrors)) {
				// $$$ hugh - if we're a mambot, reload the form session state we saved in
				// processForm() when it banged out.
				if ($this->_isMambot) {
					$srow = $this->getSessionData();
					$this->sessionModel->last_page = 0;
					if ($srow->data != '') {
						$data = FArrayHelper::toObject(unserialize($srow->data ), 'stdClass', false);
						JFilterOutput::objectHTMLSafe( $data);
						$data = array($data);
						FabrikHelperHTML::debug($data, 'form:getData from session (form in Mambot and errors)');
					}
				}
				else {
					// $$$ rob - use setFormData rather than JRequest::get()
					//as it applies correct input filtering to data as defined in article manager parameters
					$data = $this->setFormData();
					$data = FArrayHelper::toObject($data, 'stdClass', false);
					//$$$rob ensure "<tags>text</tags>" that are entered into plain text areas are shown correctly
					JFilterOutput::objectHTMLSafe( $data);
					$data = array($data);
					FabrikHelperHTML::debug($data, 'form:getData from POST (form not in Mambot and errors)');
				}
			} else {
				//test if its a resumed paged form
				$srow = $this->getSessionData();
				JDEBUG ? $_PROFILER->mark('formmodel getData: session data loaded') : null;
				if ($this->saveMultiPage() && $srow->data != '') {
					$data = array(FArrayHelper::toObject(array_merge(unserialize($srow->data), JArrayHelper::fromObject($data[0]))));
					FabrikHelperHTML::debug($data, 'form:getData from session (form not in Mambot and no errors');
				} else {
					// only try and get the row data if its an active record
					//use !== 0 as rowid may be alphanumeric
					// $$$ hugh - when 'usekey', rowid can actually be 0 (like if using userid and this is guest access)
					// so go ahead and try and load the row, if it doesn't exist, we'll supress the warning
					$usekey = JRequest::getVar('usekey', '');
					if (!empty($usekey) || (int)$this->_rowId !== 0 || (!is_numeric($this->_rowId) && $this->_rowId != '')) {

						// $$$ hugh - once we have a few join elements, our select statements are
						// getting big enough to hit default select length max in MySQL.
						$listModel->setBigSelects();

						//otherwise lets get the table record
						$sql 	= $this->_buildQuery();
						$fabrikDb->setQuery($sql);
						FabrikHelperHTML::debug($fabrikDb->getQuery(), 'form:render');
						$rows = $fabrikDb->loadObjectList();
						if (is_null($rows)) {
							JError::raiseWarning(500, $fabrikDb->getErrorMsg());
						}
						JDEBUG ? $_PROFILER->mark('formmodel getData: rows data loaded') : null;
						//$$$ rob Ack above didnt work for joined data where there would be n rows rerutned frho "this rowid = $this->_rowId  \n";
						$data = array();
						foreach ($rows as &$row) {
							if (empty($data)) {
								//if loading in a rowid=-1 set the row id to the actual row id
								$this->_rowId = isset($row->__pk_val) ? $row->__pk_val : $this->_rowId;
							}
							$row = empty($row) ? array() : JArrayHelper::fromObject($row);
							$data[] = FArrayHelper::toObject(array_merge($row, JRequest::get('request')));
						}
						FabrikHelperHTML::debug($data, 'form:getData from querying rowid= '.$this->_rowId.' (form not in Mambot and no errors)');

						// if empty data return and trying to edit a record then show error
						//occurs if user trying to edit a record forbidden by a prefilter rull
						if (empty($data) && $this->_rowId != '') {
							// $$$ hugh - special case when using -1, if user doesn't have a record yet
							if (JRequest::getVar('rowid') == '-1') {
								return;
							}
							else {

								// if no key found set rowid to 0 so we can insert a new record.
								if (empty($usekey) && !$this->_isMambot) {
									$this->_rowId = 0;
									JError::raiseNotice(500, JText::sprintf('COULD NOT FIND RECORD IN DATABASE', $this->_rowId));
									return;
								} else {
									//if we are using usekey then theres a good possiblity that the record
									//won't yet exists- so in this case suppress this error message
									$this->_rowId = 0;
								}
							}
						}
					}
				}
				//no need to setJoinData if you are correcting a failed validation
				if (!empty($data)) {
					$this->setJoinData($data);
				}
			}
			//set the main part of the form's default data
			if ($this->_rowId != '') {
				$data = JArrayHelper::fromObject($data[0]);
			} else {
				//could be a view
				if ($listModel->isView()) {
					//@TODO test for new records from views
					$data = JArrayHelper::fromObject($data[0]);
				} else {
					if (($this->_isMambot || $this->saveMultiPage()) && (!empty($data) && is_object($data[0]))) {
						$data = JArrayHelper::fromObject($data[0]);
					}else{
						$data = JRequest::get('request');
					}
				}
			}

			$this->_listModel =& $listModel;
		}
		//Test to allow {$my->id}'s to be evald from query strings
		$w = new FabrikWorker();
		$data = $w->parseMessageForPlaceHolder($data);
		$this->_data =& $data;
		FabrikHelperHTML::debug($data, 'form:data');
		JDEBUG ? $_PROFILER->mark('queryselect: getData() end') : null;
		return $this->_data;
	}

	/**
	 * checks if user is logged in and form multipage settings to determine
	 * if the form saves to the session table on multipage navigation
	 * @return boolean
	 */

	function saveMultiPage()
	{
		$params =& $this->getParams();
		$session =& JFactory::getSession();
		//set in plugins such as confirmation plugin
		if ($session->has('com_fabrik.form.'.$this->getId().'.session.on')) {
			return true;
		}
		$save = (int)$params->get('multipage_save', 1);
		$user =& JFactory::getUser();
		if ($user->get('id') !== 0) {
			return $save === 0 ? false : true;
		} else {
			return $save === 2 ? true : false;
		}
	}

	/**
	 *
	 * if editing a record which contains repeated join data then on start $data is an
	 * array with each records being a row in the database.
	 *
	 * We need to take this structure and convert it to the same format as when the form
	 * is submitted
	 *
	 */

	function setJoinData(&$data)
	{
		$this->_joinDefaultData = array();
		if (empty($data)) {
			return;
		}
		if (!array_key_exists('join', $data[0])) {
			$data[0]->join = array();
		}
		// $$$ hugh - sometimes $data[0]->join is an object not an array?
		// $$$ rob - no longer as in render we use FarrayHelper to not recurse into data when setting to object
		// $$$ rob   readding back in - was needed with cdd in repeat groups
		if (is_object($data[0]->join)) {
		 $data[0]->join = JArrayHelper::fromObject($data[0]->join);
		}

		//no joins so leave !
		if (!is_array($this->_aJoinObjs)) {
			return;
		}

		if ($this->_rowId != '') {
			$groups =& $this->getGroupsHiarachy();
			foreach ($groups as $groupModel) {
				if ($groupModel->isJoin()) {
					$group =& $groupModel->getGroup();
					//$$$ rob - if loading data from session then the join structure is already in place so dont overwrite
					if (array_key_exists($group->join_id, $data[0]->join)) {
						continue;
					}
					$data[0]->join[$group->join_id] = array();
					$elementModels =& $groupModel->getMyElements();
					foreach ($elementModels as $elementModel) {

						$aNames = $elementModel->getJoinDataNames();
						foreach ($data as $row) {

							foreach ($aNames as $pair) {
								if (array_key_exists($pair[0], $row)) {
									$v = $row->$pair[0];
									if (strstr($row->$pair[0], GROUPSPLITTER2)) {
										$v = explode(GROUPSPLITTER2, $v);
									}
									$data[0]->join[$group->join_id][$pair[0]][] = $v;
									unset($row->$pair[0]);
								}
								/* $$$ hugh - seem to have a different format if just failed validation! */
								else if (array_key_exists($pair[1], $row)) {
									$v = $row->$pair[1];
									if (is_object($v)) {
										$v = JArrayHelper::fromObject($v);
									}
									$data[0]->join[$group->join_id][$pair[0]] = $v;
									unset($row->$pair[1]);
								}
							}
							/*if (array_key_exists($rawname, $row)) {
								$v = $row->$rawname;
								if (strstr($row->$rawname, GROUPSPLITTER2)) {
									$v = explode(GROUPSPLITTER2, $v);
								}
								$data[0]->join[$group->join_id][$rawname][] = $v;
								unset($row->$rawname);
							}
							// $$$ hugh - seem to have a different format if just failed validation!
							else if (array_key_exists($fv_rawname, $row)) {
								$v = $row->$fv_rawname;
								if (is_object($v)) {
									$v = JArrayHelper::fromObject($v);
								}
								$data[0]->join[$group->join_id][$rawname][] = $v;
								unset($row->$fv_rawname);
							}*/
						}
					}
				}
			}
		}
	}

	/**
	 * get the forms session data (used when using multipage forms)
	 *
	 * @return object session data
	 */

	function getSessionData()
	{
		$params =& $this->getParams();
		$this->sessionModel =& JModel::getInstance('Formsession', 'FabrikModel');
		$this->sessionModel->setFormId($this->getId());
		$this->sessionModel->setRowId($this->_rowId);
		$useCookie = (int)$params->get('multipage_save', 1) === 2 ? true : false;
		$this->sessionModel->useCookie($useCookie);
		return $this->sessionModel->load();
	}

	/**
	 * @access private
	 * create the sql query to get the rows data for insertion into the form
	 */

	function _buildQuery()
	{
		if (isset($this->query)) {
			return $this->query;
		}
		$db =& FabrikWorker::getDbo();
		$conf =& JFactory::getConfig();
		$form	=& $this->getForm();
		if (!$form->record_in_database) {
			return;
		}
		$listModel =& $this->getListModel();
		$table =& $listModel->getTable();

		$sql = $listModel->_buildQuerySelect();
		$sql .= $listModel->_buildQueryJoin();

		$emptyRowId = $this->_rowId === '' ? true : false;
		$random = JRequest::getVar('random');
		$usekey = JRequest::getVar('usekey');
		if ($usekey != '') {
			$usekey = explode('|', $usekey);
			foreach ($usekey as &$tmpk) {
				$tmpk = !strstr($tmpk, '.') ? $table->db_table_name.'.'.$tmpk : $tmpk;
				$tmpk = FabrikString::safeColName($tmpk);
			}
			if (!is_array($this->_rowId)) {
				$aRowIds = explode('|', $this->_rowId);
			}
		}
		$comparison = JRequest::getVar('usekey_comparison', '=');
		$viewpk = JRequest::getVar('view_primary_key');
		// $$$ hugh - changed this to !==, as in rowid=-1/usekey situations, we can have a rowid of 0
		// I don't THINK this will have any untoward side effects, but ...
		if (!$random && !$emptyRowId) {
			$sql .= " WHERE ";
			if (!empty($usekey)) {
				$sql .= "(";
				$parts = array();
				for ($k = 0; $k < count($usekey); $k++) {
					//ensure that the key value is not quoted as we Quote() afterwards
					if (strstr($aRowIds[$k], "'")) {
						$aRowIds[$k] = str_replace("'", '', $aRowIds[$k]);
					}
					if ($comparison == '=') {
						$parts[] = " ".$usekey[$k]." = ".$db->Quote($aRowIds[$k]);
					} else {
						$parts[] = " ".$usekey[$k]." LIKE ". $db->Quote("%".$aRowIds[$k]."%");
					}
				}
				$sql .= implode(" AND ", $parts);
				$sql .= ")";
			} else {
				$sql .= " $table->db_primary_key = ". $db->Quote($this->_rowId);
			}
		} else {
			if ($viewpk != '') {
				$sql .= " WHERE $viewpk ";
			} else if ($random) {
				// $$$ rob Should this not go after prefilters have been applied ?
				$sql .= " ORDER BY RAND() LIMIT 1 ";
			}
		}
		// get prefilter conditions from table and apply them to the record
		//the false, ignores any filters set by the table
		$where = $listModel->_buildQueryWhere(false);

		if (strstr($sql, 'WHERE') && $this->_rowId != '') {
			//do it this way as queries may contain subquerues which we want to keep the where
			$firstword = substr($where, 0, 5);
			if ($firstword == 'WHERE') {
				$where = substr_replace($where, 'AND', 0, 5);
			}
		}
		//set rowId to -2 to indicate random record
		if ($random) {
			$this->_rowId = -2;
		}
		// $$$ rob ensure that all prefilters are wrapped in brackets so that
		// only one record is loaded by the query - might need to set $word = and?
		if (trim($where) != '') {
			$where = explode(' ', $where);
			$word = array_shift($where);
			$sql .= $word . ' (' . implode(' ', $where) . ')';
		}
		if (!$random) {
			// $$$ rob if showing joined repeat groups we want to be able to order them as defined in the table
			$sql .= $listModel->_buildQueryOrder();
		}
		$this->query = $sql;
		return $sql;
	}

	/**
	 * After having saved the form we
	 * 1) Create a new group if none selected in edit form list
	 * 2) Delete all old form_group records
	 * 3) Recreate the form group records
	 * 4) Make a table view if needed
	 * @return bol true if you should display the form list, false if you're
	 * redirected elsewhere
	 */

	function saveFormGroups()
	{
		$db =& JFactory::getDBO();
		$current_groups_str = JRequest::getVar('current_groups_str', $this->_getFromGroupsStr(), 'post');
		$record_in_database = JRequest::getInt('record_in_database', $this->_form->record_in_database, 'post');
		$createGroup 				= JRequest::getInt('_createGroup', 0, 'post');
		$form =& $this->getForm();
		if ($createGroup) {
			$group = JTable::getInstance('Group', 'FabrikTable');
			$group->name = $form->label;
			$group->state = 1;
			$group->store();
			$current_groups_str .= "," . $db->insertid();
		}

		$db->setQuery("DELETE FROM #__fabrik_formgroup WHERE form_id = ".(int)$this->_id);
		// delete the old form groups
		if (!$db->query()) {
			JError::raiseError(500, $db->stderr());
		}

		$this->_makeFormGroups( $current_groups_str);

		if ($record_in_database == '1') {
			$listModel =& $this->getListModel();
			$dbTableName = ($listModel->_table->db_table_name == '') ? JRequest::getVar('_database_name', '', 'post') : $listModel->_table->db_table_name;
			// @TODO - sanitize table name, but not sure whether to do it here, or after the databaseTableExists() call.
			//$dbTableName = preg_replace('#[^0-9a-zA-Z_]#', '_', $dbTableName);

			$cnn =& $listModel->getConnection();
			// @TODO - allow user to select connection when saving form
			//$cnnId = JRequest::getVar('_connection_id', $cnn->_id, 'post');
			$table =& $listModel->getTable();

			$defaultDb =& $listModel->getDb();

			if (!$listModel->databaseTableExists($dbTableName, $defaultDb)) {
				// @TODO - need to sanitize table name (get rid of non alphanumeirc or _),
				// just not sure whether to do it here, or above (before we test for existinance)
				// $$$ hugh - for now, just do it here, after we test for the 'unsanitized', as
				// need to do some more testing on MySQL table name case sensitivity
				// BUT ... as we're potentially changing the table name after testing for existance
				// we need to test again.
				$dbTableName = preg_replace('#[^0-9a-zA-Z_]#', '_', $dbTableName);
				if ($listModel->databaseTableExists($dbTableName, $defaultDb)) {
					return JError::raiseWarning(500, JText::_("PROBLEM WITH DATABASE NAME"));
				}

				//need to pass the correct database obj here
				$listModel->createDBTable($this, $dbTableName, $defaultDb);

				$connection =& $listModel->getConnection();

				//NEW 2.0 create table view
				//enusre _tbl_key is set to 'id'
				$table->_tbl_key = 'id';
				$table->id = null;
				$table->label 				= $form->label;
				$table->form_id 			= $form->id;
				$table->connection_id = $connection->_id;
				$table->db_table_name	= $dbTableName;
				$table->db_primary_key = '`'.$dbTableName . '`.`id`';
				$table->auto_inc 			= 1;
				$table->state 				= $form->state;
				$table->created				= $form->created;
				$table->created_by		= $form->created_by;
				$table->attribs = $listModel->getDefaultAttribs();
				$res = $table->store();
				if ($res) {
					$listModel->_id = $table->getDBO()->insertid();
				} else {
					$fabrikDatabase =& $listModel->getDb();
					if ($dbTableName == '') {
						$dbTableName = JRequest::getVar('db_table_name', '', 'post');
						if ($dbTableName == '') {
							$table->load(JRequest::getInt('id', '', 'post'));
							$dbTableName = $table->db_table_name;
						}
					}
					$listModel = $this->getListModel();
					$listModel->ammendTable($this, $dbTableName, $fabrikDatabase);
				}
			} else {
				//update existing table
				$listModel->ammendTable($this);
				$listModel->makeSafeTableColumns();
			}
		}
	}

	function _makeFormGroups( $current_groups_str )
	{
		$db =& JFactory::getDBO();
		$orderid = 1;
		$current_groups = explode(",", $current_groups_str);
		foreach ($current_groups as $group_id) {
			if ($group_id != '') {
				$sql = "INSERT INTO #__fabrik_formgroup (form_id, group_id, ordering) VALUES (".(int)$this->_id.", ".(int)$group_id.", ".(int)$orderid.")";
				$db->setQuery($sql);
				if (!$db->query()) {
					JError::raiseError(500, $db->stderr());
				}
				$orderid ++;
			}
		}
	}

	/**
	 * attempts to determine if the form contains the element
	 * @param string element name to search for
	 * @param bool check search name against element id
	 * @return bol true if found, false if not found
	 */

	function hasElement($searchName, $checkInt = false)
	{
		$groups =& $this->getGroupsHiarachy();
		foreach ($groups as $groupModel) {
			$elementModels =& $groupModel->getMyElements();
			if (!is_array($groupModel->elements)) {
				continue;
			}
			foreach ($groupModel->elements as $elementModel) {
				$element =& $elementModel->getElement();
				if ($checkInt) {
					if ($searchName == $element->id) {
						$this->_currentElement = $elementModel;
						return true;
					}
				}
				if ($searchName == $element->name) {
					$this->_currentElement = $elementModel;
					return true;
				}
				if ($searchName == $elementModel->getFullName(true, true, false)) {
					$this->_currentElement = $elementModel;
					return true;
				}
				if ($searchName == $elementModel->getFullName(false, true, false)) {
					$this->_currentElement = $elementModel;
					return true;
				}
				if ($searchName == $elementModel->getFullName(true, false, false)) {
					$this->_currentElement = $elementModel;
					return true;
				}
				if ($searchName == $elementModel->getFullName(false, false, false)) {
					$this->_currentElement = $elementModel;
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * get an element
	 * @param string $searchName
	 * @param bool check search name against element id
	 * @return mixed ok: element model not ok: false
	 */

	function getElement($searchName, $checkInt = false)
	{
		if ($this->hasElement($searchName, $checkInt)) {
			return $this->_currentElement;
		} else {
			return false;
		}
	}

	/**
	 * @param object $viewModel
	 */

	function setTableModel(&$listModel)
	{
		$this->_listModel = $listModel;
	}

	/**
	 * is the page a multipage form?
	 * @return bol true/false
	 *
	 */

	function isMultiPage()
	{
		$groups =& $this->getGroupsHiarachy();
		foreach ($groups as $groupModel) {
			$params =& $groupModel->getParams();
			if ($params->get('split_page')) {
				return true;
			}
		}
		return false;
	}

	/**
	 * get an object of pages, key'd on page counter and containing an array of the page's group ids
	 *
	 * @return object
	 */

	function getPages()
	{
		if (!is_null($this->pages)) {
			return $this->pages;
		}
		$this->pages = new stdClass();
		$pageCounter = 0;
		$groups = $this->getGroupsHiarachy(); //dont use & as this buggers up in PHP 5.2.0
		$c = 0;
		foreach ($groups as $groupModel) {
			$params =& $groupModel->getParams();
			if ($params->get('split_page') && $c != 0 && $groupModel->canView()) {
				$pageCounter ++;
			}
			if ($groupModel->canView()) {
				if (!isset($this->pages->$pageCounter)) {
					$this->pages->$pageCounter = array();
				}
				array_push( $this->pages->$pageCounter, $groupModel->_id);
			}
			$c ++;
		}
		return $this->pages;
	}

	/**
	 * get the method that the form should use on submission
	 *
	 * @return string ajax/post
	 */

	function getPostMethod()
	{
		if (is_null($this->_postMethod)) {
			$this->_postMethod = JRequest::getVar('_postMethod', 'post');
			$groups =& $this->getGroupsHiarachy();
			foreach ($groups as $groupModel) {
				$elementModels =& $groupModel->getPublishedElements();
				foreach ($elementModels as $elementModel) {
					if ($elementModel->requiresAJAXSubmit()) {
						$this->_postMethod = 'ajax';
					}
				}
			}
		}
		return $this->_postMethod;
	}

	/**
	 * @since fabrik2.0rc1
	 * Used in special case where you have 2 + n-n joins in a single table
	 * In this case the sql query will most likely create four rows of data for
	 * each combination of possibilities
	 *
	 * E.g.
	 *
	 * tbl classes (id, label)
	 *
	 * left joined to:
	 * tbl student_classes (id, label, student_id)
	 *
	 * left joined to
	 * tbl student_teachers (id, label, teacher_id)
	 *
	 * entering one records with 2 students and 2 teachers gives you 4 rows in the query
	 *
	 * classid  student_id, teacher_id
	 * 1        1           1
	 * 1        2	          1
	 * 1        1	          2
	 * 1        2           2
	 *
	 * @param unknown_type $data
	 */

	function _reduceDataForXRepeatedJoins()
	{
		$groups = $this->getGroupsHiarachy();
		$listModel =& $this->getListModel();
		foreach ($groups as $groupModel) {
			if ($groupModel->canRepeat() && $groupModel->isJoin()) {

				$joinModel 	=& $groupModel->getJoinModel();
				$tblJoin 		=& $joinModel->getJoin();
				// $$$ hugh - slightly modified these lines so we don't create $this->_data['join'] if there is no
				// join data, because that then messes up code subsequent code that checks for empty($this->_data)
				if (!isset($this->_data['join'])) {
					//$this->_data['join'] = array();
					return;
				}
				if (!array_key_exists($tblJoin->id, $this->_data['join'])) {
					//return;
					continue;
				}

				$jdata 			=& $this->_data['join'][$tblJoin->id];
				$db 				=& $listModel->getDb();
				$db->setQuery("DESCRIBE ".$db->nameQuote($tblJoin->table_join));
				$fields = $db->loadObjectList();
				foreach ($fields as $f) {
					if ($f->Key == 'PRI') {
						$pkField = $tblJoin->table_join . "___" . $f->Field;
					}
				}
				$usedkeys = array();
				if (!empty($jdata) && array_key_exists($pkField, $jdata)) {
					foreach ($jdata[$pkField] as $key=>$value) {
						/*
						 * $$$rob
						 * added : || ($value === '' && !empty($this->_arErrors))
						 * this was incorrectly reducing empty data
						 * when re-viewing form after failed validation
						 * with a form with repeating groups (with empty data in the key fields
						 *
						 */
						if (!in_array($value, $usedkeys) || ($value === '' && !empty($this->_arErrors))) {
							$usedkeys[$key] = $value;
						}
					}
				}
				$keystokeep = array_keys($usedkeys);
				///remove unneeded data from array
				foreach ($jdata as $key =>$value) {
					foreach ($value as $key2=>$v) {
						if (!in_array($key2, $keystokeep)) {
							unset($jdata[$key][$key2]);
						}
					}
				}

				//reduce the keys so that we dont have keys of 0, 2
				foreach ($jdata as $key =>$array) {
					$jdata[$key] = array_values($array);
				}
			}
		}
	}

	/**
	 * query all active form plugins to see if they inject cutsom html into the top
	 * or bottom of the form
	 *
	 *return array plugin top html, plugin bottom html (inside <form>) plugin end (after form)
	 */

	function _getFormPluginHTML()
	{
		$pluginManager =& $this->getPluginManager();
		$formPlugins =& $pluginManager->getPlugInGroup('form');
		$form =& $this->getForm();

		$pluginManager->runPlugins('getBottomContent', $this, 'form');
		$pluginbottom = implode("<br />", array_filter($pluginManager->_data));

		$pluginManager->runPlugins('getTopContent', $this, 'form');
		$plugintop = implode("<br />", array_filter($pluginManager->_data));

		//inserted after the form's closing </form> tag
		$pluginManager->runPlugins('getEndContent', $this, 'form');
		$pluginend = implode("<br />", array_filter($pluginManager->_data));
		return array($plugintop, $pluginbottom, $pluginend);
	}

	function swapValuesForLabels(&$d)
	{
		$groups = $this->getGroupsHiarachy();
		foreach (array_keys($groups) as $gkey) {
			$group = $groups[$gkey];
			$elementModels =& $group->getPublishedElements();
			for ($j=0; $j < count($elementModels); $j++) {
				$elementModel = $elementModels[$j];
				$elkey = $elementModel->getFullName(false, true, false);
				$v = JArrayHelper::getValue($d, $elkey);
				if (is_array($v)) {
					$origdata = JArrayHelper::getValue($d, $elkey, array());
					foreach (array_keys($v) as $x) {
						$origval = JArrayHelper::getValue($origdata, $x);
						$d[$elkey][$x] = $elementModel->getLabelForValue($v[$x], $origval, $d);
					}
				} else {
					$d[$elkey] = $elementModel->getLabelForValue($v, JArrayHelper::getValue($d, $elkey), $d);
				}
			}
		}
	}

	/**
	 * Presuming that our introduction looks like this:
	 *
	 * {new:this is an intro}
	 * {edit:You're editing a record}
	 * some more text
	 *
	 * creating a new form record will show the intro text as:
	 *
	 * this is an intro
	 * some more text
	 *
	 * and editing an existing record will show:
	 *
	 * You're editing a record
	 * some more text
	 *
	 * @return string modified intro
	 */

	function getIntro()
	{
		$match = ((int)$this->_rowId === 0) ? 'new' : 'edit';
		$remove = ((int)$this->_rowId === 0) ? 'edit' : 'new';
		$match = "/{".$match.":\s*.*?}/i";
		$remove = "/{".$remove.":\s*.*?}/i";
		$intro =& $this->getForm()->intro;
		$orig_intro = $intro;
		$intro = preg_replace_callback( $match, array($this, '_getIntro'), $intro);
		$intro = preg_replace($remove, '', $intro);
		if ($orig_intro != $intro) {
			// $$$ special case hack for placeholders / plugin calls if using new/edit method,
			// as regex can't handle nested replacements.  So use [] instead of {} like this ...
			// {new: this is a [placeholder]}{edit: this is a plugin [jumi blah]}
			$intro = str_replace('[','{', $intro);
			$intro = str_replace(']','}', $intro);
		}
		if (strpos($intro, '{')) {
			$data = $this->_data;
			$this->swapValuesForLabels($data);
			$w = new FabrikWorker();
			$intro = $w->parseMessageForPlaceHolder($intro, $data, true);
		}
		//$intro = str_replace('{','[', $intro);
		//$intro = str_replace('}',']', $intro);
		return $intro;
	}

	/**
	 * used from getIntro as preg_replace_callback function to strip
	 * undeisred text from form label intro
	 * @param array $match
	 * @return string intro text
	 */

	private function _getIntro($match)
	{
		$m = explode(":", $match[0]);
		array_shift($m);
		return FabrikString::rtrimword(implode(":", $m ) , "}");
	}

	/**
	 *
	 * @return string label
	 */

	function getLabel()
	{
		$label = $this->getForm()->label;
		if (!$this->_editable) {
			return str_replace("{Add/Edit}", '', $label);
		}
		if (JString::stristr($label, "{Add/Edit}")) {
			$replace = ((int)$this->_rowId === 0) ? JText::_('ADD') : JText::_('EDIT');
			$label = str_replace("{Add/Edit}", $replace, $label);
		}
		return $label;
	}

	/** currently only called from listModel _createLinkedForm when copying existing table
	 *
	 * @return object form table
	 */

	function copy()
	{
		//array key = old id value new id
		$this->groupidmap = array();
		$groups = $this->getGroups();
		$form =& $this->getForm();
		$form->id = 0;
		// rob newFormLabel set in table copy
		if (JRequest::getVar('newFormLabel', '') !== '') {
			$form->label = JRequest::getVar('newFormLabel');
		}
		$form->store();

		$newElements = array();
		foreach ($groups as $group) {
			$oldid = $group->_id;
			// $$$rob use + rather than array_merge to preserve keys
			$group->_newFormid = $form->id;
			$newElements = $newElements + $group->copy();

			$this->groupidmap[$oldid] = $group->getGroup()->id;
		}
		//need to do finalCopyCheck() on form elements

		$pluginManager =& $this->getPluginManager();

		$elementModel =& JModel::getInstance('Element', 'FabrikModel');
		//@TODO something not right here when copying a cascading dropdown element in a join group
		foreach ($newElements as $origId => $newId) {
			$plugin = $pluginManager->getElementPlugin($newId);
			$plugin->finalCopyCheck($newElements);
		}
		return $form;
	}

	/**
	 * if you have koowa installed their db obj doesnt have a getNullDate function
	 * @return unknown_type
	 */

	function getNullDate()
	{
		return '0000-00-00 00:00:00';
	}


	public function getRelatedTables()
	{
		$links = array();
		$params =& $this->getParams();
		if (!$params->get('show-referring-table-releated-data', false)) {
			return $links;
		}
		$db =& JFactory::getDBO();
		$listModel =& $this->getListModel();
		//
		$referringTable =& JModel::getInstance('Table', 'FabrikModel');
		// $$$ rob - not sure that referring_table is anything other than the form's table id
		// but for now just defaulting to that if no other variable found (e.g when links in sef urls)
		$tid = JRequest::getInt('referring_table', JRequest::getInt('tableid', $listModel->getTable()->id));
		$referringTable->setId($tid);
		$tmpKey 	= '__pk_val';
		$tableParams =& $referringTable->getParams();
		$table =& $referringTable->getTable();
		$joinsToThisKey =& $referringTable->getJoinsToThisKey();
		$linksToForms =&  $referringTable->getLinksToThisKey();

		$row =& $this->getData();
		$linkedTables						= $tableParams->get('linkedtable', '', '_default', 'array');
		$aExisitngLinkedForms 	= $tableParams->get('linkedform', '', '_default', 'array');
		$linkedform_linktype 		= $tableParams->get('linkedform_linktype', '', '_default', 'array');
		$linkedtable_linktype 	= $tableParams->get('linkedtable_linktype', '', '_default', 'array');
		$f = 0;

		$sql = "SELECT id, label, db_table_name FROM #__fabrik_tables";
		$db->setQuery($sql);
		$aTableNames = $db->loadObjectList('label');

		foreach ($joinsToThisKey as $element) {
			$qsKey	= $this->getListModel()->getTable()->db_table_name . "___" . $element->name;
			$val 		= JRequest::getVar($qsKey);
			if ($val == '') {
				//default to row id if we are coming from a main link (and not a related data link)
				$val = JRequest::getVar($qsKey . "_raw", JRequest::getVar('rowid'));
			}
			$linkedTable 	= JArrayHelper::getValue($linkedTables, $f, false);

			if ($linkedTable != false) {
				// $$$ hugh - changed to use _raw as key, see:
				// http://fabrikar.com/forums/showthread.php?t=20020
				$linkKey = $element->db_table_name . "___" . $element->name;
				$linkKeyRaw = preg_match("#.*_raw$#", $linkKey) ? $linkKey : $linkKey . "_raw";
				$popUpLink 		= JArrayHelper::getValue($linkedtable_linktype, $f, false);
				$recordCounts =& $referringTable->getRecordCounts($element);
				$count = is_array($recordCounts) && array_key_exists($val, $recordCounts) ? $recordCounts[$val]->total : 0;
				$element->table_id = (array_key_exists($element->tablelabel, $aTableNames)) ?  $aTableNames[$element->tablelabel]->id : '';
				$links[$element->table_id][] = $referringTable->viewDataLink($popUpLink, $element, null, $linkKey, $val, $count, $f);
			}

			$f ++;
		}
		$f = 0;
		//create columns containing links which point to forms assosciated with this table
		foreach ($linksToForms as $element) {
			$linkedForm 	= JArrayHelper::getValue($aExisitngLinkedForms, $f, false);
			$popUpLink 		= JArrayHelper::getValue($linkedform_linktype, $f, false);

			if ($linkedForm !== '0') {
				if (is_object($element)) {
					//$$$rob moved these two lines here as there were giving warnings since Hugh commented out the if ($element != '') {
					// $$$ hugh - what?  Eh?  WhaddidIdo?  Anyway, we use $linkKey up ^^ there somewhere, so we need to define it earlier!
					$linkKey	= @$element->db_table_name . "___" . @$element->name;
					//$linkKey	= $this->getListModel()->getTable()->db_table_name . "___" . $element->name;
					$val = JRequest::getVar($linkKey);
					if ($val == '') {
						$val = JRequest::getVar($qsKey . "_raw", JRequest::getVar('rowid'));
					}
					$links[$element->table_id][] = $referringTable->viewFormLink($popUpLink, $element, null, $linkKey, $val, false, '');
				}
			}
			$f ++;
		}
		return $links;
	}

	/**
	 * get the url to use as the form's action property
	 * @return string url
	 */
	function getAction()
	{
		$app = &JFactory::getApplication();
		// Get the router
		$router = &$app->getRouter();
		if ($app->isAdmin()) {
			$action = JArrayHelper::getValue($_SERVER, 'REQUEST_URI', 'index.php');
			// $$$rob no good for cck form?
			//return "index.php";
			return $action;
		}
		if ((int)$this->_packageId !== 0) {
			$action = 'index.php?option=com_fabrik&view=form&fabrik='.$this->getId();
			return $action;
		}
		$option = JRequest::getCmd('option');

		if ($option === 'com_fabrik') {
			$page = "index.php?";
			//get array of all querystring vars
			$queryvars = $router->parse(JFactory::getURI());

			if ($this->getPostMethod() === 'ajax') {
				$queryvars['format'] = 'raw';
				//@TODO this should prb be views or controllers now?
				$queryvars['controller'] = "form";
				$queryvars['view'] = 'form';
			}
			$qs = array();
			foreach ($queryvars as $k => $v) {
				if ($k == 'rowid') {
					$v = $this->getRowId();
				}
				// $$$ hugh - things get weird if we have been passed a urlencoded URL as a qs arg,
				// which the $router->parse() above will have urldecoded, and it gets used as part of the URI path
				// when we JRoute::_() below.  So we need to re-urlencode stuff and junk.
				// Ooops, make sure it isn't an array, which we'll get if they have something like
				// &table___foo[value]=bar
				if (!is_array($v)) {
					$v = urlencode($v);
				}
				$qs[] = "$k=$v";
			}
			$action = $page . implode("&amp;",$qs);

			$action = JRoute::_($action);
		} else {
			//in plugin & SEF URLs
			if ((int)$router->getMode() === (int)JROUTER_MODE_SEF) {
				//$$$ rob if embedding a form in a form, then the embedded form's url will contain
				// the id of the main form - not sure if its an issue for now
				$action = JArrayHelper::getValue($_SERVER, 'REQUEST_URI', 'index.php');
			} else {
				// in plugin and no sef (routing dealt with in form controller)
				$action = 'index.php';
			}
		}
		return $action;
	}

	/**
	 * if the group is a joined group we want to ensure that its id field is contained with in the group's elements
	 *
	 * @param object $groupTable
	 * @return string html hidden field
	 */

	function _makeJoinIdElement(&$groupTable )
	{
		$listModel =& $this->getListModel();
		$joinId = $this->_aJoinGroupIds[$groupTable->id];
		$element 			= new stdClass();
		//add in row id for join data
		$element->label = '';
		$element->error = '';
		$element->value = '';
		$element->id = '';
		$element->className = '';
		$element->containerClass = '';
		foreach ($listModel->getJoins() as $oJoin) {
			if ($oJoin->id == $joinId) {
				$key = $oJoin->table_join . $this->_joinTableElementStep . $oJoin->table_join_key;

				if (array_key_exists('join', $this->_data)) {
					// $$$ rob if join element is a db join the data $key contains label and not foreign key value
					if (@array_key_exists($key . "_raw", $this->_data['join'][$joinId])) {
						$val = $this->_data['join'][$joinId][$key . "_raw"];
					} else {
						$val = @$this->_data['join'][$joinId][$key];
					}
					if (is_array($val)) {
						if (array_key_exists(0,$val)) {
							$val = $val[0];
						}
						else {
							$val = '';
						}
					}
				} else {
					$val = '';
				}
				if ($val == '') {
					//somethings gone wrong - lets take the main table's key
					$k = $oJoin->join_from_table. $this->_joinTableElementStep . $oJoin->table_key;
					$val = @$this->_data[$k];
				}
				$element->value = $val;
				$element->element = '<input type="hidden" id="join.' . $joinId . '.rowid" name="join[' . $joinId . '][rowid]" value="' . $val . '" />';
				$element->hidden = true;
				$element->containerClass = 'fabrikElementContainer  fabrikHide';
			}
		}
		return $element;
	}

	public function getreadOnlyVals()
	{
		return $this->readOnlyVals;
	}

	/**
	 * prepare the elements for rendering
	 * @param string $tmpl @since 3.0
	 */
	public function getGroupView($tmpl = '')
	{
		if (isset($this->groupView)) {
			return $this->groupView;
		}
		// $$$rob - do regardless of whether form is editable as $data is required for hidden encrypted fields
		// and not used anywhere else (avoids a warning message)
		$data = array();
		foreach ($this->_data as $key => $val) {
			if (is_string($val)) {
				$data[$key] = htmlspecialchars($val, ENT_QUOTES);
			}
		}

		$this->groupView = array();
		$this->readOnlyVals = array();
		// $$$ hugh - temp foreach fix
		$groups = $this->getGroupsHiarachy();

		$postedRepeatGroupCount = (array) JRequest::getVar('fabrik_repeat_group');
		foreach ($groups as $gkey => $groupModel) {
			$groupTable =& $groupModel->getGroup();
			$group = $groupModel->getGroupProperties($this);
			$groupParams =& $groupModel->getParams();
			$aElements = array();
			//check if group is acutally a table join

			if (array_key_exists($groupTable->id, $this->_aJoinGroupIds)) {
				$aElements[] = $this->_makeJoinIdElement($groupTable);
			}

			$repeatGroup = 1;
			$foreignKey = null;
			$startHidden = false;

			if ($groupModel->canRepeat()) {
				if ($groupModel->isJoin()) {

					$joinTable =& $groupModel->getJoinModel()->getJoin();
					$foreignKey  = '';
					if (is_object($joinTable)) {
						$fullFk = $joinTable->table_join . "___" . $joinTable->table_join_key;
						//need to duplicate this perhaps per the number of times
						//that a repeat group occurs in the default data?

						// $$$ rob added check that the join data is not empty which seems to occur on a new form, without it the warning about no
						// published fk is raised incorrectly
						// $$$ hugh - we have some code that relias on $model->_data being empty for new forms
						//if (!isset($this->_data['join'])) {
						//$this->_data['join'] = array();
						//}
						if (isset($this->_data['join']) && array_key_exists($joinTable->id, $this->_data['join']) && !empty($this->_data['join'][$joinTable->id])) {
							$elementModels =& $groupModel->getPublishedElements();
							reset($elementModels);
							$tmpElement = current($elementModels);
							$smallerElHTMLName = $tmpElement->getFullName(false, true, false);
							$repeatGroup = count($this->_data['join'][$joinTable->id][$smallerElHTMLName]);
							if (!array_key_exists($fullFk, $this->_data['join'][$joinTable->id])) {
								JError::raiseWarning(E_ERROR, JText::sprintf('JOINED_DATA_BUT_FK_NOT_PUBLISHED', $fullFk));
								$startHidden = false;
							} else {
								// show empty groups if we are validating a posted form
								if (JRequest::getCmd('task') !== 'processForm') {
									$fkData = $this->_data['join'][$joinTable->id][$fullFk];
									if ($this->sessionModel->row->data === '') {
										// $$$rob first and only group should be hidden. (someone saved a repeat group with no rows selected
										$startHidden = (count($fkData) === 1 &&  $fkData[0] == '') ? true : false;
									}
								} else {
									$startHidden = JArrayHelper::getValue($postedRepeatGroupCount, $groupTable->id) == 0 ? true : false;
								}
							}
						} else {
							if (!$groupParams->get('repeat_group_show_first')) {
								continue;
							}
						}
					}
				} else {
					// repeat groups which aren't joins
					$elementModels =& $groupModel->getPublishedElements();
					foreach ($elementModels as $tmpElement) {
						$smallerElHTMLName = $tmpElement->getFullName(false, true, false);
						// $$$ rob use the raw data if it exists
						// otherwise if you have just one dbjoin el in a repeat group the data would contain
						// the first label only.e.g.
						//[table___dbjoin_raw] => 1//..*..//2
						//[table___dbjoin_raw] => one
						// you could argue that it should be:
						//[table___dbjoin_raw] => one//..*..//two
						// but it isnt at the moment

						if (array_key_exists($smallerElHTMLName . '_raw', $this->_data)) {
							$d = $this->_data[$smallerElHTMLName . '_raw'];
						} else {
							$d = @$this->_data[$smallerElHTMLName];
						}
						if (is_string($d) && strstr($d, GROUPSPLITTER)) {
							$d = explode(GROUPSPLITTER, $d);
						}
						$c = count($d);
						if ($c > $repeatGroup) { $repeatGroup = $c;}
					}
				}
			}
			$groupModel->_repeatTotal = $startHidden ? 0 : $repeatGroup;
			$aSubGroups = array();
			for ($c = 0; $c < $repeatGroup; $c++) {
				$aSubGroupElements = array();
				$elCount = 0;
				$elementModels =& $groupModel->getPublishedElements();

				foreach ($elementModels as $elementModel) {
					$elementModel->tmpl = $tmpl;
					//$$$rob test don't include the element in the form is we can't use and edit it
					//test for captcha element when user logged in

					if (!$this->_editable) {
						$elementModel->_inDetailedView = true;
					}

					if (!$this->_editable && !$elementModel->canView()) {
						continue;
					}
					$elementModel->_foreignKey = $foreignKey;
					$elementModel->_repeatGroupTotal = $repeatGroup - 1;

					$element = $elementModel->preRender($c, $groupModel, $this, $elCount);

					if (!$element || ($elementModel->canView() && !$elementModel->canUse()))
					{
						// $$$ hugh - $this->data doesn't seem to always have what we need in it, but $data does.
						// can't remember exact details, was chasing a nasty issue with encrypted 'user' elements.

						// $$$ rob HTMLName seems not to work for joined data in confirmation plugin
						//$this->readOnlyVals[$elementModel->getHTMLName($c )] = $elementModel->getValue($this->data);
						$elementModel->getValuesToEncrypt($this->readOnlyVals, $data, $c);
						$this->readOnlyVals[$elementModel->getFullName(false, true, false )]['repeatgroup'] = $groupModel->canRepeat();
						$this->readOnlyVals[$elementModel->getFullName(false, true, false )]['join'] = $groupModel->isJoin();
					}
					if ($element) {
						$elementModel->stockResults($element, $aElements, $this->data, $aSubGroupElements);
					}
					if ($element && !$element->hidden) {
						$elCount ++;
					}
				}
				//if its a repeatable group put in subgroup
				if ($groupModel->canRepeat()) {
					$aSubGroups[] = $aSubGroupElements;
				}
			}
			$groupModel->randomiseElements($aElements);

			$group->elements = $aElements;
			$group->subgroups = $aSubGroups;
			$group->startHidden = $startHidden;
			//only create the group if there are some element inside it
			if (count($aElements) != 0) {
				//28/01/2011 $$$rob and if it is published
				$showGroup = $groupParams->get('repeat_group_show_first');
				if ($showGroup != -1) {
					if (!($showGroup == 2 && $this->_editable)) {
						$this->groupView[$group->name] = $group;
					}
				}
			}
		}
		return $this->groupView;
	}


	function getLinkedFabrikTables($table)
	{
		//get any fabrik tables that link to the join table
		if (!isset($this->_linkedFabrikTables)) {
			$this->_linkedFabrikTables = array();
		}
		if (!array_key_exists($table, $this->_linkedFabrikTables)) {
			$db		=& JFactory::getDBO();
			if (trim($table == '')) {
				return array();
			} else {
				$db->setQuery("SELECT * FROM #__fabrik_tables WHERE db_table_name = ".$db->Quote($table));
			}
			$this->_linkedFabrikTables[$table] = $db->loadResultArray();
		}
		return $this->_linkedFabrikTables[$table];
	}

	function updatedByPlugin($fullname = '') {
		// used to see if something legitimate in the submission process, like a form plugin,
		// has modified an RO element value and wants to override the RO/origdata.
		return array_key_exists($fullname, $this->_pluginUpdatedElements);
	}

	/**
	* Does the form contain user errors
	*
	* @return  bool
	*/

	public function hasErrors()
	{

		$errorsFound = !empty($this->_arErrors);

		if ($this->saveMultiPage(false))
		{
			$srow = $this->getSessionData();
			/*
			 * Test if its a resumed paged form
			* if so _arErrors will be filled so check all elements had no errors
			*/
			$multiPageErrors = false;
			if ($srow->data != '')
			{
				foreach ($this->_arErrors as $err)
				{
					if (!empty($err[0]))
					{
						$multiPageErrors = true;
					}
				}
				if (!$multiPageErrors)
				{
					$errorsFound = false;
				}
			}
		}
		return $errorsFound;
	}

}

?>