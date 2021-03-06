<?php

namespace barrelstrength\sproutforms\fields\formfields;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\Template as TemplateHelper;
use craft\base\PreviewableFieldInterface;

use barrelstrength\sproutbase\SproutBase;
use barrelstrength\sproutforms\base\FormField;
use barrelstrength\sproutbase\app\fields\web\assets\url\UrlFieldAsset;

class Url extends FormField implements PreviewableFieldInterface
{
    /**
     * @var string
     */
    public $cssClasses;

    /**
     * @var string|null
     */
    public $customPatternErrorMessage;

    /**
     * @var bool|null
     */
    public $customPatternToggle;

    /**
     * @var string|null
     */
    public $customPattern;

    /**
     * @var string|null
     */
    public $placeholder;

    public static function displayName(): string
    {
        return Craft::t('sprout-forms', 'URL');
    }

    /**
     * @return string
     */
    public function getSvgIconPath()
    {
        return '@sproutbaseicons/chain.svg';
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate(
            'sprout-forms/_components/fields/formfields/url/settings',
            [
                'field' => $this,
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function getInputHtml($value, ElementInterface $element = null): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(UrlFieldAsset::class);

        $name = $this->handle;
        $inputId = Craft::$app->getView()->formatInputId($name);
        $namespaceInputId = Craft::$app->getView()->namespaceInputId($inputId);

        $fieldContext = SproutBase::$app->utilities->getFieldContext($this, $element);

        return Craft::$app->getView()->renderTemplate('sprout-base-fields/_components/fields/formfields/url/input', [
                'namespaceInputId' => $namespaceInputId,
                'id' => $inputId,
                'name' => $name,
                'value' => $value,
                'fieldContext' => $fieldContext,
                'placeholder' => $this->placeholder
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function getExampleInputHtml()
    {
        return Craft::$app->getView()->renderTemplate('sprout-forms/_components/fields/formfields/url/example',
            [
                'field' => $this
            ]
        );
    }

    /**
     * @param mixed      $value
     * @param array|null $renderingOptions
     *
     * @return string
     * @throws \Twig_Error_Loader
     * @throws \yii\base\Exception
     */
    public function getFrontEndInputHtml($value, array $renderingOptions = null): string
    {
        $attributes = $this->getAttributes();
        $errorMessage = SproutBase::$app->urlField->getErrorMessage($attributes['name'], $this);
        $placeholder = $this->placeholder ?? '';

        $rendered = Craft::$app->getView()->renderTemplate(
            'url/input',
            [
                'name' => $this->handle,
                'value' => $value,
                'field' => $this,
                'pattern' => $this->customPattern,
                'errorMessage' => $errorMessage,
                'renderingOptions' => $renderingOptions,
                'placeholder' => $placeholder
            ]
        );

        return TemplateHelper::raw($rendered);
    }

    /**
     * @inheritdoc
     */
    public function getTableAttributeHtml($value, ElementInterface $element): string
    {
        if ($value) {
            return '<a href="'.$value.'" target="_blank">'.$value.'</a>';
        }

        return '';
    }

    /**
     * @inheritdoc
     */
    public function getElementValidationRules(): array
    {
        return ['validateUrl'];
    }

    /**
     * Validates our fields submitted value beyond the checks
     * that were assumed based on the content attribute.
     *
     * @param ElementInterface $element
     *
     * @return void
     */
    public function validateUrl(ElementInterface $element)
    {
        $value = $element->getFieldValue($this->handle);

        if (!SproutBase::$app->urlField->validate($value, $this)) {
            $element->addError(
                $this->handle,
                SproutBase::$app->urlField->getErrorMessage($this->name, $this)
            );
        }
    }
}
