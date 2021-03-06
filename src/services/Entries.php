<?php

namespace barrelstrength\sproutforms\services;

use barrelstrength\sproutforms\elements\Entry;
use Craft;
use GuzzleHttp\Client;
use barrelstrength\sproutforms\SproutForms;
use barrelstrength\sproutforms\elements\Entry as EntryElement;
use barrelstrength\sproutforms\elements\Form as FormElement;
use barrelstrength\sproutforms\events\OnBeforeSaveEntryEvent;
use barrelstrength\sproutforms\events\OnSaveEntryEvent;
use barrelstrength\sproutforms\fields\formfields\BaseRelationFormField;
use barrelstrength\sproutforms\models\EntryStatus;
use barrelstrength\sproutforms\records\Entry as EntryRecord;
use barrelstrength\sproutforms\fields\formfields\EmailDropdown as EmailDropdownField;
use craft\db\Query;
use barrelstrength\sproutforms\records\EntryStatus as EntryStatusRecord;
use craft\base\ElementInterface;
use GuzzleHttp\Exception\RequestException;
use yii\base\Component;
use yii\base\Exception;

class Entries extends Component
{
    /**
     * @var bool
     */
    public $fakeIt = false;

    /**
     * @var EntryRecord
     */
    protected $entryRecord;

    /**
     * @param EntryRecord $entryRecord
     */
    public function __construct($entryRecord = null)
    {
        $this->entryRecord = $entryRecord;

        if ($this->entryRecord === null) {
            $this->entryRecord = EntryRecord::find();
        }

        parent::__construct($entryRecord);
    }

    /**
     * Returns an active or new entry element
     *
     * @param FormElement $form
     *
     * @return EntryElement
     */
    public function getEntry(FormElement $form)
    {
        if (isset(SproutForms::$app->forms->activeEntries[$form->handle])) {
            return SproutForms::$app->forms->activeEntries[$form->handle];
        }

        $entry = new EntryElement;

        $entry->formId = $form->id;

        return $entry;
    }

    /**
     * @param $entryStatusId
     *
     * @return EntryStatus
     */
    public function getEntryStatusById($entryStatusId)
    {
        $entryStatus = EntryStatusRecord::find()
            ->where(['id' => $entryStatusId])
            ->one();

        $entryStatusesModel = new EntryStatus;

        if ($entryStatus) {
            $entryStatusesModel->setAttributes($entryStatus->getAttributes(), false);
        }

        return $entryStatusesModel;
    }

    /**
     * @param EntryStatus $entryStatus
     *
     * @return bool
     * @throws Exception
     * @throws \yii\db\Exception
     */
    public function saveEntryStatus(EntryStatus $entryStatus): bool
    {
        $record = new EntryStatusRecord;

        if ($entryStatus->id) {
            $record = EntryStatusRecord::findOne($entryStatus->id);

            if (!$record) {
                throw new Exception(Craft::t('sprout-forms', 'No Entry Status exists with the id of “{id}”', [
                    'id' => $entryStatus->id
                ]));
            }
        }

        $record->setAttributes($entryStatus->getAttributes(), false);

        $record->sortOrder = $entryStatus->sortOrder ?: 999;

        $entryStatus->validate();

        if (!$entryStatus->hasErrors()) {
            $transaction = Craft::$app->db->beginTransaction();

            try {
                if ($record->isDefault) {
                    EntryStatusRecord::updateAll(['isDefault' => false]);
                }

                $record->save(false);

                if (!$entryStatus->id) {
                    $entryStatus->id = $record->id;
                }

                $transaction->commit();
            } catch (Exception $e) {
                $transaction->rollback();

                throw $e;
            }

            return true;
        }

        return false;
    }

    /**
     * @param $id
     *
     * @return bool
     * @throws \Exception
     * @throws \Throwable
     * @throws \yii\db\StaleObjectException
     */
    public function deleteEntryStatusById($id)
    {
        $statuses = $this->getAllEntryStatuses();

        $entry = EntryElement::find()->where(['statusId' => $id])->one();

        if ($entry) {
            return false;
        }

        if (count($statuses) >= 2) {
            $entryStatus = EntryStatusRecord::findOne($id);

            if ($entryStatus) {
                $entryStatus->delete();
                return true;
            }
        }

        return false;
    }

