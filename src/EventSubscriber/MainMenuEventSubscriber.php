<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\EventSubscriber;

use Ibexa\AdminUi\Menu\Event\ConfigureMenuEvent;
use Ibexa\AdminUi\Menu\MainMenuBuilder;
use Ibexa\AdminUi\Menu\MenuItemFactory;
use Ibexa\Contracts\Core\Repository\PermissionResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class MainMenuEventSubscriber implements EventSubscriberInterface
{
    private const ITEM_ADMIN__FORM_SUBMISSIONS = 'main__admin__form_submissions';

    public function __construct(
        private readonly MenuItemFactory $menuItemFactory,
        private readonly PermissionResolver $permissionResolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConfigureMenuEvent::MAIN_MENU => ['onMainMenuConfigure', 0],
        ];
    }

    public function onMainMenuConfigure(ConfigureMenuEvent $event): void
    {
        if (!$this->permissionResolver->hasAccess('setup', 'system_info')) {
            return;
        }

        $event->getMenu()
            ->getChild(MainMenuBuilder::ITEM_ADMIN)
            ->addChild(
                $this->menuItemFactory->createItem(
                    self::ITEM_ADMIN__FORM_SUBMISSIONS,
                    [
                        'label' => 'Form Submissions',
                        'route' => 'ibexa_form_builder.submissions_list',
                    ]
                )
            );
    }
}
