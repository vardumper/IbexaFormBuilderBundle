<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\ContentService;
use Ibexa\Contracts\Core\Repository\LocationService;
use Ibexa\Contracts\Core\Repository\PermissionResolver;
use Ibexa\Contracts\Core\Repository\SearchService;
use Ibexa\Contracts\Core\Repository\UserService;
use Ibexa\Contracts\Core\Repository\Values\Content\ContentInfo;
use Ibexa\Contracts\Core\Repository\Values\Content\LocationUpdateStruct;
use Ibexa\Contracts\Core\Repository\Values\Content\Search\SearchHit;
use Ibexa\Contracts\Core\Repository\Values\Content\Search\SearchResult;
use Ibexa\Contracts\Core\Repository\Values\User\User;
use Ibexa\Core\FieldType\Integer\Value as IntegerValue;
use Ibexa\Core\Repository\Values\Content\Location as ConcreteLocation;
use Symfony\Component\Console\Tester\CommandTester;
use vardumper\IbexaFormBuilderBundle\Command\SyncOrderCommand;

function makeSyncCommand(
    ?SearchService $search = null,
    ?UserService $users = null,
    ?PermissionResolver $permissions = null,
): SyncOrderCommand {
    $emptyResult = new SearchResult(['searchHits' => [], 'totalCount' => 0]);

    $search ??= testMock(SearchService::class);
    $search->method('findLocations')->willReturn($emptyResult);

    $user = testMock(User::class);

    $users ??= testMock(UserService::class);
    $users->method('loadUserByLogin')->willReturn($user);

    $permissions ??= testMock(PermissionResolver::class);

    return new SyncOrderCommand(
        $search,
        testMock(LocationService::class),
        testMock(ContentService::class),
        $permissions,
        $users,
    );
}

it('has the correct console command name', function (): void {
    expect(makeSyncCommand()->getName())->toBe('ibexa:form-builder:sync-order');
});

it('exits with success when no locations are found', function (): void {
    $tester = new CommandTester(makeSyncCommand());
    $exit = $tester->execute([]);

    expect($exit)->toBe(0)
        ->and($tester->getDisplay())->toContain('Done.');
});

it('sets the current user reference using the admin login option', function (): void {
    $user = testMock(User::class);
    $userService = testMock(UserService::class);
    $userService->method('loadUserByLogin')
        ->with('custom-admin')
        ->willReturn($user);

    $permissionResolver = testMock(PermissionResolver::class);

    $userReferenceSet = false;
    $permissionResolver->method('setCurrentUserReference')->willReturnCallback(function () use (&$userReferenceSet): void {
        $userReferenceSet = true;
    });

    $tester = new CommandTester(makeSyncCommand(users: $userService, permissions: $permissionResolver));
    $exit = $tester->execute(['--admin-login' => 'custom-admin']);

    expect($exit)->toBe(0)
        ->and($userReferenceSet)->toBeTrue();
});

it('queries for container and orderable locations separately', function (): void {
    $callCount = 0;
    $emptyResult = new SearchResult(['searchHits' => [], 'totalCount' => 0]);

    $searchService = testMock(SearchService::class);
    $searchService->method('findLocations')->willReturnCallback(function () use (&$callCount, $emptyResult): SearchResult {
        $callCount++;

        return $emptyResult;
    });

    $tester = new CommandTester(makeSyncCommand(search: $searchService));
    $tester->execute([]);

    expect($callCount)->toBeGreaterThanOrEqual(2);
});

it('syncContainerSortFields updates location sort field for matching content types', function (): void {
    $contentInfo = new ContentInfo(['id' => 1]);
    $location = new ConcreteLocation(['id' => 1, 'contentInfo' => $contentInfo]);
    $hit = new SearchHit(['valueObject' => $location]);

    $containerResult = new SearchResult(['searchHits' => [$hit], 'totalCount' => 1]);
    $emptyResult = new SearchResult(['searchHits' => [], 'totalCount' => 0]);

    $callCount = 0;
    $searchService = testMock(SearchService::class);
    $searchService->method('findLocations')->willReturnCallback(
        static function () use (&$callCount, $containerResult, $emptyResult): SearchResult {
            return ++$callCount === 1 ? $containerResult : $emptyResult;
        },
    );

    $updateStruct = new LocationUpdateStruct();
    $locationService = testMock(LocationService::class);
    $locationService->method('newLocationUpdateStruct')->willReturn($updateStruct);

    $updateCalled = false;
    $locationService->method('updateLocation')->willReturnCallback(
        static function (mixed $location) use (&$updateCalled): \Ibexa\Contracts\Core\Repository\Values\Content\Location {
            $updateCalled = true;

            return $location;
        },
    );

    $tester = new CommandTester(new SyncOrderCommand(
        $searchService,
        $locationService,
        testMock(ContentService::class),
        testMock(PermissionResolver::class),
        (function (): UserService {
            $user = testMock(User::class);
            $userService = testMock(UserService::class);
            $userService->method('loadUserByLogin')->willReturn($user);

            return $userService;
        })(),
    ));
    $exit = $tester->execute([]);

    expect($exit)->toBe(0)
        ->and($updateCalled)->toBeTrue();
});

it('syncOrderPriorities updates location priority from IntegerValue order field', function (): void {
    $contentInfo = new ContentInfo(['id' => 2]);
    $location = new ConcreteLocation(['id' => 2, 'contentInfo' => $contentInfo]);
    $hit = new SearchHit(['valueObject' => $location]);

    $orderableResult = new SearchResult(['searchHits' => [$hit], 'totalCount' => 1]);
    $emptyResult = new SearchResult(['searchHits' => [], 'totalCount' => 0]);

    $callCount = 0;
    $searchService = testMock(SearchService::class);
    $searchService->method('findLocations')->willReturnCallback(
        static function () use (&$callCount, $emptyResult, $orderableResult): SearchResult {
            return ++$callCount === 2 ? $orderableResult : $emptyResult;
        },
    );

    $content = testMock(\Ibexa\Contracts\Core\Repository\Values\Content\Content::class);
    $content->method('getFieldValue')->willReturn(new IntegerValue(3));

    $contentService = testMock(ContentService::class);
    $contentService->method('loadContentByContentInfo')->willReturn($content);

    $updateStruct = new LocationUpdateStruct();
    $locationService = testMock(LocationService::class);
    $locationService->method('newLocationUpdateStruct')->willReturn($updateStruct);

    $updateCalled = false;
    $locationService->method('updateLocation')->willReturnCallback(
        static function (mixed $location) use (&$updateCalled): \Ibexa\Contracts\Core\Repository\Values\Content\Location {
            $updateCalled = true;

            return $location;
        },
    );

    $tester = new CommandTester(new SyncOrderCommand(
        $searchService,
        $locationService,
        $contentService,
        testMock(PermissionResolver::class),
        (function (): UserService {
            $user = testMock(User::class);
            $userService = testMock(UserService::class);
            $userService->method('loadUserByLogin')->willReturn($user);

            return $userService;
        })(),
    ));
    $exit = $tester->execute([]);

    expect($exit)->toBe(0)
        ->and($updateCalled)->toBeTrue()
        ->and($updateStruct->priority)->toBe(3);
});