    /**
     * Reorders Entry Statuses
     *
     * @param $entryStatusIds
     *
     * @return bool
     * @throws Exception
     * @throws \yii\db\Exception
     */
    public function reorderEntryStatuses($entryStatusIds)
    {
        $transaction = Craft::$app->db->beginTransaction();

        try {
            foreach ($entryStatusIds as $entryStatus => $entryStatusId) {
                $entryStatusRecord = $this->getEntryStatusRecordById($entryStatusId);
                $entryStatusRecord->sortOrder = $entryStatus + 1;
                $entryStatusRecord->save();
            }

            $transaction->commit();
        } catch (Exception $e) {
            $transaction->rollBack();

            throw $e;
        }

        return true;
    }

    /**
     * @return array
     */
    public function getAllEntryStatuses()
    {
        $entryStatuses = EntryStatusRecord::find()
            ->orderBy(['sortOrder' => 'asc'])
            ->all();

        return $entryStatuses;
    }

    /**
     * Returns a form entry model if one is found in the database by id
     *
     * @param          $entryId
     * @param int|null $siteId
     *
     * @return array|ElementInterface|null
     */
    public function getEntryById($entryId, int $siteId = null)
    {
        $query = EntryElement::find();
        $query->id($entryId);
        $query->siteId($siteId);

        // @todo - look into enabledForSite method
        // $query->enabledForSite(false);

        return $query->one();
    }

    /**
     * @param EntryElement $entry
     *
     * @return bool
     * @throws Exception
     * @throws \Throwable
     */
    public function saveEntry(EntryElement $entry)
    {
        $isNewEntry = !$entry->id;

        if ($entry->id) {
            $entryRecord = EntryRecord::findOne($entry->id);

            if (!$entryRecord) {
                throw new Exception(Craft::t('sprout-forms', 'No entry exists with id '.$entry->id));
            }
        }

        $entry->validate();

        if ($entry->hasErrors()) {

            SproutForms::error($entry->getErrors());

            return false;
        }

        $event = new OnBeforeSaveEntryEvent([
            'entry' => $entry
        ]);

        $this->trigger(EntryElement::EVENT_BEFORE_SAVE, $event);

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            if (!$event->isValid) {
                foreach ($event->errors as $key => $error) {
                    $entry->addError($key, $error);
                }

                SproutForms::error('OnBeforeSaveEntryEvent is not valid');

                if ($event->fakeIt) {
                    SproutForms::$app->entries->fakeIt = true;
                }

                return false;
            }

            $success = Craft::$app->getElements()->saveElement($entry);

            if (!$success) {
                SproutForms::error('Couldn’t save Element on saveEntry service.');
                $transaction->rollBack();
                return false;
            }

            SproutForms::info('Form Entry Element Saved.');

            $transaction->commit();

            $this->callOnSaveEntryEvent($entry, $isNewEntry);
        } catch (\Exception $e) {
            SproutForms::error('Failed to save element: '.$e->getMessage());
            $transaction->rollBack();

            throw $e;
        }

