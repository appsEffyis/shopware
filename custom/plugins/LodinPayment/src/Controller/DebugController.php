<?php

namespace LodinPayment\Controller;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class DebugController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $orderTransactionRepository
    ) {}

    #[Route(path: '/lodin/debug', name: 'lodin.debug', methods: ['GET'])]
    public function debug(Context $context): JsonResponse
    {
        $transactions = $this->orderTransactionRepository->search(new Criteria(), $context);

        $data = [];

        foreach ($transactions as $t) {
            $data[] = [
                'id' => $t->getId(),
                'customFields' => $t->getCustomFields(),
            ];
        }

        return new JsonResponse($data);
    }
}