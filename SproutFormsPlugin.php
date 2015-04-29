<?php
namespace Craft;

class SproutFormsPlugin extends BasePlugin
{
	public function getName()
	{
		$pluginName         = Craft::t('Sprout Forms');
		$pluginNameOverride = $this->getSettings()->pluginNameOverride;

		return ($pluginNameOverride) ? $pluginNameOverride : $pluginName;
	}

	public function getVersion()
	{
		return '0.9.0';
	}

	public function getDeveloper()
	{
		return 'Barrel Strength Design';
	}

	public function getDeveloperUrl()
	{
		return 'http://barrelstrengthdesign.com';
	}

	public function hasCpSection()
	{
		return true;
	}

	public function init()
	{
		Craft::import('plugins.sproutforms.fields.ISproutFormsFieldType');
		Craft::import('plugins.sproutforms.fields.BaseSproutFormsFieldType');

		craft()->on('email.onBeforeSendEmail', array(sproutForms(), 'handleOnBeforeSendEmail'));
	}

	/**
	 * @return array
	 */
	protected function defineSettings()
	{
		return array(
			'pluginNameOverride'     => AttributeType::String,
			'templateFolderOverride' => AttributeType::String
		);
	}

	public function registerCpRoutes()
	{
		return array(

			/*
			 * Create New Form
			 * @controller SproutForms_FormsController
			 * @method     actionEditFormTemplate
			 * @template   sproutforms/templates/forms/_editForm.html
			 */
			'sproutforms/forms/new'                                          => array(
				'action' => 'sproutForms/forms/editFormTemplate'
			),
			/*
			 * Create New Form
			 * @controller SproutForms_FormsController
			 * @method     actionEditFormTemplate
			 * @template   sproutforms/templates/forms/_editForm.html
			 */
			'sproutforms/forms/edit/(?P<formId>\d+)'                         => array(
				'action' => 'sproutForms/forms/editFormTemplate'
			),
			/*
			 * Create New Field
			 * @controller SproutForms_FieldsController
			 * @method     actionEditFieldTemplate
			 * @template   sproutforms/templates/forms/_editField.html
			 */
			'sproutforms/forms/(?P<formId>\d+)/fields/new'                   => array(
				'action' => 'sproutForms/fields/editFieldTemplate'
			),
			/*
			 * Edit Field
			 * @controller SproutForms_FieldsController
			 * @method     actionEditFieldTemplate
			 * @template   sproutforms/templates/forms/_editField.html
			 */
			'sproutforms/forms/(?P<formId>\d+)/fields/edit/(?P<fieldId>\d+)' => array(
				'action' => 'sproutForms/fields/editFieldTemplate'
			),
			/*
			 * Edit Entry
			 * @controller SproutForms_EntriesController
			 * @method     actionEditEntryTemplate
			 * @template   sproutforms/templates/entries/_edit.html
			 */
			'sproutforms/entries/edit/(?P<entryId>\d+)'                      => array(
				'action' => 'sproutForms/entries/editEntryTemplate'
			),
			/*
			 * Edit Settings
			 * @controller SproutSeo_SettingsController
			 * @method     actionSettingsIndexTemplate
			 * @template   sproutforms/templates/settings/index.html
			 */
			'sproutforms/settings'                                           => array(
				'action' => 'sproutForms/settings/settingsIndexTemplate'
			),
			/*
			 * Filter Forms by Group
			 */
			'sproutforms/forms/(?P<groupId>\d+)'                             =>
				'sproutforms/forms',
			/*
			 * Example Form installation page
			 */
			'sproutforms/examples'                                           =>
				'sproutforms/_cp/examples',

		);
	}

	public function registerUserPermissions()
	{
		return array(
			'editSproutFormsSettings' => array(
				'label' => Craft::t('Edit Form Settings')
			)
		);
	}

	/**
	 * Event registrar
	 *
	 * @param string   $event
	 * @param \Closure $callback
	 *
	 * @deprecate Deprecated for version 0.9.0 in favour of defineSproutEmailEvents()
	 */
	public function sproutformsAddEventListener($event, \Closure $callback)
	{
		switch ($event)
		{
			case 'saveEntry':
			{
				// only event supported at this time
				craft()->on('sproutForms.saveEntry', $callback);
				break;
			}
		}
	}

	/**
	 * @return array
	 */
	public function defineSproutEmailEvents()
	{
		$sproutEmail = craft()->plugins->getPlugin('sproutEmail');

		if ($sproutEmail && version_compare($sproutEmail->getVersion(), '0.9.2', '>='))
		{
			require_once dirname(__FILE__).'/integrations/sproutemail/SproutForms_SaveEntryEvent.php';

			return array(new SproutForms_SaveEntryEvent());
		}

		sproutForms()->log('Sprout Email 0.9.2+ is required for Dynamic Events integration.');
	}

	/**
	 * Redirects to examples after installation
	 *
	 * @return void
	 */
	public function onAfterInstall()
	{
		craft()->request->redirect(UrlHelper::getCpUrl().'/sproutforms/examples');
	}

	/**
	 * @throws \Exception
	 */
	public function onBeforeUninstall()
	{
		$forms = sproutForms()->forms->getAllForms();

		foreach ($forms as $form)
		{
			sproutForms()->forms->deleteForm($form);
		}
	}
}

/**
 * @return SproutFormsService
 */
function sproutForms()
{
	return Craft::app()->getComponent('sproutForms');
}
