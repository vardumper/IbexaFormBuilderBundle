<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Command;

use Composer\InstalledVersions;
use Ibexa\Contracts\Core\Persistence\Content\Location as PersistenceLocation;
use Ibexa\Contracts\Core\Repository\ContentTypeService;
use Ibexa\Contracts\Core\Repository\Exceptions\NotFoundException;
use Ibexa\Contracts\Core\Repository\Repository;
use Ibexa\Core\FieldType\Checkbox\Value as CheckboxValue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ibexa:form-builder:install-content-types',
    description: 'Programmatically creates or updates the content types required by IbexaFormBuilderBundle (form, input, textarea, select, option, fieldset, button).',
)]
final class InstallContentTypesCommand extends Command
{
    private const GROUP_IDENTIFIER = 'Form Builder';
    private const LANGUAGE = 'eng-GB';

    private bool $useModernFieldTypes;

    public function __construct(
        private readonly ContentTypeService $contentTypeService,
        private readonly Repository $repository,
    ) {
        parent::__construct();
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $coreVersion = InstalledVersions::getVersion('ibexa/core') ?? '5.0.0';
        $this->useModernFieldTypes = version_compare($coreVersion, '5.0.0', '>=');
    }

    /**
     * Translates a modern 5.0+ ibexa_* field type identifier to the legacy ez* name on Ibexa 4.x.
     */
    private function ft(string $type): string
    {
        if ($this->useModernFieldTypes) {
            return $type;
        }

        return match ($type) {
            'ibexa_string' => 'ezstring',
            'ibexa_integer' => 'ezinteger',
            'ibexa_boolean' => 'ezboolean',
            'ibexa_selection' => 'ezselection',
            default => $type,
        };
    }

