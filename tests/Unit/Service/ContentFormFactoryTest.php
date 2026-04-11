<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\ContentTypeService;
use Ibexa\Contracts\Core\Repository\LocationService;
use Ibexa\Core\Repository\ContentService;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use vardumper\IbexaFormBuilderBundle\Service\ConstraintResolver;
use vardumper\IbexaFormBuilderBundle\Service\ContentFormFactory;

function makeContentFormFactory(
    ?FormFactoryInterface $formFactory = null,
    ?TagAwareCacheInterface $cache = null,
): ContentFormFactory {
    $formFactory ??= testMock(FormFactoryInterface::class);
    $cache ??= testMock(TagAwareCacheInterface::class);

    return new ContentFormFactory(
        testMock(ContentService::class),
        testMock(LocationService::class),
        testMock(ContentTypeService::class),
        $formFactory,
        $cache,
        new ConstraintResolver(),
    );
}

function makeFormBuilderMock(): FormBuilderInterface
{
    $form = testMock(FormInterface::class);
    $builder = testMock(FormBuilderInterface::class);
    $builder->method('add')->willReturnSelf();
    $builder->method('create')->willReturnSelf();
    $builder->method('getForm')->willReturn($form);

    return $builder;
}

it('createForm returns a FormInterface for an empty structure', function (): void {
    $builder = makeFormBuilderMock();

    $formFactory = testMock(FormFactoryInterface::class);
    $formFactory->method('createBuilder')->willReturn($builder);

    $factory = makeContentFormFactory(formFactory: $formFactory);

    $result = $factory->createForm([
        'formName' => 'my-form',
        'method' => 'POST',
        'fields' => [],
    ], '/action');

    expect($result)->toBeInstanceOf(FormInterface::class);
});

it('createForm passes form name, method and action to the builder', function (): void {
    $builder = makeFormBuilderMock();
    $capturedOptions = [];

    $formFactory = testMock(FormFactoryInterface::class);
    $formFactory->method('createBuilder')->willReturnCallback(
        static function (mixed $type, mixed $data, array $options) use ($builder, &$capturedOptions): FormBuilderInterface {
            $capturedOptions = $options;

            return $builder;
        },
    );

    $factory = makeContentFormFactory(formFactory: $formFactory);
    $factory->createForm([
        'formName' => 'contact',
        'method' => 'GET',
        'fields' => [],
    ], '/submit');

    expect($capturedOptions['method'])->toBe('GET')
        ->and($capturedOptions['attr']['name'])->toBe('contact')
        ->and($capturedOptions['action'])->toBe('/submit');
});

it('createForm adds fields to the builder', function (): void {
    $addedFields = [];
    $builder = makeFormBuilderMock();
    $builder->method('add')->willReturnCallback(
        static function (mixed $name) use ($builder, &$addedFields): FormBuilderInterface {
            $addedFields[] = $name;

            return $builder;
        },
    );

    $formFactory = testMock(FormFactoryInterface::class);
    $formFactory->method('createBuilder')->willReturn($builder);

    $factory = makeContentFormFactory(formFactory: $formFactory);
    $factory->createForm([
        'formName' => 'test',
        'method' => 'POST',
        'fields' => [
            [
                'identifier' => 'email_field',
                'typeClass' => \Symfony\Component\Form\Extension\Core\Type\TextType::class,
                'options' => ['label' => 'Email', 'attr' => [], 'row_attr' => []],
            ],
            [
                'identifier' => 'message_field',
                'typeClass' => \Symfony\Component\Form\Extension\Core\Type\TextareaType::class,
                'options' => ['label' => 'Message', 'attr' => [], 'row_attr' => []],
            ],
        ],
    ], '/action');

    expect($addedFields)->toContain('email_field')
        ->and($addedFields)->toContain('message_field');
});

it('createForm instantiates constraint defs into live objects', function (): void {
    $addedOptions = [];
    $builder = makeFormBuilderMock();
    $builder->method('add')->willReturnCallback(
        static function (mixed $name, mixed $type, array $options) use ($builder, &$addedOptions): FormBuilderInterface {
            $addedOptions = $options;

            return $builder;
        },
    );

    $formFactory = testMock(FormFactoryInterface::class);
    $formFactory->method('createBuilder')->willReturn($builder);

    $factory = makeContentFormFactory(formFactory: $formFactory);
    $factory->createForm([
        'formName' => 'test',
        'method' => 'POST',
        'fields' => [
            [
                'identifier' => 'name',
                'typeClass' => \Symfony\Component\Form\Extension\Core\Type\TextType::class,
                'options' => [
                    'attr' => [],
                    'row_attr' => [],
                    '_constraint_defs' => [
                        ['class' => \Symfony\Component\Validator\Constraints\NotBlank::class, 'options' => []],
                    ],
                ],
            ],
        ],
    ], '/action');

    expect($addedOptions)->toHaveKey('constraints')
        ->and($addedOptions['constraints'][0])->toBeInstanceOf(\Symfony\Component\Validator\Constraints\NotBlank::class);
});

it('createForm builds nested fieldset with children', function (): void {
    $fieldsetBuilder = makeFormBuilderMock();
    $rootBuilder = makeFormBuilderMock();
    $rootBuilder->method('create')->willReturn($fieldsetBuilder);

    $addedToRoot = [];
    $rootBuilder->method('add')->willReturnCallback(
        static function (mixed $arg) use ($rootBuilder, &$addedToRoot): FormBuilderInterface {
            $addedToRoot[] = is_string($arg) ? $arg : 'builder';

            return $rootBuilder;
        },
    );

    $formFactory = testMock(FormFactoryInterface::class);
    $formFactory->method('createBuilder')->willReturn($rootBuilder);

    $factory = makeContentFormFactory(formFactory: $formFactory);
    $factory->createForm([
        'formName' => 'test',
        'method' => 'POST',
        'fields' => [
            [
                'identifier' => 'group',
                'typeClass' => \Symfony\Component\Form\Extension\Core\Type\FormType::class,
                'options' => ['attr' => [], 'row_attr' => []],
                'children' => [
                    [
                        'identifier' => 'inner_field',
                        'typeClass' => \Symfony\Component\Form\Extension\Core\Type\TextType::class,
                        'options' => ['attr' => [], 'row_attr' => []],
                    ],
                ],
            ],
        ],
    ], '/action');

    expect($addedToRoot)->toContain('builder');
});
