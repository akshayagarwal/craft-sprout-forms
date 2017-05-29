<?php
namespace barrelstrength\sproutforms\integrations\sproutforms\fields;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\helpers\Template as TemplateHelper;
use craft\base\Volume;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQuery;
use craft\errors\InvalidSubpathException;
use craft\errors\InvalidVolumeException;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\models\VolumeFolder;
use craft\web\UploadedFile;

use barrelstrength\sproutforms\SproutForms;

/**
 * Class SproutFormsAssetsField
 *
 * @package Craft
 */
class Assets extends BaseRelationField
{
	/**
	 * @inheritdoc
	 */
	public static function displayName(): string
	{
		return SproutForms::t('Assets');
	}

	/**
	 * @inheritdoc
	 */
	protected static function elementType(): string
	{
		return Asset::class;
	}

	/**
	 * @inheritdoc
	 */
	public static function defaultSelectionLabel(): string
	{
		return SproutForms::t('Add an asset');
	}

	// Properties
	// =========================================================================

	/**
	 * @var string|null The input’s boostrap class
	 */
	public $boostrapClass;

	/**
	 * @var bool|null Whether related assets should be limited to a single folder
	 */
	public $useSingleFolder;

	/**
	 * @var int|null The asset volume ID that files should be uploaded to by default (only used if [[useSingleFolder]] is false)
	 */
	public $defaultUploadLocationSource;

	/**
	 * @var string|null The subpath that files should be uploaded to by default (only used if [[useSingleFolder]] is false)
	 */
	public $defaultUploadLocationSubpath;

	/**
	 * @var int|null The asset volume ID that files should be restricted to (only used if [[useSingleFolder]] is true)
	 */
	public $singleUploadLocationSource;

	/**
	 * @var string|null The subpath that files should be restricted to (only used if [[useSingleFolder]] is true)
	 */
	public $singleUploadLocationSubpath;

	/**
	 * @var bool|null Whether the available assets should be restricted to [[allowedKinds]]
	 */
	public $restrictFiles;

	/**
	 * @var array|null The file kinds that the field should be restricted to (only used if [[restrictFiles]] is true)
	 */
	public $allowedKinds;

	/**
	 * Uploaded files that failed validation.
	 *
	 * @var UploadedFile[]
	 */
	private $_failedFiles = [];

	/**
	 * @inheritdoc
	 */
	public function init()
	{
		parent::init();
		$this->allowLargeThumbsView = true;
		$this->settingsTemplate     = 'sproutforms/_components/fields/assets/settings';
		$this->inputTemplate        = '_components/fieldtypes/Assets/input';
		$this->inputJsClass         = 'Craft.AssetSelectInput';
	}

	/**
	 * @inheritdoc
	 */
	public function getSourceOptions(): array
	{
		$sourceOptions = [];

		foreach (Asset::sources('settings') as $key => $volume) {
			if (!isset($volume['heading'])) {
				$sourceOptions[] = [
					'label' => $volume['label'],
					'value' => $volume['key']
				];
			}
		}

		return $sourceOptions;
	}

	/**
	 * Returns the available file kind options for the settings
	 *
	 * @return array
	 */
	public function getFileKindOptions(): array
	{
		$fileKindOptions = [];

		foreach (AssetsHelper::getFileKinds() as $value => $kind) {
			$fileKindOptions[] = ['value' => $value, 'label' => $kind['label']];
		}

		return $fileKindOptions;
	}


	/**
	 * @param FieldModel $field
	 * @param mixed      $value
	 * @param array      $settings
	 * @param array      $renderingOptions
	 *
	 * @return \Twig_Markup
	 */
	public function getFormInputHtml($field, $value, $settings, array $renderingOptions = null): string
	{
		$this->beginRendering();

		$rendered = Craft::$app->getView()->renderTemplate(
			'assets/input',
			[
				'name'             => $field->handle,
				'value'            => $value,
				'field'            => $field,
				'settings'         => $settings,
				'renderingOptions' => $renderingOptions
			]
		);

		$this->endRendering();

		return TemplateHelper::raw($rendered);
	}

