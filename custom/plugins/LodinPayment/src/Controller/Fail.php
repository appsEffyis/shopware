<?php declare(strict_types=1);

namespace LodinPayment\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class Fail extends AbstractController
{
    #[Route(path: '/lodin/fail', name: 'lodin.fail', methods: ['GET'])]
    public function fail(): Response
    {
        return $this->render('@LodinPayment/storefront/page/lodin/fail.html.twig');
    }
}