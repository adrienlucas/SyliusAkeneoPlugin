<?php

declare(strict_types=1);

namespace Synolia\SyliusAkeneoPlugin\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Synolia\SyliusAkeneoPlugin\Client\ClientFactory;
use Synolia\SyliusAkeneoPlugin\Factory\AttributePipelineFactory;
use Synolia\SyliusAkeneoPlugin\Logger\Messages;
use Synolia\SyliusAkeneoPlugin\Payload\Attribute\AttributePayload;

final class ImportAttributesCommand extends AbstractImportCommand
{
    use LockableTrait;

    protected static $defaultDescription = 'Import Attributes and Options from Akeneo PIM.';

    /** @var string */
    protected static $defaultName = 'akeneo:import:attributes';

    /** @var \Synolia\SyliusAkeneoPlugin\Factory\AttributePipelineFactory */
    private $attributePipelineFactory;

    /** @var \Synolia\SyliusAkeneoPlugin\Client\ClientFactory */
    private $clientFactory;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        AttributePipelineFactory $legacyFamilyPipelineFactory,
        ClientFactory $clientFactory,
        LoggerInterface $akeneoLogger,
        string $name = null
    ) {
        parent::__construct($name);
        $this->attributePipelineFactory = $legacyFamilyPipelineFactory;
        $this->clientFactory = $clientFactory;
        $this->logger = $akeneoLogger;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ) {
        if (!$this->lock()) {
            $output->writeln(Messages::commandAlreadyRunning());

            return 0;
        }

        $context = parent::createContext($input, $output);

        $this->logger->notice(self::$defaultName);
        /** @var \League\Pipeline\Pipeline $attributePipeline */
        $attributePipeline = $this->attributePipelineFactory->create();

        $attributePayload = new AttributePayload($this->clientFactory->createFromApiCredentials(), $context);
        $attributePipeline->process($attributePayload);

        $this->logger->notice(Messages::endOfCommand(self::$defaultName));
        $this->release();

        return 0;
    }
}