	/**
	 * Adds support for edit field in the Entries section of SproutForms (Control
	 * panel html)
	 * @inheritdoc
	 */
	public function getInputHtml($value, ElementInterface $element = null): string
	{
		try
		{
			return parent::getInputHtml($value, $element);
		} catch (InvalidSubpathException $e)
		{
			return '<p class="warning">'.
				'<span data-icon="alert"></span> '.
				Craft::t('app', 'This field’s target subfolder path is invalid: {path}', [
					'path' => '<code>'.$this->singleUploadLocationSubpath.'</code>'
				]).
				'</p>';
		} catch (InvalidVolumeException $e)
		{
			return '<p class="warning">'.
				'<span data-icon="alert"></span> '.
				$e->getMessage().
				'</p>';
		}
	}

	/**
	 * @inheritdoc
	 */
	public function getElementValidationRules(): array
	{
		$rules = parent::getElementValidationRules();
		$rules[] = 'validateFileType';

		return $rules;
	}

	/**
	 * Validates the files to make sure they are one of the allowed file kinds.
	 *
	 * @param ElementInterface $element
	 *
	 * @return void
	 */
	public function validateFileType(ElementInterface $element)
	{
		/** @var Element $element */
		$value = $element->getFieldValue($this->handle);

		// Check if this field restricts files and if files are passed at all.
		if ($this->restrictFiles && !empty($this->allowedKinds) && is_array($value) && !empty($value)) {
			$allowedExtensions = $this->_getAllowedExtensions();

			foreach ($value as $assetId) {
				$file = Craft::$app->getAssets()->getAssetById($assetId);

				if ($file && !in_array(mb_strtolower(pathinfo($file->filename, PATHINFO_EXTENSION)), $allowedExtensions, true)) {
					$element->addError($this->handle, Craft::t('app', '"{filename}" is not allowed in this field.', ['filename' => $file->filename]));
				}
			}
		}

		foreach ($this->_failedFiles as $file) {
			$element->addError($this->handle, Craft::t('app', '"{filename}" is not allowed in this field.', ['filename' => $file]));
		}
	}

	/**
	 * Resolve source path for uploading for this field.
	 *
	 * @param ElementInterface|null $element
	 *
	 * @return int
	 */
	public function resolveDynamicPathToFolderId(ElementInterface $element = null): int
	{
		return $this->_determineUploadFolderId($element, true);
	}

	// Events
	// -------------------------------------------------------------------------
	// Protected Methods
	// =========================================================================

	/**
	 * @inheritdoc
	 */
	protected function inputSources(ElementInterface $element = null)
	{
		$folderId = $this->_determineUploadFolderId($element, false);
		Craft::$app->getSession()->authorize('saveAssetInVolume:'.$folderId);

		if ($this->useSingleFolder) {
			$folderPath = 'folder:'.$folderId;
			$folder = Craft::$app->getAssets()->getFolderById($folderId);

			// Construct the path
			while ($folder->parentId && $folder->volumeId !== null) {
				$parent = $folder->getParent();
				$folderPath = 'folder:'.$parent->id.'/'.$folderPath;
				$folder = $parent;
			}

			return [$folderPath];
		}

		$sources = [];

		// If it's a list of source IDs, we need to convert them to their folder counterparts
		if (is_array($this->sources)) {
			foreach ($this->sources as $source) {
				if (strpos($source, 'folder:') === 0) {
					$sources[] = $source;
				}
			}
		} else {
			if ($this->sources === '*') {
				$sources = '*';
			}
		}

		return $sources;
	}

	/**
	 * @inheritdoc
	 */
	protected function inputTemplateVariables($value = null, ElementInterface $element = null): array
	{
		$variables = parent::inputTemplateVariables($value, $element);
		$variables['hideSidebar'] = (int)$this->useSingleFolder;
		$variables['defaultFieldLayoutId'] = $this->_uploadVolume()->fieldLayoutId ?? null;

		return $variables;
	}


	/**
	 * @inheritdoc
	 */
	protected function inputSelectionCriteria(): array
	{
		return [
			'kind' => ($this->restrictFiles && !empty($this->allowedKinds)) ? $this->allowedKinds : [],
		];
	}

