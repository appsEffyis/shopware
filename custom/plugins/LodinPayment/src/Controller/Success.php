<?php declare(strict_types=1);

namespace LodinPayment\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class Success extends AbstractController
{
    #[Route(path: '/lodin/success', name: 'lodin.success', methods: ['GET'])]
    public function success(): Response
    {
        return $this->render('@LodinPayment/storefront/page/lodin/success.html.twig');
    }
}