<?php
/**
 *
 * @package fabrikar
 * @author Hugh Messenger
 * @copyright (C) Hugh Messenger
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

// Check to ensure this file is included in Joomla!
defined('_JEXEC') or die();

//require the abstract plugin class
require_once(COM_FABRIK_FRONTEND.DS.'models'.DS.'plugin.php');
require_once(COM_FABRIK_FRONTEND.DS.'models'.DS.'validation_rule.php');

class FabrikModelEmailexists extends FabrikModelValidationRule {

	var $_pluginName = 'emailexists';

	/** @param string classname used for formatting error messages generated by plugin */
	var $_className = 'emailexists';

	/**
	 * validate the elements data against the rule
	 * @param string data to check
	 * @param object element
	 * @param int plugin sequence ref
	 * @param int repeat group count
	 * @return bol true if validation passes, false if fails
	 */

	function validate($data, &$element, $c,  $repeat_count = 0)
	{
		if (empty($data)) {
			return false;
		}
		$params =& $this->getParams();
		$ornot = $params->get('emailexists_or_not', '_default','array', $c);
		$ornot = JArrayHelper::getValue($ornot, $c, 'fail_if_exists');

		jimport('joomla.user.helper');
		$user = JFactory::getUser();

		$db = JFactory::getDBO();
		$db->setQuery("SELECT id FROM #__users WHERE email = ".$db->Quote($data));
		$result = $db->loadResult();

		if ($user->get('guest')){
			if (!$result) {
				if ($ornot == 'fail_if_exists') {
					return true;
				}
			} else {
				if ($ornot == 'fail_if_not_exists') {
					return true;
				}
			}
			return false;
		} else {
			if (!$result) {
				if ($ornot == 'fail_if_exists') {
					return true;
				}else{
					return false;
				}
			} else {
				if ($result == $user->get('id')) // The connected user is editing his own data
				{
					if ($ornot == 'fail_if_exists') {
						return true;
					} else {
						return false;
					}
				}
				return false;
			}
		}
	}

	/**
	 *  renders admin settings
	 */
/*
	function renderAdminSettings($elementId, &$row, &$params, $c)
	{
		$params->_counter_override = $this->_counter;
		$display =  ($this->_adminVisible) ? "display:block" : "display:none";
		$return = '<div class="page-' . $elementId . ' validationSettings" style="' . $display . '">'
		. $params->render('params', '_default', false, $c);
		$return .= '</div>';
		$return = str_replace("\r", "", $return);
		return $return;
		//dont do here as if we json enocde it as we do in admin form view things go wrong
		//return  addslashes(str_replace("\n", "", $return));
	}
*/
	
}
?>