	// Private Methods
	// =========================================================================

	/**
	 * Resolve a source path to it's folder ID by the source path and the matched source beginning.
	 *
	 * @param string                $uploadSource
	 * @param string                $subpath
	 * @param ElementInterface|null $element
	 * @param bool                  $createDynamicFolders whether missing folders should be created in the process
	 *
	 * @throws InvalidVolumeException if the volume root folder doesn’t exist
	 * @throws InvalidSubpathException if the subpath cannot be parsed in full
	 * @return int
	 */
	private function _resolveVolumePathToFolderId(string $uploadSource, string $subpath, ElementInterface $element = null, bool $createDynamicFolders = true): int
	{
		$assetsService = Craft::$app->getAssets();

		$volumeId = $this->_volumeIdBySourceKey($uploadSource);

		// Make sure the volume and root folder actually exists
		if ($volumeId === null || ($rootFolder = $assetsService->getRootFolderByVolumeId($volumeId)) === null) {
			throw new InvalidVolumeException();
		}

		// Are we looking for a subfolder?
		$subpath = is_string($subpath) ? trim($subpath, '/') : '';

		if ($subpath === '') {
			// Get the root folder in the source
			$folder = $rootFolder;
		} else {
			// Prepare the path by parsing tokens and normalizing slashes.
			try {
				$renderedSubpath = Craft::$app->getView()->renderObjectTemplate($subpath, $element);
			} catch (\Exception $e) {
				throw new InvalidSubpathException($subpath);
			}

			// Did any of the tokens return null?
			if (
				$renderedSubpath === '' ||
				trim($renderedSubpath, '/') != $renderedSubpath ||
				strpos($renderedSubpath, '//') !== false
			) {
				throw new InvalidSubpathException($subpath);
			}

			// Sanitize the subpath
			$segments = explode('/', $renderedSubpath);
			foreach ($segments as &$segment) {
				$segment = FileHelper::sanitizeFilename($segment, [
					'asciiOnly' => Craft::$app->getConfig()->getGeneral()->convertFilenamesToAscii
				]);
			}
			unset($segment);
			$subpath = implode('/', $segments);

			$folder = $assetsService->findFolder([
				'volumeId' => $volumeId,
				'path' => $subpath.'/'
			]);

			// Ensure that the folder exists
			if (!$folder) {
				if (!$createDynamicFolders) {
					throw new InvalidSubpathException($subpath);
				}

				// Start at the root, and, go over each folder in the path and create it if it's missing.
				$parentFolder = $rootFolder;

				$segments = explode('/', $subpath);
				foreach ($segments as $segment) {
					$folder = $assetsService->findFolder([
						'parentId' => $parentFolder->id,
						'name' => $segment
					]);

					// Create it if it doesn't exist
					if (!$folder) {
						$folder = $this->_createSubfolder($parentFolder, $segment);
					}

					// In case there's another segment after this...
					$parentFolder = $folder;
				}
			}
		}

		return $folder->id;
	}

	/**
	 * Create a subfolder within a folder with the given name.
	 *
	 * @param VolumeFolder $currentFolder
	 * @param string       $folderName
	 *
	 * @return VolumeFolder The new subfolder
	 */
	private function _createSubfolder(VolumeFolder $currentFolder, string $folderName): VolumeFolder
	{
		$newFolder = new VolumeFolder();
		$newFolder->parentId = $currentFolder->id;
		$newFolder->name = $folderName;
		$newFolder->volumeId = $currentFolder->volumeId;
		$newFolder->path = ltrim(rtrim($currentFolder->path, '/').'/'.$folderName, '/').'/';

		Craft::$app->getAssets()->createFolder($newFolder, true);

		return $newFolder;
	}

	/**
	 * Get a list of allowed extensions for a list of file kinds.
	 *
	 * @return array
	 */
	private function _getAllowedExtensions(): array
	{
		if (!is_array($this->allowedKinds)) {
			return [];
		}

		$extensions = [];
		$allKinds = AssetsHelper::getFileKinds();

		foreach ($this->allowedKinds as $allowedKind) {
			foreach ($allKinds[$allowedKind]['extensions'] as $ext) {
				$extensions[] = $ext;
			}
		}

		return $extensions;
	}