    protected function configure(): void
    {
        $this->addOption('overwrite-existing', null, InputOption::VALUE_NONE, 'Update existing content types with new field definitions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $group = $this->ensureContentTypeGroup($io);
        $overwriteExisting = (bool) $input->getOption('overwrite-existing');

        $definitions = $this->getContentTypeDefinitions();

        foreach ($definitions as $shortIdentifier => $definition) {
            $identifier = 'form_builder_' . $shortIdentifier;
            if ($this->contentTypeExists($identifier)) {
                if (!$overwriteExisting) {
                    $io->note(sprintf('Content type "%s" already exists — skipping.', $identifier));
                    continue;
                }

                // Try to delete and recreate
                $this->deleteContentType($identifier, $io);

                // If content type still exists (has content items), patch missing fields instead
                if ($this->contentTypeExists($identifier)) {
                    $this->patchContentType($identifier, $definition['fields'], $io);
                    continue;
                }
            }

            $this->createContentType($identifier, $shortIdentifier, $definition, $group, $io);
        }

        $io->success('All form builder content types have been installed.');

        return Command::SUCCESS;
    }

    private function ensureContentTypeGroup(SymfonyStyle $io): \Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroup
    {
        return $this->repository->sudo(function () use ($io) {
            try {
                return $this->contentTypeService->loadContentTypeGroupByIdentifier(self::GROUP_IDENTIFIER);
            } catch (NotFoundException) {
                $io->note(sprintf('Content type group "%s" not found — creating.', self::GROUP_IDENTIFIER));
                $struct = $this->contentTypeService->newContentTypeGroupCreateStruct(self::GROUP_IDENTIFIER);

                return $this->contentTypeService->createContentTypeGroup($struct);
            }
        });
    }

    private function contentTypeExists(string $identifier): bool
    {
        return $this->repository->sudo(function () use ($identifier) {
            try {
                $this->contentTypeService->loadContentTypeByIdentifier($identifier);

                return true;
            } catch (NotFoundException) {
                return false;
            }
        });
    }

    private function createContentType(
        string $identifier,
        string $remoteId,
        array $definition,
        \Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroup $group,
        SymfonyStyle $io,
    ): void {
        $this->repository->sudo(function () use ($identifier, $remoteId, $definition, $group, $io) {
            $struct = $this->contentTypeService->newContentTypeCreateStruct($identifier);
            $struct->remoteId = $remoteId;
            $struct->mainLanguageCode = self::LANGUAGE;
            $struct->names = [self::LANGUAGE => $definition['name']];
            $struct->descriptions = [self::LANGUAGE => $definition['description'] ?? ''];
            $struct->nameSchema = $definition['nameSchema'] ?? '<form_builder_name>';
            $struct->urlAliasSchema = $definition['nameSchema'] ?? '<form_builder_name>';
            $struct->isContainer = $definition['isContainer'] ?? false;
            $struct->defaultSortField = $definition['defaultSortField'] ?? PersistenceLocation::SORT_FIELD_PUBLISHED;
            $struct->defaultSortOrder = $definition['defaultSortOrder'] ?? PersistenceLocation::SORT_ORDER_DESC;

            $this->addFieldDefinitions($struct, $definition['fields']);

            $draft = $this->contentTypeService->createContentType($struct, [$group]);
            $this->contentTypeService->publishContentTypeDraft($draft);

            $io->writeln(sprintf('  ✓ Created content type <info>%s</info>', $identifier));
        });
    }

    private function deleteContentType(
        string $identifier,
        SymfonyStyle $io,
    ): void {
        $this->repository->sudo(function () use ($identifier, $io) {
            $contentType = $this->contentTypeService->loadContentTypeByIdentifier($identifier);
            try {
                $this->contentTypeService->deleteContentType($contentType);
                $io->writeln(sprintf('  ✓ Deleted content type <info>%s</info>', $identifier));
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'still has Content items')) {
                    $io->warning(sprintf('Cannot delete content type "%s" — it has content items. Skipping overwrite.', $identifier));
                } else {
                    throw $e;
                }
            }
        });
    }

    private function patchContentType(
        string $identifier,
        array $fields,
        SymfonyStyle $io,
    ): void {
        $this->repository->sudo(function () use ($identifier, $fields, $io) {
            $contentType = $this->contentTypeService->loadContentTypeByIdentifier($identifier);

            $missing = array_filter(
                array_keys($fields),
                fn (string $fieldId) => $contentType->getFieldDefinition($fieldId) === null,
            );

            if (empty($missing)) {
                $io->writeln(sprintf('  ✓ Content type <info>%s</info> already has all field definitions — nothing to patch.', $identifier));

                return;
            }

            $draft = $this->contentTypeService->createContentTypeDraft($contentType);
            $position = count(iterator_to_array($contentType->getFieldDefinitions())) + 1;

            foreach ($missing as $fieldIdentifier) {
                $field = $fields[$fieldIdentifier];
                $fieldStruct = $this->contentTypeService->newFieldDefinitionCreateStruct(
                    $fieldIdentifier,
                    $this->ft($field['type']),
                );
                $fieldStruct->names = [self::LANGUAGE => $field['label']];
                $fieldStruct->descriptions = [self::LANGUAGE => $field['description'] ?? ''];
                $fieldStruct->position = $position++;
                $fieldStruct->isRequired = $field['required'] ?? false;
                $fieldStruct->isSearchable = $field['searchable'] ?? true;
                $fieldStruct->isTranslatable = false;

                if (array_key_exists('defaultValue', $field)) {
                    $fieldStruct->defaultValue = new CheckboxValue((bool) $field['defaultValue']);
                }

                if (isset($field['settings'])) {
                    $fieldStruct->fieldSettings = $field['settings'];
                }

                if (isset($field['validators'])) {
                    $fieldStruct->validatorConfiguration = $field['validators'];
                }

                $this->contentTypeService->addFieldDefinition($draft, $fieldStruct);
            }

            $this->contentTypeService->publishContentTypeDraft($draft);
            $io->writeln(sprintf('  ✓ Patched content type <info>%s</info> — added: %s', $identifier, implode(', ', $missing)));
        });
    }

    private function addFieldDefinitions(
        \Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeCreateStruct|\Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeDraft $struct,
        array $fields,
    ): void {
        $position = 1;
        foreach ($fields as $fieldIdentifier => $field) {
            $fieldStruct = $this->contentTypeService->newFieldDefinitionCreateStruct(
                $fieldIdentifier,
                $this->ft($field['type']),
            );
            $fieldStruct->names = [self::LANGUAGE => $field['label']];
            $fieldStruct->descriptions = [self::LANGUAGE => $field['description'] ?? ''];
            $fieldStruct->position = $position++;
            $fieldStruct->isRequired = $field['required'] ?? false;
            $fieldStruct->isSearchable = $field['searchable'] ?? true;
            $fieldStruct->isTranslatable = false;

            if (array_key_exists('defaultValue', $field)) {
                $fieldStruct->defaultValue = new CheckboxValue((bool) $field['defaultValue']);
            }

            if (isset($field['settings'])) {
                $fieldStruct->fieldSettings = $field['settings'];
            }

            if (isset($field['validators'])) {
                $fieldStruct->validatorConfiguration = $field['validators'];
            }

            $struct->addFieldDefinition($fieldStruct);
        }
    }

    private function getContentTypeDefinitions(): array
    {
        return [
            'form' => [
                'name' => 'Form',
                'description' => 'HTML <form> element managed as Ibexa content.',
                'nameSchema' => '<form_builder_name>',
                'isContainer' => true,
                'defaultSortField' => PersistenceLocation::SORT_FIELD_PRIORITY,
                'defaultSortOrder' => PersistenceLocation::SORT_ORDER_ASC,
                'fields' => [
                    'form_builder_name' => [
                        'type' => 'ibexa_string',
                        'label' => 'Internal Name',
                        'required' => true,
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => 1]],
                    ],
                    'form_builder_method' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Method',
                        'settings' => ['options' => ['get', 'post'], 'isMultiple' => false],
                    ],
                    'form_builder_action' => [
                        'type' => 'ibexa_string',
                        'label' => 'Action URL',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 2048, 'minStringLength' => null]],
                    ],
                    'form_builder_enctype' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Encoding Type',
                        'settings' => ['options' => ['application/x-www-form-urlencoded', 'multipart/form-data', 'text/plain'], 'isMultiple' => false],
                    ],
                    'form_builder_autocomplete' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocomplete',
                        'settings' => ['options' => ['on', 'off'], 'isMultiple' => false],
                    ],
                    'form_builder_novalidate' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'No Validate',
                        'searchable' => false,
                    ],
                    'form_builder_target' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Target',
                        'settings' => ['options' => ['_self', '_blank', '_parent', '_top'], 'isMultiple' => false],
                    ],
                    'form_builder_submission_action' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Submission Action',
                        'settings' => ['options' => ['store', 'email', 'both'], 'isMultiple' => false],
                    ],
                    'form_builder_notification_email' => [
                        'type' => 'ibexa_string',
                        'label' => 'Notification Email (To)',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_notification_email_cc' => [
                        'type' => 'ibexa_string',
                        'label' => 'Notification Email (CC)',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_notification_email_bcc' => [
                        'type' => 'ibexa_string',
                        'label' => 'Notification Email (BCC)',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_email_subject' => [
                        'type' => 'ibexa_string',
                        'label' => 'Email Subject',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                ],
            ],

            'input' => [
                'name' => 'Input',
                'description' => 'HTML <input> element managed as Ibexa content.',
                'nameSchema' => '<form_builder_name>',
                'isContainer' => false,
                'fields' => [
                    'form_builder_name' => [
                        'type' => 'ibexa_string',
                        'label' => 'Internal Name',
                        'required' => true,
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => 1]],
                    ],
                    'form_builder_label' => [
                        'type' => 'ibexa_string',
                        'label' => 'Label',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_helper_text' => [
                        'type' => 'ibexa_string',
                        'label' => 'Helper Text',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_type' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Input Type',
                        'settings' => [
                            'options' => [
                                'text',
                                'email',
                                'number',
                                'url',
                                'tel',
                                'date',
                                'time',
                                'password',
                                'hidden',
                                'submit',
                                'button',
                                'reset',
                                'checkbox',
                                'radio',
                                'color',
                                'datetime-local',
                                'file',
                                'image',
                                'month',
                                'range',
                                'search',
                                'week',
                            ],
                            'isMultiple' => false,
                        ],
                    ],
                    'form_builder_value' => [
                        'type' => 'ibexa_string',
                        'label' => 'Default Value',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_placeholder' => [
                        'type' => 'ibexa_string',
                        'label' => 'Placeholder',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_accept' => [
                        'type' => 'ibexa_string',
                        'label' => 'Accept (MIME types)',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 512, 'minStringLength' => null]],
                    ],
                    'form_builder_pattern' => [
                        'type' => 'ibexa_string',
                        'label' => 'Pattern (regex)',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 512, 'minStringLength' => null]],
                    ],
                    'form_builder_required' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Required',
                        'searchable' => false,
                    ],
                    'form_builder_disabled' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Disabled',
                        'searchable' => false,
                    ],
                    'form_builder_readonly' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Read Only',
                        'searchable' => false,
                    ],
                    'form_builder_checked' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Checked (default)',
                        'searchable' => false,
                    ],
                    'form_builder_multiple' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Multiple',
                        'searchable' => false,
                    ],
                    'form_builder_autocomplete' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocomplete',
                        'settings' => ['options' => ['on', 'off'], 'isMultiple' => false],
                    ],
                    'form_builder_maxlength' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Max Length',
                        'searchable' => false,
                    ],
                    'form_builder_minlength' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Min Length',
                        'searchable' => false,
                    ],
                    'form_builder_size' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Size',
                        'searchable' => false,
                    ],
                    'form_builder_min' => [
                        'type' => 'ibexa_string',
                        'label' => 'Min',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_max' => [
                        'type' => 'ibexa_string',
                        'label' => 'Max',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_step' => [
                        'type' => 'ibexa_string',
                        'label' => 'Step',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_hide_label' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Hide Label',
                        'searchable' => false,
                    ],
                    'form_builder_order' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Order',
                        'searchable' => false,
                    ],
                    'form_builder_label_wrap' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Input inside label',
                        'description' => 'When enabled, the label element wraps the input (implicit association). When disabled, a separate <label for="..."> is used.',
                        'defaultValue' => true,
                        'searchable' => false,
                    ],
                    'form_builder_label_after' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Label text after field',
                        'description' => 'When enabled, the label text is rendered after the input element. Useful for checkboxes and radios.',
                        'defaultValue' => false,
                        'searchable' => false,
                    ],
                    'form_builder_constraints' => [
                        'type' => 'ibexa_string',
                        'label' => 'Validation Constraints (JSON)',
                        'description' => 'JSON array of constraint objects. Each object requires a "type" key. Supported types: NotBlank, IsNull, IsTrue, IsFalse, Email, Url, Ip, Regex, Length, Uuid, Hostname, Range, GreaterThan, GreaterThanOrEqual, LessThan, LessThanOrEqual, Positive, PositiveOrZero, Negative, NegativeOrZero, Date, Time. Examples: [{"type":"NotBlank"},{"type":"Length","min":2,"max":100}] — non-blank between 2 and 100 chars. [{"type":"Email"}] — valid e-mail. [{"type":"Regex","pattern":"/^[A-Z0-9]+$/i"}] — pattern match. [{"type":"Range","min":1,"max":10}] — numeric range.',
                        'searchable' => false,
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 4096, 'minStringLength' => null]],
                    ],
                ],
            ],

            'textarea' => [
                'name' => 'Textarea',
                'description' => 'HTML <textarea> element managed as Ibexa content.',
                'nameSchema' => '<form_builder_name>',
                'isContainer' => false,
                'fields' => [
                    'form_builder_name' => [
                        'type' => 'ibexa_string',
                        'label' => 'Internal Name',
                        'required' => true,
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => 1]],
                    ],
                    'form_builder_label' => [
                        'type' => 'ibexa_string',
                        'label' => 'Label',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_helper_text' => [
                        'type' => 'ibexa_string',
                        'label' => 'Helper Text',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_placeholder' => [
                        'type' => 'ibexa_string',
                        'label' => 'Placeholder',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_wrap' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Wrap',
                        'settings' => ['options' => ['soft', 'hard', 'off'], 'isMultiple' => false],
                    ],
                    'form_builder_autocomplete' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocomplete',
                        'settings' => ['options' => ['on', 'off'], 'isMultiple' => false],
                    ],
                    'form_builder_required' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Required',
                        'searchable' => false,
                    ],
                    'form_builder_disabled' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Disabled',
                        'searchable' => false,
                    ],
                    'form_builder_readonly' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Read Only',
                        'searchable' => false,
                    ],
                    'form_builder_rows' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Rows',
                        'searchable' => false,
                    ],
                    'form_builder_cols' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Columns',
                        'searchable' => false,
                    ],
                    'form_builder_maxlength' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Max Length',
                        'searchable' => false,
                    ],
                    'form_builder_minlength' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Min Length',
                        'searchable' => false,
                    ],
                    'form_builder_hide_label' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Hide Label',
                        'searchable' => false,
                    ],
                    'form_builder_order' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Order',
                        'searchable' => false,
                    ],
                    'form_builder_label_wrap' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Input inside label',
                        'description' => 'When enabled, the label element wraps the textarea (implicit association). When disabled, a separate <label for="..."> is used.',
                        'defaultValue' => true,
                        'searchable' => false,
                    ],
                    'form_builder_label_after' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Label text after field',
                        'description' => 'When enabled, the label text is rendered after the textarea element.',
                        'defaultValue' => false,
                        'searchable' => false,
                    ],
                    'form_builder_constraints' => [
                        'type' => 'ibexa_string',
                        'label' => 'Validation Constraints (JSON)',
                        'description' => 'JSON array of constraint objects. Each object requires a "type" key. Supported types: NotBlank, IsNull, IsTrue, IsFalse, Email, Url, Ip, Regex, Length, Uuid, Hostname, Range, GreaterThan, GreaterThanOrEqual, LessThan, LessThanOrEqual, Positive, PositiveOrZero, Negative, NegativeOrZero, Date, Time. Examples: [{"type":"NotBlank"},{"type":"Length","min":2,"max":100}] — non-blank between 2 and 100 chars. [{"type":"Email"}] — valid e-mail. [{"type":"Regex","pattern":"/^[A-Z0-9]+$/i"}] — pattern match. [{"type":"Range","min":1,"max":10}] — numeric range.',
                        'searchable' => false,
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 4096, 'minStringLength' => null]],
                    ],
                ],
            ],

            'select' => [
                'name' => 'Select',
                'description' => 'HTML <select> element managed as Ibexa content.',
                'nameSchema' => '<form_builder_name>',
                'isContainer' => true,
                'defaultSortField' => PersistenceLocation::SORT_FIELD_PRIORITY,
                'defaultSortOrder' => PersistenceLocation::SORT_ORDER_ASC,
                'fields' => [
                    'form_builder_name' => [
                        'type' => 'ibexa_string',
                        'label' => 'Internal Name',
                        'required' => true,
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => 1]],
                    ],
                    'form_builder_label' => [
                        'type' => 'ibexa_string',
                        'label' => 'Label',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_helper_text' => [
                        'type' => 'ibexa_string',
                        'label' => 'Helper Text',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_required' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Required',
                        'searchable' => false,
                    ],
                    'form_builder_disabled' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Disabled',
                        'searchable' => false,
                    ],
                    'form_builder_multiple' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Multiple',
                        'searchable' => false,
                    ],
                    'form_builder_size' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Size (visible rows)',
                        'searchable' => false,
                    ],
                    'form_builder_autocomplete' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocomplete',
                        'settings' => ['options' => ['on', 'off'], 'isMultiple' => false],
                    ],
                    'form_builder_hide_label' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Hide Label',
                        'searchable' => false,
                    ],
                    'form_builder_order' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Order',
                        'searchable' => false,
                    ],
                    'form_builder_label_wrap' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Input inside label',
                        'description' => 'When enabled, the label element wraps the select (implicit association). When disabled, a separate <label for="..."> is used.',
                        'defaultValue' => true,
                        'searchable' => false,
                    ],
                    'form_builder_label_after' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Label text after field',
                        'description' => 'When enabled, the label text is rendered after the select element.',
                        'defaultValue' => false,
                        'searchable' => false,
                    ],
                    'form_builder_constraints' => [
                        'type' => 'ibexa_string',
                        'label' => 'Validation Constraints (JSON)',
                        'description' => 'JSON array of constraint objects. Each object requires a "type" key. Supported types: NotBlank, IsNull, IsTrue, IsFalse, Email, Url, Ip, Regex, Length, Uuid, Hostname, Range, GreaterThan, GreaterThanOrEqual, LessThan, LessThanOrEqual, Positive, PositiveOrZero, Negative, NegativeOrZero, Date, Time. Examples: [{"type":"NotBlank"},{"type":"Length","min":2,"max":100}] — non-blank between 2 and 100 chars. [{"type":"Email"}] — valid e-mail. [{"type":"Regex","pattern":"/^[A-Z0-9]+$/i"}] — pattern match. [{"type":"Range","min":1,"max":10}] — numeric range.',
                        'searchable' => false,
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 4096, 'minStringLength' => null]],
                    ],
                ],
            ],

            'option' => [
                'name' => 'Option',
                'description' => 'HTML <option> element managed as Ibexa content (child of select).',
                'nameSchema' => '<form_builder_name>',
                'isContainer' => false,
                'fields' => [
                    'form_builder_name' => [
                        'type' => 'ibexa_string',
                        'label' => 'Internal Name',
                        'required' => true,
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => 1]],
                    ],
                    'form_builder_label' => [
                        'type' => 'ibexa_string',
                        'label' => 'Display Label',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_value' => [
                        'type' => 'ibexa_string',
                        'label' => 'Value',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_disabled' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Disabled',
                        'searchable' => false,
                    ],
                    'form_builder_selected' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Selected (default)',
                        'searchable' => false,
                    ],
                    'form_builder_order' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Order',
                        'searchable' => false,
                    ],
                ],
            ],

            'fieldset' => [
                'name' => 'Fieldset',
                'description' => 'HTML <fieldset> element managed as Ibexa content.',
                'nameSchema' => '<form_builder_name>',
                'isContainer' => true,
                'defaultSortField' => PersistenceLocation::SORT_FIELD_PRIORITY,
                'defaultSortOrder' => PersistenceLocation::SORT_ORDER_ASC,
                'fields' => [
                    'form_builder_name' => [
                        'type' => 'ibexa_string',
                        'label' => 'Internal Name',
                        'required' => true,
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => 1]],
                    ],
                    'form_builder_label' => [
                        'type' => 'ibexa_string',
                        'label' => 'Legend',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_helper_text' => [
                        'type' => 'ibexa_string',
                        'label' => 'Helper Text',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_role' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Role',
                        'settings' => ['options' => ['grid', 'group', 'presentation', 'search'], 'isMultiple' => false],
                    ],
                    'form_builder_disabled' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Disabled',
                        'searchable' => false,
                    ],
                    'form_builder_hide_label' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Hide Legend',
                        'searchable' => false,
                    ],
                    'form_builder_order' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Order',
                        'searchable' => false,
                    ],
                ],
            ],

            'horizontal_group' => [
                'name' => 'Horizontal Group',
                'description' => 'Wraps child elements in a <div class="grid"> layout container.',
                'nameSchema' => '<form_builder_name>',
                'isContainer' => true,
                'defaultSortField' => PersistenceLocation::SORT_FIELD_PRIORITY,
                'defaultSortOrder' => PersistenceLocation::SORT_ORDER_ASC,
                'fields' => [
                    'form_builder_name' => [
                        'type' => 'ibexa_string',
                        'label' => 'Internal Name',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_label' => [
                        'type' => 'ibexa_string',
                        'label' => 'Label',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_helper_text' => [
                        'type' => 'ibexa_string',
                        'label' => 'Helper Text',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_order' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Order',
                        'searchable' => false,
                    ],
                ],
            ],

            'button' => [
                'name' => 'Button',
                'description' => 'HTML <button> element managed as Ibexa content.',
                'nameSchema' => '<form_builder_name>',
                'isContainer' => false,
                'fields' => [
                    'form_builder_name' => [
                        'type' => 'ibexa_string',
                        'label' => 'Internal Name',
                        'required' => true,
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => 1]],
                    ],
                    'form_builder_label' => [
                        'type' => 'ibexa_string',
                        'label' => 'Button Label (visible text)',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_helper_text' => [
                        'type' => 'ibexa_string',
                        'label' => 'Helper Text',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_type' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Button Type',
                        'settings' => ['options' => ['button', 'submit', 'reset'], 'isMultiple' => false],
                    ],
                    'form_builder_value' => [
                        'type' => 'ibexa_string',
                        'label' => 'Value',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_disabled' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Disabled',
                        'searchable' => false,
                    ],
                    'form_builder_formaction' => [
                        'type' => 'ibexa_string',
                        'label' => 'Form Action Override (URL)',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 2048, 'minStringLength' => null]],
                    ],
                    'form_builder_formmethod' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Form Method Override',
                        'settings' => ['options' => ['get', 'post', 'dialog'], 'isMultiple' => false],
                    ],
                    'form_builder_formenctype' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Form Encoding Type Override',
                        'settings' => ['options' => ['application/x-www-form-urlencoded', 'multipart/form-data', 'text/plain'], 'isMultiple' => false],
                    ],
                    'form_builder_formnovalidate' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Form No Validate',
                        'searchable' => false,
                    ],
                    'form_builder_formtarget' => [
                        'type' => 'ibexa_string',
                        'label' => 'Form Target Override',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_order' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Order',
                        'searchable' => false,
                    ],
                ],
            ],
        ];
    }
}
