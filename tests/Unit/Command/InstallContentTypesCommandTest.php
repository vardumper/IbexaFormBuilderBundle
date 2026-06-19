<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\ContentTypeService;
use Ibexa\Contracts\Core\Repository\Repository;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentType;
use Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroup;
use Ibexa\Core\Base\Exceptions\NotFoundException as ConcreteNotFoundException;
use Symfony\Component\Console\Tester\CommandTester;
use vardumper\IbexaFormBuilderBundle\Command\InstallContentTypesCommand;

it('has the correct console command name', function (): void {
    $command = new InstallContentTypesCommand(
        testMock(ContentTypeService::class),
        testMock(Repository::class),
    );

    expect($command->getName())->toBe('ibexa:form-builder:install-content-types');
});

it('patches all content types that already exist when overwrite is not requested', function (): void {
    $contentTypeService = testMock(ContentTypeService::class);
    $repository = testMock(Repository::class);

    $contentTypeService
        ->method('loadContentTypeGroupByIdentifier')
        ->willReturn(testMock(ContentTypeGroup::class));

    $contentTypeService
        ->method('loadContentTypeByIdentifier')
        ->willReturn(testMock(ContentType::class));

    $repository->method('sudo')->willReturnCallback(function (callable $callback) {
        return $callback();
    });

    $command = new InstallContentTypesCommand($contentTypeService, $repository);
    $tester = new CommandTester($command);
    $exit = $tester->execute([]);

    expect($exit)->toBe(0)
        ->and($tester->getDisplay())->toContain('Patched content type')
        ->and($tester->getDisplay())->toContain('All form builder content types have been installed.');
});

it('creates all content types when none exist', function (): void {
    $contentTypeService = testMock(ContentTypeService::class);
    $repository = testMock(Repository::class);

    $contentTypeService
        ->method('loadContentTypeGroupByIdentifier')
        ->willReturn(testMock(ContentTypeGroup::class));

    $contentTypeService
        ->method('loadContentTypeByIdentifier')
        ->willThrowException(new ConcreteNotFoundException('content type', ['identifier' => 'form_builder_form']));

    $repository->method('sudo')->willReturnCallback(function (callable $callback) {
        return $callback();
    });

    $command = new InstallContentTypesCommand($contentTypeService, $repository);
    $tester = new CommandTester($command);
    $exit = $tester->execute([]);

    expect($exit)->toBe(0)
        ->and($tester->getDisplay())->toContain('All form builder content types have been installed.');
});

it('creates a new content type group when the group does not exist', function (): void {
    $contentTypeService = testMock(ContentTypeService::class);
    $repository = testMock(Repository::class);

    $contentTypeService
        ->method('loadContentTypeGroupByIdentifier')
        ->willThrowException(new ConcreteNotFoundException('content type group', ['identifier' => 'Form Builder']));

    $contentTypeService
        ->method('newContentTypeGroupCreateStruct')
        ->willReturn(testMock(\Ibexa\Contracts\Core\Repository\Values\ContentType\ContentTypeGroupCreateStruct::class));

    $contentTypeService
        ->method('createContentTypeGroup')
        ->willReturn(testMock(ContentTypeGroup::class));

    $contentTypeService
        ->method('loadContentTypeByIdentifier')
        ->willReturn(testMock(ContentType::class));

    $repository->method('sudo')->willReturnCallback(function (callable $callback) {
        return $callback();
    });

    $command = new InstallContentTypesCommand($contentTypeService, $repository);
    $tester = new CommandTester($command);
    $exit = $tester->execute([]);

    expect($exit)->toBe(0)
        ->and($tester->getDisplay())->toContain('Content type group')
        ->and($tester->getDisplay())->toContain('not found — creating');
});
