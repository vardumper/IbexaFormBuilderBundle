<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Service;

use Ibexa\Contracts\Core\Repository\ContentTypeService;
use Ibexa\Contracts\Core\Repository\LocationService;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\Core\Repository\Values\Content\Field;
use Ibexa\Contracts\Core\Repository\Values\Content\Location;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentType;
use Ibexa\Contracts\Core\Repository\Values\ContentType\FieldDefinition;
use Ibexa\Core\FieldType\Selection\Value as SelectionValue;
use Ibexa\Core\Repository\ContentService;
use Symfony\Component\Form\Extension\Core\Type as FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class ContentFormFactory
{
    /** @var array<int, ContentType> */
    private array $contentTypeCache = [];

    public function __construct(
        private readonly ContentService $contentService,
        private readonly LocationService $locationService,
        private readonly ContentTypeService $contentTypeService,
        private readonly FormFactoryInterface $formFactory,
        private readonly TagAwareCacheInterface $cache,
        private readonly ConstraintResolver $constraintResolver,
    ) {
    }

    /** @return array<string, mixed> */
    public function getFormStructure(int $contentId): array
    {
        return $this->cache->get(
            "form_structure_{$contentId}",
            function (ItemInterface $item) use ($contentId) {
                $item->expiresAfter(3600);
                $item->tag(["form_{$contentId}", 'forms']);

                $content = $this->contentService->loadContent($contentId);
                $mainLocation = $this->locationService->loadLocation($content->contentInfo->mainLocationId);

                return [
                    'formName' => $content->getFieldValue('name') ? $content->getFieldValue('name')->__toString() ?: 'form' : 'form',
                    'method' => $content->getFieldValue('method') ? $content->getFieldValue('method')->__toString() : 'POST',
                    'modificationDate' => $content->contentInfo->modificationDate,
                    'fields' => $this->extractFieldsStructure($mainLocation),
                ];
            },
        );
    }

    /**
     * @param array<string, mixed> $structure
     *
     * @return FormInterface<mixed>
     */
    public function createForm(array $structure, string $action): FormInterface
    {
        /** @var FormBuilderInterface<mixed> $builder */
        $builder = $this->formFactory->createBuilder(FormType\FormType::class, null, [
            'attr' => ['name' => $structure['formName']],
            'action' => $action,
            'method' => $structure['method'],
        ]);

        $this->buildFormFromStructure($builder, $structure['fields']);

        return $builder->getForm();
    }

    /** @return list<array<string, mixed>> */
    private function extractFieldsStructure(Location $location): array
    {
        $fields = [];

        foreach ($this->getSortedChildLocations($location) as $childLocation) {
            $childContent = $childLocation->getContent();
            if ($this->isOptionContent($childContent)) {
                continue;
            }

            $typeClass = $this->determineTypeClass($childContent);
            $fieldData = [
                'identifier' => $this->getFieldIdentifier($childContent),
                'typeClass' => $typeClass,
                'options' => $this->buildFieldOptions($childContent, $typeClass),
            ];

            if ($typeClass === FormType\ChoiceType::class) {
                $fieldData['choices'] = $this->buildChoicesFromChildren($childLocation);
            }

            if ($typeClass === FormType\FormType::class) {
                $fieldData['children'] = $this->extractFieldsStructure($childLocation);
            }

            $fields[] = $fieldData;
        }

        return $fields;
    }

    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param list<array<string, mixed>> $fields
     */
    private function buildFormFromStructure(FormBuilderInterface $builder, array $fields): void
    {
        foreach ($fields as $fieldData) {
            $options = $fieldData['options'];

            // Instantiate serialisable constraint definitions into live objects
            $constraintDefs = $options['_constraint_defs'] ?? [];
            unset($options['_constraint_defs']);
            if (!empty($constraintDefs)) {
                $options['constraints'] = array_map(ConstraintResolver::instantiate(...), $constraintDefs);
            }

            if (isset($fieldData['choices'])) {
                $options['choices'] = $fieldData['choices'];
            }

            if ($fieldData['typeClass'] === FormType\FormType::class && isset($fieldData['children'])) {
                /** @var FormBuilderInterface<mixed> $fieldsetBuilder */
                $fieldsetBuilder = $builder->create($fieldData['identifier'], $fieldData['typeClass'], $options);
                $this->buildFormFromStructure($fieldsetBuilder, $fieldData['children']);
                $builder->add($fieldsetBuilder);

                continue;
            }

            $builder->add($fieldData['identifier'], $fieldData['typeClass'], $options);
        }
    }

    private function determineTypeClass(Content $content): string
    {
        $typeFieldValue = $content->getFieldValue('type');
        $selection = $typeFieldValue instanceof SelectionValue ? $typeFieldValue->selection : null;

        if ($selection === null) {
            $identifier = $content->contentType->identifier ?? null;

            return match ($identifier) {
                'form_builder_textarea' => FormType\TextareaType::class,
                'form_builder_fieldset', 'form_builder_horizontal_group' => FormType\FormType::class,
                'form_builder_select' => FormType\ChoiceType::class,
                'form_builder_input' => FormType\TextType::class,
                'form_builder_button' => FormType\SubmitType::class,
                default => throw new \InvalidArgumentException(sprintf('Form field type is not specified for content type "%s".', $identifier)),
            };
        }

        if (!isset($selection[0])) {
            throw new \InvalidArgumentException('Unknown error.');
        }

        $fieldDefinition = $this->loadContentType((int) $content->contentInfo->contentTypeId)->getFieldDefinition('type');
        $fieldSettings = $fieldDefinition->getFieldSettings();
        $field = $selection[0];

        if (!isset($fieldSettings['options'][$field])) {
            throw new \InvalidArgumentException('Form field type settings are not properly configured.');
        }

        $type = $fieldSettings['options'][$field];
        $typeClass = 'Symfony\\Component\\Form\\Extension\\Core\\Type\\' . ucfirst($type) . 'Type';

        if (!class_exists($typeClass)) {
            throw new \InvalidArgumentException(sprintf('Unsupported form field type "%s".', $type));
        }

        return $typeClass;
    }

    /** @return array<string, mixed> */
    private function buildFieldOptions(Content $content, string $typeClass): array
    {
        $options = ['attr' => [], 'row_attr' => []];
        $contentType = $this->loadContentType((int) $content->contentInfo->contentTypeId);

        $fieldValues = [];
        foreach ($content->getFields() as $field) {
            $fieldDefinition = $contentType->getFieldDefinition($field->fieldDefIdentifier);
            if ($fieldDefinition !== null) {
                $fieldValues[$field->fieldDefIdentifier] = $this->getFieldValue($field, $fieldDefinition);
            }
        }

        $hideLabel = $fieldValues['hide_label'] ?? $fieldValues['form_builder_hide_label'] ?? false;

        if (($content->contentType->identifier ?? null) === 'form_builder_horizontal_group') {
            $options['attr']['data-type'] = 'horizontal_group';
        }

        foreach ($fieldValues as $fieldIdentifier => $fieldValue) {
            // Strip form_builder_ prefix for matching
            $key = str_starts_with($fieldIdentifier, 'form_builder_') ? substr($fieldIdentifier, 13) : $fieldIdentifier;
            match ($key) {
                'label' => $options['label'] = (!empty($fieldValue) && !$hideLabel) ? $fieldValue : false,
                'helper_text' => !empty($fieldValue) && ($options['row_attr']['data-helper-text'] = $fieldValue),
                'label_wrap' => $fieldValue && ($options['row_attr']['data-label-wrap'] = true),
                'label_after' => $fieldValue && ($options['row_attr']['data-label-after'] = true),
                'placeholder' => !empty($fieldValue) && $options['attr']['placeholder'] = $fieldValue,
                'type' => !empty($fieldValue) && $options['attr']['type'] = $fieldValue,
                'value' => !empty($fieldValue) && ($options['data'] = $fieldValue),
                'required' => ($typeClass !== FormType\SubmitType::class && $typeClass !== FormType\ButtonType::class) && ($options['required'] = (bool) $fieldValue),
                'disabled' => $options['disabled'] = (bool) $fieldValue,
                'readonly' => $fieldValue && ($options['attr']['readonly'] = 'readonly'),
                'checked' => $fieldValue && ($options['attr']['checked'] = 'checked'),
                'expanded' => $typeClass === FormType\ChoiceType::class && $options['expanded'] = (bool) $fieldValue,
                'multiple' => $typeClass === FormType\ChoiceType::class && $options['multiple'] = (bool) $fieldValue,
                'role' => ($typeClass === FormType\FormType::class && !empty($fieldValue)) && $options['attr']['role'] = $fieldValue,
                'autocomplete' => !empty($fieldValue) && ($options['attr']['autocomplete'] = $fieldValue),
                'maxlength' => !empty($fieldValue) && ($options['attr']['maxlength'] = $fieldValue),
                'minlength' => !empty($fieldValue) && ($options['attr']['minlength'] = $fieldValue),
                'size' => !empty($fieldValue) && ($options['attr']['size'] = $fieldValue),
                'min' => !empty($fieldValue) && ($options['attr']['min'] = $fieldValue),
                'max' => !empty($fieldValue) && ($options['attr']['max'] = $fieldValue),
                'step' => !empty($fieldValue) && ($options['attr']['step'] = $fieldValue),
                'pattern' => !empty($fieldValue) && ($options['attr']['pattern'] = $fieldValue),
                'accept' => !empty($fieldValue) && ($options['attr']['accept'] = $fieldValue),
                'wrap' => !empty($fieldValue) && ($options['attr']['wrap'] = $fieldValue),
                'rows' => !empty($fieldValue) && ($options['attr']['rows'] = $fieldValue),
                'cols' => !empty($fieldValue) && ($options['attr']['cols'] = $fieldValue),
                default => null,
            };
        }

        // Resolve constraints (auto from HTML attrs + manual JSON config) and
        // store as definitions — they will be instantiated when building the form
        // to ensure the cached structure remains serialisable.
        $constraintDefs = $this->constraintResolver->resolve($fieldValues);
        if (!empty($constraintDefs)) {
            $options['_constraint_defs'] = $constraintDefs;
        }

        return $options;
    }

    /** @return array<string, string> */
    private function buildChoicesFromChildren(Location $selectLocation): array
    {
        $choices = [];

        foreach ($this->getSortedChildLocations($selectLocation) as $childLocation) {
            $childContent = $childLocation->getContent();
            if (!$this->isOptionContent($childContent)) {
                continue;
            }

            $label = trim((string) ($childContent->getFieldValue('label')?->__toString()
                ?? $childContent->getFieldValue('name')?->__toString()
                ?? $childContent->contentInfo->name));
            $label = $label !== '' ? $label : 'option_' . $childContent->id;

            $value = trim((string) ($childContent->getFieldValue('value')?->__toString() ?? $label));
            $value = $value !== '' ? $value : (string) $childContent->id;

            $choices[$label] = $value;
        }

        return $choices;
    }

    /** @return list<Location> */
    private function getSortedChildLocations(Location $parentLocation): array
    {
        $locations = $this->locationService->loadLocationChildren($parentLocation)->locations;

        usort($locations, static function (Location $left, Location $right): int {
            $leftContent = $left->getContent();
            $rightContent = $right->getContent();
            $leftOrder = (int) ($leftContent->getFieldValue('form_builder_order')?->__toString()
                ?? $leftContent->getFieldValue('order')?->__toString()
                ?? 0);
            $rightOrder = (int) ($rightContent->getFieldValue('form_builder_order')?->__toString()
                ?? $rightContent->getFieldValue('order')?->__toString()
                ?? 0);

            return $leftOrder <=> $rightOrder;
        });

        return $locations;
    }

    private function getFieldIdentifier(Content $content): string
    {
        $identifier = trim((string) ($content->getFieldValue('name')?->__toString() ?? $content->contentInfo->name));

        if ($identifier === '') {
            return 'field_' . $content->id;
        }

        // Symfony form names must start with a letter, digit or underscore and
        // only contain letters, digits, underscores, hyphens and colons.
        $slug = preg_replace('/[^a-zA-Z0-9_\-:]/', '_', $identifier) ?? '';
        $slug = preg_replace('/^[^a-zA-Z0-9_]+/', '', $slug) ?? '';
        $slug = preg_replace('/_+/', '_', $slug) ?? '';
        $slug = trim($slug, '_');

        return $slug !== '' ? $slug : 'field_' . $content->id;
    }

    private function isOptionContent(Content $content): bool
    {
        return ($content->contentType->identifier ?? null) === 'option';
    }

    private function loadContentType(int $contentTypeId): ContentType
    {
        if (!isset($this->contentTypeCache[$contentTypeId])) {
            $this->contentTypeCache[$contentTypeId] = $this->contentTypeService->loadContentType($contentTypeId);
        }

        return $this->contentTypeCache[$contentTypeId];
    }

    private function getFieldValue(Field $field, FieldDefinition $fieldDefinition): mixed
    {
        $fieldType = $fieldDefinition->fieldTypeIdentifier;

        if ($fieldType === 'ezselection') {
            $selection = $field->value->selection;
            if ($selection === null || !isset($selection[0])) {
                return null;
            }

            $fieldSettings = $fieldDefinition->getFieldSettings();

            return $fieldSettings['options'][$selection[0]] ?? null;
        }

        if ($fieldType === 'ezboolean') {
            return $field->value->bool ?? false;
        }

        return trim($field->value->__toString());
    }
}
