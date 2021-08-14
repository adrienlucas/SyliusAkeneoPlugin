<?php

declare(strict_types=1);

namespace Synolia\SyliusAkeneoPlugin\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Synolia\SyliusAkeneoPlugin\Client\ClientFactory;
use Synolia\SyliusAkeneoPlugin\Payload\Attribute\AttributePayload;
use Synolia\SyliusAkeneoPlugin\Task\Attribute\BatchAttributesTask;

final class BatchImportAttributesCommand extends Command
{
    private const DESCRIPTION = 'Import Attributes range from Akeneo PIM.';

    /** @var string */
    protected static $defaultName = 'akeneo:batch:attributes';

    /** @var \Synolia\SyliusAkeneoPlugin\Client\ClientFactory */
    private $clientFactory;

    /** @var LoggerInterface */
    private $logger;

    /** @var \Synolia\SyliusAkeneoPlugin\Task\Attribute\BatchAttributesTask */
    private $attributesTask;

    public function __construct(
        ClientFactory $clientFactory,
        LoggerInterface $akeneoLogger,
        BatchAttributesTask $batchProductGroupsTask,
        string $name = null
    ) {
        parent::__construct($name);
        $this->clientFactory = $clientFactory;
        $this->logger = $akeneoLogger;
        $this->attributesTask = $batchProductGroupsTask;
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

        $attributePayload = new AttributePayload($this->clientFactory->createFromApiCredentials());
        $attributePayload->setIds($ids);

        $this->attributesTask->__invoke($attributePayload);

        return 0;
    }
}
