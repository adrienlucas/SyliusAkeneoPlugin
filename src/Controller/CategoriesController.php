<?php

declare(strict_types=1);

namespace Synolia\SyliusAkeneoPlugin\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Synolia\SyliusAkeneoPlugin\Entity\CategoriesConfiguration;
use Synolia\SyliusAkeneoPlugin\Form\Type\CategoriesConfigurationType;
use Synolia\SyliusAkeneoPlugin\Repository\CategoriesConfigurationRepository;

final class CategoriesController extends AbstractController
{
    /** @var EntityManagerInterface */
    private $entityManager;

    /** @var CategoriesConfigurationRepository|RepositoryInterface */
    private $categoriesConfigurationRepository;

    public function __construct(EntityManagerInterface $entityManager, RepositoryInterface $categoriesConfigurationRepository)
    {
        $this->entityManager = $entityManager;
        $this->categoriesConfigurationRepository = $categoriesConfigurationRepository;
    }

    public function __invoke(Request $request): Response
    {
        $categoriesConfigurations = null;
        if ($this->categoriesConfigurationRepository instanceof CategoriesConfigurationRepository) {
            $categoriesConfigurations = $this->categoriesConfigurationRepository->getCategoriesConfiguration();
        }
        if ($categoriesConfigurations === null) {
            $categoriesConfigurations = new CategoriesConfiguration();
        }

        $form = $this->createForm(CategoriesConfigurationType::class, $categoriesConfigurations);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($form->getData());
            $this->entityManager->flush();
        }

        return $this->render('@SynoliaSyliusAkeneoPlugin/Admin/AkeneoConnector/categories.html.twig', [
                'form' => $form->createView(),
            ]
        );
    }
}