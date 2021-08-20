<?php

declare(strict_types=1);

namespace Synolia\SyliusAkeneoPlugin\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Synolia\SyliusAkeneoPlugin\Client\ClientFactory;
use Synolia\SyliusAkeneoPlugin\Payload\Family\FamilyPayload;
use Synolia\SyliusAkeneoPlugin\Task\Family\BatchFamilyTask;

final class BatchImportFamiliesCommand extends Command
{
    private const DESCRIPTION = '';

    /** @var string */
    public static $defaultName = 'akeneo:batch:families';

    /** @var \Synolia\SyliusAkeneoPlugin\Client\ClientFactory */
    private $clientFactory;

    /** @var LoggerInterface */
    private $logger;

    /** @var \Synolia\SyliusAkeneoPlugin\Task\Family\BatchFamilyTask */
    private $batchFamilyTask;

    public function __construct(
        ClientFactory $clientFactory,
        LoggerInterface $akeneoLogger,
        BatchFamilyTask $batchProductGroupsTask,
        string $name = null
    ) {
        parent::__construct($name);
        $this->clientFactory = $clientFactory;
        $this->logger = $akeneoLogger;
        $this->batchFamilyTask = $batchProductGroupsTask;
    }

    protected function configure(): void
    {
        $this->setDescription(self::DESCRIPTION);
        $this->addArgument('ids');
        $this->setHidden(true);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ) {
        $ids = explode(',', $input->getArgument('ids'));

        $this->logger->notice('Processing batch', ['from_id' => $ids[0], 'to_id' => $ids[\count($ids) - 1]]);
        $this->logger->debug(self::$defaultName, ['batched_ids' => $ids]);

        $batchPayload = new FamilyPayload($this->clientFactory->createFromApiCredentials());
        $batchPayload->setIds($ids);

        $this->batchFamilyTask->__invoke($batchPayload);

        return 0;
    }
}
