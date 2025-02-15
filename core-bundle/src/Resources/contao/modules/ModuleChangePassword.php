<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

use Patchwork\Utf8;

/**
 * Front end module "change password".
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class ModuleChangePassword extends Module
{
	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate = 'mod_changePassword';

	/**
	 * Display a wildcard in the back end
	 *
	 * @return string
	 */
	public function generate()
	{
		$container = System::getContainer();
		$request = $container->get('request_stack')->getCurrentRequest();

		if ($request && $container->get('contao.routing.scope_matcher')->isBackendRequest($request))
		{
			$objTemplate = new BackendTemplate('be_wildcard');
			$objTemplate->wildcard = '### ' . Utf8::strtoupper($GLOBALS['TL_LANG']['FMD']['changePassword'][0]) . ' ###';
			$objTemplate->title = $this->headline;
			$objTemplate->id = $this->id;
			$objTemplate->link = $this->name;
			$objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

			return $objTemplate->parse();
		}

		// Return if there is no logged in user
		if (!$container->get('contao.security.token_checker')->hasFrontendUser())
		{
			return '';
		}

		return parent::generate();
	}

	/**
	 * Generate the module
	 */
	protected function compile()
	{
		/** @var PageModel $objPage */
		global $objPage;

		$this->import(FrontendUser::class, 'User');

		$GLOBALS['TL_LANGUAGE'] = $objPage->language;

		System::loadLanguageFile('tl_member');
		$this->loadDataContainer('tl_member');

		// Call onload_callback (e.g. to check permissions)
		if (\is_array($GLOBALS['TL_DCA']['tl_member']['config']['onload_callback']))
		{
			foreach ($GLOBALS['TL_DCA']['tl_member']['config']['onload_callback'] as $callback)
			{
				if (\is_array($callback))
				{
					$this->import($callback[0]);
					$this->{$callback[0]}->{$callback[1]}();
				}
				elseif (\is_callable($callback))
				{
					$callback();
				}
			}
		}

		// Old password widget
		$arrFields['oldPassword'] = array
		(
			'name'      => 'oldpassword',
			'label'     => &$GLOBALS['TL_LANG']['MSC']['oldPassword'],
			'inputType' => 'text',
			'eval'      => array('mandatory'=>true, 'preserveTags'=>true, 'hideInput'=>true),
		);

		// New password widget
		$arrFields['newPassword'] = $GLOBALS['TL_DCA']['tl_member']['fields']['password'];
		$arrFields['newPassword']['name'] = 'password';
		$arrFields['newPassword']['label'] = &$GLOBALS['TL_LANG']['MSC']['newPassword'];

		$row = 0;
		$strFields = '';
		$doNotSubmit = false;
		$objMember = MemberModel::findByPk($this->User->id);
		$strFormId = 'tl_change_password_' . $this->id;
		$strTable = $objMember->getTable();
		$session = System::getContainer()->get('session');
		$flashBag = $session->getFlashBag();

		// Initialize the versioning (see #8301)
		$objVersions = new Versions($strTable, $objMember->id);
		$objVersions->setUsername($objMember->username);
		$objVersions->setUserId(0);
		$objVersions->setEditUrl('contao/main.php?do=member&act=edit&id=%s&rt=1');
		$objVersions->initialize();

		/** @var FormTextField $objOldPassword */
		$objOldPassword = null;

		/** @var FormPassword $objNewPassword */
		$objNewPassword = null;

		// Initialize the widgets
		foreach ($arrFields as $strKey=>$arrField)
		{
			/** @var Widget $strClass */
			$strClass = $GLOBALS['TL_FFL'][$arrField['inputType']];

			// Continue if the class is not defined
			if (!class_exists($strClass))
			{
				continue;
			}

			$arrField['eval']['required'] = $arrField['eval']['mandatory'];

			/** @var Widget $objWidget */
			$objWidget = new $strClass($strClass::getAttributesFromDca($arrField, $arrField['name']));

			$objWidget->storeValues = true;
			$objWidget->rowClass = 'row_' . $row . (($row == 0) ? ' row_first' : '') . ((($row % 2) == 0) ? ' even' : ' odd');

			// Increase the row count if it is a password field
			if ($objWidget instanceof FormPassword)
			{
				$objWidget->rowClassConfirm = 'row_' . ++$row . ((($row % 2) == 0) ? ' even' : ' odd');
			}

			++$row;

			// Store the widget objects
			$strVar  = 'obj' . ucfirst($strKey);
			$$strVar = $objWidget;

			// Validate the widget
			if (Input::post('FORM_SUBMIT') == $strFormId)
			{
				$objWidget->validate();

				// Validate the old password
				if ($strKey == 'oldPassword')
				{
					$encoder = System::getContainer()->get('security.encoder_factory')->getEncoder(FrontendUser::class);

					if (!$encoder->isPasswordValid($objMember->password, $objWidget->value, null))
					{
						$objWidget->value = '';
						$objWidget->addError($GLOBALS['TL_LANG']['MSC']['oldPasswordWrong']);
						sleep(2); // Wait 2 seconds while brute forcing :)
					}
				}

				if ($objWidget->hasErrors())
				{
					$doNotSubmit = true;
				}
			}

			$strFields .= $objWidget->parse();
		}

		$this->Template->fields = $strFields;
		$this->Template->hasError = $doNotSubmit;

		// Store the new password
		if (!$doNotSubmit && Input::post('FORM_SUBMIT') == $strFormId)
		{
			$objMember->tstamp = time();
			$objMember->password = $objNewPassword->value;
			$objMember->save();

			// Create a new version
			if ($GLOBALS['TL_DCA'][$strTable]['config']['enableVersioning'])
			{
				$objVersions->create();
			}

			// HOOK: set new password callback
			if (isset($GLOBALS['TL_HOOKS']['setNewPassword']) && \is_array($GLOBALS['TL_HOOKS']['setNewPassword']))
			{
				foreach ($GLOBALS['TL_HOOKS']['setNewPassword'] as $callback)
				{
					$this->import($callback[0]);
					$this->{$callback[0]}->{$callback[1]}($objMember, $objNewPassword->value, $this);
				}
			}

			// Update the current user so they are not logged out automatically
			$this->User->findBy('id', $objMember->id);

			// Check whether there is a jumpTo page
			if (($objJumpTo = $this->objModel->getRelated('jumpTo')) instanceof PageModel)
			{
				$this->jumpToOrReload($objJumpTo->row());
			}

			$flashBag->set('mod_changePassword_confirm', $GLOBALS['TL_LANG']['MSC']['newPasswordSet']);
			$this->reload();
		}

		// Confirmation message
		if ($session->isStarted() && $flashBag->has('mod_changePassword_confirm'))
		{
			$arrMessages = $flashBag->get('mod_changePassword_confirm');
			$this->Template->message = $arrMessages[0];
		}

		$this->Template->formId = $strFormId;
		$this->Template->slabel = StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['changePassword']);
		$this->Template->rowLast = 'row_' . $row . ' row_last' . ((($row % 2) == 0) ? ' even' : ' odd');
	}
}

class_alias(ModuleChangePassword::class, 'ModuleChangePassword');