	/**
	 * Validate incoming files against field settings.
	 *
	 * @param array $incomingFiles
	 *
	 * @return void
	 */
	private function _validateIncomingFiles(array $incomingFiles)
	{
		if ($this->restrictFiles && !empty($this->allowedKinds)) {
			$allowedExtensions = $this->_getAllowedExtensions();
		}

		foreach ($incomingFiles as $fileInfo) {
			if (!empty($allowedExtensions)) {
				$extension = StringHelper::toLowerCase(pathinfo($fileInfo['filename'], PATHINFO_EXTENSION));

				if (!in_array($extension, $allowedExtensions, true)) {
					$this->_failedFiles[] = $fileInfo['filename'];
				}
			}
		}
	}

	/**
	 * Determine an upload folder id by looking at the settings and whether Element this field belongs to is new or not.
	 *
	 * @param ElementInterface|null $element
	 * @param bool                  $createDynamicFolders whether missing folders should be created in the process
	 *
	 * @return int if the folder subpath is not valid
	 * @throws InvalidSubpathException if the folder subpath is not valid
	 * @throws InvalidVolumeException if there's a problem with the field's volume configuration
	 */
	private function _determineUploadFolderId(ElementInterface $element = null, bool $createDynamicFolders = true): int
	{
		/** @var Element $element */
		if ($this->useSingleFolder) {
			$uploadSource = $this->singleUploadLocationSource;
			$subpath = $this->singleUploadLocationSubpath;
		} else {
			$uploadSource = $this->defaultUploadLocationSource;
			$subpath = $this->defaultUploadLocationSubpath;
		}

		if (!$uploadSource) {
			throw new InvalidVolumeException(Craft::t('app', 'This field\'s Volume configuration is invalid.'));
		}

		$assets = Craft::$app->getAssets();

		try {
			$folderId = $this->_resolveVolumePathToFolderId($uploadSource, $subpath, $element, $createDynamicFolders);
		} catch (InvalidVolumeException $exception) {
			$message = $this->useSingleFolder ? Craft::t('app', 'This field’s single upload location Volume is missing') : Craft::t('app', 'This field’s default upload location Volume is missing');
			throw new InvalidVolumeException($message);
		} catch (InvalidSubpathException $exception) {
			// If this is a new/disabled element, the subpath probably just contained a token that returned null, like {id}
			// so use the user's upload folder instead
			if ($element === null || !$element->id || !$element->enabled || !$createDynamicFolders) {
				$userModel = Craft::$app->getUser()->getIdentity();

				$userFolder = $assets->getUserTemporaryUploadFolder($userModel);

				$folderId = $userFolder->id;
			} else {
				// Existing element, so this is just a bad subpath
				throw $exception;
			}
		}

		return $folderId;
	}

	/**
	 * Returns a volume ID from an upload source key.
	 *
	 * @param string $sourceKey
	 *
	 * @return int|null
	 */
	public function _volumeIdBySourceKey(string $sourceKey)
	{
		$parts = explode(':', $sourceKey, 2);

		if (count($parts) !== 2 || !is_numeric($parts[1])) {
			return null;
		}

		$folder = Craft::$app->getAssets()->getFolderById((int)$parts[1]);

		return $folder->volumeId ?? null;
	}

	/**
	 * Returns the target upload volume for the field.
	 *
	 * @return Volume|null
	 */
	private function _uploadVolume()
	{
		if ($this->useSingleFolder) {
			$sourceKey = $this->singleUploadLocationSource;
		} else {
			$sourceKey = $this->defaultUploadLocationSource;
		}

		if (($volumeId = $this->_volumeIdBySourceKey($sourceKey)) === null) {
			return null;
		}

		return Craft::$app->getVolumes()->getVolumeById($volumeId);
	}


	/**
	 * @return string
	 */
	public function getIconPath()
	{
		return $this->getTemplatesPath().'assets/asset.svg';
	}
}
