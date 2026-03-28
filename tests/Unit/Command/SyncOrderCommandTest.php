<?php

declare(strict_types=1);

use Ibexa\Contracts\Core\Repository\ContentService;
use Ibexa\Contracts\Core\Repository\LocationService;
use Ibexa\Contracts\Core\Repository\PermissionResolver;
use Ibexa\Contracts\Core\Repository\SearchService;
use Ibexa\Contracts\Core\Repository\UserService;
use Ibexa\Contracts\Core\Repository\Values\Content\Search\SearchResult;
use Ibexa\Contracts\Core\Repository\Values\User\User;
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