        return true;
    }

    /**
     * @param EntryElement $entry
     * @return bool
     */
    public function forwardEntry(Entry $entry)
    {
        $fields = $entry->getPayloadFields();
        $endpoint = $entry->getForm()->submitAction;

        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {

            SproutForms::error($entry->formName.' submit action is an invalid URL: '.$endpoint);

            return false;
        }

        $client = new Client(['verify' => false]);

        try {
            SproutForms::info($fields);

            $response = $client->post($endpoint, null, $fields);

            SproutForms::info($response->getBody()->getContents());
        } catch (RequestException $e) {
            $entry->addError('general', $e->getMessage());

            return false;
        }

        return true;
    }

    public function getDefaultEntryStatusId()
    {
        $entryStatus = EntryStatusRecord::find()
            ->orderBy(['isDefault' => SORT_DESC])
            ->one();

        return $entryStatus != null ? $entryStatus->id : null;
    }

    /**
     * Saves some relations for a field.
     *
     * @param BaseRelationFormField $field
     * @param ElementInterface      $source
     * @param array                 $targetIds
     *
     * @throws \Exception
     * @return void
     */
    public function saveRelations(BaseRelationFormField $field, ElementInterface $source, array $targetIds)
    {
        if (!is_array($targetIds)) {
            $targetIds = [];
        }

        // Prevent duplicate target IDs.
        $targetIds = array_unique($targetIds);

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            // Delete the existing relations
            $oldRelationConditions = [
                'and',
                [
                    'fieldId' => $field->id,
                    'sourceId' => $source->id,
                ]
            ];

            if ($field->localizeRelations) {
                $oldRelationConditions[] = [
                    'or',
                    ['sourceSiteId' => null],
                    ['sourceSiteId' => $source->siteId]
                ];
            }

            Craft::$app->getDb()->createCommand()
                ->delete('{{%relations}}', $oldRelationConditions)
                ->execute();

            // Add the new ones
            if (!empty($targetIds)) {
                $values = [];

                if ($field->localizeRelations) {
                    $sourceSiteId = $source->siteId;
                } else {
                    $sourceSiteId = null;
                }

                foreach ($targetIds as $sortOrder => $targetId) {
                    $values[] = [
                        $field->id,
                        $source->id,
                        $sourceSiteId,
                        $targetId,
                        $sortOrder + 1
                    ];
                }

                $columns = [
                    'fieldId',
                    'sourceId',
                    'sourceSiteId',
                    'targetId',
                    'sortOrder'
                ];
                Craft::$app->getDb()->createCommand()
                    ->batchInsert('{{%relations}}', $columns, $values)
                    ->execute();
            }

            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollBack();

            throw $e;
        }
    }

    /**
     * @param       $formId
     * @param array $submittedFields
     */
    public function unobfuscateEmailAddresses($formId, array $submittedFields = [])
    {
        if (!is_numeric($formId)) {
            return;
        }

        $fieldContext = 'sproutForms:'.$formId;

        // Get all Email Select Fields for this form
        $emailSelectFieldHandles = (new Query())
            ->select('handle')
            ->from('{{%fields}}')
            ->where(['context' => $fieldContext, 'type' => EmailDropdownField::class])
            ->all();

        $oldContext = Craft::$app->content->fieldContext;

        Craft::$app->content->fieldContext = $fieldContext;

        foreach ($emailSelectFieldHandles as $key => $handle) {
            if (isset($submittedFields[$handle['handle']])) {
                // Get our field settings, which include the map of
                // email addresses to their indexes
                $field = Craft::$app->fields->getFieldByHandle($handle['handle']);
                $options = $field->settings['options'];

                // Get the obfuscated email index from our post request
                $index = $submittedFields[$handle['handle']];
                $emailValue = $options[$index]['value'];

                // Update the Email Select value in our post request from
                // the Email Index value to the Email Address
                $_POST['fields'][$handle['handle']] = $emailValue;
            }
        }

        Craft::$app->content->fieldContext = $oldContext;
    }

    /**
     * Handles event to unobfuscate email addresses in a Sprout Forms submission
     *
     * @param $form
     */
    public function handleUnobfuscateEmailAddresses($form)
    {
        if (!Craft::$app->request->getIsSiteRequest()) {
            return;
        }

        $submittedFields = Craft::$app->request->getBodyParam('fields') ?? [];

        // Unobfuscate email address in $_POST request
        $this->unobfuscateEmailAddresses($form->id, $submittedFields);
    }

    /**
     * Gets an Entry Status's record.
     *
     * @param null $entryStatusId
     *
     * @return EntryStatusRecord|null|static
     * @throws Exception
     */
    private function getEntryStatusRecordById($entryStatusId = null)
    {
        if ($entryStatusId) {
            $entryStatusRecord = EntryStatusRecord::findOne($entryStatusId);

            if (!$entryStatusRecord) {
                throw new Exception(Craft::t('sprout-forms', 'No Entry Status exists with the ID “{id}”.',
                    ['id' => $entryStatusId]
                )
                );
            }
        } else {
            $entryStatusRecord = new EntryStatusRecord();
        }

        return $entryStatusRecord;
    }

    public function isDataSaved($form)
    {
        $settings = Craft::$app->getPlugins()->getPlugin('sprout-forms')->getSettings();

        $saveData = $settings->enableSaveData;

        if (($settings->enableSaveDataPerFormBasis && $saveData) || $form->submitAction) {
            $saveData = $form->saveData;
        }

        return $saveData;
    }

    /**
     * @param $entry
     * @param $isNewEntry
     */
    public function callOnSaveEntryEvent($entry, $isNewEntry)
    {
        $event = new OnSaveEntryEvent([
            'entry' => $entry,
            'isNewEntry' => $isNewEntry,
        ]);

        $this->trigger(EntryElement::EVENT_AFTER_SAVE, $event);
    }

}
