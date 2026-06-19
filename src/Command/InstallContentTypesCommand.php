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

    private readonly bool $useModernFieldTypes;

    public function __construct(
        private readonly ContentTypeService $contentTypeService,
        private readonly Repository $repository,
    ) {
        $coreVersion = InstalledVersions::getVersion('ibexa/core') ?? '5.0.0';
        $this->useModernFieldTypes = \version_compare($coreVersion, '5.0.0', '>=');
        parent::__construct();
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
                if ($overwriteExisting) {
                    $this->deleteContentType($identifier, $io);
                }

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
                $io->note(\sprintf('Content type group "%s" not found — creating.', self::GROUP_IDENTIFIER));
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

            $io->writeln(\sprintf('  ✓ Created content type <info>%s</info>', $identifier));
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
                $io->writeln(\sprintf('  ✓ Deleted content type <info>%s</info>', $identifier));
            } catch (\Exception $e) {
                if (\str_contains($e->getMessage(), 'still has Content items')) {
                    $io->warning(\sprintf('Cannot delete content type "%s" — it has content items. Skipping overwrite.', $identifier));
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

            $missing = \array_filter(
                \array_keys($fields),
                fn (string $fieldId) => $contentType->getFieldDefinition($fieldId) === null,
            );

            if (empty($missing)) {
                $io->writeln(\sprintf('  ✓ Content type <info>%s</info> already has all field definitions — nothing to patch.', $identifier));

                return;
            }

            $draft = $this->contentTypeService->createContentTypeDraft($contentType);
            $position = \count(\iterator_to_array($contentType->getFieldDefinitions())) + 1;

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
            $io->writeln(\sprintf('  ✓ Patched content type <info>%s</info> — added: %s', $identifier, \implode(', ', $missing)));
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
                    'form_builder_accept_charset' => [
                        'type' => 'ibexa_string',
                        'label' => 'Accept Charset',
                        'description' => 'Specifies the character encodings that are to be used for form submission (accept-charset attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_autocorrect' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocorrect',
                        'description' => 'Controls whether autocorrection of editable text is enabled for spelling and/or punctuation errors.',
                        'settings' => ['options' => ['on', 'off'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_label' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Label',
                        'description' => 'Defines a string value that labels the current element for assistive technologies (aria-label).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_invalid' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Invalid',
                        'description' => 'Indicates that the value entered does not conform to the expected format (aria-invalid).',
                        'settings' => ['options' => ['false', 'true', 'grammar', 'spelling'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_live' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Live',
                        'description' => 'Defines how updates to the element should be announced to screen readers (aria-live).',
                        'settings' => ['options' => ['off', 'polite', 'assertive'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_atomic' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Atomic',
                        'description' => 'Indicates whether assistive technologies should present the entire region as a whole when changes occur (aria-atomic).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_relevant' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Relevant',
                        'description' => 'Indicates what content changes should be announced in a live region (aria-relevant).',
                        'settings' => ['options' => ['additions', 'removals', 'text', 'all', 'additions text'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_details' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Details',
                        'description' => 'References an element ID that provides additional details about the current element (aria-details).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_keyshortcuts' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Key Shortcuts',
                        'description' => 'Defines keyboard shortcuts available for the element (aria-keyshortcuts).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_roledescription' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Role Description',
                        'description' => 'Provides a human-readable custom role description for assistive technologies (aria-roledescription).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_id' => [
                        'type' => 'ibexa_string',
                        'label' => 'ID',
                        'description' => 'Unique HTML id attribute for the element.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_class' => [
                        'type' => 'ibexa_string',
                        'label' => 'CSS Class(es)',
                        'description' => 'Space-separated CSS class names (class attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 512, 'minStringLength' => null]],
                    ],
                    'form_builder_style' => [
                        'type' => 'ibexa_string',
                        'label' => 'Inline Style',
                        'description' => 'Inline CSS style (style attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_title' => [
                        'type' => 'ibexa_string',
                        'label' => 'Title',
                        'description' => 'Advisory information shown as a tooltip (title attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_lang' => [
                        'type' => 'ibexa_string',
                        'label' => 'Language',
                        'description' => 'Language of the element content (lang attribute, e.g. "en", "de").',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 35, 'minStringLength' => null]],
                    ],
                    'form_builder_dir' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Text Direction',
                        'description' => 'Specifies the text direction (dir attribute).',
                        'settings' => ['options' => ['ltr', 'rtl', 'auto'], 'isMultiple' => false],
                    ],
                    'form_builder_tabindex' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Tab Index',
                        'description' => 'Specifies the tab order of the element (tabindex attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_accesskey' => [
                        'type' => 'ibexa_string',
                        'label' => 'Access Key',
                        'description' => 'Keyboard shortcut to activate/focus the element (accesskey attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 10, 'minStringLength' => null]],
                    ],
                    'form_builder_hidden' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Hidden',
                        'description' => 'When enabled, the element is not rendered (hidden attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_draggable' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Draggable',
                        'description' => 'Specifies whether the element is draggable (draggable attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_translate' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Translate',
                        'description' => 'Specifies whether the element content should be translated (translate attribute).',
                        'settings' => ['options' => ['yes', 'no'], 'isMultiple' => false],
                    ],
                    'form_builder_slot' => [
                        'type' => 'ibexa_string',
                        'label' => 'Slot',
                        'description' => 'Assigns the element to a named slot in a shadow DOM (slot attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_data_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Data Attributes',
                        'description' => 'Custom data-* attributes. The "data-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'name', 'name' => 'Name'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                    'form_builder_alpine_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Alpine.js Attributes',
                        'description' => 'Alpine.js x-* directives. The "x-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'directive', 'name' => 'Directive'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
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
                        'description' => 'Specifies the type of the input. Defaults to text if omitted.',
                        'settings' => [
                            'options' => [
                                'button',
                                'checkbox',
                                'color',
                                'date',
                                'datetime-local',
                                'email',
                                'file',
                                'hidden',
                                'image',
                                'month',
                                'number',
                                'password',
                                'radio',
                                'range',
                                'reset',
                                'search',
                                'submit',
                                'tel',
                                'text',
                                'time',
                                'url',
                                'week',
                            ],
                            'isMultiple' => false,
                        ],
                    ],
                    'form_builder_value' => [
                        'type' => 'ibexa_string',
                        'label' => 'Default Value',
                        'description' => 'The value associated with the input (value attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_placeholder' => [
                        'type' => 'ibexa_string',
                        'label' => 'Placeholder',
                        'description' => 'Specifies a short hint that describes the expected value of the input field.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_accept' => [
                        'type' => 'ibexa_string',
                        'label' => 'Accept (MIME types)',
                        'description' => 'Comma-separated list of file types the server accepts (accept attribute). Used with type="file".',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 512, 'minStringLength' => null]],
                    ],
                    'form_builder_alt' => [
                        'type' => 'ibexa_string',
                        'label' => 'Alt Text',
                        'description' => 'Alternative text for image inputs (alt attribute). Used with type="image".',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_src' => [
                        'type' => 'ibexa_string',
                        'label' => 'Image Source URL',
                        'description' => 'URL of the image (src attribute). Used with type="image".',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 2048, 'minStringLength' => null]],
                    ],
                    'form_builder_height' => [
                        'type' => 'ibexa_string',
                        'label' => 'Height',
                        'description' => 'Height of the image input (height attribute). Used with type="image". Accepts integer (px).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 20, 'minStringLength' => null]],
                    ],
                    'form_builder_width' => [
                        'type' => 'ibexa_string',
                        'label' => 'Width',
                        'description' => 'Width of the image input (width attribute). Used with type="image". Accepts integer (px).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 20, 'minStringLength' => null]],
                    ],
                    'form_builder_list' => [
                        'type' => 'ibexa_string',
                        'label' => 'Datalist ID',
                        'description' => 'ID of a <datalist> element containing pre-defined options (list attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_pattern' => [
                        'type' => 'ibexa_string',
                        'label' => 'Pattern (regex)',
                        'description' => "Regular expression the input's value is checked against (pattern attribute).",
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
                        'description' => 'Pre-selects the input when the page loads (checked attribute). Used with type="checkbox" or type="radio".',
                        'searchable' => false,
                    ],
                    'form_builder_multiple' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Multiple',
                        'description' => 'Allows multiple values (multiple attribute). Used with type="email" or type="file".',
                        'searchable' => false,
                    ],
                    'form_builder_autocomplete' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocomplete',
                        'settings' => ['options' => ['on', 'off'], 'isMultiple' => false],
                    ],
                    'form_builder_autocorrect' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocorrect',
                        'description' => 'Controls whether autocorrection of editable text is enabled (autocorrect attribute).',
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
                        'description' => 'Visible width of the input in characters (size attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_min' => [
                        'type' => 'ibexa_string',
                        'label' => 'Min',
                        'description' => 'Minimum value (min attribute). Accepts numbers or date strings depending on input type.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_max' => [
                        'type' => 'ibexa_string',
                        'label' => 'Max',
                        'description' => 'Maximum value (max attribute). Accepts numbers or date strings depending on input type.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_step' => [
                        'type' => 'ibexa_string',
                        'label' => 'Step',
                        'description' => 'Legal number intervals for the input (step attribute). Use "any" to allow any value.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_dirname' => [
                        'type' => 'ibexa_string',
                        'label' => 'Dirname',
                        'description' => 'Name of the field that will contain the text direction on submit (dirname attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_form_owner' => [
                        'type' => 'ibexa_string',
                        'label' => 'Form Owner (ID)',
                        'description' => 'Associates this input with a form element by its ID, allowing placement outside the form (form attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_formaction' => [
                        'type' => 'ibexa_string',
                        'label' => 'Form Action Override (URL)',
                        'description' => 'URL that processes the form submission. Overrides the form action attribute. Used with type="submit" or type="image".',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 2048, 'minStringLength' => null]],
                    ],
                    'form_builder_formenctype' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Form Encoding Type Override',
                        'description' => 'Overrides the form enctype for submission. Used with type="submit" or type="image".',
                        'settings' => ['options' => ['application/x-www-form-urlencoded', 'multipart/form-data', 'text/plain'], 'isMultiple' => false],
                    ],
                    'form_builder_formmethod' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Form Method Override',
                        'description' => 'Overrides the form HTTP method. Used with type="submit" or type="image".',
                        'settings' => ['options' => ['get', 'post', 'dialog'], 'isMultiple' => false],
                    ],
                    'form_builder_formnovalidate' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Form No Validate',
                        'description' => 'Skips form validation on submit. Used with type="submit" or type="image".',
                        'searchable' => false,
                    ],
                    'form_builder_formtarget' => [
                        'type' => 'ibexa_string',
                        'label' => 'Form Target Override',
                        'description' => 'Overrides the form target (_self, _blank, _parent, _top, or a frame name). Used with type="submit" or type="image".',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_popovertarget' => [
                        'type' => 'ibexa_string',
                        'label' => 'Popover Target (ID)',
                        'description' => 'ID of the popover element this input controls (popovertarget attribute). Part of the Popover API.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_popovertargetaction' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Popover Target Action',
                        'description' => 'Action to perform on the controlled popover: show, hide, or toggle (default) (popovertargetaction attribute).',
                        'settings' => ['options' => ['toggle', 'show', 'hide'], 'isMultiple' => false],
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
                    'form_builder_role' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Role',
                        'description' => 'Defines the semantic purpose of the element for assistive technologies (role attribute).',
                        'settings' => ['options' => ['alert', 'application', 'article', 'banner', 'button', 'checkbox', 'complementary', 'contentinfo', 'dialog', 'form', 'grid', 'group', 'heading', 'img', 'link', 'list', 'listbox', 'listitem', 'main', 'menu', 'menubar', 'menuitem', 'navigation', 'none', 'presentation', 'radio', 'region', 'search', 'status', 'tab', 'tablist', 'tabpanel', 'textbox', 'toolbar', 'tooltip'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_label' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Label',
                        'description' => 'Defines a string value that labels the current element for assistive technologies (aria-label).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_labelledby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Labelled By',
                        'description' => 'Identifies the element(s) that label the current element. Space-separated list of IDs (aria-labelledby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_describedby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Described By',
                        'description' => 'Identifies the element(s) that describe the current element. Space-separated list of IDs (aria-describedby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_controls' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Controls',
                        'description' => 'Identifies the element(s) whose contents are controlled by this element. Space-separated list of IDs (aria-controls).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_current' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Current',
                        'description' => 'Indicates the current item within a container or set of related elements (aria-current).',
                        'settings' => ['options' => ['false', 'page', 'step', 'location', 'date', 'time', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_invalid' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Invalid',
                        'description' => 'Indicates that the value entered does not conform to the expected format (aria-invalid).',
                        'settings' => ['options' => ['false', 'true', 'grammar', 'spelling'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_disabled' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Disabled',
                        'description' => 'Indicates the element is perceivable but disabled (aria-disabled).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_required' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Required',
                        'description' => 'Specifies that the field is required before form submission (aria-required).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_readonly' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Readonly',
                        'description' => 'Marks the field as read-only but still selectable and focusable (aria-readonly).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_checked' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Checked',
                        'description' => 'Defines the checked state for checkboxes, radio buttons, or toggle switches (aria-checked).',
                        'settings' => ['options' => ['false', 'true', 'mixed', 'undefined'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_pressed' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Pressed',
                        'description' => 'Indicates whether a toggle element is pressed (aria-pressed).',
                        'settings' => ['options' => ['false', 'true', 'mixed', 'undefined'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_expanded' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Expanded',
                        'description' => 'Indicates whether a collapsible UI element is expanded or collapsed (aria-expanded).',
                        'settings' => ['options' => ['false', 'true', 'undefined'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_haspopup' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Has Popup',
                        'description' => 'Indicates that the element has an associated popup (aria-haspopup).',
                        'settings' => ['options' => ['false', 'true', 'menu', 'listbox', 'tree', 'grid', 'dialog'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_autocomplete' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Autocomplete',
                        'description' => 'Specifies autocomplete behaviour for the field (aria-autocomplete).',
                        'settings' => ['options' => ['none', 'inline', 'list', 'both'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_placeholder' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Placeholder',
                        'description' => 'Provides a placeholder hint for the field (aria-placeholder).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_valuemax' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Value Max',
                        'description' => 'Defines the maximum value for a range input (aria-valuemax).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_valuemin' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Value Min',
                        'description' => 'Defines the minimum value for a range input (aria-valuemin).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_valuenow' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Value Now',
                        'description' => 'Specifies the current value for a range input (aria-valuenow).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_valuetext' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Value Text',
                        'description' => 'Provides a human-readable representation of the current value (aria-valuetext).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_live' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Live',
                        'description' => 'Defines how updates to the element should be announced to screen readers (aria-live).',
                        'settings' => ['options' => ['off', 'polite', 'assertive'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_atomic' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Atomic',
                        'description' => 'Indicates whether assistive technologies should present the entire region as a whole (aria-atomic).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_relevant' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Relevant',
                        'description' => 'Indicates what content changes should be announced in a live region (aria-relevant).',
                        'settings' => ['options' => ['additions', 'removals', 'text', 'all', 'additions text'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_details' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Details',
                        'description' => 'References an element ID that provides additional details (aria-details).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_keyshortcuts' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Key Shortcuts',
                        'description' => 'Defines keyboard shortcuts available for the element (aria-keyshortcuts).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_roledescription' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Role Description',
                        'description' => 'Provides a human-readable custom role description for assistive technologies (aria-roledescription).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_id' => [
                        'type' => 'ibexa_string',
                        'label' => 'ID',
                        'description' => 'Unique HTML id attribute for the element.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_class' => [
                        'type' => 'ibexa_string',
                        'label' => 'CSS Class(es)',
                        'description' => 'Space-separated CSS class names (class attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 512, 'minStringLength' => null]],
                    ],
                    'form_builder_style' => [
                        'type' => 'ibexa_string',
                        'label' => 'Inline Style',
                        'description' => 'Inline CSS style (style attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_title' => [
                        'type' => 'ibexa_string',
                        'label' => 'Title',
                        'description' => 'Advisory information shown as a tooltip (title attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_lang' => [
                        'type' => 'ibexa_string',
                        'label' => 'Language',
                        'description' => 'Language of the element content (lang attribute, e.g. "en", "de").',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 35, 'minStringLength' => null]],
                    ],
                    'form_builder_dir' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Text Direction',
                        'description' => 'Specifies the text direction (dir attribute).',
                        'settings' => ['options' => ['ltr', 'rtl', 'auto'], 'isMultiple' => false],
                    ],
                    'form_builder_tabindex' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Tab Index',
                        'description' => 'Specifies the tab order of the element (tabindex attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_accesskey' => [
                        'type' => 'ibexa_string',
                        'label' => 'Access Key',
                        'description' => 'Keyboard shortcut to activate/focus the element (accesskey attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 10, 'minStringLength' => null]],
                    ],
                    'form_builder_hidden' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Hidden',
                        'description' => 'When enabled, the element is not rendered (hidden attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_draggable' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Draggable',
                        'description' => 'Specifies whether the element is draggable (draggable attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_translate' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Translate',
                        'description' => 'Specifies whether the element content should be translated (translate attribute).',
                        'settings' => ['options' => ['yes', 'no'], 'isMultiple' => false],
                    ],
                    'form_builder_autocapitalize' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocapitalize',
                        'description' => 'Controls whether text input is automatically capitalised (autocapitalize attribute).',
                        'settings' => ['options' => ['off', 'none', 'on', 'sentences', 'words', 'characters'], 'isMultiple' => false],
                    ],
                    'form_builder_autofocus' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Autofocus',
                        'description' => 'When enabled, the input automatically receives focus on page load (autofocus attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_contenteditable' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Content Editable',
                        'description' => 'Specifies whether the element content is editable (contenteditable attribute).',
                        'settings' => ['options' => ['true', 'false', 'inherit', 'plaintext-only'], 'isMultiple' => false],
                    ],
                    'form_builder_inputmode' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Input Mode',
                        'description' => 'Hints at the type of virtual keyboard to display (inputmode attribute).',
                        'settings' => ['options' => ['none', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url'], 'isMultiple' => false],
                    ],
                    'form_builder_spellcheck' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Spell Check',
                        'description' => 'Specifies whether spell checking is enabled (spellcheck attribute).',
                        'settings' => ['options' => ['true', 'false', 'default'], 'isMultiple' => false],
                    ],
                    'form_builder_data_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Data Attributes',
                        'description' => 'Custom data-* attributes. The "data-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'name', 'name' => 'Name'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                    'form_builder_alpine_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Alpine.js Attributes',
                        'description' => 'Alpine.js x-* directives. The "x-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'directive', 'name' => 'Directive'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
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
                    'form_builder_autocorrect' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocorrect',
                        'description' => 'Controls whether autocorrection of editable text is enabled for spelling and/or punctuation errors.',
                        'settings' => ['options' => ['on', 'off'], 'isMultiple' => false],
                    ],
                    'form_builder_dirname' => [
                        'type' => 'ibexa_string',
                        'label' => 'Dirname',
                        'description' => 'Specifies the name of the field that will contain the text direction (ltr or rtl) when the form is submitted.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_form_owner' => [
                        'type' => 'ibexa_string',
                        'label' => 'Form Owner (ID)',
                        'description' => 'Associates this textarea with a form element by its ID. Allows placement outside the form element.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_role' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Role',
                        'description' => 'Defines the semantic purpose of the element for assistive technologies (role attribute).',
                        'settings' => ['options' => ['alert', 'application', 'article', 'banner', 'button', 'checkbox', 'complementary', 'contentinfo', 'dialog', 'form', 'grid', 'group', 'heading', 'img', 'link', 'list', 'listbox', 'listitem', 'main', 'menu', 'menubar', 'menuitem', 'navigation', 'none', 'presentation', 'radio', 'region', 'search', 'status', 'tab', 'tablist', 'tabpanel', 'textbox', 'toolbar', 'tooltip'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_label' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Label',
                        'description' => 'Defines a string value that labels the current element for assistive technologies (aria-label).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_labelledby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Labelled By',
                        'description' => 'Identifies the element(s) that label the current element. Space-separated list of IDs (aria-labelledby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_describedby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Described By',
                        'description' => 'Identifies the element(s) that describe the current element. Space-separated list of IDs (aria-describedby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_controls' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Controls',
                        'description' => 'Identifies the element(s) whose contents are controlled by this element. Space-separated list of IDs (aria-controls).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_invalid' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Invalid',
                        'description' => 'Indicates that the value entered does not conform to the expected format (aria-invalid).',
                        'settings' => ['options' => ['false', 'true', 'grammar', 'spelling'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_disabled' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Disabled',
                        'description' => 'Indicates that the element is perceivable but disabled (aria-disabled).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_required' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Required',
                        'description' => 'Specifies that the field is required before form submission (aria-required).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_readonly' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Readonly',
                        'description' => 'Marks the field as read-only but still selectable and focusable (aria-readonly).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_multiline' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Multiline',
                        'description' => 'Indicates whether the input allows multiple lines of text (aria-multiline).',
                        'settings' => ['options' => ['true', 'false'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_placeholder' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Placeholder',
                        'description' => 'Provides a placeholder hint for the field (aria-placeholder).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_autocomplete' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Autocomplete',
                        'description' => 'Specifies autocomplete behaviour for the field (aria-autocomplete).',
                        'settings' => ['options' => ['none', 'inline', 'list', 'both'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_expanded' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Expanded',
                        'description' => 'Indicates whether a collapsible UI element is expanded or collapsed (aria-expanded).',
                        'settings' => ['options' => ['false', 'true', 'undefined'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_haspopup' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Has Popup',
                        'description' => 'Indicates that the element has an associated popup (aria-haspopup).',
                        'settings' => ['options' => ['false', 'true', 'menu', 'listbox', 'tree', 'grid', 'dialog'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_pressed' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Pressed',
                        'description' => 'Indicates whether a toggle element is pressed (aria-pressed).',
                        'settings' => ['options' => ['false', 'true', 'mixed', 'undefined'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_live' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Live',
                        'description' => 'Defines how updates to the element should be announced to screen readers (aria-live).',
                        'settings' => ['options' => ['off', 'polite', 'assertive'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_atomic' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Atomic',
                        'description' => 'Indicates whether assistive technologies should present the entire region as a whole (aria-atomic).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_relevant' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Relevant',
                        'description' => 'Indicates what content changes should be announced in a live region (aria-relevant).',
                        'settings' => ['options' => ['additions', 'removals', 'text', 'all', 'additions text'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_details' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Details',
                        'description' => 'References an element ID that provides additional details about the current element (aria-details).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_keyshortcuts' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Key Shortcuts',
                        'description' => 'Defines keyboard shortcuts available for the element (aria-keyshortcuts).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_roledescription' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Role Description',
                        'description' => 'Provides a human-readable custom role description for assistive technologies (aria-roledescription).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_id' => [
                        'type' => 'ibexa_string',
                        'label' => 'ID',
                        'description' => 'Unique HTML id attribute for the element.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_class' => [
                        'type' => 'ibexa_string',
                        'label' => 'CSS Class(es)',
                        'description' => 'Space-separated CSS class names (class attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 512, 'minStringLength' => null]],
                    ],
                    'form_builder_style' => [
                        'type' => 'ibexa_string',
                        'label' => 'Inline Style',
                        'description' => 'Inline CSS style (style attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_title' => [
                        'type' => 'ibexa_string',
                        'label' => 'Title',
                        'description' => 'Advisory information shown as a tooltip (title attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_lang' => [
                        'type' => 'ibexa_string',
                        'label' => 'Language',
                        'description' => 'Language of the element content (lang attribute, e.g. "en", "de").',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 35, 'minStringLength' => null]],
                    ],
                    'form_builder_dir' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Text Direction',
                        'description' => 'Specifies the text direction (dir attribute).',
                        'settings' => ['options' => ['ltr', 'rtl', 'auto'], 'isMultiple' => false],
                    ],
                    'form_builder_tabindex' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Tab Index',
                        'description' => 'Specifies the tab order of the element (tabindex attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_accesskey' => [
                        'type' => 'ibexa_string',
                        'label' => 'Access Key',
                        'description' => 'Keyboard shortcut to activate/focus the element (accesskey attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 10, 'minStringLength' => null]],
                    ],
                    'form_builder_hidden' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Hidden',
                        'description' => 'When enabled, the element is not rendered (hidden attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_draggable' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Draggable',
                        'description' => 'Specifies whether the element is draggable (draggable attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_translate' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Translate',
                        'description' => 'Specifies whether the element content should be translated (translate attribute).',
                        'settings' => ['options' => ['yes', 'no'], 'isMultiple' => false],
                    ],
                    'form_builder_slot' => [
                        'type' => 'ibexa_string',
                        'label' => 'Slot',
                        'description' => 'Assigns the element to a named slot in a shadow DOM (slot attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_data_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Data Attributes',
                        'description' => 'Custom data-* attributes. The "data-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'name', 'name' => 'Name'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                    'form_builder_alpine_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Alpine.js Attributes',
                        'description' => 'Alpine.js x-* directives. The "x-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'directive', 'name' => 'Directive'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                    'form_builder_autocapitalize' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocapitalize',
                        'description' => 'Controls whether text input is automatically capitalised (autocapitalize attribute).',
                        'settings' => ['options' => ['off', 'none', 'on', 'sentences', 'words', 'characters'], 'isMultiple' => false],
                    ],
                    'form_builder_autofocus' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Autofocus',
                        'description' => 'When enabled, the textarea automatically receives focus on page load (autofocus attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_contenteditable' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Content Editable',
                        'description' => 'Specifies whether the element content is editable by the user (contenteditable attribute).',
                        'settings' => ['options' => ['true', 'false', 'inherit', 'plaintext-only'], 'isMultiple' => false],
                    ],
                    'form_builder_inputmode' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Input Mode',
                        'description' => 'Hints at the type of virtual keyboard to display (inputmode attribute).',
                        'settings' => ['options' => ['none', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url'], 'isMultiple' => false],
                    ],
                    'form_builder_spellcheck' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Spell Check',
                        'description' => 'Specifies whether spell checking is enabled (spellcheck attribute).',
                        'settings' => ['options' => ['true', 'false', 'default'], 'isMultiple' => false],
                    ],
                    'form_builder_popover' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Popover',
                        'description' => 'Marks the element as a popover (popover attribute).',
                        'settings' => ['options' => ['auto', 'manual'], 'isMultiple' => false],
                    ],
                ],
            ],

            'select' => [
                'name' => 'Select',
                'description' => 'The select element represents a control for selecting amongst a set of options.',
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
                    'form_builder_autocomplete' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocomplete',
                        'settings' => ['options' => ['on', 'off'], 'isMultiple' => false],
                    ],
                    'form_builder_autocorrect' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocorrect',
                        'description' => 'Controls whether autocorrection of editable text is enabled (autocorrect attribute).',
                        'settings' => ['options' => ['on', 'off'], 'isMultiple' => false],
                    ],
                    'form_builder_disabled' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Disabled',
                        'searchable' => false,
                    ],
                    'form_builder_multiple' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Multiple',
                        'description' => 'When present, allows the user to select more than one option.',
                        'searchable' => false,
                    ],
                    'form_builder_required' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Required',
                        'searchable' => false,
                    ],
                    'form_builder_size' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Size (visible rows)',
                        'description' => 'Number of visible rows in the select list (size attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_form_owner' => [
                        'type' => 'ibexa_string',
                        'label' => 'Form Owner (ID)',
                        'description' => 'Associates this select with a form element by its ID, allowing placement outside the form (form attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
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
                    'form_builder_role' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Role',
                        'description' => 'Defines the semantic purpose of the element for assistive technologies (role attribute).',
                        'settings' => ['options' => ['alert', 'application', 'article', 'banner', 'button', 'checkbox', 'complementary', 'contentinfo', 'dialog', 'form', 'grid', 'group', 'heading', 'img', 'link', 'list', 'listbox', 'listitem', 'main', 'menu', 'menubar', 'menuitem', 'navigation', 'none', 'presentation', 'radio', 'region', 'search', 'status', 'tab', 'tablist', 'tabpanel', 'textbox', 'toolbar', 'tooltip'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_label' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Label',
                        'description' => 'Defines a string value that labels the current element for assistive technologies (aria-label).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_labelledby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Labelled By',
                        'description' => 'Identifies the element(s) that label the current element. Space-separated list of IDs (aria-labelledby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_describedby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Described By',
                        'description' => 'Identifies the element(s) that describe the current element. Space-separated list of IDs (aria-describedby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_controls' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Controls',
                        'description' => 'Identifies the element(s) whose contents are controlled by this element. Space-separated list of IDs (aria-controls).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_invalid' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Invalid',
                        'description' => 'Indicates that the value entered does not conform to the expected format (aria-invalid).',
                        'settings' => ['options' => ['false', 'true', 'grammar', 'spelling'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_disabled' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Disabled',
                        'description' => 'Indicates that the element is perceivable but disabled (aria-disabled).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_required' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Required',
                        'description' => 'Specifies that the field is required before form submission (aria-required).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_readonly' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Readonly',
                        'description' => 'Marks the field as read-only but still selectable and focusable (aria-readonly).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_expanded' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Expanded',
                        'description' => 'Indicates whether a collapsible UI element is expanded or collapsed (aria-expanded).',
                        'settings' => ['options' => ['false', 'true', 'undefined'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_haspopup' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Has Popup',
                        'description' => 'Indicates that the element has an associated popup (aria-haspopup).',
                        'settings' => ['options' => ['false', 'true', 'menu', 'listbox', 'tree', 'grid', 'dialog'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_pressed' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Pressed',
                        'description' => 'Indicates whether a toggle button is pressed (aria-pressed).',
                        'settings' => ['options' => ['false', 'true', 'mixed', 'undefined'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_autocomplete' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Autocomplete',
                        'description' => 'Specifies autocomplete behaviour for the field (aria-autocomplete).',
                        'settings' => ['options' => ['none', 'inline', 'list', 'both'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_placeholder' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Placeholder',
                        'description' => 'Provides a placeholder hint for the field (aria-placeholder).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_multiselectable' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Multiselectable',
                        'description' => 'Defines whether multiple items can be selected in a listbox, grid, or tree (aria-multiselectable).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_activedescendant' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Active Descendant',
                        'description' => 'Identifies the currently active child element, e.g. for autocomplete suggestions or composite widgets (aria-activedescendant).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_orientation' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Orientation',
                        'description' => 'Specifies whether an element is horizontal or vertical (aria-orientation).',
                        'settings' => ['options' => ['horizontal', 'vertical', 'undefined'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_live' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Live',
                        'description' => 'Defines how updates to the element should be announced to screen readers (aria-live).',
                        'settings' => ['options' => ['off', 'polite', 'assertive'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_atomic' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Atomic',
                        'description' => 'Indicates whether assistive technologies should present the entire region as a whole (aria-atomic).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_relevant' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Relevant',
                        'description' => 'Indicates what content changes should be announced in a live region (aria-relevant).',
                        'settings' => ['options' => ['additions', 'removals', 'text', 'all', 'additions text'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_details' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Details',
                        'description' => 'References an element ID that provides additional details (aria-details).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_keyshortcuts' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Key Shortcuts',
                        'description' => 'Defines keyboard shortcuts available for the element (aria-keyshortcuts).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_roledescription' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Role Description',
                        'description' => 'Provides a human-readable custom role description for assistive technologies (aria-roledescription).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_id' => [
                        'type' => 'ibexa_string',
                        'label' => 'ID',
                        'description' => 'Unique HTML id attribute for the element.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_class' => [
                        'type' => 'ibexa_string',
                        'label' => 'CSS Class(es)',
                        'description' => 'Space-separated CSS class names (class attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 512, 'minStringLength' => null]],
                    ],
                    'form_builder_style' => [
                        'type' => 'ibexa_string',
                        'label' => 'Inline Style',
                        'description' => 'Inline CSS style (style attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_title' => [
                        'type' => 'ibexa_string',
                        'label' => 'Title',
                        'description' => 'Advisory information shown as a tooltip (title attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_lang' => [
                        'type' => 'ibexa_string',
                        'label' => 'Language',
                        'description' => 'Language of the element content (lang attribute, e.g. "en", "de").',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 35, 'minStringLength' => null]],
                    ],
                    'form_builder_dir' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Text Direction',
                        'description' => 'Specifies the text direction (dir attribute).',
                        'settings' => ['options' => ['ltr', 'rtl', 'auto'], 'isMultiple' => false],
                    ],
                    'form_builder_tabindex' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Tab Index',
                        'description' => 'Specifies the tab order of the element (tabindex attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_accesskey' => [
                        'type' => 'ibexa_string',
                        'label' => 'Access Key',
                        'description' => 'Keyboard shortcut to activate/focus the element (accesskey attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 10, 'minStringLength' => null]],
                    ],
                    'form_builder_hidden' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Hidden',
                        'description' => 'When enabled, the element is not rendered (hidden attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_draggable' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Draggable',
                        'description' => 'Specifies whether the element is draggable (draggable attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_translate' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Translate',
                        'description' => 'Specifies whether the element content should be translated (translate attribute).',
                        'settings' => ['options' => ['yes', 'no'], 'isMultiple' => false],
                    ],
                    'form_builder_slot' => [
                        'type' => 'ibexa_string',
                        'label' => 'Slot',
                        'description' => 'Assigns the element to a named slot in a shadow DOM (slot attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_autocapitalize' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocapitalize',
                        'description' => 'Controls whether text input is automatically capitalised (autocapitalize attribute).',
                        'settings' => ['options' => ['off', 'none', 'on', 'sentences', 'words', 'characters'], 'isMultiple' => false],
                    ],
                    'form_builder_autofocus' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Autofocus',
                        'description' => 'When enabled, the element automatically receives focus on page load (autofocus attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_contenteditable' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Content Editable',
                        'description' => 'Specifies whether the element content is editable (contenteditable attribute).',
                        'settings' => ['options' => ['true', 'false', 'inherit', 'plaintext-only'], 'isMultiple' => false],
                    ],
                    'form_builder_inputmode' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Input Mode',
                        'description' => 'Hints at the type of virtual keyboard to display (inputmode attribute).',
                        'settings' => ['options' => ['none', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url'], 'isMultiple' => false],
                    ],
                    'form_builder_spellcheck' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Spell Check',
                        'description' => 'Specifies whether spell checking is enabled (spellcheck attribute).',
                        'settings' => ['options' => ['true', 'false', 'default'], 'isMultiple' => false],
                    ],
                    'form_builder_popover' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Popover',
                        'description' => 'Turns the element into a popover element (popover attribute). Part of the Popover API.',
                        'settings' => ['options' => ['auto', 'manual', 'hint'], 'isMultiple' => false],
                    ],
                    'form_builder_data_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Data Attributes',
                        'description' => 'Custom data-* attributes. The "data-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'name', 'name' => 'Name'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                    'form_builder_alpine_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Alpine.js Attributes',
                        'description' => 'Alpine.js x-* directives. The "x-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'directive', 'name' => 'Directive'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                ],
            ],

            'option' => [
                'name' => 'Option',
                'description' => 'HTML <option> element managed as Ibexa content (child of select or optgroup).',
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
                        'description' => 'Specifies a label for the option (label attribute). Falls back to element text content.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_value' => [
                        'type' => 'ibexa_string',
                        'label' => 'Value',
                        'description' => 'The value submitted with the form when this option is selected (value attribute).',
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
                    'form_builder_role' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Role',
                        'description' => 'Defines the semantic purpose of the element for assistive technologies (role attribute).',
                        'settings' => ['options' => ['alert', 'application', 'article', 'banner', 'button', 'checkbox', 'complementary', 'contentinfo', 'dialog', 'form', 'grid', 'group', 'heading', 'img', 'link', 'list', 'listbox', 'listitem', 'main', 'menu', 'menubar', 'menuitem', 'navigation', 'none', 'presentation', 'radio', 'region', 'search', 'status', 'tab', 'tablist', 'tabpanel', 'textbox', 'toolbar', 'tooltip'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_controls' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Controls',
                        'description' => 'Identifies the element(s) whose contents are controlled by this element. Space-separated list of IDs (aria-controls).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_describedby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Described By',
                        'description' => 'Identifies the element(s) that describe the current element. Space-separated list of IDs (aria-describedby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_labelledby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Labelled By',
                        'description' => 'Identifies the element(s) that label the current element. Space-separated list of IDs (aria-labelledby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_busy' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Busy',
                        'description' => 'Indicates whether the element is currently busy (aria-busy).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_hidden' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Hidden',
                        'description' => 'Indicates whether the element is exposed to an accessibility API. Use only on decorative elements (aria-hidden).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_selected' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Selected',
                        'description' => 'Indicates whether the option is selected (aria-selected).',
                        'settings' => ['options' => ['false', 'true', 'undefined'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_posinset' => [
                        'type' => 'ibexa_integer',
                        'label' => 'ARIA Position in Set',
                        'description' => "Defines the element's 1-based position within a set (aria-posinset).",
                        'searchable' => false,
                    ],
                    'form_builder_aria_setsize' => [
                        'type' => 'ibexa_integer',
                        'label' => 'ARIA Set Size',
                        'description' => 'Specifies the total number of items in a set (aria-setsize).',
                        'searchable' => false,
                    ],
                    'form_builder_aria_level' => [
                        'type' => 'ibexa_integer',
                        'label' => 'ARIA Level',
                        'description' => 'Defines the hierarchical level of the element (aria-level).',
                        'searchable' => false,
                    ],
                    'form_builder_aria_details' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Details',
                        'description' => 'References an element ID that provides additional details about the current element (aria-details).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_keyshortcuts' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Key Shortcuts',
                        'description' => 'Defines keyboard shortcuts available for the element (aria-keyshortcuts).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_roledescription' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Role Description',
                        'description' => 'Provides a human-readable custom role description for assistive technologies (aria-roledescription).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_live' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Live',
                        'description' => 'Defines how updates to the element should be announced to screen readers (aria-live).',
                        'settings' => ['options' => ['off', 'polite', 'assertive'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_relevant' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Relevant',
                        'description' => 'Indicates what content changes should be announced in a live region (aria-relevant).',
                        'settings' => ['options' => ['additions', 'removals', 'text', 'all', 'additions text'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_atomic' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Atomic',
                        'description' => 'Indicates whether assistive technologies should present the entire region as a whole (aria-atomic).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_id' => [
                        'type' => 'ibexa_string',
                        'label' => 'ID',
                        'description' => 'Unique HTML id attribute for the element.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_class' => [
                        'type' => 'ibexa_string',
                        'label' => 'CSS Class(es)',
                        'description' => 'Space-separated CSS class names (class attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 512, 'minStringLength' => null]],
                    ],
                    'form_builder_style' => [
                        'type' => 'ibexa_string',
                        'label' => 'Inline Style',
                        'description' => 'Inline CSS style (style attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_title' => [
                        'type' => 'ibexa_string',
                        'label' => 'Title',
                        'description' => 'Advisory information shown as a tooltip (title attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_lang' => [
                        'type' => 'ibexa_string',
                        'label' => 'Language',
                        'description' => 'Language of the element content (lang attribute, e.g. "en", "de").',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 35, 'minStringLength' => null]],
                    ],
                    'form_builder_dir' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Text Direction',
                        'description' => 'Specifies the text direction (dir attribute).',
                        'settings' => ['options' => ['ltr', 'rtl', 'auto'], 'isMultiple' => false],
                    ],
                    'form_builder_tabindex' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Tab Index',
                        'description' => 'Specifies the tab order of the element (tabindex attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_accesskey' => [
                        'type' => 'ibexa_string',
                        'label' => 'Access Key',
                        'description' => 'Keyboard shortcut to activate/focus the element (accesskey attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 10, 'minStringLength' => null]],
                    ],
                    'form_builder_hidden' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Hidden',
                        'description' => 'When enabled, the element is not rendered (hidden attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_draggable' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Draggable',
                        'description' => 'Specifies whether the element is draggable (draggable attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_translate' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Translate',
                        'description' => 'Specifies whether the element content should be translated (translate attribute).',
                        'settings' => ['options' => ['yes', 'no'], 'isMultiple' => false],
                    ],
                    'form_builder_slot' => [
                        'type' => 'ibexa_string',
                        'label' => 'Slot',
                        'description' => 'Assigns the element to a named slot in a shadow DOM (slot attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_autocapitalize' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocapitalize',
                        'description' => 'Controls whether text input is automatically capitalised (autocapitalize attribute).',
                        'settings' => ['options' => ['off', 'none', 'on', 'sentences', 'words', 'characters'], 'isMultiple' => false],
                    ],
                    'form_builder_contenteditable' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Content Editable',
                        'description' => 'Specifies whether the element content is editable by the user (contenteditable attribute).',
                        'settings' => ['options' => ['true', 'false', 'inherit', 'plaintext-only'], 'isMultiple' => false],
                    ],
                    'form_builder_inputmode' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Input Mode',
                        'description' => 'Hints at the type of virtual keyboard to display (inputmode attribute).',
                        'settings' => ['options' => ['none', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url'], 'isMultiple' => false],
                    ],
                    'form_builder_spellcheck' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Spell Check',
                        'description' => 'Specifies whether spell checking is enabled (spellcheck attribute).',
                        'settings' => ['options' => ['true', 'false', 'default'], 'isMultiple' => false],
                    ],
                    'form_builder_popover' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Popover',
                        'description' => 'Marks the element as a popover (popover attribute).',
                        'settings' => ['options' => ['auto', 'manual'], 'isMultiple' => false],
                    ],
                    'form_builder_data_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Data Attributes',
                        'description' => 'Custom data-* attributes. The "data-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'name', 'name' => 'Name'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                    'form_builder_alpine_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Alpine.js Attributes',
                        'description' => 'Alpine.js x-* directives. The "x-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'directive', 'name' => 'Directive'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                ],
            ],

            'optgroup' => [
                'name' => 'Option Group',
                'description' => 'HTML <optgroup> element managed as Ibexa content (child of select, groups option elements).',
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
                        'label' => 'Group Label',
                        'description' => 'The visible label for this option group (label attribute).',
                        'required' => true,
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => 1]],
                    ],
                    'form_builder_disabled' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Disabled',
                        'description' => 'When enabled, all options within this group are disabled.',
                        'searchable' => false,
                    ],
                    'form_builder_order' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Order',
                        'searchable' => false,
                    ],
                    'form_builder_role' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Role',
                        'description' => 'Defines the semantic purpose of the element for assistive technologies (role attribute).',
                        'settings' => ['options' => ['alert', 'application', 'article', 'banner', 'button', 'checkbox', 'complementary', 'contentinfo', 'dialog', 'form', 'grid', 'group', 'heading', 'img', 'link', 'list', 'listbox', 'listitem', 'main', 'menu', 'menubar', 'menuitem', 'navigation', 'none', 'presentation', 'radio', 'region', 'search', 'status', 'tab', 'tablist', 'tabpanel', 'textbox', 'toolbar', 'tooltip'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_controls' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Controls',
                        'description' => 'Identifies the element(s) whose contents are controlled by this element. Space-separated list of IDs (aria-controls).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_describedby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Described By',
                        'description' => 'Identifies the element(s) that describe the current element. Space-separated list of IDs (aria-describedby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_labelledby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Labelled By',
                        'description' => 'Identifies the element(s) that label the current element. Space-separated list of IDs (aria-labelledby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_busy' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Busy',
                        'description' => 'Indicates whether the element is currently busy (aria-busy).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_hidden' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Hidden',
                        'description' => 'Indicates whether the element is exposed to an accessibility API. Use only on decorative elements (aria-hidden).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_details' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Details',
                        'description' => 'References an element ID that provides additional details about the current element (aria-details).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_keyshortcuts' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Key Shortcuts',
                        'description' => 'Defines keyboard shortcuts available for the element (aria-keyshortcuts).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_roledescription' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Role Description',
                        'description' => 'Provides a human-readable custom role description for assistive technologies (aria-roledescription).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_live' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Live',
                        'description' => 'Defines how updates to the element should be announced to screen readers (aria-live).',
                        'settings' => ['options' => ['off', 'polite', 'assertive'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_relevant' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Relevant',
                        'description' => 'Indicates what content changes should be announced in a live region (aria-relevant).',
                        'settings' => ['options' => ['additions', 'removals', 'text', 'all', 'additions text'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_atomic' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Atomic',
                        'description' => 'Indicates whether assistive technologies should present the entire region as a whole (aria-atomic).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_id' => [
                        'type' => 'ibexa_string',
                        'label' => 'ID',
                        'description' => 'Unique HTML id attribute for the element.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_class' => [
                        'type' => 'ibexa_string',
                        'label' => 'CSS Class(es)',
                        'description' => 'Space-separated CSS class names (class attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 512, 'minStringLength' => null]],
                    ],
                    'form_builder_style' => [
                        'type' => 'ibexa_string',
                        'label' => 'Inline Style',
                        'description' => 'Inline CSS style (style attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_title' => [
                        'type' => 'ibexa_string',
                        'label' => 'Title',
                        'description' => 'Advisory information shown as a tooltip (title attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_lang' => [
                        'type' => 'ibexa_string',
                        'label' => 'Language',
                        'description' => 'Language of the element content (lang attribute, e.g. "en", "de").',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 35, 'minStringLength' => null]],
                    ],
                    'form_builder_dir' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Text Direction',
                        'description' => 'Specifies the text direction (dir attribute).',
                        'settings' => ['options' => ['ltr', 'rtl', 'auto'], 'isMultiple' => false],
                    ],
                    'form_builder_tabindex' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Tab Index',
                        'description' => 'Specifies the tab order of the element (tabindex attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_accesskey' => [
                        'type' => 'ibexa_string',
                        'label' => 'Access Key',
                        'description' => 'Keyboard shortcut to activate/focus the element (accesskey attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 10, 'minStringLength' => null]],
                    ],
                    'form_builder_hidden' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Hidden',
                        'description' => 'When enabled, the element is not rendered (hidden attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_draggable' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Draggable',
                        'description' => 'Specifies whether the element is draggable (draggable attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_translate' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Translate',
                        'description' => 'Specifies whether the element content should be translated (translate attribute).',
                        'settings' => ['options' => ['yes', 'no'], 'isMultiple' => false],
                    ],
                    'form_builder_slot' => [
                        'type' => 'ibexa_string',
                        'label' => 'Slot',
                        'description' => 'Assigns the element to a named slot in a shadow DOM (slot attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_data_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Data Attributes',
                        'description' => 'Custom data-* attributes. The "data-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'name', 'name' => 'Name'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                    'form_builder_alpine_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Alpine.js Attributes',
                        'description' => 'Alpine.js x-* directives. The "x-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'directive', 'name' => 'Directive'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                    'form_builder_autocapitalize' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocapitalize',
                        'description' => 'Controls whether text input is automatically capitalised (autocapitalize attribute).',
                        'settings' => ['options' => ['off', 'none', 'on', 'sentences', 'words', 'characters'], 'isMultiple' => false],
                    ],
                    'form_builder_contenteditable' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Content Editable',
                        'description' => 'Specifies whether the element content is editable by the user (contenteditable attribute).',
                        'settings' => ['options' => ['true', 'false', 'inherit', 'plaintext-only'], 'isMultiple' => false],
                    ],
                    'form_builder_inputmode' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Input Mode',
                        'description' => 'Hints at the type of virtual keyboard to display (inputmode attribute).',
                        'settings' => ['options' => ['none', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url'], 'isMultiple' => false],
                    ],
                    'form_builder_spellcheck' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Spell Check',
                        'description' => 'Specifies whether spell checking is enabled (spellcheck attribute).',
                        'settings' => ['options' => ['true', 'false', 'default'], 'isMultiple' => false],
                    ],
                    'form_builder_popover' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Popover',
                        'description' => 'Marks the element as a popover (popover attribute).',
                        'settings' => ['options' => ['auto', 'manual'], 'isMultiple' => false],
                    ],
                ],
            ],

            'fieldset' => [
                'name' => 'Fieldset',
                'description' => 'The fieldset element represents a set of form controls optionally grouped under a common name.',
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
                    'form_builder_autocorrect' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocorrect',
                        'description' => 'Controls whether autocorrection of editable text is enabled (autocorrect attribute).',
                        'settings' => ['options' => ['on', 'off'], 'isMultiple' => false],
                    ],
                    'form_builder_disabled' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Disabled',
                        'description' => 'When present, all form controls inside this fieldset are disabled.',
                        'searchable' => false,
                    ],
                    'form_builder_form_owner' => [
                        'type' => 'ibexa_string',
                        'label' => 'Form Owner (ID)',
                        'description' => 'Associates this fieldset with a form element by its ID (form attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
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
                    'form_builder_role' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Role',
                        'description' => 'Defines the semantic purpose of the element for assistive technologies (role attribute).',
                        'settings' => ['options' => ['alert', 'application', 'article', 'banner', 'button', 'checkbox', 'complementary', 'contentinfo', 'dialog', 'form', 'grid', 'group', 'heading', 'img', 'link', 'list', 'listbox', 'listitem', 'main', 'menu', 'menubar', 'menuitem', 'navigation', 'none', 'presentation', 'radio', 'region', 'search', 'status', 'tab', 'tablist', 'tabpanel', 'textbox', 'toolbar', 'tooltip'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_labelledby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Labelled By',
                        'description' => 'Identifies the element(s) that label the current element. Space-separated list of IDs (aria-labelledby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_describedby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Described By',
                        'description' => 'Identifies the element(s) that describe the current element. Space-separated list of IDs (aria-describedby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_controls' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Controls',
                        'description' => 'Identifies the element(s) whose contents are controlled by this element. Space-separated list of IDs (aria-controls).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_busy' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Busy',
                        'description' => 'Indicates whether the element is currently being updated (aria-busy).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_hidden' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Hidden',
                        'description' => 'Indicates whether the element is exposed to an accessibility API. Use with caution on interactive elements (aria-hidden).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_invalid' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Invalid',
                        'description' => 'Indicates that the value entered does not conform to the expected format (aria-invalid).',
                        'settings' => ['options' => ['false', 'true', 'grammar', 'spelling'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_disabled' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Disabled',
                        'description' => 'Indicates that the element is perceivable but disabled (aria-disabled).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_live' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Live',
                        'description' => 'Defines how updates to the element should be announced to screen readers (aria-live).',
                        'settings' => ['options' => ['off', 'polite', 'assertive'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_atomic' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Atomic',
                        'description' => 'Indicates whether assistive technologies should present the entire region as a whole (aria-atomic).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_relevant' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Relevant',
                        'description' => 'Indicates what content changes should be announced in a live region (aria-relevant).',
                        'settings' => ['options' => ['additions', 'removals', 'text', 'all', 'additions text'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_details' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Details',
                        'description' => 'References an element ID that provides additional details (aria-details).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_keyshortcuts' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Key Shortcuts',
                        'description' => 'Defines keyboard shortcuts available for the element (aria-keyshortcuts).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_roledescription' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Role Description',
                        'description' => 'Provides a human-readable custom role description for assistive technologies (aria-roledescription).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_id' => [
                        'type' => 'ibexa_string',
                        'label' => 'ID',
                        'description' => 'Unique HTML id attribute for the element.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_class' => [
                        'type' => 'ibexa_string',
                        'label' => 'CSS Class(es)',
                        'description' => 'Space-separated CSS class names (class attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 512, 'minStringLength' => null]],
                    ],
                    'form_builder_style' => [
                        'type' => 'ibexa_string',
                        'label' => 'Inline Style',
                        'description' => 'Inline CSS style (style attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_title' => [
                        'type' => 'ibexa_string',
                        'label' => 'Title',
                        'description' => 'Advisory information shown as a tooltip (title attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_lang' => [
                        'type' => 'ibexa_string',
                        'label' => 'Language',
                        'description' => 'Language of the element content (lang attribute, e.g. "en", "de").',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 35, 'minStringLength' => null]],
                    ],
                    'form_builder_dir' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Text Direction',
                        'description' => 'Specifies the text direction (dir attribute).',
                        'settings' => ['options' => ['ltr', 'rtl', 'auto'], 'isMultiple' => false],
                    ],
                    'form_builder_tabindex' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Tab Index',
                        'description' => 'Specifies the tab order of the element (tabindex attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_accesskey' => [
                        'type' => 'ibexa_string',
                        'label' => 'Access Key',
                        'description' => 'Keyboard shortcut to activate/focus the element (accesskey attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 10, 'minStringLength' => null]],
                    ],
                    'form_builder_hidden' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Hidden',
                        'description' => 'When enabled, the element is not rendered (hidden attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_draggable' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Draggable',
                        'description' => 'Specifies whether the element is draggable (draggable attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_translate' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Translate',
                        'description' => 'Specifies whether the element content should be translated (translate attribute).',
                        'settings' => ['options' => ['yes', 'no'], 'isMultiple' => false],
                    ],
                    'form_builder_slot' => [
                        'type' => 'ibexa_string',
                        'label' => 'Slot',
                        'description' => 'Assigns the element to a named slot in a shadow DOM (slot attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_autocapitalize' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocapitalize',
                        'description' => 'Controls whether text input is automatically capitalised (autocapitalize attribute).',
                        'settings' => ['options' => ['off', 'none', 'on', 'sentences', 'words', 'characters'], 'isMultiple' => false],
                    ],
                    'form_builder_autofocus' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Autofocus',
                        'description' => 'When enabled, the element automatically receives focus on page load (autofocus attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_contenteditable' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Content Editable',
                        'description' => 'Specifies whether the element content is editable (contenteditable attribute).',
                        'settings' => ['options' => ['true', 'false', 'inherit', 'plaintext-only'], 'isMultiple' => false],
                    ],
                    'form_builder_inputmode' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Input Mode',
                        'description' => 'Hints at the type of virtual keyboard to display (inputmode attribute).',
                        'settings' => ['options' => ['none', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url'], 'isMultiple' => false],
                    ],
                    'form_builder_spellcheck' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Spell Check',
                        'description' => 'Specifies whether spell checking is enabled (spellcheck attribute).',
                        'settings' => ['options' => ['true', 'false', 'default'], 'isMultiple' => false],
                    ],
                    'form_builder_popover' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Popover',
                        'description' => 'Turns the element into a popover element (popover attribute). Part of the Popover API.',
                        'settings' => ['options' => ['auto', 'manual', 'hint'], 'isMultiple' => false],
                    ],
                    'form_builder_data_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Data Attributes',
                        'description' => 'Custom data-* attributes. The "data-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'name', 'name' => 'Name'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                    'form_builder_alpine_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Alpine.js Attributes',
                        'description' => 'Alpine.js x-* directives. The "x-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'directive', 'name' => 'Directive'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
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
                'description' => 'The button element represents a clickable button, used to submit forms or anywhere in a document for accessible, standard button functionality.',
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
                        'description' => 'The visible text content of the button.',
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
                        'description' => 'Specifies the type of the button (type attribute).',
                        'settings' => ['options' => ['submit', 'reset', 'button'], 'isMultiple' => false],
                    ],
                    'form_builder_value' => [
                        'type' => 'ibexa_string',
                        'label' => 'Value',
                        'description' => 'The value associated with the button, submitted with form data (value attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_autofocus' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Autofocus',
                        'description' => 'When enabled, the button automatically receives focus on page load (autofocus attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_autocorrect' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocorrect',
                        'description' => 'Controls whether autocorrection of editable text is enabled (autocorrect attribute).',
                        'settings' => ['options' => ['on', 'off'], 'isMultiple' => false],
                    ],
                    'form_builder_disabled' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Disabled',
                        'description' => 'When present, the button is disabled and cannot be activated.',
                        'searchable' => false,
                    ],
                    'form_builder_form_owner' => [
                        'type' => 'ibexa_string',
                        'label' => 'Form Owner (ID)',
                        'description' => 'Associates this button with a form element by its ID, allowing placement outside the form (form attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_formaction' => [
                        'type' => 'ibexa_string',
                        'label' => 'Form Action Override (URL)',
                        'description' => 'URL that processes the form submission. Overrides the form action attribute. Only applies to submit buttons.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 2048, 'minStringLength' => null]],
                    ],
                    'form_builder_formenctype' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Form Encoding Type Override',
                        'description' => 'Overrides the form enctype for submission. Only applies to submit buttons.',
                        'settings' => ['options' => ['application/x-www-form-urlencoded', 'multipart/form-data', 'text/plain'], 'isMultiple' => false],
                    ],
                    'form_builder_formmethod' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Form Method Override',
                        'description' => 'Overrides the form HTTP method. Only applies to submit buttons.',
                        'settings' => ['options' => ['get', 'post', 'dialog'], 'isMultiple' => false],
                    ],
                    'form_builder_formnovalidate' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Form No Validate',
                        'description' => 'Skips form validation on submit. Only applies to submit buttons.',
                        'searchable' => false,
                    ],
                    'form_builder_formtarget' => [
                        'type' => 'ibexa_string',
                        'label' => 'Form Target Override',
                        'description' => 'Overrides the form target (_self, _blank, _parent, _top, or a frame name). Only applies to submit buttons.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_popovertarget' => [
                        'type' => 'ibexa_string',
                        'label' => 'Popover Target (ID)',
                        'description' => 'ID of the popover element this button controls (popovertarget attribute). Part of the Popover API.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_popovertargetaction' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Popover Target Action',
                        'description' => 'Action to perform on the controlled popover: show, hide, or toggle (default) (popovertargetaction attribute).',
                        'settings' => ['options' => ['toggle', 'show', 'hide'], 'isMultiple' => false],
                    ],
                    'form_builder_command' => [
                        'type' => 'ibexa_string',
                        'label' => 'Command',
                        'description' => 'Action to perform on the element controlled by commandfor. Supports dialog operations (show-modal, close, request-close), popover operations (show-popover, hide-popover, toggle-popover), and custom commands prefixed with "--" (command attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_commandfor' => [
                        'type' => 'ibexa_string',
                        'label' => 'Command For (ID)',
                        'description' => 'ID of the element this button controls declaratively. Works with the command attribute. A more general version of popovertarget (commandfor attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_order' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Order',
                        'searchable' => false,
                    ],
                    'form_builder_role' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Role',
                        'description' => 'Defines the semantic purpose of the element for assistive technologies (role attribute).',
                        'settings' => ['options' => ['alert', 'application', 'article', 'banner', 'button', 'checkbox', 'complementary', 'contentinfo', 'dialog', 'form', 'grid', 'group', 'heading', 'img', 'link', 'list', 'listbox', 'listitem', 'main', 'menu', 'menubar', 'menuitem', 'navigation', 'none', 'presentation', 'radio', 'region', 'search', 'status', 'tab', 'tablist', 'tabpanel', 'textbox', 'toolbar', 'tooltip'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_label' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Label',
                        'description' => 'Defines a string value that labels the current element for assistive technologies (aria-label).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_labelledby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Labelled By',
                        'description' => 'Identifies the element(s) that label the current element. Space-separated list of IDs (aria-labelledby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_describedby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Described By',
                        'description' => 'Identifies the element(s) that describe the current element. Space-separated list of IDs (aria-describedby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_controls' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Controls',
                        'description' => 'Identifies the element(s) whose contents are controlled by this element. Space-separated list of IDs (aria-controls).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_current' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Current',
                        'description' => 'Indicates the current item within a container or set of related elements (aria-current).',
                        'settings' => ['options' => ['false', 'page', 'step', 'location', 'date', 'time', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_busy' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Busy',
                        'description' => 'Indicates whether the element is currently being updated (aria-busy).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_disabled' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Disabled',
                        'description' => 'Indicates that the element is perceivable but disabled (aria-disabled).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_expanded' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Expanded',
                        'description' => 'Indicates whether a collapsible UI element is expanded or collapsed (aria-expanded).',
                        'settings' => ['options' => ['false', 'true', 'undefined'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_haspopup' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Has Popup',
                        'description' => 'Indicates that the element has an associated popup (aria-haspopup).',
                        'settings' => ['options' => ['false', 'true', 'menu', 'listbox', 'tree', 'grid', 'dialog'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_pressed' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Pressed',
                        'description' => 'Indicates whether a toggle button is pressed (aria-pressed).',
                        'settings' => ['options' => ['false', 'true', 'mixed', 'undefined'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_checked' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Checked',
                        'description' => 'Defines the checked state for checkboxes, radio buttons, or toggle switches (aria-checked).',
                        'settings' => ['options' => ['false', 'true', 'mixed', 'undefined'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_live' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Live',
                        'description' => 'Defines how updates to the element should be announced to screen readers (aria-live).',
                        'settings' => ['options' => ['off', 'polite', 'assertive'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_atomic' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Atomic',
                        'description' => 'Indicates whether assistive technologies should present the entire region as a whole (aria-atomic).',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_relevant' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Relevant',
                        'description' => 'Indicates what content changes should be announced in a live region (aria-relevant).',
                        'settings' => ['options' => ['additions', 'removals', 'text', 'all', 'additions text'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_details' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Details',
                        'description' => 'References an element ID that provides additional details (aria-details).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_keyshortcuts' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Key Shortcuts',
                        'description' => 'Defines keyboard shortcuts available for the element (aria-keyshortcuts).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_roledescription' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Role Description',
                        'description' => 'Provides a human-readable custom role description for assistive technologies (aria-roledescription).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_id' => [
                        'type' => 'ibexa_string',
                        'label' => 'ID',
                        'description' => 'Unique HTML id attribute for the element.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_class' => [
                        'type' => 'ibexa_string',
                        'label' => 'CSS Class(es)',
                        'description' => 'Space-separated CSS class names (class attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 512, 'minStringLength' => null]],
                    ],
                    'form_builder_style' => [
                        'type' => 'ibexa_string',
                        'label' => 'Inline Style',
                        'description' => 'Inline CSS style (style attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_title' => [
                        'type' => 'ibexa_string',
                        'label' => 'Title',
                        'description' => 'Advisory information shown as a tooltip (title attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_lang' => [
                        'type' => 'ibexa_string',
                        'label' => 'Language',
                        'description' => 'Language of the element content (lang attribute, e.g. "en", "de").',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 35, 'minStringLength' => null]],
                    ],
                    'form_builder_dir' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Text Direction',
                        'description' => 'Specifies the text direction (dir attribute).',
                        'settings' => ['options' => ['ltr', 'rtl', 'auto'], 'isMultiple' => false],
                    ],
                    'form_builder_tabindex' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Tab Index',
                        'description' => 'Specifies the tab order of the element (tabindex attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_accesskey' => [
                        'type' => 'ibexa_string',
                        'label' => 'Access Key',
                        'description' => 'Keyboard shortcut to activate/focus the element (accesskey attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 10, 'minStringLength' => null]],
                    ],
                    'form_builder_hidden' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Hidden',
                        'description' => 'When enabled, the element is not rendered (hidden attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_draggable' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Draggable',
                        'description' => 'Specifies whether the element is draggable (draggable attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_translate' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Translate',
                        'description' => 'Specifies whether the element content should be translated (translate attribute).',
                        'settings' => ['options' => ['yes', 'no'], 'isMultiple' => false],
                    ],
                    'form_builder_slot' => [
                        'type' => 'ibexa_string',
                        'label' => 'Slot',
                        'description' => 'Assigns the element to a named slot in a shadow DOM (slot attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_autocapitalize' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Autocapitalize',
                        'description' => 'Controls whether text input is automatically capitalised (autocapitalize attribute).',
                        'settings' => ['options' => ['off', 'none', 'on', 'sentences', 'words', 'characters'], 'isMultiple' => false],
                    ],
                    'form_builder_contenteditable' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Content Editable',
                        'description' => 'Specifies whether the element content is editable (contenteditable attribute).',
                        'settings' => ['options' => ['true', 'false', 'inherit', 'plaintext-only'], 'isMultiple' => false],
                    ],
                    'form_builder_inputmode' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Input Mode',
                        'description' => 'Hints at the type of virtual keyboard to display (inputmode attribute).',
                        'settings' => ['options' => ['none', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url'], 'isMultiple' => false],
                    ],
                    'form_builder_spellcheck' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Spell Check',
                        'description' => 'Specifies whether spell checking is enabled (spellcheck attribute).',
                        'settings' => ['options' => ['true', 'false', 'default'], 'isMultiple' => false],
                    ],
                    'form_builder_data_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Data Attributes',
                        'description' => 'Custom data-* attributes. The "data-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'name', 'name' => 'Name'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                    'form_builder_alpine_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Alpine.js Attributes',
                        'description' => 'Alpine.js x-* directives. The "x-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'directive', 'name' => 'Directive'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                ],
            ],

            'datalist' => [
                'name' => 'Datalist',
                'description' => 'The datalist element contains a set of option elements that represent the permissible or suggested options available to other controls.',
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
                    'form_builder_order' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Order',
                        'searchable' => false,
                    ],
                    'form_builder_id' => [
                        'type' => 'ibexa_string',
                        'label' => 'ID',
                        'description' => 'Unique HTML id attribute. This is the value referenced by an input\'s list attribute.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_class' => [
                        'type' => 'ibexa_string',
                        'label' => 'CSS Class(es)',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 512, 'minStringLength' => null]],
                    ],
                    'form_builder_style' => [
                        'type' => 'ibexa_string',
                        'label' => 'Inline Style',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_hidden' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Hidden',
                        'description' => 'When enabled, the element is not rendered (hidden attribute).',
                        'searchable' => false,
                    ],
                    'form_builder_data_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Data Attributes',
                        'description' => 'Custom data-* attributes. The "data-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'name', 'name' => 'Name'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                    'form_builder_alpine_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Alpine.js Attributes',
                        'description' => 'Alpine.js x-* directives. The "x-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'directive', 'name' => 'Directive'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                ],
            ],

            'output' => [
                'name' => 'Output',
                'description' => 'The output element is a container element into which a site or app can inject the results of a calculation or the outcome of a user action.',
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
                    'form_builder_default_value' => [
                        'type' => 'ibexa_string',
                        'label' => 'Default Value',
                        'description' => 'The initial text content shown before any calculation result is available.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_for' => [
                        'type' => 'ibexa_string',
                        'label' => 'For (IDs)',
                        'description' => 'Space-separated list of IDs of elements whose values contributed to the calculation (for attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 512, 'minStringLength' => null]],
                    ],
                    'form_builder_form_owner' => [
                        'type' => 'ibexa_string',
                        'label' => 'Form Owner (ID)',
                        'description' => 'Associates this output with a form element by its ID (form attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_order' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Order',
                        'searchable' => false,
                    ],
                    'form_builder_role' => [
                        'type' => 'ibexa_selection',
                        'label' => 'Role',
                        'description' => 'Defines the semantic purpose of the element for assistive technologies (role attribute).',
                        'settings' => ['options' => ['alert', 'application', 'article', 'banner', 'button', 'checkbox', 'complementary', 'contentinfo', 'dialog', 'form', 'grid', 'group', 'heading', 'img', 'link', 'list', 'listbox', 'listitem', 'main', 'menu', 'menubar', 'menuitem', 'navigation', 'none', 'presentation', 'radio', 'region', 'search', 'status', 'tab', 'tablist', 'tabpanel', 'textbox', 'toolbar', 'tooltip'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_label' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Label',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_labelledby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Labelled By',
                        'description' => 'Space-separated list of IDs (aria-labelledby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_describedby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Described By',
                        'description' => 'Space-separated list of IDs (aria-describedby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_live' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Live',
                        'description' => 'Defines how updates should be announced to screen readers (aria-live). Defaults to "polite" for output elements.',
                        'settings' => ['options' => ['off', 'polite', 'assertive'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_atomic' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Atomic',
                        'settings' => ['options' => ['false', 'true'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_relevant' => [
                        'type' => 'ibexa_selection',
                        'label' => 'ARIA Relevant',
                        'settings' => ['options' => ['additions', 'removals', 'text', 'all', 'additions text'], 'isMultiple' => false],
                    ],
                    'form_builder_aria_details' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Details',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_id' => [
                        'type' => 'ibexa_string',
                        'label' => 'ID',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_class' => [
                        'type' => 'ibexa_string',
                        'label' => 'CSS Class(es)',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 512, 'minStringLength' => null]],
                    ],
                    'form_builder_style' => [
                        'type' => 'ibexa_string',
                        'label' => 'Inline Style',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_hidden' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Hidden',
                        'searchable' => false,
                    ],
                    'form_builder_data_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Data Attributes',
                        'description' => 'Custom data-* attributes. The "data-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'name', 'name' => 'Name'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                    'form_builder_alpine_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Alpine.js Attributes',
                        'description' => 'Alpine.js x-* directives. The "x-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'directive', 'name' => 'Directive'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                ],
            ],

            'progress' => [
                'name' => 'Progress',
                'description' => 'The progress element represents the completion progress of a task, typically displayed as a progress bar.',
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
                        'label' => 'Label / Fallback Text',
                        'description' => 'Accessible label and fallback text for browsers that do not support the progress element.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_value' => [
                        'type' => 'ibexa_string',
                        'label' => 'Value',
                        'description' => 'Current progress value (value attribute). Omit to show an indeterminate bar.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_max' => [
                        'type' => 'ibexa_string',
                        'label' => 'Max',
                        'description' => 'The total work required (max attribute). Defaults to 1.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_order' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Order',
                        'searchable' => false,
                    ],
                    'form_builder_aria_label' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Label',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_labelledby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Labelled By',
                        'description' => 'Space-separated list of IDs (aria-labelledby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_describedby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Described By',
                        'description' => 'Space-separated list of IDs (aria-describedby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_valuemin' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Value Min',
                        'description' => 'Minimum value for the progress range (aria-valuemin).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_valuemax' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Value Max',
                        'description' => 'Maximum value for the progress range (aria-valuemax).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_valuenow' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Value Now',
                        'description' => 'Current value for the progress range (aria-valuenow).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_valuetext' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Value Text',
                        'description' => 'Human-readable representation of the current value (aria-valuetext).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_id' => [
                        'type' => 'ibexa_string',
                        'label' => 'ID',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_class' => [
                        'type' => 'ibexa_string',
                        'label' => 'CSS Class(es)',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 512, 'minStringLength' => null]],
                    ],
                    'form_builder_style' => [
                        'type' => 'ibexa_string',
                        'label' => 'Inline Style',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_hidden' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Hidden',
                        'searchable' => false,
                    ],
                    'form_builder_data_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Data Attributes',
                        'description' => 'Custom data-* attributes. The "data-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'name', 'name' => 'Name'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                    'form_builder_alpine_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Alpine.js Attributes',
                        'description' => 'Alpine.js x-* directives. The "x-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'directive', 'name' => 'Directive'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                ],
            ],

            'meter' => [
                'name' => 'Meter',
                'description' => 'The meter element represents a scalar measurement within a known range, or a fractional value such as disk usage or relevance of a query result.',
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
                        'label' => 'Label / Fallback Text',
                        'description' => 'Accessible label and fallback text for browsers that do not support the meter element.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_value' => [
                        'type' => 'ibexa_string',
                        'label' => 'Value',
                        'description' => 'The current numeric value (value attribute). Must be between min and max.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_min' => [
                        'type' => 'ibexa_string',
                        'label' => 'Min',
                        'description' => 'The lower bound of the range (min attribute). Defaults to 0.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_max' => [
                        'type' => 'ibexa_string',
                        'label' => 'Max',
                        'description' => 'The upper bound of the range (max attribute). Defaults to 1.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_low' => [
                        'type' => 'ibexa_string',
                        'label' => 'Low',
                        'description' => 'The upper bound of the low range (low attribute). Must be between min and high.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_high' => [
                        'type' => 'ibexa_string',
                        'label' => 'High',
                        'description' => 'The lower bound of the high range (high attribute). Must be between low and max.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_optimum' => [
                        'type' => 'ibexa_string',
                        'label' => 'Optimum',
                        'description' => 'The optimal numeric value (optimum attribute). Hints which end of the range is preferable.',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_form_owner' => [
                        'type' => 'ibexa_string',
                        'label' => 'Form Owner (ID)',
                        'description' => 'Associates this meter with a form element by its ID (form attribute).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_order' => [
                        'type' => 'ibexa_integer',
                        'label' => 'Order',
                        'searchable' => false,
                    ],
                    'form_builder_aria_label' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Label',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_labelledby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Labelled By',
                        'description' => 'Space-separated list of IDs (aria-labelledby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_describedby' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Described By',
                        'description' => 'Space-separated list of IDs (aria-describedby).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_valuemin' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Value Min',
                        'description' => 'Minimum value for assistive technologies (aria-valuemin).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_valuemax' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Value Max',
                        'description' => 'Maximum value for assistive technologies (aria-valuemax).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_valuenow' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Value Now',
                        'description' => 'Current value for assistive technologies (aria-valuenow).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 50, 'minStringLength' => null]],
                    ],
                    'form_builder_aria_valuetext' => [
                        'type' => 'ibexa_string',
                        'label' => 'ARIA Value Text',
                        'description' => 'Human-readable representation of the current value (aria-valuetext).',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_id' => [
                        'type' => 'ibexa_string',
                        'label' => 'ID',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 255, 'minStringLength' => null]],
                    ],
                    'form_builder_class' => [
                        'type' => 'ibexa_string',
                        'label' => 'CSS Class(es)',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 512, 'minStringLength' => null]],
                    ],
                    'form_builder_style' => [
                        'type' => 'ibexa_string',
                        'label' => 'Inline Style',
                        'validators' => ['StringLengthValidator' => ['maxStringLength' => 1024, 'minStringLength' => null]],
                    ],
                    'form_builder_hidden' => [
                        'type' => 'ibexa_boolean',
                        'label' => 'Hidden',
                        'searchable' => false,
                    ],
                    'form_builder_data_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Data Attributes',
                        'description' => 'Custom data-* attributes. The "data-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'name', 'name' => 'Name'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                    'form_builder_alpine_attributes' => [
                        'type' => 'ibexa_matrix',
                        'label' => 'Alpine.js Attributes',
                        'description' => 'Alpine.js x-* directives. The "x-" prefix is added automatically.',
                        'searchable' => false,
                        'settings' => [
                            'columns' => [
                                ['identifier' => 'directive', 'name' => 'Directive'],
                                ['identifier' => 'value', 'name' => 'Value'],
                            ],
                            'minimum_rows' => 0,
                        ],
                    ],
                ],
            ],
        ];
    }
}
