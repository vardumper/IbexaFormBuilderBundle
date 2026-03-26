<?php

declare(strict_types=1);

namespace vardumper\IbexaFormBuilderBundle\Command;

use Ibexa\Contracts\Core\Repository\ContentService;
use Ibexa\Contracts\Core\Repository\LocationService;
use Ibexa\Contracts\Core\Repository\PermissionResolver;
use Ibexa\Contracts\Core\Repository\SearchService;
use Ibexa\Contracts\Core\Repository\UserService;
use Ibexa\Contracts\Core\Repository\Values\Content\Location;
use Ibexa\Contracts\Core\Repository\Values\Content\LocationQuery;
use Ibexa\Contracts\Core\Repository\Values\Content\Query\Criterion;
use Ibexa\Core\FieldType\Integer\Value as IntegerValue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'ibexa:form-builder:sync-order',
    description: 'Retroactively syncs form_builder_order field → location priority, and sets container locations to sort by priority.',
)]
final class SyncOrderCommand extends Command
{
    /** Content types whose locations should sort children by priority */
    private const CONTAINER_TYPES = [
        'form', 'form_builder_form',
        'fieldset', 'form_builder_fieldset',
        'select', 'form_builder_select',
        'horizontal_group', 'form_builder_horizontal_group',
    ];

    /** Content types that carry a form_builder_order field */
    private const ORDERABLE_TYPES = [
        'input', 'form_builder_input',
        'textarea', 'form_builder_textarea',
        'select', 'form_builder_select',
        'fieldset', 'form_builder_fieldset',
        'horizontal_group', 'form_builder_horizontal_group',
        'choice', 'form_builder_choice',
    ];

    private const ORDER_FIELD = 'form_builder_order';

    public function __construct(
        private readonly SearchService $searchService,
        private readonly LocationService $locationService,
        private readonly ContentService $contentService,
        private readonly PermissionResolver $permissionResolver,
        private readonly UserService $userService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('admin-login', null, InputOption::VALUE_OPTIONAL, 'Admin user login', 'admin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $adminLogin = $input->getOption('admin-login');
        $adminUser = $this->userService->loadUserByLogin($adminLogin);
        $this->permissionResolver->setCurrentUserReference($adminUser);

        // Step 1: Set sort_field=priority on all container locations
        $io->section('Updating container location sort fields to "priority"');
        $this->syncContainerSortFields($io);

        // Step 2: Sync form_builder_order → location priority on orderable items
        $io->section('Syncing form_builder_order → location priority');
        $this->syncOrderPriorities($io);

        $io->success('Done.');

        return Command::SUCCESS;
    }

    private function syncContainerSortFields(SymfonyStyle $io): void
    {
        $query = new LocationQuery();
        $query->filter = new Criterion\ContentTypeIdentifier(self::CONTAINER_TYPES);
        $query->limit = 50;
        $query->offset = 0;

        $total = 0;
        do {
            $results = $this->searchService->findLocations($query);
            foreach ($results->searchHits as $hit) {
                /** @var Location $location */
                $location = $hit->valueObject;
                $updateStruct = $this->locationService->newLocationUpdateStruct();
                $updateStruct->sortField = Location::SORT_FIELD_PRIORITY;
                $updateStruct->sortOrder = Location::SORT_ORDER_ASC;
                $this->locationService->updateLocation($location, $updateStruct);
                $total++;
            }
            $query->offset += $query->limit;
        } while ($query->offset < $results->totalCount);

        $io->writeln(sprintf('  Updated sort field on <info>%d</info> container location(s).', $total));
    }

    private function syncOrderPriorities(SymfonyStyle $io): void
    {
        $query = new LocationQuery();
        $query->filter = new Criterion\ContentTypeIdentifier(self::ORDERABLE_TYPES);
        $query->limit = 50;
        $query->offset = 0;

        $total = 0;
        do {
            $results = $this->searchService->findLocations($query);
            foreach ($results->searchHits as $hit) {
                /** @var Location $location */
                $location = $hit->valueObject;
                $content = $this->contentService->loadContentByContentInfo($location->contentInfo);
                $orderValue = $content->getFieldValue(self::ORDER_FIELD);

                if (!$orderValue instanceof IntegerValue || $orderValue->value === null) {
                    continue;
                }

                $updateStruct = $this->locationService->newLocationUpdateStruct();
                $updateStruct->priority = (int) $orderValue->value;
                $this->locationService->updateLocation($location, $updateStruct);
                $total++;
            }
            $query->offset += $query->limit;
        } while ($query->offset < $results->totalCount);

        $io->writeln(sprintf('  Synced priority on <info>%d</info> location(s).', $total));
    }
}
