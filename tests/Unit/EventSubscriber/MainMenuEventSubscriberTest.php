<?php

declare(strict_types=1);

use Ibexa\AdminUi\Menu\Event\ConfigureMenuEvent;
use Ibexa\AdminUi\Menu\MainMenuBuilder;
use Ibexa\AdminUi\Menu\MenuItemFactory;
use Ibexa\Contracts\Core\Repository\PermissionResolver;
use Knp\Menu\FactoryInterface;
use Knp\Menu\ItemInterface;
use vardumper\IbexaFormBuilderBundle\EventSubscriber\MainMenuEventSubscriber;

it('returns expected subscribed events', function () {
    expect(MainMenuEventSubscriber::getSubscribedEvents())->toBe([
        ConfigureMenuEvent::MAIN_MENU => ['onMainMenuConfigure', 0],
    ]);
});

it('returns early when permission resolver denies access', function () {
    $menuItemFactory = testMock(MenuItemFactory::class);
    $permissionResolver = testMock(PermissionResolver::class);
    $permissionResolver->method('hasAccess')->with('setup', 'system_info')->willReturn(false);

    $menuCalled = false;
    $menu = testMock(ItemInterface::class);
    $menu->method('getChild')
        ->willReturnCallback(function () use (&$menuCalled) {
            $menuCalled = true;

            return testMock(ItemInterface::class);
        });

    $factory = testMock(FactoryInterface::class);
    $event = new ConfigureMenuEvent($factory, $menu);

    (new MainMenuEventSubscriber($menuItemFactory, $permissionResolver))->onMainMenuConfigure($event);

    expect($menuCalled)->toBeFalse();
});

it('adds form submissions child menu item when access is granted', function () {
    $addedItem = null;

    $createdItem = testMock(ItemInterface::class);
    $menuItemFactory = testMock(MenuItemFactory::class);
    $menuItemFactory->method('createItem')
        ->with('main__admin__form_submissions', [
            'label' => 'Form Submissions',
            'route' => 'ibexa_form_builder.submissions_list',
        ])
        ->willReturn($createdItem);

    $permissionResolver = testMock(PermissionResolver::class);
    $permissionResolver->method('hasAccess')->with('setup', 'system_info')->willReturn(true);

    $adminItem = testMock(ItemInterface::class);
    $adminItem->method('addChild')
        ->willReturnCallback(function (ItemInterface $item) use (&$addedItem) {
            $addedItem = $item;

            return testMock(ItemInterface::class);
        });

    $menu = testMock(ItemInterface::class);
    $menu->method('getChild')
        ->with(MainMenuBuilder::ITEM_ADMIN)
        ->willReturn($adminItem);

    $factory = testMock(FactoryInterface::class);
    $event = new ConfigureMenuEvent($factory, $menu);

    (new MainMenuEventSubscriber($menuItemFactory, $permissionResolver))->onMainMenuConfigure($event);

    expect($addedItem)->toBe($createdItem);
});
